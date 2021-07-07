<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Laravel\Scout\Tests\Fixtures\SearchableModelWithCustomKey;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class RemoveFromSearchTest extends TestCase
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

    public function test_handle_passes_the_collection_to_engine()
    {
        $job = new RemoveFromSearch(Collection::make([
            $model = m::mock(),
        ]));

        $model->shouldReceive('searchableUsing->delete')->with(
            m::on(function ($collection) use ($model) {
                return $collection instanceof RemoveableScoutCollection && $collection->first() === $model;
            })
        );

        $job->handle();
    }

    public function test_models_are_deserialized_without_the_database()
    {
        $job = new RemoveFromSearch(Collection::make([
            $model = new SearchableModel(['id' => 1234]),
        ]));

        $job = unserialize(serialize($job));

        $this->assertInstanceOf(Collection::class, $job->models);
        $this->assertCount(1, $job->models);
        $this->assertInstanceOf(SearchableModel::class, $job->models->first());
        $this->assertTrue($model->is($job->models->first()));
        $this->assertEquals(1234, $job->models->first()->getScoutKey());
    }

    public function test_models_are_deserialized_without_the_database_using_custom_scout_key()
    {
        $job = new RemoveFromSearch(Collection::make([
            $model = new SearchableModelWithCustomKey(['other_id' => 1234]),
        ]));

        $job = unserialize(serialize($job));

        $this->assertInstanceOf(Collection::class, $job->models);
        $this->assertCount(1, $job->models);
        $this->assertInstanceOf(SearchableModelWithCustomKey::class, $job->models->first());
        $this->assertTrue($model->is($job->models->first()));
        $this->assertEquals(1234, $job->models->first()->getScoutKey());
        $this->assertEquals('searchable_model_with_custom_keys.other_id', $job->models->first()->getScoutKeyName());
    }

    public function test_removeable_scout_collection_returns_scout_keys()
    {
        $collection = RemoveableScoutCollection::make([
            new SearchableModelWithCustomKey(['other_id' => 1234]),
            new SearchableModelWithCustomKey(['other_id' => 2345]),
            new SearchableModel(['id' => 3456]),
            new SearchableModel(['id' => 7891]),
        ]);

        $this->assertEquals([
            1234,
            2345,
            3456,
            7891,
        ], $collection->getQueueableIds());
    }
}
