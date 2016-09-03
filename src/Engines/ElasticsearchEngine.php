<?php

namespace Laravel\Scout\Engines;

use Laravel\Scout\Builder;
use Elasticsearch\Client as Elasticsearch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

class ElasticsearchEngine extends Engine
{
    /**
     * @var string $index
     */
    protected $index;

    /**
     * Elasticsearch client instance
     * @var \Elasticsearch\Client
     */
    protected $elasticsearch;

    /**
     * Create a new engine instance.
     *
     * @param  \Elasticsearch\Client  $elasticsearch
     * @return void
     */
    public function __construct(Elasticsearch $elasticsearch, $index)
    {
        $this->elasticsearch = $elasticsearch;

        $this->index = $index;
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function update($models)
    {
        $body = new BaseCollection();

        $models->each(function ($model) use ($body) {
            $body->push([
                'index' => [
                    '_index' => $this->index,
                    '_type' => $model->searchableAs(),
                    '_id' => $model->getKey(),
                ]
            ]);

            $body->push($model->toSearchableArray());
        });

        $this->elasticsearch->bulk([
            'body' => $body->all()
        ]);
    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $body = new BaseCollection();

        $models->each(function ($model) use ($body) {
            $body->push([
                'delete' => [
                    '_index' => $this->index,
                    '_type' => $model->searchableAs(),
                    '_id'  => $model->getKey(),
                ]
            ]);
        });

        $this->elasticsearch->bulk([
            'body' => $body->all()
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $query
     * @return mixed
     */
    public function search(Builder $query)
    {
        return $this->performSearch($query, [
            'filters' => $this->filters($query),
            'size' => $query->limit ?: 10000,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $query
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $query, $perPage, $page)
    {
        $from = (($page * $perPage) - $perPage);

        $result = $this->performSearch($query, [
            'filters' => $this->filters($query),
            'size' => $perPage,
            'from' => $from,
        ]);

        $result['nbPages'] = (int) ceil($result['hits']['total'] / $perPage);

        return $result;
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $query
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $query, array $options = [])
    {
        $searchQuery = [];

        if (array_key_exists('filters', $options) && $options['filters']) {
            foreach ($options['filters'] as $field => $value) {
                $searchQuery[] = [
                    "match" => [
                        $field => $value
                    ],
                ];
            }
        }

        if ($searchQuery) {
            $searchQuery = [
                'bool' => [
                    'must' => $searchQuery
                ]
            ];

        }

        $searchQuery = [
            'index' =>  $this->index,
            'type'  =>  $query->model->searchableAs(),
            'body' => [
                'query' => [
                    'filtered' => [
                        'filter' => [
                            "query" => [
                                "query_string" => [
                                    "query" => "*{$query->query}*",
                                ]
                            ],
                        ],
                        'query' => $searchQuery,
                    ],
                ],
            ],
        ];

        if (array_key_exists('size', $options)) {
            $searchQuery = array_merge($searchQuery, [
                'size' => $options['size'],
            ]);
        }

        if (array_key_exists('from', $options)) {
            $searchQuery = array_merge($searchQuery, [
                'from' => $options['from'],
            ]);
        }

        return $this->elasticsearch->search($searchQuery);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  Builder  $query
     * @return array
     */
    protected function filters(Builder $query)
    {
        return $query->wheres;
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return Collection
     */
    public function map($results, $model)
    {
        if (count($results['hits']) === 0) {
            return Collection::make();
        }

        $keys = collect($results['hits']['hits'])
                    ->pluck('_id')
                    ->values()
                    ->all();

        $models = $model->whereIn(
            $model->getKeyName(), $keys
        )->get()->keyBy($model->getKeyName());


        return collect($results['hits']['hits'])->map(function ($hit) use ($model, $models) {
            return $models[$hit['_source'][$model->getKeyName()]];
        });
    }
}
