<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\MeilisearchEngine;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Laravel\Scout\Tests\Fixtures\EmptySearchableModel;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Laravel\Scout\Tests\Fixtures\SoftDeletedEmptySearchableModel;
use Meilisearch\Client;
use Meilisearch\Contracts\IndexesResults;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\SearchResult;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use stdClass;

class MeilisearchEngineTest extends TestCase
{
    protected function setUp(): void
    {
        Config::shouldReceive('get')->with('scout.after_commit', m::any())->andReturn(false);
        Config::shouldReceive('get')->with('scout.soft_delete', m::any())->andReturn(false);
    }

    protected function tearDown(): void
    {
        Container::getInstance()->flush();
        m::close();
    }

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

        $engine = new MeilisearchEngine($client);
        $engine->update(Collection::make([new SearchableModel()]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteDocuments')->with([1]);

        $engine = new MeilisearchEngine($client);
        $engine->delete(Collection::make([new SearchableModel(['id' => 1])]));
    }

    public function test_delete_removes_objects_to_index_with_a_custom_search_key()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteDocuments')->once()->with(['my-meilisearch-key.5']);

        $engine = new MeilisearchEngine($client);
        $engine->delete(Collection::make([new MeilisearchCustomKeySearchableModel(['id' => 5])]));
    }

    public function test_delete_with_removeable_scout_collection_using_custom_search_key()
    {
        $job = new RemoveFromSearch(Collection::make([
            new MeilisearchCustomKeySearchableModel(['id' => 5]),
        ]));

        $job = unserialize(serialize($job));

        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteDocuments')->once()->with(['my-meilisearch-key.5']);

        $engine = new MeilisearchEngine($client);
        $engine->delete($job->models);
    }

    public function test_remove_from_search_job_uses_custom_search_key()
    {
        $job = new RemoveFromSearch(Collection::make([
            new MeilisearchCustomKeySearchableModel(['id' => 5]),
        ]));

        $job = unserialize(serialize($job));

        Container::getInstance()->bind(EngineManager::class, function () {
            $engine = m::mock(MeilisearchEngine::class);

            $engine->shouldReceive('delete')->once()->with(m::on(function ($collection) {
                $keyName = ($model = $collection->first())->getScoutKeyName();

                return $model->getAttributes()[$keyName] === 'my-meilisearch-key.5';
            }));

            $manager = m::mock(EngineManager::class);

            $manager->shouldReceive('engine')->andReturn($engine);

            return $manager;
        });

        $job->handle();
    }

    public function test_search_sends_correct_parameters_to_meilisearch()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->with('mustang', [
            'filter' => 'foo=1 AND bar=2',
        ]);

