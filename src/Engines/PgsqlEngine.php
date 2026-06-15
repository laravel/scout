<?php

namespace Laravel\Scout\Engines;

use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Pgsql\Trigram;

class PgsqlEngine extends DatabaseModelEngine
{
    protected const QUERY_FUNCTIONS = [
        'plainto_tsquery',
        'phraseto_tsquery',
        'websearch_to_tsquery',
        'to_tsquery',
    ];

    protected const RANK_FUNCTIONS = [
        'ts_rank',
        'ts_rank_cd',
    ];

    /**
     * The PostgreSQL trigram helper instance.
     *
     * @var \Laravel\Scout\Pgsql\Trigram|null
     */
    protected $trigram;

    /**
     * Create a new engine instance.
     *
     * @param  array  $config
     * @return void
     */
    public function __construct(protected array $config)
    {
        //
    }

    /**
     * Initialize / build the search query for the given Scout builder.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildSearchQuery(Builder $builder)
    {
        $this->ensurePostgresqlConnection($builder);

        $query = $this->initializeSearchQuery($builder, $this->searchableColumns($builder));

        return $this->finalizeSearchQuery($builder, $query);
    }

    /**
     * Build the initial text search database query for all searchable columns.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $columns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function initializeSearchQuery(Builder $builder, array $columns)
    {
        $query = $this->newModelQuery($builder);

        if (blank($builder->query)) {
            return $query;
        }

        $usesTrigram = $this->trigram()->uses($builder);
        $trigramColumns = $usesTrigram ? $this->wrappedTrigramColumns($builder) : [];
        $usesTrigram = $usesTrigram && ! empty($trigramColumns);

        if ($usesTrigram) {
            $this->trigram()->applyThreshold($builder);
        }

        return $query->where(function ($query) use ($builder, $columns, $trigramColumns, $usesTrigram) {
            $canSearchPrimaryKey = ctype_digit($builder->query) &&
                in_array($builder->model->getKeyType(), ['int', 'integer']) &&
                $builder->query <= PHP_INT_MAX &&
                in_array($builder->model->getScoutKeyName(), $columns);

            if ($canSearchPrimaryKey) {
                $query->orWhere($builder->model->getQualifiedKeyName(), $builder->query);
            }

            $query->orWhereRaw(
                sprintf('%s @@ %s(?::regconfig, ?)', $this->vectorColumn($builder), $this->queryFunction()),
                [$this->language(), $builder->query]
            );

            if ($usesTrigram) {
                $query->orWhereRaw(
                    $this->trigram()->predicateExpression($trigramColumns),
                    $this->trigram()->predicateBindings($builder, $trigramColumns)
                );
            }
        });
    }

    /**
     * Add ordering to the search query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function orderSearchQuery(Builder $builder, $query)
    {
        return $query->when($builder->orders, function ($query) use ($builder) {
            foreach ($builder->orders as $order) {
                $query->orderBy($order['column'], $order['direction']);
            }
        })->when(empty($builder->orders) && blank($builder->query), function ($query) use ($builder) {
            $query->orderBy($builder->model->getTable().'.'.$builder->model->getScoutKeyName(), 'desc');
        })->when(empty($builder->orders) && filled($builder->query), function ($query) use ($builder) {
            $usesTrigram = $this->trigram()->uses($builder);
            $trigramColumns = $usesTrigram ? $this->wrappedTrigramColumns($builder) : [];

            if ($usesTrigram && ! empty($trigramColumns)) {
                $query->orderByRaw(
                    sprintf(
                        '((%s * ?) + (%s * ?)) desc',
                        $this->rankExpression($builder),
                        $this->trigramSimilarityExpression($trigramColumns)
                    ),
                    array_merge(
                        [$this->language(), $builder->query, $this->trigram()->scoreWeight('full_text', 1.0)],
                        $this->trigram()->similarityBindings($builder, $trigramColumns),
                        [$this->trigram()->scoreWeight('trigram', 0.25)]
                    )
                );

                return;
            }

            $query->orderByRaw(
                sprintf('%s desc', $this->rankExpression($builder)),
                [$this->language(), $builder->query]
            );
        });
    }

    /**
     * Get the PostgreSQL rank expression for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return string
     */
    protected function rankExpression(Builder $builder)
    {
        return sprintf(
            '%s(%s, %s(?::regconfig, ?))',
            $this->rankFunction(),
            $this->vectorColumn($builder),
            $this->queryFunction()
        );
    }

