<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class MakeSearchableTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_handle_passes_the_collection_to_engine()
    {
        $job = new MakeSearchable($collection = Collection::make([
            $model = m::mock(new SearchableModel)->makePartial(),
        ]));

        $model->shouldReceive('searchableUsing->update')->with($collection);

        $job->handle();
    }
}
