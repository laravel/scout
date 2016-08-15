<?php

namespace Laravel\Scout\Engines;

use Laravel\Scout\Builder;
use AlgoliaSearch\Client as Algolia;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

class AlgoliaEngine extends Engine
{
    /** @var \AlgoliaSearch\Client */
    private $algolia;

    /**
     * Create a new engine instance.
     *
     * @param  \AlgoliaSearch\Client  $algolia
     * @return void
     */
    public function __construct(Algolia $algolia)
    {
        $this->algolia = $algolia;
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function update($models)
    {
        $index = $this->algolia->initIndex($models->first()->searchableAs());

        $index->addObjects($models->map(function ($model) {
            return array_merge(['objectID' => $model->getKey()], $model->toSearchableArray());
        })->values()->all());
    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $index = $this->algolia->initIndex($models->first()->searchableAs());

        $index->deleteObjects(
            $models->map(function ($model) {
                return $model->getKey();
            })->values()->all()
        );
    }

    /**
     * Perform the given search on the engine.
     * Search: https://www.algolia.com/doc/api-client/php/search#search-in-an-index
     * Filters: https://www.algolia.com/doc/api-client/php/parameters#filters
     *
     * @param  Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'filters' => $builder->filters,
            'hitsPerPage' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     * Search: https://www.algolia.com/doc/api-client/php/search#search-in-an-index
     * Filters: https://www.algolia.com/doc/api-client/php/parameters#filters
     *
     * @param  Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'filters' => $builder->filters,
            'hitsPerPage' => $perPage,
            'page' => $page - 1,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        return $this->algolia->initIndex(
            $builder->index ?: $builder->model->searchableAs()
        )->search($builder->query, $options);
    }

    /**
     * Get the filter array for the query.
     *
     * @param  Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function ($value, $key) {
            return $key.'='.$value;
        })->values()->all();
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

        $keys = collect($results['hits'])
                        ->pluck('objectID')->values()->all();

        $models = $model->whereIn(
            $model->getKeyName(), $keys
        )->get()->keyBy($model->getKeyName());

        return collect($results['hits'])->map(function ($hit) use ($model, $models) {
            return $models[$hit[$model->getKeyName()]];
        });
    }

    /**
     * Sets settings to Algolia index.
     * https://www.algolia.com/doc/api-client/php/settings#set-settings
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  mixed  $settings
     * @return void
     */
    public function setSettings($model, $settings)
    {
        $index = $this->algolia->initIndex($model->searchableAs());

        if (isset($settings['synonyms'])) {
            foreach (array_chunk($settings['synonyms'], 100) as $synonyms) {
                $index->batchSynonyms($synonyms, true, true);
            }

            unset($settings['synonyms']);
        }

        $slavesSettings = [];
        if (isset($settings['slaves'])) {
            $slaveNames = [];

            foreach ($settings['slaves'] as $slaveName => $slaveSettings) {
                $slaveNames[] = $slaveName;
                $slavesSettings[$slaveName] = $slaveSettings;
            }

            $settings['slaves'] = $slaveNames;
        }

        $index->setSettings($settings, true);

        if (!empty($slavesSettings)) {
            unset($settings['slaves']);

            foreach ($slavesSettings as $slaveName => $slaveSettings) {
                $slaveSettings = array_merge($settings, $slavesSettings[$slaveName]);
                $this->algolia->initIndex($slaveName)->setSettings($slaveSettings);
            }
        }
    }
}
