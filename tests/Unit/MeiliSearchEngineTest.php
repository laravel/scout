<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\MeiliSearchEngine;
use Laravel\Scout\Tests\Fixtures\EmptySearchableModel;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Laravel\Scout\Tests\Fixtures\SoftDeletedEmptySearchableModel;
use MeiliSearch\Client;
use MeiliSearch\Endpoints\Indexes;
use MeiliSearch\Search\SearchResult;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use stdClass;

class MeiliSearchEngineTest extends TestCase
{
    public function test_update_adds_objects_to_index()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('addDocuments')->with([
            [
                'id' => 1,
            ],
            'id',
        ]);

        $engine = new MeiliSearchEngine($client);
        $engine->update(Collection::make([new SearchableModel()]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteDocuments')->with([1]);

        $engine = new MeiliSearchEngine($client);
        $engine->delete(Collection::make([new SearchableModel(['id' => 1])]));
    }

    public function test_search_sends_correct_parameters_to_meilisearch()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->with('mustang', [
            'filters' => 'foo=1',
        ]);

        $engine = new MeiliSearchEngine($client);
        $builder = new Builder(new SearchableModel(), 'mustang', function ($meilisearch, $query, $options) {
            $options['filters'] = 'foo=1';

            return $meilisearch->search($query, $options);
        });
        $engine->search($builder);
    }

    public function test_submitting_a_callable_search_with_search_method_returns_array()
    {
        $builder = new Builder(
            new SearchableModel(),
            $query = 'mustang',
            $callable = function ($meilisearch, $query, $options) {
                $options['filters'] = 'foo=1';

                return $meilisearch->search($query, $options);
            }
        );
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->with($query, ['filters' => 'foo=1'])->andReturn(new SearchResult($expectedResult = [
            'hits' => [],
            'offset' => 0,
            'limit' => 20,
            'nbHits' => 0,
            'exhaustiveNbHits' => false,
            'processingTimeMs' => 1,
            'query' => 'mustang',
        ]));

        $engine = new MeiliSearchEngine($client);
        $result = $engine->search($builder);

        $this->assertSame($expectedResult, $result);
    }

    public function test_submitting_a_callable_search_with_raw_search_method_works()
    {
        $builder = new Builder(
            new SearchableModel(),
            $query = 'mustang',
            $callable = function ($meilisearch, $query, $options) {
                $options['filters'] = 'foo=1';

                return $meilisearch->rawSearch($query, $options);
            }
        );
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->with($query, ['filters' => 'foo=1'])->andReturn($expectedResult = [
            'hits' => [],
            'offset' => 0,
            'limit' => 20,
            'nbHits' => 0,
            'exhaustiveNbHits' => false,
            'processingTimeMs' => 1,
            'query' => 'mustang',
        ]);

        $engine = new MeiliSearchEngine($client);
        $result = $engine->search($builder);

        $this->assertSame($expectedResult, $result);
    }

