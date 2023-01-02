<?php

namespace Laravel\Scout\Engines;

use Algolia\AlgoliaSearch\SearchClient as Algolia;
use Exception;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Jobs\RemoveableScoutCollection;

class AlgoliaEngine extends Engine
{
    /**
     * The Algolia client.
     *
     * @var \Algolia\AlgoliaSearch\SearchClient
     */
    protected $algolia;

    /**
     * Determines if soft deletes for Scout are enabled or not.
     *
     * @var bool
     */
    protected $softDelete;

    /**
     * Create a new engine instance.
     *
     * @param  \Algolia\AlgoliaSearch\SearchClient  $algolia
     * @param  bool  $softDelete
     * @return void
     */
    public function __construct(Algolia $algolia, $softDelete = false)
    {
        $this->algolia = $algolia;
        $this->softDelete = $softDelete;
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     *
     * @throws \Algolia\AlgoliaSearch\Exceptions\AlgoliaException
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $this->algolia->initIndex($models->first()->searchableAs());

        if ($this->usesSoftDelete($models->first()) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $objects = $models->map(function ($model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return;
            }

            return array_merge(
                $searchableData,
                $model->scoutMetadata(),
                ['objectID' => $model->getScoutKey()],
            );
        })->filter()->values()->all();

        if (! empty($objects)) {
            $index->saveObjects($objects);
        }
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $this->algolia->initIndex($models->first()->searchableAs());

        $keys = $models instanceof RemoveableScoutCollection
            ? $models->pluck($models->first()->getUnqualifiedScoutKeyName())
            : $models->map->getScoutKey();

        $index->deleteObjects($keys->all());
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'hitsPerPage' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'hitsPerPage' => $perPage,
            'page' => $page - 1,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $algolia = $this->algolia->initIndex(
            $builder->index ?: $builder->model->searchableAs()
        );

        $options = array_merge($builder->options, $options);

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $algolia,
                $builder->query,
                $options
            );
        }

        return $algolia->search($builder->query, $options);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        $wheres = collect($builder->wheres)->map(function ($value, $key) {
            return $key.'='.$value;
        })->values();

        return $wheres->merge(collect($builder->whereIns)->map(function ($values, $key) {
            return collect($values)->map(function ($value) use ($key) {
                return $key.'='.$value;
            })->all();
        })->values())->values()->all();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits'])->pluck('objectID')->values();
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
        if (count($results['hits']) === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results['hits'])->pluck('objectID')->values()->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds(
            $builder, $objectIds
        )->filter(function ($model) use ($objectIds) {
            return in_array($model->getScoutKey(), $objectIds);
        })->sortBy(function ($model) use ($objectIdPositions) {
            return $objectIdPositions[$model->getScoutKey()];
        })->values();
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
        if (count($results['hits']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = collect($results['hits'])->pluck('objectID')->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds(
                $builder, $objectIds
            )->cursor()->filter(function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds);
            })->sortBy(function ($model) use ($objectIdPositions) {
                return $objectIdPositions[$model->getScoutKey()];
            })->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['nbHits'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $index = $this->algolia->initIndex($model->searchableAs());

        $index->clearObjects();
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
        throw new Exception('Algolia indexes are created automatically upon adding objects.');
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    public function deleteIndex($name)
    {
        return $this->algolia->initIndex($name)->delete();
    }

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }

    /**
     * Dynamically call the Algolia client instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->algolia->$method(...$parameters);
    }
}
