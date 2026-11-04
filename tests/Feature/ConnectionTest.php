<?php

namespace LaravelSurrealDb\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaravelSurrealDb\SurrealDbConnection;

class ConnectionTest extends TestCase
{
    protected array $tables = ['sd_values'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sd_values', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique()->nullable();
            $table->text('text')->nullable();
            $table->boolean('yes')->default(true);
            $table->integer('int')->nullable();
            $table->bigInteger('big')->nullable();
            $table->float('float')->nullable();
            $table->decimal('price', 8, 2)->nullable();
            $table->date('day')->nullable();
            $table->timestamp('at')->nullable();
            $table->timestampTz('at_tz')->nullable();
            $table->uuid('uuid')->nullable();
            $table->json('doc')->nullable();
        });
    }

    public function test_connects_to_surrealdb(): void
    {
        $this->assertInstanceOf(SurrealDbConnection::class, DB::connection());
        $this->assertSame('SurrealDB', DB::connection()->getDriverTitle());
        $this->assertStringContainsString('SurrealDB', (string) DB::scalar('select version()'));
        $this->assertSame(self::env('SURREALDB_DATABASE', 'laravel_test'), DB::scalar('select current_database()'));
    }

    public function test_values_keep_their_types(): void
    {
        $id = DB::table('sd_values')->insertGetId([
            'text' => "it's a \\ ? 🚀 chào",
            'yes' => false,
            'int' => 42,
            'big' => PHP_INT_MAX,
            'float' => 9.5,
            'price' => '12.30',
            'day' => '2026-01-02',
            'at' => CarbonImmutable::parse('2026-01-02 03:04:05'),
            'at_tz' => '2026-01-02 03:04:05+00',
            'uuid' => '7f0c8b0e-4b4a-4c1a-9b65-2f6b4b9f0e11',
            'doc' => json_encode(['theme' => 'dark', 'tags' => ['a', 'b'], 'n' => ['k' => 1]]),
        ]);

        $this->assertIsInt($id);

        $row = DB::table('sd_values')->find($id);

        $this->assertSame("it's a \\ ? 🚀 chào", $row->text);
        $this->assertFalse($row->yes);
        $this->assertSame(42, $row->int);
        $this->assertSame(PHP_INT_MAX, $row->big);
        $this->assertEquals(9.5, $row->float);
        $this->assertSame('12.30', $row->price);
        $this->assertSame('2026-01-02', $row->day);
        $this->assertSame('2026-01-02 03:04:05', $row->at);
        $this->assertStringStartsWith('2026-01-02 03:04:05', $row->at_tz);
        $this->assertSame('7f0c8b0e-4b4a-4c1a-9b65-2f6b4b9f0e11', $row->uuid);
        // SurrealDB returns object keys sorted.
        $this->assertSame(['n' => ['k' => 1], 'tags' => ['a', 'b'], 'theme' => 'dark'], json_decode($row->doc, true));

        // Parameters take the type of the column they are compared with.
        $this->assertSame(1, DB::table('sd_values')
            ->where('yes', false)
            ->where('int', '>', '40')
            ->where('at', '>', '2026-01-01 00:00:00')
            ->where('day', CarbonImmutable::parse('2026-01-02')->toDateString())
            ->whereDate('at', '2026-01-02')
            ->whereYear('at', 2026)
            ->whereMonth('at', 1)
            ->whereTime('at', '>=', '03:00')
            ->count());
    }

    public function test_default_values(): void
    {
        DB::table('sd_values')->insert(['text' => 'defaults']);

        $this->assertTrue(DB::table('sd_values')->where('text', 'defaults')->value('yes'));
    }

    public function test_unique_violations_are_reported(): void
    {
        DB::table('sd_values')->insert(['email' => 'a@x.io']);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('sd_values')->insert(['email' => 'a@x.io']);
    }

    public function test_type_mismatch_is_reported(): void
    {
        $this->expectExceptionMessageMatches('/22P02|42804/');

        DB::table('sd_values')->insert(['int' => 'not a number']);
    }

    public function test_reconnects(): void
    {
        DB::table('sd_values')->insert(['text' => 'before']);

        DB::purge();
        DB::reconnect();

        $this->assertSame(1, DB::table('sd_values')->count());
    }
}
