<?php

namespace Laravel\Scout\Engines;

use Elastic\Elasticsearch\Client as ElasticsearchClient;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use stdClass;

class ElasticsearchEngine extends Engine implements UpdatesIndexSettings
{
    /**
     * The Elasticsearch client.
     *
     * @var \Elastic\Elasticsearch\Client
     */
    protected $elasticsearch;

    /**
     * Determines if soft deletes for Scout are enabled or not.
     *
     * @var bool
     */
    protected $softDelete;

    /**
     * Create a new Elasticsearch engine instance.
     *
     * @param  \Elastic\Elasticsearch\Client  $elasticsearch
     * @param  bool  $softDelete
     * @return void
     */
    public function __construct(ElasticsearchClient $elasticsearch, $softDelete = false)
    {
        $this->elasticsearch = $elasticsearch;
        $this->softDelete = $softDelete;
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        if ($this->usesSoftDelete($models->first()) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $params = ['body' => []];

        $models->each(function ($model) use (&$params) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return;
            }

            $params['body'][] = [
                'index' => [
                    '_index' => $model->indexableAs(),
                    '_id' => $model->getScoutKey(),
                ],
            ];

            $params['body'][] = array_merge(
                $searchableData,
                $model->scoutMetadata(),
                [$model->getScoutKeyName() => $model->getScoutKey()],
            );
        });

