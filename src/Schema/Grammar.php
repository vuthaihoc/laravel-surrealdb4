<?php

namespace LaravelSurrealDb\Schema;

use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;
use LaravelSurrealDb\Exceptions\UnsupportedFeatureException;

class Grammar extends PostgresGrammar
{
    /**
     * Migrations run outside a transaction: SurrealDB Cloud does not enforce
     * primary keys and unique constraints created inside one, and a statement
     * outside a transaction is run again when it meets a conflict.
     *
     * @var bool
     */
    protected $transactions = false;

    /**
     * SurrealDB checks a column default against the column type: a boolean
     * default is `true`/`false`, not PostgreSQL's `'1'`/`'0'`.
     *
     * @param  mixed  $value
     * @return string
     */
    protected function getDefaultValue($value)
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return parent::getDefaultValue($value);
    }

    /**
     * SurrealDB does not coerce a quoted default (`'0'`) to a number or
     * boolean column: defaults of those columns are written unquoted.
     *
     * @param  Fluent<string, mixed>  $column
     * @return string|null
     */
    protected function modifyDefault(Blueprint $blueprint, Fluent $column)
    {
        $default = $column->get('default');
        $type = $column->get('type');

        $literal = match (true) {
            in_array($type, self::NUMBER_TYPES, true) && is_bool($default) => $default ? '1' : '0',
            in_array($type, self::NUMBER_TYPES, true) && is_numeric($default) => (string) $default,
            $type === 'boolean' && in_array($default, [true, false, 1, 0, '1', '0', 'true', 'false'], true) => filter_var($default, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false',
            default => null,
        };

        if ($literal === null) {
            return parent::modifyDefault($blueprint, $column);
        }

        $column = clone $column;
        $column->offsetSet('default', new Expression($literal));

        return parent::modifyDefault($blueprint, $column);
    }

    /** Laravel column types with a numeric default. */
    protected const NUMBER_TYPES = ['integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger', 'float', 'double', 'decimal', 'year'];

    /**
     * No CHECK constraints: an enum is a plain varchar(255), its values unchecked.
     *
     * @param  Fluent<string, mixed>  $column
     * @return string
     */
    protected function typeEnum(Fluent $column)
    {
        return 'varchar(255)';
    }

    /**
     * SurrealDB does not parse `drop identity` (columns have no identity).
     *
     * @param  Fluent<string, mixed>  $command
     * @return string|list<string>
     */
    public function compileChange(Blueprint $blueprint, Fluent $command)
    {
        $sql = parent::compileChange($blueprint, $command);

        $strip = static fn (string $statement): string => (string) preg_replace('/, alter column "[^"]+" drop identity if exists/', '', $statement);

        return is_array($sql) ? array_map($strip, $sql) : $strip($sql);
    }

    /**
     * SurrealDB names a unique constraint itself (`{table}_{columns}_uniq`),
     * so the add statement is plain and the drop maps Laravel's name.
     *
     * @param  Fluent<string, mixed>  $command
     * @return string[]
     */
    public function compileUnique(Blueprint $blueprint, Fluent $command)
    {
        return [sprintf('alter table %s add constraint %s unique (%s)',
            $this->wrapTable($blueprint),
            $this->wrap((string) $command->get('index')),
            $this->columnize((array) $command->get('columns'))
        )];
    }

    /**
     * @param  Fluent<string, mixed>  $command
     * @return string
     */
    public function compileDropUnique(Blueprint $blueprint, Fluent $command)
    {
        $index = $this->wrap($this->surrealConstraintName($blueprint, $command, 'unique', 'uniq'));

        return "alter table {$this->wrapTable($blueprint)} drop constraint {$index}";
    }

    /**
     * SurrealDB names a foreign key itself (`{table}_{columns}_fkey`).
     *
     * @param  Fluent<string, mixed>  $command
     * @return string
     */
    public function compileDropForeign(Blueprint $blueprint, Fluent $command)
    {
        $index = $this->wrap($this->surrealConstraintName($blueprint, $command, 'foreign', 'fkey'));

        return "alter table {$this->wrapTable($blueprint)} drop constraint {$index}";
    }

    /**
     * The name SurrealDB gave the constraint Laravel names `$command->index`.
     *
     * @param  Fluent<string, mixed>  $command
     */
    protected function surrealConstraintName(Blueprint $blueprint, Fluent $command, string $laravelSuffix, string $surrealSuffix): string
    {
        $columns = (array) $command->get('columns');

        if ($columns !== []) {
            return $this->connection->getTablePrefix().$blueprint->getTable().'_'.implode('_', $columns).'_'.$surrealSuffix;
        }

        $index = (string) $command->get('index');

        return str_ends_with($index, '_'.$laravelSuffix)
            ? substr($index, 0, -strlen($laravelSuffix)).$surrealSuffix
            : $index;
    }

    /**
     * SurrealDB 4.0.0 fails on every `DROP INDEX` ("Expected `what` but found
     * `NONE`"), so dropping an index is skipped. Dropping the table drops its
     * indexes.
     *
     * @param  Fluent<string, mixed>  $command
     * @return string|null
     */
    public function compileDropIndex(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /**
     * SurrealDB answers `ALTER TABLE ... RENAME COLUMN` without renaming.
     *
     * @param  Fluent<string, mixed>  $command
     * @return string
     */
    public function compileRenameColumn(Blueprint $blueprint, Fluent $command)
    {
        throw new UnsupportedFeatureException('SurrealDB does not rename columns: add a new column, copy the values and drop the old one.');
    }

    /**
     * SurrealDB answers `ALTER TABLE ... RENAME TO` without renaming.
     *
     * @param  Fluent<string, mixed>  $command
     * @return string
     */
    public function compileRename(Blueprint $blueprint, Fluent $command)
    {
        throw new UnsupportedFeatureException('SurrealDB does not rename tables: create the new table, copy the rows and drop the old one.');
    }

    /**
     * @param  Fluent<string, mixed>  $command
     * @return string
     */
    public function compileRenameIndex(Blueprint $blueprint, Fluent $command)
    {
        throw new UnsupportedFeatureException('SurrealDB does not rename indexes.');
    }

    /**
     * No COMMENT statement: column comments are skipped.
     *
     * @param  Fluent<string, mixed>  $command
     * @return string|null
     */
    public function compileComment(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /**
     * No COMMENT statement: table comments are skipped.
     *
     * @param  Fluent<string, mixed>  $command
     * @return string|null
     */
    public function compileTableComment(Blueprint $blueprint, Fluent $command)
    {
        return null;
    }

    /**
     * `pg_attrdef` holds no defaults: `information_schema.columns` reports the
     * `nextval()` default of serial columns, which marks them auto-increment.
     *
     * @param  string|null  $schema
     * @param  string  $table
     * @return string
     */
    public function compileColumns($schema, $table)
    {
        return sprintf(
            'select column_name as name, udt_name as type_name, coalesce(column_type, data_type) as type, '
            .'collation_name as collation, is_nullable = \'YES\' as nullable, column_default as "default", '
            .'case when is_generated = \'ALWAYS\' then \'s\' else \'\' end as generated, null as comment '
            .'from information_schema.columns where table_schema = %s and table_name = %s order by ordinal_position',
            $schema ? $this->quoteString($schema) : 'current_schema()',
            $this->quoteString($table)
        );
    }

    /**
     * Enums and domains (`pg_depend` does not exist; table row types are not
     * listed).
     *
     * @param  string|string[]|null  $schema
     * @return string
     */
    public function compileTypes($schema)
    {
        return 'select t.typname as name, n.nspname as schema, t.typtype as type, t.typcategory as category, false as implicit '
            .'from pg_type t join pg_namespace n on n.oid = t.typnamespace '
            ."where t.typtype in ('e', 'd') and "
            .$this->compileSchemaWhereClause($schema, 'n.nspname');
    }
}
