<?php

namespace Laravel\Scout\Pgsql;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Arr;
use Illuminate\Support\Fluent;
use InvalidArgumentException;
use Laravel\Scout\Exceptions\ScoutException;

class SearchableSchema
{
    /**
     * The supported PostgreSQL text search weights.
     */
    protected const COLUMN_WEIGHTS = ['A', 'B', 'C', 'D'];

    /**
     * The PostgreSQL configuration reader instance.
     *
     * @var \Laravel\Scout\Pgsql\Configuration|null
     */
    protected $configuration;

    /**
     * Create a new searchable schema helper instance.
     *
     * @param  array  $config
     * @return void
     */
    public function __construct(protected array $config)
    {
        //
    }

    /**
     * Register the PostgreSQL searchable schema macros.
     *
     * @return void
     */
    public static function register()
    {
        if (! method_exists(Blueprint::class, 'tsvector') && ! Blueprint::hasMacro('tsvector')) {
            Blueprint::macro('tsvector', function ($column) {
                return $this->addColumn('tsvector', $column);
            });
        }

        if (! method_exists(PostgresGrammar::class, 'typeTsvector') && ! PostgresGrammar::hasMacro('typeTsvector')) {
            PostgresGrammar::macro('typeTsvector', fn (Fluent $column) => 'tsvector');
        }

        if (! Blueprint::hasMacro('searchable')) {
            Blueprint::macro('searchable', function ($columns, array $options = []) {
                $helper = new SearchableSchema(config('scout.pgsql', []));

                $connection = $helper->connection($this);
                $helper->ensurePostgresqlConnection($connection);
                $helper->ensureSupportedOptions($options);

                $columns = Arr::wrap($columns);
                $vectorColumn = $helper->vectorColumn();

                $trigramColumns = $helper->trigramColumns($options);

                if ($helper->shouldCreateTrigramExtension($options)) {
                    $helper->addTrigramExtension($this);
                }

                $this->tsvector($vectorColumn)->storedAs(new Expression(
                    $helper->searchVectorExpression($connection, $columns, $options)
                ));

                $helper->addIndexes($this, $connection, $this->getTable(), $vectorColumn, $trigramColumns, $options['index'] ?? null);
            });
        }

        if (! Blueprint::hasMacro('dropSearchable')) {
            Blueprint::macro('dropSearchable', function (array $options = []) {
                $helper = new SearchableSchema(config('scout.pgsql', []));

                $connection = $helper->connection($this);
                $helper->ensurePostgresqlConnection($connection);
                $helper->ensureSupportedOptions($options);

                $vectorColumn = $helper->vectorColumn();

                $this->dropIndex($helper->qualifiedIndexName(
                    $this->getTable(),
                    $options['index'] ?? $helper->indexName($connection, $this->getTable(), [$vectorColumn], 'index')
                ));

                foreach ($helper->trigramColumns($options) as $column) {
                    $this->dropIndex($helper->qualifiedIndexName(
                        $this->getTable(),
                        $helper->indexName($connection, $this->getTable(), [$column], 'trigram_index')
                    ));
                }

                $this->dropColumn($vectorColumn);
            });
        }

        if (! PostgresGrammar::hasMacro('compileScoutPgsqlExtension')) {
            PostgresGrammar::macro('compileScoutPgsqlExtension', function (Blueprint $blueprint, Fluent $command) {
                return sprintf('create extension if not exists %s', $this->wrap($command->extension));
            });
        }
    }

