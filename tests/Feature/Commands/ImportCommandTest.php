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

    public function test_import_without_prepare_option_does_not_resolve_the_engine()
    {
        config(['scout.driver' => 'pgsql']);

        $class = ImportCommandUnresolvableEngineUser::class;

        $this->artisan('scout:import', [
            'model' => $class,
        ])
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

    public function test_prepare_pgsql_option_skips_existing_indexes()
    {
        config(['scout.driver' => 'pgsql']);
        config(['scout.pgsql.trigram.columns' => ['name']]);

        $class = ImportCommandPgsqlSearchableUser::class;

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('getColumnListing')->once()->with('users')->andReturn(['id', 'name', 'email', 'age', 'search_vector']);
        $schema->shouldReceive('hasColumn')->once()->with('users', 'search_vector')->andReturn(true);
        $schema->shouldNotReceive('table');

        $this->useSchemaBuilder($schema);
        $this->app['db']->connection('pgsql_schema')->ginIndexes = ['search_vector', 'name:gin_trgm_ops'];

        $this->artisan('scout:import', [
            'model' => $class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput("PostgreSQL search schema already exists for [{$class}].")
            ->expectsOutput("All [{$class}] records have been imported.")
            ->assertSuccessful();
    }

    public function test_prepare_pgsql_option_creates_trigram_index_when_column_only_has_a_plain_gin_index()
    {
        config(['scout.driver' => 'pgsql']);
        config(['scout.pgsql.trigram.columns' => ['name']]);

        $class = ImportCommandPgsqlSearchableUser::class;

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('getColumnListing')->once()->with('users')->andReturn(['id', 'name', 'search_vector']);
        $schema->shouldReceive('hasColumn')->once()->with('users', 'search_vector')->andReturn(true);
        $schema->shouldReceive('table')->once()->with('users', Mockery::on(function ($callback) {
            $table = Mockery::mock();
            $index = Mockery::mock();

            $table->shouldNotReceive('index');
            $table->shouldReceive('rawIndex')->once()->with('"name" gin_trgm_ops', 'users_name_trigram_index')->andReturn($index);
            $index->shouldReceive('algorithm')->once()->with('gin');

            $callback($table);

            return true;
        }));

        $this->useSchemaBuilder($schema);
        $this->app['db']->connection('pgsql_schema')->ginIndexes = ['search_vector', 'name'];

        $this->artisan('scout:import', [
            'model' => $class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput("Prepared PostgreSQL search indexes for [{$class}].")
            ->assertSuccessful();
    }

    public function test_prepare_pgsql_option_creates_trigram_extension_when_indexes_exist()
    {
        config(['scout.driver' => 'pgsql']);
        config(['scout.pgsql.trigram.columns' => ['name']]);
        config(['scout.pgsql.trigram.create_extension' => true]);

        $class = ImportCommandPgsqlSearchableUser::class;

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('getColumnListing')->once()->with('users')->andReturn(['id', 'name', 'search_vector']);
        $schema->shouldReceive('hasColumn')->once()->with('users', 'search_vector')->andReturn(true);
        $schema->shouldReceive('table')->once()->with('users', Mockery::on(function ($callback) {
            $table = Mockery::mock();

            $table->shouldReceive('addCommand')->once()->with('scoutPgsqlExtension', ['extension' => 'pg_trgm']);
            $table->shouldNotReceive('index');
            $table->shouldNotReceive('rawIndex');

            $callback($table);

            return true;
        }));

        $this->useSchemaBuilder($schema);
        $this->app['db']->connection('pgsql_schema')->ginIndexes = ['search_vector', 'name:gin_trgm_ops'];

        $this->artisan('scout:import', [
            'model' => $class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput("Prepared PostgreSQL search indexes for [{$class}].")
            ->assertSuccessful();
    }

    public function test_prepare_pgsql_option_skips_date_columns()
    {
        config(['scout.driver' => 'pgsql']);

        $class = ImportCommandPgsqlSearchableUserWithDates::class;

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('getColumnListing')->once()->with('users')->andReturn(['id', 'name', 'birthday', 'created_at']);
        $schema->shouldReceive('hasColumn')->once()->with('users', 'search_vector')->andReturn(false);
        $schema->shouldReceive('table')->once()->with('users', Mockery::on(function ($callback) {
            $table = Mockery::mock();

            $table->shouldReceive('searchable')->once()->with(['id', 'name'], [
                'trigram' => [
                    'columns' => [],
                ],
            ]);

            $callback($table);

            return true;
        }));

        $this->useSchemaBuilder($schema);
        $this->app['db']->connection('pgsql_schema')->nonImmutableTextColumns = ['birthday', 'created_at'];

        $this->artisan('scout:import', [
            'model' => $class,
            '--prepare-pgsql' => true,
        ])->assertSuccessful();
    }

    public function test_prepare_pgsql_option_discovers_default_searchable_array_from_a_stored_record()
    {
        config(['scout.driver' => 'pgsql']);

        $class = ImportCommandPgsqlDefaultSearchableUser::class;

        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('getColumnListing')->once()->with('users')->andReturn(['id', 'name', 'email']);
        $schema->shouldReceive('hasColumn')->once()->with('users', 'search_vector')->andReturn(false);
        $schema->shouldReceive('table')->once()->with('users', Mockery::on(function ($callback) {
            $table = Mockery::mock();

            $table->shouldReceive('searchable')->once()->with(['id', 'name', 'email'], [
                'trigram' => [
                    'columns' => [],
                ],
            ]);

            $callback($table);

            return true;
        }));

        $this->useSchemaBuilder($schema);
        $this->app['db']->connection('pgsql_schema')->rows = [(object) ['id' => 1, 'name' => 'Taylor', 'email' => 'taylor@laravel.com']];

        $this->artisan('scout:import', [
            'model' => $class,
            '--prepare-pgsql' => true,
        ])
            ->expectsOutput("Prepared PostgreSQL search columns and indexes for [{$class}].")
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

class ImportCommandPgsqlSearchableUserWithDates extends ImportCommandPgsqlSearchableUser
{
    public function toSearchableArray()
    {
        return [
            'id' => null,
            'name' => null,
            'birthday' => null,
            'created_at' => null,
        ];
    }
}

class ImportCommandPgsqlDefaultSearchableUser extends ImportCommandPgsqlSearchableUser
{
    public function toSearchableArray()
    {
        return $this->toArray();
    }
}

class ImportCommandUnresolvableEngineUser extends ImportCommandPgsqlSearchableUser
{
    public function searchableUsing()
    {
        throw new \RuntimeException('The engine should not be resolved.');
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

    public $ginIndexes = [];

    public $nonImmutableTextColumns = [];

    public $rows = [];

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        if (str_contains($query, 'pg_attribute')) {
            return array_map(fn ($column) => (object) ['attname' => $column], $this->nonImmutableTextColumns);
        }

        if (str_contains($query, 'from "users"')) {
            return $this->rows;
        }

        return parent::select($query, $bindings, $useReadPdo, $fetchUsing);
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true)
    {
        if (str_contains($query, 'pg_index')) {
            $index = isset($bindings[2]) ? "{$bindings[1]}:{$bindings[2]}" : $bindings[1];

            return (object) ['exists' => in_array($index, $this->ginIndexes, true)
                || (! isset($bindings[2]) && in_array("{$bindings[1]}:gin_trgm_ops", $this->ginIndexes, true))];
        }

        return parent::selectOne($query, $bindings, $useReadPdo);
    }

    public function getDriverName()
    {
        return 'pgsql';
    }

    public function getSchemaBuilder()
    {
        return $this->schemaBuilder ?: parent::getSchemaBuilder();
    }
}
