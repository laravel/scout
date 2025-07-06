<?php

namespace Laravel\Scout\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Scout\Scout;

class MakeRangeSearchable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The model to be made searchable.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    public $model;

    /**
     * The start id to be made searchable.
     *
     * @var int
     */
    public $start;

    /**
     * The end id to be made searchable.
     *
     * @var int
     */
    public $end;

    /**
     * Create a new job instance.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  int  $start
     * @param  int  $end
     * @return void
     */
    public function __construct($model, $start, $end)
    {
        $this->model = $model;
        $this->start = $start;
        $this->end = $end;
    }

    /**
     * Handle the job.
     *
     * @return void
     */
    public function handle()
    {
        $models = $this->model::makeAllSearchableQuery()
            ->whereBetween($this->model->getScoutKeyName(), [$this->start, $this->end])
            ->get()
            ->filter
            ->shouldBeSearchable();

        dispatch(new Scout::$makeSearchableJob($models))
            ->onQueue($this->model->syncWithSearchUsingQueue())
            ->onConnection($this->model->syncWithSearchUsing());
    }
}
