<?php

namespace Laravel\Scout;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection as BaseCollection;
use Laravel\Scout\Jobs\MakeSearchable;

trait Searchable
{
    /**
     * Additional metadata attributes managed by Scout.
     *
     * @var array
     */
    protected $scoutMetadata = [];

    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootSearchable()
    {
        static::addGlobalScope(new SearchableScope);

        static::observe(new ModelObserver);

        (new static)->registerSearchableMacros();
    }

    /**
     * Register the searchable macros.
     *
     * @return void
     */
    public function registerSearchableMacros()
    {
        $self = $this;

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
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function queueMakeSearchable($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        if (! config('scout.queue')) {
            return $models->first()->searchableUsing()->update($models);
        }

        dispatch((new MakeSearchable($models))
                ->onQueue($models->first()->syncWithSearchUsingQueue())
                ->onConnection($models->first()->syncWithSearchUsing()));
    }

    /**
     * Dispatch the job to make the given models unsearchable.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function queueRemoveFromSearch($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        return $models->first()->searchableUsing()->delete($models);
    }

    /**
     * Determine if the model should be searchable.
     *
     * @return bool
     */
    public function shouldBeSearchable()
    {
        return true;
    }

    /**
     * Perform a search against the model's indexed data.
     *
     * @param  string  $query
     * @param  \Closure  $callback
     * @return \Laravel\Scout\Builder
     */
    public static function search($query = '', $callback = null)
    {
        return app(Builder::class, [
            'model' => new static,
            'query' => $query,
            'callback' => $callback,
            'softDelete'=> static::usesSoftDelete() && config('scout.soft_delete', false),
        ]);
    }

    /**
     * Make all instances of the model searchable.
     *
     * @return void
     */
    public static function makeAllSearchable()
    {
        $self = new static;

        $softDelete = static::usesSoftDelete() && config('scout.soft_delete', false);

        $self->newQuery()
            ->when($softDelete, function ($query) {
                $query->withTrashed();
            })
            ->orderBy($self->getKeyName())
            ->searchable();
    }

    /**
     * Make the given model instance searchable.
     *
     * @return void
     */
    public function searchable()
    {
        $this->newCollection([$this])->searchable();
    }

    /**
     * Remove all instances of the model from the search index.
     *
     * @return void
     */
    public static function removeAllFromSearch()
    {
        $self = new static;

        $self->searchableUsing()->flush($self);
    }

    /**
     * Remove the given model instance from the search index.
     *
     * @return void
     */
    public function unsearchable()
    {
        $this->newCollection([$this])->unsearchable();
    }

    /**
     * Get the requested models from an array of object IDs.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $ids
     * @return mixed
     */
    public function getScoutModelsByIds(Builder $builder, array $ids)
    {
        $query = static::usesSoftDelete()
            ? $this->withTrashed() : $this->newQuery();

        if ($builder->queryCallback) {
            call_user_func($builder->queryCallback, $query);
        }

        return $query->whereIn(
            $this->getScoutKeyName(), $ids
        )->get();
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
     * @return mixed
     */
    public static function withoutSyncingToSearch($callback)
    {
        static::disableSearchSyncing();

        try {
            return $callback();
        } finally {
            static::enableSearchSyncing();
        }
    }

    /**
     * Get the index name for the model.
     *
     * @return string
     */
    public function searchableAs()
    {
        return config('scout.prefix').$this->getTable();
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
        return config('scout.queue.connection') ?: config('queue.default');
    }

    /**
     * Get the queue that should be used with syncing.
     *
     * @return string
     */
    public function syncWithSearchUsingQueue()
    {
        return config('scout.queue.queue');
    }

    /**
     * Sync the soft deleted status for this model into the metadata.
     *
     * @return $this
     */
    public function pushSoftDeleteMetadata()
    {
        return $this->withScoutMetadata('__soft_deleted', $this->trashed() ? 1 : 0);
    }

    /**
     * Get all Scout related metadata.
     *
     * @return array
     */
    public function scoutMetadata()
    {
        return $this->scoutMetadata;
    }

    /**
     * Set a Scout related metadata.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function withScoutMetadata($key, $value)
    {
        $this->scoutMetadata[$key] = $value;

        return $this;
    }

    /**
     * Get the value used to index the model.
     *
     * @return mixed
     */
    public function getScoutKey()
    {
        return $this->getKey();
    }

    /**
     * Get the key name used to index the model.
     *
     * @return mixed
     */
    public function getScoutKeyName()
    {
        return $this->getQualifiedKeyName();
    }

    /**
     * Determine if the current class should use soft deletes with searching.
     *
     * @return bool
     */
    protected static function usesSoftDelete()
    {
        return in_array(SoftDeletes::class, class_uses_recursive(get_called_class()));
    }
}
