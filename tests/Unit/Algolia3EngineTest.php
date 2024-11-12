<?php

namespace Laravel\Scout\Tests\Unit;

use Algolia\AlgoliaSearch\SearchClient;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Algolia3Engine;
use Laravel\Scout\Tests\Fixtures\EmptySearchableModel;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Laravel\Scout\Tests\Fixtures\SoftDeletedEmptySearchableModel;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use stdClass;

class Algolia3EngineTest extends TestCase
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

    public function test_map_method_respects_order()
    {
        $client = m::mock(SearchClient::class);
        $engine = new Algolia3Engine($client);

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

        $this->assertCount(4, $results);

        // It's important we assert with array keys to ensure
        // they have been reset after sorting.
        $this->assertEquals([
            0 => ['id' => 1],
            1 => ['id' => 2],
            2 => ['id' => 4],
            3 => ['id' => 3],
        ], $results->toArray());
    }

    public function test_lazy_map_correctly_maps_results_to_models()
    {
        $client = m::mock(SearchClient::class);
        $engine = new Algolia3Engine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive('queryScoutModelsByIds->cursor')->andReturn($models = LazyCollection::make([
            new SearchableModel(['id' => 1, 'name' => 'test']),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, ['nbHits' => 1, 'hits' => [
            ['objectID' => 1, 'id' => 1, '_rankingInfo' => ['nbTypos' => 0]],
        ]], $model);

        $this->assertCount(1, $results);
        $this->assertEquals(['id' => 1, 'name' => 'test'], $results->first()->toArray());
        $this->assertEquals(['_rankingInfo' => ['nbTypos' => 0]], $results->first()->scoutMetaData());
    }

    public function test_lazy_map_method_respects_order()
    {
        $client = m::mock(SearchClient::class);
        $engine = new Algolia3Engine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive('queryScoutModelsByIds->cursor')->andReturn($models = LazyCollection::make([
            new SearchableModel(['id' => 1]),
            new SearchableModel(['id' => 2]),
            new SearchableModel(['id' => 3]),
            new SearchableModel(['id' => 4]),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, ['nbHits' => 4, 'hits' => [
            ['objectID' => 1, 'id' => 1],
            ['objectID' => 2, 'id' => 2],
            ['objectID' => 4, 'id' => 4],
            ['objectID' => 3, 'id' => 3],
        ]], $model);

        $this->assertCount(4, $results);

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

        $engine = new Algolia3Engine($client);
        $engine->update(Collection::make([new Algolia3CustomKeySearchableModel]));
    }

    public function test_a_model_is_removed_with_a_custom_algolia_key()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('deleteObjects')->with(['my-algolia-key.1']);

        $engine = new Algolia3Engine($client);
        $engine->delete(Collection::make([new Algolia3CustomKeySearchableModel(['id' => 1])]));
    }

    public function test_flush_a_model_with_a_custom_algolia_key()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('clearObjects');

        $engine = new Algolia3Engine($client);
        $engine->flush(new Algolia3CustomKeySearchableModel);
    }

    public function test_update_empty_searchable_array_does_not_add_objects_to_index()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldNotReceive('saveObjects');

        $engine = new Algolia3Engine($client);
        $engine->update(Collection::make([new EmptySearchableModel]));
    }

    public function test_update_empty_searchable_array_from_soft_deleted_model_does_not_add_objects_to_index()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock('StdClass'));
        $index->shouldNotReceive('saveObjects');

        $engine = new Algolia3Engine($client, true);
        $engine->update(Collection::make([new SoftDeletedEmptySearchableModel]));
    }
}

class Algolia3CustomKeySearchableModel extends SearchableModel
{
    public function getScoutKey()
    {
        return 'my-algolia-key.'.$this->getKey();
    }
}
