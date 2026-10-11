<?php

namespace Laravel\Scout\Tests\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Engines\DatabaseEngine;
use Laravel\Scout\Exceptions\ScoutException;
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
        $app['config']->set('database.connections.pgsql_tenant', array_merge(
            $app['config']->get('database.connections.pgsql_testing'), ['search_path' => 'scout_tenant', 'schema' => 'scout_tenant']
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetTrigramCache();

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

    public function test_query_modes_and_cover_density_ranking()
    {
        PgsqlPost::create(['title' => 'Shah Rukh Khan', 'body' => 'Romance']);
        PgsqlPost::create(['title' => 'Salman Khan', 'body' => 'Action']);

        $this->assertSame(['Shah Rukh Khan'], PgsqlWebsearchPost::search('khan -salman')->get()->pluck('title')->all());
        $this->assertSame(['Shah Rukh Khan'], PgsqlPhrasePost::search('rukh khan')->get()->pluck('title')->all());
        $this->assertSame([], PgsqlPhrasePost::search('khan rukh')->get()->all());
        $this->assertSame(['Salman Khan'], PgsqlRawPost::search('khan & !rukh')->get()->pluck('title')->all());
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

    public function test_trigram_blend_mode_matches_typos_alongside_full_text()
    {
        PgsqlBlendPost::create(['title' => 'Star Wars']);
        PgsqlBlendPost::create(['title' => 'Star Trek']);
        PgsqlBlendPost::create(['title' => 'Stra Wars']);

        $this->assertSame(['Star Wars', 'Stra Wars', 'Star Trek'], PgsqlBlendPost::search('star wars')->get()->pluck('title')->all());
    }

    public function test_trigram_blend_weights_change_the_ranking()
    {
        PgsqlBlendPost::create(['title' => 'Laravel Scout search engine docs']);
        PgsqlBlendPost::create(['title' => 'Scoutt']);

        $this->assertSame(['Laravel Scout search engine docs', 'Scoutt'], PgsqlBlendRankPost::search('scout')->get()->pluck('title')->all());
        $this->assertSame(['Scoutt', 'Laravel Scout search engine docs'], PgsqlBlendTrigramPost::search('scout')->get()->pluck('title')->all());
    }

    public function test_search_without_a_vector_column_uses_expression_ranking()
    {
        PgsqlExpressionPost::create(['title' => 'Queues', 'body' => 'Scout keeps search indexes in sync']);
        PgsqlExpressionPost::create(['title' => 'Laravel Scout', 'body' => 'Scout docs']);
        PgsqlExpressionPost::create(['title' => 'Horizon', 'body' => 'Queue dashboard']);

        $this->assertSame(['Laravel Scout', 'Queues'], PgsqlExpressionPost::search('scout')->get()->pluck('title')->all());
    }

    public function test_trigram_is_disabled_when_pg_trgm_is_not_on_the_search_path()
    {
        DB::statement('create schema scout_tenant');
        Schema::connection('pgsql_tenant')->create('scout_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->searchable(['title' => 'A', 'body' => 'D']);
        });
        PgsqlSearchPathPost::create(['title' => 'The Godfather']);

        Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'pg_trgm'));

        $this->assertSame([], PgsqlSearchPathPost::search('godfathr')->get()->all());
        $this->assertSame(['The Godfather'], PgsqlSearchPathPost::search('godfather')->get()->pluck('title')->all());
    }

    public function test_missing_trigram_extension_falls_back_to_full_text_and_warns_once()
    {
        Schema::table('scout_posts', fn (Blueprint $table) => $table->dropIndex('scout_posts_title_trigram'));
        DB::statement('drop extension pg_trgm');
        PgsqlPost::create(['title' => 'The Godfather']);

        Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'pg_trgm'));

        try {
            $this->assertSame([], PgsqlPost::search('godfathr')->get()->all());
            $this->assertSame(['The Godfather'], PgsqlPost::search('godfather')->get()->pluck('title')->all());
        } finally {
            DB::statement('create extension if not exists pg_trgm');
        }
    }

    public function test_missing_vector_column_fails_clearly()
    {
        Schema::table('scout_posts', fn (Blueprint $table) => $table->dropSearchable(['title']));

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('The [search_vector] search vector column does not exist on the [scout_posts] table.');

        PgsqlPost::search('scout')->get();
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

    protected function resetTrigramCache(): void
    {
        (fn () => static::$trigramExtensionInstalled = [])->bindTo(null, DatabaseEngine::class)();
    }
}

class PgsqlPost extends Model
{
    use Searchable, SoftDeletes;

    protected $table = 'scout_posts';

    protected $guarded = [];

    #[SearchUsingFullText(['title', 'body'], ['vector' => 'search_vector', 'trigram' => ['title']])]
    public function toSearchableArray()
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

class PgsqlBlendPost extends PgsqlPost
{
    #[SearchUsingFullText(['title', 'body'], ['vector' => 'search_vector', 'trigram' => ['title'], 'trigram_mode' => 'blend', 'rank' => 'ts_rank_cd'])]
    public function toSearchableArray()
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

class PgsqlBlendRankPost extends PgsqlPost
{
    #[SearchUsingFullText(['title', 'body'], ['vector' => 'search_vector', 'trigram' => ['title'], 'trigram_mode' => 'blend'])]
    public function toSearchableArray()
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

class PgsqlBlendTrigramPost extends PgsqlPost
{
    #[SearchUsingFullText(['title', 'body'], ['vector' => 'search_vector', 'trigram' => ['title'], 'trigram_mode' => 'blend', 'weights' => ['rank' => 0, 'trigram' => 1]])]
    public function toSearchableArray()
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

class PgsqlExpressionPost extends PgsqlPost
{
    #[SearchUsingFullText(['title', 'body'], ['language' => 'english', 'rank' => 'ts_rank_cd'])]
    public function toSearchableArray()
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

class PgsqlPhrasePost extends PgsqlPost
{
    #[SearchUsingFullText(['title', 'body'], ['vector' => 'search_vector', 'mode' => 'phrase'])]
    public function toSearchableArray()
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

class PgsqlWebsearchPost extends PgsqlPost
{
    #[SearchUsingFullText(['title', 'body'], ['vector' => 'search_vector', 'mode' => 'websearch'])]
    public function toSearchableArray()
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

class PgsqlRawPost extends PgsqlPost
{
    #[SearchUsingFullText(['title', 'body'], ['vector' => 'search_vector', 'mode' => 'raw'])]
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

class PgsqlSearchPathPost extends PgsqlPost
{
    protected $connection = 'pgsql_tenant';
}
