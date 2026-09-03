<?php

namespace Laravel\Scout\Tests\Unit;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Endpoints\Indices;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\ElasticsearchEngine;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Mockery as m;
use Nyholm\Psr7\Response;
use Orchestra\Testbench\Concerns\InteractsWithMockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as Psr18Client;
use Psr\Http\Message\RequestInterface;
use stdClass;

class ElasticsearchEngineTest extends TestCase
{
    use InteractsWithMockery;

    /**
     * The HTTP requests captured from the Elasticsearch client.
     *
     * @var array
     */
    protected $requests = [];

    protected function setUp(): void
    {
        Config::shouldReceive('get')->with('scout.after_commit', m::any())->andReturn(false);
        Config::shouldReceive('get')->with('scout.soft_delete', m::any())->andReturn(false);
    }

    protected function tearDown(): void
    {
        Container::getInstance()->flush();

        $this->tearDownTheTestEnvironmentUsingMockery();
    }

    public function test_update_sends_bulk_index_operations()
    {
        $engine = $this->engine();

        $engine->update(new Collection([
            new SearchableModel(['id' => 1, 'name' => 'Taylor']),
            new SearchableModel(['id' => 2, 'name' => 'Abigail']),
        ]));

        $this->assertCount(1, $this->requests);

        $this->assertSame('POST', $this->request()->getMethod());
        $this->assertSame('/_bulk', $this->request()->getUri()->getPath());
        $this->assertEquals([
            [
                'index' => ['_index' => 'table', '_id' => 1],
            ],
            [
                'id' => 1,
                'name' => 'Taylor',
            ],
            [
                'index' => ['_index' => 'table', '_id' => 2],
            ],
            [
                'id' => 2,
                'name' => 'Abigail',
            ],
        ], $this->ndjsonBody());
    }

    public function test_update_includes_soft_delete_metadata_when_enabled()
    {
        $engine = $this->engine([], true);

        $engine->update(new Collection([
            (new ElasticsearchSoftDeleteSearchableModel)->forceFill(['id' => 1, 'name' => 'Taylor']),
        ]));

        $this->assertEquals([
            [
                'index' => ['_index' => 'table', '_id' => 1],
            ],
            [
                'id' => 1,
                'name' => 'Taylor',
                '__soft_deleted' => 0,
            ],
        ], $this->ndjsonBody());
    }

    public function test_updating_empty_eloquent_collection_does_nothing()
    {
        $this->engine()->update(new Collection);

        $this->assertCount(0, $this->requests);
    }

    public function test_delete_sends_bulk_delete_operations()
    {
        $this->engine()->delete(new Collection([
            new SearchableModel(['id' => 1]),
        ]));

        $this->assertCount(1, $this->requests);

        $this->assertSame('POST', $this->request()->getMethod());
        $this->assertSame('/_bulk', $this->request()->getUri()->getPath());
        $this->assertEquals([
            [
                'delete' => ['_index' => 'table', '_id' => 1],
            ],
        ], $this->ndjsonBody());
    }

    public function test_search_sends_multi_match_query()
    {
        $this->engine()->search($this->builder('hello'));

        $this->assertSame('POST', $this->request()->getMethod());
        $this->assertSame('/table/_search', $this->request()->getUri()->getPath());
        $this->assertEquals([
            'query' => [
                'bool' => [
                    'must' => [
                        ['multi_match' => ['query' => 'hello', 'type' => 'bool_prefix', 'fields' => ['*']]],
                    ],
                ],
            ],
        ], $this->jsonBody());
    }

    public function test_search_without_query_matches_all_documents()
    {
        $this->engine()->search($this->builder());

        $this->assertEquals([
            'query' => ['match_all' => []],
        ], $this->jsonBody());
    }

    public function test_search_with_wildcard_query_matches_all_documents()
    {
        $this->engine()->search($this->builder('*'));

        $this->assertEquals([
            'query' => ['match_all' => []],
        ], $this->jsonBody());
    }

