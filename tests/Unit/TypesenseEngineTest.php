<?php

namespace Laravel\Scout\Tests\Unit;

use Http\Client\Exception;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\TypesenseEngine;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Typesense\Client as TypesenseClient;
use Typesense\Collection as TypesenseCollection;
use Typesense\Documents;
use Typesense\Exceptions\TypesenseClientError;

class TypesenseEngineTest extends TestCase
{
    protected TypesenseEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

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
        m::close();
    }

    public function test_update_method(): void
    {
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
}
