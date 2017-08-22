<?php

namespace Laravel\Scout;

use Laravel\Scout\Jobs\MakeSearchable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Laravel\Scout\ModelObserver;
use Laravel\Scout\SearchableScope;

trait Searchable
{

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
     * Perform a search against the model's indexed data.
     *
     * @param  string  $query
     * @param  Closure  $callback
     * @return \Laravel\Scout\Builder
     */
    public static function search($query, $callback = null)
    {
        return new Builder(new static, $query, $callback);
    }

    /**
     * Make all instances of the model searchable.
     *
     * @return void
     */
    public static function makeAllSearchable()
    {
        $self = new static();

        $self->newQuery()
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
        Collection::make([$this])->searchable();
    }

    /**
     * Remove all instances of the model from the search index.
     *
     * @return void
     */
    public static function removeAllFromSearch()
    {
        $self = new static();

        $self->newQuery()
            ->orderBy($self->getKeyName())
            ->unsearchable();
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
     * Get the indexable data array for the model and its searchable relations.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        $indices=[];
        if(empty(self::$searchableAttributes)){
            $indices = $this->toArray();
        }
        else {
            $indices=array_only($this->toArray(), self::$searchableAttributes);
        }
        if(!empty(self::$searchableRelationAttributes)){
            $indices = array_merge($indices, $this->relationToSearchableArray());
        }
        return $indices;
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
     * Get the queue that should be used with syncing
     *
     * @return  string
     */
    public function syncWithSearchUsingQueue()
    {
        return config('scout.queue.queue');
    }

    /**
     * Index all indexable relationships
     *
     * @return array
     */
    private function relationToSearchableArray() {
        $extraData = [];
        //load all relations
        $this->loadRelationTree(array_keys(self::$searchableRelationAttributes));

        foreach (self::$searchableRelationAttributes as $relation=>$fields){


            $extraData= array_merge($this->relationDataArray($relation, $fields), $extraData);


        }

        return $extraData;
    }

    /**
     * Load all searchable relations
     *
     * @param array $relationList
     */
    public function loadRelationTree(array $relationList){
        $relations = [];
        foreach ($relationList as $relation){
            $relationNodes=[];
            $position = 0;
            $needle='.';
            while(strpos($relation, $needle, $position) > -1){
                $index = strpos($relation, '.', $position);
                $relationNodes[] = substr($relation,0,$index);
                $position = $index + strlen($needle);
            }

            $relationNodes[]=$relation;
            $relations=array_unique(array_merge($relations,$relationNodes));

        }

        $this->load($relations);
    }

    /**
     * Index all indexable relationship data of a relation
     *
     * @param $relation
     * @param $fields
     *
     * @return array
     */
    private function relationDataArray($relation, $fields){
        $extraData=[];
        $data = $this->toArray();
        foreach (explode('.', $relation) as $node){
            $data=$data[$node];
        }

        if(!is_null($data)) {
            if(!empty($fields)){
                $data = array_only($data, $fields);
            }
            foreach ($data as $key => $value) {
                if (!is_array($value)) {
                    $extraData[str_replace('.', '_', $relation) . '_'
                    . $key]
                        = $value;
                }
            }
        }

        return $extraData;
    }
}
