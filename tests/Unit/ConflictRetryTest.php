<?php

namespace LaravelSurrealDb\Tests\Unit;

use Closure;
use Illuminate\Database\QueryException;
use LaravelSurrealDb\SurrealDbConnection;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ConflictRetryTest extends TestCase
{
    protected function connection(array $config = []): SurrealDbConnection
    {
        return new SurrealDbConnection(fn () => throw new RuntimeException('No server in unit tests.'), 'app', '', ['driver' => 'surrealdb'] + $config);
    }

    protected function runStatement(SurrealDbConnection $connection, Closure $callback): mixed
    {
        return (fn () => $this->run('select 1', [], $callback))->call($connection);
    }

    protected static function conflict(string $state = 'XX000'): PDOException
    {
        return new class($state) extends PDOException
        {
            public function __construct(string $state)
            {
                parent::__construct("SQLSTATE[{$state}]: Internal error: 7 ERROR: Transaction conflict: conflict (409). This transaction can be retried");
                $this->code = $state;
            }
        };
    }

    public function test_conflicts_are_concurrency_errors(): void
    {
        $connection = $this->connection();
        $detect = fn ($e) => (fn () => $this->causedByConcurrencyError($e))->call($connection);

        $this->assertTrue($detect(self::conflict()));
        $this->assertTrue($detect(self::conflict('40001')));
        $this->assertFalse($detect(new PDOException('duplicate key value violates unique constraint')));
    }

    public function test_a_conflicted_statement_outside_a_transaction_runs_again(): void
    {
        $calls = 0;

        $result = $this->runStatement($this->connection(), function () use (&$calls) {
            if (++$calls < 3) {
                throw self::conflict();
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
    }

    public function test_retries_are_limited(): void
    {
        $calls = 0;

        try {
            $this->runStatement($this->connection(['conflict_retries' => 1]), function () use (&$calls) {
                $calls++;

                throw self::conflict();
            });
            $this->fail('The conflict was not reported.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Transaction conflict', $e->getMessage());
        }

        $this->assertSame(2, $calls);
    }

    public function test_other_errors_and_statements_in_a_transaction_are_not_retried(): void
    {
        $calls = 0;

        try {
            $this->runStatement($this->connection(), function () use (&$calls) {
                $calls++;

                throw new PDOException('syntax error');
            });
        } catch (QueryException) {
        }

        $this->assertSame(1, $calls);

        $connection = $this->connection();
        (fn () => $this->transactions = 1)->call($connection);
        $calls = 0;

        try {
            $this->runStatement($connection, function () use (&$calls) {
                $calls++;

                throw self::conflict();
            });
        } catch (QueryException) {
        }

        $this->assertSame(1, $calls);
    }
}
