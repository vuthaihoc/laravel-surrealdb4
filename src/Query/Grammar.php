<?php

namespace LaravelSurrealDb\Query;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use LaravelSurrealDb\Exceptions\UnsupportedFeatureException;

class Grammar extends PostgresGrammar
{
    /**
     * SurrealDB locks only record-id targets (`SELECT ... FROM t:1 FOR
     * UPDATE`), not table scans. Transactions run at snapshot isolation and
     * a conflicting commit fails with 40001, so locks are dropped.
     *
     * @param  bool|string  $value
     * @return string
     */
    protected function compileLock(BaseBuilder $query, $value)
    {
        return '';
    }

    /**
     * No `@>` operator: a scalar is looked up with `= any()` (see
     * Builder::whereJsonContains()).
     *
     * @param  string  $column
     * @param  string  $value
     * @return string
     */
    protected function compileJsonContains($column, $value)
    {
        $column = str_replace('->>', '->', $this->wrap($column));

        return '('.$value.' = any(('.$column.')))';
    }

    /**
     * No `?` operator: a key exists when its value is not null.
     *
     * @param  string  $column
     * @return string
     */
    protected function compileJsonContainsKey($column)
    {
        $segments = explode('->', $column);

        $lastSegment = (string) array_pop($segments);

        if (filter_var($lastSegment, FILTER_VALIDATE_INT) !== false || preg_match('/\[-?[0-9]+\]$/', $lastSegment)) {
            return parent::compileJsonContainsKey($column);
        }

        $column = str_replace('->>', '->', $this->wrap(implode('->', $segments)));

        return '(('.$column.')->'.$this->quoteString($lastSegment).') is not null';
    }

    /**
     * Never reached: Builder::update() applies JSON paths in PHP.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return string
     */
    protected function compileJsonUpdateColumn($key, $value)
    {
        throw new UnsupportedFeatureException("SurrealDB has no jsonb_set(): update the JSON path [{$key}] through the query builder.");
    }

    /**
     * Never reached: Builder::update() writes by id when the query joins or limits.
     *
     * @param  array<string, mixed>  $values
     * @return string
     */
    protected function compileUpdateWithJoinsOrLimit(BaseBuilder $query, array $values)
    {
        throw new UnsupportedFeatureException('SurrealDB cannot UPDATE with joins or a limit: run the update through the query builder.');
    }

    /**
     * Never reached: Builder::delete() deletes by id when the query joins or limits.
     *
     * @return string
     */
    protected function compileDeleteWithJoinsOrLimit(BaseBuilder $query)
    {
        throw new UnsupportedFeatureException('SurrealDB cannot DELETE with joins or a limit: run the delete through the query builder.');
    }

    /**
     * SurrealDB has no UPDATE ... FROM.
     *
     * @param  array<string, mixed>  $values
     * @return string
     */
    public function compileUpdateFrom(BaseBuilder $query, $values)
    {
        throw new UnsupportedFeatureException('SurrealDB has no UPDATE ... FROM: use update() with a join.');
    }

    /**
     * No `pg_stat_activity`: the connection count is unknown.
     *
     * @return string|null
     */
    public function compileThreadCount()
    {
        return null;
    }
}
