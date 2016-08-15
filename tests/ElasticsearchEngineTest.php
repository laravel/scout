<?php

use Laravel\Scout\Engines\ElasticsearchEngine;
use Illuminate\Database\Eloquent\Collection;

class ElasticsearchEngineTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        Mockery::close();
    }

    public function test_update_adds_objects_to_index()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $client->shouldReceive('bulk')->with([
            'body' => [
                [
                    'update' => [
                        '_id' => 1,
                        '_index' => 'scout',
                        '_type' => 'table',
                    ]
                ],
                [
                    'doc' => ['id' => 1 ],
                    'doc_as_upsert' => true
                ]
            ]
        ]);

        $engine = new ElasticsearchEngine($client, 'scout');
        $engine->update(Collection::make([new ElasticsearchEngineTestModel]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $client->shouldReceive('bulk')->with([
            'body' => [
                [
                    'delete' => [
                        '_id' => 1,
                        '_index' => 'scout',
                        '_type' => 'table',
                    ]
                ],
            ]
        ]);

        $engine = new ElasticsearchEngine($client, 'scout');
        $engine->delete(Collection::make([new ElasticsearchEngineTestModel]));
    }

    public function test_search_sends_correct_parameters_to_elasticsearch()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $client->shouldReceive('search')->with([
            'index' => 'scout',
            'type' => 'table',
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            ['query_string' => ['query' => '*zonda*']],
                            ['match_phrase' => ['foo' => 1]]
                        ]
                    ]
                ]
            ]
        ]);

        $engine = new ElasticsearchEngine($client, 'scout');
        $builder = new Laravel\Scout\Builder(new ElasticsearchEngineTestModel, 'zonda');
        $builder->where('foo', 1);
        $engine->search($builder);
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $client = Mockery::mock('Elasticsearch\Client');
        $engine = new ElasticsearchEngine($client, 'scout');

        $model = Mockery::mock('Illuminate\Database\Eloquent\Model');
        $model->shouldReceive('getKeyName')->andReturn('key');
        $model->shouldReceive('whereIn')->once()->with('key', ['1'])->andReturn($model);
        $model->shouldReceive('get')->once()->andReturn(Collection::make([new ElasticsearchEngineTestModel]));

        $results = $engine->map([
            'hits' => [
                'total' => '1',
                'hits' => [
                    [
                        '_id' => '1'
                    ]
                ]
            ]
        ], $model);

        $this->assertEquals(1, count($results));
    }
}

class ElasticsearchEngineTestModel
{
    public function searchableAs()
    {
        return 'table';
    }

    public function getKey()
    {
        return '1';
    }

    public function toSearchableArray()
    {
        return ['id' => 1];
    }
}
