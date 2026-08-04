<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
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

        $namespace = app()->getNamespace();

        if (! class_exists($class) && ! class_exists($class = "{$namespace}Models\\{$class}")) {
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

            $this->line("<comment>Imported [{$class}] models up to ID:</comment> {$key}");
        });

        if ($this->option('fresh')) {
            $model::removeAllFromSearch();
        }

        $model::makeAllSearchable($this->option('chunk'));

        $events->forget(ModelsImported::class);

        $this->info("All [{$class}] records have been imported.");
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
        $connection = $model->getConnection();
        $schema = $connection->getSchemaBuilder();
        $helper = new SearchableSchema(config('scout.pgsql', []));
        $table = $model->getTable();
        $vectorColumn = $helper->vectorColumn();
        $columns = $this->searchableDatabaseColumns($model, $schema, $vectorColumn, $class);
        $trigramColumns = $this->trigramDatabaseColumns($helper, $columns);

        if (! $schema->hasColumn($table, $vectorColumn)) {
            $schema->table($table, function ($table) use ($columns, $trigramColumns) {
                $table->searchable($columns, [
                    'trigram' => [
                        'columns' => $trigramColumns,
                    ],
                ]);
            });

            $this->info("Prepared PostgreSQL search columns and indexes for [{$class}].");

            return;
        }

        $createdExtension = false;

        if ($helper->shouldCreateTrigramExtension() && ! empty($trigramColumns)) {
            $connection->statement('create extension if not exists "pg_trgm"');
            $createdExtension = true;
        }

        if ($this->preparePgsqlIndexes($connection, $schema, $helper, $table, $vectorColumn, $trigramColumns) || $createdExtension) {
            $this->info("Prepared PostgreSQL search indexes for [{$class}].");

            return;
        }

        $this->warn("PostgreSQL search schema already exists for [{$class}].");
    }

    /**
     * Get searchable payload keys that are backed by real database columns.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  \Illuminate\Database\Schema\Builder  $schema
     * @param  string  $vectorColumn
     * @param  class-string  $class
     * @return array
     */
    protected function searchableDatabaseColumns($model, $schema, $vectorColumn, $class)
    {
        $databaseColumns = array_flip($schema->getColumnListing($model->getTable()));

        $columns = array_values(array_filter(array_keys($model->toSearchableArray()), function ($column) use ($databaseColumns, $vectorColumn) {
            return $column !== $vectorColumn && array_key_exists($column, $databaseColumns);
        }));

        if (empty($columns)) {
            $table = $model->getTable();

            throw new ScoutException("No database columns from [{$class}::toSearchableArray()] exist on [{$table}].");
        }

        return $columns;
    }

    /**
     * Get configured trigram columns that can be indexed by the database.
     *
     * @param  \Laravel\Scout\Pgsql\SearchableSchema  $helper
     * @param  array  $columns
     * @return array
     */
    protected function trigramDatabaseColumns(SearchableSchema $helper, array $columns)
    {
        $searchableColumns = array_flip($columns);

        return array_values(array_filter($helper->trigramColumns(), fn ($column) => array_key_exists($column, $searchableColumns)));
    }

    /**
     * Prepare missing PostgreSQL indexes for an existing vector column.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  \Illuminate\Database\Schema\Builder  $schema
     * @param  \Laravel\Scout\Pgsql\SearchableSchema  $helper
     * @param  string  $table
     * @param  string  $vectorColumn
     * @param  array  $trigramColumns
     * @return bool
     */
    protected function preparePgsqlIndexes($connection, $schema, SearchableSchema $helper, $table, $vectorColumn, array $trigramColumns)
    {
        $missingVectorIndex = ! $this->schemaHasIndex($schema, $table, [$vectorColumn], 'gin');
        $missingTrigramColumns = array_values(array_filter($trigramColumns, function ($column) use ($connection, $schema, $helper, $table) {
            return ! $this->schemaHasIndex($schema, $table, $helper->indexName($connection, $table, [$column], 'trigram_index'), 'gin');
        }));

        if (! $missingVectorIndex && empty($missingTrigramColumns)) {
            return false;
        }

        $tableName = $table;

        $schema->table($table, function ($table) use ($connection, $helper, $tableName, $vectorColumn, $missingVectorIndex, $missingTrigramColumns) {
            if ($missingVectorIndex) {
                $table->index($vectorColumn, null, 'gin');
            }

            foreach ($missingTrigramColumns as $column) {
                $table->rawIndex(
                    sprintf('%s gin_trgm_ops', $connection->getSchemaGrammar()->wrap($column)),
                    $helper->indexName($connection, $tableName, [$column], 'trigram_index')
                )->algorithm('gin');
            }
        });

        return true;
    }

    /**
     * Determine if the schema builder can find the given index.
     *
     * @param  \Illuminate\Database\Schema\Builder  $schema
     * @param  string  $table
     * @param  string|array  $index
     * @param  string|null  $type
     * @return bool
     */
    protected function schemaHasIndex($schema, $table, $index, $type = null)
    {
        return method_exists($schema, 'hasIndex') && $schema->hasIndex($table, $index, $type);
    }
}
