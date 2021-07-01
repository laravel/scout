<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Laravel\Scout\ModelObserver;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class ModelObserverTest extends TestCase
{
    protected function setUp(): void
    {
        Config::shouldReceive('get')->with('scout.after_commit', m::any())->andReturn(false);
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function test_saved_handler_makes_model_searchable()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $model->shouldReceive('searchShouldUpdate')->andReturn(true);
        $model->shouldReceive('shouldBeSearchable')->andReturn(true);
        $model->shouldReceive('searchable');
        $observer->saved($model);
    }

    public function test_saved_handler_doesnt_make_model_searchable_when_search_shouldnt_update()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $model->shouldReceive('searchShouldUpdate')->andReturn(false);
        $model->shouldReceive('shouldBeSearchable')->andReturn(true);
        $model->shouldReceive('searchable')->never();
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
