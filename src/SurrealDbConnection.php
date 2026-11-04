<?php

namespace LaravelSurrealDb;

use Closure;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use LaravelSurrealDb\Query\Builder;
use LaravelSurrealDb\Query\Grammar as QueryGrammar;
use LaravelSurrealDb\Query\ParameterTypes;
use LaravelSurrealDb\Schema\Builder as SchemaBuilder;
use LaravelSurrealDb\Schema\Grammar as SchemaGrammar;
use Throwable;

/**
 * A SurrealDB 4 connection over the PostgreSQL wire protocol, which runs
 * the PostgreSQL dialect of SQL against SurrealDB tables.
 */
class SurrealDbConnection extends PostgresConnection
{
    public function getDriverTitle()
    {
        return 'SurrealDB';
    }

    /**
     * @return Builder
     */
    public function query()
    {
        return new Builder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * @return SchemaBuilder
     */
    public function getSchemaBuilder()
    {
        if ($this->schemaGrammar === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new SchemaBuilder($this);
    }

    /**
     * Typed placeholders inside nested queries and HAVING (see ParameterTypes).
     *
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        if ($bindings !== []) {
            [$query, $bindings] = ParameterTypes::cast($query, $bindings);
        }

        return parent::runQueryCallback($query, $bindings, $callback);
    }

    /**
     * SurrealDB reports a write-write conflict of its key-value store as
     * "Transaction conflict ... This transaction can be retried": SQLSTATE
     * 40001 on some statements, XX000 on others. Both let
     * `DB::transaction($callback, $attempts)` run the callback again.
     */
    protected function causedByConcurrencyError(Throwable $e)
    {
        return parent::causedByConcurrencyError($e)
            || Str::contains($e->getMessage(), ['This transaction can be retried', 'Transaction conflict']);
    }

    /**
     * How many times a statement or a driver-run transaction aborted on a
     * SurrealDB transaction conflict is run again (`conflict_retries`).
     */
    public function conflictRetries(): int
    {
        return max(0, (int) ($this->getConfig('conflict_retries') ?? 3));
    }

    /**
     * A statement run outside a transaction is its own transaction: when
     * SurrealDB aborts it on a conflict nothing was written, so it is run
     * again, up to `conflict_retries` times (default 3), after 0.1 s, 0.2 s,
     * 0.4 s... (at most 1 s).
     *
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        if ($this->transactions > 0 || ! $this->causedByConcurrencyError($e)) {
            return parent::handleQueryException($e, $query, $bindings, $callback);
        }

        for ($attempt = 1; $attempt <= $this->conflictRetries(); $attempt++) {
            usleep(min(1_000_000, 50_000 * 2 ** $attempt));

            try {
                return $this->runQueryCallback($query, $bindings, $callback);
            } catch (QueryException $retry) {
                if (! $this->causedByConcurrencyError($retry)) {
                    throw $retry;
                }

                $e = $retry;
            }
        }

        throw $e;
    }

    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }
}
