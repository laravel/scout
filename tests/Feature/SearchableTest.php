<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Tests\Fixtures\OverriddenMakeSearchable;
use Laravel\Scout\Tests\Fixtures\OverriddenRemoveFromSearch;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Mockery as m;
use Orchestra\Testbench\TestCase;

class SearchableTest extends TestCase
{
    public function test_searchable_using_update_is_called_on_collection()
    {
        $collection = m::mock();
        $collection->shouldReceive('isEmpty')->andReturn(false);
        $collection->shouldReceive('first->searchableUsing->update')->with($collection);

        $model = new SearchableModel();
        $model->queueMakeSearchable($collection);
    }

    public function test_searchable_using_update_is_not_called_on_empty_collection()
    {
        $collection = m::mock();
        $collection->shouldReceive('isEmpty')->andReturn(true);
        $collection->shouldNotReceive('first->searchableUsing->update');

        $model = new SearchableModel;
        $model->queueMakeSearchable($collection);
    }

    public function test_overridden_make_searchable_is_dispatched()
    {
        Queue::fake();

        $collection = m::mock();
        $collection->shouldReceive('isEmpty')->andReturn(false);
        $collection->shouldReceive('first->syncWithSearchUsingQueue');
        $collection->shouldReceive('first->syncWithSearchUsing');
        $collection->shouldReceive('first->searchableUsing->update')->with($collection);

        config()->set('scout.queue', true);
        config()->set('scout.jobs.make_searchable', OverriddenMakeSearchable::class);

        $model = new SearchableModel;
        $model->queueMakeSearchable($collection);
    }

    public function test_searchable_using_delete_is_called_on_collection()
    {
        $collection = m::mock();
        $collection->shouldReceive('isEmpty')->andReturn(false);
        $collection->shouldReceive('first->searchableUsing->delete')->with($collection);

        $model = new SearchableModel;
        $model->queueRemoveFromSearch($collection);
    }

    public function test_searchable_using_delete_is_not_called_on_empty_collection()
    {
        $collection = m::mock();
        $collection->shouldReceive('isEmpty')->andReturn(true);
        $collection->shouldNotReceive('first->searchableUsing->delete');

        $model = new SearchableModel;
        $model->queueRemoveFromSearch($collection);
    }

    public function test_overridden_remove_from_search_is_dispatched()
    {
        Queue::fake();

        $collection = m::mock();
        $collection->shouldReceive('isEmpty')->andReturn(false);
        $collection->shouldReceive('first->syncWithSearchUsingQueue');
        $collection->shouldReceive('first->syncWithSearchUsing');
        $collection->shouldReceive('first->searchableUsing->update')->with($collection);

        config()->set('scout.queue', true);
        config()->set('scout.jobs.remove_from_search', OverriddenRemoveFromSearch::class);

        $model = new SearchableModel;
        $model->queueRemoveFromSearch($collection);
    }

    public function test_make_all_searchable_uses_order_by()
    {
        ModelStubForMakeAllSearchable::makeAllSearchable();
    }
}

class ModelStubForMakeAllSearchable extends SearchableModel
{
    public function newQuery()
    {
        $mock = m::mock(Builder::class);

        $mock->shouldReceive('when')
                ->with(true, m::type('Closure'))
                ->andReturnUsing(function ($condition, $callback) use ($mock) {
                    $callback($mock);

                    return $mock;
                });

        $mock->shouldReceive('orderBy')
            ->with('id')
            ->andReturnSelf()
            ->shouldReceive('searchable');

        $mock->shouldReceive('when')->andReturnSelf();

        return $mock;
    }
}
