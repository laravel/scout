<?php

namespace Laravel\Scout\Tests\Unit;

use Http\Client\Exception;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\TypesenseEngine;
use Laravel\Scout\Exceptions\ScoutException;
use Laravel\Scout\Tests\Fixtures\FakeEmbeddings;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Laravel\Scout\Tests\Fixtures\SearchableModelWithPrecomputedEmbedding;
use Mockery as m;
use Orchestra\Testbench\Concerns\InteractsWithMockery;
use PHPUnit\Framework\TestCase;
use Typesense\Client as TypesenseClient;
use Typesense\Collection as TypesenseCollection;
use Typesense\Documents;
use Typesense\Exceptions\RequestMalformed;
use Typesense\Exceptions\TypesenseClientError;
use Typesense\MultiSearch;

class TypesenseEngineTest extends TestCase
{
    use InteractsWithMockery;

    protected TypesenseEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Facade::clearResolvedInstances();
        Config::shouldReceive('get')->with('scout.after_commit', m::any())->andReturn(false);
        Config::shouldReceive('get')->with('scout.soft_delete', m::any())->andReturn(false);

        // Mock the Typesense client and pass it to the engine constructor
        $typesenseClient = $this->createMock(TypesenseClient::class);
        $this->engine = $this->getMockBuilder(TypesenseEngine::class)
            ->setConstructorArgs([$typesenseClient, 1000])
            ->onlyMethods(['getOrCreateCollectionFromModel', 'buildSearchParameters'])
            ->getMock();
    }

    protected function tearDown(): void
    {
        Container::getInstance()->flush();

        $this->tearDownTheTestEnvironmentUsingMockery();
    }

    /**
     * Call protected/private method of a class.
     *
     * @param  object  &$object  Instantiated object that we will run method on.
     * @param  string  $methodName  Method name to call
     * @param  array  $parameters  Array of parameters to pass into method.
     * @return mixed Method return.
     */
    public function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    public function test_filters_method()
    {
        $builder = m::mock(Builder::class);
        $builder->wheres = [
            ['field' => 'status', 'value' => 'active', 'operator' => '='],
            ['field' => 'age', 'value' => 25, 'operator' => '='],
        ];
        $builder->whereIns = [
            'category' => ['electronics', 'books'],
        ];
        $builder->whereNotIns = [
            'category' => ['furniture', 'phones'],
        ];

        $result = $this->invokeMethod($this->engine, 'filters', [$builder]);

        $expected = 'status:=active && age:=25 && category:=[electronics, books] && category:!=[furniture, phones]';
        $this->assertEquals($expected, $result);
    }

    public function test_parse_filter_value_method()
    {
        $this->assertEquals('true', $this->invokeMethod($this->engine, 'parseFilterValue', [true]));
        $this->assertEquals('false', $this->invokeMethod($this->engine, 'parseFilterValue', [false]));
        $this->assertEquals('25', $this->invokeMethod($this->engine, 'parseFilterValue', [25]));
        $this->assertEquals('3.14', $this->invokeMethod($this->engine, 'parseFilterValue', [3.14]));
        $this->assertEquals('test', $this->invokeMethod($this->engine, 'parseFilterValue', ['test']));
        $this->assertEquals('test "quoted"', $this->invokeMethod($this->engine, 'parseFilterValue', ['test "quoted"']));
        $this->assertEquals('`special value`', $this->invokeMethod($this->engine, 'parseFilterValue', ['`special value`']));

        $nestedArray = ['a', ['b', 'c'], 'd'];
        $expectedNested = ['a', ['b', 'c'], 'd'];
        $this->assertEquals($expectedNested, $this->invokeMethod($this->engine, 'parseFilterValue', [$nestedArray]));
    }

    public function test_parse_where_filter_method()
    {
        $this->assertEquals('status:=active', $this->invokeMethod($this->engine, 'parseWhereFilter', ['active', 'status']));
        $this->assertEquals('age:=25', $this->invokeMethod($this->engine, 'parseWhereFilter', ['25', 'age']));
        $this->assertEquals('tags:tag1tag2tag3', $this->invokeMethod($this->engine, 'parseWhereFilter', [['tag1', 'tag2', 'tag3'], 'tags']));
    }

    public function test_parse_where_in_filter_method()
    {
        $this->assertEquals('category:=[electronics, books]', $this->invokeMethod($this->engine, 'parseWhereInFilter', [['electronics', 'books'], 'category']));
        $this->assertEquals('id:=[1, 2, 3]', $this->invokeMethod($this->engine, 'parseWhereInFilter', [[1, 2, 3], 'id']));
    }

    public function test_parse_where_not_in_filter_metheod()
    {
        $this->assertEquals('category:!=[electronics, books]', $this->invokeMethod($this->engine, 'parseWhereNotInFilter', [['electronics', 'books'], 'category']));
        $this->assertEquals('id:!=[1, 2, 3]', $this->invokeMethod($this->engine, 'parseWhereNotInFilter', [[1, 2, 3], 'id']));
    }

    public function test_update_method(): void
    {
        Config::shouldReceive('get')->with('scout.typesense.import_action', m::any())->andReturn('upsert');

        // Mock models and their methods
        $models = [
            $this->createMock(SearchableModel::class),
        ];

        $models[0]->expects($this->once())
            ->method('toSearchableArray')
            ->willReturn(['id' => 1, 'name' => 'Model 1']);

        $models[0]->expects($this->once())
            ->method('scoutMetadata')
            ->willReturn([]);

        // Mock the getOrCreateCollectionFromModel method
        $collection = $this->createMock(TypesenseCollection::class);
        $documents = $this->createMock(Documents::class);
        $collection->expects($this->once())
            ->method('getDocuments')
            ->willReturn($documents);
        $documents->expects($this->once())
            ->method('import')
            ->with(
                [['id' => 1, 'name' => 'Model 1']], ['action' => 'upsert'],
            )
            ->willReturn([[
                'success' => true,
            ]]);

        $this->engine->expects($this->once())
            ->method('getOrCreateCollectionFromModel')
            ->willReturn($collection);

        // Call the update method
        $this->engine->update(collect($models));
    }

    public function test_update_method_with_emplace_action(): void
    {
        // Override config for this specific test
        Config::shouldReceive('get')->with('scout.typesense.import_action', m::any())->andReturn('emplace');

        // Mock models and their methods
        $models = [
            $this->createMock(SearchableModel::class),
        ];

        $models[0]->expects($this->once())
            ->method('toSearchableArray')
            ->willReturn(['id' => 1, 'name' => 'Model 1']);

        $models[0]->expects($this->once())
            ->method('scoutMetadata')
            ->willReturn([]);

        // Mock the getOrCreateCollectionFromModel method
        $collection = $this->createMock(TypesenseCollection::class);
        $documents = $this->createMock(Documents::class);
        $collection->expects($this->once())
            ->method('getDocuments')
            ->willReturn($documents);
        $documents->expects($this->once())
            ->method('import')
            ->with(
                [['id' => 1, 'name' => 'Model 1']], ['action' => 'emplace'],
            )
            ->willReturn([[
                'success' => true,
            ]]);

        $this->engine->expects($this->once())
            ->method('getOrCreateCollectionFromModel')
            ->willReturn($collection);

        // Call the update method
        $this->engine->update(collect($models));
    }

    public function test_update_adds_precomputed_and_generated_embeddings_to_documents(): void
    {
        Config::shouldReceive('get')->with('scout.typesense.import_action', m::any())->andReturn('upsert');

        $this->fakeEmbeddings([[[0.3, 0.4]]]);

        $engine = $this->getMockBuilder(TypesenseEngine::class)
            ->setConstructorArgs([$this->createMock(TypesenseClient::class), 1000, [
                'model-settings' => [
                    SearchableModelWithPrecomputedEmbedding::class => [
                        'embedding' => [
                            'attribute' => 'embedding',
                            'dimensions' => 2,
                            'provider' => 'openai',
                            'model' => 'text-embedding-test',
                        ],
                    ],
                ],
            ]])
            ->onlyMethods(['getOrCreateCollectionFromModel'])
            ->getMock();

        $precomputed = new SearchableModelWithPrecomputedEmbedding(['id' => 10, 'name' => 'Precomputed']);
        $precomputed->setAttribute('embedding', [0.1, 0.2]);

        $generated = new SearchableModelWithPrecomputedEmbedding(['id' => 20, 'name' => 'Generate this']);

        $collection = $this->createMock(TypesenseCollection::class);
        $documents = $this->createMock(Documents::class);
        $collection->expects($this->once())
            ->method('getDocuments')
            ->willReturn($documents);
        $documents->expects($this->once())
            ->method('import')
            ->with(
                [
                    ['id' => 10, 'name' => 'Precomputed', 'embedding' => [0.1, 0.2]],
                    ['id' => 20, 'name' => 'Generate this', 'embedding' => [0.3, 0.4]],
                ],
                ['action' => 'upsert'],
            )
            ->willReturn([
                ['success' => true],
                ['success' => true],
            ]);

        $engine->expects($this->once())
            ->method('getOrCreateCollectionFromModel')
            ->willReturn($collection);

        $engine->update($precomputed->newCollection([$precomputed, $generated]));

        $this->assertSame([[
            'inputs' => ['Generate this'],
            'cache' => null,
            'dimensions' => 2,
            'provider' => 'openai',
            'model' => 'text-embedding-test',
        ]], FakeEmbeddings::$requests);
    }

    public function test_update_does_not_generate_embeddings_when_using_native_embeddings(): void
    {
        Config::shouldReceive('get')->with('scout.typesense.import_action', m::any())->andReturn('upsert');

        $this->fakeEmbeddings([]);

        $engine = $this->getMockBuilder(TypesenseEngine::class)
            ->setConstructorArgs([$this->createMock(TypesenseClient::class), 1000, [
                'model-settings' => [
                    SearchableModel::class => [
                        'embedding' => [
                            'attribute' => 'embedding',
                            'driver' => 'typesense',
                        ],
                    ],
                ],
            ]])
            ->onlyMethods(['getOrCreateCollectionFromModel'])
            ->getMock();

        $model = new SearchableModel(['id' => 1, 'name' => 'Model 1']);

        $collection = $this->createMock(TypesenseCollection::class);
        $documents = $this->createMock(Documents::class);
        $collection->expects($this->once())
            ->method('getDocuments')
            ->willReturn($documents);
        $documents->expects($this->once())
            ->method('import')
            ->with(
                [['id' => 1, 'name' => 'Model 1']],
                ['action' => 'upsert'],
            )
            ->willReturn([[
                'success' => true,
            ]]);

        $engine->expects($this->once())
            ->method('getOrCreateCollectionFromModel')
            ->willReturn($collection);

        $engine->update($model->newCollection([$model]));

        $this->assertSame([], FakeEmbeddings::$requests);
    }

    public function test_semantic_search_generates_a_query_vector(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'dimensions' => 2,
        ]);

        $this->fakeEmbeddings([[[0.25, 0.75]]]);

        $builder = (new Builder(new SearchableModel, 'conceptual query'))
            ->semantic(minSimilarity: 0.7);

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('*', $parameters['q']);
        $this->assertSame('name', $parameters['query_by']);
        $this->assertSame('embedding:([0.25, 0.75], distance_threshold: 0.3)', $parameters['vector_query']);
        $this->assertSame('embedding', $parameters['exclude_fields']);
        $this->assertArrayNotHasKey('vector', $parameters);
        $this->assertSame(['conceptual query'], FakeEmbeddings::$requests[0]['inputs']);
    }

    public function test_hybrid_search_accepts_a_precomputed_query_vector_and_normalizes_weights(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'dimensions' => 2,
        ]);

        $this->fakeEmbeddings([]);

        $builder = (new Builder(new SearchableModel, 'combined query'))
            ->options(['vector' => [0.4, 0.6]])
            ->hybrid(textWeight: 1, semanticWeight: 2);

        $parameters = $engine->buildSearchParameters($builder, 2, 5);

        $this->assertSame('combined query', $parameters['q']);
        $this->assertSame('name', $parameters['query_by']);
        $this->assertSame('embedding:([0.4, 0.6], alpha: '.(2 / 3).')', $parameters['vector_query']);
        $this->assertSame('embedding', $parameters['exclude_fields']);
        $this->assertArrayNotHasKey('vector', $parameters);
        $this->assertSame([], FakeEmbeddings::$requests);
    }

    public function test_semantic_search_with_native_embeddings_queries_the_embedding_field(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'driver' => 'typesense',
        ]);

        $this->fakeEmbeddings([]);

        $builder = (new Builder(new SearchableModel, 'conceptual query'))->semantic();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('conceptual query', $parameters['q']);
        $this->assertSame('embedding', $parameters['query_by']);
        $this->assertFalse($parameters['prefix']);
        $this->assertArrayNotHasKey('vector_query', $parameters);
        $this->assertSame('embedding', $parameters['exclude_fields']);
        $this->assertSame([], FakeEmbeddings::$requests);
    }

    public function test_semantic_search_with_native_embeddings_applies_a_distance_threshold(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'driver' => 'typesense',
        ]);

        $builder = (new Builder(new SearchableModel, 'conceptual query'))->semantic(minSimilarity: 0.5);

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('embedding', $parameters['query_by']);
        $this->assertSame('embedding:([], distance_threshold: 0.5)', $parameters['vector_query']);
    }

    public function test_semantic_search_with_native_embeddings_drops_per_field_parameters(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'driver' => 'typesense',
        ], 'name,description');

        $builder = (new Builder(new SearchableModel, 'conceptual query'))
            ->options(['query_by_weights' => '2,1', 'num_typos' => '2,1', 'infix' => 'off'])
            ->semantic();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('embedding', $parameters['query_by']);
        $this->assertFalse($parameters['prefix']);
        $this->assertArrayNotHasKey('query_by_weights', $parameters);
        $this->assertArrayNotHasKey('num_typos', $parameters);
        $this->assertSame('off', $parameters['infix']);
    }

    public function test_hybrid_search_with_native_embeddings_appends_the_embedding_field_to_query_by(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'driver' => 'typesense',
        ]);

        $builder = (new Builder(new SearchableModel, 'combined query'))->hybrid();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('combined query', $parameters['q']);
        $this->assertSame('name,embedding', $parameters['query_by']);
        $this->assertSame('true,false', $parameters['prefix']);
        $this->assertSame('embedding:([], alpha: 0.5)', $parameters['vector_query']);
        $this->assertSame('embedding', $parameters['exclude_fields']);
    }

    public function test_hybrid_search_with_native_embeddings_extends_per_field_parameters(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'driver' => 'typesense',
        ], 'name,description');

        $builder = (new Builder(new SearchableModel, 'combined query'))
            ->options(['query_by_weights' => '2,1', 'num_typos' => '2,1', 'prefix' => 'true,false', 'infix' => 'off'])
            ->hybrid();

        $parameters = $engine->buildSearchParameters($builder, 1, 10);

        $this->assertSame('name,description,embedding', $parameters['query_by']);
        $this->assertSame('2,1,0', $parameters['query_by_weights']);
        $this->assertSame('2,1,0', $parameters['num_typos']);
        $this->assertSame('true,false,false', $parameters['prefix']);
        $this->assertSame('off', $parameters['infix']);
    }

    public function test_hybrid_search_requires_a_keyword_field_in_query_by(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'driver' => 'typesense',
        ], '');

        $builder = (new Builder(new SearchableModel, 'combined query'))->hybrid();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Typesense hybrid searches require at least one keyword field in the [query_by] search parameter.');

        $engine->buildSearchParameters($builder, 1, 10);
    }

    public function test_semantic_search_cannot_be_combined_with_a_custom_vector_query_option(): void
    {
        $engine = $this->semanticEngine([
            'attribute' => 'embedding',
            'dimensions' => 2,
        ]);

        $builder = (new Builder(new SearchableModel, 'conceptual query'))
            ->options(['vector_query' => 'embedding:([], k: 10)'])
            ->semantic();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Typesense semantic and hybrid searches cannot be combined with a custom [vector_query] option.');

        $engine->buildSearchParameters($builder, 1, 10);
    }

    public function test_semantic_search_requires_embedding_settings(): void
    {
        Container::getInstance()->instance('config', new Repository(['scout' => []]));

        $engine = new TypesenseEngine($this->createMock(TypesenseClient::class), 1000);

        $builder = (new Builder(new SearchableModel, 'conceptual query'))->semantic();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('No Typesense embedding settings have been configured for ['.SearchableModel::class.'].');

        $engine->buildSearchParameters($builder, 1, 10);
    }

    public function test_searches_with_vector_queries_use_the_multi_search_endpoint(): void
    {
        $multiSearch = $this->createMock(MultiSearch::class);
        $engine = $this->multiSearchEngine($multiSearch);

        $engine->expects($this->once())
            ->method('buildSearchParameters')
            ->willReturn([
                'q' => '*',
                'query_by' => 'name',
                'vector_query' => 'embedding:([0.1, 0.2])',
            ]);

        $multiSearch->expects($this->once())
            ->method('perform')
            ->with([
                'searches' => [
                    [
                        'q' => '*',
                        'query_by' => 'name',
                        'vector_query' => 'embedding:([0.1, 0.2])',
                        'collection' => 'table',
                    ],
                ],
            ])
            ->willReturn(['results' => [['found' => 1, 'hits' => [['document' => ['id' => '1']]]]]]);

        $results = $engine->search(new Builder(new SearchableModel, 'conceptual query'));

        $this->assertSame(1, $results['found']);
        $this->assertSame('1', $results['hits'][0]['document']['id']);
    }

    public function test_multi_search_errors_are_converted_to_typesense_exceptions(): void
    {
        $multiSearch = $this->createMock(MultiSearch::class);
        $engine = $this->multiSearchEngine($multiSearch);

        $engine->method('buildSearchParameters')->willReturn([
            'q' => '*',
            'query_by' => 'name',
            'vector_query' => 'embedding:([0.1, 0.2])',
        ]);

        $multiSearch->method('perform')->willReturn([
            'results' => [['code' => 400, 'error' => 'Query string exceeds max allowed length.']],
        ]);

        $this->expectException(RequestMalformed::class);
        $this->expectExceptionMessage('Query string exceeds max allowed length.');

        $engine->search(new Builder(new SearchableModel, 'conceptual query'));
    }

    public function test_multi_search_creates_missing_collections_and_retries(): void
    {
        $multiSearch = $this->createMock(MultiSearch::class);
        $engine = $this->multiSearchEngine($multiSearch);

        $engine->method('buildSearchParameters')->willReturn([
            'q' => '*',
            'query_by' => 'name',
            'vector_query' => 'embedding:([0.1, 0.2])',
        ]);

        $multiSearch->expects($this->exactly(2))
            ->method('perform')
            ->willReturnOnConsecutiveCalls(
                ['results' => [['code' => 404, 'error' => 'Not found.']]],
                ['results' => [['found' => 0, 'hits' => []]]],
            );

        $results = $engine->search(new Builder(new SearchableModel, 'conceptual query'));

        $this->assertSame(0, $results['found']);
    }

    public function test_delete_method(): void
    {
        // Mock models and their methods
        $models = [
            $this->createMock(SearchableModel::class),
        ];

        $models[0]->expects($this->once())
            ->method('getScoutKey')
            ->willReturn(1);

        // Mock the getOrCreateCollectionFromModel and deleteDocument methods
        $collection = $this->createMock(TypesenseCollection::class);
        $documents = $this->createMock(Documents::class);
        $collection->expects($this->once())
            ->method('getDocuments')
            ->willReturn($documents);

        $this->engine->expects($this->once())
            ->method('getOrCreateCollectionFromModel')
            ->willReturn($collection);

        // Call the delete method
        $this->engine->delete(collect($models));
    }

    public function test_search_method(): void
    {
        // Mock the Builder
        $builder = $this->createMock(Builder::class);

        // Mock the buildSearchParameters method
        $this->engine->expects($this->once())
            ->method('buildSearchParameters')
            ->with($builder, 1)
            ->willReturn([
                'q' => $builder->query,
                'query_by' => 'id',
                'filter_by' => '',
                'per_page' => 10,
                'page' => 1,
                'highlight_start_tag' => '<mark>',
                'highlight_end_tag' => '</mark>',
                'snippet_threshold' => 30,
                'exhaustive_search' => false,
                'use_cache' => false,
                'cache_ttl' => 60,
                'prioritize_exact_match' => true,
                'enable_overrides' => true,
                'highlight_affix_num_tokens' => 4,
            ]);

        // Call the search method
        $this->engine->search($builder);
    }

    public function test_paginate_method(): void
    {
        // Mock the Builder
        $builder = $this->createMock(Builder::class);

        // Mock the buildSearchParameters method
        $this->engine->expects($this->once())
            ->method('buildSearchParameters')
            ->with($builder, 2, 10)
            ->willReturn([
                'q' => $builder->query,
                'query_by' => 'id',
                'filter_by' => '',
                'per_page' => 10,
                'page' => 2,
                'highlight_start_tag' => '<mark>',
                'highlight_end_tag' => '</mark>',
                'snippet_threshold' => 30,
                'exhaustive_search' => false,
                'use_cache' => false,
                'cache_ttl' => 60,
                'prioritize_exact_match' => true,
                'enable_overrides' => true,
                'highlight_affix_num_tokens' => 4,
            ]);

        // Call the paginate method
        $this->engine->paginate($builder, 10, 2);
    }

    public function test_map_ids_method(): void
    {
        // Sample search results
        $results = [
            'hits' => [
                ['document' => ['id' => 1]],
                ['document' => ['id' => 2]],
                ['document' => ['id' => 3]],
            ],
        ];

        // Call the mapIds method
        $mappedIds = $this->engine->mapIds($results);

        // Assert that the result is an instance of Collection
        $this->assertInstanceOf(Collection::class, $mappedIds);

        // Assert that the mapped IDs match the expected IDs
        $this->assertEquals([1, 2, 3], $mappedIds->toArray());
    }

    public function test_get_total_count_method(): void
    {
        // Sample search results with 'found' key
        $resultsWithFound = ['found' => 5];

        // Sample search results without 'found' key
        $resultsWithoutFound = ['hits' => []];

        // Call the getTotalCount method with results containing 'found'
        $totalCountWithFound = $this->engine->getTotalCount($resultsWithFound);

        // Call the getTotalCount method with results without 'found'
        $totalCountWithoutFound = $this->engine->getTotalCount($resultsWithoutFound);

        // Assert that the total count is correctly extracted from the results
        $this->assertEquals(5, $totalCountWithFound);
        $this->assertEquals(0, $totalCountWithoutFound);
    }

    public function test_flush_method(): void
    {
        // Mock a model instance
        $model = $this->createMock(Model::class);

        $collection = $this->createMock(TypesenseCollection::class);
        // Mock the getOrCreateCollectionFromModel method
        $this->engine->expects($this->once())
            ->method('getOrCreateCollectionFromModel')
            ->with($model)
            ->willReturn($collection);

        // Mock the delete method of the TypesenseCollection
        $collection->expects($this->once())
            ->method('delete');

        // Call the flush method
        $this->engine->flush($model);
    }

    public function test_create_index_method_throws_exception(): void
    {
        // Define the expected exception class and message
        $expectedException = \Exception::class;
        $expectedExceptionMessage = 'Typesense indexes are created automatically upon adding objects.';

        // Use PHPUnit's expectException method to assert that the specified exception is thrown
        $this->expectException($expectedException);
        $this->expectExceptionMessage($expectedExceptionMessage);

        // Call the createIndex method which should throw an exception
        $this->engine->createIndex('test_index');
    }

    /**
     * @throws Exception
     * @throws TypesenseClientError
     */
    public function test_set_search_params_method(): void
    {
        // Mock the Builder
        $builder = $this->createMock(Builder::class);

        // Mock the buildSearchParameters method
        $this->engine->expects($this->once())
            ->method('buildSearchParameters')
            ->with($builder, 1)
            ->willReturn([
                'q' => $builder->query,
                'query_by' => 'id',
                'filter_by' => '',
                'per_page' => 10,
                'page' => 1,
                'highlight_start_tag' => '<mark>',
                'highlight_end_tag' => '</mark>',
                'snippet_threshold' => 30,
                'exhaustive_search' => false,
                'use_cache' => false,
                'cache_ttl' => 60,
                'prioritize_exact_match' => true,
                'enable_overrides' => true,
                'highlight_affix_num_tokens' => 4,
            ]);

        // Set search options
        $builder->options(['query_by' => 'id']);
        // Call the search method
        $this->engine->search($builder);
    }

    public function test_soft_deleted_objects_are_returned_with_only_trashed_method()
    {
        // Create a mock of SearchableModel
        $searchableModel = m::mock(SearchableModel::class)->makePartial();

        // Mock the search method to return a collection with a soft-deleted object
        $searchableModel->shouldReceive('search')->with('Soft Deleted Object')->andReturnSelf();
        $searchableModel->shouldReceive('onlyTrashed')->andReturnSelf();
        $searchableModel->shouldReceive('get')->andReturn(collect([
            new SearchableModel(['id' => 1, 'name' => 'Soft Deleted Object']),
        ]));

        // Perform the search with onlyTrashed() using the mocked model
        $results = $searchableModel::search('Soft Deleted Object')->onlyTrashed()->get();

        // Assert that the soft deleted object is returned
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results->first()->id);
    }

    public function test_soft_deleted_objects_are_returned_with_with_trashed_method()
    {
        // Create a mock of SearchableModel
        $searchableModel = m::mock(SearchableModel::class)->makePartial();

        // Mock the search method to return a collection with a soft-deleted object
        $searchableModel->shouldReceive('search')->with('Soft Deleted Object')->andReturnSelf();
        $searchableModel->shouldReceive('withTrashed')->andReturnSelf();
        $searchableModel->shouldReceive('get')->andReturn(collect([
            new SearchableModel(['id' => 1, 'name' => 'Soft Deleted Object']),
        ]));

        // Perform the search with withTrashed() using the mocked model
        $results = $searchableModel::search('Soft Deleted Object')->withTrashed()->get();

        // Assert that the soft deleted object is returned
        $this->assertCount(1, $results);
        $this->assertEquals(1, $results->first()->id);
    }

    /**
     * Create a partially mocked engine whose client performs multi-searches.
     */
    protected function multiSearchEngine(MultiSearch $multiSearch): TypesenseEngine
    {
        $client = $this->createMock(TypesenseClient::class);
        $client->method('getMultiSearch')->willReturn($multiSearch);

        $engine = $this->getMockBuilder(TypesenseEngine::class)
            ->setConstructorArgs([$client, 1000])
            ->onlyMethods(['getOrCreateCollectionFromModel', 'buildSearchParameters'])
            ->getMock();

        $collection = $this->createMock(TypesenseCollection::class);
        $documents = $this->createMock(Documents::class);
        $collection->method('getDocuments')->willReturn($documents);
        $documents->expects($this->never())->method('search');

        $engine->method('getOrCreateCollectionFromModel')->willReturn($collection);

        return $engine;
    }

    /**
     * Create an engine with embedding settings for the searchable model fixture.
     */
    protected function semanticEngine(array $embedding, string $queryBy = 'name'): TypesenseEngine
    {
        Container::getInstance()->instance('config', new Repository([
            'scout' => [
                'typesense' => [
                    'model-settings' => [
                        SearchableModel::class => [
                            'search-parameters' => [
                                'query_by' => $queryBy,
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        return new TypesenseEngine($this->createMock(TypesenseClient::class), 1000, [
            'model-settings' => [
                SearchableModel::class => [
                    'embedding' => $embedding,
                ],
            ],
        ]);
    }

    /**
     * Register fake Laravel AI embedding responses.
     */
    protected function fakeEmbeddings(array $responses): void
    {
        if (! class_exists('Laravel\\Ai\\Embeddings')) {
            class_alias(FakeEmbeddings::class, 'Laravel\\Ai\\Embeddings');
        }

        FakeEmbeddings::fake($responses);
    }
}
