<?php

namespace Tests;

use Mockery;
use Laravel\Scout\Jobs\MakeSearchable;
use Illuminate\Database\Eloquent\Collection;

class MakeSearchableTest extends AbstractTestCase
{
    public function test_handle_passes_the_collection_to_engine()
    {
        $job = new MakeSearchable($collection = Collection::make([
            $model = Mockery::mock(),
        ]));

        $model->shouldReceive('searchableUsing->update')->with($collection);

        $job->handle();
    }
}
