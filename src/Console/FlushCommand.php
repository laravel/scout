<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Laravel\Scout\Events\ModelsFlushed;
use Illuminate\Contracts\Events\Dispatcher;

class FlushCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:flush {model}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Flush all of the model's records from the index";

    /**
     * Execute the console command.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function handle(Dispatcher $events)
    {
        $class = $this->argument('model');

        $model = new $class;

        $events->listen(ModelsFlushed::class, function ($event) use ($class) {
            $key = $event->models->last()->getKey();

            $this->line('<comment>Flushed ['.$class.'] models up to ID:</comment> '.$key);
        });

        $model::removeAllFromSearch();

        $events->forget(ModelsFlushed::class);

        $this->info('All ['.$class.'] records have been flushed.');
    }
}
