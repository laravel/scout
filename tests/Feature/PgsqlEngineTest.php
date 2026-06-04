<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\PgsqlEngine;
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
        $models = SearchableUser::search()->get();

        $this->assertCount(3, $models);
        $this->assertEqualsCanonicalizing([1, 2, 3], $models->modelKeys());
        $this->assertEqualsCanonicalizing([1, 2, 3], SearchableUser::search()->keys()->all());
        $this->assertSame(3, SearchableUser::search()->paginate(1)->total());
        $this->assertCount(1, SearchableUser::search()->simplePaginate(1));
    }

    public function test_it_applies_constraints_callbacks_and_limits()
    {
        $models = SearchableUser::search()->where('email', 'taylor@laravel.com')->get();

        $this->assertCount(1, $models);
        $this->assertSame('Taylor Otwell', $models[0]->name);

        $models = SearchableUser::search()
            ->whereIn('email', ['taylor@laravel.com', 'nuno@example.com'])
            ->whereNotIn('name', ['Nuno Maduro'])
            ->get();

        $this->assertCount(1, $models);
        $this->assertSame('Taylor Otwell', $models[0]->name);

        $models = SearchableUser::search()->query(function ($query) {
            $query->where('age', '>', 30);
        })->get();

        $this->assertCount(1, $models);
        $this->assertSame('Taylor Otwell', $models[0]->name);

        $this->assertCount(1, SearchableUser::search()->take(1)->get());
    }

    public function test_explicit_ordering_takes_precedence()
    {
        $models = SearchableUser::search()->orderBy('name', 'asc')->get();

        $this->assertSame(['Abigail Otwell', 'Nuno Maduro', 'Taylor Otwell'], $models->pluck('name')->all());

        $models = SearchableUser::search()->orderBy('name', 'desc')->get();

        $this->assertSame(['Taylor Otwell', 'Nuno Maduro', 'Abigail Otwell'], $models->pluck('name')->all());
    }

    public function test_non_empty_searches_use_the_configured_vector_column_and_default_tsquery_function()
    {
        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravel'));

        $this->assertStringContainsString('"users"."search_vector" @@ plainto_tsquery(?::regconfig, ?)', $query->toSql());
        $this->assertStringContainsString('order by ts_rank("users"."search_vector", plainto_tsquery(?::regconfig, ?)) desc', $query->toSql());
        $this->assertSame(['english', 'laravel', 'english', 'laravel'], $query->getBindings());
    }

    public function test_non_empty_searches_use_the_configured_language()
    {
        $this->app->make('config')->set('scout.pgsql.language', 'simple');

        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravel'));

        $this->assertStringContainsString('plainto_tsquery(?::regconfig, ?)', $query->toSql());
        $this->assertSame(['simple', 'laravel', 'simple', 'laravel'], $query->getBindings());
    }

    public function test_non_empty_searches_can_use_websearch_queries()
    {
        $this->app->make('config')->set('scout.pgsql.query_function', 'websearch_to_tsquery');

        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravel'));

        $this->assertStringContainsString('"users"."search_vector" @@ websearch_to_tsquery(?::regconfig, ?)', $query->toSql());
        $this->assertStringContainsString('order by ts_rank("users"."search_vector", websearch_to_tsquery(?::regconfig, ?)) desc', $query->toSql());
    }

    public function test_non_empty_searches_can_use_cover_density_ranking()
    {
        $this->app->make('config')->set('scout.pgsql.rank_function', 'ts_rank_cd');

        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravel'));

        $this->assertStringContainsString('order by ts_rank_cd("users"."search_vector", plainto_tsquery(?::regconfig, ?)) desc', $query->toSql());
    }

    public function test_explicit_ordering_skips_relevance_ranking()
    {
        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravel')->orderBy('name'));

        $this->assertStringContainsString('order by "name" asc', $query->toSql());
        $this->assertStringNotContainsString('ts_rank', $query->toSql());
    }

    public function test_invalid_pgsql_search_config_fails_clearly()
    {
        $this->app->make('config')->set('scout.pgsql.rank_function', 'rank(search_vector) desc; --');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout driver rank function must be one of: ts_rank, ts_rank_cd.');

        $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravel'));
    }

    public function test_invalid_pgsql_language_fails_clearly()
    {
        $this->app->make('config')->set('scout.pgsql.language', 'english; --');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout driver language must be a valid PostgreSQL text search configuration name.');

        $this->buildPgsqlSearchQuery(SearchableUser::search('laravel'));
    }

    public function test_invalid_pgsql_query_function_fails_clearly()
    {
        $this->app->make('config')->set('scout.pgsql.query_function', 'custom_tsquery');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout driver query function must be one of: plainto_tsquery, phraseto_tsquery, websearch_to_tsquery, to_tsquery.');

        $this->buildPgsqlSearchQuery(SearchableUser::search('laravel'));
    }

    public function test_invalid_pgsql_vector_column_fails_clearly()
    {
        $this->app->make('config')->set('scout.pgsql.vector_column', 'search_vector) desc; --');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout driver vector column must be a valid column name.');

        $this->buildPgsqlSearchQuery(SearchableUser::search('laravel'));
    }

    public function test_it_applies_soft_delete_constraints()
    {
        $this->app->make('config')->set('scout.soft_delete', true);

        $active = ChirpFactory::new()->create(['content' => 'laravel scout search']);
        $deleted = ChirpFactory::new()->create(['content' => 'laravel scout search']);

        $deleted->delete();

        $this->assertCount(1, Chirp::search()->get());
        $this->assertSame($active->getKey(), Chirp::search()->first()->getKey());
        $this->assertCount(2, Chirp::search()->withTrashed()->get());
        $this->assertCount(1, Chirp::search()->onlyTrashed()->get());
        $this->assertSame($deleted->getKey(), Chirp::search()->onlyTrashed()->first()->getKey());
    }

    protected function buildPgsqlSearchQuery($builder)
    {
        return (new InspectablePgsqlEngine($this->app->make('config')->get('scout.pgsql')))->buildSearchQueryForTest($builder);
    }

    protected function buildOrderedPgsqlSearchQuery($builder)
    {
        return (new InspectablePgsqlEngine($this->app->make('config')->get('scout.pgsql')))->buildOrderedSearchQueryForTest($builder);
    }
}

class InspectablePgsqlEngine extends PgsqlEngine
{
    public function buildSearchQueryForTest(Builder $builder)
    {
        return $this->buildSearchQuery($builder);
    }

    public function buildOrderedSearchQueryForTest(Builder $builder)
    {
        return $this->orderSearchQuery($builder, $this->buildSearchQuery($builder));
    }
}

class PgsqlTestingSQLiteConnection extends SQLiteConnection
{
    public function getDriverName()
    {
        return 'pgsql';
    }
}
