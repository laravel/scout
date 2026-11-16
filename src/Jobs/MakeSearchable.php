<?php

namespace Laravel\Scout\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Queue\SerializesModels;
use Laravel\Scout\Traits\ConfiguresJobOptions;

class MakeSearchable implements ShouldQueue
{
    use ConfiguresJobOptions, Queueable, SerializesModels;

    /**
     * The models to be made searchable.
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
        $this->models = $models;

        $this->configureJob();
    }

    /**
     * Handle the job.
     *
     * @return void
     */
    public function handle()
    {
        $models = $this->withoutTrashedModels($this->models);

        if ($models->isEmpty()) {
            return;
        }

        $models->first()->makeSearchableUsing($models)->first()->searchableUsing()->update($models);
    }

    /**
     * Remove the models that were soft deleted after the job was dispatched.
     *
     * Queued models are restored without global scopes, so soft deleted models are restored as well.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function withoutTrashedModels($models)
    {
        if ($models->isEmpty() ||
            config('scout.soft_delete', false) ||
            ! in_array(SoftDeletes::class, class_uses_recursive($models->first()))) {
            return $models;
        }

        return $models->reject->trashed()->values();
    }
}
