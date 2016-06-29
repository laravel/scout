<?php

use Illuminate\Database\Eloquent\Collection;

class RemoveFromSearchTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        Mockery::close();
    }

    public function test_handle_passes_the_collection_to_engine()
    {
        $job = new Laravel\Scout\Jobs\RemoveFromSearch($collection = Collection::make([
            $model = Mockery::mock()
        ]));

        $model->shouldReceive('searchableUsing->delete')->with($collection);

        $job->handle();
    }
}