    public function test_search_translates_wheres_into_filters()
    {
        $builder = $this->builder()
            ->where('name', 'Taylor')
            ->where('price', '>', 100)
            ->where('price', '<=', 500)
            ->where('foo', null)
            ->where('bar', '!=', null)
            ->whereIn('id', [1, 2])
            ->whereNotIn('color', ['red']);

        $this->engine()->search($builder);

        $this->assertEquals([
            'query' => [
                'bool' => [
                    'filter' => [
                        ['term' => ['name' => 'Taylor']],
                        ['range' => ['price' => ['gt' => 100]]],
                        ['range' => ['price' => ['lte' => 500]]],
                        ['bool' => ['must_not' => ['exists' => ['field' => 'foo']]]],
                        ['exists' => ['field' => 'bar']],
                        ['terms' => ['id' => [1, 2]]],
                        ['bool' => ['must_not' => ['terms' => ['color' => ['red']]]]],
                    ],
                ],
            ],
        ], $this->jsonBody());
    }

    public function test_search_applies_soft_delete_filter()
    {
        $this->engine()->search(new Builder(new SearchableModel, 'hello', null, true));

        $this->assertEquals([
            'query' => [
                'bool' => [
                    'must' => [
                        ['multi_match' => ['query' => 'hello', 'type' => 'bool_prefix', 'fields' => ['*']]],
                    ],
                    'filter' => [
                        ['term' => ['__soft_deleted' => 0]],
                    ],
                ],
            ],
        ], $this->jsonBody());
    }

    public function test_search_with_limit_and_orders()
    {
        $builder = $this->builder('hello')->take(5)->orderBy('name', 'desc');

        $this->engine()->search($builder);

        $this->assertSame('size=5', $this->request()->getUri()->getQuery());
        $this->assertEquals([
            'query' => [
                'bool' => [
                    'must' => [
                        ['multi_match' => ['query' => 'hello', 'type' => 'bool_prefix', 'fields' => ['*']]],
                    ],
                ],
            ],
            'sort' => [
                ['name' => 'desc'],
            ],
        ], $this->jsonBody());
    }

    public function test_paginate_sends_from_and_size()
    {
        $this->engine()->paginate($this->builder('hello'), 15, 2);

        $this->assertSame('/table/_search', $this->request()->getUri()->getPath());
        $this->assertSame('from=15&size=15', $this->request()->getUri()->getQuery());
    }

    public function test_search_with_callback()
    {
        $client = $this->client();

        $engine = new ElasticsearchEngine($client);

        $builder = $this->builder('hello', function ($elasticsearch, $query, $searchParams) use ($client) {
            $this->assertSame($client, $elasticsearch);
            $this->assertSame('hello', $query);
            $this->assertSame('table', $searchParams['index']);

            return 'result';
        });

        $this->assertSame('result', $engine->search($builder));
        $this->assertCount(0, $this->requests);
    }

    public function test_map_ids_returns_correct_values_of_primary_key()
    {
        $engine = $this->engine();

        $results = [
            'hits' => [
                'hits' => [
                    ['_id' => '1', '_source' => ['id' => 1]],
                    ['_id' => '2', '_source' => ['id' => 2]],
                    ['_id' => '3', '_source' => ['id' => 3]],
                ],
            ],
        ];

        $this->assertEquals([1, 2, 3], $engine->mapIdsFrom($results, 'id')->all());
        $this->assertEquals(['1', '2', '3'], $engine->mapIds($results)->all());
    }

