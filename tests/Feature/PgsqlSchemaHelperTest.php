<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Scout\ScoutServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use PDO;
use ReflectionClass;

class PgsqlSchemaHelperTest extends TestCase
{
    use WithWorkbench;

    protected function defineEnvironment($app)
    {
        $app['config']->set('scout.driver', 'pgsql');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.pgsql_schema', [
            'driver' => 'testing-pgsql-schema',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['db']->extend('testing-pgsql-schema', function ($config) {
            return new PgsqlSchemaHelperTestingConnection(new PDO('sqlite::memory:'), $config['database'], $config['prefix'], $config);
        });
    }

    public function test_searchable_blueprint_macro_is_registered()
    {
        $this->assertTrue(Blueprint::hasMacro('searchable'));
        $this->assertTrue(Blueprint::hasMacro('dropSearchable'));
    }

    public function test_searchable_blueprint_macro_is_registered_for_other_scout_drivers()
    {
        Blueprint::flushMacros();

        $this->app['config']->set('scout.driver', 'collection');

        $this->app->getProvider(ScoutServiceProvider::class)->boot();

        $this->assertTrue(Blueprint::hasMacro('searchable'));
        $this->assertTrue(Blueprint::hasMacro('dropSearchable'));

        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', []);

        $connection->useDefaultSchemaGrammar();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper may only be used with PostgreSQL connections.');

        $this->toSql($connection, function ($table) {
            $table->searchable(['title']);
        });
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

    public function test_searchable_helper_uses_configured_vector_column()
    {
        $this->app['config']->set('scout.pgsql.vector_column', 'document_vector');

        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title']);
        });

