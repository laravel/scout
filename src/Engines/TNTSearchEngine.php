<?php

namespace Laravel\Scout\Engines;

use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;
use TeamTNT\TNTSearch\TNTSearch;

class TNTSearchEngine extends Engine
{
    /**
     * Create a new engine instance.
     *
     * @param  TeamTNT\TNTSearch\TNTSearch $tnt
     * @return void
     */
    public function __construct(TNTSearch $tnt)
    {
        $this->tnt = $tnt;
        $this->tnt->loadConfig(config('scout.tntsearch'));
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function update($models)
    {
        $this->initIndex($models->first()->searchableAs());
        $models->each(function ($model) {
            $this->tnt->selectIndex("{$model->searchableAs()}.index");
            $index = $this->tnt->getIndex();
            if ($model->id) {
                $index->update($model->id, $model->toSearchableArray());
            } else {
                $index->insert($model->toSearchableArray());
            }
        });
    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $this->initIndex($models->first()->searchableAs());

        $models->each(function ($model) {
            $this->tnt->selectIndex("{$model->searchableAs()}.index");
            $index = $this->tnt->getIndex();
            $index->delete($model->id);
        });
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder);
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
        $index = $builder->index ?: $builder->model->searchableAs();
        $limit = $builder->limit ?: 10;

        $this->tnt->selectIndex("{$index}.index");
        return $this->tnt->search($builder->query, $limit);
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
            return $key . '=' . $value;
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
        if (count($results['ids']) === 0) {
            return Collection::make();
        }

        $keys = collect($results['ids']);

        $models = $model->whereIn(
            $model->getKeyName(), $keys
        )->get()->keyBy($model->getKeyName());

        return collect($results['ids'])->map(function ($hit) use ($models) {
            return $models[$hit];
        });
    }

    public function initIndex($indexName)
    {
        if (!file_exists(config('scout.tntsearch.storage') . "/{$indexName}.index")) {
            $indexer                = $this->tnt->createIndex("$indexName.index");
            $indexer->disableOutput = true;
            $indexer->query("SELECT * FROM $indexName");
            $indexer->run();
        }
    }
}
