<?php

namespace Laravel\Scout\Engines;

use Laravel\Scout\Builder;
use AlgoliaSearch\Client as Algolia;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

class AlgoliaEngine extends Engine
{
    /**
     * Create a new engine instance.
     *
     * @param  \AlgoliaSearch\Client  $algolia
     * @return void
     */
    public function __construct(Algolia $algolia)
    {
        $this->algolia = $algolia;
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function update($models)
    {
        $index = $this->algolia->initIndex($models->first()->searchableAs());

        $index->addObjects($models->map(function ($model) {
            return array_merge(['objectID' => $model->getKey()], $model->toSearchableArray());
        })->values()->all());
    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $index = $this->algolia->initIndex($models->first()->searchableAs());

        $index->deleteObjects(
            $models->map(function ($model) {
                return $model->getKey();
            })->values()->all()
        );
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $query
     * @return mixed
     */
    public function search(Builder $query)
    {
        return $this->performSearch($query, array_filter([
            'numericFilters' => $this->filters($query),
            'hitsPerPage' => $query->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $query
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $query, $perPage, $page)
    {
        return $this->performSearch($query, [
            'numericFilters' => $this->filters($query),
            'hitsPerPage' => $perPage,
            'page' => $page - 1,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $query
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $query, array $options = [])
    {
        return $this->algolia->initIndex(
            $query->index ?: $query->model->searchableAs()
        )->search($query->query, $options);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  Builder  $query
     * @return array
     */
    protected function filters(Builder $query)
    {
        return collect($query->wheres)->map(function ($value, $key) {
            return $key.'='.$value;
        })->values()->all();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return Collection
     */
    public function map($results, $model)
    {
        if (count($results['hits']) === 0) {
            return Collection::make();
        }

        $keys = collect($results['hits'])
                        ->pluck('objectID')->values()->all();

        $models = $model->whereIn(
            $model->getKeyName(), $keys
        )->get()->keyBy($model->getKeyName());

        return collect($results['hits'])->map(function ($hit) use ($model, $models) {
            return $models[$hit[$model->getKeyName()]];
        });
    }
}
