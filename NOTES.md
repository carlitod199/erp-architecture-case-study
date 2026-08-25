# Verification notes

This file exists so that the claims in [README.md](README.md) and `docs/` can be
re-checked. Every substantive statement about the production systems was read out
of the source before it was written down. Anything that could not be verified is
not in the README — the last section here lists what was left out and why.

Source files are referred to by **role**, never by name or path, because the file
names themselves carry product and vendor identifiers that must not appear in a
public repository. Anyone with the source can find each one from the description
in a single grep.

---

## How the source was read

Two systems, and only the parts named as in scope:

- **System A** — the security and data core (bootstrap, connection factory,
  session/auth, permissions, login throttling, response headers, secret box,
  crypto helpers, version gate, CRUD helpers, barcode and QR helpers, print
  component, the fiscal adapter directory), the REST API layer (front controller,
  API core, request context, auth/sync/write route files), and the field client's
  offline layer, HTTP service and sync context.
- **System B** — the bank-integration endpoints, the permissions endpoint, the
  access-control device webhook, the Node API's configuration, middleware and
  worker.

File counts were taken with `find <dir> -type f -name '*.php' | wc -l` and the
same for `*.js`, over exactly those directories.

---

## Claims verified, and how

### Scale (the only numbers in the README)

| Claim | How verified |
|---|---|
| System A: 49 PHP files, 86 JavaScript files | `find` + `wc -l` over the in-scope directories |
| System B: 58 PHP files, 25 JavaScript files | same |

These are counts of files in the directories the study was written from. No other
quantity appears anywhere in this repository.

### Operating constraints

| Claim | How verified |
|---|---|
| Apache with `.htaccess` rewrites | An `.htaccess` in the API directory with `RewriteEngine On`, a front-controller rule, an `Authorization`-preserving rule, and a `FilesMatch` denying every PHP file but the front controller |
| PHP-FPM | Referenced by name in a comment in the secret-box file explaining why an env loader may not have run yet in that SAPI |
| No Redis / Memcached / APCu | `grep -rn "apcu_\|memcache\|redis"` across both systems' in-scope PHP and JS: **zero matches** |
| Sessions on the filesystem | `grep -rn "session_set_save_handler\|session.save_path"`: **zero matches**, so the default handler is in use |
| No test suite | No test directory, no test runner configuration, and no test files in either system's in-scope tree |
| Version floor enforced in application code | A dedicated gate file checking `PHP_VERSION_ID < 80100`, written without modern syntax, with a comment stating it must remain parseable on old versions and be included first |
| Scheduled work is an HTTP endpoint | A cron-triggered endpoint that explicitly does not use a session or CSRF and authenticates with a token from the environment |
| Advisory file lock per tenant | A non-blocking exclusive `flock` on a per-tenant lock file in the system temp directory, returning an "already running" result instead of blocking |
| The one background worker is a polling loop | A Node worker with a `setInterval`-style loop, a batch limit, a query for rows not yet sent, and a comment stating that a failed send is simply retried on the next cycle — no backoff, no attempt cap |

### Session and authentication

