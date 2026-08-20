<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use Illuminate\Pagination\Paginator;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\DatabaseEngine;
use Laravel\Scout\Tests\Fixtures\FakeEmbeddings;
use PDO;
use PHPUnit\Framework\TestCase;

class DatabaseEngineSemanticTest extends TestCase
{
    protected $connectionResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionResolver = Model::getConnectionResolver();
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => '/');
    }

    protected function tearDown(): void
    {
        if (is_null($this->connectionResolver)) {
            Model::unsetConnectionResolver();
        } else {
            Model::setConnectionResolver($this->connectionResolver);
        }

        parent::tearDown();
    }

    public function test_it_builds_a_filtered_postgres_vector_similarity_query()
    {
        $model = $this->model();

        if (! method_exists($model->newQuery()->getQuery(), 'whereVectorSimilarTo')) {
            $this->markTestSkipped('Vector similarity queries require Laravel 13.');
        }

        $this->fakeEmbeddings([[[0.1, 0.2]]]);

        $query = $this->engine()->semanticQuery(
            (new Builder($model, 'semantic query'))
                ->semantic(minSimilarity: 0.4)
                ->where('user_id', 1)
        );

        $this->assertSame(
            'select * from "documents" where ("documents"."embedding" <=> ?) <= ? and "user_id" = ? order by ("documents"."embedding" <=> ?) asc, "documents"."id" asc',
            $query->toSql()
        );
        $this->assertSame(['[0.1,0.2]', 0.6, 1, '[0.1,0.2]'], $query->getBindings());
        $this->assertSame(['semantic query'], FakeEmbeddings::$requests[0]['inputs']);
        $this->assertNull(FakeEmbeddings::$requests[0]['cache']);
    }

    public function test_semantic_search_callbacks_are_applied_after_the_vector_constraint()
    {
        $model = $this->model();

        if (! method_exists($model->newQuery()->getQuery(), 'whereVectorSimilarTo')) {
            $this->markTestSkipped('Vector similarity queries require Laravel 13.');
        }

        $this->fakeEmbeddings([[[0.1, 0.2]]]);
        $query = $this->engine()->semanticQuery(
            (new Builder($model, 'semantic query', function ($query) {
                $query->orWhere('name', 'Fallback');
            }))->semantic()
        );

        $this->assertSame(
            'select * from "documents" where ("documents"."embedding" <=> ?) <= ? or "name" = ? order by ("documents"."embedding" <=> ?) asc, "documents"."id" asc',
            $query->toSql()
        );
        $this->assertSame(['[0.1,0.2]', 0.4, 'Fallback', '[0.1,0.2]'], $query->getBindings());
    }

    public function test_semantic_search_callbacks_can_override_the_scout_result_limit()
    {
        $model = $this->model();

        if (! method_exists($model->newQuery()->getQuery(), 'whereVectorSimilarTo')) {
            $this->markTestSkipped('Vector similarity queries require Laravel 13.');
        }

        $this->fakeEmbeddings([[[0.1, 0.2]]]);
        $query = $this->engine()->semanticQuery(
            (new Builder($model, 'semantic query', function ($query) {
                $query->take(1);
            }))->semantic()->take(10)
        );

        $this->assertSame(1, $query->getQuery()->limit);
    }

    public function test_semantic_pagination_respects_the_scout_result_limit()
    {
        $model = $this->model();
        $model->getConnection()->statement('create table documents (id integer primary key, name varchar(255))');
        $model->getConnection()->table('documents')->insert([
            ['id' => 1, 'name' => 'One'],
            ['id' => 2, 'name' => 'Two'],
            ['id' => 3, 'name' => 'Three'],
            ['id' => 4, 'name' => 'Four'],
        ]);
        $engine = new class extends DatabaseEngine
        {
            protected function buildSemanticSearchQuery(Builder $builder)
            {
                return $builder->model->newQuery()->orderBy('id');
            }
        };

        $results = $engine->paginateUsingDatabase(
            (new Builder($model, 'query'))->semantic()->take(3),
            2,
            'page',
            2
        );

        $this->assertSame(3, $results->total());
        $this->assertSame([3], $results->getCollection()->modelKeys());
    }

    public function test_it_persists_a_precomputed_embedding_without_model_events()
    {
        $model = $this->model();

        if (! method_exists($model->newQuery()->getQuery(), 'whereVectorSimilarTo')) {
            $this->markTestSkipped('Vector similarity queries require Laravel 13.');
        }

        $model->getConnection()->statement('create table documents (id integer primary key, name varchar(255), embedding text)');
        $model->getConnection()->table('documents')->insert(['id' => 1, 'name' => 'Document']);

        $model->forceFill(['id' => 1, 'name' => 'Document', 'embedding' => [0.3, 0.7]]);
        $model->exists = true;

        (new DatabaseEngine)->update($model->newCollection([$model]));

        $this->assertSame(
            '[0.3,0.7]',
            $model->getConnection()->table('documents')->where('id', 1)->value('embedding')
        );
    }

    public function test_it_generates_and_persists_a_database_embedding()
    {
        $model = $this->model();

        if (! method_exists($model->newQuery()->getQuery(), 'whereVectorSimilarTo')) {
            $this->markTestSkipped('Vector similarity queries require Laravel 13.');
        }

        $model->getConnection()->statement('create table documents (id integer primary key, name varchar(255), embedding text)');
        $model->getConnection()->table('documents')->insert(['id' => 1, 'name' => 'Generate this']);
        $this->fakeEmbeddings([[[0.2, 0.8]]]);

        $model->forceFill(['id' => 1, 'name' => 'Generate this']);
        $model->exists = true;

        (new DatabaseEngine)->update($model->newCollection([$model]));

        $this->assertSame(['Generate this'], FakeEmbeddings::$requests[0]['inputs']);
        $this->assertSame(
            '[0.2,0.8]',
            $model->getConnection()->table('documents')->where('id', 1)->value('embedding')
        );
    }

    protected function model(): DatabaseSemanticModel
    {
        $connection = new PostgresConnection(new PDO('sqlite::memory:'), '', '', ['driver' => 'pgsql']);
        $resolver = new ConnectionResolver(['pgsql' => $connection]);
        $resolver->setDefaultConnection('pgsql');
        Model::setConnectionResolver($resolver);

        return new DatabaseSemanticModel;
    }

    protected function fakeEmbeddings(array $responses): void
    {
        if (! class_exists('Laravel\\Ai\\Embeddings')) {
            class_alias(FakeEmbeddings::class, 'Laravel\\Ai\\Embeddings');
        }

        FakeEmbeddings::fake($responses);
    }

    protected function engine(): DatabaseEngine
    {
        return new class extends DatabaseEngine
        {
            public function semanticQuery(Builder $builder)
            {
                return $this->buildSemanticSearchQuery($builder);
            }
        };
    }
}

class DatabaseSemanticModel extends Model
{
    protected $table = 'documents';

    protected $guarded = [];

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }

    public function toSearchableEmbedding()
    {
        return $this->embedding ?? $this->name;
    }

    public function getScoutKey()
    {
        return $this->getKey();
    }
}
