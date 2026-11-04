<?php

namespace LaravelSurrealDb\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class TransactionTest extends TestCase
{
    protected array $tables = ['sd_tx'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sd_tx', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('n')->default(0);
        });
    }

    /**
     * SurrealDB Cloud may abort a transaction on a key-value conflict
     * ("This transaction can be retried"): the attempts run it again.
     */
    public function test_commit_with_reads_and_writes(): void
    {
        $count = DB::transaction(function () {
            DB::table('sd_tx')->insert(['name' => 'a']);
            DB::table('sd_tx')->where('name', 'a')->increment('n', 5);

            // Reads see the pending writes.
            return DB::table('sd_tx')->where('name', 'a')->value('n');
        }, attempts: 3);

        $this->assertSame(5, $count);
        $this->assertSame(1, DB::table('sd_tx')->count());
    }

    public function test_rollback_on_exception(): void
    {
        try {
            DB::transaction(function () {
                DB::table('sd_tx')->insert(['name' => 'gone']);
                $this->assertSame(1, DB::table('sd_tx')->count());

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, DB::table('sd_tx')->count());
    }

    public function test_nested_transactions_use_savepoints(): void
    {
        DB::transaction(function () {
            DB::table('sd_tx')->insert(['name' => 'outer']);

            try {
                DB::transaction(function () {
                    DB::table('sd_tx')->insert(['name' => 'inner']);

                    throw new RuntimeException('inner fails');
                });
            } catch (RuntimeException) {
            }

            DB::table('sd_tx')->insert(['name' => 'after']);
        }, attempts: 3);

        $this->assertSame(['after', 'outer'], DB::table('sd_tx')->orderBy('name')->pluck('name')->all());
    }

    public function test_manual_transactions(): void
    {
        DB::beginTransaction();
        DB::table('sd_tx')->insert(['name' => 'manual']);
        DB::rollBack();

        DB::beginTransaction();
        DB::table('sd_tx')->insert(['name' => 'kept']);
        DB::commit();

        $this->assertSame(['kept'], DB::table('sd_tx')->pluck('name')->all());
    }
}
