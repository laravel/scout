<?php

namespace Laravel\Scout\Engines;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\PaginatesEloquentModels;
use ReflectionMethod;

class DatabaseEngine extends Engine implements PaginatesEloquentModels
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
            'results' => $models,
            'total' => $models->count(),
        ];
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
        return $this->buildSearchQuery($builder)
                ->when(! $this->getFullTextColumns($builder), function ($query) use ($builder) {
                    $query->orderBy($builder->model->getKeyName(), 'desc');
                })
                ->paginate($perPage, ['*'], 'page', $page);
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
        return $this->buildSearchQuery($builder)
                ->when(! $this->getFullTextColumns($builder), function ($query) use ($builder) {
                    $query->orderBy($builder->model->getKeyName(), 'desc');
                })
                ->simplePaginate($perPage, ['*'], 'page', $page);
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
        return $this->buildSearchQuery($builder)
            ->when(! is_null($page) && ! is_null($perPage), function ($query) use ($page, $perPage) {
                $query->forPage($page, $perPage);
            })
            ->when(! $this->getFullTextColumns($builder), function ($query) use ($builder) {
                $query->orderBy($builder->model->getKeyName(), 'desc');
            })
            ->get();
    }

    /**
     * Initialize / build the search query for the given Scout builder.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildSearchQuery(Builder $builder)
    {
        $query = $this->initializeSearchQuery(
            $builder,
            $columns = array_keys($builder->model->toSearchableArray()),
            $this->getPrefixColumns($builder),
            $this->getFullTextColumns($builder)
        );

        return $this->constrainForSoftDeletes(
            $builder, $this->addAdditionalConstraints($builder, $query->take($builder->limit))
        );
    }

    /**
     * Build the initial text search database query for all relevant columns.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $columns
     * @param  array  $prefixColumns
     * @param  array  $fullTextColumns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function initializeSearchQuery(Builder $builder, array $columns, array $prefixColumns = [], array $fullTextColumns = [])
    {
        if (blank($builder->query)) {
            return $builder->model->query();
        }

        return $builder->model->query()->where(function ($query) use ($builder, $columns, $prefixColumns, $fullTextColumns) {
            $connectionType = $builder->model->getConnection()->getDriverName();

            $canSearchPrimaryKey = ctype_digit($builder->query) &&
                                   in_array($builder->model->getKeyType(), ['int', 'integer']) &&
                                   ($connectionType != 'pgsql' || $builder->query <= PHP_INT_MAX) &&
                                   in_array($builder->model->getKeyName(), $columns);

            if ($canSearchPrimaryKey) {
                $query->orWhere($builder->model->getQualifiedKeyName(), $builder->query);
            }

            $likeOperator = $connectionType == 'pgsql' ? 'ilike' : 'like';

            foreach ($columns as $column) {
                if (in_array($column, $fullTextColumns)) {
                    $query->orWhereFullText(
                        $builder->model->qualifyColumn($column),
                        $builder->query,
                        $this->getFullTextOptions($builder)
                    );
                } else {
                    if ($canSearchPrimaryKey && $column === $builder->model->getKeyName()) {
                        continue;
                    }

                    $query->orWhere(
                        $builder->model->qualifyColumn($column),
                        $likeOperator,
                        in_array($column, $prefixColumns) ? $builder->query.'%' : '%'.$builder->query.'%',
                    );
                }
            }
        });
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
            foreach ($builder->wheres as $key => $value) {
                if ($key !== '__soft_deleted') {
                    $query->where($key, '=', $value);
                }
            }
        })->when(! $builder->callback && count($builder->whereIns) > 0, function ($query) use ($builder) {
            foreach ($builder->whereIns as $key => $values) {
                $query->whereIn($key, $values);
            }
        })->when(! is_null($builder->queryCallback), function ($query) use ($builder) {
            call_user_func($builder->queryCallback, $query);
        });
    }

    /**
     * Ensure that soft delete constraints are properly applied to the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return \Illuminate\Database\Query\Builder
     */
    protected function constrainForSoftDeletes($builder, $query)
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
     * Get the full-text columns for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function getFullTextColumns(Builder $builder)
    {
        return $this->getAttributeColumns($builder, SearchUsingFullText::class);
    }

    /**
     * Get the prefix search columns for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function getPrefixColumns(Builder $builder)
    {
        return $this->getAttributeColumns($builder, SearchUsingPrefix::class);
    }

    /**
     * Get the columns marked with a given attribute.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  string  $attributeClass
     * @return array
     */
    protected function getAttributeColumns(Builder $builder, $attributeClass)
    {
        $columns = [];

        if (PHP_MAJOR_VERSION < 8) {
            return [];
        }

        foreach ((new ReflectionMethod($builder->model, 'toSearchableArray'))->getAttributes() as $attribute) {
            if ($attribute->getName() !== $attributeClass) {
                continue;
            }

            $columns = array_merge($columns, Arr::wrap($attribute->getArguments()[0]));
        }

        return $columns;
    }

    /**
     * Get the full-text search options for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function getFullTextOptions(Builder $builder)
    {
        $options = [];

        if (PHP_MAJOR_VERSION < 8) {
            return [];
        }

        foreach ((new ReflectionMethod($builder->model, 'toSearchableArray'))->getAttributes(SearchUsingFullText::class) as $attribute) {
            $arguments = $attribute->getArguments()[1] ?? [];

            $options = array_merge($options, Arr::wrap($arguments));
        }

        return $options;
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
                    ? collect($results->modelKeys())
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
}
