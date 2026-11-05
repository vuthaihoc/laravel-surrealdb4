# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project Overview

`vuthaihoc/laravel-surrealdb4`: a Laravel 12/13 database driver (`surrealdb`) for SurrealDB 4 over its PostgreSQL wire listener (`--postgres-bind`), built on Laravel's `pgsql` classes. Sibling packages: `../laravel-xtdb2`, `../laravel-matrixone`. `scratch/probes/` holds throwaway probes (excluded from Pint).

## Commands

- `composer test` — PHPUnit. `Unit` needs no server; `Feature` needs SurrealDB on 127.0.0.1:5436 (`docker run -d --name surrealdb4-test -p 127.0.0.1:5436:5432 surrealdb/surrealdb:v4.0.0 start --user root --pass root --postgres-bind 0.0.0.0:5432 memory`). Feature tests must pass twice in a row; `LaravelTablesTest` runs Testbench's default migrations and Laravel's database drivers on them. `SURREALDB_*` variables point them elsewhere (SurrealDB Cloud: `SURREALDB_HOST=sql.surrealdb.earth SURREALDB_INSTANCE=<id> SURREALDB_SSLMODE=require`); they drop tables, and the migration test runs `migrate:fresh`, which drops every table of the database: use an empty one. Never write credentials into the repository.
- `composer test:known-issues` — `tests/KnownIssues`: SurrealDB bugs and PostgreSQL differences in plain SQL, asserting PostgreSQL behaviour. Excluded from `composer test`; failures are expected until SurrealDB fixes them (a test that starts passing means a workaround can be reconsidered). Add one for every new SurrealDB limit the driver works around. The last group fails only on SurrealDB Cloud.
- `composer phpstan` (level 8, larastan), `composer cs` / `composer cs:fix` (Pint).

## Architecture

- `src/SurrealDbServiceProvider.php` — binds `db.connector.surrealdb` and `Connection::resolverFor('surrealdb')`.
- `src/Connectors/SurrealDbConnector.php` — startup database `namespace/database` (from `namespace` + `database`, or `ns/db`, or `ns.db`); `instance` appends `@instance` to the username (Cloud routing); `PDO::PGSQL_ATTR_DISABLE_PREPARES` (pdo_pgsql's `DEALLOCATE` aborts SurrealDB transactions); no `synchronous_commit`.
- `src/SurrealDbConnection.php` — `runQueryCallback()` applies `Query\ParameterTypes`; SurrealDB's "Transaction conflict ... can be retried" (40001 or XX000) is a concurrency error (`DB::transaction($cb, $attempts)` retries) and a statement outside a transaction runs again up to `conflict_retries` times (default 3).
- `src/Query/ParameterTypes.php` — placeholder scanner (skips quoted strings, identifiers, comments; keeps `??`). Inside nested queries and HAVING, SurrealDB reads parameters as text: ints/floats/bools are cast, `DateTimeInterface` → `?::timestamp`, date strings are inlined as literals (and removed from the bindings).
- `src/Query/Builder.php` — `update()`/`delete()` with joins, a limit, LIKE or subqueries select the ids and write by id in chunks (SurrealDB refuses those statements, and has no `ctid`); JSON path updates read-modify-write documents; `whereJsonContains()` of lists as one `= any()` per value. Both run in a transaction retried on conflicts.
- `src/Query/Grammar.php` — locks dropped; `whereJsonContains` → `(? = any(col))`; `whereJsonContainsKey` → `(col->'key') is not null`; update/delete with joins or limit and `updateFrom` throw (the builder handles them first).
- `src/Schema/Grammar.php` — no schema transactions (migrations run outside one: Cloud does not enforce keys created in a transaction); unquoted number/boolean defaults; `enum` → `varchar(255)`; no `drop identity`; unique/foreign names mapped to SurrealDB's `{table}_{cols}_uniq|_fkey`; `dropIndex()`, comments skipped; renames throw `UnsupportedFeatureException`; `getColumns()` from `information_schema.columns` (pg_attrdef is empty).
- `src/Schema/Builder.php` — `getIndexes()`/`getForeignKeys()` from `pg_index`/`pg_constraint` with column names resolved in PHP (no ordered aggregates, no `unnest ... with ordinality`); foreign key toggles are no-ops.

## SurrealDB pitfalls (4.0.0)

- No CHECK, COMMENT, ALTER INDEX, SET CONSTRAINTS; DROP INDEX fails; RENAME COLUMN / RENAME TO succeed without renaming.
- No `@>`, `?` (key exists), `jsonb_set`, `jsonb_array_elements`; `'...'::jsonb` literals fail; JSON object keys come back sorted.
- UPDATE/DELETE refuse LIKE, subqueries, joins and limits; FOR UPDATE needs record ids.
- Parameters in nested queries and HAVING are text (see ParameterTypes); a uuid column never matches a parameter there.
- SurrealDB Cloud (4.0 nightly): ids do not restart after a table is re-created; primary keys and unique constraints created inside a transaction are not enforced; UPDATE/DELETE comparing an indexed uuid column match no row; the first serial value of a new (or `truncate ... restart identity`) table taken inside a transaction conflicts; dropping a table with a foreign key takes 15–35 s and meets 409 conflicts; a foreign key column reads `true` inside a nested query; `->>` of a non-string JSON value is `false`; `insert ... select` into a foreign key column fails.

## Rules

- Every grammar or scanner change needs a unit test, and a feature test against SurrealDB when behaviour depends on the server. Feature tests must not rely on id values (Cloud).
- Code comments and docs in English.
