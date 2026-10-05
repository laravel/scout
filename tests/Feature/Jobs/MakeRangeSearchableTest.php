<?php

namespace Laravel\Scout\Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeRangeSearchable;
use Laravel\Scout\Jobs\MakeSearchable;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\SearchableUser;
use Workbench\Database\Factories\SearchableUserFactory;

#[WithConfig('scout.driver', 'testing')]
#[WithConfig('scout.after_commit', false)]
#[WithConfig('scout.soft_delete', false)]
#[WithMigration]
class MakeRangeSearchableTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithWorkbench;

    public function test_handle_makes_models_in_range_searchable()
    {
        SearchableUserFactory::new()->count(5)->create();

        $model = new SearchableUser;
        $job = new MakeRangeSearchable($model, 2, 4);

        $this->app->make('scout.spied')->shouldReceive('update')->once();

        $job->handle();
    }

    public function test_handle_with_single_id_range()
    {
        SearchableUserFactory::new()->count(3)->create();

        $model = new SearchableUser;
        $job = new MakeRangeSearchable($model, 2, 2);

        $this->app->make('scout.spied')->shouldReceive('update')->once();

        $job->handle();
    }

    public function test_handle_passes_queue_and_connection_to_make_searchable_job()
    {
        $user = SearchableUserFactory::new()->create();

        config(['scout.queue' => ['connection' => 'sync', 'queue' => 'scout']]);

        Queue::fake();

        $job = (new MakeRangeSearchable(SearchableUser::class, $user->id, $user->id))
            ->onQueue('imports')
            ->onConnection('redis');

        $job->handle();

        Queue::assertPushedOn('imports', MakeSearchable::class, function ($job) use ($user) {
            return $job->connection === 'redis' && $job->models->modelKeys() === [$user->id];
        });

        Queue::assertPushed(MakeSearchable::class, 1);
    }

    public function test_handle_uses_model_queue_and_connection_when_neither_is_set()
    {
        $user = SearchableUserFactory::new()->create();

        config(['scout.queue' => ['connection' => 'sync', 'queue' => 'scout']]);

        Queue::fake();

        $job = new MakeRangeSearchable(SearchableUser::class, $user->id, $user->id);

        $job->handle();

        Queue::assertPushedOn('scout', MakeSearchable::class, function ($job) use ($user) {
            return $job->connection === 'sync' && $job->models->modelKeys() === [$user->id];
        });

        Queue::assertPushed(MakeSearchable::class, 1);
    }

    public function test_handle_with_no_models_in_range()
    {
        // Create users with IDs 1-3
        SearchableUserFactory::new()->count(3)->create();

        $model = new SearchableUser;
        // Try to process range 10-15 (no models exist)
        $job = new MakeRangeSearchable($model, 10, 15);

        // Should not call update when no models exist in range
        $this->app->make('scout.spied')->shouldNotReceive('update');

        $job->handle();
    }

    public function test_handle_with_gaps_in_ids()
    {
        // Create users, then delete some to create gaps
        $users = SearchableUserFactory::new()->count(5)->create();

        // Delete users with IDs 2 and 4
        SearchableUser::whereIn('id', [$users[1]->id, $users[3]->id])->delete();

        $model = new SearchableUser;
        $job = new MakeRangeSearchable($model, 1, 5);

        // Should still call update (will process existing models in range)
        $this->app->make('scout.spied')->shouldReceive('update')->once();

        $job->handle();
    }

    public function test_handle_with_reverse_range_throws_no_error()
    {
        SearchableUserFactory::new()->count(3)->create();

        $model = new SearchableUser;
        // Test with start > end (should handle gracefully)
        $job = new MakeRangeSearchable($model, 5, 2);

        // Should not call update when range is invalid
        $this->app->make('scout.spied')->shouldNotReceive('update');

        $job->handle();
    }

    public function test_handle_with_zero_range()
    {
        SearchableUserFactory::new()->count(3)->create();

        $model = new SearchableUser;
        // Test with zero in range
        $job = new MakeRangeSearchable($model, 0, 2);

        // Should call update
        $this->app->make('scout.spied')->shouldReceive('update')->once();

        $job->handle();
    }

    public function test_handle_with_very_large_range()
    {
        SearchableUserFactory::new()->count(5)->create();

        $model = new SearchableUser;
        // Test with very large range
        $job = new MakeRangeSearchable($model, 1, 1000000);

        // Should still call update (will only process existing models)
        $this->app->make('scout.spied')->shouldReceive('update')->once();

        $job->handle();
    }
}
