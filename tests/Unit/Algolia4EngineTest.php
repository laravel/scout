<?php

namespace Laravel\Scout\Tests\Unit;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Algolia4Engine;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Mockery as m;
use Orchestra\Testbench\TestCase;
use stdClass;

class Algolia4EngineTest extends TestCase
{
    public function test_map_method_respects_order()
    {
        $client = m::mock(SearchClient::class);
        $engine = new Algolia4Engine($client);

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
}
