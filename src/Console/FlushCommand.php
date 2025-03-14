<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\RuntimeException;

#[AsCommand(name: 'scout:flush')]
class FlushCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:flush
        {model? : Class name of the model to flush}
        {--all : Flush all configured models}';

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
     */
    public function handle()
    {
        if (! $this->argument('model') && ! $this->option('all')) {
            throw new RuntimeException('Not enough arguments (missing: "model")');
        }

        if ($class = $this->argument('model')) {
            return $this->flushModel($class);
        }

        $this->flushAllModels();
    }

    /**
     * Flush the model's records from the index.
     *
     * @param  string<class-string> $class
     * @return void
     */
    protected function flushModel(string $class)
    {
        $model = new $class;

        $model::removeAllFromSearch();

        $this->info('All ['.$class.'] records have been flushed.');
    }

    protected function flushAllModels()
    {
        $driver = config('scout.driver');
        $modelsKey = $driver === 'typesense' ? 'model-settings' : 'index-settings';
        $settings = (array) config('scout.'.$driver.'.'.$modelsKey);
        $classes = array_keys($settings);

        foreach ($classes as $class) {
            $this->flushModel($class);
        }
    }
}
