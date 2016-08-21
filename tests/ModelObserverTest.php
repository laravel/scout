<?php

namespace Tests;

use Mockery;
use Laravel\Scout\ModelObserver;

class ModelObserverTest extends AbstractTestCase
{
    public function test_created_handler_makes_model_searchable()
    {
        $observer = new ModelObserver;
        $model = Mockery::mock();
        $model->shouldReceive('searchable');
        $observer->created($model);
    }

    public function test_created_handler_doesnt_make_model_searchable_when_disabled()
    {
        $observer = new ModelObserver;
        $model = Mockery::mock();
        $observer->disableSyncingFor(get_class($model));
        $model->shouldReceive('searchable')->never();
        $observer->created($model);
    }

    public function test_updated_handler_makes_model_searchable()
    {
        $observer = new ModelObserver;
        $model = Mockery::mock();
        $model->shouldReceive('searchable');
        $observer->updated($model);
    }

    public function test_deleted_handler_makes_model_unsearchable()
    {
        $observer = new ModelObserver;
        $model = Mockery::mock();
        $model->shouldReceive('unsearchable');
        $observer->deleted($model);
    }

    public function test_restored_handler_makes_model_searchable()
    {
        $observer = new ModelObserver;
        $model = Mockery::mock();
        $model->shouldReceive('searchable');
        $observer->restored($model);
    }
}
