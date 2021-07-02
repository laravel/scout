<?php

namespace Laravel\Scout\Contracts;

interface Searchable
{
    /**
     * Boot the trait.
     *
     * @return void
     */
    public static function bootSearchable();

    /**
     * Register the searchable macros.
     *
     * @return void
     */
    public function registerSearchableMacros();

    /**
     * Dispatch the job to make the given models searchable.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function queueMakeSearchable($models);

    /**
     * Dispatch the job to make the given models unsearchable.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function queueRemoveFromSearch($models);

    /**
     * Determine if the model should be searchable.
     *
     * @return bool
     */
    public function shouldBeSearchable();

    /**
     * Perform a search against the model's indexed data.
     *
     * @param  string  $query
     * @param  \Closure  $callback
     * @return \Laravel\Scout\Builder
     */
    public static function search($query = '', $callback = null);

    /**
     * Make all instances of the model searchable.
     *
     * @param  int  $chunk
     * @return void
     */
    public static function makeAllSearchable($chunk = null);

    /**
     * Make the given model instance searchable.
     *
     * @return void
     */
    public function searchable();

    /**
     * Remove all instances of the model from the search index.
     *
     * @return void
     */
    public static function removeAllFromSearch();

    /**
     * Remove the given model instance from the search index.
     *
     * @return void
     */
    public function unsearchable();

    /**
     * Get the requested models from an array of object IDs.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $ids
     * @return mixed
     */
    public function getScoutModelsByIds(\Laravel\Scout\Builder $builder, array $ids);

    /**
     * Get a query builder for retrieving the requested models from an array of object IDs.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $ids
     * @return mixed
     */
    public function queryScoutModelsByIds(\Laravel\Scout\Builder $builder, array $ids);

    /**
     * Enable search syncing for this model.
     *
     * @return void
     */
    public static function enableSearchSyncing();

    /**
     * Disable search syncing for this model.
     *
     * @return void
     */
    public static function disableSearchSyncing();

    /**
     * Temporarily disable search syncing for the given callback.
     *
     * @param  callable  $callback
     * @return mixed
     */
    public static function withoutSyncingToSearch($callback);

    /**
     * Get the index name for the model.
     *
     * @return string
     */
    public function searchableAs();

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray();

    /**
     * Get the Scout engine for the model.
     *
     * @return mixed
     */
    public function searchableUsing();

    /**
     * Get the queue connection that should be used when syncing.
     *
     * @return string
     */
    public function syncWithSearchUsing();

    /**
     * Get the queue that should be used with syncing.
     *
     * @return string
     */
    public function syncWithSearchUsingQueue();

    /**
     * Sync the soft deleted status for this model into the metadata.
     *
     * @return Searchable
     */
    public function pushSoftDeleteMetadata();

    /**
     * Get all Scout related metadata.
     *
     * @return array
     */
    public function scoutMetadata();

    /**
     * Set a Scout related metadata.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return Searchable
     */
    public function withScoutMetadata($key, $value);

    /**
     * Get the value used to index the model.
     *
     * @return mixed
     */
    public function getScoutKey();

    /**
     * Get the key name used to index the model.
     *
     * @return mixed
     */
    public function getScoutKeyName();
}