    /**
     * Create any missing search vector column and indexes for the model's table.
     *
     * Returns "created" when the vector column was added, "indexed" when only indexes were added, or null when nothing changed.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string|null
     *
     * @throws \Laravel\Scout\Exceptions\ScoutException
     */
    public function prepare(Model $model)
    {
        $connection = $model->getConnection();
        $schema = $connection->getSchemaBuilder();
        $table = $model->getTable();
        $vectorColumn = $this->vectorColumn();
        $tableColumns = $schema->getColumnListing($table);

        if (! $schema->hasColumn($table, $vectorColumn)) {
            $columns = $this->generatableColumns($model, $tableColumns, $vectorColumn);
            $trigramColumns = array_values(array_intersect($this->trigramColumns(), $columns));

            $schema->table($table, function ($table) use ($columns, $trigramColumns) {
                $table->searchable($columns, ['trigram' => ['columns' => $trigramColumns]]);
            });

            return 'created';
        }

        $trigramColumns = array_values(array_intersect($this->trigramColumns(), $tableColumns));
        $createExtension = $this->shouldCreateTrigramExtension() && ! empty($trigramColumns);
        $missingVectorIndex = ! $this->hasGinIndex($connection, $table, $vectorColumn);
        $missingTrigramColumns = array_values(array_filter(
            $trigramColumns, fn ($column) => ! $this->hasGinIndex($connection, $table, $column, 'gin_trgm_ops')
        ));

        if (! $createExtension && ! $missingVectorIndex && empty($missingTrigramColumns)) {
            return null;
        }

        $schema->table($table, function ($blueprint) use ($connection, $table, $vectorColumn, $createExtension, $missingVectorIndex, $missingTrigramColumns) {
            if ($createExtension) {
                $this->addTrigramExtension($blueprint);
            }

            $this->addIndexes($blueprint, $connection, $table, $missingVectorIndex ? $vectorColumn : null, $missingTrigramColumns);
        });

        return 'indexed';
    }

    /**
     * Add the search vector and trigram indexes to the blueprint.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Database\Connection  $connection
     * @param  string  $table
     * @param  string|null  $vectorColumn
     * @param  array  $trigramColumns
     * @param  string|null  $index
     * @return void
     */
    public function addIndexes($blueprint, Connection $connection, $table, $vectorColumn, array $trigramColumns, $index = null)
    {
        if (! is_null($vectorColumn)) {
            $blueprint->index($vectorColumn, $index, 'gin');
        }

        foreach ($trigramColumns as $column) {
            $blueprint->rawIndex(
                sprintf('%s gin_trgm_ops', $connection->getSchemaGrammar()->wrap($column)),
                $this->indexName($connection, $table, [$column], 'trigram_index')
            )->algorithm('gin');
        }
    }

    /**
     * Add the pg_trgm extension command to the blueprint.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @return void
     */
    public function addTrigramExtension($blueprint)
    {
        (fn () => $this->addCommand('scoutPgsqlExtension', ['extension' => 'pg_trgm']))->call($blueprint);
    }

    /**
     * Get the searchable payload keys that can be used in a generated column.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  array  $tableColumns
     * @param  string  $vectorColumn
     * @return array
     *
     * @throws \Laravel\Scout\Exceptions\ScoutException
     */
    protected function generatableColumns(Model $model, array $tableColumns, $vectorColumn)
    {
        $databaseColumns = array_diff($tableColumns, $this->nonImmutableTextColumns($model), [$vectorColumn]);

        $searchableModel = $model->newQuery()->first() ?? $model;

        $columns = array_values(array_intersect(array_keys($searchableModel->toSearchableArray()), $databaseColumns));

        if (empty($columns)) {
            throw new ScoutException(sprintf(
                'No database columns from [%s::toSearchableArray()] exist on [%s]. Add the search vector with the [searchable] schema helper in a migration instead.',
                get_class($model),
                $model->getTable()
            ));
        }

        return $columns;
    }

    /**
     * Get the table columns whose text output is not immutable and cannot be used in a generated column.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return array
     */
    protected function nonImmutableTextColumns(Model $model)
    {
        $connection = $model->getConnection();

        return array_column($connection->select(
            "select a.attname from pg_attribute a join pg_type t on t.oid = a.atttypid join pg_proc p on p.oid = t.typoutput where a.attrelid = to_regclass(?) and a.attnum > 0 and not a.attisdropped and p.provolatile <> 'i'",
            [$connection->getQueryGrammar()->wrapTable($model->getTable())]
        ), 'attname');
    }

