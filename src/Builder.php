<?php

namespace Laravel\Scout;

use Illuminate\Container\Container;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Traits\Macroable;
use Laravel\Scout\Contracts\PaginatesEloquentModels;
use Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase;

class Builder
{
    use Macroable;

    /**
     * The model instance.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    public $model;

    /**
     * The query expression.
     *
     * @var string
     */
    public $query;

    /**
     * Optional callback before search execution.
     *
     * @var \Closure|null
     */
    public $callback;

    /**
     * Optional callback before model query execution.
     *
     * @var \Closure|null
     */
    public $queryCallback;

    /**
     * The custom index specified for the search.
     *
     * @var string
     */
    public $index;

    /**
     * The "where" constraints added to the query.
     *
     * @var array
     */
    public $wheres = [];

    /**
     * The "where in" constraints added to the query.
     *
     * @var array
     */
    public $whereIns = [];

    /**
     * The "limit" that should be applied to the search.
     *
     * @var int
     */
    public $limit;

    /**
     * The "order" that should be applied to the search.
     *
     * @var array
     */
    public $orders = [];

    /**
     * Extra options that should be applied to the search.
     *
     * @var array
     */
    public $options = [];

    /**
     * Create a new search builder instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $query
     * @param  \Closure|null  $callback
     * @param  bool  $softDelete
     * @return void
     */
    public function __construct($model, $query, $callback = null, $softDelete = false)
    {
        $this->model = $model;
        $this->query = $query;
        $this->callback = $callback;

        if ($softDelete) {
            $this->wheres['__soft_deleted'] = 0;
        }
    }

    /**
     * Specify a custom index to perform this search on.
     *
     * @param  string  $index
     * @return $this
     */
    public function within($index)
    {
        $this->index = $index;

        return $this;
    }

    /**
     * Add a constraint to the search query.
     *
     * @param  string  $field
     * @param  mixed  $value
     * @return $this
     */
    public function where($field, $value)
    {
        $this->wheres[$field] = $value;

        return $this;
    }

    /**
     * Add a "where in" constraint to the search query.
     *
     * @param  string  $field
     * @param  array  $values
     * @return $this
     */
    public function whereIn($field, array $values)
    {
        $this->whereIns[$field] = $values;

        return $this;
    }

    /**
     * Include soft deleted records in the results.
     *
     * @return $this
     */
    public function withTrashed()
    {
        unset($this->wheres['__soft_deleted']);

        return $this;
    }

    /**
     * Include only soft deleted records in the results.
     *
     * @return $this
     */
    public function onlyTrashed()
    {
        return tap($this->withTrashed(), function () {
            $this->wheres['__soft_deleted'] = 1;
        });
    }

    /**
     * Set the "limit" for the search query.
     *
     * @param  int  $limit
     * @return $this
     */
    public function take($limit)
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Add an "order" for the search query.
     *
     * @param  string  $column
     * @param  string  $direction
     * @return $this
     */
    public function orderBy($column, $direction = 'asc')
    {
        $this->orders[] = [
            'column' => $column,
            'direction' => strtolower($direction) == 'asc' ? 'asc' : 'desc',
        ];

        return $this;
    }

    /**
     * Set extra options for the search query.
     *
     * @param  array  $options
     * @return $this
     */
    public function options(array $options)
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Apply the callback's query changes if the given "value" is true.
     *
     * @param  mixed  $value
     * @param  callable  $callback
     * @param  callable  $default
     * @return mixed
     */
    public function when($value, $callback, $default = null)
    {
        if ($value) {
            return $callback($this, $value) ?: $this;
        } elseif ($default) {
            return $default($this, $value) ?: $this;
        }

        return $this;
    }

    /**
     * Pass the query to a given callback.
     *
     * @param  \Closure  $callback
     * @return $this
     */
    public function tap($callback)
    {
        return $this->when(true, $callback);
    }

    /**
     * Set the callback that should have an opportunity to modify the database query.
     *
     * @param  callable  $callback
     * @return $this
     */
    public function query($callback)
    {
        $this->queryCallback = $callback;

        return $this;
    }

    /**
     * Get the raw results of the search.
     *
     * @return mixed
     */
    public function raw()
    {
        return $this->engine()->search($this);
    }

    /**
     * Get the keys of search results.
     *
     * @return \Illuminate\Support\Collection
     */
    public function keys()
    {
        return $this->engine()->keys($this);
    }

