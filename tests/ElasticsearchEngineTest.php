<?php

namespace Tests;

use Mockery;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\ElasticsearchEngine;
use Tests\Fixtures\ElasticsearchEngineTestModel;
use Illuminate\Database\Eloquent\Collection;

class ElasticsearchEngineTest extends AbstractTestCase
{
    public function test_update_adds_objects_to_index()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $client->shouldReceive('index')->with([
            'index' => 'index_name',
            'type' => 'table',
            'id' => 1,
            'body' => ['id' => 1],
        ]);

        $engine = new ElasticsearchEngine($client, 'index_name');
        $engine->update(Collection::make([new ElasticsearchEngineTestModel]));
    }


    public function test_delete_removes_objects_to_index()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $client->shouldReceive('delete')->with([
            'index' => 'index_name',
            'type' => 'table',
            'id' => 1,
        ]);


        $engine = new ElasticsearchEngine($client, 'index_name');
        $engine->delete(Collection::make([new ElasticsearchEngineTestModel]));
    }

    public function test_search_sends_correct_parameters_to_algolia()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $client->shouldReceive('search')
            ->with([
                'index' =>  'index_name',
                'type'  =>  'table',
                'body' => [
                    'query' => [
                        "bool" => [
                            "filter" => [
                                "query_string" => [
                                    "query" => "*zonda*",
                                ],
                            ],
                            "must" => [
                                "match" => [
                                    'foo' => 1,
                                ],
                            ],
                        ],
                    ],
                ],
                'size' => 10000,
            ]);

        $engine = new ElasticsearchEngine($client, 'index_name');
        $builder = new Builder(new ElasticsearchEngineTestModel, 'zonda');
        $builder->where('foo', 1);
        $engine->search($builder);
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $engine = new ElasticsearchEngine($client, 'index_name');

        $model = Mockery::mock('StdClass');
        $model->shouldReceive('getKeyName')->andReturn('id');
        $model->shouldReceive('whereIn')->once()->with('id', [1])->andReturn($model);
        $model->shouldReceive('get')->once()->andReturn(Collection::make([new ElasticsearchEngineTestModel]));

        $results = $engine->map([
            'hits' => [
                'hits' => [
                    [
                        '_id' => 1,
                        '_source' => ['id' => 1]
                    ],
                ]
            ]
        ], $model);

        $this->assertEquals(1, count($results));
    }
}
