<?php

namespace LaravelSurrealDb\Query;

use Closure;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Builder as BaseBuilder;
use LaravelSurrealDb\Exceptions\UnsupportedFeatureException;
use LaravelSurrealDb\SurrealDbConnection;

/**
 * SurrealDB refuses UPDATE and DELETE statements that join, limit, use LIKE
 * or read a subquery, and has no jsonb_set(). Such writes select the record
 * ids first and then write by id (see writeByKeys()), and JSON path updates
 * are applied to the documents in PHP (see updateJsonPaths()).
 */
class Builder extends BaseBuilder
{
    /** Ids written per statement by a keyed write. */
    public int $keyedWriteChunkSize = 1000;

    /** Where operators SurrealDB refuses inside an UPDATE or DELETE. */
    protected const WRITE_REFUSED_OPERATORS = [
        'like', 'not like', 'ilike', 'not ilike', 'like binary', 'not like binary',
        'similar to', 'not similar to', 'rlike', 'not rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*',
    ];

    /** Where types SurrealDB refuses inside an UPDATE or DELETE. */
    protected const WRITE_REFUSED_TYPES = ['Like', 'InSub', 'NotInSub', 'Exists', 'NotExists', 'Sub'];

    /**
     * `whereJsonContains()` compiles to `? = any(column)`: SurrealDB has no
     * `@>` operator. A list is contained when each of its items is, and a
     * document (an associative array) cannot be matched.
     *
     * @param  string  $column
     * @param  mixed  $value
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereJsonContains($column, $value, $boolean = 'and', $not = false)
    {
        if ($value instanceof ExpressionContract) {
            return parent::whereJsonContains($column, $value, $boolean, $not);
        }

        if (is_array($value)) {
            if (! array_is_list($value) || array_filter($value, is_array(...)) !== []) {
                throw new UnsupportedFeatureException('SurrealDB cannot match a JSON document with whereJsonContains(): pass a scalar or a list of scalars.');
            }

            if (count($value) !== 1) {
                return $this->whereNested(function (self $query) use ($column, $value) {
                    foreach ($value as $item) {
                        $query->whereJsonContains($column, $item);
                    }
                }, $not ? $boolean.' not' : $boolean);
            }

            $value = reset($value);
        }

        $this->wheres[] = ['type' => 'JsonContains', 'column' => $column, 'value' => $value, 'boolean' => $boolean, 'not' => $not];

        $this->addBinding($value);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return int
     */
    public function update(array $values)
    {
        if ($this->hasJsonPaths($values)) {
            return $this->updateJsonPaths($values);
        }

        if ($this->needsKeyedWrite()) {
            return $this->writeByKeys(fn (BaseBuilder $query) => $query->update($values));
        }

        return parent::update($values);
    }

    /**
     * @param  mixed  $id
     * @return int
     */
    public function delete($id = null)
    {
        if (! is_null($id)) {
            $this->where($this->tableAlias().'.id', '=', $id);
        }

        if ($this->needsKeyedWrite()) {
            return $this->writeByKeys(fn (BaseBuilder $query) => $query->delete());
        }

        return parent::delete();
    }

    /**
     * Whether the UPDATE or DELETE of this query needs the ids first.
     */
    public function needsKeyedWrite(): bool
    {
        return ! empty($this->joins) || ! is_null($this->limit) || $this->wheresRefusedInWrites($this->wheres);
    }

    /**
     * @param  array<int, array<string, mixed>>  $wheres
     */
    protected function wheresRefusedInWrites(array $wheres): bool
    {
        foreach ($wheres as $where) {
            $refused = match ($where['type']) {
                'Basic' => in_array(strtolower((string) $where['operator']), self::WRITE_REFUSED_OPERATORS, true)
                    || $this->isSubquery($where['value']),
                // whereIn() with a query stores the subquery as an expression.
                'In', 'NotIn' => array_filter($where['values'], $this->isSubquery(...)) !== [],
                'Nested' => $this->wheresRefusedInWrites($where['query']->wheres),
                'raw' => $this->isRefusedSql((string) $where['sql']),
                default => in_array($where['type'], self::WRITE_REFUSED_TYPES, true),
            };

            if ($refused) {
                return true;
            }
        }

        return false;
    }

    protected function isSubquery(mixed $value): bool
    {
        return $value instanceof ExpressionContract
            && $this->isRefusedSql((string) $value->getValue($this->grammar));
    }

    protected function isRefusedSql(string $sql): bool
    {
        return (bool) preg_match('/\b(i?like|similar\s+to|select)\b/i', $sql);
    }

