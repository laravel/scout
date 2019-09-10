<?php

namespace Laravel\Scout\Tests;

use Algolia\AlgoliaSearch\SearchClient;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\AlgoliaEngine;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use stdClass;

class AlgoliaEngineTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_update_adds_objects_to_index()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('saveObjects')->with([[
            'id' => 1,
            'objectID' => 1,
        ]]);

        $engine = new AlgoliaEngine($client);
        $engine->update(Collection::make([new SearchableModel]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('deleteObjects')->with([1]);

        $engine = new AlgoliaEngine($client);
        $engine->delete(Collection::make([new SearchableModel(['id' => 1])]));
    }

    public function test_search_sends_correct_parameters_to_algolia()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->with('zonda', [
            'numericFilters' => ['foo=1'],
        ]);

        $engine = new AlgoliaEngine($client);
        $builder = new Builder(new SearchableModel, 'zonda');
        $builder->where('foo', 1);
        $engine->search($builder);
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $client = m::mock(SearchClient::class);
        $engine = new AlgoliaEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive('getScoutModelsByIds')->andReturn($models = Collection::make([
            new SearchableModel(['id' => 1]),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, ['nbHits' => 1, 'hits' => [
            ['objectID' => 1, 'id' => 1],
        ]], $model);

        $this->assertEquals(1, count($results));
    }

    public function test_map_method_respects_order()
    {
        $client = m::mock(SearchClient::class);
        $engine = new AlgoliaEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive('getScoutModelsByIds')->andReturn($models = Collection::make([
            new SearchableModel(['id' => 1]),
            new SearchableModel(['id' => 2]),
            new SearchableModel(['id' => 3]),
            new SearchableModel(['id' => 4]),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, ['nbHits' => 4, 'hits' => [
            ['objectID' => 1, 'id' => 1],
            ['objectID' => 2, 'id' => 2],
            ['objectID' => 4, 'id' => 4],
            ['objectID' => 3, 'id' => 3],
        ]], $model);

        $this->assertEquals(4, count($results));

        // It's important we assert with array keys to ensure
        // they have been reset after sorting.
        $this->assertEquals([
            0 => ['id' => 1],
            1 => ['id' => 2],
            2 => ['id' => 4],
            3 => ['id' => 3],
        ], $results->toArray());
    }

    public function test_a_model_is_indexed_with_a_custom_algolia_key()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('saveObjects')->with([[
            'id' => 1,
            'objectID' => 'my-algolia-key.1',
        ]]);

        $engine = new AlgoliaEngine($client);
        $engine->update(Collection::make([new CustomKeySearchableModel]));
    }

    public function test_a_model_is_removed_with_a_custom_algolia_key()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('deleteObjects')->with(['my-algolia-key.1']);

        $engine = new AlgoliaEngine($client);
        $engine->delete(Collection::make([new CustomKeySearchableModel(['id' => 1])]));
    }

    public function test_flush_a_model_with_a_custom_algolia_key()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('clearObjects');

        $engine = new AlgoliaEngine($client);
        $engine->flush(new CustomKeySearchableModel);
    }

    public function test_update_empty_searchable_array_does_not_add_objects_to_index()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldNotReceive('saveObjects');

        $engine = new AlgoliaEngine($client);
        $engine->update(Collection::make([new EmptySearchableModel]));
    }
}

class CustomKeySearchableModel extends SearchableModel
{
    public function getScoutKey()
    {
        return 'my-algolia-key.'.$this->getKey();
    }
}

class EmptySearchableModel extends SearchableModel
{
    public function toSearchableArray()
    {
        return [];
    }

    public function test_update_empty_searchable_array_from_soft_deleted_model_does_not_add_objects_to_index()
    {
        $client = m::mock('Algolia\AlgoliaSearch\SearchClient');
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock('StdClass'));
        $index->shouldNotReceive('saveObjects');

        $engine = new AlgoliaEngine($client, true);
        $engine->update(Collection::make([new SoftDeletedEmptySearchableModel]));
    }
}

class SoftDeletedEmptySearchableModel extends SearchableModel
{
    public function toSearchableArray()
    {
        return [];
    }

    public function pushSoftDeleteMetadata()
    {
        //
    }

    public function scoutMetadata()
    {
        return ['__soft_deleted' => 1];
    }
}
