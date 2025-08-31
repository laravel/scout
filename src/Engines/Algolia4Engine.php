<?php

namespace Laravel\Scout\Engines;

use Algolia\AlgoliaSearch\Api\SearchClient as Algolia4SearchClient;
use Algolia\AlgoliaSearch\Configuration\SearchConfig as Algolia4SearchConfig;
use Illuminate\Support\Arr;
use Laravel\Scout\Builder;
use Laravel\Scout\Jobs\RemoveableScoutCollection;

/**
 * @template TAlgoliaClient of \Algolia\AlgoliaSearch\Api\SearchClient
 */
class Algolia4Engine extends AlgoliaEngine
{
    /**
     * Create a new engine instance.
     *
     * @param  TAlgoliaClient  $algolia
     * @param  bool  $softDelete
     * @return void
     */
    public function __construct(Algolia4SearchClient $algolia, $softDelete = false)
    {
        parent::__construct($algolia, $softDelete);
    }

    /**
     * Make a new engine instance.
     *
     * @param  array  $config
     * @param  array  $headers
     * @param  bool  $softDelete
     * @return static
     */
    public static function make(array $config, array $headers, bool $softDelete = false)
    {
        $configuration = (new Algolia4SearchConfig(array_merge([
            'appId' => $config['id'],
            'apiKey' => $config['secret'],
        ]), array_filter([
            'batchSize' => transform(Arr::get($config, 'batch_size'), fn ($batchSize) => is_int($batchSize) ? $batchSize : null),
        ])))->setDefaultHeaders($headers);

        if (is_int($connectTimeout = Arr::get($config, 'connect_timeout'))) {
            $configuration->setConnectTimeout($connectTimeout);
        }

        if (is_int($readTimeout = Arr::get($config, 'read_timeout'))) {
            $configuration->setReadTimeout($readTimeout);
        }

        if (is_int($writeTimeout = Arr::get($config, 'write_timeout'))) {
            $configuration->setWriteTimeout($writeTimeout);
        }

        return new static(Algolia4SearchClient::createWithConfig($configuration), $softDelete);
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

        $index = $models->first()->indexableAs();

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
        })
            ->filter()
            ->values()
            ->all();

        if (! empty($objects)) {
            $this->algolia->saveObjects($index, $objects);
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

        $keys = $models instanceof RemoveableScoutCollection
            ? $models->pluck($models->first()->getScoutKeyName())
            : $models->map->getScoutKey();

        $this->algolia->deleteObjects($models->first()->indexableAs(), $keys->all());
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    public function deleteIndex($name)
    {
        return $this->algolia->deleteIndex($name);
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $this->algolia->clearObjects($model->indexableAs());
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
        $options = array_merge($builder->options, $options);

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->algolia,
                $builder->query,
                $options
            );
        }

        $queryParams = array_merge(['query' => $builder->query], $options);

        return $this->algolia->searchSingleIndex(
            $builder->index ?: $builder->model->searchableAs(),
            $queryParams
        );
    }

    /**
     * Update the index settings for the given index.
     *
     * @return void
     */
    public function updateIndexSettings(string $name, array $settings = [])
    {
        $this->algolia->setSettings($name, $settings);
    }
}
