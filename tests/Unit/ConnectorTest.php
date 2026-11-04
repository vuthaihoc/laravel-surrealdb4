<?php

namespace LaravelSurrealDb\Tests\Unit;

use LaravelSurrealDb\Connectors\SurrealDbConnector;
use PDO;
use Pdo\Pgsql;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConnectorTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function databases(): array
    {
        return [
            'namespace option' => [['namespace' => 'main', 'database' => 'app'], 'main/app'],
            'ns/db' => [['database' => 'main/app'], 'main/app'],
            'ns.db (SurrealDB Cloud strings)' => [['database' => 'main.app'], 'main/app'],
            'namespace with ns/db' => [['namespace' => 'other', 'database' => 'main/app'], 'main/app'],
            'database only' => [['database' => 'app'], 'app'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('databases')]
    public function test_startup_database(array $config, string $expected): void
    {
        $this->assertSame($expected, SurrealDbConnector::normalizeConfig($config)['database']);
    }

    public function test_instance_routes_the_username(): void
    {
        $config = SurrealDbConnector::normalizeConfig(['database' => 'main/app', 'username' => 'hocvt', 'instance' => '06gq8hg0']);
        $this->assertSame('hocvt@06gq8hg0', $config['username']);

        $config = SurrealDbConnector::normalizeConfig(['database' => 'main/app', 'username' => 'hocvt@06gq8hg0', 'instance' => '06gq8hg0']);
        $this->assertSame('hocvt@06gq8hg0', $config['username']);

        $config = SurrealDbConnector::normalizeConfig(['database' => 'main/app', 'username' => 'root', 'instance' => '']);
        $this->assertSame('root', $config['username']);
    }

    public function test_statements_are_unnamed(): void
    {
        $this->assertSame(PHP_VERSION_ID >= 80400 ? Pgsql::ATTR_DISABLE_PREPARES : PDO::PGSQL_ATTR_DISABLE_PREPARES, SurrealDbConnector::DISABLE_PREPARES);
        $this->assertTrue((new SurrealDbConnector)->getDefaultOptions()[SurrealDbConnector::DISABLE_PREPARES]);
    }
}
