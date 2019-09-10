<?php

namespace Laravel\Scout\Tests;

use Laravel\Scout\ModelObserver;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class ModelObserverTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_saved_handler_makes_model_searchable()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $model->shouldReceive('shouldBeSearchable')->andReturn(true);
        $model->shouldReceive('searchable');
        $observer->saved($model);
    }

    public function test_saved_handler_doesnt_make_model_searchable_when_disabled()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $observer->disableSyncingFor(get_class($model));
        $model->shouldReceive('searchable')->never();
        $observer->saved($model);
    }

    public function test_saved_handler_makes_model_unsearchable_when_disabled_per_model_rule()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $model->shouldReceive('shouldBeSearchable')->andReturn(false);
        $model->shouldReceive('searchable')->never();
        $model->shouldReceive('unsearchable');
        $observer->saved($model);
    }

    public function test_deleted_handler_makes_model_unsearchable()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $model->shouldReceive('unsearchable');
        $observer->deleted($model);
    }

    public function test_restored_handler_makes_model_searchable()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $model->shouldReceive('shouldBeSearchable')->andReturn(true);
        $model->shouldReceive('searchable');
        $observer->restored($model);
    }
}
