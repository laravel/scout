<?php

namespace Laravel\Scout\Engines;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
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
            'results' => $models->forPage($page, $perPage)->all(),
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
        $query = $builder->model->query()
                        ->when(! is_null($builder->callback), function ($query) use ($builder) {
                            call_user_func($builder->callback, $query, $builder, $builder->query);
                        })
                        ->when(! $builder->callback && count($builder->wheres) > 0, function ($query) use ($builder) {
                            foreach ($builder->wheres as $key => $value) {
                                if ($key !== '__soft_deleted') {
                                    $query->where($key, $value);
                                }
                            }
                        })
                        ->when(! $builder->callback && count($builder->whereIns) > 0, function ($query) use ($builder) {
                            foreach ($builder->whereIns as $key => $values) {
                                $query->whereIn($key, $values);
                            }
                        })
                        ->orderBy($builder->model->getKeyName(), 'desc');

        $models = $this->ensureSoftDeletesAreHandled($builder, $query)
                        ->get()
                        ->values();

        if (count($models) === 0) {
            return $models;
        }

        $columns = array_keys($models->first()->toSearchableArray());

        return $models->filter(function ($model) use ($builder, $columns) {
            if (! $model->shouldBeSearchable()) {
                return false;
            }

            foreach ($columns as $column) {
                $attribute = $model->{$column};

                if (Str::contains(Str::lower($attribute), Str::lower($builder->query))) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Ensure that soft delete handling is properly applied to the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Query\Builder
     */
    protected function ensureSoftDeletesAreHandled($builder, $query)
    {
        if (Arr::get($builder->wheres, '__soft_deleted') === 0) {
            return $query->withoutTrashed();
        } elseif (Arr::get($builder->wheres, '__soft_deleted') === 1) {
            return $query->onlyTrashed();
        } elseif (in_array(SoftDeletes::class, class_uses_recursive(get_class($builder->model))) &&
                  config('scout.soft_delete', false)) {
            return $query->withTrashed();
        }

        return $query;
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
