<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Scout\ModelObserver;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\SearchableUser;
use Workbench\Database\Factories\ChirpFactory;
use Workbench\Database\Factories\SearchableUserFactory;

#[WithConfig('scout.driver', 'testing')]
#[WithConfig('scout.after_commit', false)]
#[WithConfig('scout.soft_delete', false)]
#[WithMigration]
class ModelObserverTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithWorkbench;

    public function test_saved_handler_makes_model_searchable()
    {
        $model = SearchableUserFactory::new()->createQuietly(['name' => 'Laravel']);

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldReceive('update')->once();
        });

        $model->name = 'Laravel Scout';
        $model->save();
    }

    public function test_saved_handler_doesnt_make_model_searchable_when_search_shouldnt_update()
    {
        $_ENV['user.searchIndexShouldBeUpdated'] = false;

        $model = SearchableUserFactory::new()->createQuietly(['name' => 'Laravel']);

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldNotReceive('update');
        });

        $model->name = 'Laravel Scout';
        $model->save();

        unset($_ENV['user.searchIndexShouldBeUpdated']);
    }

    public function test_saved_handler_doesnt_make_model_searchable_when_disabled()
    {
        $model = SearchableUserFactory::new()->createQuietly(['name' => 'Laravel']);

        ModelObserver::disableSyncingFor(SearchableUser::class);

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldNotReceive('update');
        });

        $model->name = 'Laravel Scout';
        $model->save();

        ModelObserver::enableSyncingFor(SearchableUser::class);
    }

    public function test_saved_handler_makes_model_unsearchable_when_disabled_per_model_rule()
    {
        $_ENV['user.shouldBeSearchable'] = false;

        $model = SearchableUserFactory::new()->createQuietly(['name' => 'Laravel']);

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldNotReceive('update');
        });

        $model->name = 'Laravel Scout';
        $model->save();

        unset($_ENV['user.shouldBeSearchable']);
    }

    public function saved_handler_doesnt_make_model_unsearchable_when_disabled_per_model_rule_and_already_unsearchable()
    {
        $_ENV['user.wasSearchableBeforeUpdate'] = false;
        $_ENV['user.shouldBeSearchable'] = false;

        $model = SearchableUserFactory::new()->createQuietly(['name' => 'Laravel']);

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldNotReceive('update');
        });

        $model->name = 'Laravel Scout';
        $model->save();

        unset($_ENV['user.shouldBeSearchable'], $_ENV['user.wasSearchableBeforeUpdate']);
    }

    public function test_deleted_handler_doesnt_make_model_unsearchable_when_already_unsearchable()
    {
        $_ENV['user.wasSearchableBeforeDelete'] = false;

        $model = SearchableUserFactory::new()->createQuietly();

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldNotReceive('delete');
        });

        $model->delete();

        unset($_ENV['user.wasSearchableBeforeDelete']);
    }

    public function test_deleted_handler_makes_model_unsearchable()
    {
        $_ENV['user.wasSearchableBeforeDelete'] = true;

        $model = SearchableUserFactory::new()->createQuietly();

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldReceive('delete')->once();
        });

        $model->forceDelete();

        unset($_ENV['user.wasSearchableBeforeDelete']);
    }

    public function test_deleted_handler_on_soft_delete_model_makes_model_unsearchable()
    {
        $model = ChirpFactory::new()->createQuietly();

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldReceive('delete')->once();
        });

        $model->delete();
    }

    public function test_update_on_sensitive_attributes_triggers_search()
    {
        $_ENV['user.searchIndexShouldBeUpdated'] = function ($model) {
            $sensitiveAttributeKeys = ['name', 'email'];

            return collect($model->getDirty())->keys()
                ->intersect($sensitiveAttributeKeys)
                ->isNotEmpty();
        };

        $model = SearchableUserFactory::new()->createQuietly([
            'name' => 'taylor Otwell',
            'remember_token' => 123,
            'password' => 'secret',
        ]);

        $model->password = 'extremelySecurePassword';
        $model->name = 'Taylor';

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldReceive('update')->once();
        });

        $model->save();

        unset($_ENV['user.searchIndexShouldBeUpdated']);
    }

    public function test_update_on_non_sensitive_attributes_doesnt_trigger_search()
    {
        $_ENV['user.searchIndexShouldBeUpdated'] = function ($model) {
            $sensitiveAttributeKeys = ['name', 'email'];

            return collect($model->getDirty())->keys()
                ->intersect($sensitiveAttributeKeys)
                ->isNotEmpty();
        };

        $model = SearchableUserFactory::new()->createQuietly([
            'name' => 'taylor Otwell',
            'remember_token' => 123,
            'password' => 'secret',
        ]);

        $model->password = 'extremelySecurePassword';
        $model->remember_token = 456;

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldNotReceive('update');
            $scout->shouldNotReceive('delete');
        });

        $model->save();

        unset($_ENV['user.searchIndexShouldBeUpdated']);
    }

    public function test_unsearchable_should_be_called_when_deleting()
    {
        $_ENV['user.searchIndexShouldBeUpdated'] = function ($model) {
            $sensitiveAttributeKeys = ['name', 'email'];

            return collect($model->getDirty())->keys()
                ->intersect($sensitiveAttributeKeys)
                ->isNotEmpty();
        };

        $model = SearchableUserFactory::new()->createQuietly([
            'name' => 'taylor Otwell',
            'remember_token' => 123,
            'password' => 'secret',
        ]);

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldNotReceive('update');
            $scout->shouldReceive('delete')->once();
        });

        $model->delete();

        unset($_ENV['user.searchIndexShouldBeUpdated']);
    }
}
