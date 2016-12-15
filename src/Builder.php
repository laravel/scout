<?php

namespace Laravel\Scout;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;

class Builder
{
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
     * @var string
     */
    public $callback;

    /**
     * The custom index specified for the search.
     *
     * @var string
     */
    public $index;

    /**
     * The "where" constraints that should be applied to the query.
     *
     * @var array
     */
    public $wheres = [
        'and' => [],
        'between' => [],
        'in' => [],
        'not' => [],
        'not_in' => [],
        'not_null' => [],
        'null' => [],
        'or' => [],
        'or_between' => [],
    ];

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
     * Create a new search builder instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $query
     * @param  Closure  $callback
     * @return void
     */
    public function __construct($model, $query, $callback = null)
    {
        $this->model = $model;
        $this->query = $query;
        $this->callback = $callback;
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
     * Add a "where" constraint for the query.
     *
     * @param  string $field
     * @param  mixed  $value
     * @return $this
     */
    public function where($field, $value)
    {
        if (is_null($value)) {
            return $this->whereNull($field);
        }

        $this->wheres['and'][] = [$field => $value];

        return $this;
    }

    /**
     * Add an "or where" constraint for the query.
     *
     * @param  string $field
     * @param  mixed  $value
     * @return $this
     */
    public function orWhere($field, $value)
    {
        $this->wheres['or'][] = [$field => $value];

        return $this;
    }

    /**
     * Add a "where between" constraint for the query.
     *
     * @param  string $field
     * @param  array  $value
     * @return $this
     */
    public function whereBetween($field, array $value)
    {
        $this->wheres['between'][] = [$field => $value];

        return $this;
    }

    /**
     * Add an "or where between" constraint for the query.
     *
     * @param  string $field
     * @param  array  $value
     * @return $this
     */
    public function orWhereBetween($field, array $value)
    {
        $this->wheres['or_between'][] = [$field => $value];

        return $this;
    }

    /**
     * Add a "where in" constraint for the query.
     *
     * @param  string $field
     * @param  mixed  $value
     * @return $this
     */
    public function whereIn($field, $value)
    {
        $this->wheres['in'][] = [$field => $value];

        return $this;
    }

    /**
     * Add a "where not" constraint for the query.
     *
     * @param  string $field
     * @param  mixed  $value
     * @return $this
     */
    public function whereNot($field, $value)
    {
        $this->wheres['not'][] = [$field => $value];

        return $this;
    }

    /**
     * Add a "where not in" constraint for the query.
     *
     * @param  string $field
     * @param  mixed  $value
     * @return $this
     */
    public function whereNotIn($field, $value)
    {
        $this->wheres['not_in'][] = [$field => $value];

        return $this;
    }

    /**
     * Add a "where null" constraint for the query.
     *
     * @param  string $field
     * @param  mixed  $value
     * @return $this
     */
    public function whereNull($field)
    {
        $this->wheres['null'][] = $field;

        return $this;
    }

    /**
     * Add a "where not null" constraint for the query.
     *
     * @param  string $field
     * @param  mixed  $value
     * @return $this
     */
    public function whereNotNull($field)
    {
        $this->wheres['not_null'][] = $field;

        return $this;
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
     * Paginate the given query into a simple paginator.
     *
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = null, $pageName = 'page', $page = null)
    {
        $engine = $this->engine();

        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        $results = Collection::make($engine->map(
            $rawResults = $engine->paginate($this, $perPage, $page), $this->model
        ));

        $paginator = (new LengthAwarePaginator($results, $engine->getTotalCount($rawResults), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]));

        return $paginator->appends('query', $this->query);
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
