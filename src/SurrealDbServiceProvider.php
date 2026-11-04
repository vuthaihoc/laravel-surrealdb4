<?php

namespace LaravelSurrealDb;

use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;
use LaravelSurrealDb\Connectors\SurrealDbConnector;

class SurrealDbServiceProvider extends ServiceProvider
{
    /**
     * Register the `surrealdb` database driver.
     *
     * A connector and a connection resolver (rather than a DatabaseManager
     * extension) let Laravel's ConnectionFactory build the connection like a
     * built-in driver: read/write connections, lazy PDO and reconnects work.
     */
    public function register(): void
    {
        $this->app->bind('db.connector.surrealdb', SurrealDbConnector::class);

        Connection::resolverFor('surrealdb', static fn ($connection, $database, $prefix, $config) => new SurrealDbConnection($connection, $database, $prefix, $config));
    }
}
