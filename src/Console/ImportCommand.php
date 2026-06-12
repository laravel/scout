<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Engines\PgsqlEngine;
use Laravel\Scout\Events\ModelsImported;
use Laravel\Scout\Exceptions\ScoutException;
use Laravel\Scout\Pgsql\SearchableSchema;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'scout:import')]
class ImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:import
            {model : Class name of model to bulk import}
            {--fresh : Flush the index before importing}
            {--prepare-pgsql : Create PostgreSQL search columns and indexes before importing}
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
     *
     * @throws ScoutException
     */
    public function handle(Dispatcher $events)
    {
        $class = $this->argument('model');

        if (! class_exists($class) && ! class_exists($class = app()->getNamespace()."Models\\{$class}")) {
            throw new ScoutException("Model [{$class}] not found.");
        }

        $model = new $class;
        $usesPgsqlEngine = $model->searchableUsing() instanceof PgsqlEngine;

        if ($usesPgsqlEngine && $this->option('prepare-pgsql')) {
            $this->preparePgsqlSearch($model, $class);
        } elseif ($usesPgsqlEngine) {
            $this->warn('Using the [pgsql] Scout engine does not create PostgreSQL search columns or indexes.');
            $this->warn('Add the PostgreSQL search vector and any trigram indexes through a migration before importing.');
        } elseif ($this->option('prepare-pgsql')) {
            $this->warn('The [--prepare-pgsql] option only applies to models using the [pgsql] Scout engine.');
        }

        $events->listen(ModelsImported::class, function ($event) use ($class) {
            $key = $event->models->last()->getScoutKey();

            $this->line('<comment>Imported ['.$class.'] models up to ID:</comment> '.$key);
        });

        if ($this->option('fresh')) {
            $model::removeAllFromSearch();
        }

        $model::makeAllSearchable($this->option('chunk'));

        $events->forget(ModelsImported::class);

        $this->info('All ['.$class.'] records have been imported.');
    }

    /**
     * Prepare PostgreSQL search columns and indexes for the model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  class-string  $class
     * @return void
     */
    protected function preparePgsqlSearch($model, $class)
    {
        $schema = Schema::connection($model->getConnectionName());
        $vectorColumn = (new SearchableSchema(config('scout.pgsql', [])))->vectorColumn();

        if ($schema->hasColumn($model->getTable(), $vectorColumn)) {
            $this->warn('PostgreSQL search column ['.$vectorColumn.'] already exists for ['.$class.']; skipping preparation.');

            return;
        }

        $schema->table($model->getTable(), function ($table) use ($model) {
            $table->searchable(array_keys($model->toSearchableArray()));
        });

        $this->info('Prepared PostgreSQL search columns and indexes for ['.$class.'].');
    }
}
