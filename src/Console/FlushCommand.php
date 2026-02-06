<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Laravel\Scout\Exceptions\ScoutException;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'scout:flush')]
class FlushCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:flush {model : Class name of the model to flush}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Flush all of the model's records from the index";

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

        $model::removeAllFromSearch();

        $this->info('All ['.$class.'] records have been flushed.');
    }
}
