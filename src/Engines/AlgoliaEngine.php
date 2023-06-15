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
            ? $models->pluck($models->first()->getScoutKeyName())
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
            //            'numericFilters' => $this->numericFilters($builder),   //filters are more powerful and suggested way, can this be removed?
            'filters' => $this->queryFilters($builder),
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
            //            'numericFilters' => $this->numericFilters($builder),   //filters are more powerful and suggested way, can this be removed?
            'filters' => $this->queryFilters($builder),
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

    //filters are more powerful and suggested way, can this be removed?
    /**
     * Get the numeric filters array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function numericFilters(Builder $builder)
    {
        $wheres = collect($builder->wheres)->map(function ($value, $key) {
            return $key.'='.$value;
        })->values();

        return $wheres->merge(collect($builder->whereIns)->map(function ($values, $key) {
            if (empty($values)) {
                return '0=1';
            }

            return collect($values)->map(function ($value) use ($key) {
                return $key.'='.$value;
            })->all();
        })->values())->values()->all();
    }

    /**
     * Get the filters array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return string
     */
    protected function queryFilters(Builder $builder)
    {
        $filtersQuery = '';
        $firstItem = true;

        foreach ($builder->wheres as $field => $value) {
            if ($firstItem) {
                $firstItem = false;
            } else {
                $filtersQuery .= ' AND ';
            }

            $filtersQuery .= $this->prepareValueForFilterString($field, $value);
        }

        if (count($builder->whereIns) > 0) {
            if ($firstItem) {
                $firstItem = false;
            } else {
                $filtersQuery .= ' AND ';
            }

            $filtersQuery .= '('.collect($builder->whereIns)->map(fn ($values, $key) => empty($values) ? '0=1' : $this->concatenateValuesForFilterString($key, $values))->join(' AND ').')';
        }

        foreach ($builder->advancedWheres as $whereData) {
            $filtersQuery .= $this->prepareFilterFromAdvancedWhereRule($whereData, ! $firstItem);

            if ($firstItem) {
                $firstItem = false;
            }
        }

        return $filtersQuery;
    }

    /**
     * Prepare the query string for a single advanced where rule.
     *
     * @param  array  $whereData
     * @param  bool  $prependBooleanOperator
     * @return string
     */
    protected function prepareFilterFromAdvancedWhereRule(array $whereData, bool $prependBooleanOperator = false)
    {
        $prependString = $prependBooleanOperator ? (' '.$whereData['boolean'].($whereData['not'] ? ' NOT' : '').' ') : '';

        switch($whereData['type']) {
            case 'In':
                return $prependString.'('.$this->concatenateValuesForFilterString($whereData['field'], $whereData['values']).')';

            case 'Between':
                if (count($whereData['values']) > 1) {
                    return $prependString."{$whereData['field']}: ".
                           (is_numeric($whereData['values'][0]) ?
                            "{$whereData['values'][0]} TO {$whereData['values'][1]}" :
                            "\"{$whereData['values'][0]}\" TO \"{$whereData['values'][1]}\"");
                }

            case 'Nested':
                if (count($whereData['builder']->wheres) > 0 || count($whereData['builder']->advancedWheres) > 0) {
                    $firstItem = true;
                    $subQuery = '';
                    foreach ($whereData['builder']->wheres as $field => $value) {
                        $subQuery .= (! $firstItem ? ' AND ' : '').$this->prepareValueForFilterString($field, $value);

                        if ($firstItem) {
                            $firstItem = false;
                        }
                    }
                    foreach ($whereData['builder']->advancedWheres as $subWhereData) {
                        $subQuery .= $this->prepareFilterFromAdvancedWhereRule($subWhereData, ! $firstItem);

                        if ($firstItem) {
                            $firstItem = false;
                        }
                    }

                    return $prependString.'('.$subQuery.')';
                }
                break;

            case 'Basic':
            default:
                if (! isset($whereData['operator']) || $whereData['operator'] == '=' || $whereData['operator'] == 'eq') {
                    $whereData['operator'] = ':';
                }

                return $prependString.$this->prepareValueForFilterString($whereData['field'], $whereData['value'], $whereData['operator']);
        }

        return '';
    }

    /**
     * Concatenate an array of values in a single query string for a field.
     *
     * @param  string  $field
     * @param  array  $values
     * @param  string  $operator
     * @return string
     */
    protected function concatenateValuesForFilterString($field, $values, $operator = 'OR')
    {
        return collect($values)
            ->map(fn ($value, $key) => $this->prepareValueForFilterString($field, $value))
            ->join(" {$operator} ");
    }

    /**
     * Prepare the basic query string for a single value.
     *
     * @param  string  $field
     * @param  mixed  $value
     * @param  string  $operator
     * @return string
     */
    protected function prepareValueForFilterString($field, $value, $operator = ':')
    {
        return $field.$operator.(is_bool($value) ? ($value ? 'true' : 'false') : (is_numeric($value) ? $value : '"'.$value.'"'));
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
