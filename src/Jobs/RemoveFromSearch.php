<?php

namespace Laravel\Scout\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;

class RemoveFromSearch implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The models to be removed from the search index.
     *
     * @var \Laravel\Scout\Jobs\RemoveableScoutCollection
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
     * @return \Laravel\Scout\Jobs\RemoveableScoutCollection
     */
    protected function restoreCollection($value)
    {
        if (! $value->class || count($value->id) === 0) {
            return new RemoveableScoutCollection;
        }

        return new RemoveableScoutCollection(
            collect($value->id)->map(function ($id) use ($value) {
                return tap(new $value->class, function ($model) use ($id) {
                    $model->setKeyType(
                        is_string($id) ? 'string' : 'int'
                    )->forceFill([
                        $model->getScoutKeyName() => $id,
                    ]);
                });
            })
        );
    }
}
