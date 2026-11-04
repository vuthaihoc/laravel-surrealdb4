<?php

namespace LaravelSurrealDb\Tests\KnownIssues;

use LaravelSurrealDb\Connectors\SurrealDbConnector;
use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Throwable;

/**
 * Known SurrealDB bugs and PostgreSQL incompatibilities the driver works
 * around, as plain SQL that PostgreSQL accepts. Each test asserts the
 * PostgreSQL behaviour, so it FAILS while the issue exists and starts PASSING
 * once a SurrealDB release fixes it; the driver workaround named in the test
 * can then be reconsidered.
 *
 * Not part of `composer test`; run with `composer test:known-issues`.
 */
abstract class TestCase extends BaseTestCase
{
    /** A table name prefix per test. */
    protected string $prefix;

    /** @var list<string> tables to drop after the test, in creation order */
    protected array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix = 'ki_'.bin2hex(random_bytes(4)).'_';
    }

    protected function tearDown(): void
    {
        $pdo = self::connect();

        foreach (array_reverse($this->created) as $table) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $pdo->exec("drop table if exists {$table}");

                    break;
                } catch (Throwable) {
                }
            }
        }

        parent::tearDown();
    }

    protected function table(string $name): string
    {
        $table = '"'.$this->prefix.$name.'"';

        if (! in_array($table, $this->created, true)) {
            $this->created[] = $table;
        }

        return $table;
    }

    /**
     * A new connection, configured like the driver's unless `$nativePrepares`.
     */
    protected static function connect(bool $nativePrepares = false): PDO
    {
        $instance = self::env('SURREALDB_INSTANCE', '');
        $username = self::env('SURREALDB_USERNAME', 'root');

        return new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s/%s;sslmode=%s',
                self::env('SURREALDB_HOST', '127.0.0.1'),
                self::env('SURREALDB_PORT', '5436'),
                self::env('SURREALDB_NAMESPACE', 'main'),
                self::env('SURREALDB_DATABASE', 'laravel_test'),
                self::env('SURREALDB_SSLMODE', 'disable'),
            ),
            $instance !== '' && ! str_contains($username, '@') ? "{$username}@{$instance}" : $username,
            self::env('SURREALDB_PASSWORD', 'root'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                SurrealDbConnector::DISABLE_PREPARES => ! $nativePrepares,
            ],
        );
    }

    /**
     * Run statements on one fresh connection and return the rows of the last
     * one, or fail with the server error. A statement is SQL or [SQL, bindings].
     *
     * @param  string|array{0: string, 1: list<mixed>}  ...$statements
     * @return list<array<string, mixed>>
     */
    protected function runSql(string|array ...$statements): array
    {
        $pdo = self::connect();
        $rows = [];

        foreach ($statements as $statement) {
            [$sql, $bindings] = is_array($statement) ? $statement : [$statement, []];

            try {
                $result = $pdo->prepare($sql);
                $result->execute($bindings);
                $rows = $result->columnCount() > 0 ? $result->fetchAll() : [];
            } catch (Throwable $e) {
                $this->fail("SurrealDB rejected [{$sql}]: ".preg_replace('/\s+/', ' ', mb_substr($e->getMessage(), 0, 300)));
            }
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    protected static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
