<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
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
        $model->shouldReceive('shouldBeSearchable')->andReturn(true);
        $model->shouldReceive('searchable')->once();
        $observer->saved($model);
    }

    public function test_saved_handler_doesnt_make_model_searchable_when_disabled()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $observer->disableSyncingFor(get_class($model));
        $model->shouldReceive('searchable')->never();
        $observer->saved($model);
        $observer->enableSyncingFor(get_class($model));
    }

    public function test_saved_handler_makes_model_unsearchable_when_disabled_per_model_rule()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $model->shouldReceive('shouldBeSearchable')->andReturn(false);
        $model->shouldReceive('wasSearchableBeforeUpdate')->andReturn(true);
        $model->shouldReceive('searchable')->never();
        $model->shouldReceive('unsearchable')->once();
        $observer->saved($model);
    }

    public function test_saved_handler_doesnt_make_model_unsearchable_when_disabled_per_model_rule_and_already_unsearchable()
    {
        $observer = new ModelObserver;
        $model = m::mock(Model::class)->makePartial();
        $model->shouldReceive('shouldBeSearchable')->andReturn(false);
        $model->shouldReceive('wasSearchableBeforeUpdate')->andReturn(false);
        $model->shouldReceive('searchable')->never();
        $model->shouldReceive('unsearchable')->never();
        $observer->saved($model);
    }

    public function test_deleted_handler_doesnt_make_model_unsearchable_when_already_unsearchable()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $model->shouldReceive('wasSearchableBeforeDelete')->andReturn(false);
        $model->shouldReceive('unsearchable')->never();
        $observer->deleted($model);
    }

    public function test_deleted_handler_makes_model_unsearchable()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $model->shouldReceive('wasSearchableBeforeDelete')->andReturn(true);
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
