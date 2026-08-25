# The data layer, and why there is no ORM

## The constraint that decided it

Managed PHP hosting. No long-running processes, no orchestrator, no profiler
attached to production, and a team small enough that the person who writes a
query is the person who gets paged about it.

An ORM is not merely a library in that environment. It brings a schema
abstraction, a migration runner, a metadata cache that wants a writable
directory, and a query builder whose generated SQL you would inspect with tooling
that is not installed on that host. Every one of those assumes infrastructure
that is not there. Third-party code is used where it is unavoidable — signing
fiscal XML, rendering barcodes — and each of those dependencies is confined to a
single adapter file, precisely because a dependency is a liability on this stack.

So the data layer is PDO, prepared statements, and a small set of helpers that
fit in one file.

## What the thin layer actually provides

A small vocabulary, used everywhere, that makes the *right* thing the shortest
thing to type:

```
row(sql, params)      one row or null
rows(sql, params)     a list
value(sql, params)    a scalar
insert(table, data)   stamps tenant_id, created_by, updated_by; returns the id
update(table, id, d)  stamps updated_by; WHERE id = ? AND tenant_id = ? LIMIT 1
delete(table, id)     soft-deletes when the table has an `active` column
```

Three things are worth noticing about that list.

**The tenant scope is in the helper, not in the caller.** `insert()` sets
`tenant_id` from the session and `update()` puts it in the `WHERE` clause. A
developer cannot forget it on a write, because there is no parameter to forget.
Reads are the weak spot — a hand-written `SELECT` can still omit the scope — and
that asymmetry is discussed in [multi-tenancy.md](multi-tenancy.md).

**Deletion is inactivation when the table supports it.** Business records are
referenced by other business records; a hard delete either fails on a foreign key
or destroys history that someone will need to explain a number six months later.
When a hard delete is genuinely correct, a foreign-key violation is translated
into "this cannot be removed because other records depend on it" rather than
surfacing as a 500.

**Every write stamps who did it.** Not an audit log — an audit log exists
separately for authentication and permission events — but two columns that make
"who changed this row" answerable without one.

## Why not a query builder either

The queries in this system are not CRUD. A production report joins several
tables, aggregates over a date range, and pivots on a category. Written in SQL it
is something a database person can read, `EXPLAIN`, and tune. Written in a fluent
builder it becomes method chaining that produces SQL nobody reads until it is
slow.

The cost of that choice is real and shows up as repetition: similar `SELECT`
clauses appear in more than one screen, and a schema change means grepping. That
is a genuine maintenance tax, paid in exchange for queries whose performance
characteristics are visible in the source.

## Native prepares, and the rule it forces

`ATTR_EMULATE_PREPARES => false` is set globally. The driver sends SQL and values
separately, so a value can never be re-parsed as syntax. The cost is a rule the
whole codebase has to remember:

- **A named placeholder can appear only once per statement.** The same `:tenant`
  in an outer query and a subquery raises "Invalid parameter number". You bind
  `:tenant` and `:tenant_inner`. This is the one that bites, because it only
  appears on the queries complex enough to have subqueries, and only after
  emulation is switched off.
- **Placeholders are values, never identifiers or clauses.** `LIMIT :n` needs an
  explicit integer binding; `INTERVAL :days DAY` cannot be bound at all.

Both consequences are written out in
[`reference/Database.php`](../reference/Database.php), because "turn off emulated
prepares" is advice that circulates without its fallout attached.

## Connection settings that change behaviour

- `ERRMODE_EXCEPTION` — a failed write throws instead of quietly returning
  `false`. Without it, an unchecked return value is a write that never happened
  and a user who thinks it did.
- `FETCH_ASSOC` — one key per column instead of two.
- `STRINGIFY_FETCHES => false` — integers come back as integers, so `===`
  comparisons and JSON responses are honest about types.
- **The session time zone is pinned per connection.** This is not tidiness. The
  mobile client's synchronisation cursor is compared against `updated_at` values
  stamped by the database. When the PHP host and the managed database instance
  disagree about the wall clock, a cursor taken from PHP's clock can sit *ahead*
  of the database's, and records written in the gap are never returned by any
  subsequent delta query. They do not error. They are simply invisible, until
  someone notices a day of field data missing. The fix is two rules: pin the
  session time zone, and read "now" from the database whenever the value will be
  compared against database timestamps.

The corollary is a client-side guard: if the stored cursor is later than the
server time in the response, the client discards the cursor and re-runs a full
load. Cheap insurance against a class of bug that is otherwise silent.

## Schema evolution, and the compromise that admits it

Migrations are numbered SQL files applied by hand. There is no runner, no
`schema_migrations` table, and therefore no reliable answer to "which migrations
has this environment had". That is the weakness. What the code does about it is
a compromise worth describing honestly:

```php
if (has_column('items', 'variety_id')) {
    $select .= ', v.name AS variety';
    $join   .= ' LEFT JOIN varieties v ON v.id = i.variety_id';
}
```

`has_column()` asks `information_schema` once per column per request and caches
the answer. Queries are then assembled to match the schema that is actually
present, so an environment one migration behind degrades — the field is missing
from the response — instead of throwing a `PDOException` on every request.

This is a real engineering decision with a real cost. It keeps a partially
migrated environment usable, which matters when the person applying migrations is
also the person answering the support call. It also means the schema is not a
contract, drift can persist unnoticed for months, and every conditional column is
a branch that no test covers. A migration runner with a version table would make
the whole pattern unnecessary. Its absence is the single largest item in the
"what I would do differently" list.

## What is deliberately not here

- **No repository or entity layer.** Screens talk to the helpers directly. For a
  team of one to three, an extra layer of indirection is a cost with no
  corresponding benefit.
- **No application cache.** No Redis on the host, and file-based caching on
  shared storage buys little. Every page hits the database. The mitigation is
  indexes and narrow queries, not a cache.
- **No connection pooling.** PHP-FPM opens a connection per request. Nothing to
  be done about that within the constraints, and at this scale nothing that needs
  doing.
