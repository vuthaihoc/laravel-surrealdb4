# SurrealDB 4 driver for Laravel

A Laravel 12 / 13 database driver for [SurrealDB 4](https://surrealdb.com): Eloquent, the query builder and
migrations over SurrealDB's PostgreSQL wire listener, which runs the PostgreSQL dialect of SQL against SurrealDB
tables.

> **Pre-release.** Tested on SurrealDB `4.0.0` (Docker) and on SurrealDB Cloud (a 4.0 nightly, November 2026).

The driver is Laravel's `pgsql` driver with SurrealDB's differences worked around. The [limits](#limits) are listed below.
Laravel's default tables and the drivers that use them are tested: the database cache (with locks), sessions,
authentication, password resets, notifications, the queue, job batches and failed jobs.

## Installation

```bash
composer require vuthaihoc/laravel-surrealdb4
```

```php
// config/database.php
'surrealdb' => [
    'driver' => 'surrealdb',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'namespace' => env('DB_NAMESPACE', 'main'),
    'database' => env('DB_DATABASE', 'app'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', 'root'),
    'charset' => 'utf8',
    'prefix' => '',
    'search_path' => 'public',
    'sslmode' => env('DB_SSLMODE', 'disable'),
    // 'conflict_retries' => 3,   // see "Transactions"
],
```

The listener selects SurrealDB's namespace and database with the startup database `namespace/database`. The driver
builds it from `namespace` and `database`; `'database' => 'main/app'` (or `'main.app'`) works too, without `namespace`.
SurrealDB creates a missing database.

Run SurrealDB locally with the PostgreSQL listener:

```bash
docker run -d --name surrealdb -p 127.0.0.1:5432:5432 surrealdb/surrealdb:v4.0.0 start --user root --pass root --postgres-bind 0.0.0.0:5432 memory
```

### SurrealDB Cloud

Cloud instances share the host `sql.surrealdb.earth`, which routes on `user@instance`. Set `instance` and the driver
appends it to the username:

```php
'host' => 'sql.surrealdb.earth',
'port' => 5432,
'instance' => env('DB_INSTANCE'),   // the instance id of <id>.surrealdb.earth
'namespace' => 'main',
'database' => 'app',
'username' => env('DB_USERNAME'),   // a database user: becomes "user@instance"
'password' => env('DB_PASSWORD'),
'sslmode' => 'require',
```

## Transactions

`DB::transaction()`, nested transactions (savepoints) and manual transactions work. SurrealDB aborts a transaction
that conflicts with another one ("Transaction conflict ... This transaction can be retried", SQLSTATE `40001` or
`XX000`). The driver:

- treats that error as a concurrency error, so `DB::transaction($callback, attempts: 3)` runs the callback again;
- runs a statement outside a transaction again, up to `conflict_retries` times (default 3, with a growing pause from 0.1 s
  to 1 s): SurrealDB wrote nothing;
- retries its own read-then-write transactions (see below) the same way, after the same pauses.

Migrations run outside a transaction, as on MySQL: SurrealDB Cloud does not enforce primary keys and unique constraints
created inside one. A migration that fails halfway is not rolled back.

## What the driver changes

| Laravel | On SurrealDB |
|---|---|
| migrations | run outside a transaction (see [Transactions](#transactions)) |
| prepared statements | sent unnamed (`PDO::PGSQL_ATTR_DISABLE_PREPARES`): pdo_pgsql's `DEALLOCATE` would abort the transaction |
| parameters in a nested query (`exists`, `whereHas()`, `in (select ...)`, a union branch, a subquery column) or in `HAVING` | SurrealDB reads them as text there, which matches only text columns: integers, floats and booleans are cast (`?::bigint`, `?::float8`), dates (`DateTimeInterface`) cast to `timestamp`, and date strings (`2026-01-02`, `2026-01-02 03:04:05`) inlined as literals |
| `update()` / `delete()` with joins, a limit, `like`, or a subquery in `where` | the ids are selected, then rows are written by id, in chunks of 1000, in a transaction (SurrealDB refuses those statements) |
| JSON path updates (`update(['meta->a->b' => 1])`, `meta->list[0]`) | the documents are read, changed in PHP and written back, in a transaction |
| `whereJsonContains('col', 'x')`, `whereJsonContains('col', ['x', 'y'])` | `'x' = any(col)` for each value; an object or a nested array throws `UnsupportedFeatureException` |
| number and boolean column defaults | written unquoted (SurrealDB does not coerce `'0'`) |
| `unique()`, `foreign()` | SurrealDB names them `{table}_{columns}_uniq` / `_fkey`; `getIndexes()`, `dropUnique()` and `dropForeign()` use those names |
| `enum()` | `varchar(255)`: SurrealDB has no `CHECK` constraints, the values are not checked |
| `comment()` | skipped (no `COMMENT`) |
| `dropIndex()` | skipped: `DROP INDEX` fails on SurrealDB 4.0.0. The index goes with its table |
| `lockForUpdate()`, `sharedLock()` | ignored (`FOR UPDATE` needs SurrealDB record ids) |
| `Schema::disableForeignKeyConstraints()` | does nothing: foreign keys stay checked |

## Limits

| Laravel | On SurrealDB |
|---|---|
| `renameColumn()`, `Schema::rename()`, `renameIndex()` | `UnsupportedFeatureException`: SurrealDB answers the statements without renaming |
| `updateFrom()` | `UnsupportedFeatureException` |
| JSON values | come back with their object keys sorted |
| JSON path updates with an expression or a query as the value | `UnsupportedFeatureException` |
| a uuid or numeric string compared in a nested query (`whereHas('posts', fn ($q) => $q->where('uuid', $uuid))`, `where('price', '9.99')`) | matches nothing: the parameter is text there. Pass numbers as `int`/`float`; compare uuids at the top level, or join |

### SurrealDB Cloud (4.0 nightly)

The Cloud build differs from 4.0.0 (see `tests/KnownIssues`, last group):

- A dropped and re-created table does not start its ids at 1: do not rely on id values.
- Dropping a table that has a foreign key takes 15–35 s and meets transaction conflicts (retried), so `migrate:fresh`
  is slow.
- A foreign key column reads as `true` inside a nested query: `whereIn('id', Post::select('user_id'))` matches nothing.
  Use `whereHas()`, `whereExists()` or a join, which compare the column instead of reading it.
- `->>` reads a JSON number, boolean, array or object as `false` (strings are right): `where('meta->count', 1)`,
  `whereJsonLength()` and selecting `meta->list` fail. Compare JSON strings, or read the whole column and decode it.
- `insert ... select` into a foreign key column fails.
- An update or delete comparing an indexed uuid column (`uuid('id')->primary()`) with a value changes no row: models
  with such keys (`HasUuids`) and database notifications (`markAsRead()`) cannot be updated or deleted. Use
  `string('id', 36)` keys.
- The first id of a new table, or after `truncate()`, taken inside a transaction makes it fail with a transaction
  conflict. Laravel does not retry its own transactions, so the first `Bus::batch()->dispatch()` after `migrate` can
  fail; pass `attempts` to your own `DB::transaction()` calls.

## Testing

```bash
docker run -d --name surrealdb4-test -p 127.0.0.1:5436:5432 surrealdb/surrealdb:v4.0.0 start --user root --pass root --postgres-bind 0.0.0.0:5432 memory
composer test                # Unit (no server) + Feature (SurrealDB on 127.0.0.1:5436)
composer test:known-issues   # SurrealDB issues the driver works around, asserting PostgreSQL behaviour: failures expected
composer phpstan
composer cs
```

The Feature tests drop and create their tables. Point them at another server with `SURREALDB_HOST`, `SURREALDB_PORT`,
`SURREALDB_NAMESPACE`, `SURREALDB_DATABASE`, `SURREALDB_USERNAME`, `SURREALDB_PASSWORD`, `SURREALDB_INSTANCE` and
`SURREALDB_SSLMODE` (use an empty database: `migrate:fresh` drops every table).

## License

MIT