    /**
     * Get the model's searchable columns.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function searchableColumns(Builder $builder)
    {
        return array_keys($builder->model->toSearchableArray());
    }

    /**
     * Get the trigram similarity expression for the query.
     *
     * @param  array  $columns
     * @return string
     */
    protected function trigramSimilarityExpression(array $columns)
    {
        return $this->trigram()->similarityExpression($columns);
    }

    /**
     * Get the wrapped configured trigram columns present in the model's searchable columns.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function wrappedTrigramColumns(Builder $builder)
    {
        return array_map(fn ($column) => $this->searchableColumn($builder, $column), $this->trigramColumns($builder));
    }

    /**
     * Get the configured trigram columns present in the model's searchable columns.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function trigramColumns(Builder $builder)
    {
        $columns = $this->config['trigram']['columns'] ?? [];

        if (! is_array($columns)) {
            throw new InvalidArgumentException('The [pgsql] Scout driver trigram columns must be an array.');
        }

        $searchableColumns = array_flip($this->searchableColumns($builder));

        return array_values(array_filter($columns, function ($column) use ($searchableColumns) {
            if (! $this->isValidColumnName($column)) {
                throw new InvalidArgumentException(sprintf('The [pgsql] Scout driver trigram column [%s] must be a valid column name.', $column));
            }

            return array_key_exists($column, $searchableColumns);
        }));
    }

    /**
     * Get the PostgreSQL trigram helper instance.
     *
     * @return \Laravel\Scout\Pgsql\Trigram
     */
    protected function trigram()
    {
        return $this->trigram ??= new Trigram($this->config);
    }

    /**
     * Get the configured PostgreSQL text search language.
     *
     * @return string
     */
    protected function language()
    {
        $language = $this->config['language'] ?? 'english';

        if (! $this->isValidConfigurationName($language)) {
            throw new InvalidArgumentException('The [pgsql] Scout driver language must be a valid PostgreSQL text search configuration name.');
        }

        return $language;
    }

    /**
     * Get the configured PostgreSQL search vector column.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return string
     */
    protected function vectorColumn(Builder $builder)
    {
        $column = $this->config['vector_column'] ?? 'search_vector';

        if (! $this->isValidColumnName($column)) {
            throw new InvalidArgumentException('The [pgsql] Scout driver vector column must be a valid column name.');
        }

        return $builder->model->getConnection()->getQueryGrammar()->wrap(
            $builder->model->qualifyColumn($column)
        );
    }

    /**
     * Get the wrapped searchable column name.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  string  $column
     * @return string
     */
    protected function searchableColumn(Builder $builder, $column)
    {
        if (! $this->isValidColumnName($column)) {
            throw new InvalidArgumentException(sprintf('The [pgsql] Scout driver searchable column [%s] must be a valid column name.', $column));
        }

        return $builder->model->getConnection()->getQueryGrammar()->wrap(
            $builder->model->qualifyColumn($column)
        );
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

    /**
     * Get the configured PostgreSQL tsquery function.
     *
     * @return string
     */
    protected function queryFunction()
    {
        $function = $this->config['query_function'] ?? self::QUERY_FUNCTIONS[0];

        if (! in_array($function, self::QUERY_FUNCTIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'The [pgsql] Scout driver query function must be one of: %s.',
                implode(', ', self::QUERY_FUNCTIONS)
            ));
        }

        return $function;
    }

    /**
     * Get the configured PostgreSQL rank function.
     *
     * @return string
     */
    protected function rankFunction()
    {
        $function = $this->config['rank_function'] ?? self::RANK_FUNCTIONS[0];

        if (! in_array($function, self::RANK_FUNCTIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'The [pgsql] Scout driver rank function must be one of: %s.',
                implode(', ', self::RANK_FUNCTIONS)
            ));
        }

        return $function;
    }

    /**
     * Ensure the builder's model is using a PostgreSQL connection.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return void
     */
    protected function ensurePostgresqlConnection(Builder $builder)
    {
        if ($builder->modelConnectionType() !== 'pgsql') {
            throw new InvalidArgumentException('The [pgsql] Scout driver may only be used with PostgreSQL connections.');
        }
    }
}