| Claim | How verified |
|---|---|
| Idle, absolute and rotation clocks with those roles | Three constants and three checks in the session/auth include; `last_activity` written on every request, `login_at` never rewritten |
| GC lifetime aligned to the absolute timeout | `ini_set('session.gc_maxlifetime', ...)` set to the absolute timeout, with a comment describing sessions dying early at the 24-minute default |
| Rotation does not destroy the old session | The rotation block snapshots the session, stamps the outgoing record, commits, opens the new id, restores the payload, and treats the stamped record as valid inside a grace constant |
| The reason it works that way | An in-source comment describing the multi-tab failure: an in-flight request on the old id, a recreated empty session, a new CSRF token, and phantom logout |
| Cookie flags | `session_set_cookie_params` with `httponly`, `samesite`, zero lifetime, and `secure` computed from a TLS check that includes `X-Forwarded-Proto` |
| bcrypt cost 12, re-hash on login | `password_hash(..., PASSWORD_BCRYPT, ['cost' => 12])` and a `password_needs_rehash` branch on the successful-login path |
| Uniform login response including timing | A shared throttle include defining a fixed dummy hash and a dummy-verify function, called on the missing-account branch and on the throttled branch before the error is returned |
| Throttle keyed on (identity, IP) plus IP alone | Two count queries with two constants; a comment states that keying on the account alone would let an attacker lock out a legitimate user |
| Fail-closed | The `catch` around the throttle queries returns `true`, with a comment stating the reasoning |
| CSRF token history accepted for a bounded window | A previous-token list with a maximum count and a maximum age, compared with `hash_equals` |
| A CSRF failure on a form navigation redirects rather than 403s | A navigation branch that stashes the submitted values, sets a flash message and issues a 303; the AJAX branch returns JSON 403 including the current token |

### Authorisation

| Claim | How verified |
|---|---|
| `module.screen.action` slugs, generated from one catalogue | A single catalogue generator producing `.view` / `.edit` / `.delete` per screen from a central menu matrix, with a comment recording that two divergent generators were unified into it |
| Four wildcard forms | The permission-check function handles `*`, a `.*` namespace prefix, an `*.action` suffix, and an exact match; the API context file implements the same four |
| Permissions snapshotted on the session at login | The check reads `$_SESSION['permissions']`; the API path writes the same key after resolving the token |
| Sensitive actions outside the wildcard namespace | Dedicated slugs for approve / open / close / reopen actions added to the catalogue as explicit entries, with comments recording that they are grantable to individuals |
| Denials are audited | The deny path writes permission, role, IP, user agent and URL to an audit table |
| A denied page redirects to a reachable screen with a loop guard | The deny path computes a landing URL and compares it to the current path before redirecting, falling back to a 403 route |
| Fallback role→permission map in code | A function returning a hard-coded map per role name, plus a second one in the API context file with a comment noting it mirrors the web one |

### Secrets

| Claim | How verified |
|---|---|
| AES-256-GCM with a versioned prefix | `openssl_encrypt(..., 'aes-256-gcm', ...)` storing nonce, tag and ciphertext concatenated and base64-encoded behind a version prefix, in both the secret box and the crypto helpers |
| Associated data binds the context | The AEAD calls pass an AAD argument; the field-level layer builds it from the tenant id |
| Per-purpose / per-tenant derived subkeys | `hash_hkdf('sha256', $master, 32, $info)` with an info string containing the purpose and the tenant |
| Key from the environment, validated to 32 bytes | The key accessor reads an environment variable, accepts base64 or raw, and throws on the wrong length |
| Masking on display, mask-aware on save | A mask helper, and a save path that checks whether the posted value looks masked before deciding to re-encrypt |
| Certificate passphrase never in the database | The fiscal adapter reads the passphrase from a gitignored config file keyed by tenant, with comments stating explicitly that it must never be in the database or in version control |

### Data layer

| Claim | How verified |
|---|---|
| `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES => false` | The connection factory's PDO options array |
| Session time zone pinned per connection | A `SET time_zone` executed on connect, with a comment that it is applied per session and does not change the server globally |
| The clock-skew bug and its fix | A comment in the API core stating that the application host ran ahead of the database, that the cursor is compared against database-stamped `updated_at`, and that the cursor is therefore read with `SELECT NOW()` |
| The client-side guard against a future cursor | The sync module compares the stored cursor to the response's `server_time` and falls back to a full load |
| The one-placeholder rule | A comment in the sync routes stating that with emulation off a named placeholder cannot be reused, alongside a query using two differently named tenant placeholders |
| `INTERVAL` built from a validated constant | Both the throttle queries and the token queries interpolate an integer cast from a constant, each with a comment stating no user data is involved |
| Writes stamp tenant and acting user | The insert helper sets `tenant_id`, `created_by`, `updated_by`; the update helper sets `updated_by` and puts tenant and id in the `WHERE` |
| Delete inactivates when possible, and translates FK violations | The delete helper checks for an `active` column, otherwise catches SQLSTATE `23000` and produces a user-facing sentence |
| Schema drift tolerated via `information_schema` | A cached column-existence helper, used across the sync routes to assemble columns and joins conditionally, with comments naming the migrations involved |
| A missing session tenant is a hard stop | The tenant accessor exits with 403 rather than defaulting |

