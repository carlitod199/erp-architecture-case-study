# Integrating with systems you do not control

Three integrations, three different shapes of problem: a national e-invoicing
authority, a bank's payment-slip API, and an access-control turnstile on a local
network. What they share is that the other side is not going to change for you,
its documentation is incomplete, and it will be unavailable at the worst moment.

The rule that came out of all three: **the core must not know the integration
exists.** Each one lives behind a single adapter file. If the vendor is replaced,
or the integration is switched off, exactly one file changes.

---

## 1. A government e-invoicing service

### What makes it hard

- Invoices are **signed XML**, using an X.509 certificate that belongs to the
  business, protected by a passphrase.
- The XML schema is versioned by the authority and changes on their schedule.
- There is a homologation (test) environment and a production environment, and
  sending test data to production has legal consequences.
- Certificates expire annually. When one does, every invoice fails.

### The decisions

**The certificate passphrase never touches the database.** It lives in a
gitignored configuration file on disk, keyed by tenant; the certificate itself
lives outside the web root. A database dump therefore contains no material that
can sign anything. This is stronger than encrypting it, because there is nothing
to decrypt — see [security-model.md](security-model.md).

**Default to the test environment.** Where the configuration is ambiguous or the
value is missing, the adapter selects homologation. Getting this backwards means
issuing real fiscal documents by accident. A default that fails safely is worth
more than a default that is convenient.

**Incomplete configuration is a checklist, not an exception.** The adapter can
answer "what is missing before this can work?" and returns a list: the emitter's
tax id, the state, the certificate file, the passphrase. The screen renders the
list. Nothing throws, nothing 500s, and the person who has to fix it is told
precisely what to fix — which is usually an accountant, not a developer.

**A proof-of-life call before anything real.** The first thing the integration
does is query the authority's *service status* endpoint. That single call proves
the certificate loads, the passphrase is right, the network path works, and the
authority is up — without creating a document. When something breaks at 8am,
"the proof-of-life call fails with this code" is a diagnosis; "invoice submission
failed" is not.

**Responses are parsed namespace-agnostically.** Government XML arrives with
namespace prefixes that vary between endpoints and change between schema
revisions. Extracting fields by local name rather than by fully-qualified path
means a prefix change does not break the parser. This is the kind of defensive
choice that looks like sloppiness until the third time the prefix changes.

**Adapter isolation is total.** One file imports the e-invoicing library. The
rest of the codebase deals in the domain: an invoice, a status, a rejection
reason. That boundary is also what makes it possible to reason about the fact
that a heavy third-party dependency is being loaded at all.

---

## 2. A bank's payment-slip (boleto) API

### What makes it hard

Money, and asynchrony. Issuing a slip is a synchronous call, but *payment* is an
event that happens hours or days later, at a bank counter or in an app, and
reaches you — maybe — through a webhook you do not control.

### OAuth, certificates, and cached tokens

The bank uses OAuth 2 client credentials **plus** a client certificate. Two
secrets — a client secret and a certificate passphrase — both encrypted at rest
with a purpose-derived key, both masked in the configuration screen, and both
left untouched when the form posts back an unchanged mask.

Sandbox and production are separate credential sets, and which one is active is
a stored, visible, per-tenant setting. There is no "it picks the right one
automatically". Ambiguity about which environment is live is how test slips end
up in front of real customers.

### Reconciliation: three paths to the same state

A payment can be learned about in three ways, and a correct system handles all
three arriving in any order:

1. **A webhook** from the bank, if it fires and if it is not lost.
2. **A polled query** on an individual slip, from an operator's screen.
3. **A scheduled sweep** over all open slips.

All three converge on one reconciliation service, and that service applies a
payment only from the bank's answer to a query it made itself. The webhook
endpoint does not write to the ledger: it authenticates, resolves the tenant, and
hands the payload to that same service. Treating the webhook as a *hint* rather
than as truth removes an entire class of problem — a spoofed or replayed webhook
cannot post a payment, because the payment is only ever recorded from an
authenticated query the system made.

The sweep is what makes the whole thing reliable. Webhooks get lost, and a
financial system that discovers payments only when the network cooperates is not
a financial system. The sweep is idempotent, batch-limited, and safe to run
often.

### Applying a payment exactly once

The write is a transaction: update the slip, insert a status-history row, insert
an audit-log entry. Applying a payment to a slip already marked paid is a no-op
that reports "already reconciled", not an error and not a second entry — because
all three discovery paths will regularly find the same payment.

Divergences are recorded rather than resolved automatically. If the amount paid
does not equal the amount due, the slip is flagged for a human. A system that
silently accepts a short payment is a system that loses money quietly.

### Guarding the unauthenticated endpoints

The webhook and the scheduled-sweep entry point are reachable without a session,
so they have their own controls:

- A shared secret compared with a **timing-safe** comparison, accepted from a
  header (or a query parameter, for schedulers that cannot set headers), read
  from the environment and never from the database.
- The tenant is resolved explicitly from the request and validated. A
  multi-tenant webhook with an ambiguous tenant is a cross-tenant write waiting
  to happen.
- Writes are attributed to a dedicated technical user id, so the audit trail
  distinguishes "the bank told us" from "an operator did this".
- An **advisory file lock per tenant** around the sweep. Overlapping runs are the
  default outcome of an HTTP-triggered cron and a slow bank; the second run
  returns "already running" instead of doubling the load and racing the first.
- Errors return a correlation id and log the detail. The bank's own error
  messages are passed through where they are actionable ("payer document
  invalid") and swallowed where they are not.

### Trade-off

There is no job queue, so the sweep is an HTTP endpoint hit by an external
scheduler. It is idempotent, locked, and batch-limited — but it is still a
long-running job on a request thread, and a bank that is slow can push it toward
a timeout. A queue with a worker process is the right answer and is not available
on this hosting. This is listed openly in the README's "what I would do
differently".

---

## 3. An access-control turnstile

The device posts events to an HTTP endpoint on the local network. Nothing about
that is negotiable — the firmware sends what it sends.

- **Raw events are stored before they are interpreted.** The event is written to
  a raw table first, then processed. When the device firmware sends something
  undocumented, the payload is still there to look at. Reconstructing an event
  from an error message is not a thing anyone can do.
- **Deduplication uses a unique constraint, not a lookup.** Devices resend. The
  insert either succeeds or violates a unique index on the event id, and a
  violation returns success to the device so it stops retrying. Checking for
  existence first and then inserting is a race; letting the database arbitrate is
  not.
- **When the device sends no usable id, one is derived** by hashing the fields
  that identify the event. Imperfect, and much better than no deduplication.
- **Payloads are masked before storage.** Biometric templates, photographs,
  passwords and session tokens are replaced with a placeholder by a recursive
  walk over the payload before it is written. Debugging value is retained;
  biometric data is not stored in a debug table.
- **Authentication is a per-device secret plus an optional source-IP allow-list.**
  The device cannot do better; the endpoint compensates with defence in depth.

---

## The pattern underneath all three

| Concern | Decision |
|---|---|
| Vendor knowledge | Confined to one adapter file |
| Secrets | Encrypted at rest, or kept out of the database entirely |
| Environments | Explicit, stored, and defaulting to the safe one |
| Inbound events | Treated as hints; the truth comes from an authenticated query |
| Idempotency | Enforced by a unique constraint, not by a prior lookup |
| Reliability | A scheduled sweep behind every webhook |
| Diagnosis | A proof-of-life call, a configuration checklist, a correlation id |
| Failure | Degraded and explained, never a fatal error on an unrelated screen |
