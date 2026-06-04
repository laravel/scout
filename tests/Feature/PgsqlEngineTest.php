<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use PDO;
use Workbench\App\Models\Chirp;
use Workbench\App\Models\SearchableUser;
use Workbench\Database\Factories\ChirpFactory;
use Workbench\Database\Factories\SearchableUserFactory;

class PgsqlEngineTest extends TestCase
{
    use WithWorkbench;

    protected function defineEnvironment($app)
    {
        $app->make('config')->set('scout.driver', 'pgsql');
        $app->make('config')->set('database.default', 'testing');
        $app->make('config')->set('database.connections.testing', [
            'driver' => 'testing-pgsql',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app->make('db')->extend('testing-pgsql', function ($config) {
            return new PgsqlTestingSQLiteConnection(new PDO('sqlite::memory:'), $config['database'], $config['prefix'], $config);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->defineDatabaseSchema();
        $this->seedSearchableUsers();
    }

    protected function defineDatabaseSchema()
    {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->integer('age')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('remember_token')->nullable();
            $table->timestamps();
        });

        Schema::create('chirps', function ($table) {
            $table->id();
            $table->uuid('scout_id');
            $table->text('content');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function seedSearchableUsers()
    {
        SearchableUserFactory::new()->create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'age' => 35,
        ]);

        SearchableUserFactory::new()->create([
            'name' => 'Abigail Otwell',
            'email' => 'abigail@laravel.com',
            'age' => 25,
        ]);

        SearchableUserFactory::new()->create([
            'name' => 'Nuno Maduro',
            'email' => 'nuno@example.com',
            'age' => 30,
        ]);
    }

    public function test_it_can_retrieve_results_with_empty_search_without_search_constraints()
    {
        $models = SearchableUser::search()->get();

        $this->assertCount(3, $models);

        SearchableUser::search('')->query(function ($query) {
            $this->assertStringNotContainsString('search_vector', $query->toSql());
            $this->assertStringNotContainsString('to_tsvector', $query->toSql());
            $this->assertStringNotContainsString('similarity', $query->toSql());
        })->get();
    }

    public function test_it_can_retrieve_keys_and_paginated_results()
    {
        $models = SearchableUser::search('laravel')->get();

        $this->assertCount(2, $models);
        $this->assertEqualsCanonicalizing([1, 2], $models->modelKeys());
        $this->assertEqualsCanonicalizing([1, 2], SearchableUser::search('laravel')->keys()->all());
        $this->assertSame(2, SearchableUser::search('laravel')->paginate(1)->total());
        $this->assertCount(1, SearchableUser::search('laravel')->simplePaginate(1));
    }

    public function test_it_applies_constraints_callbacks_and_limits()
    {
        $models = SearchableUser::search('laravel')->where('email', 'taylor@laravel.com')->get();

        $this->assertCount(1, $models);
        $this->assertSame('Taylor Otwell', $models[0]->name);

        $models = SearchableUser::search('laravel')
            ->whereIn('email', ['taylor@laravel.com', 'nuno@example.com'])
            ->whereNotIn('name', ['Nuno Maduro'])
            ->get();

        $this->assertCount(1, $models);
        $this->assertSame('Taylor Otwell', $models[0]->name);

        $models = SearchableUser::search('laravel')->query(function ($query) {
            $query->where('age', '>', 30);
        })->get();

        $this->assertCount(1, $models);
        $this->assertSame('Taylor Otwell', $models[0]->name);

        $this->assertCount(1, SearchableUser::search('laravel')->take(1)->get());
    }

    public function test_explicit_ordering_takes_precedence()
    {
        $models = SearchableUser::search('laravel')->orderBy('name', 'asc')->get();

        $this->assertSame(['Abigail Otwell', 'Taylor Otwell'], $models->pluck('name')->all());

        $models = SearchableUser::search('laravel')->orderBy('name', 'desc')->get();

        $this->assertSame(['Taylor Otwell', 'Abigail Otwell'], $models->pluck('name')->all());
    }

    public function test_it_applies_soft_delete_constraints()
    {
        $this->app->make('config')->set('scout.soft_delete', true);

        $active = ChirpFactory::new()->create(['content' => 'laravel scout search']);
        $deleted = ChirpFactory::new()->create(['content' => 'laravel scout search']);

        $deleted->delete();

        $this->assertCount(1, Chirp::search('laravel')->get());
        $this->assertSame($active->getKey(), Chirp::search('laravel')->first()->getKey());
        $this->assertCount(2, Chirp::search('laravel')->withTrashed()->get());
        $this->assertCount(1, Chirp::search('laravel')->onlyTrashed()->get());
        $this->assertSame($deleted->getKey(), Chirp::search('laravel')->onlyTrashed()->first()->getKey());
    }
}

class PgsqlTestingSQLiteConnection extends SQLiteConnection
{
    public function getDriverName()
    {
        return 'pgsql';
    }
}
