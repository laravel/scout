<?php

namespace Laravel\Scout\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class RemoveFromSearch implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The models to be removed from the search index.
     *
     * @var \Illuminate\Database\Eloquent\Collection
     */
    public $models;

    /**
     * Create a new job instance.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function __construct($models)
    {
        $this->models = RemoveableScoutCollection::make($models);
    }

    /**
     * Handle the job.
     *
     * @return void
     */
    public function handle()
    {
        if ($this->models->isNotEmpty()) {
            $this->models->first()->searchableUsing()->delete($this->models);
        }
    }

    /**
     * Restore a queueable collection instance.
     *
     * @param  \Illuminate\Contracts\Database\ModelIdentifier  $value
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function restoreCollection($value)
    {
        if (! $value->class || count($value->id) === 0) {
            return new EloquentCollection;
        }

        return new EloquentCollection(
            collect($value->id)->map(function ($id) use ($value) {
                return tap(new $value->class, function ($model) use ($id) {
                    $keyName = $this->getUnqualifiedScoutKeyName(
                        $model->getScoutKeyName()
                    );

                    $model->forceFill([$keyName => $id]);
                });
            })
        );
    }

    /**
     * Get the unqualified Scout key name.
     *
     * @param string $keyName
     * @return string
     */
    protected function getUnqualifiedScoutKeyName($keyName)
    {
        return Str::afterLast($keyName, '.');
    }
}