    public function test_map_ids_returns_empty_collection_if_no_hits()
    {
        $results = ['hits' => ['hits' => []]];

        $this->assertCount(0, $this->engine()->mapIdsFrom($results, 'id'));
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $engine = $this->engine();

        $model = m::mock(stdClass::class);
        $model->shouldReceive(['getScoutKeyName' => 'id']);
        $model->shouldReceive('getScoutModelsByIds')->andReturn($models = Collection::make([
            new SearchableModel(['id' => 1, 'name' => 'test']),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'hits' => [
                'total' => ['value' => 1],
                'hits' => [
                    ['_id' => '1', '_source' => ['id' => 1, 'name' => 'test', '__soft_deleted' => 0]],
                ],
            ],
        ], $model);

        $this->assertCount(1, $results);
        $this->assertEquals(['id' => 1, 'name' => 'test'], $results->first()->toArray());
        $this->assertEquals(['__soft_deleted' => 0], $results->first()->scoutMetadata());
    }

    public function test_map_method_respects_order()
    {
        $engine = $this->engine();

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
            'hits' => [
                'total' => ['value' => 4],
                'hits' => [
                    ['_id' => '1', '_source' => ['id' => 1]],
                    ['_id' => '2', '_source' => ['id' => 2]],
                    ['_id' => '4', '_source' => ['id' => 4]],
                    ['_id' => '3', '_source' => ['id' => 3]],
                ],
            ],
        ], $model);

