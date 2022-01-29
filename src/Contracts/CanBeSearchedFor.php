<?php

namespace Laravel\Scout\Contracts;

use Laravel\Scout\Builder;

interface CanBeSearchedFor
{
    /**
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function queueMakeSearchable($models);

    /**
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function queueRemoveFromSearch($models);

    /**
     * @return bool
     */
    public function shouldBeSearchable();

    /**
     * @return bool
     */
    public function searchIndexShouldBeUpdated();

    /**
     * @param  string  $query
     * @param  \Closure  $callback
     * @return \Laravel\Scout\Builder
     */
    public static function search($search = '', $callback = null);

    /**
     * @param  int  $chunk
     * @return void
     */
    public static function makeAllSearchable($chunk = null);

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function makeAllSearchableUsing($query);

    /**
     * @return void
     */
    public function searchable();

    /**
     * @return void
     */
    public static function removeAllFromSearch();

    /**
     * @return void
     */
    public function unsearchable();

    /**
     * @return bool
     */
    public function wasSearchableBeforeUpdate();

    /**
     * @return bool
     */
    public function wasSearchableBeforeDelete();

    /**
     * @return mixed
     */
    public function getScoutModelsByIds(Builder $builder, array $ids);

    /**
     * @return mixed
     */
    public function queryScoutModelsByIds(Builder $builder, array $ids);

    /**
     * @return void
     */
    public static function enableSearchSyncing();

    /**
     * @return void
     */
    public static function disableSearchSyncing();

    /**
     * @param  callable  $callback
     * @return mixed
     */
    public static function withoutSyncingToSearch($callback);

    /**
     * @return string
     */
    public function searchableAs();

    /**
     * @return array
     */
    public function toSearchableArray();

    /**
     * @return mixed
     */
    public function searchableUsing();

    /**
     * @return string
     */
    public function syncWithSearchUsing();

    /**
     * @return string
     */
    public function syncWithSearchUsingQueue();

    /**
     * @return $this
     */
    public function pushSoftDeleteMetadata();

    /**
     * @return array
     */
    public function scoutMetadata();

    /**
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function withScoutMetadata($key, $value);

    /**
     * @return mixed
     */
    public function getScoutKey();

    /**
     * @return mixed
     */
    public function getScoutKeyName();

    /**
     * @return bool
     */
    public static function usesSoftDelete();
}
