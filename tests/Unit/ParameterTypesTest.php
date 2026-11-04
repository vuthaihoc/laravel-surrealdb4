<?php

namespace LaravelSurrealDb\Tests\Unit;

use DateTimeImmutable;
use LaravelSurrealDb\Query\ParameterTypes;
use PHPUnit\Framework\TestCase;

class ParameterTypesTest extends TestCase
{
    /**
     * @param  list<mixed>  $bindings
     */
    protected static function sql(string $sql, array $bindings): string
    {
        return ParameterTypes::cast($sql, $bindings)[0];
    }

    public function test_top_level_placeholders_stay_untyped(): void
    {
        $sql = 'select * from "users" where "id" = ? and "active" = ? and "score" > ? and "id" in (?, ?)';

        $this->assertSame($sql, self::sql($sql, [1, true, 1.5, 2, 3]));
        $this->assertSame('insert into "t" ("a", "b") values (?, ?)', self::sql('insert into "t" ("a", "b") values (?, ?)', [1, 'x']));
    }

    public function test_placeholders_in_nested_queries_are_typed(): void
    {
        $this->assertSame(
            'select exists(select * from "users" where "id" = ?::bigint and "name" = ? and ("active" = (?::bigint <> 0) or "score" > ?::float8)) as "exists"',
            self::sql('select exists(select * from "users" where "id" = ? and "name" = ? and ("active" = ? or "score" > ?)) as "exists"', [1, 'Ada', false, 2.5])
        );

        $this->assertSame(
            '(select "name" from "users" where "id" = ?::bigint) union (select "name" from "users" where "id" = ?::bigint)',
            self::sql('(select "name" from "users" where "id" = ?) union (select "name" from "users" where "id" = ?)', [1, 2])
        );

        $this->assertSame(
            'select * from "users" where "id" = ? and "id" in (select "user_id" from "posts" where "votes" in (?::bigint, ?::bigint)) and "votes" > ?',
            self::sql('select * from "users" where "id" = ? and "id" in (select "user_id" from "posts" where "votes" in (?, ?)) and "votes" > ?', [1, 2, 3, 4])
        );
    }

    public function test_placeholders_in_having_are_typed(): void
    {
        $this->assertSame(
            'select "g", count(*) as "n" from "t" where "g" > ? group by "g" having count(*) > ?::bigint and (sum("x") < ?::float8) order by "g" limit 5',
            self::sql('select "g", count(*) as "n" from "t" where "g" > ? group by "g" having count(*) > ? and (sum("x") < ?) order by "g" limit 5', [1, 2, 3.5])
        );

        $this->assertSame(
            'select * from "having_t" where "having_c" = ? and "x" in (select "g" from "t" group by "g" having count(*) > ?::bigint) and "y" = ?',
            self::sql('select * from "having_t" where "having_c" = ? and "x" in (select "g" from "t" group by "g" having count(*) > ?) and "y" = ?', [1, 2, 3])
        );
    }

    public function test_quotes_comments_and_escaped_question_marks_are_skipped(): void
    {
        $this->assertSame(
            'select * from "a(select ?" where exists (select 1 where \'it\'\'s (select ?)\' = ? and "x" = ?::bigint and "j" ?? \'k\') -- (select ?'."\n".'and "y" = ?',
            self::sql('select * from "a(select ?" where exists (select 1 where \'it\'\'s (select ?)\' = ? and "x" = ? and "j" ?? \'k\') -- (select ?'."\n".'and "y" = ?', ['s', 5, 6])
        );
    }

    public function test_dates_in_nested_queries_are_typed_or_inlined(): void
    {
        $date = new DateTimeImmutable('2026-01-02 03:04:05');
        $sql = 'select * from "users" where "created_at" > ? and exists (select 1 from "posts" where "posts"."at" > ? and "posts"."day" = ? and "title" = ? and "at" < ?)';

        [$cast, $bindings] = ParameterTypes::cast($sql, ['2026-01-01', $date, '2026-01-02', 'x', '2026-01-02T03:04:05.123+07:00']);

        $this->assertSame('select * from "users" where "created_at" > ? and exists (select 1 from "posts" where "posts"."at" > ?::timestamp and "posts"."day" = \'2026-01-02\' and "title" = ? and "at" < \'2026-01-02T03:04:05.123+07:00\')', $cast);
        $this->assertSame(['2026-01-01', $date, 'x'], $bindings);
    }

    public function test_only_date_strings_are_inlined(): void
    {
        $sql = 'select exists (select 1 from "t" where "a" = ? and "b" = ? and "c" = ?) as "e"';
        $bindings = ["2026-01-02' or 1=1 --", '2026-13-45x', '12026-01-02'];

        $this->assertSame([$sql, $bindings], ParameterTypes::cast($sql, $bindings));
    }

    public function test_named_bindings_are_kept_when_nothing_is_inlined(): void
    {
        $bindings = ['id' => 5];

        $this->assertSame(['select exists (select 1 from "t" where "id" = :id) as "e"', $bindings], ParameterTypes::cast('select exists (select 1 from "t" where "id" = :id) as "e"', $bindings));
    }
}
