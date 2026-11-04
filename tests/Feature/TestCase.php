<?php

namespace LaravelSurrealDb\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use LaravelSurrealDb\SurrealDbServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Feature tests run on a SurrealDB 4 server with the PostgreSQL listener
 * (SURREALDB_* variables, see phpunit.xml.dist). Each test drops the tables
 * it uses in setUp().
 */
abstract class TestCase extends OrchestraTestCase
{
    /** @var list<string> tables dropped before each test, children first */
    protected array $tables = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SurrealDbServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'surrealdb');
        $app['config']->set('database.connections.surrealdb', self::connectionConfig());
    }

    /**
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return [
            'driver' => 'surrealdb',
            'host' => self::env('SURREALDB_HOST', '127.0.0.1'),
            'port' => (int) self::env('SURREALDB_PORT', '5436'),
            'namespace' => self::env('SURREALDB_NAMESPACE', 'main'),
            'database' => self::env('SURREALDB_DATABASE', 'laravel_test'),
            'username' => self::env('SURREALDB_USERNAME', 'root'),
            'password' => self::env('SURREALDB_PASSWORD', 'root'),
            'instance' => self::env('SURREALDB_INSTANCE', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => self::env('SURREALDB_SSLMODE', 'disable'),
            // SurrealDB Cloud answers DDL on tables with foreign keys with transaction conflicts.
            'conflict_retries' => 10,
        ];
    }

    protected static function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }
}
