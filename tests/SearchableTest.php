<?php

namespace Tests;

use Mockery;
use Tests\Fixtures\SearchableTestModel;

class SearchableText extends AbstractTestCase
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

}

namespace Laravel\Scout;

function config($arg)
{
    return false;
}
