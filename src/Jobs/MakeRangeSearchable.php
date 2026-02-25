<?php

namespace Laravel\Scout\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Laravel\Scout\Scout;

class MakeRangeSearchable implements ShouldQueue
{
    use Queueable;

    /**
     * The model to be made searchable.
     *
     * @var string
     */
    public $class;

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
     * @param  string  $class
     * @param  int  $start
     * @param  int  $end
     * @return void
     */
    public function __construct($class, $start, $end)
    {
        $this->class = $class;
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
        $model = new $this->class;

        $models = $model::makeAllSearchableQuery()
            ->whereBetween($model->getScoutKeyName(), [$this->start, $this->end])
            ->get()
            ->filter
            ->shouldBeSearchable();

        if ($models->isEmpty()) {
            return;
        }

        dispatch(new Scout::$makeSearchableJob($models))
            ->onQueue($model->syncWithSearchUsingQueue())
            ->onConnection($model->syncWithSearchUsing());
    }
}
