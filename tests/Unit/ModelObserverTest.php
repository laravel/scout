<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Laravel\Scout\ModelObserver;
use Laravel\Scout\Tests\Fixtures\SearchableModelWithSensitiveAttributes;
use Laravel\Scout\Tests\Fixtures\SearchableModelWithSoftDeletes;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class ModelObserverTest extends TestCase
{
    protected function setUp(): void
    {
        Config::clearResolvedInstances();
        Config::shouldReceive('get')->with('scout.after_commit', m::any())->andReturn(false);
        Config::shouldReceive('get')->with('scout.soft_delete', m::any())->andReturn(false);
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function test_saved_handler_makes_model_searchable()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $model->shouldReceive('searchIndexShouldBeUpdated')->andReturn(true);
        $model->shouldReceive('shouldBeSearchable')->andReturn(true);
        $model->shouldReceive('searchable')->once();
        $observer->saved($model);
    }

    public function test_saved_handler_doesnt_make_model_searchable_when_search_shouldnt_update()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $model->shouldReceive('searchIndexShouldBeUpdated')->andReturn(false);
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
        $observer->enableSyncingFor(get_class($model));
    }

    public function test_saved_handler_makes_model_unsearchable_when_disabled_per_model_rule()
    {
        $observer = new ModelObserver;
        $model = m::mock();
        $model->shouldReceive('searchIndexShouldBeUpdated')->andReturn(true);
        $model->shouldReceive('shouldBeSearchable')->andReturn(false);
        $model->shouldReceive('wasSearchableBeforeUpdate')->andReturn(true);
        $model->shouldReceive('searchable')->never();
        $model->shouldReceive('unsearchable')->once();
        $observer->saved($model);
    }

    public function test_saved_handler_doesnt_make_model_unsearchable_when_disabled_per_model_rule_and_already_unsearchable()
    {
        $observer = new ModelObserver;
        $model = m::mock(Model::class);
        $model->shouldReceive('searchIndexShouldBeUpdated')->andReturn(true);
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
        $model->shouldReceive('unsearchable')->once();
        $observer->deleted($model);
    }

    public function test_deleted_handler_on_soft_delete_model_makes_model_unsearchable()
    {
        $observer = new ModelObserver;
        $model = m::mock(SearchableModelWithSoftDeletes::class);
        $model->shouldReceive('wasSearchableBeforeDelete')->andReturn(true);
        $model->shouldReceive('searchable')->never();
        $model->shouldReceive('unsearchable')->once();
        $observer->deleted($model);
    }

    public function test_update_on_sensitive_attributes_triggers_search()
    {
        $model = m::mock(
            new SearchableModelWithSensitiveAttributes([
                'first_name' => 'taylor',
                'last_name' => 'Otwell',
                'remember_token' => 123,
                'password' => 'secret',
            ])
        )->makePartial();

        // Let's pretend it's in sync with the database.
        $model->syncOriginal();

        // Update
        $model->password = 'extremelySecurePassword';
        $model->first_name = 'Taylor';

        // Assertions
        $model->shouldReceive('searchable')->once();
        $model->shouldReceive('unsearchable')->never();

        $observer = new ModelObserver;
        $observer->saved($model);
    }

    public function test_update_on_non_sensitive_attributes_doesnt_trigger_search()
    {
        $model = m::mock(
            new SearchableModelWithSensitiveAttributes([
                'first_name' => 'taylor',
                'last_name' => 'Otwell',
                'remember_token' => 123,
                'password' => 'secret',
            ])
        )->makePartial();

        // Let's pretend it's in sync with the database.
        $model->syncOriginal();

        // Update
        $model->password = 'extremelySecurePassword';
        $model->remember_token = 456;

        // Assertions
        $model->shouldReceive('searchable')->never();
        $model->shouldReceive('unsearchable')->never();

        $observer = new ModelObserver;
        $observer->saved($model);
    }

    public function test_unsearchable_should_be_called_when_deleting()
    {
        $model = m::mock(
            new SearchableModelWithSensitiveAttributes([
                'first_name' => 'taylor',
                'last_name' => 'Otwell',
                'remember_token' => 123,
                'password' => 'secret',
            ])
        )->makePartial();

        // Let's pretend it's in sync with the database.
        $model->syncOriginal();

        // Assertions
        $model->shouldReceive('searchable')->never();
        $model->shouldReceive('unsearchable')->once();

        $observer = new ModelObserver;
        $observer->deleted($model);
    }
}
