<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Support\Facades\Config;
use Laravel\Scout\ModelObserver;
use Laravel\Scout\Tests\Fixtures\SearchableModelWithSensitiveAttributes;
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
