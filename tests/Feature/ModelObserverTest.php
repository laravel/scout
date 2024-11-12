<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Scout\ModelObserver;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
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
        $_ENV['search-index.user'] = false;

        $model = SearchableUserFactory::new()->createQuietly(['name' => 'Laravel']);

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldNotReceive('update');
        });

        $model->save();

        unset($_ENV['search-index.user']);
    }

    public function test_saved_handler_doesnt_make_model_searchable_when_disabled()
    {
        $model = SearchableUserFactory::new()->createQuietly(['name' => 'Laravel']);

        ModelObserver::disableSyncingFor($model::class);

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldNotReceive('update');
        });

        $model->save();

        ModelObserver::enableSyncingFor($model::class);
    }
}
