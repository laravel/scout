<?php

namespace Laravel\Scout\Engines;

use Algolia\AlgoliaSearch\Config\SearchConfig as Algolia3SearchConfig;
use Algolia\AlgoliaSearch\SearchClient as Algolia3SearchClient;
use Illuminate\Support\Arr;
use Laravel\Scout\Builder;
use Laravel\Scout\Jobs\RemoveableScoutCollection;

/**
 * @template TAlgoliaClient of \Algolia\AlgoliaSearch\SearchClient
 */
class Algolia3Engine extends AlgoliaEngine
{
    /**
     * Create a new engine instance.
     *
     * @param  TAlgoliaClient  $algolia
     * @param  bool  $softDelete
     * @return void
     */
    public function __construct(Algolia3SearchClient $algolia, $softDelete = false)
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
        $configuration = Algolia3SearchConfig::create(
            $config['id'], $config['secret'],
        )->setDefaultHeaders($headers);

        if (is_int($connectTimeout = Arr::get($config, 'connect_timeout'))) {
            $configuration->setConnectTimeout($connectTimeout);
        }

        if (is_int($readTimeout = Arr::get($config, 'read_timeout'))) {
            $configuration->setReadTimeout($readTimeout);
        }

        if (is_int($writeTimeout = Arr::get($config, 'write_timeout'))) {
            $configuration->setWriteTimeout($writeTimeout);
        }

        if (is_int($batchSize = Arr::get($config, 'batch_size'))) {
            $configuration->setBatchSize($batchSize);
        }

        return new static(Algolia3SearchClient::createWithConfig($configuration), $softDelete);
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

        $index = $this->algolia->initIndex($models->first()->indexableAs());

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

        $index = $this->algolia->initIndex($models->first()->indexableAs());

        $keys = $models instanceof RemoveableScoutCollection
            ? $models->pluck($models->first()->getScoutKeyName())
            : $models->map->getScoutKey();

        $index->deleteObjects($keys->all());
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
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $index = $this->algolia->initIndex($model->indexableAs());

        $index->clearObjects();
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

    /**
     * Update the index settings for the given index.
     *
     * @return void
     */
    public function updateIndexSettings(string $name, array $settings = [])
    {
        $this->algolia->initIndex($name)->setSettings($settings);
    }
}
