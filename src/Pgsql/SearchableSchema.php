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
        if (! Blueprint::hasMacro('searchable')) {
            Blueprint::macro('searchable', function ($columns, array $options = []) {
                $helper = new SearchableSchema(config('scout.pgsql', []));

                $helper->ensurePostgresqlConnection($this->connection);

                $columns = Arr::wrap($columns);
                $vectorColumn = $helper->vectorColumn($options);

                if ($helper->shouldCreateTrigramExtension($options)) {
                    $this->addCommand('scoutPgsqlExtension', ['extension' => 'pg_trgm']);
                }

                $this->tsvector($vectorColumn)->storedAs(new Expression(
                    $helper->searchVectorExpression($this->connection, $columns, $options)
                ));

                $this->index($vectorColumn, $options['index'] ?? null, 'gin');

                foreach ($helper->trigramColumns($options) as $column) {
                    $this->addCommand('scoutPgsqlTrigramIndex', [
                        'column' => $column,
                        'index' => $this->createIndexName('trigram_index', [$column]),
                    ]);
                }
            });
        }

        if (! PostgresGrammar::hasMacro('compileScoutPgsqlExtension')) {
            PostgresGrammar::macro('compileScoutPgsqlExtension', function (Blueprint $blueprint, Fluent $command) {
                return sprintf('create extension if not exists %s', $this->wrapValue($command->extension));
            });
        }

        if (! PostgresGrammar::hasMacro('compileScoutPgsqlTrigramIndex')) {
            PostgresGrammar::macro('compileScoutPgsqlTrigramIndex', function (Blueprint $blueprint, Fluent $command) {
                return sprintf(
                    'create index %s on %s using gin (%s gin_trgm_ops)',
                    $this->wrap($command->index),
                    $this->wrapTable($blueprint),
                    $this->wrap($command->column)
                );
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

        return collect($columns)->map(function ($column) use ($grammar, $language, $weights) {
            $weight = $weights[$column] ?? self::COLUMN_WEIGHTS[3];

            if (! in_array($weight, self::COLUMN_WEIGHTS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'The [pgsql] Scout schema helper column weight for [%s] must be one of: %s.',
                    $column,
                    implode(', ', self::COLUMN_WEIGHTS)
                ));
            }

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
        return $options['vector_column'] ?? $this->config['vector_column'] ?? 'search_vector';
    }

    /**
     * Determine if the pg_trgm extension should be created.
     *
     * @param  array  $options
     * @return bool
     */
    public function shouldCreateTrigramExtension(array $options = [])
    {
        return (bool) data_get($options, 'trigram.extension.create', data_get($this->config, 'trigram.extension.create', false));
    }

    /**
     * Get the configured trigram index columns.
     *
     * @param  array  $options
     * @return array
     */
    public function trigramColumns(array $options = [])
    {
        return Arr::wrap(data_get($options, 'trigram.columns', []));
    }

    /**
     * Get the configured text search language.
     *
     * @param  array  $options
     * @return string
     */
    protected function language(array $options = [])
    {
        return $options['language'] ?? $this->config['language'] ?? 'english';
    }

    /**
     * Get the configured column weights.
     *
     * @param  array  $options
     * @return array
     */
    protected function columnWeights(array $options = [])
    {
        return $options['weights'] ?? $options['column_weights'] ?? $this->config['column_weights'] ?? [];
    }
}
