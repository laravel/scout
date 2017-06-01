<?php

namespace Tests;

use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Stub;
use Mockery;
use Tests\Fixtures\SearchableTestModel;

class SearchableTest extends AbstractTestCase
{
    public function test_searchable_using_update_is_called_on_collection()
    {
        $collection = Mockery::mock();
        $collection->shouldReceive('isEmpty')->andReturn(false);
        $collection->shouldReceive('first->searchableUsing->update')->with($collection);

        $model = new SearchableTestModel();
        $model->queueMakeSearchable($collection);
    }

    public function test_searchable_using_update_is_not_called_on_empty_collection()
    {
        $collection = Mockery::mock();
        $collection->shouldReceive('isEmpty')->andReturn(true);
        $collection->shouldNotReceive('first->searchableUsing->update');

        $model = new SearchableTestModel();
        $model->queueMakeSearchable($collection);
    }

    public function test_searchable_using_delete_is_called_on_collection()
    {
        $collection = Mockery::mock();
        $collection->shouldReceive('isEmpty')->andReturn(false);
        $collection->shouldReceive('first->searchableUsing->delete')->with($collection);

        $model = new SearchableTestModel();
        $model->queueRemoveFromSearch($collection);
    }

    public function test_searchable_using_delete_is_not_called_on_empty_collection()
    {
        $collection = Mockery::mock();
        $collection->shouldReceive('isEmpty')->andReturn(true);
        $collection->shouldNotReceive('first->searchableUsing->delete');

        $model = new SearchableTestModel();
        $model->queueRemoveFromSearch($collection);
    }

    public function test_make_all_searchable_uses_order_by()
    {
        ModelStubForMakeAllSearchable::makeAllSearchable();
    }

    public function test_make_all_searchable_with_trashed()
    {
        ModelStubForMakeAllSearchableWithTrashed::makeAllSearchableWithTrashed();
    }

    public function test_make_all_searchable_with_trashed_fails_silently()
    {
        ModelStubForMakeAllSearchable::makeAllSearchableWithTrashed();
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

        return $mock;
    }
}

class ModelStubForMakeAllSearchableWithTrashed extends SearchableTestModel
{
    use SoftDeletes;

    public function newQuery()
    {
        $mock = \Mockery::mock('Illuminate\Database\Eloquent\Builder');

        $mock->shouldReceive('orderBy')
            ->with('id')
            ->andReturnSelf()
            ->shouldReceive('withTrashed')
            ->andReturnSelf()
            ->shouldReceive('searchable');

        return $mock;
    }
}

class ModelStubForRemoveAllFromSearch extends SearchableTestModel
{
    public function newQuery()
    {
        $mock = \Mockery::mock('Illuminate\Database\Eloquent\Builder');

        $mock->shouldReceive('orderBy')
            ->with('id')
            ->andReturnSelf()
            ->shouldReceive('unsearchable');

        return $mock;
    }
}

namespace Laravel\Scout;

use Tests\Fixtures\SearchableTestModel;

function config($arg)
{
    return false;
}
