<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\PgsqlEngine;
use Laravel\Scout\Pgsql\Trigram;
use Laravel\Scout\Searchable;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use PDO;
use Workbench\App\Models\Bookmark;
use Workbench\App\Models\Chirp;
use Workbench\App\Models\SearchableUser;
use Workbench\Database\Factories\BookmarkFactory;
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

        Schema::create('bookmarks', function ($table) {
            $table->id();
            $table->foreignId('chirp_id');
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('external_documents', function ($table) {
            $table->id();
            $table->integer('external_id');
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('string_documents', function ($table) {
            $table->id();
            $table->string('code');
            $table->string('title');
            $table->timestamps();
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

    public function test_keys_returns_scout_keys_for_database_backed_engines()
    {
        PgsqlIntegerScoutKeyDocument::query()->create([
            'external_id' => 1001,
            'title' => 'First document',
        ]);
        PgsqlIntegerScoutKeyDocument::query()->create([
            'external_id' => 1002,
            'title' => 'Second document',
        ]);

        $this->assertSame([1002, 1001], PgsqlIntegerScoutKeyDocument::search()->keys()->all());
    }

    public function test_numeric_search_checks_integer_scout_key_column()
    {
        $query = $this->buildPgsqlSearchQuery(PgsqlIntegerScoutKeyDocument::search('1001'));

        $this->assertStringContainsString('"external_documents"."external_id" = ?', $query->toSql());
        $this->assertStringNotContainsString('"external_documents"."id" = ?', $query->toSql());
    }

    public function test_numeric_search_does_not_use_exact_key_optimization_for_non_integer_scout_keys()
    {
        $query = $this->buildPgsqlSearchQuery(PgsqlStringScoutKeyDocument::search('123'));

        $this->assertStringNotContainsString('"string_documents"."code" = ?', $query->toSql());
        $this->assertStringNotContainsString('"string_documents"."id" = ?', $query->toSql());
    }

    public function test_it_uses_custom_page_names_for_database_pagination()
    {
        $models = SearchableUser::search()->paginate(1, 'users_page', 2);

        $this->assertSame(3, $models->total());
        $this->assertSame(2, $models->currentPage());
        $this->assertStringContainsString('users_page=1', $models->url(1));

        $models = SearchableUser::search()->simplePaginate(1, 'simple_users_page', 2);

        $this->assertSame(2, $models->currentPage());
        $this->assertStringContainsString('simple_users_page=1', $models->url(1));
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

        $models = SearchableUser::search()->tap(function ($builder) {
            $builder->take(1);
        })->get();

        $this->assertCount(1, $models);

        $this->assertCount(1, SearchableUser::search()->take(1)->get());
    }

    public function test_explicit_ordering_takes_precedence()
    {
        $models = SearchableUser::search()->orderBy('name', 'asc')->get();

        $this->assertSame(['Abigail Otwell', 'Nuno Maduro', 'Taylor Otwell'], $models->pluck('name')->all());

        $models = SearchableUser::search()->orderBy('name', 'desc')->get();

        $this->assertSame(['Taylor Otwell', 'Nuno Maduro', 'Abigail Otwell'], $models->pluck('name')->all());

        $models = SearchableUser::search()->orderByDesc('name')->get();

        $this->assertSame(['Taylor Otwell', 'Nuno Maduro', 'Abigail Otwell'], $models->pluck('name')->all());
    }

    public function test_it_starts_from_custom_scout_queries()
    {
        BookmarkFactory::new()
            ->for(ChirpFactory::new()->create(['content' => 'This chirp is searchable']))
            ->create([
                'label' => 'laravel',
            ]);

        $query = $this->buildPgsqlSearchQuery(Bookmark::search('chirp'));

        $this->assertStringContainsString('inner join "chirps" on "chirps"."id" = "bookmarks"."chirp_id"', $query->toSql());
        $this->assertStringContainsString('"bookmarks"."search_vector" @@ plainto_tsquery(?::regconfig, ?)', $query->toSql());
    }

    public function test_non_empty_searches_use_the_configured_vector_column_and_default_tsquery_function()
    {
        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravel'));

        $this->assertStringContainsString('"users"."search_vector" @@ plainto_tsquery(?::regconfig, ?)', $query->toSql());
        $this->assertStringContainsString('order by ts_rank("users"."search_vector", plainto_tsquery(?::regconfig, ?)) desc', $query->toSql());
        $this->assertSame(['english', 'laravel', 'english', 'laravel'], $query->getBindings());
    }

    public function test_non_empty_searches_use_the_configured_vector_column()
    {
        $this->app->make('config')->set('scout.pgsql.vector_column', 'document_vector');

        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravel'));

        $this->assertStringContainsString('"users"."document_vector" @@ plainto_tsquery(?::regconfig, ?)', $query->toSql());
        $this->assertStringContainsString('order by ts_rank("users"."document_vector", plainto_tsquery(?::regconfig, ?)) desc', $query->toSql());
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

    public function test_schema_qualified_pgsql_vector_column_fails_clearly()
    {
        $this->app->make('config')->set('scout.pgsql.vector_column', 'users.search_vector');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout driver vector column must be a valid column name.');

        $this->buildPgsqlSearchQuery(SearchableUser::search('laravel'));
    }

    public function test_trigram_similarity_can_match_when_full_text_does_not()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name', 'email']);

        $engine = $this->pgsqlEngine(true);
        $query = $engine->buildOrderedSearchQueryForTest(SearchableUser::search('laravle'));

        $this->assertStringContainsString('"users"."search_vector" @@ plainto_tsquery(?::regconfig, ?)', $query->toSql());
        $this->assertStringContainsString('("users"."name" % ? or "users"."email" % ?)', $query->toSql());
        $this->assertStringNotContainsString('set_config(\'pg_trgm.similarity_threshold\'', $query->toSql());
        $this->assertStringContainsString('similarity(coalesce(cast("users"."name" as text), \'\'), ?)', $query->toSql());
        $this->assertStringContainsString('similarity(coalesce(cast("users"."email" as text), \'\'), ?)', $query->toSql());
        $this->assertSame([
            'english', 'laravle', 'laravle', 'laravle',
            'english', 'laravle', 1.0, 'laravle', 'laravle', 0.25,
        ], $query->getBindings());
        $this->assertSame([], $engine->appliedThresholds());
    }

    public function test_trigram_threshold_is_not_applied_while_building_the_query()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.threshold', 0.15);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name']);

        $engine = $this->pgsqlEngine(true);
        $query = $engine->buildOrderedSearchQueryForTest(SearchableUser::search('laravle'));

        $this->assertStringContainsString('("users"."name" % ?)', $query->toSql());
        $this->assertSame([], $engine->appliedThresholds());
        $this->assertSame([
            'english', 'laravle', 'laravle',
            'english', 'laravle', 1.0, 'laravle', 0.25,
        ], $query->getBindings());
    }

    public function test_trigram_search_uses_configured_columns_only()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name']);

        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravle'), true);

        $this->assertStringContainsString('"users"."name" % ?', $query->toSql());
        $this->assertStringContainsString('similarity(coalesce(cast("users"."name" as text), \'\'), ?)', $query->toSql());
        $this->assertStringNotContainsString('"users"."id" % ?', $query->toSql());
        $this->assertStringNotContainsString('"users"."email" % ?', $query->toSql());
        $this->assertStringNotContainsString('"users"."age" % ?', $query->toSql());
        $this->assertStringNotContainsString('similarity(coalesce(cast("users"."id" as text)', $query->toSql());
        $this->assertStringNotContainsString('similarity(coalesce(cast("users"."email" as text)', $query->toSql());
        $this->assertStringNotContainsString('similarity(coalesce(cast("users"."age" as text)', $query->toSql());
    }

    public function test_trigram_search_rejects_schema_qualified_columns()
    {
        $_ENV['user.toSearchableArray'] = fn () => [
            'users.name' => 'Taylor Otwell',
        ];

        try {
            $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
            $this->app->make('config')->set('scout.pgsql.trigram.columns', ['users.name']);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('The [pgsql] Scout driver trigram column [users.name] must be a valid column name.');

            $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravle'), true);
        } finally {
            unset($_ENV['user.toSearchableArray']);
        }
    }

    public function test_trigram_behavior_is_skipped_when_disabled()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['invalid column']);

        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravle'), true);

        $this->assertStringNotContainsString('similarity(', $query->toSql());
        $this->assertStringNotContainsString(' % ?', $query->toSql());
        $this->assertStringContainsString('order by ts_rank("users"."search_vector", plainto_tsquery(?::regconfig, ?)) desc', $query->toSql());
    }

    public function test_trigram_behavior_is_skipped_without_configured_columns()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);

        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravle'), true);

        $this->assertStringContainsString('"users"."search_vector" @@ plainto_tsquery(?::regconfig, ?)', $query->toSql());
        $this->assertStringNotContainsString('similarity(', $query->toSql());
        $this->assertStringNotContainsString(' % ?', $query->toSql());
        $this->assertStringContainsString('order by ts_rank("users"."search_vector", plainto_tsquery(?::regconfig, ?)) desc', $query->toSql());
        $this->assertSame(['english', 'laravle', 'english', 'laravle'], $query->getBindings());
    }

    public function test_missing_trigram_extension_falls_back_to_full_text_search()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name']);

        Log::shouldReceive('warning')->once()->with('Scout [pgsql] trigram search is enabled, but the [pg_trgm] extension is not available. Falling back to PostgreSQL full-text search.');

        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravle'));

        $this->assertStringContainsString('"users"."search_vector" @@ plainto_tsquery(?::regconfig, ?)', $query->toSql());
        $this->assertStringNotContainsString('similarity(', $query->toSql());
        $this->assertStringNotContainsString(' % ?', $query->toSql());
        $this->assertSame(['english', 'laravle', 'english', 'laravle'], $query->getBindings());
    }

    public function test_missing_trigram_extension_warning_is_emitted_once_per_connection()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name']);

        Log::shouldReceive('warning')->once()->with('Scout [pgsql] trigram search is enabled, but the [pg_trgm] extension is not available. Falling back to PostgreSQL full-text search.');

        $engine = $this->pgsqlEngine();

        $engine->buildOrderedSearchQueryForTest(SearchableUser::search('laravle'));
        $engine->buildOrderedSearchQueryForTest(SearchableUser::search('laravle'));
    }

    public function test_trigram_ranking_blends_full_text_rank_and_similarity()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name', 'email']);
        $this->app->make('config')->set('scout.pgsql.weights.full_text', 1.5);
        $this->app->make('config')->set('scout.pgsql.weights.trigram', 0.5);

        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravle'), true);

        $this->assertStringContainsString('order by ((ts_rank("users"."search_vector", plainto_tsquery(?::regconfig, ?)) * ?) + (greatest(similarity(coalesce(cast("users"."name" as text), \'\'), ?), similarity(coalesce(cast("users"."email" as text), \'\'), ?)) * ?)) desc', $query->toSql());
        $this->assertSame([
            'english', 'laravle', 'laravle', 'laravle',
            'english', 'laravle', 1.5, 'laravle', 'laravle', 0.5,
        ], $query->getBindings());
    }

    public function test_invalid_trigram_threshold_fails_clearly()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name']);
        $this->app->make('config')->set('scout.pgsql.trigram.threshold', 'invalid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout driver trigram threshold must be numeric and between 0 and 1.');

        $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravle'), true);
    }

    public function test_trigram_threshold_accepts_zero()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name']);
        $this->app->make('config')->set('scout.pgsql.trigram.threshold', 0);

        $engine = $this->pgsqlEngine(true);

        $engine->buildOrderedSearchQueryForTest(SearchableUser::search('laravle'));

        $this->assertSame([], $engine->appliedThresholds());
    }

    public function test_trigram_threshold_accepts_one()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name']);
        $this->app->make('config')->set('scout.pgsql.trigram.threshold', 1);

        $engine = $this->pgsqlEngine(true);

        $engine->buildOrderedSearchQueryForTest(SearchableUser::search('laravle'));

        $this->assertSame([], $engine->appliedThresholds());
    }

    public function test_trigram_threshold_rejects_values_below_zero()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name']);
        $this->app->make('config')->set('scout.pgsql.trigram.threshold', -0.1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout driver trigram threshold must be numeric and between 0 and 1.');

        $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravle'), true);
    }

    public function test_trigram_threshold_rejects_values_above_one()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name']);
        $this->app->make('config')->set('scout.pgsql.trigram.threshold', 1.1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout driver trigram threshold must be numeric and between 0 and 1.');

        $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravle'), true);
    }

    public function test_invalid_full_text_score_weight_fails_clearly()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name']);
        $this->app->make('config')->set('scout.pgsql.weights.full_text', 'invalid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout driver score weight [full_text] must be numeric.');

        $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravle'), true);
    }

    public function test_invalid_trigram_score_weight_fails_clearly()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name']);
        $this->app->make('config')->set('scout.pgsql.weights.trigram', 'invalid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout driver score weight [trigram] must be numeric.');

        $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravle'), true);
    }

    public function test_explicit_ordering_overrides_blended_relevance_ranking()
    {
        $this->app->make('config')->set('scout.pgsql.trigram.enabled', true);
        $this->app->make('config')->set('scout.pgsql.trigram.columns', ['name']);

        $query = $this->buildOrderedPgsqlSearchQuery(SearchableUser::search('laravle')->orderBy('name'), true);

        $this->assertStringContainsString('"users"."name" % ?', $query->toSql());
        $this->assertStringContainsString('order by "name" asc', $query->toSql());
        $this->assertStringNotContainsString('ts_rank', $query->toSql());
        $this->assertStringNotContainsString('similarity(', $query->toSql());
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

    protected function buildOrderedPgsqlSearchQuery($builder, $trigramAvailable = null)
    {
        return $this->pgsqlEngine($trigramAvailable)->buildOrderedSearchQueryForTest($builder);
    }

    protected function pgsqlEngine($trigramAvailable = null)
    {
        return new InspectablePgsqlEngine($this->app->make('config')->get('scout.pgsql'), $trigramAvailable);
    }
}

