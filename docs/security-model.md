# Security model

Both systems are session-based server-rendered PHP applications with a token-based
REST API bolted onto the same core. There is no identity provider, no API gateway,
no WAF, and no platform team. Everything below is application code, because
application code is the only layer that exists.

Nothing here is a claim that these systems are secure, certified, or audited.
They are not. What follows is a description of the controls that exist and the
reasoning that put them there.

---

## The threat model, stated plainly

What is actually being defended against, in rough order of likelihood:

1. **Credential stuffing and password guessing** against a login form that is
   reachable from the open internet, because the users are in the field and a
   VPN is not a thing anyone will maintain.
2. **A leaked database dump.** Shared hosting, third-party backup tooling, and a
   development copy of the schema on someone's laptop are all real. The question
   is not whether a dump can happen but what a dump is worth.
3. **A cross-site request forgery** against an authenticated operator, because
   one POST can approve a purchase order.
4. **Stored XSS** through a field an operator typed, since almost every screen
   renders data another user entered.
5. **Horizontal privilege escalation between tenants** — the failure mode that
   would end the product, and the one that is easiest to introduce by forgetting
   a single `WHERE` clause.

What is explicitly **not** defended against: a compromise of the host itself.
If an attacker can read the web process's environment, they hold the master
encryption key. On managed shared hosting there is no key management service to
delegate to. That is a real limitation and it belongs in this document rather
than in a footnote.

---

## Authentication

**Passwords** are bcrypt at cost 12, re-hashed transparently on successful login
whenever the configured cost has moved. Cost is a deployment-lifetime decision
that has to be revisable without a migration, and the login path is the only
place where the plaintext exists to re-hash with.

**The login response is uniform.** "No such account", "wrong password" and
"account disabled" produce the same message, the same status code, and — this is
the part that is usually missed — the same amount of work. When there is no
account row to verify against, the submitted password is verified against a dummy
hash of the same cost and the result is discarded. Without that, the
existing-account branch runs bcrypt and the missing-account branch does not, and
the difference is measurable over a network. See
[`reference/LoginThrottle.php`](../reference/LoginThrottle.php).

**Throttling is keyed on (identity, IP), with a looser counter on IP alone.**
Counting per account only is an invitation: anyone who knows an operator's email
can lock them out of their own ERP by failing five logins. Keying on the pair
means an attacker locks out only themselves. The IP-only counter catches the
other shape of the attack — one source spraying many accounts, none of which
individually reaches the pair threshold.

**The throttle fails closed.** If the attempt store errors, the system cannot
know how many attempts have already happened, and it refuses. This is a
deliberate availability sacrifice, and it has a real failure mode worth stating:
a broken audit table locks everyone out of the application. The mitigation is
that the store is the same database the application already needs to serve any
page at all, so "the store is down" and "the app is down" are nearly the same
event.

---

## Sessions

Three clocks, all enforced in application code:

| Clock | Purpose | Behaviour |
|---|---|---|
| Idle timeout | Unattended terminal | Slides forward on every request |
| Absolute timeout | Bounds a stolen session | Fixed at login, never extended |
| Rotation interval | Bounds a stolen session id | New id on a schedule |

The garbage collector is aligned to the absolute timeout rather than left at its
default. This is not cosmetic: the default `session.gc_maxlifetime` is 24 minutes,
so a policy allowing 60 minutes of idle time will still see sessions die at
roughly 24 — the collector deletes the session file out from under a policy that
believes it is still valid, and the resulting bug reports say "it logs me out
randomly".

**Rotation does not destroy the old session.** This is the single most
interesting decision in the session layer, and it exists because the obvious
implementation is wrong in a specific, reproducible way. `session_regenerate_id(true)`
deletes the old record immediately. A user with five tabs open — which is the
normal state of an ERP user — has requests in flight carrying the old id. Those
requests land on an id that no longer exists, a fresh empty session is created
under it, a new CSRF token is minted into that empty session, and every open form
in every other tab is now invalid. Instead: stamp the outgoing session, commit it,
open the new id with the same payload, and let the stamped record stay readable
for a short grace window.

The trade-off is that two ids are valid for one user during the grace window.
That is bounded and measured in seconds. The alternative is a logout bug that
fires constantly. See [`reference/SessionGuard.php`](../reference/SessionGuard.php).

**Cookie flags**: `HttpOnly`, `Secure` when the request arrived over TLS (through
a terminating proxy, so `X-Forwarded-Proto` counts), `SameSite=Lax`, zero
lifetime, `use_strict_mode` on, `use_only_cookies` on.

`SameSite=Lax` rather than `Strict` is a usability decision: `Strict` drops the
cookie on top-level navigation into the app from an external link, so following a
notification email lands the user on a login screen. `Lax` still blocks the
cross-site POST, which is what CSRF actually needs.

---

## CSRF

