<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Scout\Events\ModelsImported;

class ImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:import
            {model : Class name of model to bulk import}
            {--c|chunk= : The number of records to import at a time (Defaults to configuration value: `scout.chunk.searchable`)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import the given model into the search index';

    /**
     * Execute the console command.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function handle(Dispatcher $events)
    {
        $class = $this->lookupClass();

        if(!$class) {
            $this->error('Class ['.$this->argument('model').'] could not be found.');
            return self::FAILURE;
        }

        $model = new $class;

        $events->listen(ModelsImported::class, function ($event) use ($class) {
            $key = $event->models->last()->getScoutKey();

            $this->line('<comment>Imported ['.$class.'] models up to ID:</comment> '.$key);
        });

        $model::makeAllSearchable($this->option('chunk'));

        $events->forget(ModelsImported::class);

        $this->info('All ['.$class.'] records have been imported.');
    }

    protected function lookupClass()
    {
        $class = $this->argument('model');
        if(!class_exists($class)) {
            $rootNamespace = $this->laravel->getNamespace();
            $class = is_dir(app_path('Models'))
                ? $rootNamespace.'Models\\'.$class
                : $rootNamespace.$class;
        }

        if(!class_exists($class)) {
            return false;
        }

        return $class;
    }
}