    public function test_map_ids_returns_empty_collection_if_no_hits()
    {
        $client = m::mock(Client::class);
        $engine = new MeiliSearchEngine($client);

        $results = $engine->mapIds([
            'nbHits' => 0, 'hits' => [],
        ]);

        $this->assertEquals(0, count($results));
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $client = m::mock(Client::class);
        $engine = new MeiliSearchEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive(['getKeyName' => 'id']);
        $model->shouldReceive('getScoutModelsByIds')->andReturn($models = Collection::make([new SearchableModel(['id' => 1])]));
        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'nbHits' => 1, 'hits' => [
                ['id' => 1],
            ],
        ], $model);

        $this->assertEquals(1, count($results));
    }

    public function test_map_method_respects_order()
    {
        $client = m::mock(Client::class);
        $engine = new MeiliSearchEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive(['getKeyName' => 'id']);
        $model->shouldReceive('getScoutModelsByIds')->andReturn($models = Collection::make([
            new SearchableModel(['id' => 1]),
            new SearchableModel(['id' => 2]),
            new SearchableModel(['id' => 3]),
            new SearchableModel(['id' => 4]),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'nbHits' => 4, 'hits' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 4],
                ['id' => 3],
            ],
        ], $model);

        $this->assertEquals(4, count($results));
        $this->assertEquals([
            0 => ['id' => 1],
            1 => ['id' => 2],
            2 => ['id' => 4],
            3 => ['id' => 3],
        ], $results->toArray());
    }

    public function test_lazy_map_correctly_maps_results_to_models()
    {
        $client = m::mock(Client::class);
        $engine = new MeiliSearchEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive(['getKeyName' => 'id']);
        $model->shouldReceive('queryScoutModelsByIds->cursor')->andReturn($models = LazyCollection::make([new SearchableModel(['id' => 1])]));
        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, [
            'nbHits' => 1, 'hits' => [
                ['id' => 1],
            ],
        ], $model);

        $this->assertEquals(1, count($results));
    }

    public function test_lazy_map_method_respects_order()
    {
        $client = m::mock(Client::class);
        $engine = new MeiliSearchEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive(['getKeyName' => 'id']);
        $model->shouldReceive('queryScoutModelsByIds->cursor')->andReturn($models = LazyCollection::make([
            new SearchableModel(['id' => 1]),
            new SearchableModel(['id' => 2]),
            new SearchableModel(['id' => 3]),
            new SearchableModel(['id' => 4]),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, [
            'nbHits' => 4, 'hits' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 4],
                ['id' => 3],
            ],
        ], $model);

        $this->assertEquals(4, count($results));
        $this->assertEquals([
            0 => ['id' => 1],
            1 => ['id' => 2],
            2 => ['id' => 4],
            3 => ['id' => 3],
        ], $results->toArray());
    }

    public function test_a_model_is_indexed_with_a_custom_meilisearch_key()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('addDocuments')->with([['id' => 'my-meilisearch-key.1']], 'id');

        $engine = new MeiliSearchEngine($client);
        $engine->update(Collection::make([new MeiliSearchCustomKeySearchableModel()]));
    }

    public function test_flush_a_model_with_a_custom_meilisearch_key()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteAllDocuments');

        $engine = new MeiliSearchEngine($client);
        $engine->flush(new MeiliSearchCustomKeySearchableModel());
    }

    public function test_update_empty_searchable_array_does_not_add_documents_to_index()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldNotReceive('addDocuments');

        $engine = new MeiliSearchEngine($client);
        $engine->update(Collection::make([new EmptySearchableModel()]));
    }

    public function test_pagination_correct_parameters()
    {
        $perPage = 5;
        $page = 2;

        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->with('mustang', [
            'filters' => 'foo=1',
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]);

        $engine = new MeiliSearchEngine($client);
        $builder = new Builder(new SearchableModel(), 'mustang', function ($meilisearch, $query, $options) {
            $options['filters'] = 'foo=1';

            return $meilisearch->search($query, $options);
        });
        $engine->paginate($builder, $perPage, $page);
    }

    public function test_update_empty_searchable_array_from_soft_deleted_model_does_not_add_documents_to_index()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn(m::mock(Indexes::class));
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldNotReceive('addDocuments');

        $engine = new MeiliSearchEngine($client, true);
        $engine->update(Collection::make([new SoftDeletedEmptySearchableModel()]));
    }

    public function test_engine_forwards_calls_to_meilisearch_client()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('testMethodOnClient')->once();

        $engine = new MeiliSearchEngine($client);
        $engine->testMethodOnClient();
    }

    public function test_updating_empty_eloquent_collection_does_nothing()
    {
        $client = m::mock(Client::class);
        $engine = new MeiliSearchEngine($client);
        $engine->update(new Collection());
        $this->assertTrue(true);
    }

    public function test_performing_search_without_callback_works()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->once()->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->andReturn([]);

        $engine = new MeiliSearchEngine($client);
        $builder = new Builder(new SearchableModel(), '');
        $engine->search($builder);
    }

    public function test_where_conditions_are_applied()
    {
        $builder = new Builder(new SearchableModel(), '');
        $builder->where('foo', 'bar');
        $builder->where('key', 'value');
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->once()->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filters' => 'foo="bar" AND key="value"',
            'limit' => $builder->limit,
        ]))->andReturn([]);

        $engine = new MeiliSearchEngine($client);
        $engine->search($builder);
    }

    public function test_engine_returns_hits_entry_from_search_response()
    {
        $this->assertTrue(3 === (new MeiliSearchEngine(m::mock(Client::class)))->getTotalCount([
            'nbHits' => 3,
        ]));
    }
}

class MeiliSearchCustomKeySearchableModel extends SearchableModel
{
    public function getScoutKey()
    {
        return 'my-meilisearch-key.'.$this->getKey();
    }
}
