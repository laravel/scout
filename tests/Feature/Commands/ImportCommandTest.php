<?php

namespace Laravel\Scout\Tests\Feature\Commands;

use Illuminate\Database\Eloquent\Model;
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
            return new ImportCommandPgsqlTestingConnection(new PDO('sqlite::memory:'), $config['database'], $config['prefix'], $config);
        });
    }

    public function test_it_can_prepare_pgsql_search_schema_before_importing()
    {
        config(['scout.driver' => 'pgsql']);

        $schema = Mockery::mock();
        $schema->shouldReceive('hasColumn')->once()->with('users', 'search_vector')->andReturn(false);
        $schema->shouldReceive('table')->once()->with('users', Mockery::on(function ($callback) {
            $table = Mockery::mock();

            $table->shouldReceive('searchable')->once()->with(['id', 'name', 'email', 'age']);

            $callback($table);

            return true;
        }));

        $this->useSchemaBuilder($schema);

        $this->artisan('scout:import', [
            'model' => ImportCommandPgsqlSearchableUser::class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput('Prepared PostgreSQL search columns and indexes for ['.ImportCommandPgsqlSearchableUser::class.'].')
            ->expectsOutput('All ['.ImportCommandPgsqlSearchableUser::class.'] records have been imported.')
            ->assertSuccessful();
    }

    public function test_it_skips_pgsql_preparation_when_search_vector_already_exists()
    {
        config(['scout.driver' => 'pgsql']);

        $schema = Mockery::mock();
        $schema->shouldReceive('hasColumn')->once()->with('users', 'search_vector')->andReturn(true);
        $schema->shouldNotReceive('table');

        $this->useSchemaBuilder($schema);

        $this->artisan('scout:import', [
            'model' => ImportCommandPgsqlSearchableUser::class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput('PostgreSQL search column [search_vector] already exists for ['.ImportCommandPgsqlSearchableUser::class.']; skipping preparation.')
            ->expectsOutput('All ['.ImportCommandPgsqlSearchableUser::class.'] records have been imported.')
            ->assertSuccessful();
    }

    public function test_prepare_pgsql_option_warns_when_driver_is_not_pgsql()
    {
        config(['scout.driver' => 'database']);

        $schema = Mockery::mock();
        $schema->shouldNotReceive('hasColumn');
        $schema->shouldNotReceive('table');

        $this->useSchemaBuilder($schema);

        $this->artisan('scout:import', [
            'model' => ImportCommandPgsqlSearchableUser::class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput('The [--prepare-pgsql] option only applies to models using the [pgsql] Scout engine.')
            ->expectsOutput('All ['.ImportCommandPgsqlSearchableUser::class.'] records have been imported.')
            ->assertSuccessful();
    }

    public function test_prepare_pgsql_option_uses_model_specific_pgsql_engine()
    {
        config(['scout.driver' => 'collection']);

        $schema = Mockery::mock();
        $schema->shouldReceive('hasColumn')->once()->with('users', 'search_vector')->andReturn(false);
        $schema->shouldReceive('table')->once()->with('users', Mockery::on(function ($callback) {
            $table = Mockery::mock();

            $table->shouldReceive('searchable')->once()->with(['id', 'name', 'email', 'age']);

            $callback($table);

            return true;
        }));

        $this->useSchemaBuilder($schema);

        $this->artisan('scout:import', [
            'model' => ImportCommandModelSpecificPgsqlSearchableUser::class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput('Prepared PostgreSQL search columns and indexes for ['.ImportCommandModelSpecificPgsqlSearchableUser::class.'].')
            ->expectsOutput('All ['.ImportCommandModelSpecificPgsqlSearchableUser::class.'] records have been imported.')
            ->assertSuccessful();
    }

    public function test_prepare_pgsql_option_skips_model_specific_non_pgsql_engine()
    {
        config(['scout.driver' => 'pgsql']);

        $schema = Mockery::mock();
        $schema->shouldNotReceive('hasColumn');
        $schema->shouldNotReceive('table');

        $this->useSchemaBuilder($schema);

        $this->artisan('scout:import', [
            'model' => ImportCommandModelSpecificCollectionSearchableUser::class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput('The [--prepare-pgsql] option only applies to models using the [pgsql] Scout engine.')
            ->expectsOutput('All ['.ImportCommandModelSpecificCollectionSearchableUser::class.'] records have been imported.')
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
