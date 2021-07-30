<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Jobs\MakeSearchable;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class MakeSearchableTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_unique_id_returns_unique_string()
    {
        $model = m::mock();

        $key = random_int(1, 1000);

        $model
            ->shouldReceive('getScoutKeyName')
            ->andReturn("model.id")
            ->shouldReceive('getScoutKey')
            ->andReturn($key);

        $job = new MakeSearchable($collection = Collection::make([$model]));

        $model->shouldReceive('searchableUsing->update')->with($collection);

        $this->assertEquals(
            "model.id.$key",
            $job->uniqueId()
        );
    }

    public function test_handle_passes_the_collection_to_engine()
    {
        $job = new MakeSearchable($collection = Collection::make([
            $model = m::mock(),
        ]));

        $model->shouldReceive('searchableUsing->update')->with($collection);

        $job->handle();
    }
}
