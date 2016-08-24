<?php

namespace Tests;

use Mockery;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\AlgoliaEngine;
use Tests\Fixtures\AlgoliaEngineTestModel;
use Illuminate\Database\Eloquent\Collection;

class AlgoliaEngineTest extends AbstractTestCase
{
    public function test_update_adds_objects_to_index()
    {
        $client = Mockery::mock('AlgoliaSearch\Client');
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = Mockery::mock('StdClass'));
        $index->shouldReceive('addObjects')->with([[
            'id' => 1,
            'objectID' => 1,
        ]]);

        $engine = new AlgoliaEngine($client);
        $engine->update(Collection::make([new AlgoliaEngineTestModel]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $client = Mockery::mock('AlgoliaSearch\Client');
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = Mockery::mock('StdClass'));
        $index->shouldReceive('deleteObjects')->with([1]);

        $engine = new AlgoliaEngine($client);
        $engine->delete(Collection::make([new AlgoliaEngineTestModel]));
    }

    public function test_search_sends_correct_parameters_to_algolia()
    {
        $client = Mockery::mock('AlgoliaSearch\Client');
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = Mockery::mock('StdClass'));
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
        $client = Mockery::mock('AlgoliaSearch\Client');
        $engine = new AlgoliaEngine($client);

        $model = Mockery::mock('StdClass');
        $model->shouldReceive('getKeyName')->andReturn('id');
        $model->shouldReceive('whereIn')->once()->with('id', [1])->andReturn($model);
        $model->shouldReceive('get')->once()->andReturn(Collection::make([new AlgoliaEngineTestModel]));

        $results = $engine->map(['nbHits' => 1, 'hits' => [
            ['objectID' => 1, 'id' => 1],
        ]], $model);

        $this->assertEquals(1, count($results));
    }
}
