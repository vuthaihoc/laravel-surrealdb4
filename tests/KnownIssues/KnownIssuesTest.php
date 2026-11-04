<?php

namespace LaravelSurrealDb\Tests\KnownIssues;

use Throwable;

/**
 * SurrealDB 4.0 issues seen through its PostgreSQL listener. The first
 * groups fail on SurrealDB 4.0.0; the last one fails only on SurrealDB Cloud
 * (a 4.0 nightly) and passes on 4.0.0.
 *
 * Last verified 2026-11-04: SurrealDB 4.0.0, 22 failures and 3 passes (the
 * Cloud group); SurrealDB Cloud, 25 failures.
 */
class KnownIssuesTest extends TestCase
{
    // ---------------------------------------------------------------- statements and parameters

    /**
     * pdo_pgsql deallocates a native prepared statement with DEALLOCATE, which
     * SurrealDB does not know: inside a transaction the error aborts it (25P02).
     * Driver workaround: PDO::PGSQL_ATTR_DISABLE_PREPARES (unnamed statements).
     */
    public function test_deallocate_inside_a_transaction(): void
    {
        $this->runSql('create table '.$this->table('dealloc').' ("id" bigserial primary key, "a" integer not null)');
        $pdo = self::connect(nativePrepares: true);

        try {
            $pdo->beginTransaction();

            foreach ([1, 2] as $value) {
                $statement = $pdo->prepare('insert into '.$this->table('dealloc').' ("a") values (?)');
                $statement->execute([$value]);
                $statement = null;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $this->fail($e->getMessage());
        }

        $this->assertSame([['n' => 2]], $this->runSql('select count(*) as n from '.$this->table('dealloc')));
    }

    /**
     * Inside a nested query (exists, in, a subquery column, a union branch) an
     * untyped parameter is not typed from what it is compared with: an integer
     * stays text and matches nothing.
     * Driver workaround: Query\ParameterTypes casts those placeholders.
     */
    public function test_untyped_parameters_in_nested_queries(): void
    {
        $rows = $this->runSql(
            'create table '.$this->table('nested').' ("id" bigserial primary key, "a" integer not null)',
            'insert into '.$this->table('nested').' ("a") values (5)',
            ['select exists(select 1 from '.$this->table('nested').' where "a" = ?) as "e"', [5]],
        );

        $this->assertSame([['e' => true]], $rows);
    }

    /**
     * The same in a HAVING clause.
     * Driver workaround: Query\ParameterTypes.
     */
    public function test_untyped_parameters_in_having(): void
    {
        $rows = $this->runSql(
            'create table '.$this->table('having').' ("id" bigserial primary key, "a" integer not null)',
            'insert into '.$this->table('having').' ("a") values (5), (5), (6)',
            ['select "a" from '.$this->table('having').' group by "a" having count(*) > ?', [1]],
        );

        $this->assertSame([['a' => 5]], $rows);
    }

    /**
     * The same for strings: in a nested query a parameter is text, which
     * matches no uuid, numeric, date or timestamp column.
     * Driver workaround: dates and date strings are typed or inlined
     * (Query\ParameterTypes); uuid and numeric strings are not.
     */
    public function test_string_parameters_in_nested_queries(): void
    {
        $uuid = '7f0c8b0e-4b4a-4c1a-9b65-2f6b4b9f0e11';
        $table = $this->table('nested_strings');

        $rows = $this->runSql(
            "create table {$table} (\"id\" bigserial primary key, \"u\" uuid, \"n\" numeric(10,2), \"t\" timestamp(0) without time zone)",
            ["insert into {$table} (\"u\", \"n\", \"t\") values (?, ?, ?)", [$uuid, '1.50', '2026-01-02 03:04:05']],
            ["select exists(select 1 from {$table} where \"u\" = ?) as \"u\", exists(select 1 from {$table} where \"n\" = ?) as \"n\", exists(select 1 from {$table} where \"t\" = ?) as \"t\"", [$uuid, '1.50', '2026-01-02 03:04:05']],
        );

        $this->assertSame([['u' => true, 'n' => true, 't' => true]], $rows);
    }

    // ---------------------------------------------------------------- schema

    /**
     * A quoted default is not coerced to the column type ("Expected `int` but found `'0'`").
     * Driver workaround: Schema\Grammar writes number and boolean defaults unquoted.
     */
    public function test_quoted_number_default(): void
    {
        $rows = $this->runSql(
            'create table '.$this->table('defaults')." (\"id\" bigserial primary key, \"n\" integer not null default '0', \"b\" boolean not null default '1')",
            'insert into '.$this->table('defaults').' default values',
            'select "n", "b" from '.$this->table('defaults'),
        );

        $this->assertSame([['n' => 0, 'b' => true]], $rows);
    }

    /**
     * No CHECK constraints.
     * Driver workaround: enum() is a plain varchar(255).
     */
    public function test_check_constraint(): void
    {
        $this->runSql('create table '.$this->table('checks').' ("a" varchar(255) check ("a" in (\'x\', \'y\')))');

        $this->assertSame([], $this->runSql('select * from '.$this->table('checks')));
    }

    /**
     * No COMMENT statement.
     * Driver workaround: comments are skipped.
     */
    public function test_comment(): void
    {
        $this->runSql(
            'create table '.$this->table('comments').' ("a" integer)',
            'comment on column '.$this->table('comments').'."a" is \'A\'',
        );

        $this->addToAssertionCount(1);
    }

    /**
     * A constraint is renamed `{table}_{columns}_uniq` / `_fkey`: dropping it by
     * the name it was created with fails.
     * Driver workaround: dropUnique() and dropForeign() use SurrealDB's names.
     */
    public function test_constraint_keeps_its_name(): void
    {
        $this->runSql(
            'create table '.$this->table('names').' ("id" bigserial primary key, "a" integer)',
            'alter table '.$this->table('names').' add constraint "my_unique" unique ("a")',
            'alter table '.$this->table('names').' drop constraint "my_unique"',
        );

        $this->addToAssertionCount(1);
    }

    /**
     * DROP INDEX fails ("Expected `what` but found `NONE`").
     * Driver workaround: dropIndex() is skipped.
     */
    public function test_drop_index(): void
    {
        $this->runSql(
            'create table '.$this->table('indexes').' ("id" bigserial primary key, "a" integer)',
            'create index "'.$this->prefix.'a_index" on '.$this->table('indexes').' ("a")',
            'drop index "'.$this->prefix.'a_index"',
        );

        $this->addToAssertionCount(1);
    }

    /**
     * RENAME COLUMN succeeds without renaming.
     * Driver workaround: renameColumn() throws UnsupportedFeatureException.
     */
    public function test_rename_column(): void
    {
        $rows = $this->runSql(
            'create table '.$this->table('cols').' ("a" integer)',
            'insert into '.$this->table('cols').' ("a") values (1)',
            'alter table '.$this->table('cols').' rename column "a" to "b"',
            'select "b" from '.$this->table('cols'),
        );

        $this->assertSame([['b' => 1]], $rows);
    }

    /**
     * RENAME TO succeeds without renaming.
     * Driver workaround: Schema::rename() throws UnsupportedFeatureException.
     */
    public function test_rename_table(): void
    {
        $this->table('renamed');

        $rows = $this->runSql(
            'create table '.$this->table('old').' ("a" integer)',
            'alter table '.$this->table('old').' rename to '.$this->table('renamed'),
            'select * from '.$this->table('renamed'),
        );

        $this->assertSame([], $rows);
    }

    /**
     * SET CONSTRAINTS is not parsed.
     * Driver workaround: disableForeignKeyConstraints() runs nothing.
     */
    public function test_set_constraints(): void
    {
        $this->runSql('set constraints all deferred');

        $this->addToAssertionCount(1);
    }

    /**
     * pg_attrdef is empty: column defaults are not in the catalog.
     * Driver workaround: getColumns() reads information_schema.columns.
     */
    public function test_column_defaults_in_pg_attrdef(): void
    {
        $this->runSql('create table '.$this->table('attrdef').' ("id" bigserial primary key, "n" integer default 7)');

        $rows = $this->runSql('select pg_get_expr(d.adbin, d.adrelid) as "default" from pg_attrdef d join pg_class c on c.oid = d.adrelid join pg_attribute a on a.attrelid = c.oid and a.attnum = d.adnum where c.relname = \''.$this->prefix.'attrdef\' and a.attname = \'n\'');

        $this->assertSame([['default' => '7']], $rows);
    }

    // ---------------------------------------------------------------- queries and writes

    /**
     * No jsonb containment operator.
     * Driver workaround: whereJsonContains() compiles to `? = any(column)`.
     */
    public function test_jsonb_containment(): void
    {
        $rows = $this->runSql(
            'create table '.$this->table('contains').' ("id" bigserial primary key, "j" json)',
            'insert into '.$this->table('contains').' ("j") values (\'{"tags": ["a", "b"]}\')',
            'select "id" from '.$this->table('contains').' where ("j"->\'tags\') @> \'["a"]\'',
        );

        $this->assertCount(1, $rows);
    }

    /**
     * A string literal cast to jsonb is parsed as a number ("invalid input
     * syntax for type: numeric"). Not needed by the driver: JSON is written as
     * plain text parameters.
     */
    public function test_jsonb_literal(): void
    {
        $this->assertSame([['v' => '1']], $this->runSql('select \'{"a": 1}\'::jsonb->>\'a\' as "v"'));
    }

    /**
     * No jsonb_set().
     * Driver workaround: JSON path updates read, change and write the documents.
     */
    public function test_jsonb_set(): void
    {
        $rows = $this->runSql(
            'create table '.$this->table('set').' ("id" bigserial primary key, "j" json)',
            'insert into '.$this->table('set').' ("j") values (\'{"a": 1}\')',
            'select jsonb_set("j"::jsonb, \'{a}\', \'2\')->>\'a\' as "v" from '.$this->table('set'),
        );

        $this->assertSame([['v' => '2']], $rows);
    }

    /**
     * A JSON document comes back with its keys sorted. Not worked around.
     */
    public function test_json_key_order(): void
    {
        $rows = $this->runSql(
            'create table '.$this->table('json').' ("id" bigserial primary key, "j" json)',
            'insert into '.$this->table('json').' ("j") values (\'{"b":1,"a":2}\')',
            'select "j" from '.$this->table('json'),
        );

        $this->assertSame([['j' => '{"b":1,"a":2}']], $rows);
    }

    /**
     * UPDATE and DELETE refuse LIKE in their WHERE clause.
     * Driver workaround: Query\Builder writes by id.
     */
    public function test_like_in_update(): void
    {
        $this->runSql(
            'create table '.$this->table('like').' ("id" bigserial primary key, "a" varchar(255))',
            'insert into '.$this->table('like').' ("a") values (\'yes\')',
            'update '.$this->table('like').' set "a" = \'no\' where "a" like \'y%\'',
        );

        $this->assertSame([['a' => 'no']], $this->runSql('select "a" from '.$this->table('like')));
    }

    /**
     * UPDATE and DELETE refuse a subquery in their WHERE clause.
     * Driver workaround: Query\Builder writes by id.
     */
    public function test_subquery_in_delete(): void
    {
        $this->runSql(
            'create table '.$this->table('sub').' ("id" bigserial primary key, "a" integer)',
            'insert into '.$this->table('sub').' ("a") values (1), (2)',
            'delete from '.$this->table('sub').' where "id" in (select "id" from '.$this->table('sub').' where "a" = 1)',
        );

        $this->assertSame([['a' => 2]], $this->runSql('select "a" from '.$this->table('sub')));
    }

    /**
     * No ctid column, which PostgreSQL's UPDATE/DELETE with joins or a limit uses.
     * Driver workaround: Query\Builder writes by id.
     */
    public function test_ctid(): void
    {
        $this->runSql(
            'create table '.$this->table('ctid').' ("id" bigserial primary key)',
            'select "ctid" from '.$this->table('ctid'),
        );

        $this->addToAssertionCount(1);
    }

    /**
     * FOR UPDATE fails on tables with plain columns ("needs record ids").
     * Driver workaround: lockForUpdate() and sharedLock() are dropped.
     */
    public function test_for_update(): void
    {
        $rows = $this->runSql(
            'create table '.$this->table('lock').' ("id" bigserial primary key, "a" integer)',
            'insert into '.$this->table('lock').' ("a") values (1)',
            'select "a" from '.$this->table('lock').' where "a" = 1 for update',
        );

        $this->assertSame([['a' => 1]], $rows);
    }

    /**
     * No ORDER BY inside an aggregate (Laravel's getIndexes() uses it).
     * Driver workaround: Schema\Builder::getIndexes() orders the columns in PHP.
     */
    public function test_ordered_aggregate(): void
    {
        $rows = $this->runSql(
            'create table '.$this->table('agg').' ("id" bigserial primary key, "a" varchar(255))',
            'insert into '.$this->table('agg').' ("a") values (\'b\'), (\'a\')',
            'select string_agg("a", \',\' order by "a") as "v" from '.$this->table('agg'),
        );

        $this->assertSame([['v' => 'a,b']], $rows);
    }

    // ---------------------------------------------------------------- SurrealDB Cloud (4.0 nightly) only

    /**
     * Cloud: a column with a foreign key reads as `true` (typed boolean) inside
     * a nested query, so `id in (select user_id ...)` matches nothing.
     * Not worked around: compare with whereColumn()/exists (whereHas()) instead.
     */
    public function test_foreign_key_column_in_a_nested_query(): void
    {
        [['id' => $id]] = $this->runSql(
            'create table '.$this->table('fk_users').' ("id" bigserial primary key, "name" varchar(255))',
            'create table '.$this->table('fk_posts').' ("id" bigserial primary key, "user_id" bigint not null)',
            'alter table '.$this->table('fk_posts').' add constraint "'.$this->prefix.'fk" foreign key ("user_id") references '.$this->table('fk_users').' ("id")',
            'insert into '.$this->table('fk_users').' ("name") values (\'Ada\') returning "id"',
        );

        $rows = $this->runSql(
            ['insert into '.$this->table('fk_posts').' ("user_id") values (?)', [$id]],
            'select "name" from '.$this->table('fk_users').' where "id" in (select "user_id" from '.$this->table('fk_posts').')',
        );

        $this->assertSame([['name' => 'Ada']], $rows);
    }

    /**
     * Cloud: `->>`, `#>>` and json_extract_path_text() return false for a
     * number, boolean, array or object (strings are right), so
     * `where('meta->n', 1)`, whereJsonLength() and `value('meta->list')` fail.
     * Not worked around.
     */
    public function test_text_of_a_non_string_json_value(): void
    {
        $rows = $this->runSql(
            'create table '.$this->table('json_text').' ("id" bigserial primary key, "j" json)',
            'insert into '.$this->table('json_text').' ("j") values (\'{"n": 1, "l": [1, 2]}\')',
            'select "j"->>\'n\' as "n", "j"->>\'l\' as "l", jsonb_array_length(("j"->\'l\')::jsonb) as "len" from '.$this->table('json_text'),
        );

        $this->assertSame([['n' => '1', 'l' => '[1,2]', 'len' => 2]], $rows);
    }

    /**
     * Cloud: a new table's serial column does not start at 1 when a table of
     * that name existed before. Not worked around: do not rely on ids.
     */
    public function test_serial_of_a_recreated_table_starts_at_one(): void
    {
        $create = 'create table '.$this->table('serial').' ("id" bigserial primary key, "a" integer)';

        $rows = $this->runSql(
            $create,
            'insert into '.$this->table('serial').' ("a") values (1)',
            'drop table '.$this->table('serial'),
            $create,
            'insert into '.$this->table('serial').' ("a") values (1) returning "id"',
        );

        $this->assertSame([['id' => 1]], $rows);
    }
}
