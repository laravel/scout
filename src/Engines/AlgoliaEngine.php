<?php

namespace Laravel\Scout\Engines;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\Exceptions\NotSupportedException;

/**
 * @template TAlgoliaClient of object
 */
abstract class AlgoliaEngine extends Engine implements UpdatesIndexSettings
{
    /**
     * The Algolia client.
     *
     * @var TAlgoliaClient
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
     * @param  TAlgoliaClient  $algolia
     * @param  bool  $softDelete
     * @return void
     */
    public function __construct($algolia, $softDelete = false)
    {
        $this->algolia = $algolia;
        $this->softDelete = $softDelete;
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    abstract protected function performSearch(Builder $builder, array $options = []);

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     *
     * @throws \Algolia\AlgoliaSearch\Exceptions\AlgoliaException
     */
    abstract public function update($models);

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    abstract public function delete($models);

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    abstract public function deleteIndex($name);

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    abstract public function flush($model);

    /**
     * Update the index settings for the given index.
     *
     * @return void
     */
    abstract public function updateIndexSettings(string $name, array $settings = []);

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
     * Get the filter array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        $wheres = collect($builder->wheres)
            ->map(fn ($value, $key) => $key.'='.$value)
            ->values();

        return $wheres->merge(collect($builder->whereIns)->map(function ($values, $key) {
            if (empty($values)) {
                return '0=1';
            }

            return collect($values)
                ->map(fn ($value) => $key.'='.$value)
                ->all();
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

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(fn ($model) => in_array($model->getScoutKey(), $objectIds))
            ->map(function ($model) use ($results, $objectIdPositions) {
                $result = $results['hits'][$objectIdPositions[$model->getScoutKey()]] ?? [];

                foreach ($result as $key => $value) {
                    if (substr($key, 0, 1) === '_') {
                        $model->withScoutMetadata($key, $value);
                    }
                }

                return $model;
            })
            ->sortBy(fn ($model) => $objectIdPositions[$model->getScoutKey()])
            ->values();
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

        return $model->queryScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(fn ($model) => in_array($model->getScoutKey(), $objectIds))
            ->map(function ($model) use ($results, $objectIdPositions) {
                $result = $results['hits'][$objectIdPositions[$model->getScoutKey()]] ?? [];

                foreach ($result as $key => $value) {
                    if (substr($key, 0, 1) === '_') {
                        $model->withScoutMetadata($key, $value);
                    }
                }

                return $model;
            })
            ->sortBy(fn ($model) => $objectIdPositions[$model->getScoutKey()])
            ->values();
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
     * Create a search index.
     *
     * @param  string  $name
     * @param  array  $options
     * @return mixed
     *
     * @throws NotSupportedException
     */
    public function createIndex($name, array $options = [])
    {
        throw new NotSupportedException('Algolia indexes are created automatically upon adding objects.');
    }

    /**
     * Configure the soft delete filter within the given settings.
     *
     * @return array
     */
    public function configureSoftDeleteFilter(array $settings = [])
    {
        $settings['attributesForFaceting'][] = 'filterOnly(__soft_deleted)';

        return $settings;
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