Per-session token, `hash_equals` comparison, required on every state-changing
request. Accepted from a form field, an `X-CSRF-Token` header, or a JSON body
field, because the same endpoint serves all three.

Two refinements came out of production, and both are about multi-tab users:

**A short history of recently rotated tokens is accepted.** When the token
rotates, forms already rendered in other tabs carry the previous one. Accepting
the last few tokens *of the same session* for a bounded window does not weaken
the control — an attacker on another origin cannot read any of them, current or
previous, because they are all bound to an `HttpOnly` cookie. It only stops a
user's own tabs from invalidating each other.

**A CSRF failure on a form navigation is not a raw 403.** It redirects back to
the form with a flash message and the typed values restored, because the
overwhelmingly common cause is a legitimate user whose token rotated, not an
attack. An attacker gets the same redirect and learns nothing; a user gets their
work back. A failure on an AJAX or API call returns a JSON 403 carrying the
current token so the front end can re-submit without losing the form.

The trade-off: a wider acceptance window than one token. It is bounded in count
and time, and it buys the difference between "the app randomly loses my work" and
"the app is usable with more than two tabs open".

---

## Authorisation

Slugs shaped `module.screen.action`, resolved once at login into a flat list on
the session, checked in memory. Four wildcard forms: `*`, `module.*`,
`module.screen.*`, `*.action`. See
[`reference/Permissions.php`](../reference/Permissions.php) and
[multi-tenancy.md](multi-tenancy.md) for how the tenant scope interacts.

Two properties worth calling out:

- **`module.view` does not imply `module.screen.view`.** Seeing that a module
  exists in navigation is a different grant from seeing the data on one of its
  screens.
- **Sensitive actions live outside the wildcard namespace.** Approving, reopening,
  or writing something off gets a slug that no `module.*` grant covers, so it has
  to be granted deliberately and shows up in an audit of who holds it.

Denials are audited with the requested permission, the role, the URL, the IP and
the user agent. A denied *page* redirects to the first module the user can
actually reach rather than showing a bare 403, with a loop guard — a 403 that
tells a warehouse operator nothing is a support call; a redirect to their own
landing screen is not.

---

## Secrets at rest

Third-party credentials the application must be able to *use* — an API client
secret, a certificate passphrase, a device password — cannot be hashed. They are
encrypted with AES-256-GCM under a key derived from a master key held in the
process environment, and the ciphertext carries a key id so rotation is a
background sweep rather than a big-bang migration. Context (tenant, purpose) is
authenticated as associated data, so a ciphertext moved between rows fails to
open. See [`reference/SecretBox.php`](../reference/SecretBox.php).

Two rules that turned out to matter more than the algorithm:

- **A secret that never enters the database does not need to be encrypted in it.**
  The strongest form of protection for a certificate passphrase was to keep it in
  a gitignored config file on disk, referenced by path, and never write it to a
  column at all.
- **Secrets are masked on the way out and mask-aware on the way in.** The
  configuration screen renders `********3f7a`. When the form posts back an
  unchanged mask, the save path recognises it and leaves the stored value alone,
  so re-saving an unrelated field cannot overwrite a credential with asterisks.

---

## Output and input handling

- Every value rendered into HTML passes through an escaping helper with
  `ENT_QUOTES | ENT_SUBSTITUTE` and an explicit charset.
- Values embedded into a `<script>` block are encoded with the hex flags for
  `<`, `>`, `&`, `'` and `"`, which is what actually prevents a `</script>`
  breakout — plain `json_encode` does not.
- Uploads are checked twice: an extension allow-list, and a content check that
  the bytes match the claimed type (a real raster image, a real PDF header). An
  extension check alone is a rename away from useless.
- All SQL goes through prepared statements with native prepares. The rare clause
  that cannot take a placeholder — an `INTERVAL`, a `LIMIT`, a table name — is
  built from a validated integer or an allow-list, with a comment at the site
  explaining why it is safe. Every one of those is a place a future edit can
  introduce an injection, so they are made loud rather than quiet.

## Response headers

Sent centrally, before any output. HSTS only over TLS; `nosniff`;
frame restrictions; a trimmed referrer; device APIs switched off. The CSP is
honestly transitional: it still allows inline script, which means it stops very
little today. The migration path — self-host assets, add per-response nonces,
convert inline handlers to listeners, then enforce — is written down in
[`reference/SecurityHeaders.php`](../reference/SecurityHeaders.php) rather than
implied by shipping a report-only policy and calling it protection.

## Transport and version floor

The application requires PHP 8.1 and enforces it in a version gate written in
syntax old enough to parse on PHP 5, included as the first statement of every
entry point. Without it, a host that silently rolls back the PHP version produces
a fatal parse error and a white screen; with it, the operator gets a sentence
telling them exactly what is wrong. On shared hosting, where the PHP version can
change because someone clicked a dropdown in a control panel, this is not
paranoia.
