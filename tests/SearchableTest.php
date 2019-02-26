<?php

namespace Laravel\Scout\Tests;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Laravel\Scout\Tests\Fixtures\SearchableTestModel;

class SearchableTest extends TestCase
{
    public function tearDown()
    {
        m::close();
    }

    public function test_searchable_make_searchable_dispatched_immediately_if_now_is_true()
    {
        $model = new SearchableTestModel;

        $collection = $model->newCollection([$model]);

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatchNow')->withArgs(function ($arg) use($collection){
            if ($arg->models === $collection && $arg instanceof MakeSearchable) {
                return true;
            }
            return false;
        });

        $now = true;

        $model->dispatchMakeSearchable($dispatcher, $collection, $now);
    }

    public function test_searchable_make_searchable_dispatched_in_queue_if_now_is_false()
    {
        $model = new SearchableTestModel;

        $collection = $model->newCollection([$model]);

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->withArgs(function ($arg) use($collection){
            if ($arg->models === $collection && $arg instanceof MakeSearchable) {
                return true;
            }
            return false;
        });

        $now = false;

        $model->dispatchMakeSearchable($dispatcher, $collection, $now);
    }

    public function test_searchable_remove_from_search_dispatched_immediately_if_now_is_true()
    {
        $model = new SearchableTestModel;

        $collection = $model->newCollection([$model]);

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatchNow')->withArgs(function ($arg) use($collection){
            if ($arg->models === $collection && $arg instanceof RemoveFromSearch) {
                return true;
            }
            return false;
        });

        $now = true;

        $model->dispatchRemoveFromSearch($dispatcher, $collection, $now);
    }

    public function test_searchable_remove_from_search_dispatched_in_queue_if_now_is_false()
    {
        $model = new SearchableTestModel;

        $collection = $model->newCollection([$model]);

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->withArgs(function ($arg) use($collection){
            if ($arg->models === $collection && $arg instanceof RemoveFromSearch) {
                return true;
            }
            return false;
        });

        $now = false;

        $model->dispatchRemoveFromSearch($dispatcher, $collection, $now);
    }

    public function test_make_all_searchable_uses_order_by()
    {
        ModelStubForMakeAllSearchable::makeAllSearchable();
    }
}

class ModelStubForMakeAllSearchable extends SearchableTestModel
{
    public function newQuery()
    {
        $mock = \Mockery::mock('Illuminate\Database\Eloquent\Builder');

        $mock->shouldReceive('orderBy')
            ->with('id')
            ->andReturnSelf()
            ->shouldReceive('searchable');

        $mock->shouldReceive('when')->andReturnSelf();

        return $mock;
    }
}

namespace Laravel\Scout;

function config($arg)
{
    return false;
}
