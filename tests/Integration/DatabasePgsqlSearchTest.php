<?php

namespace Laravel\Scout\Tests\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Searchable;
use Orchestra\Testbench\Attributes\RequiresEnv;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * @group pgsql
 */
#[Group('pgsql')]
#[RequiresEnv('PGSQL_TEST_DATABASE')]
class DatabasePgsqlSearchTest extends TestCase
{
    use WithWorkbench;

    protected function defineEnvironment($app)
    {
        $app['config']->set('scout.driver', 'database');
        $app['config']->set('database.default', 'pgsql_testing');
        $app['config']->set('database.connections.pgsql_testing', [
            'driver' => 'pgsql',
            'host' => env('PGSQL_TEST_HOST', '127.0.0.1'),
            'port' => env('PGSQL_TEST_PORT', 5432),
            'database' => env('PGSQL_TEST_DATABASE'),
            'username' => env('PGSQL_TEST_USERNAME', 'postgres'),
            'password' => env('PGSQL_TEST_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => 'prefer',
        ]);
        $app['config']->set('database.connections.pgsql_prefixed', array_merge(
            $app['config']->get('database.connections.pgsql_testing'), ['prefix' => 'pre_', 'prefix_indexes' => true]
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('create extension if not exists pg_trgm');

        Schema::dropIfExists('scout_posts');
        Schema::create('scout_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->searchable(['title' => 'A', 'body' => 'D'], 'english', ['title']);
        });
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            config(['database.default' => 'pgsql_testing']);

            Schema::dropIfExists('scout_posts');
            Schema::connection('pgsql_prefixed')->dropIfExists('scout_posts');
            DB::statement('drop schema if exists scout_tenant cascade');
        }

        parent::tearDown();
    }

    public function test_searchable_creates_generated_vector_and_gin_indexes()
    {
        $this->assertSame(
            ['scout_posts_search_vector_index' => 'CREATE INDEX scout_posts_search_vector_index ON public.scout_posts USING gin (search_vector)',
                'scout_posts_title_trigram' => 'CREATE INDEX scout_posts_title_trigram ON public.scout_posts USING gin (title gin_trgm_ops)'],
            $this->indexes()
        );

        Schema::table('scout_posts', fn (Blueprint $table) => $table->dropSearchable(['title']));

        $this->assertSame([], $this->indexes());
        $this->assertFalse(Schema::hasColumn('scout_posts', 'search_vector'));
    }

    public function test_searchable_can_be_added_to_an_existing_table()
    {
        Schema::table('scout_posts', fn (Blueprint $table) => $table->dropSearchable(['title']));
        PgsqlPost::create(['title' => 'Laravel Scout']);

        Schema::table('scout_posts', fn (Blueprint $table) => $table->searchable(['title', 'body']));

        $this->assertSame(['scout_posts_search_vector_index'], array_keys($this->indexes()));
        $this->assertSame(['Laravel Scout'], PgsqlPost::search('scout')->get()->pluck('title')->all());
    }

    public function test_searchable_creates_the_trigram_extension()
    {
        DB::statement('drop extension pg_trgm cascade');
        Schema::table('scout_posts', fn (Blueprint $table) => $table->dropSearchable());

        try {
            Schema::table('scout_posts', fn (Blueprint $table) => $table->searchable(['title'], 'english', ['title']));

            $this->assertTrue(DB::selectOne("select exists(select 1 from pg_extension where extname = 'pg_trgm') as installed")->installed);
        } finally {
            DB::statement('create extension if not exists pg_trgm');
        }
    }

    public function test_weighted_vector_search_ranks_title_matches_first_and_matches_null_columns()
    {
        PgsqlPost::create(['title' => 'Queues', 'body' => 'Scout keeps search indexes in sync']);
        PgsqlPost::create(['title' => 'Laravel Scout', 'body' => null]);
        PgsqlPost::create(['title' => 'Horizon', 'body' => 'Queue dashboard']);

        $this->assertSame(['Laravel Scout', 'Queues'], PgsqlPost::search('scout')->get()->pluck('title')->all());
    }

    public function test_query_modes()
    {
        PgsqlPost::create(['title' => 'Shah Rukh Khan', 'body' => 'Romance']);
        PgsqlPost::create(['title' => 'Salman Khan', 'body' => 'Action']);

        $this->assertSame(['Shah Rukh Khan'], PgsqlWebsearchPost::search('khan -salman')->get()->pluck('title')->all());
        $this->assertSame(['Shah Rukh Khan'], PgsqlPhrasePost::search('rukh khan')->get()->pluck('title')->all());
        $this->assertSame([], PgsqlPhrasePost::search('khan rukh')->get()->all());
    }

    public function test_per_query_mode_overrides_the_model_mode_and_skips_the_trigram_fallback()
    {
        PgsqlPost::create(['title' => 'Shah Rukh Khan', 'body' => 'Romance']);
        PgsqlPost::create(['title' => 'Salman Khan', 'body' => 'Action']);

        $this->assertSame(['Shah Rukh Khan'], PgsqlPost::search('khan -salman')->options(['mode' => 'websearch'])->get()->pluck('title')->all());
        $this->assertSame([], PgsqlPost::search('khan rukh')->options(['mode' => 'phrase'])->get()->all());
        $this->assertSame(['Salman Khan'], PgsqlPhrasePost::search('salman')->options(['mode' => 'plain'])->get()->pluck('title')->all());
        $this->assertSame([], PgsqlPost::search('salman khn')->options(['mode' => 'websearch'])->get()->all());
        $this->assertSame(['Salman Khan'], PgsqlPost::search('salman khn')->get()->pluck('title')->all());
        $this->assertCount(2, PgsqlPost::search('khan')->options(['rank' => 'pg_sleep(1) + ts_rank'])->get());
    }

    public function test_equally_ranked_results_paginate_without_duplicates()
    {
        foreach (range(1, 5) as $i) {
            PgsqlPost::create(['title' => 'Same title']);
        }

        $ids = collect(range(1, 5))->flatMap(
            fn ($page) => PgsqlPost::search('same')->paginate(1, 'page', $page)->pluck('id')
        );

        $this->assertSame([5, 4, 3, 2, 1], $ids->all());
    }

    public function test_trigram_fallback_runs_only_when_full_text_finds_nothing()
    {
        PgsqlPost::create(['title' => 'The Godfather']);
        PgsqlPost::create(['title' => 'Godzilla']);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->assertSame(['The Godfather'], PgsqlPost::search('godfather')->get()->pluck('title')->all());
        $this->assertCount(0, array_filter($queries, fn ($sql) => str_contains($sql, '% ?')));

        $this->assertSame(['The Godfather'], PgsqlPost::search('godfathr')->get()->pluck('title')->all());

        $page = PgsqlPost::search('godfathr')->paginate(10);
        $this->assertSame(1, $page->total());
        $this->assertSame(['The Godfather'], $page->pluck('title')->all());

        $this->assertSame(['The Godfather'], PgsqlPost::search('godfathr')->simplePaginate(10)->pluck('title')->all());
        $this->assertSame([], PgsqlPost::search('nothing like it')->get()->all());
    }

    public function test_search_without_a_vector_column_uses_expression_ranking()
    {
        PgsqlExpressionPost::create(['title' => 'Queues', 'body' => 'Scout keeps search indexes in sync']);
        PgsqlExpressionPost::create(['title' => 'Laravel Scout', 'body' => 'Scout docs']);
        PgsqlExpressionPost::create(['title' => 'Horizon', 'body' => 'Queue dashboard']);

        $this->assertSame(['Laravel Scout', 'Queues'], PgsqlExpressionPost::search('scout')->get()->pluck('title')->all());
    }

    public function test_boolean_vector_option_searches_tsvector_columns_directly()
    {
        PgsqlBooleanVectorPost::create(['title' => 'Queues', 'body' => 'Scout keeps search indexes in sync']);
        PgsqlBooleanVectorPost::create(['title' => 'Laravel Scout', 'body' => null]);
        PgsqlBooleanVectorPost::create(['title' => 'Horizon', 'body' => 'Queue dashboard']);

        $this->assertSame(['Laravel Scout', 'Queues'], PgsqlBooleanVectorPost::search('scout')->get()->pluck('title')->all());
        $this->assertSame(['Laravel Scout', 'Queues'], PgsqlBooleanVectorPost::search('scout')->orderBy('id', 'desc')->get()->pluck('title')->all());
    }

    public function test_soft_deleted_and_explicitly_ordered_searches()
    {
        PgsqlPost::create(['title' => 'Scout A', 'body' => 'scout scout scout']);
        PgsqlPost::create(['title' => 'Scout B'])->delete();
        PgsqlPost::create(['title' => 'Scout C']);

        $this->assertSame(['Scout A', 'Scout C'], PgsqlPost::search('scout')->get()->pluck('title')->all());
        $this->assertSame(['Scout B'], PgsqlPost::search('scout')->onlyTrashed()->get()->pluck('title')->all());
        $this->assertSame(['Scout C', 'Scout A'], PgsqlPost::search('scout')->orderBy('title', 'desc')->get()->pluck('title')->all());
    }

    public function test_schema_qualified_tables()
    {
        DB::statement('create schema scout_tenant');

        $this->assertSearchableTableWorks(Schema::connection('pgsql_testing'), 'scout_tenant.scout_posts', new PgsqlTenantPost);
    }

    public function test_prefixed_tables_on_a_named_connection()
    {
        // Any accidental use of the default connection now fails...
        config(['database.default' => 'missing']);

        $this->assertSearchableTableWorks(Schema::connection('pgsql_prefixed'), 'scout_posts', new PgsqlPrefixedPost);
    }

    protected function assertSearchableTableWorks($schema, string $table, Model $model): void
    {
        $schema->create($table, function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->searchable(['title' => 'A', 'body' => 'D'], 'english', ['title']);
        });

        $model->newQuery()->create(['title' => 'The Godfather']);
        $model->newQuery()->create(['title' => 'Godzilla', 'body' => 'godfather cameo']);

        $this->assertSame(['The Godfather', 'Godzilla'], $model::search('godfather')->get()->pluck('title')->all());
        $this->assertSame(['The Godfather'], $model::search('godfathr')->get()->pluck('title')->all());
        $this->assertSame(1, $model::search('godfathr')->paginate()->total());

        $schema->table($table, fn (Blueprint $table) => $table->dropSearchable(['title']));

        $this->assertFalse($schema->hasColumn($table, 'search_vector'));
    }

