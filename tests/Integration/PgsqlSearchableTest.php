<?php

namespace Laravel\Scout\Tests\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Searchable;
use Orchestra\Testbench\Attributes\RequiresEnv;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('pgsql')]
#[RequiresEnv('SCOUT_PGSQL_TEST_DATABASE')]
class PgsqlSearchableTest extends TestCase
{
    use WithWorkbench;

    protected function defineEnvironment($app)
    {
        $app['config']->set('scout.driver', 'pgsql');
        $app['config']->set('scout.pgsql.column_weights', [
            'title' => 'A',
            'body' => 'D',
        ]);
        $app['config']->set('database.default', 'pgsql_testing');
        $app['config']->set('database.connections.pgsql_testing', [
            'driver' => 'pgsql',
            'host' => env('SCOUT_PGSQL_TEST_HOST', '127.0.0.1'),
            'port' => env('SCOUT_PGSQL_TEST_PORT', 5432),
            'database' => env('SCOUT_PGSQL_TEST_DATABASE'),
            'username' => env('SCOUT_PGSQL_TEST_USERNAME', 'postgres'),
            'password' => env('SCOUT_PGSQL_TEST_PASSWORD', ''),
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
            'sslmode' => env('SCOUT_PGSQL_TEST_SSLMODE', 'prefer'),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->app?->bound('db.schema')) {
            Schema::dropIfExists('scout_pgsql_posts');
        }

        parent::tearDown();
    }

    public function test_generated_vector_index_and_ranked_search_results()
    {
        $this->createPostsTable();

        PgsqlSearchPost::query()->create([
            'title' => 'Laravel Scout',
            'body' => 'Native PostgreSQL search driver',
        ]);
        PgsqlSearchPost::query()->create([
            'title' => 'PostgreSQL Search',
            'body' => 'Laravel Scout uses generated search vectors',
        ]);
        PgsqlSearchPost::query()->create([
            'title' => 'Queues',
            'body' => 'Background jobs and workers',
        ]);

        $this->assertHasPgsqlIndex('scout_pgsql_posts_search_vector_index', 'USING gin (search_vector)');

        $results = PgsqlSearchPost::search('laravel')->get();

        $this->assertSame(['Laravel Scout', 'PostgreSQL Search'], $results->pluck('title')->all());

        $page = PgsqlSearchPost::search('laravel')->paginate(1, 'page', 1);

        $this->assertSame(2, $page->total());
        $this->assertSame(['Laravel Scout'], $page->getCollection()->pluck('title')->all());

        $page = PgsqlSearchPost::search('laravel')->paginate(1, 'page', 2);

        $this->assertSame(['PostgreSQL Search'], $page->getCollection()->pluck('title')->all());
    }

    public function test_trigram_extension_setup_and_similarity_search()
    {
        if (! env('SCOUT_PGSQL_TEST_TRIGRAM', false)) {
            $this->markTestSkipped('Set SCOUT_PGSQL_TEST_TRIGRAM=true to run pg_trgm integration coverage.');
        }

        $this->app['config']->set('scout.pgsql.trigram.enabled', true);
        $this->app['config']->set('scout.pgsql.trigram.threshold', 0.15);
        $this->app['config']->set('scout.pgsql.trigram.columns', ['title']);

        $this->createPostsTable(true);

        PgsqlSearchPost::query()->create([
            'title' => 'Laravel Scout',
            'body' => 'Native PostgreSQL search driver',
        ]);
        PgsqlSearchPost::query()->create([
            'title' => 'Queues',
            'body' => 'Laravel Scout',
        ]);

        $this->assertTrue($this->pgTrgmExtensionExists());
        $this->assertHasPgsqlIndex('scout_pgsql_posts_title_trigram_index', 'gin_trgm_ops');

        $results = PgsqlSearchPost::search('laravle')->get();

        $this->assertSame(['Laravel Scout'], $results->pluck('title')->all());
    }

    public function test_trigram_search_honors_non_default_threshold()
    {
        if (! env('SCOUT_PGSQL_TEST_TRIGRAM', false)) {
            $this->markTestSkipped('Set SCOUT_PGSQL_TEST_TRIGRAM=true to run pg_trgm integration coverage.');
        }

        $this->app['config']->set('scout.pgsql.trigram.enabled', true);
        $this->app['config']->set('scout.pgsql.trigram.threshold', 0.7);
        $this->app['config']->set('scout.pgsql.trigram.columns', ['title']);

        $this->createPostsTable(true);

        PgsqlSearchPost::query()->create([
            'title' => 'Laravel Scout',
            'body' => 'Native PostgreSQL search driver',
        ]);

        $this->assertSame(['Laravel Scout'], PgsqlSearchPost::search('laravel scout')->get()->pluck('title')->all());
        $this->assertSame([], PgsqlSearchPost::search('laravle')->get()->pluck('title')->all());
    }

    public function test_drop_searchable_removes_generated_vector_column_and_indexes()
    {
        $withTrigram = (bool) env('SCOUT_PGSQL_TEST_TRIGRAM', false);

        $this->createPostsTable($withTrigram);

        $this->assertHasPgsqlColumn('search_vector');
        $this->assertHasPgsqlIndex('scout_pgsql_posts_search_vector_index', 'USING gin (search_vector)');

        if ($withTrigram) {
            $this->assertHasPgsqlIndex('scout_pgsql_posts_title_trigram_index', 'gin_trgm_ops');
        }

        Schema::table('scout_pgsql_posts', function (Blueprint $table) use ($withTrigram) {
            $table->dropSearchable([
                'trigram' => $withTrigram ? [
                    'columns' => ['title'],
                ] : [],
            ]);
        });

        $this->assertMissingPgsqlColumn('search_vector');
        $this->assertMissingPgsqlIndex('scout_pgsql_posts_search_vector_index');

        if ($withTrigram) {
            $this->assertMissingPgsqlIndex('scout_pgsql_posts_title_trigram_index');
        }
    }

    protected function createPostsTable($withTrigram = false)
    {
        Schema::dropIfExists('scout_pgsql_posts');

        Schema::create('scout_pgsql_posts', function (Blueprint $table) use ($withTrigram) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->searchable(['title', 'body'], [
                'weights' => [
                    'title' => 'A',
                    'body' => 'D',
                ],
                'trigram' => $withTrigram ? [
                    'create_extension' => true,
                    'columns' => ['title'],
                ] : [],
            ]);
        });
    }

    protected function assertHasPgsqlIndex($name, $definition)
    {
        $index = DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', 'scout_pgsql_posts')
            ->where('indexname', $name)
            ->value('indexdef');

        $this->assertNotNull($index);
        $this->assertStringContainsString($definition, $index);
    }

    protected function assertMissingPgsqlIndex($name)
    {
        $this->assertFalse(DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', 'scout_pgsql_posts')
            ->where('indexname', $name)
            ->exists());
    }

    protected function assertHasPgsqlColumn($name)
    {
        $this->assertTrue(DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'scout_pgsql_posts')
            ->where('column_name', $name)
            ->exists());
    }

    protected function assertMissingPgsqlColumn($name)
    {
        $this->assertFalse(DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'scout_pgsql_posts')
            ->where('column_name', $name)
            ->exists());
    }

    protected function pgTrgmExtensionExists()
    {
        return DB::table('pg_extension')->where('extname', 'pg_trgm')->exists();
    }
}

class PgsqlSearchPost extends Model
{
    use Searchable;

    public $timestamps = false;

    protected $guarded = [];

    protected $table = 'scout_pgsql_posts';

    public function toSearchableArray()
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
        ];
    }
}
