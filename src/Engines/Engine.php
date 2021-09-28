<?php

namespace Laravel\Scout\Engines;

use Laravel\Scout\Builder;

abstract class Engine
{
    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
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
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    abstract public function search(Builder $builder);

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    abstract public function paginate(Builder $builder, $perPage, $page);

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    abstract public function mapIds($results);

    /**
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    abstract public function map(Builder $builder, $results, $model);

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\LazyCollection
     */
    abstract public function lazyMap(Builder $builder, $results, $model);

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    abstract public function getTotalCount($results);

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    abstract public function flush($model);

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @param  array  $options
     * @return mixed
     */
    abstract public function createIndex($name, array $options = []);

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    abstract public function deleteIndex($name);

    /**
     * Get the results of the query as a Collection of primary keys.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Support\Collection
     */
    public function keys(Builder $builder)
    {
        return $this->mapIds($this->search($builder));
    }

    /**
     * Get the results of the given query mapped onto models.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function get(Builder $builder)
    {
        return $this->map(
            $builder, $this->search($builder), $builder->model
        );
    }

    /**
     * Get a lazy collection for the given query mapped onto models.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function cursor(Builder $builder)
    {
        return $this->lazyMap(
            $builder, $this->search($builder), $builder->model
        );
    }
}
