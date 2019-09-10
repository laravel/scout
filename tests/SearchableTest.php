<?php

namespace Laravel\Scout\Tests;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class SearchableTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

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
