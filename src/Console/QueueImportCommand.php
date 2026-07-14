<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Laravel\Scout\Exceptions\ScoutException;
use Laravel\Scout\Jobs\MakeRangeSearchable;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'scout:queue-import')]
class QueueImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:queue-import
            {model : Class name of model to bulk queue}
            {--min= : The minimum ID to start queuing from}
            {--max= : The maximum ID to queue up to}
            {--c|chunk= : The number of records to queue in a single job (Defaults to configuration value: `scout.chunk.searchable`)}
            {--order=asc : The order in which ranges should be queued (`asc` or `desc`)}
            {--queue= : The queue that should be used (Defaults to configuration value: `scout.queue.queue`)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import the given model into the search index via chunked, queued jobs';

    /**
     * Execute the console command.
     *
     * @return void
     *
     * @throws ScoutException
     */
    public function handle()
    {
        $class = $this->argument('model');

        if (! class_exists($class) && ! class_exists($class = app()->getNamespace()."Models\\{$class}")) {
            throw new ScoutException("Model [{$class}] not found.");
        }

        $model = new $class;

        $query = $model::makeAllSearchableQuery();

        $order = $this->option('order') ?: 'asc';

        $min = $this->option('min') ?? $query->min($model->getScoutKeyName());
        $max = $this->option('max') ?? $query->max($model->getScoutKeyName());

        $chunk = max(1, (int) ($this->option('chunk') ?? config('scout.chunk.searchable', 500)));

        if (! in_array($order, ['asc', 'desc'])) {
            $this->error('The order option must be either "asc" or "desc".');

            return;
        }

        if (! $min || ! $max) {
            $this->info('No records found for ['.$class.'].');

            return;
        }

        if (! is_numeric($min) || ! is_numeric($max)) {
            $this->error('The primary key for ['.$class.'] is not numeric.');

            return;
        }

        if ($min > $max) {
            $this->info('No records found for ['.$class.'] within the given ID range.');

            return;
        }

        $min = (int) $min;
        $max = (int) $max;

        for ($offset = 0; $offset <= $max - $min; $offset += $chunk) {
            if ($order === 'asc') {
                $start = $min + $offset;
                $end = min($start + $chunk - 1, $max);
            } else {
                $end = $max - $offset;
                $start = max($end - $chunk + 1, $min);
            }

            dispatch(new MakeRangeSearchable($class, $start, $end))
                ->onQueue($this->option('queue') ?? $model->syncWithSearchUsingQueue())
                ->onConnection($model->syncWithSearchUsing());

            $this->line($order === 'asc'
                ? '<comment>Queued ['.$class.'] models up to ID:</comment> '.$end
                : '<comment>Queued ['.$class.'] models down to ID:</comment> '.$start);
        }

        $this->info('All ['.$class.'] records have been queued for importing.');
    }
}