        $this->assertContains('alter table "posts" add column "document_vector" tsvector not null generated always as (setweight(to_tsvector(\'english\', coalesce(cast("title" as text), \'\')), \'D\')) stored', $sql);
        $this->assertContains('create index "posts_document_vector_index" on "posts" using gin ("document_vector")', $sql);
    }

    public function test_searchable_helper_ignores_per_call_vector_column_and_language_options()
    {
        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title'], [
                'language' => 'simple',
                'vector_column' => 'document_vector',
            ]);
        });

        $this->assertContains('alter table "posts" add column "search_vector" tsvector not null generated always as (setweight(to_tsvector(\'english\', coalesce(cast("title" as text), \'\')), \'D\')) stored', $sql);
        $this->assertContains('create index "posts_search_vector_index" on "posts" using gin ("search_vector")', $sql);
        $this->assertStringNotContainsString('document_vector', implode('\n', $sql));
        $this->assertStringNotContainsString('simple', implode('\n', $sql));
    }

    public function test_searchable_helper_uses_configured_column_weights()
    {
        $this->app['config']->set('scout.pgsql.column_weights', [
            'title' => 'A',
            'body' => 'B',
        ]);

        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title', 'body']);
        });

        $this->assertContains('alter table "posts" add column "search_vector" tsvector not null generated always as (setweight(to_tsvector(\'english\', coalesce(cast("title" as text), \'\')), \'A\') || setweight(to_tsvector(\'english\', coalesce(cast("body" as text), \'\')), \'B\')) stored', $sql);
    }

    public function test_searchable_helper_rejects_invalid_configured_column_weights()
    {
        $this->app['config']->set('scout.pgsql.column_weights', [
            'title' => 'Z',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper column weight for [title] must be one of: A, B, C, D.');

        $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title']);
        });
    }

    public function test_searchable_helper_accepts_schema_qualified_languages()
    {
        $this->app['config']->set('scout.pgsql.language', 'pg_catalog.english');

        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title']);
        });

        $this->assertContains('alter table "posts" add column "search_vector" tsvector not null generated always as (setweight(to_tsvector(\'pg_catalog.english\', coalesce(cast("title" as text), \'\')), \'D\')) stored', $sql);
    }

    public function test_searchable_helper_rejects_invalid_languages()
    {
        $this->app['config']->set('scout.pgsql.language', 'english; --');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper language must be a valid PostgreSQL text search configuration name.');

        $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title']);
        });
    }

    public function test_searchable_helper_rejects_invalid_vector_columns()
    {
        $this->app['config']->set('scout.pgsql.vector_column', 'search_vector) desc; --');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper vector column must be a valid column name.');

        $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title']);
        });
    }

    public function test_searchable_helper_rejects_schema_qualified_vector_columns()
    {
        $this->app['config']->set('scout.pgsql.vector_column', 'foo.bar');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper vector column must be a valid column name.');

        $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title']);
        });
    }

    public function test_searchable_helper_rejects_invalid_searchable_columns()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper searchable column [title; --] must be a valid column name.');

        $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title; --']);
        });
    }

    public function test_searchable_helper_rejects_schema_qualified_searchable_columns()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper searchable column [posts.title] must be a valid column name.');

        $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['posts.title']);
        });
    }

    public function test_searchable_helper_rejects_empty_searchable_columns()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper searchable columns must not be empty.');

        $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable([]);
        });
    }

    public function test_searchable_helper_rejects_invalid_trigram_columns()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper trigram column [title; --] must be a valid column name.');

        $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title'], [
                'trigram' => [
                    'columns' => ['title; --'],
                ],
            ]);
        });
    }

    public function test_searchable_helper_rejects_schema_qualified_trigram_columns()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper trigram column [posts.title] must be a valid column name.');

        $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title'], [
                'trigram' => [
                    'columns' => ['posts.title'],
                ],
            ]);
        });
    }

    public function test_searchable_helper_rejects_non_array_column_weights()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper column weights must be an array.');

        $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title'], [
                'weights' => 'invalid',
            ]);
        });
    }

    public function test_searchable_helper_rejects_invalid_column_weights()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper column weight for [title] must be one of: A, B, C, D.');

        $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title'], [
                'weights' => [
                    'title' => 'Z',
                ],
            ]);
        });
    }

    public function test_searchable_helper_rejects_schema_qualified_column_weights()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper column weight column [posts.title] must be a valid column name.');

        $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title'], [
                'weights' => [
                    'posts.title' => 'A',
                ],
            ]);
        });
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

    public function test_searchable_helper_creates_configured_trigram_indexes()
    {
        $this->app['config']->set('scout.pgsql.trigram.columns', ['title']);

        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title', 'body']);
        });

        $this->assertContains('create index "posts_title_trigram_index" on "posts" using gin ("title" gin_trgm_ops)', $sql);
    }

    public function test_searchable_helper_trigram_options_override_configured_trigram_indexes()
    {
        $this->app['config']->set('scout.pgsql.trigram.columns', ['body']);

        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title', 'body'], [
                'trigram' => [
                    'columns' => ['title'],
                ],
            ]);
        });

        $this->assertContains('create index "posts_title_trigram_index" on "posts" using gin ("title" gin_trgm_ops)', $sql);
        $this->assertNotContains('create index "posts_body_trigram_index" on "posts" using gin ("body" gin_trgm_ops)', $sql);
    }

    public function test_searchable_helper_creates_prefixed_trigram_indexes()
    {
        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title', 'body'], [
                'trigram' => [
                    'columns' => ['title'],
                ],
            ]);
        }, $this->makePgsqlConnection([
            'prefix_indexes' => true,
        ], 'prefix_'));

        $this->assertContains('create index "prefix_posts_title_trigram_index" on "prefix_posts" using gin ("title" gin_trgm_ops)', $sql);
    }

    public function test_drop_searchable_helper_drops_generated_vector_index_and_column()
    {
        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->dropSearchable();
        });

        $this->assertContains('drop index "posts_search_vector_index"', $sql);
        $this->assertContains('alter table "posts" drop column "search_vector"', $sql);
    }

    public function test_drop_searchable_helper_drops_prefixed_default_vector_index()
    {
        $connection = $this->makePgsqlConnection([
            'prefix_indexes' => true,
        ], 'prefix_');

        $createSql = $this->compilePgsqlBlueprint(function ($table) {
            $table->searchable(['title']);
        }, $connection);

        $dropSql = $this->compilePgsqlBlueprint(function ($table) {
            $table->dropSearchable();
        }, $connection);

        $this->assertContains('create index "prefix_posts_search_vector_index" on "prefix_posts" using gin ("search_vector")', $createSql);
        $this->assertContains('drop index "prefix_posts_search_vector_index"', $dropSql);
    }

    public function test_drop_searchable_helper_uses_configured_vector_column_and_index()
    {
        $this->app['config']->set('scout.pgsql.vector_column', 'document_vector');

        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->dropSearchable([
                'index' => 'posts_document_vector_index',
            ]);
        });

        $this->assertContains('drop index "posts_document_vector_index"', $sql);
        $this->assertContains('alter table "posts" drop column "document_vector"', $sql);
    }

    public function test_drop_searchable_helper_drops_custom_index_name_unchanged_with_prefixed_indexes()
    {
        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->dropSearchable([
                'index' => 'custom_search_vector_index',
            ]);
        }, $this->makePgsqlConnection([
            'prefix_indexes' => true,
        ], 'prefix_'));

        $this->assertContains('drop index "custom_search_vector_index"', $sql);
        $this->assertNotContains('drop index "prefix_custom_search_vector_index"', $sql);
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

    public function test_drop_searchable_helper_drops_configured_trigram_indexes()
    {
        $this->app['config']->set('scout.pgsql.trigram.columns', ['title']);

        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->dropSearchable();
        });

        $this->assertContains('drop index "posts_search_vector_index"', $sql);
        $this->assertContains('drop index "posts_title_trigram_index"', $sql);
        $this->assertContains('alter table "posts" drop column "search_vector"', $sql);
    }

    public function test_drop_searchable_helper_trigram_options_override_configured_trigram_indexes()
    {
        $this->app['config']->set('scout.pgsql.trigram.columns', ['body']);

        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->dropSearchable([
                'trigram' => [
                    'columns' => ['title'],
                ],
            ]);
        });

        $this->assertContains('drop index "posts_title_trigram_index"', $sql);
        $this->assertNotContains('drop index "posts_body_trigram_index"', $sql);
    }

    public function test_drop_searchable_helper_drops_prefixed_trigram_indexes()
    {
        $sql = $this->compilePgsqlBlueprint(function ($table) {
            $table->dropSearchable([
                'trigram' => [
                    'columns' => ['title'],
                ],
            ]);
        }, $this->makePgsqlConnection([
            'prefix_indexes' => true,
        ], 'prefix_'));

        $this->assertContains('drop index "prefix_posts_search_vector_index"', $sql);
        $this->assertContains('drop index "prefix_posts_title_trigram_index"', $sql);
        $this->assertContains('alter table "prefix_posts" drop column "search_vector"', $sql);
    }

    public function test_searchable_helper_uses_named_schema_connection_when_default_connection_is_not_postgresql()
    {
        Schema::connection('pgsql_schema')->table('posts', function (Blueprint $table) {
            $table->searchable(['title']);
        });

        $statements = $this->app['db']->connection('pgsql_schema')->statements;

        $this->assertContains('alter table "posts" add column "search_vector" tsvector not null generated always as (setweight(to_tsvector(\'english\', coalesce(cast("title" as text), \'\')), \'D\')) stored', $statements);
        $this->assertContains('create index "posts_search_vector_index" on "posts" using gin ("search_vector")', $statements);
    }

    public function test_drop_searchable_helper_uses_named_schema_connection_when_default_connection_is_not_postgresql()
    {
        Schema::connection('pgsql_schema')->table('posts', function (Blueprint $table) {
            $table->dropSearchable();
        });

        $statements = $this->app['db']->connection('pgsql_schema')->statements;

        $this->assertContains('drop index "posts_search_vector_index"', $statements);
        $this->assertContains('alter table "posts" drop column "search_vector"', $statements);
    }

    public function test_searchable_helper_rejects_real_non_postgresql_schema_connections()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper may only be used with PostgreSQL connections.');

        Schema::connection('testing')->table('posts', function (Blueprint $table) {
            $table->searchable(['title']);
        });
    }

    public function test_searchable_helper_rejects_non_postgresql_connections()
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', []);

        $connection->useDefaultSchemaGrammar();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper may only be used with PostgreSQL connections.');

        $this->toSql($connection, function ($table) {
            $table->searchable(['title']);
        });
    }

    public function test_drop_searchable_helper_rejects_non_postgresql_connections()
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', []);

        $connection->useDefaultSchemaGrammar();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout schema helper may only be used with PostgreSQL connections.');

        $this->toSql($connection, function ($table) {
            $table->dropSearchable();
        });
    }

    protected function compilePgsqlBlueprint($callback, $connection = null)
    {
        $connection ??= $this->makePgsqlConnection();

        return $this->toSql($connection, $callback);
    }

    protected function toSql($connection, $callback)
    {
        $ref = new ReflectionClass(Blueprint::class);
        $params = $ref->getConstructor()->getParameters();

        if ($params[0]->getName() === 'connection') {
            $blueprint = new Blueprint($connection, 'posts', $callback);

            return $blueprint->toSql();
        }

        $blueprint = new Blueprint('posts', null, $connection->getTablePrefix());
        $blueprint->connection = $connection;
        $callback($blueprint);

        return $blueprint->toSql($connection, $connection->getSchemaGrammar());
    }

    protected function makePgsqlConnection(array $config = [], $prefix = '')
    {
        $connection = new PostgresConnection(new PDO('sqlite::memory:'), 'database', $prefix, array_merge([
            'driver' => 'pgsql',
        ], $config));

        $connection->useDefaultSchemaGrammar();

        return $connection;
    }
}

class PgsqlSchemaHelperTestingConnection extends PostgresConnection
{
    public array $statements = [];

    public function getDriverName()
    {
        return 'pgsql';
    }

    public function statement($query, $bindings = [])
    {
        $this->statements[] = $query;

        return true;
    }
}
