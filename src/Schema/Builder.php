<?php

namespace LaravelSurrealDb\Schema;

use Illuminate\Database\Schema\PostgresBuilder;

/**
 * SurrealDB's catalogs lack `string_agg(... order by ...)` and
 * `unnest(...) with ordinality`, which Laravel's index and foreign key
 * queries use: the column lists are assembled in PHP.
 */
class Builder extends PostgresBuilder
{
    /**
     * SurrealDB has no `SET CONSTRAINTS`: foreign keys stay checked.
     *
     * @return bool
     */
    public function enableForeignKeyConstraints()
    {
        return true;
    }

    /**
     * SurrealDB has no `SET CONSTRAINTS`: foreign keys stay checked.
     *
     * @return bool
     */
    public function disableForeignKeyConstraints()
    {
        return true;
    }

    /**
     * @param  string  $table
     * @return list<array{name: string, columns: list<string>, type: string|null, unique: bool, primary: bool}>
     */
    public function getIndexes($table)
    {
        [$schema, $table] = $this->parseSchemaAndTable($table);

        $table = $this->connection->getTablePrefix().$table;

        $names = $this->attributeNames($schema, $table);

        $rows = $this->connection->selectFromWriteConnection(
            'select ic.relname as name, i.indkey, i.indisunique as "unique", i.indisprimary as "primary", am.amname as type '
            .'from pg_index i join pg_class tc on tc.oid = i.indrelid '
            .'join pg_namespace tn on tn.oid = tc.relnamespace '
            .'join pg_class ic on ic.oid = i.indexrelid '
            .'left join pg_am am on am.oid = ic.relam '
            .'where tc.relname = ? and tn.nspname = '.$this->schemaExpression($schema),
            $this->schemaBindings($table, $schema)
        );

        $indexes = array_map(fn ($row) => [
            'name' => $row->name,
            'columns' => implode(',', $this->columnNames($row->indkey, $names)),
            'type' => $row->type ?? 'btree',
            'unique' => $row->unique,
            'primary' => $row->primary,
        ], array_values($rows));

        return $this->connection->getPostProcessor()->processIndexes($indexes);
    }

    /**
     * @param  string  $table
     * @return list<array<string, mixed>>
     */
    public function getForeignKeys($table)
    {
        [$schema, $table] = $this->parseSchemaAndTable($table);

        $table = $this->connection->getTablePrefix().$table;

        $rows = $this->connection->selectFromWriteConnection(
            'select c.conname as name, c.conkey, c.confkey, c.confupdtype as on_update, c.confdeltype as on_delete, '
            .'fc.relname as foreign_table, fn.nspname as foreign_schema '
            .'from pg_constraint c join pg_class tc on c.conrelid = tc.oid '
            .'join pg_namespace tn on tn.oid = tc.relnamespace '
            .'join pg_class fc on c.confrelid = fc.oid '
            .'join pg_namespace fn on fn.oid = fc.relnamespace '
            ."where c.contype = 'f' and tc.relname = ? and tn.nspname = ".$this->schemaExpression($schema),
            $this->schemaBindings($table, $schema)
        );

        $names = $this->attributeNames($schema, $table);

        $foreignKeys = array_map(fn ($row) => [
            'name' => $row->name,
            'columns' => implode(',', $this->columnNames($row->conkey, $names)),
            'foreign_schema' => $row->foreign_schema,
            'foreign_table' => $row->foreign_table,
            'foreign_columns' => implode(',', $this->columnNames($row->confkey, $this->attributeNames($row->foreign_schema, $row->foreign_table))),
            'on_update' => $row->on_update,
            'on_delete' => $row->on_delete,
        ], array_values($rows));

        return $this->connection->getPostProcessor()->processForeignKeys($foreignKeys);
    }

    /**
     * Column names by attribute number.
     *
     * @return array<int, string>
     */
    protected function attributeNames(?string $schema, string $table): array
    {
        $rows = $this->connection->selectFromWriteConnection(
            'select a.attnum, a.attname from pg_attribute a join pg_class c on c.oid = a.attrelid '
            .'join pg_namespace n on n.oid = c.relnamespace '
            .'where a.attnum > 0 and c.relname = ? and n.nspname = '.$this->schemaExpression($schema),
            $this->schemaBindings($table, $schema)
        );

        $names = [];

        foreach ($rows as $row) {
            $names[(int) $row->attnum] = (string) $row->attname;
        }

        return $names;
    }

    /**
     * Names of the attribute numbers of an int2vector/int2[] value ("{3,2}").
     *
     * @param  array<int, int|string>|string|null  $numbers
     * @param  array<int, string>  $names
     * @return list<string>
     */
    protected function columnNames(array|string|null $numbers, array $names): array
    {
        if (is_string($numbers)) {
            $numbers = preg_split('/[\s,{}]+/', $numbers, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_map(fn ($number) => $names[(int) $number] ?? (string) $number, $numbers ?? []));
    }

    protected function schemaExpression(?string $schema): string
    {
        return $schema ? '?' : 'current_schema()';
    }

    /**
     * @return list<string>
     */
    protected function schemaBindings(string $table, ?string $schema): array
    {
        return $schema ? [$table, $schema] : [$table];
    }
}
