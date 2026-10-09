<?php

namespace Laravel\Scout\Engines;

use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Pgsql\Configuration;
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
     * The PostgreSQL configuration reader instance.
     *
     * @var \Laravel\Scout\Pgsql\Configuration|null
     */
    protected $configuration;

    /**
     * The cached table column listings.
     *
     * @var array
     */
    protected array $tableColumns = [];

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
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->withTrigramThreshold($builder, fn () => parent::search($builder));
    }

    /**
     * Paginate the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginateUsingDatabase(Builder $builder, $perPage, $pageName, $page)
    {
        return $this->withTrigramThreshold($builder, fn () => parent::paginateUsingDatabase($builder, $perPage, $pageName, $page));
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginateUsingDatabase(Builder $builder, $perPage, $pageName, $page)
    {
        return $this->withTrigramThreshold($builder, fn () => parent::simplePaginateUsingDatabase($builder, $perPage, $pageName, $page));
    }

    /**
     * Pluck and return the Scout keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return $results['results']->map(fn ($model) => $model->getScoutKey())->values();
    }

    /**
     * Run the callback with the configured trigram threshold.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  callable  $callback
     * @return mixed
     */
    protected function withTrigramThreshold(Builder $builder, callable $callback)
    {
        if (! $this->usesTrigram($builder)) {
            return $callback();
        }

        $connection = $builder->model->getConnection();

        $previous = $connection->transactionLevel() > 0
            ? $this->trigram()->currentThreshold($builder)
            : false;

        return $connection->transaction(function () use ($builder, $callback, $previous) {
            $this->trigram()->applyThreshold($builder);

            $result = $callback();

            if ($previous !== false) {
                $this->trigram()->setThreshold($builder, $previous);
            }

            return $result;
        });
    }

    /**
     * Determine if trigram matching and ranking apply to the search.
     *
     * Trigram matching is limited to plainto_tsquery so it cannot bypass operators such as negation.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return bool
     */
    protected function usesTrigram(Builder $builder)
    {
        return $this->queryFunction() === 'plainto_tsquery' &&
            $this->trigram()->uses($builder) &&
            ! empty($this->trigramColumns($builder));
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

        $query = $this->initializeSearchQuery($builder);

        return $this->finalizeSearchQuery($builder, $query);
    }

    /**
     * Build the initial text search database query for all searchable columns.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function initializeSearchQuery(Builder $builder)
    {
        $query = $this->newSearchQuery($builder);

        if (blank($builder->query)) {
            return $query;
        }

        return $query->where(function ($query) use ($builder) {
            $canSearchPrimaryKey = $this->isBigintString($builder->query) &&
                in_array($builder->model->getScoutKeyType(), ['int', 'integer']) &&
                in_array($builder->model->getScoutKeyName(), $this->searchableColumns($builder));

            if ($canSearchPrimaryKey) {
                $query->orWhereRaw(
                    sprintf('%s = ?::bigint', $this->wrapColumn($builder, $builder->model->getScoutKeyName())),
                    [$builder->query]
                );
            }

            $query->orWhereRaw(
                sprintf('%s @@ %s(?::regconfig, ?)', $this->vectorColumn($builder), $this->queryFunction()),
                [$this->language(), $builder->query]
            );

            if ($this->usesTrigram($builder)) {
                $trigramColumns = $this->wrappedTrigramColumns($builder);

                $query->orWhereRaw(
                    $this->trigram()->predicateExpression($trigramColumns),
                    $this->trigram()->bindings($builder, $trigramColumns)
                );
            }
        });
    }

    /**
     * Determine if the given value is a decimal string within PostgreSQL's bigint range.
     *
     * @param  mixed  $value
     * @return bool
     */
    protected function isBigintString($value)
    {
        if (! is_string($value) || ! ctype_digit($value)) {
            return false;
        }

        $digits = ltrim($value, '0');

        return strlen($digits) < 19 ||
            (strlen($digits) === 19 && strcmp($digits, '9223372036854775807') <= 0);
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
            $query->orderBy($builder->model->qualifyColumn($builder->model->getScoutKeyName()), 'desc');
        })->when(empty($builder->orders) && filled($builder->query), function ($query) use ($builder) {
            if ($this->usesTrigram($builder)) {
                $trigramColumns = $this->wrappedTrigramColumns($builder);

                $query->orderByRaw(
                    sprintf(
                        '((%s * ?) + (%s * ?)) desc',
                        $this->rankExpression($builder),
                        $this->trigram()->similarityExpression($trigramColumns)
                    ),
                    array_merge(
                        [$this->language(), $builder->query, $this->trigram()->scoreWeight('full_text', 1.0)],
                        $this->trigram()->bindings($builder, $trigramColumns),
                        [$this->trigram()->scoreWeight('trigram', 0.25)]
                    )
                );
            } else {
                $query->orderByRaw(
                    sprintf('%s desc', $this->rankExpression($builder)),
                    [$this->language(), $builder->query]
                );
            }

            $query->orderBy($builder->model->qualifyColumn($builder->model->getScoutKeyName()), 'desc');
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
     * Get the configured trigram columns present on the model's table.
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

        $columns = array_map(fn ($column) => $this->configuration()->column($column, 'trigram'), $columns);

        return array_values(array_intersect($columns, $this->tableColumns($builder)));
    }

    /**
     * Get the column listing for the model's table.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function tableColumns(Builder $builder)
    {
        $connection = $builder->model->getConnection();
        $table = $builder->model->getTable();

        return $this->tableColumns[spl_object_id($connection)][$table] ??= $connection->getSchemaBuilder()->getColumnListing($table);
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
     * Get the PostgreSQL configuration reader instance.
     *
     * @return \Laravel\Scout\Pgsql\Configuration
     */
    protected function configuration()
    {
        return $this->configuration ??= new Configuration($this->config, 'driver');
    }

    /**
     * Get the configured PostgreSQL text search language.
     *
     * @return string
     */
    protected function language()
    {
        return $this->configuration()->language();
    }

    /**
     * Get the configured PostgreSQL search vector column.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return string
     */
    protected function vectorColumn(Builder $builder)
    {
        return $this->wrapColumn($builder, $this->configuration()->vectorColumn());
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
        return $this->wrapColumn($builder, $this->configuration()->column($column, 'searchable'));
    }

    /**
     * Wrap the qualified column name for the model's connection.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  string  $column
     * @return string
     */
    protected function wrapColumn(Builder $builder, $column)
    {
        return $builder->model->getConnection()->getQueryGrammar()->wrap(
            $builder->model->qualifyColumn($column)
        );
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