### API layer

| Claim | How verified |
|---|---|
| Front controller with a route table | An array of `[method, regex, file, handler, public]` entries dispatched in a loop, with captured groups passed as handler arguments |
| Single envelope with `ok / data / message / error / sync.server_time` | The success and error helpers in the API core |
| Stable error codes, client switches on the code | The error helper takes a code; the client's HTTP service raises a typed error carrying the code, and the sync engine matches against a set of codes |
| Opaque bearer token, SHA-256 at rest, revocable | Login generates 32 random bytes as hex and stores only `hash('sha256', $token)`; the context file looks up by hash and checks expiry and a revocation column |
| Refresh capped by an absolute ceiling | The refresh statement uses `LEAST(NOW() + INTERVAL ? DAY, created_at + INTERVAL ? DAY)` and a `WHERE` that returns zero rows past the ceiling |
| Token last-use write throttled | The last-use update is guarded by a `WHERE` clause requiring the stored timestamp to be older than one minute |
| The API populates the same session shape as the web login | The context file assigns user, tenant, role and permissions into `$_SESSION` with a comment stating this is what lets the shared helpers work unchanged |
| CORS allow-list; native clients unaffected | An origin regex allow-list plus `Vary: Origin`, with a comment noting a native app sends no `Origin` |
| Route files not directly requestable | The `FilesMatch` block in the API `.htaccess` |

### Error handling

| Claim | How verified |
|---|---|
| Error, exception and shutdown handlers installed in bootstrap | All three registered, with the error handler converting to `ErrorException` and the shutdown handler filtering on the fatal error types |
| Content negotiation decided once | A single request-classification function using path, `Accept` and the AJAX header, used by the renderer and by the auth failure paths |
| Mid-render failures declare themselves and close the document | The renderer's `headers_sent()` branch emits an inline alert and closes `</main></body></html>` — with a comment stating that a page must not pretend to be healthy |
| `display_errors` off, `log_errors` on, `expose_php` off | Set in bootstrap before anything else runs |
| Correlation ids on the hardest paths | The reconciliation and cron endpoints generate a debug id, log it with the trace, and return it in the response |

### Offline-first

| Claim | How verified |
|---|---|
| Local SQLite with a cache table, a queue table and a sync-cursor table | The client's database module creates `cache`, `sync_meta` and a queue table, in WAL mode |
| Queue states | The queue column comment lists `pending / sending / confirmed / error / failed` and the module implements the transitions |
| Client-generated UUID per record | A dedicated module generating a v4 UUID, with a comment stating the write endpoint is idempotent on it |
| Server-side idempotency with a stored response | A helper that looks up `(tenant, client_uuid)`, returns the stored response on a hit, and otherwise inserts inside a transaction and records the response JSON |
| The unique-constraint race is handled | The `catch` matches SQLSTATE `23000`, re-reads the row and returns the stored response |
| Three failure classes | A set of "definitive rejection" error codes; a `no connection` branch that returns the item to pending and `break`s the drain; and a backoff path with an attempt cap |
| Exponential backoff with a cap | `2 ** (attempts - 1)` minutes with a maximum attempt constant, after which the item is marked failed |
| Stuck `sending` items re-hydrated at launch | A function that resets `sending` to `pending`, with a comment about a crash between send and confirm |
| Tombstones | The sync routes' comment on returning removed rows with a deletion marker on the delta but not on the full load; the client filters on that marker and deletes from the cache |
| Snapshot modules | A set of module names treated as whole-view replacement rather than delta merge |
| Paged delta with a loop guard | A page size, a maximum page count, and a check that the cursor advanced before continuing |
| Failed items are surfaced, not dropped | Separate counters for pending and failed, exposed through the client's sync context, plus a discard action |
| Attachments queued as children of their parent | The queue orders parent rows first and the upload posts the parent's client UUID for the server to resolve |

