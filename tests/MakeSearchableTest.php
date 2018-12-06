<?php

namespace Laravel\Scout\Tests;

use Mockery as m;
use PHPUnit\Framework\TestCase;
use Laravel\Scout\Jobs\MakeSearchable;
use Illuminate\Database\Eloquent\Collection;

class MakeSearchableTest extends TestCase
{
    public function tearDown()
    {
        m::close();
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
