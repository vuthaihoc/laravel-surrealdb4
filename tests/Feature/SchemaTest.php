<?php

namespace LaravelSurrealDb\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaravelSurrealDb\Exceptions\UnsupportedFeatureException;

class SchemaTest extends TestCase
{
    protected array $tables = ['sd_articles', 'sd_authors', 'sd_flags', 'migrations', 'sd_migrated'];

    protected function createAuthorsAndArticles(): void
    {
        Schema::create('sd_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('Display name');
            $table->string('email')->unique();
            $table->enum('role', ['admin', 'editor'])->default('editor');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('logins')->default(0);
            $table->decimal('balance', 10, 2)->default('0.00');
            $table->timestamps();
            $table->softDeletes();
            $table->comment('Authors');
        });

        Schema::create('sd_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('sd_authors')->cascadeOnDelete();
            $table->string('title');
            $table->text('body')->nullable();
            $table->index(['author_id', 'title']);
            $table->unique(['author_id', 'title']);
        });
    }

    public function test_tables_and_columns(): void
    {
        $this->createAuthorsAndArticles();

        $this->assertTrue(Schema::hasTable('sd_authors'));
        $this->assertFalse(Schema::hasTable('sd_missing'));
        $this->assertContains('sd_articles', array_column(Schema::getTables(), 'name'));
        $this->assertTrue(Schema::hasColumns('sd_authors', ['name', 'email', 'deleted_at']));
        $this->assertFalse(Schema::hasColumn('sd_authors', 'missing'));

        $columns = collect(Schema::getColumns('sd_authors'))->keyBy('name');
        $this->assertTrue($columns['id']['auto_increment']);
        $this->assertSame('int8', $columns['id']['type_name']);
        $this->assertSame('character varying(100)', $columns['name']['type']);
        $this->assertFalse($columns['name']['nullable']);
        $this->assertTrue($columns['deleted_at']['nullable']);
        $this->assertSame('bool', $columns['active']['type_name']);
        $this->assertSame('numeric(10,2)', str_replace(' ', '', $columns['balance']['type']));
        $this->assertSame('varchar', Schema::getColumnType('sd_authors', 'email'));
    }

    public function test_indexes_and_foreign_keys(): void
    {
        $this->createAuthorsAndArticles();

        $indexes = collect(Schema::getIndexes('sd_articles'))->keyBy('name');
        $this->assertSame(['id'], $indexes['sd_articles_pkey']['columns']);
        $this->assertTrue($indexes['sd_articles_pkey']['primary']);
        $this->assertSame(['author_id', 'title'], $indexes['sd_articles_author_id_title_index']['columns']);
        $this->assertFalse($indexes['sd_articles_author_id_title_index']['unique']);
        // SurrealDB names unique constraints itself.
        $this->assertSame(['author_id', 'title'], $indexes['sd_articles_author_id_title_uniq']['columns']);
        $this->assertTrue($indexes['sd_articles_author_id_title_uniq']['unique']);
        $this->assertTrue(Schema::hasIndex('sd_articles', ['author_id', 'title'], 'unique'));

        $foreignKeys = Schema::getForeignKeys('sd_articles');
        $this->assertCount(1, $foreignKeys);
        $this->assertSame('sd_articles_author_id_fkey', $foreignKeys[0]['name']);
        $this->assertSame(['author_id'], $foreignKeys[0]['columns']);
        $this->assertSame('sd_authors', $foreignKeys[0]['foreign_table']);
        $this->assertSame(['id'], $foreignKeys[0]['foreign_columns']);
        $this->assertSame('cascade', $foreignKeys[0]['on_delete']);

        $authorId = DB::table('sd_authors')->insertGetId(['name' => 'Ada', 'email' => 'ada@x.io']);
        DB::table('sd_articles')->insert(['author_id' => $authorId, 'title' => 'One']);

        try {
            DB::table('sd_articles')->insert(['author_id' => $authorId + 1000, 'title' => 'Orphan']);
            $this->fail('The foreign key was not checked.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }

        DB::table('sd_authors')->delete($authorId);
        $this->assertSame(0, DB::table('sd_articles')->count());
    }

    public function test_alter_tables(): void
    {
        $this->createAuthorsAndArticles();

        Schema::table('sd_authors', function (Blueprint $table) {
            $table->string('nick', 50)->nullable();
            $table->integer('score')->default(10);
        });
        $this->assertTrue(Schema::hasColumns('sd_authors', ['nick', 'score']));

        DB::table('sd_authors')->insert(['name' => 'Ada', 'email' => 'ada@x.io']);
        $this->assertSame(10, DB::table('sd_authors')->value('score'));

        Schema::table('sd_authors', fn (Blueprint $table) => $table->string('nick', 80)->nullable()->change());
        $this->assertSame('character varying(80)', collect(Schema::getColumns('sd_authors'))->firstWhere('name', 'nick')['type']);

        Schema::table('sd_authors', fn (Blueprint $table) => $table->dropColumn(['nick', 'score']));
        $this->assertFalse(Schema::hasColumn('sd_authors', 'nick'));

        Schema::table('sd_articles', function (Blueprint $table) {
            $table->dropForeign(['author_id']);
            $table->dropUnique(['author_id', 'title']);
        });
        $this->assertSame([], Schema::getForeignKeys('sd_articles'));
        $this->assertFalse(Schema::hasIndex('sd_articles', ['author_id', 'title'], 'unique'));

        Schema::table('sd_authors', fn (Blueprint $table) => $table->dropUnique('sd_authors_email_unique'));
        DB::table('sd_authors')->insert(['name' => 'Ada 2', 'email' => 'ada@x.io']);
        $this->assertSame(2, DB::table('sd_authors')->where('email', 'ada@x.io')->count());

        // DROP INDEX fails on SurrealDB 4.0.0: dropIndex() is skipped.
        Schema::table('sd_articles', fn (Blueprint $table) => $table->dropIndex(['author_id', 'title']));

    }

    public function test_renames_are_refused(): void
    {
        // SurrealDB answers RENAME COLUMN and RENAME TO without renaming.
        Schema::create('sd_flags', fn (Blueprint $table) => $table->string('a'));

        try {
            Schema::table('sd_flags', fn (Blueprint $table) => $table->renameColumn('a', 'b'));
            $this->fail('renameColumn() was not refused.');
        } catch (UnsupportedFeatureException) {
        }

        $this->expectException(UnsupportedFeatureException::class);

        Schema::rename('sd_flags', 'sd_renamed');
    }

    public function test_foreign_key_constraints_toggle_is_accepted(): void
    {
        $this->assertTrue(Schema::disableForeignKeyConstraints());
        $this->assertTrue(Schema::enableForeignKeyConstraints());
        $this->assertSame([], Schema::getTypes());
    }

    public function test_migrations_run_and_roll_back(): void
    {
        $path = ['--path' => __DIR__.'/migrations', '--realpath' => true];

        $this->artisan('migrate', $path)->assertSuccessful();
        $this->assertTrue(Schema::hasTable('sd_migrated'));
        $this->assertSame(1, DB::table('migrations')->count());

        $this->artisan('migrate:rollback', $path)->assertSuccessful();
        $this->assertFalse(Schema::hasTable('sd_migrated'));
        $this->assertSame(0, DB::table('migrations')->count());

        $this->artisan('migrate:fresh', $path)->assertSuccessful();
        $this->assertTrue(Schema::hasTable('sd_migrated'));
        $this->assertSame(['migrations', 'sd_migrated'], collect(Schema::getTables())->pluck('name')->intersect(['migrations', 'sd_migrated'])->sort()->values()->all());
    }
}
