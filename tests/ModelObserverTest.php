<?php

namespace Tests;

use Mockery;
use Laravel\Scout\ModelObserver;

class ModelObserverTest extends AbstractTestCase
{
    public function test_saved_handler_makes_model_searchable()
    {
        $observer = new ModelObserver;
        $model = Mockery::mock();
        $model->shouldReceive('shouldBeSearchable')->andReturn(true);
        $model->shouldReceive('searchable');
        $observer->saved($model);
    }

    public function test_saved_handler_doesnt_make_model_searchable_when_disabled()
    {
        $observer = new ModelObserver;
        $model = Mockery::mock();
        $observer->disableSyncingFor(get_class($model));
        $model->shouldReceive('searchable')->never();
        $observer->saved($model);
    }

    public function test_saved_handler_doesnt_make_model_searchable_when_disabled_per_model_rule()
    {
        $observer = new ModelObserver;
        $model = Mockery::mock();
        $model->shouldReceive('shouldBeSearchable')->andReturn(false);
        $model->shouldReceive('searchable')->never();
        $observer->saved($model);
    }

    public function test_saved_handler_makes_model_unsearchable_when_disabled_per_model_rule_and_configured_to_sync()
    {
        $observer = new ModelObserver;
        $model = Mockery::mock();
        $model->shouldReceive('shouldBeSearchable')->andReturn(false);
        $model->shouldReceive('searchable')->never();
        $model->shouldReceive('unsearchable');
        $observer->saved($model);
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
        $model->shouldReceive('shouldBeSearchable')->andReturn(true);
        $model->shouldReceive('searchable');
        $observer->restored($model);
    }
}
