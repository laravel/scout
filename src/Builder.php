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
     * The "limit" that should be applied to the search.
     *
     * @var int
     */
    public $limit;

    /**
     * The location constraint added to the query.
     *
     * @var array
     */
    public $location = [];

    /**
     * Create a new search builder instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $query
     * @return void
     */
    public function __construct($model, $query)
    {
        $this->model = $model;
        $this->query = $query;
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
     * Add location constraint to the query
     *
     * @param float $lat
     * @param float $lng
     * @param int $radius Radius in km
     * @return $this
     */
    public function withinLocation($lat, $lng, $radius = 10)
    {
        $this->location = compact('lat', 'lng', 'radius');

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
