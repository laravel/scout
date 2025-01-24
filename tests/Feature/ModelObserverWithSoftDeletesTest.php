<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Workbench\Database\Factories\ChirpFactory;

#[WithConfig('scout.driver', 'testing')]
#[WithConfig('scout.after_commit', false)]
#[WithConfig('scout.soft_delete', true)]
#[WithMigration]
class ModelObserverWithSoftDeletesTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithWorkbench;

    public function test_deleted_handler_makes_model_unsearchable_when_it_should_not_be_searchable()
    {
        $model = ChirpFactory::new()->createQuietly();

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldReceive('delete')->once();
        });

        $model->forceDelete();
    }

    public function test_deleted_handler_makes_model_searchable_when_it_should_be_searchable()
    {
        $model = ChirpFactory::new()->createQuietly();

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldReceive('update')->once();
        });

        $model->delete();
    }

    public function test_restored_handler_makes_model_searchable()
    {
        $model = ChirpFactory::new()->createQuietly([
            'deleted_at' => now(),
        ]);

        tap($this->app->make('scout.spied'), function ($scout) {
            $scout->shouldReceive('update')->twice();
        });

        $model->restore();
    }
}