### Traceability

| Claim | How verified |
|---|---|
| Three Application Identifiers, variable-length last | The element-string builder appends the fixed-length fields first and the batch field last, with a comment stating this removes the need for a separator |
| Weight as a zero-padded integer with implied decimals | Kilograms multiplied by 1000, rounded, range-checked, and padded to six digits; out-of-range values are omitted rather than truncated |
| Check digit validated before printing | A modulo-10 check-digit function applied after left-padding to 14 digits, returning null on failure |
| Batch sanitised to the permitted character set and length | A regex filter and a 20-character truncation |
| Inline SVG, generated offline | Both the barcode and QR helpers render SVG markup with comments stating the generation is fully offline and that the code never leaves the machine |
| Human-readable text alongside the bars | The builder returns a parenthesised HRI string next to the raw element string |
| QR error-correction level M | The QR options, with a comment giving the density-versus-tolerance reasoning |

### Integrations

| Claim | How verified |
|---|---|
| The e-invoicing library is confined to one adapter | The adapter file's header states that the core does not know the library and only this file does; the import appears nowhere else in scope |
| Defaults to the test environment | The environment is coerced to the test value unless it is explicitly the production one, with a comment marking it the safe default |
| Configuration produces a checklist, not an exception | A function returning a list of missing items, called before anything is attempted |
| A proof-of-life status call | A dedicated status-check function returning a structured result and comparing the authority's status code |
| Namespace-agnostic XML parsing | XPath expressions using `local-name()` with a comment stating that this is namespace-independent |
| Bank secrets encrypted, masked and mask-aware | The configuration save path calls the secret box on changed values and keeps the existing ciphertext when the posted value looks masked |
| Explicit sandbox/production per tenant | An environment field validated against an allow-list and stored per tenant |
| Three reconciliation paths converge on one service | The webhook endpoint, the operator endpoint and the cron endpoint all require and delegate to the same reconciliation service |
| The reconciliation service applies payments from a bank query | The operator path queries the bank, then applies the result and only marks paid when the query says so |
| Payment writes are transactional with history and audit | `beginTransaction`, the ledger update, a status-history insert and an audit insert, then `commit`, with a rollback in the catch |
| Divergences diagnosed separately | A dedicated action comparing expected against paid amounts |
| Unauthenticated endpoints use timing-safe token comparison | `hash_equals` against a value read from the environment, in both the webhook and the cron endpoint |
| Writes attributed to a technical user | Both endpoints read a technical user id from the environment and refuse to run without it |
| Access-control device: raw events stored first | An insert into a raw-event table before the processing service is invoked |
| Device dedupe by unique constraint | The insert's `catch` matches SQLSTATE `23000` and returns success to the device |
| A derived id when the device sends none | A SHA-256 over the identifying fields, truncated |
| Payload masking before storage | A recursive walk replacing values whose key matches a sensitive-name list, including biometric templates and photographs |
| Device auth by per-device secret plus optional IP allow-list | The webhook resolves the access point by its secret and then compares the remote address against a stored allow value when one is set |

### Multi-tenancy