    /**
     * Determine if the column already has a GIN index, optionally using the given operator class.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  string  $table
     * @param  string  $column
     * @param  string|null  $operatorClass
     * @return bool
     */
    protected function hasGinIndex(Connection $connection, $table, $column, $operatorClass = null)
    {
        $bindings = [$connection->getQueryGrammar()->wrapTable($table), $column];

        $sql = 'select exists (select 1 from pg_index i join pg_class c on c.oid = i.indexrelid join pg_am am on am.oid = c.relam join pg_attribute a on a.attrelid = i.indrelid and a.attnum = any(i.indkey) where i.indrelid = to_regclass(?) and a.attname = ? and am.amname = \'gin\'';

        if (! is_null($operatorClass)) {
            $sql .= ' and exists (select 1 from pg_opclass o where o.oid = any(i.indclass) and o.opcname = ?)';
            $bindings[] = $operatorClass;
        }

        return (bool) $connection->selectOne($sql.') as "exists"', $bindings)->exists;
    }

    /**
     * Ensure that the helper is running on a PostgreSQL connection.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return void
     */
    public function ensurePostgresqlConnection(Connection $connection)
    {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new InvalidArgumentException('The [pgsql] Scout schema helper may only be used with PostgreSQL connections.');
        }
    }

    /**
     * Ensure the given options do not override values the engine only reads from config.
     *
     * @param  array  $options
     * @return void
     */
    public function ensureSupportedOptions(array $options)
    {
        foreach (['vector_column', 'language'] as $option) {
            if (array_key_exists($option, $options)) {
                throw new InvalidArgumentException(sprintf(
                    'The [pgsql] Scout schema helper [%s] option must be configured in [scout.pgsql.%s] so the engine and schema stay in sync.',
                    $option,
                    $option
                ));
            }
        }
    }

    /**
     * Resolve the connection that owns the given schema blueprint.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @return \Illuminate\Database\Connection
     */
    public function connection(Blueprint $blueprint)
    {
        $connection = $this->connectionFromBlueprint($blueprint) ?? $this->connectionFromSchemaBuilder();

        if ($connection instanceof Connection) {
            return $connection;
        }

        throw new InvalidArgumentException('The [pgsql] Scout schema helper may only be used with PostgreSQL connections.');
    }

    /**
     * Resolve a connection exposed directly by the blueprint.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @return \Illuminate\Database\Connection|null
     */
    protected function connectionFromBlueprint(Blueprint $blueprint)
    {
        if (method_exists($blueprint, 'getConnection')) {
            $connection = $blueprint->getConnection();

            if ($connection instanceof Connection) {
                return $connection;
            }
        }

        if (! property_exists($blueprint, 'connection')) {
            return null;
        }

        $connection = (function () {
            return $this->connection ?? null;
        })->call($blueprint);

        return $connection instanceof Connection ? $connection : null;
    }

    /**
     * Resolve the schema builder connection while older blueprints are constructed.
     *
     * @return \Illuminate\Database\Connection|null
     */
    protected function connectionFromSchemaBuilder()
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $object = $frame['object'] ?? null;

            if ($object instanceof SchemaBuilder) {
                return $object->getConnection();
            }
        }

        return null;
    }

    /**
     * Build the generated search vector expression.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  array  $columns
     * @param  array  $options
     * @return string
     */
    public function searchVectorExpression(Connection $connection, array $columns, array $options = [])
    {
        $grammar = $connection->getSchemaGrammar();
        $language = $this->language();
        $weights = $this->columnWeights($options);
        $columns = $this->searchableColumns($columns);

        return collect($columns)->map(function ($column) use ($grammar, $language, $weights) {
            $weight = $weights[$column] ?? self::COLUMN_WEIGHTS[3];

            return sprintf(
                "setweight(to_tsvector(%s, coalesce(cast(%s as text), '')), '%s')",
                $grammar->quoteString($language),
                $grammar->wrap($column),
                $weight
            );
        })->implode(' || ');
    }

    /**
     * Get the configured search vector column.
     *
     * @return string
     */
    public function vectorColumn()
    {
        return $this->configuration()->vectorColumn();
    }

    /**
     * Determine if the pg_trgm extension should be created.
     *
     * @param  array  $options
     * @return bool
     */
    public function shouldCreateTrigramExtension(array $options = [])
    {
        return (bool) data_get($options, 'trigram.create_extension', data_get($this->config, 'trigram.create_extension', false));
    }

    /**
     * Get the configured trigram index columns.
     *
     * @param  array  $options
     * @return array
     */
    public function trigramColumns(array $options = [])
    {
        return array_map(
            fn ($column) => $this->column($column, 'trigram'),
            Arr::wrap(data_get($options, 'trigram.columns', data_get($this->config, 'trigram.columns', [])))
        );
    }

    /**
     * Create a conventional index name for the table.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  string  $table
     * @param  array  $columns
     * @param  string  $type
     * @return string
     */
    public function indexName(Connection $connection, $table, array $columns, $type)
    {
        if ($connection->getConfig('prefix_indexes')) {
            $table = str_contains($table, '.')
                ? substr_replace($table, '.'.$connection->getTablePrefix(), strrpos($table, '.'), 1)
                : $connection->getTablePrefix().$table;
        }

        $index = strtolower($table.'_'.implode('_', $columns).'_'.$type);

        return str_replace(['-', '.'], '_', $index);
    }

    /**
     * Qualify the index name with the table's schema so drops do not depend on the search path.
     *
     * @param  string  $table
     * @param  string  $index
     * @return string
     */
    public function qualifiedIndexName($table, $index)
    {
        if (! str_contains($table, '.') || str_contains($index, '.')) {
            return $index;
        }

        return substr($table, 0, strrpos($table, '.') + 1).$index;
    }

    /**
     * Get the configured text search language.
     *
     * @return string
     */
    protected function language()
    {
        return $this->configuration()->language();
    }

    /**
     * Get the validated searchable columns.
     *
     * @param  array  $columns
     * @return array
     */
    protected function searchableColumns(array $columns)
    {
        $columns = array_map(fn ($column) => $this->column($column, 'searchable'), $columns);

        if (empty($columns)) {
            throw new InvalidArgumentException('The [pgsql] Scout schema helper searchable columns must not be empty.');
        }

        return $columns;
    }

    /**
     * Get the configured column weights.
     *
     * @param  array  $options
     * @return array
     */
    protected function columnWeights(array $options = [])
    {
        $weights = $options['column_weights'] ?? $this->config['column_weights'] ?? [];

        if (! is_array($weights)) {
            throw new InvalidArgumentException('The [pgsql] Scout schema helper column weights must be an array.');
        }

        foreach ($weights as $column => $weight) {
            $this->column($column, 'column weight');

            if (! in_array($weight, self::COLUMN_WEIGHTS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'The [pgsql] Scout schema helper column weight for [%s] must be one of: %s.',
                    $column,
                    implode(', ', self::COLUMN_WEIGHTS)
                ));
            }
        }

        return $weights;
    }

    /**
     * Get a validated column name.
     *
     * @param  mixed  $column
     * @param  string  $type
     * @return string
     */
    protected function column($column, $type)
    {
        return $this->configuration()->column($column, $type);
    }

    /**
     * Get the PostgreSQL configuration reader instance.
     *
     * @return \Laravel\Scout\Pgsql\Configuration
     */
    protected function configuration()
    {
        return $this->configuration ??= new Configuration($this->config, 'schema helper');
    }
}
