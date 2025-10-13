<?php

namespace Laravel\Scout\Engines;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase;
use ReflectionMethod;

class DatabaseEngine extends Engine implements PaginatesEloquentModelsUsingDatabase
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
            ->when($builder->orders, function ($query) use ($builder) {
                foreach ($builder->orders as $order) {
                    $query->orderBy($order['column'], $order['direction']);
                }
            })
            ->when(! $this->getFullTextColumns($builder), function ($query) use ($builder) {
                $query->orderBy($builder->model->getTable().'.'.$builder->model->getScoutKeyName(), 'desc');
            })
            ->when($this->shouldOrderByRelevance($builder), function ($query) use ($builder) {
                $this->orderByRelevance($builder, $query);
            })
            ->get();
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
        return $this->buildSearchQuery($builder)
            ->when($builder->orders, function ($query) use ($builder) {
                foreach ($builder->orders as $order) {
                    $query->orderBy($order['column'], $order['direction']);
                }
            })
            ->when(! $this->getFullTextColumns($builder), function ($query) use ($builder) {
                $query->orderBy($builder->model->getTable().'.'.$builder->model->getScoutKeyName(), 'desc');
            })
            ->when($this->shouldOrderByRelevance($builder), function ($query) use ($builder) {
                $this->orderByRelevance($builder, $query);
            })
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
        return $this->buildSearchQuery($builder)
            ->when($builder->orders, function ($query) use ($builder) {
                foreach ($builder->orders as $order) {
                    $query->orderBy($order['column'], $order['direction']);
                }
            })
            ->when(! $this->getFullTextColumns($builder), function ($query) use ($builder) {
                $query->orderBy($builder->model->getTable().'.'.$builder->model->getScoutKeyName(), 'desc');
            })
            ->when($this->shouldOrderByRelevance($builder), function ($query) use ($builder) {
                $this->orderByRelevance($builder, $query);
            })
            ->simplePaginate($perPage, ['*'], $pageName, $page);
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
            array_keys($builder->model->toSearchableArray()),
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
        $query = method_exists($builder->model, 'newScoutQuery')
            ? $builder->model->newScoutQuery($builder)
            : $builder->model->newQuery();

        if (blank($builder->query)) {
            return $query;
        }

        [$connectionType] = [
            $builder->modelConnectionType(),
        ];

        return $query->where(function ($query) use ($connectionType, $builder, $columns, $prefixColumns, $fullTextColumns) {
            $canSearchPrimaryKey = ctype_digit($builder->query) &&
                                   in_array($builder->model->getKeyType(), ['int', 'integer']) &&
                                   ($connectionType != 'pgsql' || $builder->query <= PHP_INT_MAX) &&
                                   in_array($builder->model->getScoutKeyName(), $columns);

            if ($canSearchPrimaryKey) {
                $query->orWhere($builder->model->getQualifiedKeyName(), $builder->query);
            }

            $likeOperator = $connectionType == 'pgsql' ? 'ilike' : 'like';

            foreach ($columns as $column) {
                if (in_array($column, $fullTextColumns)) {
                    continue;
                } else {
                    if ($canSearchPrimaryKey && $column === $builder->model->getScoutKeyName()) {
                        continue;
                    }

                    $query->orWhere(
                        $builder->model->qualifyColumn($column),
                        $likeOperator,
                        in_array($column, $prefixColumns) ? $builder->query.'%' : '%'.$builder->query.'%',
                    );
                }
            }

            if (count($fullTextColumns) > 0) {
                $query->orWhereFullText(
                    array_map(fn ($column) => $builder->model->qualifyColumn($column), $fullTextColumns),
                    $builder->query,
                    $this->getFullTextOptions($builder)
                );
            }
        });
    }

    /**
     * Determine if the query should be ordered by relevance.
     */
    protected function shouldOrderByRelevance(Builder $builder): bool
    {
        // MySQL orders by relevance by default, so we will only order by relevance on
        // Postgres with no developer-defined orders. If there is developer defined
        // order by clauses we will let those take precedence over the relevance.
        return $builder->modelConnectionType() === 'pgsql' &&
            count($this->getFullTextColumns($builder)) > 0 &&
            empty($builder->orders);
    }

    /**
     * Add an "order by" clause that orders by relevance (Postgres only).
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function orderByRelevance(Builder $builder, $query)
    {
        $fullTextColumns = $this->getFullTextColumns($builder);

        $language = $this->getFullTextOptions($builder)['language'] ?? 'english';

        $vectors = collect($fullTextColumns)->map(function ($column) use ($builder, $language) {
            return sprintf("to_tsvector('%s', %s)", $language, $builder->model->qualifyColumn($column));
        })->implode(' || ');

        return $query->orderByRaw(
            sprintf(
                'ts_rank('.$vectors.', %s(?)) desc',
                match ($this->getFullTextOptions($builder)['mode'] ?? 'plainto_tsquery') {
                    'phrase' => 'phraseto_tsquery',
                    'websearch' => 'websearch_to_tsquery',
                    default => 'plainto_tsquery',
                },
            ),
            [$builder->query]
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
            foreach ($builder->wheres as $key => $value) {
                if ($key !== '__soft_deleted') {
                    $query->where($key, '=', $value);
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
