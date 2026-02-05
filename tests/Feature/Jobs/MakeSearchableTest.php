<?php

namespace Laravel\Scout\Tests\Feature\Jobs;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Tests\Fixtures\OverriddenMakeSearchable;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Workbench\Database\Factories\SearchableUserFactory;

#[WithConfig('scout.driver', 'testing')]
#[WithConfig('scout.after_commit', false)]
#[WithConfig('scout.soft_delete', false)]
#[WithMigration]
class MakeSearchableTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithWorkbench;

    public function test_handle_passes_the_collection_to_engine()
    {
        $model = SearchableUserFactory::new()->create();

        $job = new MakeSearchable($collection = Collection::make([$model]));

        $this->app->make('scout.spied')->shouldReceive('update')->with($collection)->once();

        $job->handle();
    }

    public function test_tries_and_backoff_are_set_from_config()
    {
        config(['scout.jobs.tries' => 3, 'scout.jobs.backoff' => [1, 5, 10]]);

        $model = SearchableUserFactory::new()->create();

        $job = new MakeSearchable(Collection::make([$model]));

        $this->assertSame(3, $job->tries);
        $this->assertSame([1, 5, 10], $job->backoff);
    }

    public function test_tries_and_backoff_are_not_set_without_config()
    {
        config(['scout.jobs.tries' => null, 'scout.jobs.backoff' => null]);

        $model = SearchableUserFactory::new()->create();

        $job = new MakeSearchable(Collection::make([$model]));

        $this->assertNull($job->tries ?? null);
        $this->assertNull($job->backoff ?? null);
    }

    public function test_subclass_tries_and_backoff_are_not_overridden_by_config()
    {
        config(['scout.jobs.tries' => 3, 'scout.jobs.backoff' => [1, 5, 10]]);

        $model = SearchableUserFactory::new()->create();

        $job = new OverriddenMakeSearchable(Collection::make([$model]));

        $this->assertSame(5, $job->tries);
        $this->assertSame([2, 4, 8, 16, 32], $job->backoff());
    }
}
