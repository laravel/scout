<?php

namespace Laravel\Scout\Engines;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase;

abstract class DatabaseModelEngine extends Engine implements PaginatesEloquentModelsUsingDatabase
{
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
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        $results = $results['results'];

        return count($results) > 0
            ? $results->map(fn ($model) => $model->getScoutKey())->values()
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
     * Start the model query for the given Scout builder.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function newModelQuery(Builder $builder)
    {
        return method_exists($builder->model, 'newScoutQuery')
            ? $builder->model->newScoutQuery($builder)
            : $builder->model->newQuery();
    }

    /**
     * Apply shared search query constraints.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function finalizeSearchQuery(Builder $builder, $query)
    {
        return $this->constrainForSoftDeletes(
            $builder, $this->addAdditionalConstraints($builder, $query->take($builder->limit))
        );
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

        if ($softDeleteWhere && $softDeleteWhere['value'] === 0) {
            return $query->withoutTrashed();
        }

        if ($softDeleteWhere && $softDeleteWhere['value'] === 1) {
            return $query->onlyTrashed();
        }

        if (in_array(SoftDeletes::class, class_uses_recursive(get_class($builder->model))) &&
            config('scout.soft_delete', false)) {
            return $query->withTrashed();
        }

        return $query;
    }

    /**
     * Initialize / build the search query for the given Scout builder.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    abstract protected function buildSearchQuery(Builder $builder);

    /**
     * Add ordering to the search query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    abstract protected function orderSearchQuery(Builder $builder, $query);
}
