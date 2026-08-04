<?php

namespace Laravel\Scout\Tests\Feature\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\SQLiteConnection;
use Laravel\Scout\Scout;
use Laravel\Scout\Searchable;
use Mockery;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use PDO;

class ImportCommandTest extends TestCase
{
    use WithWorkbench;

    protected function defineEnvironment($app)
    {
        $app->make('config')->set('database.connections.pgsql_schema', [
            'driver' => 'testing-pgsql-schema',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app->make('db')->extend('testing-pgsql-schema', function ($config) {
            $connection = new ImportCommandPgsqlTestingConnection(new PDO('sqlite::memory:'), $config['database'], $config['prefix'], $config);
            $connection->useDefaultSchemaGrammar();

            return $connection;
        });
    }

    public function test_pgsql_import_warns_that_search_schema_must_be_created_with_migrations()
    {
        config(['scout.driver' => 'pgsql']);

        $class = ImportCommandPgsqlSearchableUser::class;

        $this->artisan('scout:import', [
            'model' => $class,
        ])
            ->expectsOutput('Using the [pgsql] Scout engine does not create PostgreSQL search columns or indexes.')
            ->expectsOutput('Add the PostgreSQL search vector and any trigram indexes through a migration before importing.')
            ->expectsOutput("All [{$class}] records have been imported.")
            ->assertSuccessful();
    }

    public function test_model_specific_pgsql_engine_imports_without_schema_mutation()
    {
        config(['scout.driver' => 'collection']);

        $class = ImportCommandModelSpecificPgsqlSearchableUser::class;

        $this->artisan('scout:import', [
            'model' => $class,
        ])
            ->expectsOutput('Using the [pgsql] Scout engine does not create PostgreSQL search columns or indexes.')
            ->expectsOutput('Add the PostgreSQL search vector and any trigram indexes through a migration before importing.')
            ->expectsOutput("All [{$class}] records have been imported.")
            ->assertSuccessful();
    }

    public function test_prepare_pgsql_option_creates_search_schema_from_database_columns()
    {
        config(['scout.driver' => 'pgsql']);

        $class = ImportCommandPgsqlSearchableUser::class;

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('getColumnListing')->once()->with('users')->andReturn(['id', 'name', 'email', 'age']);
        $schema->shouldReceive('hasColumn')->once()->with('users', 'search_vector')->andReturn(false);
        $schema->shouldReceive('table')->once()->with('users', Mockery::on(function ($callback) {
            $table = Mockery::mock();

            $table->shouldReceive('searchable')->once()->with(['id', 'name', 'email', 'age'], [
                'trigram' => [
                    'columns' => [],
                ],
            ]);

            $callback($table);

            return true;
        }));

        $this->useSchemaBuilder($schema);

        $this->artisan('scout:import', [
            'model' => $class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput("Prepared PostgreSQL search columns and indexes for [{$class}].")
            ->expectsOutput("All [{$class}] records have been imported.")
            ->assertSuccessful();
    }

    public function test_prepare_pgsql_option_creates_missing_indexes_when_vector_column_exists()
    {
        config(['scout.driver' => 'pgsql']);
        config(['scout.pgsql.trigram.columns' => ['name']]);

        $class = ImportCommandPgsqlSearchableUser::class;

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('getColumnListing')->once()->with('users')->andReturn(['id', 'name', 'email', 'age', 'search_vector']);
        $schema->shouldReceive('hasColumn')->once()->with('users', 'search_vector')->andReturn(true);

        if (method_exists(Builder::class, 'hasIndex')) {
            $schema->shouldReceive('hasIndex')->once()->with('users', ['search_vector'], 'gin')->andReturn(false);
            $schema->shouldReceive('hasIndex')->once()->with('users', 'users_name_trigram_index', 'gin')->andReturn(false);
        }

        $schema->shouldReceive('table')->once()->with('users', Mockery::on(function ($callback) {
            $table = Mockery::mock();
            $index = Mockery::mock();

            $table->shouldReceive('index')->once()->with('search_vector', null, 'gin');
            $table->shouldReceive('rawIndex')->once()->with('"name" gin_trgm_ops', 'users_name_trigram_index')->andReturn($index);
            $index->shouldReceive('algorithm')->once()->with('gin');

            $callback($table);

            return true;
        }));

        $this->useSchemaBuilder($schema);

        $this->artisan('scout:import', [
            'model' => $class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput("Prepared PostgreSQL search indexes for [{$class}].")
            ->expectsOutput("All [{$class}] records have been imported.")
            ->assertSuccessful();
    }

    public function test_prepare_pgsql_option_uses_model_specific_pgsql_engine()
    {
        config(['scout.driver' => 'collection']);

        $class = ImportCommandModelSpecificPgsqlSearchableUser::class;

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('getColumnListing')->once()->with('users')->andReturn(['id', 'name', 'email', 'age']);
        $schema->shouldReceive('hasColumn')->once()->with('users', 'search_vector')->andReturn(false);
        $schema->shouldReceive('table')->once()->with('users', Mockery::type('callable'));

        $this->useSchemaBuilder($schema);

        $this->artisan('scout:import', [
            'model' => $class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput("Prepared PostgreSQL search columns and indexes for [{$class}].")
            ->expectsOutput("All [{$class}] records have been imported.")
            ->assertSuccessful();
    }

    public function test_prepare_pgsql_option_warns_when_driver_is_not_pgsql()
    {
        config(['scout.driver' => 'database']);

        $class = ImportCommandPgsqlSearchableUser::class;

        $schema = Mockery::mock(Builder::class);
        $schema->shouldNotReceive('getColumnListing');
        $schema->shouldNotReceive('table');

        $this->useSchemaBuilder($schema);

        $this->artisan('scout:import', [
            'model' => $class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput('The [--prepare-pgsql] option only applies to models using the [pgsql] Scout engine.')
            ->expectsOutput("All [{$class}] records have been imported.")
            ->assertSuccessful();
    }

    public function test_model_specific_non_pgsql_engine_imports_without_pgsql_warning()
    {
        config(['scout.driver' => 'pgsql']);

        $class = ImportCommandModelSpecificCollectionSearchableUser::class;

        $this->artisan('scout:import', [
            'model' => $class,
        ])
            ->doesntExpectOutput('Using the [pgsql] Scout engine does not create PostgreSQL search columns or indexes.')
            ->doesntExpectOutput('Add the PostgreSQL search vector and any trigram indexes through a migration before importing.')
            ->expectsOutput("All [{$class}] records have been imported.")
            ->assertSuccessful();
    }

    protected function useSchemaBuilder($schema)
    {
        $this->app['db']->connection('pgsql_schema')->schemaBuilder = $schema;
    }
}

class ImportCommandPgsqlSearchableUser extends Model
{
    use Searchable;

    protected $connection = 'pgsql_schema';

    protected $table = 'users';

    public function toSearchableArray()
    {
        return [
            'id' => null,
            'name' => null,
            'email' => null,
            'age' => null,
            'profile' => null,
        ];
    }

    public static function makeAllSearchable($chunk = null)
    {
        //
    }

    public static function removeAllFromSearch()
    {
        //
    }
}

class ImportCommandModelSpecificPgsqlSearchableUser extends ImportCommandPgsqlSearchableUser
{
    public function searchableUsing()
    {
        return Scout::engine('pgsql');
    }
}

class ImportCommandModelSpecificCollectionSearchableUser extends ImportCommandPgsqlSearchableUser
{
    public function searchableUsing()
    {
        return Scout::engine('collection');
    }
}

class ImportCommandPgsqlTestingConnection extends SQLiteConnection
{
    public $schemaBuilder;

    public function getDriverName()
    {
        return 'pgsql';
    }

    public function getSchemaBuilder()
    {
        return $this->schemaBuilder ?: parent::getSchemaBuilder();
    }
}