| Claim | How verified |
|---|---|
| `tenant_id` on business tables and in every helper | The insert/update/delete helpers and every sync query in scope |
| Tenant resolved only from the session | The tenant accessor reads the session and exits on absence; the API writes the session from the token lookup; no in-scope authenticated route reads a tenant from the request |
| Roles tenant-scoped with a global fallback | The role query matches `tenant_id = ? OR tenant_id IS NULL` |
| Cross-tenant jobs iterate | The cron endpoint selects distinct active tenant ids and loops, locking and recording results per tenant |
| Tenant-scoped configuration with graceful degradation | The print component resolves company identity from tenant parameters, then a fallback table, then a placeholder, with a comment saying the screen must degrade rather than break |

### Self-criticism (each item was verified as present, not assumed)

| Claim | How verified |
|---|---|
| Global `$pdo` alongside a singleton | The connection file ends by assigning `$pdo = Database::getConnection();` at module level; `grep -rn "global \$pdo"` finds many callers in System B |
| Uploads handled per screen | The shared content-sniffing helper's own docblock says it *complements, without replacing,* the extension allow-list "already applied by each screen" |
| One very large service file | The domain-service file is 2,459 lines and defines 53 service functions (`grep -c`) |
| Almost no transactions in the domain services | `grep -c beginTransaction` on the domain-service file returns **1**, against **53** service functions defined in it. Across the whole in-scope core and API of System A, `grep -rn beginTransaction` returns **8** occurrences in six files — the API idempotency helper, two write routes, a badge helper, a content helper and one service. This is the basis for the claim that multi-step domain writes are frequently not atomic |
| Reads are only conventionally tenant-scoped | Read helpers take raw SQL; the tenant predicate is written by the caller. Every in-scope read includes it, and nothing structurally requires it — verified by reading the read helpers' signatures and the sync route queries |
| Migrations applied by hand | No runner, no version table, and comments throughout the sync routes referring to numbered migrations that may or may not be present in a given environment |
| The CSP still allows inline script | The policy string contains `'unsafe-inline'` in `script-src`, and the enforcement flag defaults to report-only, with in-source comments describing the nonce migration as pending |

---

## Considered and left out

Things I could have said, and did not, because I could not substantiate them here:

- **Anything numeric about operation.** Users, tenants, requests per day, uptime,
  data volume, response times, cost. None of it is observable from source, so none
  of it appears.
- **Total codebase size.** The README quotes only file counts for the directories
  actually read. The systems are larger than that; I do not know by how much
  without counting, so I counted only what I read and said exactly that.
- **Migration count.** Comments in the source refer to numbered migrations, and the
  highest number seen implies a lower bound — but the migration directory was not
  in scope, so no count is claimed.
- **That the webhook re-queries the bank internally.** The webhook endpoint's own
  service class was not in the in-scope files. What is verified is that the
  endpoint delegates to the same reconciliation service the operator and cron paths
  use, and that this service applies payments from a bank query. The README says
  exactly that and no more.
- **Token caching for the bank's OAuth flow.** The authentication component was not
  in scope. An earlier draft claimed tokens were cached until near expiry; it was
  removed.
- **Whether dependencies are committed or installed.** The code loads a Composer
  autoloader, but neither a manifest nor a lock file was in scope, so nothing is
  claimed about how the vendor tree gets onto the host. An earlier draft asserted
  "no Composer install as part of deployment" and it was removed.
- **That any of this is secure.** No such claim is made anywhere. The documents
  describe controls and reasoning. There has been no third-party audit, no
  penetration test, and no certification, and the README says so by never implying
  otherwise.
- **That the systems have no other weaknesses.** The "what I would do differently"
  list is what a careful read surfaced. It is not exhaustive, and it says so.

---

## Re-checking this

Every row above is a grep away from the source. The fastest re-check:

1. Count the files in the in-scope directories and compare with the scale section.
2. Grep for `apcu_|memcache|redis` and for `session_set_save_handler` — both should
   still be empty, or the README needs updating.
3. Grep for `global \$pdo`, `beginTransaction`, and `unsafe-inline` — the
   self-criticism section depends on all three.
4. Open the session/auth include and read the rotation comment. It is the anchor
   for the most-repeated claim in this repository.
