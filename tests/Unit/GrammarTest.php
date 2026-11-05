<?php

namespace LaravelSurrealDb\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use LaravelSurrealDb\Exceptions\UnsupportedFeatureException;
use LaravelSurrealDb\Query\Builder;
use LaravelSurrealDb\SurrealDbConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GrammarTest extends TestCase
{
    protected SurrealDbConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new SurrealDbConnection(fn () => throw new RuntimeException('No server in unit tests.'), 'app', '', ['driver' => 'surrealdb']);
        $this->connection->useDefaultSchemaGrammar();
    }

    protected function query(): Builder
    {
        return $this->connection->query();
    }

    /**
     * @return list<string>
     */
    protected function schemaSql(string $table, callable $callback): array
    {
        $blueprint = new Blueprint($this->connection, $table, $callback);

        return $blueprint->toSql();
    }

    public function test_locks_are_dropped(): void
    {
        $this->assertSame('select * from "users" where "id" = ?', $this->query()->from('users')->where('id', 1)->lockForUpdate()->toSql());
        $this->assertSame('select * from "jobs"', $this->query()->from('jobs')->lock('for update skip locked')->toSql());
    }

    public function test_json_contains_uses_any(): void
    {
        $query = $this->query()->from('users')->whereJsonContains('meta->tags', 'x');
        $this->assertSame('select * from "users" where (? = any(("meta"->\'tags\')))', $query->toSql());
        $this->assertSame(['x'], $query->getBindings());

        $query = $this->query()->from('users')->whereJsonDoesntContain('tags', ['a', 'b']);
        $this->assertSame('select * from "users" where not ((? = any(("tags"))) and (? = any(("tags"))))', $query->toSql());
        $this->assertSame(['a', 'b'], $query->getBindings());

        $query = $this->query()->from('users')->whereJsonContains('tags', [5]);
        $this->assertSame('select * from "users" where (? = any(("tags")))', $query->toSql());
        $this->assertSame([5], $query->getBindings());
    }

    public function test_json_contains_rejects_documents(): void
    {
        $this->expectException(UnsupportedFeatureException::class);

        $this->query()->from('users')->whereJsonContains('meta', ['theme' => 'dark']);
    }

    public function test_json_contains_key_checks_for_null(): void
    {
        $this->assertSame(
            'select * from "users" where (("meta"->\'n\')->\'k\') is not null',
            $this->query()->from('users')->whereJsonContainsKey('meta->n->k')->toSql()
        );
    }

    public function test_writes_needing_keys(): void
    {
        $this->assertFalse($this->query()->from('users')->where('id', 1)->whereIn('id', [1, 2])->whereNull('deleted_at')->needsKeyedWrite());
        $this->assertTrue($this->query()->from('users')->where('name', 'like', 'a%')->needsKeyedWrite());
        $this->assertTrue($this->query()->from('users')->whereLike('name', 'a%')->needsKeyedWrite());
        $this->assertTrue($this->query()->from('users')->where(fn ($q) => $q->where('a', 1)->orWhere('name', 'ILIKE', 'a%'))->needsKeyedWrite());
        $this->assertTrue($this->query()->from('users')->whereIn('id', $this->query()->from('posts')->select('user_id'))->needsKeyedWrite());
        $this->assertTrue($this->query()->from('users')->whereExists($this->query()->from('posts'))->needsKeyedWrite());
        $this->assertTrue($this->query()->from('users')->join('posts', 'posts.user_id', '=', 'users.id')->needsKeyedWrite());
        $this->assertTrue($this->query()->from('users')->limit(1)->needsKeyedWrite());
        $this->assertTrue($this->query()->from('users')->whereRaw('name like ?', ['a%'])->needsKeyedWrite());
    }

    public function test_json_path_segments(): void
    {
        $this->assertSame(['a'], Builder::jsonPathSegments('a'));
        $this->assertSame(['a', 'b', 0, 'c'], Builder::jsonPathSegments('a->b[0]->c'));
        $this->assertSame(['a', 1, 2], Builder::jsonPathSegments('a[1][2]'));

        $document = ['a' => ['b' => [1, 2]], 'z' => true];
        Builder::setJsonPath($document, ['a', 'b', 1], 5);
        Builder::setJsonPath($document, ['a', 'c', 'd'], 'x');
        Builder::setJsonPath($document, ['z', 'y'], null);
        Builder::setJsonPath($document, ['a', 'b', -1], 9);
        $this->assertSame(['a' => ['b' => [1, 9], 'c' => ['d' => 'x']], 'z' => ['y' => null]], $document);
    }

    public function test_migrations_run_outside_transactions(): void
    {
        $this->assertFalse($this->connection->getSchemaGrammar()->supportsSchemaTransactions());
    }

    public function test_boolean_defaults_and_enums(): void
    {
        $sql = $this->schemaSql('users', function (Blueprint $table) {
            $table->create();
            $table->boolean('active')->default(true);
            $table->boolean('banned')->default(false);
            $table->boolean('legacy')->default('1');
            $table->enum('role', ['a', 'b'])->default('a');
            $table->integer('votes')->default(0);
            $table->decimal('price')->default('1.50');
            $table->string('code')->default('0');
        });

        $this->assertSame('create table "users" ("active" boolean not null default true, "banned" boolean not null default false, "legacy" boolean not null default true, '
            .'"role" varchar(255) not null default \'a\', "votes" integer not null default 0, "price" decimal(8, 2) not null default 1.50, "code" varchar(255) not null default \'0\')', $sql[0]);
    }

    public function test_change_drops_identity_clause(): void
    {
        $sql = $this->schemaSql('users', fn (Blueprint $table) => $table->string('nick', 100)->nullable()->change());

        $this->assertSame('alter table "users" alter column "nick" type varchar(100), alter column "nick" drop not null, alter column "nick" drop default', $sql[0]);
    }

    public function test_constraint_names_follow_surrealdb(): void
    {
        $sql = $this->schemaSql('users', function (Blueprint $table) {
            $table->unique('email');
            $table->dropUnique(['email']);
            $table->dropUnique('users_email_unique');
            $table->foreign('team_id')->references('id')->on('teams');
            $table->dropForeign(['team_id']);
            $table->dropForeign('users_team_id_foreign');
            $table->dropForeign('custom');
        });

        $this->assertSame([
            'alter table "users" add constraint "users_email_unique" unique ("email")',
            'alter table "users" drop constraint "users_email_uniq"',
            'alter table "users" drop constraint "users_email_uniq"',
            'alter table "users" add constraint "users_team_id_foreign" foreign key ("team_id") references "teams" ("id")',
            'alter table "users" drop constraint "users_team_id_fkey"',
            'alter table "users" drop constraint "users_team_id_fkey"',
            'alter table "users" drop constraint "custom"',
        ], $sql);
    }

    public function test_comments_and_index_drops_are_skipped(): void
    {
        $sql = $this->schemaSql('users', function (Blueprint $table) {
            $table->string('name')->comment('The name');
            $table->comment('Users');
            $table->dropIndex(['name']);
        });

        $this->assertSame(['alter table "users" add column "name" varchar(255) not null'], $sql);
    }

    public function test_rename_column_is_unsupported(): void
    {
        $this->expectException(UnsupportedFeatureException::class);

        $this->schemaSql('users', fn (Blueprint $table) => $table->renameColumn('a', 'b'));
    }

    public function test_rename_table_is_unsupported(): void
    {
        $this->expectException(UnsupportedFeatureException::class);

        $this->schemaSql('users', fn (Blueprint $table) => $table->rename('people'));
    }
}
