<?php

namespace Laravel\Scout\Engines;

use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Meilisearch;
use Meilisearch\Search\SearchResult;

class MeilisearchEngine extends Engine implements UpdatesIndexSettings
{
    /**
     * The Meilisearch client.
     *
     * @var \Meilisearch\Client
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
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     *
     * @throws \Meilisearch\Exceptions\ApiException
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $this->meilisearch->index($models->first()->indexableAs());

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
        })
            ->filter()
            ->values()
            ->all();

        if (! empty($objects)) {
            $index->addDocuments($objects, $models->first()->getScoutKeyName());
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

        $index = $this->meilisearch->index($models->first()->indexableAs());

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
                : \Meilisearch\Search\SearchResult;

            return $result instanceof $searchResultClass ? $result->getRaw() : $result;
        }

        return $meilisearch->rawSearch($builder->query, $searchParams);
    }

    /**
     * Get the filter array for the query.
     *
     * @return string
     */
    protected function filters(Builder $builder)
    {
        $filters = collect($builder->wheres)
            ->map(function ($value, $key) {
                if (is_bool($value)) {
                    return sprintf('%s=%s', $key, $value ? 'true' : 'false');
                }

                if (is_null($value)) {
                    return sprintf('%s %s', $key, 'IS NULL');
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

    /**
     * Get the sort array for the query.
     */
    protected function buildSortFromOrderByClauses(Builder $builder): array
    {
        return collect($builder->orders)
            ->map(fn (array $order) => $order['column'].':'.$order['direction'])
            ->toArray();
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
        if (count($results['hits']) === 0) {
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
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (is_null($results) || count($results['hits']) === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results['hits'])->pluck($model->getScoutKeyName())->values()->all();

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
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        if (count($results['hits']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = collect($results['hits'])->pluck($model->getScoutKeyName())->values()->all();
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
        return $results['totalHits'] ?? $results['estimatedTotalHits'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $index = $this->meilisearch->index($model->indexableAs());

        $index->deleteAllDocuments();
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \Meilisearch\Exceptions\ApiException
     */
    public function createIndex($name, array $options = [])
    {
        try {
            $index = $this->meilisearch->getIndex($name);
        } catch (ApiException $e) {
            $index = null;
        }

        if ($index?->getUid() !== null) {
            return $index;
        }

        return $this->meilisearch->createIndex($name, $options);
    }

    /**
     * Update the index settings for the given index.
     *
     * @return void
     */
    public function updateIndexSettings($name, array $settings = [])
    {
        $index = $this->meilisearch->index($name);

        $index->updateSettings(Arr::except($settings, 'embedders'));

        if (! empty($settings['embedders'])) {
            $index->updateEmbedders($settings['embedders']);
        }
    }

    /**
     * Configure the soft delete filter within the given settings.
     *
     * @return array
     */
    public function configureSoftDeleteFilter(array $settings = [])
    {
        $settings['filterableAttributes'][] = '__soft_deleted';

        return $settings;
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \Meilisearch\Exceptions\ApiException
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

        $query = new IndexesQuery;
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
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model));
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
