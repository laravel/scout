<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Laravel\Scout\Jobs\MakeRangeSearchable;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'scout:queue')]
class QueueCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:queue
            {model : Class name of model to bulk queue}
            {--c|chunk= : The number of records to queue in a single job (Defaults to configuration value: `scout.chunk.searchable`)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import the given model into the search index';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $class = $this->argument('model');

        $model = new $class;

        $query = $model::makeAllSearchableQuery();

        $min = $query->min($model->getScoutKeyName());
        $max = $query->max($model->getScoutKeyName());

        if (! $min || ! $max) {
            $this->info('No records found for ['.$class.']');

            return;
        }

        if (! is_numeric($min) || ! is_numeric($max)) {
            $this->error('The primary key for ['.$class.'] is not numeric.');

            return;
        }

        $chunkSize = $this->option('chunk') ?? config('scout.chunk.searchable', 500);

        $chunkSize = max(1, (int) $chunkSize);

        for ($start = $min; $start <= $max; $start += $chunkSize) {
            $end = min($start + $chunkSize - 1, $max);

            dispatch(new MakeRangeSearchable($model, $start, $end))
                ->onQueue($model->syncWithSearchUsingQueue())
                ->onConnection($model->syncWithSearchUsing());

            $this->line('<comment>Queued ['.$class.'] models up to ID:</comment> '.$end);
        }

        $this->info('All ['.$class.'] records have been queued.');
    }
}
