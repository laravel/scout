<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use InvalidArgumentException;
use Laravel\Scout\ScoutServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use PDO;

class PgsqlSchemaHelperTest extends TestCase
{
    use WithWorkbench;

    protected function defineEnvironment($app)
    {
        $app['config']->set('scout.driver', 'pgsql');
    }

    public function test_searchable_blueprint_macro_is_registered()
    {
        $this->assertTrue(Blueprint::hasMacro('searchable'));
        $this->assertTrue(Blueprint::hasMacro('dropSearchable'));
    }

    public function test_searchable_blueprint_macro_is_not_registered_for_other_scout_drivers()
    {
        Blueprint::flushMacros();

        $this->app['config']->set('scout.driver', 'collection');

        $this->app->getProvider(ScoutServiceProvider::class)->boot();

        $this->assertFalse(Blueprint::hasMacro('searchable'));
        $this->assertFalse(Blueprint::hasMacro('dropSearchable'));
    }

    public function test_searchable_helper_creates_generated_vector_column_and_gin_index()
    {
        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title', 'body'], [
                'weights' => [
                    'title' => 'A',
                ],
            ]);
        });

        $this->assertContains('alter table "posts" add column "search_vector" tsvector not null generated always as (setweight(to_tsvector(\'english\', coalesce(cast("title" as text), \'\')), \'A\') || setweight(to_tsvector(\'english\', coalesce(cast("body" as text), \'\')), \'D\')) stored', $sql);
        $this->assertContains('create index "posts_search_vector_index" on "posts" using gin ("search_vector")', $sql);
    }

    public function test_searchable_helper_can_create_trigram_extension()
    {
        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title'], [
                'trigram' => [
                    'create_extension' => true,
                ],
            ]);
        });

        $this->assertContains('create extension if not exists "pg_trgm"', $sql);
    }

    public function test_searchable_helper_skips_trigram_extension_when_disabled()
    {
        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title']);
        });

        $this->assertNotContains('create extension if not exists "pg_trgm"', $sql);
    }

    public function test_searchable_helper_creates_trigram_indexes()
    {
        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title', 'body'], [
                'trigram' => [
                    'columns' => ['title'],
                ],
            ]);
        });

        $this->assertContains('create index "posts_title_trigram_index" on "posts" using gin ("title" gin_trgm_ops)', $sql);
    }

    public function test_drop_searchable_helper_drops_generated_vector_index_and_column()
    {
        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->dropSearchable();
        });

        $this->assertContains('drop index "posts_search_vector_index"', $sql);
        $this->assertContains('alter table "posts" drop column "search_vector"', $sql);
    }

    public function test_drop_searchable_helper_uses_configured_vector_column_and_index()
    {
        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->dropSearchable([
                'vector_column' => 'document_vector',
                'index' => 'posts_document_vector_index',
            ]);
        });

        $this->assertContains('drop index "posts_document_vector_index"', $sql);
        $this->assertContains('alter table "posts" drop column "document_vector"', $sql);
    }

    public function test_drop_searchable_helper_drops_trigram_indexes()
    {
        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->dropSearchable([
                'trigram' => [
                    'columns' => ['title'],
                ],
            ]);
        });

        $this->assertContains('drop index "posts_search_vector_index"', $sql);
        $this->assertContains('drop index "posts_title_trigram_index"', $sql);
        $this->assertContains('alter table "posts" drop column "search_vector"', $sql);
    }

    public function test_searchable_helper_rejects_non_postgresql_connections()
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', []);

        $connection->useDefaultSchemaGrammar();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper may only be used with PostgreSQL connections.');

        (new Blueprint($connection, 'posts', function ($table) {
            $table->searchable(['title']);
        }))->toSql();
    }

    public function test_drop_searchable_helper_rejects_non_postgresql_connections()
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', []);

        $connection->useDefaultSchemaGrammar();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper may only be used with PostgreSQL connections.');

        (new Blueprint($connection, 'posts', function ($table) {
            $table->dropSearchable();
        }))->toSql();
    }

    protected function compilePgsqlBlueprint($callback)
    {
        $connection = new PostgresConnection(new PDO('sqlite::memory:'), 'database', '', ['driver' => 'pgsql']);

        $connection->useDefaultSchemaGrammar();

        return (new Blueprint($connection, 'posts', $callback))->toSql();
    }
}