        if (! empty($params['body'])) {
            $this->elasticsearch->bulk($params);
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

        $params = ['body' => []];

        $models->each(function ($model) use (&$params) {
            $params['body'][] = [
                'delete' => [
                    '_index' => $model->indexableAs(),
                    '_id' => $model->getScoutKey(),
                ],
            ];
        });

        $this->elasticsearch->bulk($params);
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
            'size' => $builder->limit,
            'body' => $this->buildSearchBody($builder),
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
            'from' => ($page - 1) * $perPage,
            'size' => (int) $perPage,
            'body' => $this->buildSearchBody($builder),
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $searchParams
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $searchParams = [])
    {
        $searchParams = array_merge($builder->options, $searchParams);

        $searchParams['index'] = $builder->index ?: $builder->model->searchableAs();

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->elasticsearch,
                $builder->query,
                $searchParams
            );
        }

        return $this->elasticsearch->search($searchParams);
    }

    /**
     * Build the search body for the given query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function buildSearchBody(Builder $builder)
    {
        $body = ['query' => $this->buildQuery($builder)];

        if (! empty($builder->orders)) {
            $body['sort'] = $this->buildSort($builder);
        }

        return $body;
    }

    /**
     * Build the Elasticsearch query for the given search.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function buildQuery(Builder $builder)
    {
        $bool = [];

        if ($builder->query !== '' && $builder->query !== '*') {
            $bool['must'][] = [
                'multi_match' => [
                    'query' => $builder->query,
                    'type' => 'bool_prefix',
                    'fields' => ['*'],
                ],
            ];
        }

        $filters = collect($builder->wheres)->map(function ($where) {
            return $this->buildFilter($where['field'], $where['operator'], $where['value']);
        });

        foreach ($builder->whereIns as $field => $values) {
            $filters->push(['terms' => [$field => array_values($values)]]);
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $filters->push(['bool' => ['must_not' => ['terms' => [$field => array_values($values)]]]]);
        }

        if ($filters->isNotEmpty()) {
            $bool['filter'] = $filters->values()->all();
        }

        return empty($bool) ? ['match_all' => new stdClass] : ['bool' => $bool];
    }

    /**
     * Build an Elasticsearch filter clause for the given field, operator, and value.
     *
     * @param  string  $field
     * @param  string  $operator
     * @param  mixed  $value
     * @return array
     */
    protected function buildFilter($field, $operator, $value)
    {
        if (is_null($value)) {
            return $operator === '!='
                ? ['exists' => ['field' => $field]]
                : ['bool' => ['must_not' => ['exists' => ['field' => $field]]]];
        }

        return match ($operator) {
            '!=' => ['bool' => ['must_not' => ['term' => [$field => $value]]]],
            '>' => ['range' => [$field => ['gt' => $value]]],
            '>=' => ['range' => [$field => ['gte' => $value]]],
            '<' => ['range' => [$field => ['lt' => $value]]],
            '<=' => ['range' => [$field => ['lte' => $value]]],
            default => ['term' => [$field => $value]],
        };
    }

    /**
     * Build the sort clauses for the given search.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function buildSort(Builder $builder)
    {
        return collect($builder->orders)
            ->map(fn (array $order) => [$order['column'] => $order['direction']])
            ->values()
            ->all();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits']['hits'])->pluck('_id')->values();
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
        return collect($results['hits']['hits'])->pluck('_source.'.$key)->values();
    }

    /**
     * Get the results of the query as a Collection of primary keys.
     *
     * @param  \Laravel\Scout\Builder  $builder
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
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (count($results['hits']['hits']) === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results['hits']['hits'])
            ->pluck('_id')
            ->values()
            ->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(fn ($model) => in_array($model->getScoutKey(), $objectIds))
            ->map(function ($model) use ($results, $objectIdPositions) {
                $result = $results['hits']['hits'][$objectIdPositions[$model->getScoutKey()]] ?? [];

                foreach ($result['_source'] ?? [] as $key => $value) {
                    if (str_starts_with($key, '_')) {
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
        if (count($results['hits']['hits']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = collect($results['hits']['hits'])
            ->pluck('_id')
            ->values()
            ->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(fn ($model) => in_array($model->getScoutKey(), $objectIds))
            ->map(function ($model) use ($results, $objectIdPositions) {
                $result = $results['hits']['hits'][$objectIdPositions[$model->getScoutKey()]] ?? [];

                foreach ($result['_source'] ?? [] as $key => $value) {
                    if (str_starts_with($key, '_')) {
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
        return $results['hits']['total']['value'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $this->elasticsearch->deleteByQuery([
            'index' => $model->indexableAs(),
            'body' => [
                'query' => ['match_all' => new stdClass],
            ],
        ]);
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @param  array  $options
     * @return void
     */
    public function createIndex($name, array $options = [])
    {
        $this->elasticsearch->indices()->create(array_merge([
            'index' => $name,
        ], $options));
    }

    /**
     * Update the index settings for the given index.
     *
     * @param  string  $name
     * @param  array  $settings
     * @return void
     */
    public function updateIndexSettings($name, array $settings = [])
    {
        if (! empty($settings['settings'])) {
            $this->elasticsearch->indices()->putSettings([
                'index' => $name,
                'body' => $settings['settings'],
            ]);
        }

        if (! empty($settings['mappings'])) {
            $this->elasticsearch->indices()->putMapping([
                'index' => $name,
                'body' => $settings['mappings'],
            ]);
        }
    }

    /**
     * Configure the soft delete filter within the given settings.
     *
     * @param  array  $settings
     * @return array
     */
    public function configureSoftDeleteFilter(array $settings = [])
    {
        $settings['mappings']['properties']['__soft_deleted'] = [
            'type' => 'integer',
        ];

        return $settings;
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return void
     */
    public function deleteIndex($name)
    {
        $this->elasticsearch->indices()->delete(['index' => $name]);
    }

    /**
     * Delete all search indexes.
     *
     * @return void
     */
    public function deleteAllIndexes()
    {
        $prefix = Config::get('scout.prefix');

        try {
            $indexes = collect($this->elasticsearch->indices()->get(['index' => '*'])->asArray());
        } catch (ClientResponseException) {
            return;
        }

        $indexes->keys()
            ->filter(fn ($name) => str_starts_with($name, $prefix))
            ->reject(fn ($name) => str_starts_with($name, '.'))
            ->each(fn ($name) => $this->elasticsearch->indices()->delete(['index' => $name]));
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
     * Dynamically call the Elasticsearch client instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->elasticsearch->$method(...$parameters);
    }
}
