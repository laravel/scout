<?php

namespace Laravel\Scout;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection as BaseCollection;

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

        BaseCollection::macro('searchableSync', function () use ($self) {
            $self->syncMakeSearchable($this);
        });

        BaseCollection::macro('unsearchableSync', function () use ($self) {
            $self->syncRemoveFromSearch($this);
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
            return $this->syncMakeSearchable($models);
        }

        dispatch((new Scout::$makeSearchableJob($models))
                ->onQueue($models->first()->syncWithSearchUsingQueue())
                ->onConnection($models->first()->syncWithSearchUsing()));
    }

    /**
     * Synchronously make the given models searchable.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function syncMakeSearchable($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        return $models->first()->makeSearchableUsing($models)->first()->searchableUsing()->update($models);
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

        if (! config('scout.queue')) {
            return $this->syncRemoveFromSearch($models);
        }

        dispatch(new Scout::$removeFromSearchJob($models))
            ->onQueue($models->first()->syncWithSearchUsingQueue())
            ->onConnection($models->first()->syncWithSearchUsing());
    }

    /**
     * Synchronously make the given models unsearchable.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function syncRemoveFromSearch($models)
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
     * When updating a model, this method determines if we should update the search index.
     *
     * @return bool
     */
    public function searchIndexShouldBeUpdated()
    {
        return true;
    }

    /**
     * Perform a search against the model's indexed data.
     *
     * @param  string  $query
     * @param  \Closure  $callback
     * @return \Laravel\Scout\Builder<static>
     */
    public static function search($query = '', $callback = null)
    {
        return app(static::$scoutBuilder ?? Builder::class, [
            'model' => new static,
            'query' => $query,
            'callback' => $callback,
            'softDelete' => static::usesSoftDelete() && config('scout.soft_delete', false),
        ]);
    }

    /**
     * Make all instances of the model searchable.
     *
     * @param  int  $chunk
     * @return void
     */
    public static function makeAllSearchable($chunk = null)
    {
        static::makeAllSearchableQuery()->searchable($chunk);
    }

    /**
     * Get a query builder for making all instances of the model searchable.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function makeAllSearchableQuery()
    {
        $self = new static;

        $softDelete = static::usesSoftDelete() && config('scout.soft_delete', false);

        return $self->newQuery()
            ->when(true, function ($query) use ($self) {
                $self->makeAllSearchableUsing($query);
            })
            ->when($softDelete, function ($query) {
                $query->withTrashed();
            })
            ->orderBy(
                $self->qualifyColumn($self->getScoutKeyName())
            );
    }

    /**
     * Modify the collection of models being made searchable.
     *
     * @param  \Illuminate\Support\Collection  $models
     * @return \Illuminate\Support\Collection
     */
    public function makeSearchableUsing(BaseCollection $models)
    {
        return $models;
    }

    /**
     * Modify the query used to retrieve models when making all of the models searchable.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function makeAllSearchableUsing(EloquentBuilder $query)
    {
        return $query;
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
     * Synchronously make the given model instance searchable.
     *
     * @return void
     */
    public function searchableSync()
    {
        $this->newCollection([$this])->searchableSync();
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
     * Synchronously remove the given model instance from the search index.
     *
     * @return void
     */
    public function unsearchableSync()
    {
        $this->newCollection([$this])->unsearchableSync();
    }

    /**
     * Determine if the model existed in the search index prior to an update.
     *
     * @return bool
     */
    public function wasSearchableBeforeUpdate()
    {
        return true;
    }

    /**
     * Determine if the model existed in the search index prior to deletion.
     *
     * @return bool
     */
    public function wasSearchableBeforeDelete()
    {
        return true;
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
        return $this->queryScoutModelsByIds($builder, $ids)->get();
    }

    /**
     * Get a query builder for retrieving the requested models from an array of object IDs.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $ids
     * @return mixed
     */
    public function queryScoutModelsByIds(Builder $builder, array $ids)
    {
        $query = static::usesSoftDelete()
            ? $this->withTrashed()
            : $this->newQuery();

        if ($builder->queryCallback) {
            call_user_func($builder->queryCallback, $query);
        }

        $whereIn = in_array($this->getScoutKeyType(), ['int', 'integer']) ?
            'whereIntegerInRaw' :
            'whereIn';

        return $query->{$whereIn}(
            $this->qualifyColumn($this->getScoutKeyName()), $ids
        );
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
     * Get the index name for the model when searching.
     *
     * @return string
     */
    public function searchableAs()
    {
        return config('scout.prefix').$this->getTable();
    }

    /**
     * Get the index name for the model when indexing.
     *
     * @return string
     */
    public function indexableAs()
    {
        return $this->searchableAs();
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
     * Get the auto-incrementing key type for querying models.
     *
     * @return string
     */
    public function getScoutKeyType()
    {
        return $this->getKeyType();
    }

    /**
     * Get the key name used to index the model.
     *
     * @return mixed
     */
    public function getScoutKeyName()
    {
        return $this->getKeyName();
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
