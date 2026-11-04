<?php

namespace LaravelSurrealDb\Connectors;

use Illuminate\Database\Connectors\PostgresConnector;
use PDO;

class SurrealDbConnector extends PostgresConnector
{
    /**
     * Statements run unnamed (PQexecParams) instead of as named prepared
     * statements: pdo_pgsql frees a named statement with an SQL `DEALLOCATE`,
     * which SurrealDB rejects because it only knows protocol-level names, and
     * inside a transaction that error aborts the transaction. Parameters are
     * still bound by the server, so they keep their column types.
     *
     * @var array<int, int|bool>
     */
    protected $options = [
        PDO::ATTR_CASE => PDO::CASE_NATURAL,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_EMULATE_PREPARES => false,
        self::DISABLE_PREPARES => true,
    ];

    /** Pdo\Pgsql::ATTR_DISABLE_PREPARES (PDO::PGSQL_ATTR_DISABLE_PREPARES before PHP 8.4). */
    public const DISABLE_PREPARES = 1000;

    /**
     * @param  array<string, mixed>  $config
     * @return PDO
     */
    public function connect(array $config)
    {
        return parent::connect(static::normalizeConfig($config));
    }

    /**
     * SurrealDB selects the namespace and database with the startup database
     * `ns/db`. Accepts `namespace` + `database`, `ns/db`, or the `ns.db` form
     * of SurrealDB Cloud connection strings. SurrealDB Cloud also routes on
     * `user@instance`, built from the optional `instance` option.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function normalizeConfig(array $config): array
    {
        $config['database'] = static::startupDatabase($config);

        if (! empty($config['instance']) && isset($config['username']) && ! str_contains((string) $config['username'], '@')) {
            $config['username'] = $config['username'].'@'.$config['instance'];
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function startupDatabase(array $config): string
    {
        $database = (string) ($config['database'] ?? '');

        if (! empty($config['namespace']) && ! str_contains($database, '/')) {
            return $config['namespace'].'/'.$database;
        }

        if (! str_contains($database, '/') && substr_count($database, '.') === 1) {
            return str_replace('.', '/', $database);
        }

        return $database;
    }

    /**
     * SurrealDB has no `synchronous_commit` setting.
     *
     * @param  PDO  $connection
     * @param  array<string, mixed>  $config
     * @return void
     */
    protected function configureSynchronousCommit($connection, array $config) {}
}
