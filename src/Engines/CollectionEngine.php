<?php

namespace Laravel\Scout\Engines;

use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Laravel\Scout\Builder;

class CollectionEngine extends Engine
{
    /**
     * Create a new engine instance.
     *
     * @return void
     */
    public function __construct()
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
            'results' => $models->all(),
            'total' => count($models),
        ];
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
        $models = $this->searchModels($builder);

        return [
            'results' => $models->forPage($page - 1, $perPage)->all(),
            'total' => count($models),
        ];
    }

    /**
     * Get the Eloquent models for the given builder.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function searchModels(Builder $builder)
    {
        $models = $builder->model->query()
                        ->when(count($builder->wheres) > 0, function ($query) use ($builder) {
                            foreach ($builder->wheres as $key => $value) {
                                $query->where($key, $value);
                            }
                        })
                        ->orderBy($builder->model->getKeyName(), 'desc')
                        ->get();

        $models = $models->values();

        if (count($models) === 0) {
            return $models;
        }

        $columns = array_keys($models->first()->toSearchableArray());

        return $models->filter(function ($model) use ($builder, $columns) {
            foreach ($columns as $column) {
                $attribute = $model->{$column};

                if (Str::contains($attribute, $builder->query)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        $results = $results['results'];

        return count($results) > 0
                    ? collect($results)->pluck($results[0]->getKeyName())->values()
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
        $results = $results['results'];

        if (count($results) === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results)
                ->pluck($model->getKeyName())
                ->values()
                ->all();

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
        $results = $results['results'];

        if (count($results) === 0) {
            return LazyCollection::empty();
        }

        $objectIds = collect($results)
                ->pluck($model->getKeyName())
                ->values()->all();

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
}