    protected function indexes(): array
    {
        return collect(DB::select("select indexname, indexdef from pg_indexes where tablename = 'scout_posts' and indexname <> 'scout_posts_pkey' order by indexname"))
            ->pluck('indexdef', 'indexname')
            ->all();
    }
}

class PgsqlPost extends Model
{
    use Searchable, SoftDeletes;

    protected $table = 'scout_posts';

    protected $guarded = [];

    #[SearchUsingFullText(['title', 'body'], ['tsvector' => 'search_vector', 'trigram' => ['title']])]
    public function toSearchableArray()
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

class PgsqlExpressionPost extends PgsqlPost
{
    #[SearchUsingFullText(['title', 'body'], ['language' => 'english'])]
    public function toSearchableArray()
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

class PgsqlBooleanVectorPost extends PgsqlPost
{
    #[SearchUsingFullText(['search_vector'], ['vector' => true])]
    public function toSearchableArray()
    {
        return ['search_vector' => null];
    }
}

class PgsqlPhrasePost extends PgsqlPost
{
    #[SearchUsingFullText(['title', 'body'], ['tsvector' => 'search_vector', 'mode' => 'phrase'])]
    public function toSearchableArray()
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

class PgsqlWebsearchPost extends PgsqlPost
{
    #[SearchUsingFullText(['title', 'body'], ['tsvector' => 'search_vector', 'mode' => 'websearch'])]
    public function toSearchableArray()
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

class PgsqlTenantPost extends PgsqlPost
{
    protected $table = 'scout_tenant.scout_posts';
}

class PgsqlPrefixedPost extends PgsqlPost
{
    protected $connection = 'pgsql_prefixed';
}
