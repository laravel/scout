<?php

namespace Laravel\Scout\Pgsql;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Arr;
use Illuminate\Support\Fluent;
use InvalidArgumentException;

class SearchableSchema
{
    /**
     * The supported PostgreSQL text search weights.
     */
    protected const COLUMN_WEIGHTS = ['A', 'B', 'C', 'D'];

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

                $connection = null;

                if (property_exists($this, 'connection')) {
                    /** @phpstan-ignore property.protected */
                    $connection = $this->connection;
                }

                $connection = $connection instanceof Connection ? $connection : app('db')->connection();

                $helper->ensurePostgresqlConnection($connection);

                $columns = Arr::wrap($columns);
                $vectorColumn = $helper->vectorColumn($options);

                if ($helper->shouldCreateTrigramExtension($options)) {
                    /** @phpstan-ignore method.protected */
                    $this->addCommand('scoutPgsqlExtension', ['extension' => 'pg_trgm']);
                }

                $this->tsvector($vectorColumn)->storedAs(new Expression(
                    $helper->searchVectorExpression($connection, $columns, $options)
                ));

                $this->index($vectorColumn, $options['index'] ?? null, 'gin');

                foreach ($helper->trigramColumns($options) as $column) {
                    $this->rawIndex(
                        sprintf('%s gin_trgm_ops', $connection->getSchemaGrammar()->wrap($column)),
                        $helper->indexName($connection, $this->getTable(), [$column], 'trigram_index')
                    )->algorithm('gin');
                }
            });
        }

        if (! Blueprint::hasMacro('dropSearchable')) {
            Blueprint::macro('dropSearchable', function (array $options = []) {
                $helper = new SearchableSchema(config('scout.pgsql', []));

                $connection = null;

                if (property_exists($this, 'connection')) {
                    /** @phpstan-ignore property.protected */
                    $connection = $this->connection;
                }

                $connection = $connection instanceof Connection ? $connection : app('db')->connection();

                $helper->ensurePostgresqlConnection($connection);

                $vectorColumn = $helper->vectorColumn($options);

                if (array_key_exists('index', $options)) {
                    $this->dropIndex($options['index']);
                } else {
                    $this->dropIndex([$vectorColumn]);
                }

                foreach ($helper->trigramColumns($options) as $column) {
                    $this->dropIndex($helper->indexName($connection, $this->getTable(), [$column], 'trigram_index'));
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
        $language = $this->language($options);
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
     * @param  array  $options
     * @return string
     */
    public function vectorColumn(array $options = [])
    {
        $column = $options['vector_column'] ?? $this->config['vector_column'] ?? 'search_vector';

        if (! $this->isValidColumnName($column)) {
            throw new InvalidArgumentException('The [pgsql] Scout schema helper vector column must be a valid column name.');
        }

        return $column;
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
     * Get the configured text search language.
     *
     * @param  array  $options
     * @return string
     */
    protected function language(array $options = [])
    {
        $language = $options['language'] ?? $this->config['language'] ?? 'english';

        if (! $this->isValidConfigurationName($language)) {
            throw new InvalidArgumentException('The [pgsql] Scout schema helper language must be a valid PostgreSQL text search configuration name.');
        }

        return $language;
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
        $weights = $options['weights'] ?? $options['column_weights'] ?? $this->config['column_weights'] ?? [];

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
        if (! $this->isValidColumnName($column)) {
            throw new InvalidArgumentException(sprintf('The [pgsql] Scout schema helper %s column [%s] must be a valid column name.', $type, $column));
        }

        return $column;
    }

    /**
     * Determine if the given value is a safe PostgreSQL column name.
     *
     * @param  mixed  $value
     * @return bool
     */
    protected function isValidColumnName($value)
    {
        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }

    /**
     * Determine if the given value is a safe PostgreSQL configuration name.
     *
     * @param  mixed  $value
     * @return bool
     */
    protected function isValidConfigurationName($value)
    {
        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $value) === 1;
    }
}
