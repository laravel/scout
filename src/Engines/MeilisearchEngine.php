<?php

namespace Laravel\Scout\Engines;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Search\SearchResult;

class MeilisearchEngine extends Engine
{
    /**
     * The Meilisearch client.
     *
     * @var MeilisearchClient
     */
    protected $meilisearch;

    /**
     * Determines if soft deletes for Scout are enabled or not.
     *
     * @var bool
     */
    protected $softDelete;

    /**
     * Create a new MeilisearchEngine instance.
     *
     * @param  bool  $softDelete
     * @return void
     */
    public function __construct(MeilisearchClient $meilisearch, $softDelete = false)
    {
        $this->meilisearch = $meilisearch;
        $this->softDelete = $softDelete;
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection  $models
     * @return void
     *
     * @throws ApiException
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $this->meilisearch->index($models->first()->searchableAs());

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
                [$model->getScoutKeyName() => $model->getScoutKey()],
            );
        })->filter()->values()->all();

        if (! empty($objects)) {
            $index->addDocuments($objects, $models->first()->getScoutKeyName());
        }
    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $this->meilisearch->index($models->first()->searchableAs());

        $keys = $models instanceof RemoveableScoutCollection
            ? $models->pluck($models->first()->getScoutKeyName())
            : $models->map->getScoutKey();

        $index->deleteDocuments($keys->values()->all());
    }

    /**
     * Perform the given search on the engine.
     *
     * @return mixed
     */
    public function search(Builder $builder)
    {
        //        dd($this->filters($builder));

        return $this->performSearch($builder, array_filter([
            'filter' => $this->filters($builder),
            'hitsPerPage' => $builder->limit,
            'sort' => $this->buildSortFromOrderByClauses($builder),
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * page/hitsPerPage ensures that the search is exhaustive.
     *
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, array_filter([
            'filter' => $this->filters($builder),
            'hitsPerPage' => (int) $perPage,
            'page' => $page,
            'sort' => $this->buildSortFromOrderByClauses($builder),
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $searchParams = [])
    {
        $meilisearch = $this->meilisearch->index($builder->index ?: $builder->model->searchableAs());

        $searchParams = array_merge($builder->options, $searchParams);

        if (array_key_exists('attributesToRetrieve', $searchParams)) {
            $searchParams['attributesToRetrieve'] = array_merge(
                [$builder->model->getScoutKeyName()],
                $searchParams['attributesToRetrieve'],
            );
        }

        if ($builder->callback) {
            $result = call_user_func(
                $builder->callback,
                $meilisearch,
                $builder->query,
                $searchParams
            );

            $searchResultClass = class_exists(SearchResult::class)
                ? SearchResult::class
                : SearchResult;

            return $result instanceof $searchResultClass ? $result->getRaw() : $result;
        }

        return $meilisearch->rawSearch($builder->query, $searchParams);
    }

    /**
     * @param  mixed  $value
     * @return string
     */
    protected function formatFilterValues($value)
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (filter_var($value, FILTER_VALIDATE_INT))
            ? sprintf('%s', $value)
            : sprintf('"%s"', $value);
    }

    /**
     * @param $column string
     * @param $value mixed
     * @param $operator null|string
     * @return string
     */
    protected function parseFilterExpressions($column, $value, $operator = null)
    {

        if ($operator === 'Exists') {
            return sprintf('%s EXISTS', $column);
        }

        if (in_array($operator, ['Null', 'NotNull'])) {
            return sprintf('%s %s',
                $column,
                $operator === 'Null' ? 'IS NULL' : 'IS NOT NULL'
            );
        }

        // Note: Meilisearch does not treat null values as empty. To match null fields, use the IS NULL operator.
        if (in_array($operator, ['IsEmpty', 'IsNotEmpty'])) {
            return sprintf('%s %s',
                $column,
                $operator === 'IsEmpty' ? 'IS EMPTY' : 'IS NOT EMPTY'
            );
        }

        if (is_array($value)) {

            // Meilisearch uses "TO" operator as equivalent to >= AND <=
            if ($operator === 'between') {
                return sprintf('%s %s TO %s',
                    $column,
                    $this->formatFilterValues($value[0]),
                    $this->formatFilterValues($value[1]),
                );
            }

            // Where IN/NOT IN
            if (in_array($operator, ['In', 'NotIn'])) {

                return sprintf('%s %s [%s]',
                    $column,
                    $operator === 'In' ? 'IN' : 'NOT IN',
                    implode(', ', collect($value)->map(fn ($v) => $this->formatFilterValues($v))->toArray())
                );
            }
        }

        if (empty($operator)) {
            $operator = '=';
        }

        return sprintf('%s%s%s', $column, $operator, $this->formatFilterValues($value));
    }

    /**
     * Get the filter expression to be used with the query
     *
     * @return string
     */
    protected function filters(Builder $builder)
    {

        // Transition check, original version
        if (! method_exists($builder, 'isNewSearchEngineAcive') || ! $builder->isNewSearchEngineAcive()) {

            $filters = collect($builder->wheres)->map(function ($value, $key) {
                if (is_bool($value)) {
                    return sprintf('%s=%s', $key, $value ? 'true' : 'false');
                }

                return is_numeric($value)
                    ? sprintf('%s=%s', $key, $value)
                    : sprintf('%s="%s"', $key, $value);
            });

            $whereInOperators = [
                'whereIns' => 'IN',
                'whereNotIns' => 'NOT IN',
            ];

            foreach ($whereInOperators as $property => $operator) {
                if (property_exists($builder, $property)) {
                    foreach ($builder->{$property} as $key => $values) {
                        $filters->push(sprintf('%s %s [%s]', $key, $operator, collect($values)->map(function ($value) {
                            if (is_bool($value)) {
                                return sprintf('%s', $value ? 'true' : 'false');
                            }

                            return filter_var($value, FILTER_VALIDATE_INT) !== false
                                ? sprintf('%s', $value)
                                : sprintf('"%s"', $value);
                        })->values()->implode(', ')));
                    }
                }
            }

            return $filters->values()->implode(' AND ');

        }

        // New rewritten version
        if (! is_array($builder->wheres) || empty($builder->wheres)) {
            return '';
        }

        $stack = [];

        foreach ($builder->wheres as $expression) {

            if (! empty($stack)) {
                $stack[] = strtoupper($expression['boolean']);
            }

            $type = $expression['type'];

            // Nested "( Expression )"
            if ($type === 'Nested' && array_key_exists('query', $expression)) {

                // Recursive nested expression
                $stack[] = '('.$this->filters($expression['query']).')';

            } else {

                // With NotNull/Null expressions we're only need column name
                $value = $expression['value'] ?? $expression['values'] ?? null;
                $column = $expression['column'];

                if ($type === 'Basic' && array_key_exists('operator', $expression)) {

                    // Only with a Basic expression is where we need to use an operator
                    $operator = $expression['operator'];
                    $stack[] = $this->parseFilterExpressions($column, $value, $operator);

                } else {

                    // I'm using "between" in lowercase to be consistent with \Illuminate\Database\Query\Builder
                    if ($type === 'between') {

                        $stack[] = $this->parseFilterExpressions($column, $value, $type);

                    } elseif ($type === 'Exists') {

                        $stack[] = $this->parseFilterExpressions($column, $value, $type);

                    } elseif (in_array($type, ['IsEmpty', 'IsNotEmpty'])) {

                        $stack[] = $this->parseFilterExpressions($column, $value, $type);

                    } elseif (in_array($type, ['In', 'NotIn'])) {

                        $stack[] = $this->parseFilterExpressions($column, $value, $type);

                    } elseif (in_array($type, ['Null', 'NotNull'])) {

                        $stack[] = $this->parseFilterExpressions($column, null, $type);

                    } else {

                        throw new InvalidArgumentException("{$type} expression not supported");
                    }

                }

            }

        }

        return implode(' ', $stack);

    }

    /**
     * Get the sort array for the query.
     */
    protected function buildSortFromOrderByClauses(Builder $builder): array
    {
        return collect($builder->orders)->map(function (array $order) {
            return $order['column'].':'.$order['direction'];
        })->toArray();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * This expects the first item of each search item array to be the primary key.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        if (0 === count($results['hits'])) {
            return collect();
        }

        $hits = collect($results['hits']);

        $key = key($hits->first());

        return $hits->pluck($key)->values();
    }

    /**
     * Pluck the given results with the given primary key name.
     *
     * @param  mixed  $results
     * @param  string  $key
     * @return \Illuminate\Support\Collection
     */
    public function mapIdsFrom($results, $key)
    {
        return count($results['hits']) === 0
            ? collect()
            : collect($results['hits'])->pluck($key)->values();
    }

    /**
     * Get the results of the query as a Collection of primary keys.
     *
     * @return \Illuminate\Support\Collection
     */
    public function keys(Builder $builder)
    {
        $scoutKey = $builder->model->getScoutKeyName();

        return $this->mapIdsFrom($this->search($builder), $scoutKey);
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  Model  $model
     * @return Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (is_null($results) || 0 === count($results['hits'])) {
            return $model->newCollection();
        }

        $objectIds = collect($results['hits'])->pluck($model->getScoutKeyName())->values()->all();

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
     * @param  mixed  $results
     * @param  Model  $model
     * @return LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        if (count($results['hits']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = collect($results['hits'])->pluck($model->getScoutKeyName())->values()->all();
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
        return $results['totalHits'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  Model  $model
     * @return void
     */
    public function flush($model)
    {
        $index = $this->meilisearch->index($model->searchableAs());

        $index->deleteAllDocuments();
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws ApiException
     */
    public function createIndex($name, array $options = [])
    {
        return $this->meilisearch->createIndex($name, $options);
    }

    /**
     * Update an index's settings.
     *
     * @param  string  $name
     * @return array
     *
     * @throws ApiException
     */
    public function updateIndexSettings($name, array $options = [])
    {
        return $this->meilisearch->index($name)->updateSettings($options);
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws ApiException
     */
    public function deleteIndex($name)
    {
        return $this->meilisearch->deleteIndex($name);
    }

    /**
     * Delete all search indexes.
     *
     * @return mixed
     */
    public function deleteAllIndexes()
    {
        $tasks = [];
        $limit = 1000000;

        $query = new IndexesQuery();
        $query->setLimit($limit);

        $indexes = $this->meilisearch->getIndexes($query);

        foreach ($indexes->getResults() as $index) {
            $tasks[] = $index->delete();
        }

        return $tasks;
    }

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param  Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }

    /**
     * Dynamically call the Meilisearch client instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->meilisearch->$method(...$parameters);
    }
}