class InspectablePgsqlEngine extends PgsqlEngine
{
    protected array $appliedThresholds = [];

    public function __construct(array $config, protected $trigramAvailable = null)
    {
        parent::__construct($config);
    }

    public function buildSearchQueryForTest(Builder $builder)
    {
        return $this->buildSearchQuery($builder);
    }

    public function buildOrderedSearchQueryForTest(Builder $builder)
    {
        return $this->orderSearchQuery($builder, $this->buildSearchQuery($builder));
    }

    public function appliedThresholds()
    {
        return $this->appliedThresholds;
    }

    public function recordAppliedThreshold($threshold)
    {
        $this->appliedThresholds[] = $threshold;
    }

    protected function trigram()
    {
        if (is_null($this->trigramAvailable)) {
            return parent::trigram();
        }

        return $this->trigram ??= new class($this->config, $this->trigramAvailable, $this) extends Trigram
        {
            public function __construct(array $config, protected bool $available, protected InspectablePgsqlEngine $engine)
            {
                parent::__construct($config);
            }

            public function available(Builder $builder)
            {
                return $this->available;
            }

            public function applyThreshold(Builder $builder)
            {
                $this->engine->recordAppliedThreshold($this->threshold());
            }
        };
    }
}

class PgsqlIntegerScoutKeyDocument extends Model
{
    use Searchable;

    protected $guarded = [];

    protected $casts = [
        'external_id' => 'integer',
    ];

    protected $table = 'external_documents';

    public function getScoutKey()
    {
        return $this->external_id;
    }

    public function getScoutKeyName()
    {
        return 'external_id';
    }

    public function getScoutKeyType()
    {
        return 'int';
    }

    public function toSearchableArray()
    {
        return [
            'external_id' => $this->external_id,
            'title' => $this->title,
        ];
    }
}

class PgsqlStringScoutKeyDocument extends Model
{
    use Searchable;

    protected $guarded = [];

    protected $table = 'string_documents';

    public function getScoutKey()
    {
        return $this->code;
    }

    public function getScoutKeyName()
    {
        return 'code';
    }

    public function getScoutKeyType()
    {
        return 'string';
    }

    public function toSearchableArray()
    {
        return [
            'code' => $this->code,
            'title' => $this->title,
        ];
    }
}

class PgsqlTestingSQLiteConnection extends SQLiteConnection
{
    public function getDriverName()
    {
        return 'pgsql';
    }
}