    /**
     * Select the ids this query matches, then run the write on them by id,
     * in one transaction.
     *
     * @param  Closure(BaseBuilder): int  $write
     */
    protected function writeByKeys(Closure $write): int
    {
        return $this->transaction(function () use ($write) {
            $ids = array_values(array_unique((clone $this)->pluck($this->tableAlias().'.id')->all(), SORT_REGULAR));

            $count = 0;

            foreach (array_chunk($ids, max(1, $this->keyedWriteChunkSize)) as $chunk) {
                $count += $write($this->newQuery()->from($this->tableName())->whereIn('id', $chunk));
            }

            return $count;
        });
    }

    /**
     * Run a read-then-write in a transaction, again after a SurrealDB
     * transaction conflict.
     *
     * @param  Closure(): int  $callback
     */
    protected function transaction(Closure $callback): int
    {
        $attempts = $this->connection instanceof SurrealDbConnection ? $this->connection->conflictRetries() + 1 : 1;

        return $this->connection->transaction($callback, $attempts);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function hasJsonPaths(array $values): bool
    {
        foreach (array_keys($values) as $key) {
            if (str_contains((string) $key, '->')) {
                return true;
            }
        }

        return false;
    }

    /**
     * `update(['meta->theme' => 'dark'])` without jsonb_set(): read the
     * documents, set the paths in PHP and write each document back by id.
     *
     * @param  array<string, mixed>  $values
     */
    protected function updateJsonPaths(array $values): int
    {
        $plain = [];
        $paths = [];

        foreach ($values as $key => $value) {
            $key = last(explode('.', (string) $key));

            if (! str_contains($key, '->')) {
                $plain[$key] = $value;

                continue;
            }

            if ($value instanceof ExpressionContract || $value instanceof BaseBuilder) {
                throw new UnsupportedFeatureException("SurrealDB cannot update the JSON path [{$key}] with an expression.");
            }

            [$column, $path] = explode('->', $key, 2);
            $paths[$column][] = [static::jsonPathSegments($path), $value];
        }

        return $this->transaction(function () use ($plain, $paths) {
            $alias = $this->tableAlias();
            $columns = array_map(fn ($column) => $alias.'.'.$column, array_keys($paths));

            $rows = (clone $this)->select([$alias.'.id', ...$columns])->get();

            $count = 0;

            foreach ($rows as $row) {
                $row = (array) $row;
                $update = $plain;

                foreach ($paths as $column => $assignments) {
                    $document = is_string($row[$column] ?? null) ? json_decode($row[$column], true) : ($row[$column] ?? null);
                    $document = is_array($document) ? $document : [];

                    foreach ($assignments as [$segments, $value]) {
                        static::setJsonPath($document, $segments, $value);
                    }

                    $update[$column] = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                }

                $count += $this->newQuery()->from($this->tableName())->where('id', $row['id'])->update($update);
            }

            return $count;
        });
    }

    /**
     * `a->b[0]->c` to ['a', 'b', 0, 'c'].
     *
     * @return list<string|int>
     */
    public static function jsonPathSegments(string $path): array
    {
        $segments = [];

        foreach (explode('->', $path) as $segment) {
            preg_match_all('/\[(-?\d+)\]/', $segment, $indexes);

            $name = (string) preg_replace('/(\[-?\d+\])+$/', '', $segment);

            if ($name !== '') {
                $segments[] = $name;
            }

            foreach ($indexes[1] as $index) {
                $segments[] = (int) $index;
            }
        }

        return $segments;
    }

    /**
     * @param  array<array-key, mixed>  $document
     * @param  list<string|int>  $segments
     */
    public static function setJsonPath(array &$document, array $segments, mixed $value): void
    {
        $target = &$document;

        foreach ($segments as $segment) {
            if (! is_array($target)) {
                $target = [];
            }

            if (is_int($segment) && $segment < 0) {
                $segment = max(0, count($target) + $segment);
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }

    /**
     * The table name of the "from" clause, without its alias.
     */
    protected function tableName(): string
    {
        return $this->fromParts()[0];
    }

    /**
     * The alias of the "from" clause, or its table name.
     */
    protected function tableAlias(): string
    {
        $parts = $this->fromParts();

        return $parts[count($parts) - 1];
    }

    /**
     * `table as alias` to ['table', 'alias'].
     *
     * @return non-empty-list<string>
     */
    protected function fromParts(): array
    {
        $from = $this->from instanceof ExpressionContract ? (string) $this->from->getValue($this->grammar) : (string) $this->from;

        return preg_split('/\s+as\s+/i', $from) ?: [$from];
    }
}
