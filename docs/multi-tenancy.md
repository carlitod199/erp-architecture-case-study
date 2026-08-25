# Multi-tenancy

Both systems are multi-tenant: one deployment, one database, many customer
organisations, a `tenant_id` column on every business table.

## Why a shared schema

The alternatives are a database per tenant or a schema per tenant. Both give
stronger isolation, and both were rejected for the same reason: **migrations**.

Migrations here are numbered SQL files applied by hand. With one shared schema
that is one careful operation. With a schema per tenant it is N careful
operations that must all succeed, on shared hosting, with no runner, no
transactional DDL in MySQL, and no way to roll back halfway through. The
isolation gained would be paid for with a deployment procedure that is
guaranteed to drift.

That is the honest reasoning. It is a constraint-driven decision, not an
architectural preference, and at a different scale — or with a migration runner —
it would be reconsidered.

## Where the tenant comes from

Exactly one place: the session, established at login. Never from a request
parameter, never from a header, never from a URL segment on an authenticated
route.

This is the rule that prevents the worst bug the system could have. The moment a
tenant id can be *supplied* by the caller, every endpoint that accepts it is one
missing check away from cross-tenant access. Deriving it from the authenticated
session means there is nothing to check, because there is nothing to supply.

The API path establishes exactly the same session shape. The mobile client
authenticates with an opaque bearer token; the token lookup resolves the user and
the tenant, and populates the same session keys the web login populates. Every
helper, service and query below that line then works identically for both entry
points, with one tenant-resolution path instead of two. That single decision is
what made the API a thin layer rather than a parallel implementation.

## Enforcement, and where it is weakest

**Writes are structurally safe.** The insert helper stamps `tenant_id` from the
session; the update and delete helpers put it in the `WHERE` clause. There is no
parameter for a caller to forget.

**Reads are conventionally safe.** A hand-written `SELECT` has to include the
scope, and nothing forces it. This is the real gap, and it is worth being precise
about rather than glossing:

- Every read path *does* filter by tenant today.
- Nothing *prevents* the next one from omitting it.
- A missing scope on a read fails silently — the query works, it just returns
  another organisation's rows.

What reduces the exposure in practice:

- A missing session tenant is a hard stop, not a default. There is no "tenant 0"
  fallback that quietly reads everything.
- Read helpers make the scoped form the shortest form to write.
- Foreign-key joins inherit the scope from a scoped parent, so the shape of the
  data model does some of the work.

What would actually close it, in increasing order of cost:

1. A test that greps every `SELECT` in the codebase for a tenant predicate. Crude,
   fast, and would have caught anything.
2. A required scope argument on the read helpers, so an unscoped read is an
   explicit, greppable, reviewable exception.
3. MySQL views or row-level security equivalents, so the database enforces it.

Item 1 costs an afternoon. It is not done, and that is a fair criticism.

## Tenant-scoped configuration

Configuration is per tenant, not global: integration credentials, fiscal
settings, document headers, feature availability. A parameter lookup resolves
tenant setting → sensible default → placeholder, and screens degrade rather than
break when a tenant has not filled something in. A blank company address prints
a placeholder on the document; it does not throw on the print screen.

Secrets in that configuration are encrypted with a key derived per purpose, and
the tenant id is bound into the ciphertext as associated data — so a credential
row copied from one tenant to another fails to decrypt rather than silently
authenticating as the wrong organisation. See
[`reference/SecretBox.php`](../reference/SecretBox.php).

## Roles and permissions across tenants

Roles are tenant-scoped with a global fallback: a role row belonging to the
tenant, or a system role with a null tenant. Permission slugs are global
vocabulary; the *grants* are per tenant.

When the role tables have not been populated for a tenant — a real state during
onboarding — there is a hard-coded fallback grant per role name. This keeps a new
tenant usable on day one. It is also a second source of truth for authorisation,
which is a genuine smell: two places can answer "what may this role do", and they
can disagree. The fallback should be a seed migration, not a code path.

## Background jobs

The scheduled reconciliation sweep can run for one tenant or for all active
tenants. Per-tenant iteration, per-tenant advisory lock, per-tenant result. One
tenant's failure is recorded and the loop continues; it does not abort the run
for everyone else.

This is where a shared-schema design earns some of its cost back: a cross-tenant
job is a loop, not an orchestration problem.

## What is genuinely shared, and the risk it carries

| Shared | Consequence |
|---|---|
| Database connection | One tenant's expensive query is everyone's slow page |
| PHP process pool | One tenant's load is everyone's queue depth |
| Uploads directory | Tenant-prefixed paths; a path-construction bug crosses tenants |
| Session storage | Filesystem sessions, one directory, no tenant separation |

None of these is mitigated by architecture. They are mitigated by scale — a small
number of tenants of comparable size — and that mitigation expires. The first
tenant that is an order of magnitude larger than the others makes the noisy
neighbour problem real, and the answer at that point is a separate deployment for
that tenant, not a rewrite.
