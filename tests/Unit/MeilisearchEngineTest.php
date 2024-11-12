<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\MeilisearchEngine;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Meilisearch\Client;
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
        $engine = m::spy(MeilisearchEngine::class);
        $builder = m::mock(Builder::class);

        $model = m::mock(stdClass::class);
        $model->shouldReceive(['getScoutKeyName' => 'custom_key'])->once();
        $builder->model = $model;

        $engine->shouldReceive('keys')->once()->passthru();

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

    public function test_engine_forwards_calls_to_meilisearch_client()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('testMethodOnClient')->once()->andReturn('meilisearch');

        $engine = new MeilisearchEngine($client);
        $this->assertSame('meilisearch', $engine->testMethodOnClient());
    }

    public function test_updating_empty_eloquent_collection_does_nothing()
    {
        $client = m::mock(Client::class);
        $engine = new MeilisearchEngine($client);
        $engine->update(new Collection);
        $this->assertTrue(true);
    }

    public function test_engine_returns_hits_entry_from_search_response()
    {
        $this->assertTrue((new MeilisearchEngine(m::mock(Client::class)))->getTotalCount([
            'totalHits' => 3,
        ]) === 3);
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
