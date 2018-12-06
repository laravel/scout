<?php

namespace Laravel\Scout\Tests;

use Mockery as m;
use Laravel\Scout\Builder;
use PHPUnit\Framework\TestCase;
use Laravel\Scout\Engines\AlgoliaEngine;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Tests\Fixtures\EmptyTestModel;
use Laravel\Scout\Tests\Fixtures\AlgoliaEngineTestModel;
use Laravel\Scout\Tests\Fixtures\AlgoliaEngineTestCustomKeyModel;

class AlgoliaEngineTest extends TestCase
{
    public function tearDown()
    {
        m::close();
    }

    public function test_update_adds_objects_to_index()
    {
        $client = m::mock('AlgoliaSearch\Client');
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock('StdClass'));
        $index->shouldReceive('addObjects')->with([[
            'id' => 1,
            'objectID' => 1,
        ]]);

        $engine = new AlgoliaEngine($client);
        $engine->update(Collection::make([new AlgoliaEngineTestModel]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $client = m::mock('AlgoliaSearch\Client');
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock('StdClass'));
        $index->shouldReceive('deleteObjects')->with([1]);

        $engine = new AlgoliaEngine($client);
        $engine->delete(Collection::make([new AlgoliaEngineTestModel]));
    }

    public function test_search_sends_correct_parameters_to_algolia()
    {
        $client = m::mock('AlgoliaSearch\Client');
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock('StdClass'));
        $index->shouldReceive('search')->with('zonda', [
            'numericFilters' => ['foo=1'],
        ]);

        $engine = new AlgoliaEngine($client);
        $builder = new Builder(new AlgoliaEngineTestModel, 'zonda');
        $builder->where('foo', 1);
        $engine->search($builder);
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $client = m::mock('AlgoliaSearch\Client');
        $engine = new AlgoliaEngine($client);

        $model = m::mock('StdClass');
        $model->shouldReceive('newQuery')->andReturn($model);
        $model->shouldReceive('getKeyName')->andReturn('id');
        $model->shouldReceive('getScoutModelsByIds')->andReturn(Collection::make([new AlgoliaEngineTestModel]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, ['nbHits' => 1, 'hits' => [
            ['objectID' => 1, 'id' => 1],
        ]], $model);

        $this->assertEquals(1, count($results));
    }

    public function test_a_model_is_indexed_with_a_custom_algolia_key()
    {
        $client = m::mock('AlgoliaSearch\Client');
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock('StdClass'));
        $index->shouldReceive('addObjects')->with([[
            'id' => 1,
            'objectID' => 'my-algolia-key.1',
        ]]);

        $engine = new AlgoliaEngine($client);
        $engine->update(Collection::make([new AlgoliaEngineTestCustomKeyModel]));
    }

    public function test_a_model_is_removed_with_a_custom_algolia_key()
    {
        $client = m::mock('AlgoliaSearch\Client');
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock('StdClass'));
        $index->shouldReceive('deleteObjects')->with(['my-algolia-key.1']);

        $engine = new AlgoliaEngine($client);
        $engine->delete(Collection::make([new AlgoliaEngineTestCustomKeyModel]));
    }

    public function test_flush_a_model()
    {
        $client = m::mock('AlgoliaSearch\Client');
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock('StdClass'));
        $index->shouldReceive('clearIndex');

        $engine = new AlgoliaEngine($client);
        $engine->flush(new AlgoliaEngineTestCustomKeyModel);
    }

    public function test_update_empty_searchable_array_does_not_add_objects_to_index()
    {
        $client = m::mock('AlgoliaSearch\Client');
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock('StdClass'));
        $index->shouldNotReceive('addObjects');

        $engine = new AlgoliaEngine($client);
        $engine->update(Collection::make([new EmptyTestModel]));
    }
}
