<?php

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\AlgoliaEngine;
use Laravel\Scout\Engines\CollectionEngine;
use Laravel\Scout\Tests\Fixtures\EmptySearchableModel;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Laravel\Scout\Tests\Fixtures\SearchableModelWithCustomKeyForCollectionEngineTest;
use Laravel\Scout\Tests\Fixtures\SoftDeletedEmptySearchableModel;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class CollectionEngineTest extends TestCase
{
    protected function setUp(): void
    {
        Config::shouldReceive('get')->with('scout.after_commit', m::any())->andReturn(false);
        Config::shouldReceive('get')->with('scout.soft_delete', m::any())->andReturn(false);
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $builder = m::mock(Builder::class);
        $engine = new CollectionEngine();
        $model = new SearchableModelWithCustomKeyForCollectionEngineTest([
            'id' => 1,
            'other_id' => 1234
        ]);
        $modelMock = m::mock(SearchableModelWithCustomKeyForCollectionEngineTest::class);
        $modelMock->shouldReceive('getScoutModelsByIds')->withArgs([$builder, [1234]])
            ->andReturn(Collection::make([
                $model,
            ]));
        $modelMock->shouldReceive('getScoutKeyName')->andReturn('other_id');

        $results = $engine->map($builder, [
            'results' => [$model]
        ], $modelMock);

        $this->assertCount(1, $results);
    }

    public function test_lazy_map_correctly_maps_results_to_models()
    {

        $engine = new CollectionEngine();
        $model = new SearchableModelWithCustomKeyForCollectionEngineTest([
            'id' => 1,
            'other_id' => 1234
        ]);
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('cursor')->andReturn(LazyCollection::make([$model]));
        $modelMock = m::mock(SearchableModelWithCustomKeyForCollectionEngineTest::class);
        $modelMock->shouldReceive('queryScoutModelsByIds')->withArgs([$builder, [1234]])
            ->andReturn($builder);
        $modelMock->shouldReceive('getScoutKeyName')->andReturn('other_id');

        $results = $engine->lazyMap($builder, [
            'results' => [$model]
        ], $modelMock);

        $this->assertCount(1, $results);
    }
}
