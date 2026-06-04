<?php

namespace Laravel\Scout\Engines;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase;

class PgsqlEngine extends Engine implements PaginatesEloquentModelsUsingDatabase
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

    protected const COLUMN_WEIGHTS = ['A', 'B', 'C', 'D'];

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
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        //
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
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
        $models = $this->searchModels($builder);

        return [
            'results' => $models,
            'total' => $models->count(),
        ];
    }

    /**
     * Get the Eloquent models for the given builder.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int|null  $page
     * @param  int|null  $perPage
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function searchModels(Builder $builder, $page = null, $perPage = null)
    {
        $query = $this->buildSearchQuery($builder)
            ->when(! is_null($page) && ! is_null($perPage), function ($query) use ($page, $perPage) {
                $query->forPage($page, $perPage);
            });

        return $this->orderSearchQuery($builder, $query)->get();
    }

    /**
     * Paginate the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->paginateUsingDatabase($builder, $perPage, 'page', $page);
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
        return $this->orderSearchQuery($builder, $this->buildSearchQuery($builder))
            ->paginate($perPage, ['*'], $pageName, $page);
    }

    /**
     * Paginate the given search on the engine using simple pagination.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate(Builder $builder, $perPage, $page)
    {
        return $this->simplePaginateUsingDatabase($builder, $perPage, 'page', $page);
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginateUsingDatabase(Builder $builder, $perPage, $pageName, $page)
    {
        return $this->orderSearchQuery($builder, $this->buildSearchQuery($builder))
            ->simplePaginate($perPage, ['*'], $pageName, $page);
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

        return $this->constrainForSoftDeletes(
            $builder, $this->addAdditionalConstraints($builder, $query->take($builder->limit))
        );
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
        $query = method_exists($builder->model, 'newScoutQuery')
            ? $builder->model->newScoutQuery($builder)
            : $builder->model->newQuery();

        if (blank($builder->query)) {
            return $query;
        }

        return $query->where(function ($query) use ($builder, $columns) {
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
            $query->orderByRaw(
                sprintf(
                    '%s(%s, %s(?::regconfig, ?)) desc',
                    $this->rankFunction(),
                    $this->vectorColumn($builder),
                    $this->queryFunction()
                ),
                [$this->language(), $builder->query]
            );
        });
    }

    /**
     * Get the weighted PostgreSQL search vector expression for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return string
     */
    protected function searchVectorExpression(Builder $builder)
    {
        $columns = $this->searchableColumns($builder);

        if (empty($columns)) {
            return "''::tsvector";
        }

        return collect($columns)->map(function ($column) use ($builder) {
            return sprintf(
                "setweight(to_tsvector('%s', coalesce(cast(%s as text), '')), '%s')",
                $this->language(),
                $this->searchableColumn($builder, $column),
                $this->columnWeight($builder, $column)
            );
        })->implode(' || ');
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
     * Add additional, developer defined constraints to the search query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function addAdditionalConstraints(Builder $builder, $query)
    {
        return $query->when(! is_null($builder->callback), function ($query) use ($builder) {
            call_user_func($builder->callback, $query, $builder, $builder->query);
        })->when(! $builder->callback && count($builder->wheres) > 0, function ($query) use ($builder) {
            foreach ($builder->wheres as $where) {
                if ($where['field'] !== '__soft_deleted') {
                    $query->where($where['field'], $where['operator'], $where['value']);
                }
            }
        })->when(! $builder->callback && count($builder->whereIns) > 0, function ($query) use ($builder) {
            foreach ($builder->whereIns as $key => $values) {
                $query->whereIn($key, $values);
            }
        })->when(! $builder->callback && count($builder->whereNotIns) > 0, function ($query) use ($builder) {
            foreach ($builder->whereNotIns as $key => $values) {
                $query->whereNotIn($key, $values);
            }
        })->when(! is_null($builder->queryCallback), function ($query) use ($builder) {
            call_user_func($builder->queryCallback, $query);
        });
    }

    /**
     * Ensure that soft delete constraints are properly applied to the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function constrainForSoftDeletes($builder, $query)
    {
        $softDeleteWhere = collect($builder->wheres)->firstWhere('field', '__soft_deleted');

        return match (true) {
            $softDeleteWhere && $softDeleteWhere['value'] === 0 => $query->withoutTrashed(),
            $softDeleteWhere && $softDeleteWhere['value'] === 1 => $query->onlyTrashed(),
            in_array(SoftDeletes::class, class_uses_recursive(get_class($builder->model))) &&
                config('scout.soft_delete', false) => $query->withTrashed(),
            default => $query,
        };
    }

    /**
     * Get the configured PostgreSQL text search language.
     *
     * @return string
     */
    protected function language()
    {
        $language = $this->config['language'] ?? 'english';

        if (! $this->isValidIdentifier($language)) {
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

        if (! $this->isValidIdentifier($column)) {
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
        if (! $this->isValidIdentifier($column)) {
            throw new InvalidArgumentException(sprintf('The [pgsql] Scout driver searchable column [%s] must be a valid column name.', $column));
        }

        return $builder->model->getConnection()->getQueryGrammar()->wrap(
            $builder->model->qualifyColumn($column)
        );
    }

    /**
     * Get the configured PostgreSQL column weight.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  string  $column
     * @return string
     */
    protected function columnWeight(Builder $builder, $column)
    {
        $weight = $this->columnWeights()[$column] ?? self::COLUMN_WEIGHTS[3];

        if (! in_array($weight, self::COLUMN_WEIGHTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'The [pgsql] Scout driver column weight for [%s] must be one of: %s.',
                $column,
                implode(', ', self::COLUMN_WEIGHTS)
            ));
        }

        return $weight;
    }

    /**
     * Get the configured PostgreSQL column weights.
     *
     * @return array
     */
    protected function columnWeights()
    {
        $weights = $this->config['column_weights'] ?? [];

        if (! is_array($weights)) {
            throw new InvalidArgumentException('The [pgsql] Scout driver column weights must be an array.');
        }

        return $weights;
    }

    /**
     * Determine if the given value is a safe PostgreSQL identifier or configuration name.
     *
     * @param  mixed  $value
     * @return bool
     */
    protected function isValidIdentifier($value)
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
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return count($results['results']) > 0
            ? collect($results['results']->modelKeys())
            : collect();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        return $results['results'];
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        return new LazyCollection($results['results']->all());
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['total'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        //
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @param  array  $options
     * @return mixed
     *
     * @throws \Exception
     */
    public function createIndex($name, array $options = [])
    {
        //
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    public function deleteIndex($name)
    {
        //
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
