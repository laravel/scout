<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Scout\Events\ModelsImported;
use Laravel\Scout\Searchable;
use Symfony\Component\Finder\Finder;

class ImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:import
            {model? : Class name of model to bulk import}
            {--c|chunk= : The number of records to import at a time (Defaults to configuration value: `scout.chunk.searchable`)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import all or the given model into the search index';

    /**
     * Execute the console command.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function handle(Dispatcher $events)
    {
        if (is_null($this->argument('model'))) {
            $this->importAll($events);

            return;
        }

        $this->import($events);
    }

    /**
     * Bulk import specified model.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @param  string|null  $model
     * @return void
     */
    protected function import($events, $model = null) {
        $class = $model ?? $this->argument('model');

        $model = new $class;

        $events->listen(ModelsImported::class, function ($event) use ($class) {
            $key = $event->models->last()->getScoutKey();

            $this->line('<comment>Imported [' . $class . '] models up to ID:</comment> ' . $key);
        });

        $model::makeAllSearchable($this->option('chunk'));

        $events->forget(ModelsImported::class);

        $this->info('All [' . $class . '] records have been imported.');
    }

    /**
     * Bulk import all models.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    protected function importAll($events) {
        $models = $this->getSearchableModels();

        if (empty($models)) {
            $this->warn('There is no searchable model to import.');

            return;
        }

        foreach ($models as $model) {
            $this->import($events, $model);
        }
    }

    /**
     * Get all the searchable models
     *
     * @return array
     */
    protected function getSearchableModels()
    {
        $paths = array_unique(Arr::wrap(app_path('Models')));

        $paths = array_filter($paths, function ($path) {
            return is_dir($path);
        });

        if (empty($paths)) {
            return [];
        }

        $namespace = app()->getNamespace();

        $models = [];

        foreach ((new Finder)->in($paths)->files() as $model) {
            $model = $namespace.str_replace(
                    ['/', '.php'],
                    ['\\', ''],
                    Str::after($model->getRealPath(), realpath(app_path()).DIRECTORY_SEPARATOR)
                );

            if (is_subclass_of($model, Model::class) &&
                in_array(Searchable::class, class_uses($model)) &&
                ! (new \ReflectionClass($model))->isAbstract()) {
                $models[] = $model;
            }
        }

        return $models;
    }
}