        $engine = new MeilisearchEngine($client);
        $builder = new Builder(new SearchableModel(), 'mustang', function ($meilisearch, $query, $options) {
            $options['filter'] = 'foo=1 AND bar=2';

            return $meilisearch->search($query, $options);
        });
        $engine->search($builder);
    }

    public function test_search_includes_at_least_scoutKeyName_in_attributesToRetrieve_on_builder_options()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->with('mustang', [
            'filter' => 'foo=1 AND bar=2',
            'attributesToRetrieve' => ['id', 'foo'],
        ]);

        $engine = new MeilisearchEngine($client);
        $builder = new Builder(new SearchableModel(), 'mustang', function ($meilisearch, $query, $options) {
            $options['filter'] = 'foo=1 AND bar=2';

            return $meilisearch->search($query, $options);
        });
        $builder->options = ['attributesToRetrieve' => ['foo']];
        $engine->search($builder);
    }

    public function test_submitting_a_callable_search_with_search_method_returns_array()
    {
        $builder = new Builder(
            new SearchableModel(),
            $query = 'mustang',
            $callable = function ($meilisearch, $query, $options) {
                $options['filter'] = 'foo=1';

                return $meilisearch->search($query, $options);
            }
        );
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->with($query, ['filter' => 'foo=1'])->andReturn(new SearchResult($expectedResult = [
            'hits' => [],
            'page' => 1,
            'hitsPerPage' => $builder->limit,
            'totalPages' => 1,
            'totalHits' => 0,
            'processingTimeMs' => 1,
            'query' => 'mustang',
        ]));

        $engine = new MeilisearchEngine($client);
        $result = $engine->search($builder);

        $this->assertSame($expectedResult, $result);
    }

    public function test_submitting_a_callable_search_with_raw_search_method_works()
    {
        $builder = new Builder(
            new SearchableModel(),
            $query = 'mustang',
            $callable = function ($meilisearch, $query, $options) {
                $options['filter'] = 'foo=1';

                return $meilisearch->rawSearch($query, $options);
            }
        );
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->with($query, ['filter' => 'foo=1'])->andReturn($expectedResult = [
            'hits' => [],
            'page' => 1,
            'hitsPerPage' => $builder->limit,
            'totalPages' => 1,
            'totalHits' => 0,
            'processingTimeMs' => 1,
            'query' => $query,
        ]);

        $engine = new MeilisearchEngine($client);
        $result = $engine->search($builder);

        $this->assertSame($expectedResult, $result);
    }

    public function test_map_ids_returns_empty_collection_if_no_hits()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $results = $engine->mapIdsFrom([
            'totalHits' => 0,
            'hits' => [],
        ], 'id');

        $this->assertEquals(0, count($results));
    }

    public function test_map_ids_returns_correct_values_of_primary_key()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $results = $engine->mapIdsFrom([
            'totalHits' => 5,
            'hits' => [
                [
                    'some_field' => 'something',
                    'id' => 1,
                ],
                [
                    'some_field' => 'foo',
                    'id' => 2,
                ],
                [
                    'some_field' => 'bar',
                    'id' => 3,
                ],
                [
                    'some_field' => 'baz',
                    'id' => 4,
                ],
            ],
        ], 'id');

        $this->assertEquals($results->all(), [
            1,
            2,
            3,
            4,
        ]);
    }

    public function test_returns_primary_keys_when_custom_array_order_present()
    {
        $engine = m::mock(MeilisearchEngine::class);
        $builder = m::mock(Builder::class);

        $model = m::mock(stdClass::class);
        $model->shouldReceive(['getScoutKeyName' => 'custom_key']);
        $builder->model = $model;

        $engine->shouldReceive('keys')->passthru();

        $engine
            ->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $engine
            ->shouldReceive('mapIdsFrom')
            ->once()
            ->with([], 'custom_key');

        $engine->keys($builder);
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive(['getScoutKeyName' => 'id']);
        $model->shouldReceive('getScoutModelsByIds')->andReturn($models = Collection::make([
            new SearchableModel(['id' => 1, 'name' => 'test']),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'totalHits' => 1,
            'hits' => [
                ['id' => 1, '_rankingScore' => 0.86],
            ],
        ], $model);

        $this->assertCount(1, $results);
        $this->assertEquals(['id' => 1, 'name' => 'test'], $results->first()->toArray());
        $this->assertEquals(['_rankingScore' => 0.86], $results->first()->scoutMetadata());
    }

    public function test_map_method_respects_order()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive(['getScoutKeyName' => 'id']);
        $model->shouldReceive('getScoutModelsByIds')->andReturn($models = Collection::make([
            new SearchableModel(['id' => 1]),
            new SearchableModel(['id' => 2]),
            new SearchableModel(['id' => 3]),
            new SearchableModel(['id' => 4]),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'totalHits' => 4,
            'hits' => [
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
        $engine = new MeilisearchEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive(['getScoutKeyName' => 'id']);
        $model->shouldReceive('queryScoutModelsByIds->cursor')->andReturn($models = LazyCollection::make([
            new SearchableModel(['id' => 1, 'name' => 'test']),
        ]));
        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, [
            'totalHits' => 1,
            'hits' => [
                ['id' => 1, '_rankingScore' => 0.86],
            ],
        ], $model);

        $this->assertEquals(1, count($results));
        $this->assertEquals(['id' => 1, 'name' => 'test'], $results->first()->toArray());
        $this->assertEquals(['_rankingScore' => 0.86], $results->first()->scoutMetadata());
    }

    public function test_lazy_map_method_respects_order()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive(['getScoutKeyName' => 'id']);
        $model->shouldReceive('queryScoutModelsByIds->cursor')->andReturn($models = LazyCollection::make([
            new SearchableModel(['id' => 1]),
            new SearchableModel(['id' => 2]),
            new SearchableModel(['id' => 3]),
            new SearchableModel(['id' => 4]),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, [
            'totalHits' => 4,
            'hits' => [
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
        $index->shouldReceive('addDocuments')->once()->with([[
            'meilisearch-key' => 'my-meilisearch-key.5',
            'id' => 5,
        ]], 'meilisearch-key');

        $engine = new MeilisearchEngine($client);
        $engine->update(Collection::make([new MeilisearchCustomKeySearchableModel(['id' => 5])]));
    }

    public function test_flush_a_model_with_a_custom_meilisearch_key()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteAllDocuments');

        $engine = new MeilisearchEngine($client);
        $engine->flush(new MeilisearchCustomKeySearchableModel());
    }

    public function test_update_empty_searchable_array_does_not_add_documents_to_index()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldNotReceive('addDocuments');

        $engine = new MeilisearchEngine($client);
        $engine->update(Collection::make([new EmptySearchableModel()]));
    }

    public function test_pagination_correct_parameters()
    {
        $perPage = 5;
        $page = 2;

        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->with('mustang', [
            'filter' => 'foo=1',
            'hitsPerPage' => $perPage,
            'page' => $page,
        ]);

        $engine = new MeilisearchEngine($client);
        $builder = new Builder(new SearchableModel(), 'mustang', function ($meilisearch, $query, $options) {
            $options['filter'] = 'foo=1';

            return $meilisearch->search($query, $options);
        });
        $engine->paginate($builder, $perPage, $page);
    }

    public function test_pagination_sorted_parameter()
    {
        $perPage = 5;
        $page = 2;

        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->with('mustang', [
            'filter' => 'foo=1',
            'hitsPerPage' => $perPage,
            'page' => $page,
            'sort' => ['name:asc'],
        ]);

        $engine = new MeilisearchEngine($client);
        $builder = new Builder(new SearchableModel(), 'mustang', function ($meilisearch, $query, $options) {
            $options['filter'] = 'foo=1';

            return $meilisearch->search($query, $options);
        });
        $builder->orderBy('name', 'asc');
        $engine->paginate($builder, $perPage, $page);
    }

    public function test_update_empty_searchable_array_from_soft_deleted_model_does_not_add_documents_to_index()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->andReturn(m::mock(Indexes::class));
        $client->shouldReceive('index')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldNotReceive('addDocuments');

        $engine = new MeilisearchEngine($client, true);
        $engine->update(Collection::make([new SoftDeletedEmptySearchableModel()]));
    }

    public function test_engine_forwards_calls_to_meilisearch_client()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('testMethodOnClient')->once();

        $engine = new MeilisearchEngine($client);
        $engine->testMethodOnClient();
    }

    public function test_updating_empty_eloquent_collection_does_nothing()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);
        $engine->update(new Collection());
        $this->assertTrue(true);
    }

    public function test_performing_search_without_callback_works()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->once()->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->andReturn([]);

        $engine = new MeilisearchEngine($client);
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
            'filter' => 'foo="bar" AND key="value"',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $engine = new MeilisearchEngine($client);
        $engine->search($builder);
    }

    public function test_where_in_conditions_are_applied()
    {
        $builder = new Builder(new SearchableModel(), '');
        $builder->where('foo', 'bar');
        $builder->where('bar', 'baz');
        $builder->whereIn('qux', [1, 2]);
        $builder->whereIn('quux', [1, 2]);
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->once()->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'foo="bar" AND bar="baz" AND qux IN [1, 2] AND quux IN [1, 2]',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $engine = new MeilisearchEngine($client);
        $engine->search($builder);
    }

    public function test_where_not_in_conditions_are_applied()
    {
        $builder = new Builder(new SearchableModel(), '');
        $builder->where('foo', 'bar');
        $builder->where('bar', 'baz');
        $builder->whereIn('qux', [1, 2]);
        $builder->whereIn('quux', [1, 2]);
        $builder->whereNotIn('eaea', [3]);
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->once()->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'foo="bar" AND bar="baz" AND qux IN [1, 2] AND quux IN [1, 2] AND eaea NOT IN [3]',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $engine = new MeilisearchEngine($client);
        $engine->search($builder);
    }

    public function test_where_in_conditions_are_applied_without_other_conditions()
    {
        $builder = new Builder(new SearchableModel(), '');
        $builder->whereIn('qux', [1, 2]);
        $builder->whereIn('quux', [1, 2]);
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->once()->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'qux IN [1, 2] AND quux IN [1, 2]',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $engine = new MeilisearchEngine($client);
        $engine->search($builder);
    }

    public function test_where_not_in_conditions_are_applied_without_other_conditions()
    {
        $builder = new Builder(new SearchableModel(), '');
        $builder->whereIn('qux', [1, 2]);
        $builder->whereIn('quux', [1, 2]);
        $builder->whereNotIn('eaea', [3]);
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->once()->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'qux IN [1, 2] AND quux IN [1, 2] AND eaea NOT IN [3]',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $engine = new MeilisearchEngine($client);
        $engine->search($builder);
    }

    public function test_empty_where_in_conditions_are_applied_correctly()
    {
        $builder = new Builder(new SearchableModel(), '');
        $builder->where('foo', 'bar');
        $builder->where('bar', 'baz');
        $builder->whereIn('qux', []);
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->once()->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'foo="bar" AND bar="baz" AND qux IN []',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $engine = new MeilisearchEngine($client);
        $engine->search($builder);
    }

    public function test_engine_returns_hits_entry_from_search_response()
    {
        $this->assertTrue(3 === (new MeilisearchEngine(m::mock(Client::class)))->getTotalCount([
            'totalHits' => 3,
        ]));
    }

    public function test_delete_all_indexes_works_with_pagination()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('getIndexes')->andReturn($indexesResults = m::mock(IndexesResults::class));

        $indexesResults->shouldReceive('getResults')->once();

        $engine = new MeilisearchEngine($client);
        $engine->deleteAllIndexes();
    }
}

class MeilisearchCustomKeySearchableModel extends SearchableModel
{
    public function getScoutKey()
    {
        return 'my-meilisearch-key.'.$this->getKey();
    }

    public function getScoutKeyName()
    {
        return 'meilisearch-key';
    }
}