        $this->assertCount(4, $results);
        $this->assertEquals([1, 2, 4, 3], $results->pluck('id')->all());
    }

    public function test_lazy_map_correctly_maps_results_to_models()
    {
        $engine = $this->engine();

        $model = m::mock(stdClass::class);
        $model->shouldReceive(['getScoutKeyName' => 'id']);
        $model->shouldReceive('queryScoutModelsByIds->cursor')->andReturn($models = LazyCollection::make([
            new SearchableModel(['id' => 1, 'name' => 'test']),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, [
            'hits' => [
                'total' => ['value' => 1],
                'hits' => [
                    ['_id' => '1', '_source' => ['id' => 1, 'name' => 'test', '__soft_deleted' => 0]],
                ],
            ],
        ], $model);

        $this->assertCount(1, $results);
        $this->assertEquals(['id' => 1, 'name' => 'test'], $results->first()->toArray());
        $this->assertEquals(['__soft_deleted' => 0], $results->first()->scoutMetadata());
    }

    public function test_lazy_map_method_respects_order()
    {
        $engine = $this->engine();

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
            'hits' => [
                'total' => ['value' => 4],
                'hits' => [
                    ['_id' => '1', '_source' => ['id' => 1]],
                    ['_id' => '2', '_source' => ['id' => 2]],
                    ['_id' => '4', '_source' => ['id' => 4]],
                    ['_id' => '3', '_source' => ['id' => 3]],
                ],
            ],
        ], $model);

        $this->assertCount(4, $results);
        $this->assertEquals([1, 2, 4, 3], $results->pluck('id')->all());
    }

    public function test_engine_returns_total_count_from_search_response()
    {
        $this->assertTrue(
            $this->engine()->getTotalCount(['hits' => ['total' => ['value' => 3]]]) === 3
        );
    }

    public function test_flush_deletes_all_documents_from_the_index()
    {
        $this->engine()->flush(new SearchableModel);

        $this->assertSame('POST', $this->request()->getMethod());
        $this->assertSame('/table/_delete_by_query', $this->request()->getUri()->getPath());
        $this->assertEquals([
            'query' => ['match_all' => []],
        ], $this->jsonBody());
    }

    public function test_create_index()
    {
        $this->engine()->createIndex('table', ['body' => ['settings' => ['number_of_shards' => 1]]]);

        $this->assertSame('PUT', $this->request()->getMethod());
        $this->assertSame('/table', $this->request()->getUri()->getPath());
        $this->assertEquals([
            'settings' => ['number_of_shards' => 1],
        ], $this->jsonBody());
    }

    public function test_update_index_settings_updates_settings_and_mappings()
    {
        $this->engine()->updateIndexSettings('table', [
            'settings' => ['number_of_replicas' => 2],
            'mappings' => ['properties' => ['name' => ['type' => 'text']]],
        ]);

        $this->assertCount(2, $this->requests);

        $this->assertSame('/table/_settings', $this->request(0)->getUri()->getPath());
        $this->assertEquals(['number_of_replicas' => 2], $this->jsonBody(0));

        $this->assertSame('/table/_mapping', $this->request(1)->getUri()->getPath());
        $this->assertEquals(['properties' => ['name' => ['type' => 'text']]], $this->jsonBody(1));
    }

    public function test_configure_soft_delete_filter_adds_mapping()
    {
        $settings = $this->engine()->configureSoftDeleteFilter(['settings' => ['number_of_shards' => 1]]);

        $this->assertEquals([
            'settings' => ['number_of_shards' => 1],
            'mappings' => ['properties' => ['__soft_deleted' => ['type' => 'integer']]],
        ], $settings);
    }

    public function test_delete_index()
    {
        $this->engine()->deleteIndex('table');

        $this->assertSame('DELETE', $this->request()->getMethod());
        $this->assertSame('/table', $this->request()->getUri()->getPath());
    }

    public function test_delete_all_indexes_only_deletes_indexes_with_scout_prefix()
    {
        Config::shouldReceive('get')->with('scout.prefix')->andReturn('app_');

        $engine = $this->engine([
            '{"users": {}, "app_users": {}}',
            '{}',
        ]);

        $engine->deleteAllIndexes();

        $this->assertCount(2, $this->requests);

        $this->assertSame('GET', $this->request(0)->getMethod());

        $this->assertSame('DELETE', $this->request(1)->getMethod());
        $this->assertSame('/app_users', $this->request(1)->getUri()->getPath());
    }

    public function test_engine_forwards_calls_to_elasticsearch_client()
    {
        $this->assertInstanceOf(Indices::class, $this->engine()->indices());
    }

    /**
     * Create a new Elasticsearch engine instance.
     *
     * @param  bool  $softDelete
     * @return ElasticsearchEngine
     */
    protected function engine(array $responses = [], $softDelete = false)
    {
        return new ElasticsearchEngine($this->client($responses), $softDelete);
    }

    /**
     * Create an Elasticsearch client with a mocked HTTP handler.
     *
     * @return Client
     */
    protected function client(array $responses = [])
    {
        $http = m::mock(Psr18Client::class);

        $http->shouldReceive('sendRequest')->andReturnUsing(function (RequestInterface $request) use (&$responses) {
            $this->requests[] = $request;

            $body = array_shift($responses) ?? '{}';

            return new Response(200, [
                'Content-Type' => 'application/json',
                'X-elastic-product' => 'Elasticsearch',
            ], $body);
        });

        return ClientBuilder::create()
            ->setHosts(['http://localhost:9200'])
            ->setHttpClient($http)
            ->build();
    }

    /**
     * Create a new search builder instance.
     *
     * @param  string  $query
     * @param  \Closure|null  $callback
     * @return Builder
     */
    protected function builder($query = '', $callback = null)
    {
        return new Builder(new SearchableModel, $query, $callback);
    }

    /**
     * Get the captured request at the given position.
     *
     * @param  int  $position
     * @return RequestInterface
     */
    protected function request($position = 0)
    {
        return $this->requests[$position];
    }

    /**
     * Get the decoded JSON body of the captured request.
     *
     * @param  int  $position
     * @return array
     */
    protected function jsonBody($position = 0)
    {
        return json_decode((string) $this->request($position)->getBody(), true);
    }

    /**
     * Get the decoded new-line delimited JSON body of the captured request.
     *
     * @return array
     */
    protected function ndjsonBody()
    {
        $lines = explode("\n", trim((string) $this->request()->getBody()));

        return array_map(fn ($line) => json_decode($line, true), $lines);
    }
}

class ElasticsearchSoftDeleteSearchableModel extends SearchableModel
{
    use SoftDeletes;
}
