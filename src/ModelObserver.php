<?php

namespace Laravel\Scout;

use Closure;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;

class ModelObserver
{
    /**
     * Indicates if Scout will dispatch the observer's events after all database transactions have committed.
     *
     * @var bool
     */
    public $afterCommit;

    /**
     * Indicates if Scout will keep soft deleted records in the search indexes.
     *
     * @var bool
     */
    protected $usingSoftDeletes;

    /**
     * Indicates if the model is currently force saving.
     *
     * @var bool
     */
    protected $forceSaving = false;

    /**
     * The class names that syncing is disabled for.
     *
     * @var array
     */
    protected static $syncingDisabledFor = [];

    /**
     * Create a new observer instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->afterCommit = Config::get('scout.after_commit', false);
        $this->usingSoftDeletes = Config::get('scout.soft_delete', false);
    }

    /**
     * Enable syncing for the given class.
     *
     * @param  string  $class
     * @return void
     */
    public static function enableSyncingFor($class)
    {
        unset(static::$syncingDisabledFor[$class]);
    }

    /**
     * Disable syncing for the given class.
     *
     * @param  string  $class
     * @return void
     */
    public static function disableSyncingFor($class)
    {
        static::$syncingDisabledFor[$class] = true;
    }

    /**
     * Determine if syncing is disabled for the given class or model.
     *
     * @param  object|string  $class
     * @return bool
     */
    public static function syncingDisabledFor($class)
    {
        $class = is_object($class) ? get_class($class) : $class;

        return isset(static::$syncingDisabledFor[$class]);
    }

    /**
     * Handle the saved event for the model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function saved($model)
    {
        if (static::syncingDisabledFor($model)) {
            return;
        }

        if (! $this->forceSaving && ! $model->searchIndexShouldBeUpdated()) {
            return;
        }

        if (! $model->shouldBeSearchable()) {
            if ($model->wasSearchableBeforeUpdate()) {
                $model->unsearchable();
            }

            return;
        }

        $model->searchable();
    }

    /**
     * Handle the deleted event for the model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function deleted($model)
    {
        if (static::syncingDisabledFor($model)) {
            return;
        }

        if (! $model->wasSearchableBeforeDelete()) {
            return;
        }

        if ($this->usingSoftDeletes && $this->usesSoftDelete($model)) {
            $this->whileForcingUpdate(function () use ($model) {
                $this->saved($model);
            });
        } else {
            $model->unsearchable();
        }
    }

    /**
     * Handle the force deleted event for the model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function forceDeleted($model)
    {
        if (static::syncingDisabledFor($model)) {
            return;
        }

        $model->unsearchable();
    }

    /**
     * Handle the restored event for the model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function restored($model)
    {
        $this->whileForcingUpdate(function () use ($model) {
            $this->saved($model);
        });
    }

    /**
     * Execute the given callback while forcing updates.
     *
     * @param  \Closure  $callback
     * @return mixed
     */
    protected function whileForcingUpdate(Closure $callback)
    {
        $this->forceSaving = true;

        $result = $callback();

        $this->forceSaving = false;

        return $result;
    }

    /**
     * Determine if the given model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model));
    }
}
