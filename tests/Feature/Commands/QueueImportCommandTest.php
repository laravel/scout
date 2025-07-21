<?php

namespace Laravel\Scout\Tests\Feature\Commands;

use Error;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeRangeSearchable;
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
class QueueImportCommandTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithWorkbench;

    public function test_it_processes_models_with_records()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(5)->create();

        $this->artisan('scout:queue-import', ['model' => SearchableUser::class])
            ->expectsOutputToContain('models up to ID: 5')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should dispatch one job for the range 1-5
        Queue::assertPushed(MakeRangeSearchable::class, 1);
    }

    public function test_it_handles_no_records_found()
    {
        Queue::fake();

        $this->artisan('scout:queue-import', ['model' => SearchableUser::class])
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_it_uses_custom_chunk_size()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(10)->create();

        $parameters = [
            'model' => SearchableUser::class,
            '--chunk' => 2,
        ];

        $this->artisan('scout:queue-import', $parameters)
            ->expectsOutputToContain('models up to ID: 2')
            ->expectsOutputToContain('models up to ID: 4')
            ->expectsOutputToContain('models up to ID: 6')
            ->expectsOutputToContain('models up to ID: 8')
            ->expectsOutputToContain('models up to ID: 10')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // With chunk size of 2, we should see 5 jobs (2, 2, 2, 2, 2)
        Queue::assertPushed(MakeRangeSearchable::class, 5);
    }

    public function test_it_uses_default_chunk_size_when_not_specified()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(3)->create();

        $this->artisan('scout:queue-import', ['model' => SearchableUser::class])
            ->expectsOutputToContain('models up to ID: 3')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        Queue::assertPushed(MakeRangeSearchable::class, 1);
    }

    public function test_it_processes_models_in_id_range()
    {
        Queue::fake();

        // Create users with specific IDs by creating and deleting some first
        SearchableUserFactory::new()->count(2)->create();
        SearchableUser::query()->delete();

        // Create users that will have IDs starting from 3
        SearchableUserFactory::new()->count(3)->create();

        $this->artisan('scout:queue-import', ['model' => SearchableUser::class])
            ->expectsOutputToContain('models up to ID: 5')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should dispatch one job for the range
        Queue::assertPushed(MakeRangeSearchable::class, 1);
    }

    public function test_it_processes_large_dataset_with_chunking()
    {
        Queue::fake();

        // Create a larger dataset to test chunking
        SearchableUserFactory::new()->count(25)->create();

        $parameters = [
            'model' => SearchableUser::class,
            '--chunk' => 10,
        ];

        $this->artisan('scout:queue-import', $parameters)
            ->expectsOutputToContain('models up to ID: 10')
            ->expectsOutputToContain('models up to ID: 20')
            ->expectsOutputToContain('models up to ID: 25')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // With chunk size of 10, we should see 3 jobs (10, 10, 5)
        Queue::assertPushed(MakeRangeSearchable::class, 3);
    }

    public function test_it_handles_single_record()
    {
        Queue::fake();

        // Create just one user
        SearchableUserFactory::new()->create();

        $this->artisan('scout:queue-import', ['model' => SearchableUser::class])
            ->expectsOutputToContain('models up to ID: 1')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should dispatch one job for the single record
        Queue::assertPushed(MakeRangeSearchable::class, 1);
    }

    public function test_it_uses_scout_chunk_config_when_no_option_provided()
    {
        Queue::fake();

        // Set a custom chunk size in config
        config(['scout.chunk.searchable' => 3]);

        // Create 7 users to test chunking with config value
        SearchableUserFactory::new()->count(7)->create();

        $this->artisan('scout:queue-import', ['model' => SearchableUser::class])
            ->expectsOutputToContain('models up to ID: 3')
            ->expectsOutputToContain('models up to ID: 6')
            ->expectsOutputToContain('models up to ID: 7')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // With chunk size of 3, we should see 3 jobs (3, 3, 1)
        Queue::assertPushed(MakeRangeSearchable::class, 3);
    }

    public function test_it_handles_non_sequential_ids()
    {
        Queue::fake();

        // Create users, delete some to create gaps, then create more
        $users1 = SearchableUserFactory::new()->count(3)->create();
        $users2 = SearchableUserFactory::new()->count(3)->create();

        // Delete the middle users to create gaps
        SearchableUser::whereIn('id', [$users1[1]->id, $users2[0]->id])->delete();

        $this->artisan('scout:queue-import', ['model' => SearchableUser::class])
            ->expectsOutputToContain('models up to ID: 6')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should still process all remaining records in the range
        Queue::assertPushed(MakeRangeSearchable::class, 1);
    }

    public function test_it_handles_chunk_size_larger_than_dataset()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(3)->create();

        $parameters = [
            'model' => SearchableUser::class,
            '--chunk' => 10,
        ];

        $this->artisan('scout:queue-import', $parameters)
            ->expectsOutputToContain('models up to ID: 3')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should dispatch one job since chunk size is larger than dataset
        Queue::assertPushed(MakeRangeSearchable::class, 1);
    }

    public function test_it_handles_chunk_size_of_one()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(3)->create();

        $parameters = [
            'model' => SearchableUser::class,
            '--chunk' => 1,
        ];

        $this->artisan('scout:queue-import', $parameters)
            ->expectsOutputToContain('models up to ID: 1')
            ->expectsOutputToContain('models up to ID: 2')
            ->expectsOutputToContain('models up to ID: 3')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should make 3 separate jobs
        Queue::assertPushed(MakeRangeSearchable::class, 3);
    }

    public function test_it_dispatches_jobs_with_correct_parameters()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(5)->create();

        $this->artisan('scout:queue-import', [
            'model' => SearchableUser::class,
            '--chunk' => 3,
        ])
            ->expectsOutputToContain('models up to ID: 3')
            ->expectsOutputToContain('models up to ID: 5')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should dispatch 2 jobs: one for IDs 1-3, one for IDs 4-5
        Queue::assertPushed(MakeRangeSearchable::class, function ($job) {
            return $job->start == 1 && $job->end == 3;
        });

        Queue::assertPushed(MakeRangeSearchable::class, function ($job) {
            return $job->start == 4 && $job->end == 5;
        });
    }

    public function test_it_handles_invalid_model_class()
    {
        $this->expectException(Error::class);

        $this->artisan('scout:queue-import', ['model' => 'NonExistentModel']);
    }

    public function test_it_handles_zero_chunk_size()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(3)->create();

        // Test with chunk size 0 (should fall back to default)
        $this->artisan('scout:queue-import', [
            'model' => SearchableUser::class,
            '--chunk' => 0,
        ])
            ->expectsOutputToContain('ID: 3')
            ->assertSuccessful();

        // Should still dispatch jobs (using default chunk size)
        Queue::assertPushed(MakeRangeSearchable::class);
    }

    public function test_it_accepts_custom_min_option()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(10)->create();

        $this->artisan('scout:queue-import', [
            'model' => SearchableUser::class,
            '--min' => 5,
        ])
            ->expectsOutputToContain('models up to ID: 10')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should dispatch one job for range 5-10
        Queue::assertPushed(MakeRangeSearchable::class, function ($job) {
            return $job->start == 5 && $job->end == 10;
        });
    }

    public function test_it_accepts_custom_max_option()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(10)->create();

        $this->artisan('scout:queue-import', [
            'model' => SearchableUser::class,
            '--max' => 5,
        ])
            ->expectsOutputToContain('models up to ID: 5')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should dispatch one job for range 1-5
        Queue::assertPushed(MakeRangeSearchable::class, function ($job) {
            return $job->start == 1 && $job->end == 5;
        });
    }

    public function test_it_accepts_both_min_and_max_options()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(10)->create();

        $this->artisan('scout:queue-import', [
            'model' => SearchableUser::class,
            '--min' => 3,
            '--max' => 7,
        ])
            ->expectsOutputToContain('models up to ID: 7')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should dispatch one job for range 3-7
        Queue::assertPushed(MakeRangeSearchable::class, function ($job) {
            return $job->start == 3 && $job->end == 7;
        });
    }

    public function test_it_chunks_custom_range_correctly()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(10)->create();

        $this->artisan('scout:queue-import', [
            'model' => SearchableUser::class,
            '--min' => 2,
            '--max' => 8,
            '--chunk' => 3,
        ])
            ->expectsOutputToContain('models up to ID: 4')
            ->expectsOutputToContain('models up to ID: 7')
            ->expectsOutputToContain('models up to ID: 8')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should dispatch 3 jobs: [2-4], [5-7], [8]
        Queue::assertPushed(MakeRangeSearchable::class, 3);
    }

    public function test_it_handles_min_greater_than_max()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(5)->create();

        $this->artisan('scout:queue-import', [
            'model' => SearchableUser::class,
            '--min' => 5,
            '--max' => 2,
        ])
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should not dispatch any jobs since the range is invalid (start > end in loop)
        Queue::assertNothingPushed();
    }

    public function test_it_handles_negative_min_option()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(3)->create();

        $this->artisan('scout:queue-import', [
            'model' => SearchableUser::class,
            '--min' => -5,
            '--max' => 2,
        ])
            ->expectsOutputToContain('models up to ID: 2')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should dispatch one job for range -5 to 2
        Queue::assertPushed(MakeRangeSearchable::class, function ($job) {
            return $job->start == -5 && $job->end == 2;
        });
    }

    public function test_it_accepts_custom_queue_option()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(10)->create();

        $this->artisan('scout:queue-import', [
            'model' => SearchableUser::class,
            '--queue' => 'custom-queue',
        ])
            ->expectsOutputToContain('models up to ID: 10')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        Queue::assertPushedOn('custom-queue', MakeRangeSearchable::class, function ($job) {
            return $job->start == 1 && $job->end == 10;
        });
    }

    public function test_it_can_accept_all_options()
    {
        Queue::fake();

        SearchableUserFactory::new()->count(20)->create();

        $this->artisan('scout:queue-import', [
            'model' => SearchableUser::class,
            '--min' => 5,
            '--max' => 15,
            '--chunk' => 4,
            '--queue' => 'custom-queue',
        ])
            ->expectsOutputToContain('models up to ID: 8')
            ->expectsOutputToContain('models up to ID: 12')
            ->expectsOutputToContain('models up to ID: 15')
            ->expectsOutputToContain('records have been queued')
            ->assertSuccessful();

        // Should dispatch 3 jobs: [5-8], [9-12], [13-15]
        Queue::assertPushed(MakeRangeSearchable::class, 3);

        Queue::assertPushedOn('custom-queue', MakeRangeSearchable::class, function ($job) {
            return $job->start == 5 && $job->end == 8;
        });

        Queue::assertPushedOn('custom-queue', MakeRangeSearchable::class, function ($job) {
            return $job->start == 9 && $job->end == 12;
        });

        Queue::assertPushedOn('custom-queue', MakeRangeSearchable::class, function ($job) {
            return $job->start == 13 && $job->end == 15;
        });
    }
}
