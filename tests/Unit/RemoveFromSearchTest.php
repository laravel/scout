<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Laravel\Scout\Tests\Fixtures\OverriddenRemoveFromSearch;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class RemoveFromSearchTest extends TestCase
{
    protected function setUp(): void
    {
        Config::shouldReceive('get')->with('scout.after_commit', m::any())->andReturn(false);
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function test_handle_passes_the_collection_to_engine()
    {
        $job = new RemoveFromSearch($collection = Collection::make([
            $model = m::mock(),
        ]));

        $model->shouldReceive('searchableUsing->delete')->with($collection);

        $job->handle();
    }

    public function test_models_are_deserialized_without_the_database()
    {
        $job = new RemoveFromSearch($collection = Collection::make([
            $model = new SearchableModel(['id' => 1234]),
        ]));

        $job = unserialize(serialize($job));

        $this->assertInstanceOf(Collection::class, $job->models);
        $this->assertCount(1, $job->models);
        $this->assertInstanceOf(SearchableModel::class, $job->models->first());
        $this->assertTrue($model->is($job->models->first()));
        $this->assertEquals(1234, $job->models->first()->getScoutKey());
    }
}