    /**
     * Get the first result from the search.
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function first()
    {
        return $this->get()->first();
    }

    /**
     * Get the results of the search.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get()
    {
        return $this->engine()->get($this);
    }

    /**
     * Get the results of the search as a "lazy collection" instance.
     *
     * @return \Illuminate\Support\LazyCollection
     */
    public function cursor()
    {
        return $this->engine()->cursor($this);
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate($perPage = null, $pageName = 'page', $page = null)
    {
        $engine = $this->engine();

        if ($engine instanceof PaginatesEloquentModels) {
            return $engine->simplePaginate($this, $perPage, $page)->appends('query', $this->query);
        } elseif ($engine instanceof PaginatesEloquentModelsUsingDatabase) {
            return $engine->simplePaginateUsingDatabase($this, $perPage, $pageName, $page)->appends('query', $this->query);
        }

        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        $results = $this->model->newCollection($engine->map(
            $this, $rawResults = $engine->paginate($this, $perPage, $page), $this->model
        )->all());

        $paginator = Container::getInstance()->makeWith(Paginator::class, [
            'items' => $results,
            'perPage' => $perPage,
            'currentPage' => $page,
            'options' => [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        ])->hasMorePagesWhen(($perPage * $page) < $engine->getTotalCount($rawResults));

        return $paginator->appends('query', $this->query);
    }

    /**
     * Paginate the given query into a simple paginator with raw data.
     *
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginateRaw($perPage = null, $pageName = 'page', $page = null)
    {
        $engine = $this->engine();

        if ($engine instanceof PaginatesEloquentModels) {
            return $engine->simplePaginate($this, $perPage, $page)->appends('query', $this->query);
        } elseif ($engine instanceof PaginatesEloquentModelsUsingDatabase) {
            return $engine->simplePaginateUsingDatabase($this, $perPage, $pageName, $page)->appends('query', $this->query);
        }

        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        $results = $engine->paginate($this, $perPage, $page);

        $paginator = Container::getInstance()->makeWith(Paginator::class, [
            'items' => $results,
            'perPage' => $perPage,
            'currentPage' => $page,
            'options' => [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        ])->hasMorePagesWhen(($perPage * $page) < $engine->getTotalCount($results));

        return $paginator->appends('query', $this->query);
    }

    /**
     * Paginate the given query into a paginator.
     *
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = null, $pageName = 'page', $page = null)
    {
        $engine = $this->engine();

        if ($engine instanceof PaginatesEloquentModels) {
            return $engine->paginate($this, $perPage, $page)->appends('query', $this->query);
        } elseif ($engine instanceof PaginatesEloquentModelsUsingDatabase) {
            return $engine->paginateUsingDatabase($this, $perPage, $pageName, $page)->appends('query', $this->query);
        }

        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        $results = $this->model->newCollection($engine->map(
            $this, $rawResults = $engine->paginate($this, $perPage, $page), $this->model
        )->all());

        return Container::getInstance()->makeWith(LengthAwarePaginator::class, [
            'items' => $results,
            'total' => $this->getTotalCount($rawResults),
            'perPage' => $perPage,
            'currentPage' => $page,
            'options' => [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        ])->appends('query', $this->query);
    }

    /**
     * Paginate the given query into a paginator with raw data.
     *
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginateRaw($perPage = null, $pageName = 'page', $page = null)
    {
        $engine = $this->engine();

        if ($engine instanceof PaginatesEloquentModels) {
            return $engine->paginate($this, $perPage, $page)->appends('query', $this->query);
        } elseif ($engine instanceof PaginatesEloquentModelsUsingDatabase) {
            return $engine->paginateUsingDatabase($this, $perPage, $pageName, $page)->appends('query', $this->query);
        }

        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        $results = $engine->paginate($this, $perPage, $page);

        return Container::getInstance()->makeWith(LengthAwarePaginator::class, [
            'items' => $results,
            'total' => $this->getTotalCount($results),
            'perPage' => $perPage,
            'currentPage' => $page,
            'options' => [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $pageName,
            ],
        ])->appends('query', $this->query);
    }

    /**
     * Get the total number of results from the Scout engine, or fallback to query builder.
     *
     * @param  mixed  $results
     * @return int
     */
    protected function getTotalCount($results)
    {
        $engine = $this->engine();

        $totalCount = $engine->getTotalCount($results);

        if (is_null($this->queryCallback)) {
            return $totalCount;
        }

        $ids = $engine->mapIdsFrom($results, $this->model->getScoutKeyName())->all();

        if (count($ids) < $totalCount) {
            $ids = $engine->keys(tap(clone $this, function ($builder) use ($totalCount) {
                $builder->take(
                    is_null($this->limit) ? $totalCount : min($this->limit, $totalCount)
                );
            }))->all();
        }

        return $this->model->queryScoutModelsByIds(
            $this, $ids
        )->toBase()->getCountForPagination();
    }

    /**
     * Get the engine that should handle the query.
     *
     * @return mixed
     */
    protected function engine()
    {
        return $this->model->searchableUsing();
    }
}
