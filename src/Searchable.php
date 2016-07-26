<?php

namespace Laravel\Scout;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

trait Searchable
{
    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootSearchable()
    {
        $self = new static;

        static::addGlobalScope(new SearchableScope);

        static::observe(new ModelObserver);

        BaseCollection::macro('searchable', function () use ($self) {
            $self->queueMakeSearchable($this);
        });

        BaseCollection::macro('unsearchable', function () use ($self) {
            $self->queueRemoveFromSearch($this);
        });
    }

    /**
     * Dispatch the job to make the given models searchable.
     *
     * @param  Collection  $models
     * @return void
     */
    public function queueMakeSearchable($models)
    {
        if (! config('scout.queue')) {
            return $models->first()->searchableUsing()->update($models);
        }

        dispatch((new Jobs\MakeSearchable($models))
                ->onConnection($models->first()->syncWithSearchUsing()));
    }

    /**
     * Dispatch the job to make the given models unsearchable.
     *
     * @param  Collection  $models
     * @return void
     */
    public function queueRemoveFromSearch($models)
    {
        return $models->first()->searchableUsing()->delete($models);
    }

    /**
     * Perform a search against the model's indexed data.
     *
     * @param  string  $query
     * @return \Illuminate\Support\Collection
     */
    public static function search($query)
    {
        return new Builder(new static, $query);
    }

    /**
     * Make all instances of the model searchable.
     *
     * @return void
     */
    public static function makeAllSearchable()
    {
        (new static)->newQuery()->searchable();
    }

    /**
     * Make the given model instance searchable.
     *
     * @return void
     */
    public function searchable()
    {
        Collection::make([$this])->searchable();
    }

    /**
     * Remove all instances of the model from the search index.
     *
     * @return void
     */
    public static function removeAllFromSearch()
    {
        (new static)->newQuery()->unsearchable();
    }

    /**
     * Remove the given model instance from the search index.
     *
     * @return void
     */
    public function unsearchable()
    {
        Collection::make([$this])->unsearchable();
    }

    /**
     * Enable search syncing for this model.
     *
     * @return void
     */
    public static function enableSearchSyncing()
    {
        ModelObserver::enableSyncingFor(get_called_class());
    }

    /**
     * Disable search syncing for this model.
     *
     * @return void
     */
    public static function disableSearchSyncing()
    {
        ModelObserver::disableSyncingFor(get_called_class());
    }

    /**
     * Temporarily disable search syncing for the given callback.
     *
     * @param  callable  $callback
     * @return void
     */
    public static function withoutSyncingToSearch($callback)
    {
        static::disableSearchSyncing();

        $callback();

        static::enableSearchSyncing();
    }

    /**
     * Get the index name for the model.
     *
     * @return string
     */
    public function searchableAs()
    {
        return $this->getTable();
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        return $this->toArray();
    }

    /**
     * Get the Scout engine for the model.
     *
     * @return mixed
     */
    public function searchableUsing()
    {
        return app(EngineManager::class)->engine();
    }

    /**
     * Get the queue connection that should be used when syncing.
     *
     * @return string
     */
    public function syncWithSearchUsing()
    {
        return config('queue.default');
    }
}
