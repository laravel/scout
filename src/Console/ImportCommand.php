<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Scout\Events\ModelsImported;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\RuntimeException;

#[AsCommand(name: 'scout:import')]
class ImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:import
            {model? : Class name of model to bulk import}
            {--c|chunk= : The number of records to import at a time (Defaults to configuration value: `scout.chunk.searchable`)}
            {--all : Import all configured models}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import the given model into the search index';

    protected Dispatcher $events;

    /**
     * Execute the console command.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function handle(Dispatcher $events)
    {
        $this->events = $events;

        if (! $this->argument('model') && ! $this->option('all')) {
            throw new RuntimeException('Not enough arguments (missing: "model")');
        }

        if ($class = $this->argument('model')) {
            return $this->importModel($class);
        }

        $this->importAllModels();
    }

    protected function importModel(string $class)
    {
        $model = new $class;

        $this->events->listen(ModelsImported::class, function ($event) use ($class) {
            $key = $event->models->last()->getScoutKey();

            $this->line('<comment>Imported ['.$class.'] models up to ID:</comment> '.$key);
        });

        $model::makeAllSearchable($this->option('chunk'));

        $this->events->forget(ModelsImported::class);

        $this->info('All ['.$class.'] records have been imported.');
    }

    protected function importAllModels()
    {
        $driver = config('scout.driver');
        $modelsKey = $driver === 'typesense' ? 'model-settings' : 'index-settings';
        $settings = (array) config('scout.'.$driver.'.'.$modelsKey);
        $classes = array_keys($settings);

        foreach ($classes as $class) {
            $this->importModel($class);
        }
    }
}
