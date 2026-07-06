<?php

namespace Laravel\Scout\Tests\Feature\Jobs;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\MakeSearchableUniquely;
use Laravel\Scout\Tests\Fixtures\OverriddenMakeSearchable;
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

    #[WithConfig('scout.jobs.tries', 3)]
    #[WithConfig('scout.jobs.backoff', [1, 5, 10])]
    #[WithConfig('scout.jobs.max_exceptions', 2)]
    #[WithConfig('scout.jobs.timeout', 30)]
    #[WithConfig('scout.jobs.fail_on_timeout', true)]
    public function test_job_properties_are_set_from_config()
    {
        $model = SearchableUserFactory::new()->create();

        $job = new MakeSearchable(Collection::make([$model]));

        $this->assertSame(3, $job->tries);
        $this->assertSame([1, 5, 10], $job->backoff);
        $this->assertSame(2, $job->maxExceptions);
        $this->assertSame(30, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_job_properties_are_not_set_without_config()
    {
        $model = SearchableUserFactory::new()->create();

        $job = new MakeSearchable(Collection::make([$model]));

        $this->assertNull($job->tries);
        $this->assertNull($job->backoff);
        $this->assertNull($job->maxExceptions);
        $this->assertNull($job->timeout);
        $this->assertNull($job->failOnTimeout);
    }

    #[WithConfig('scout.jobs.tries', 1)]
    #[WithConfig('scout.jobs.backoff', [1, 5, 10])]
    #[WithConfig('scout.jobs.max_exceptions', 1)]
    #[WithConfig('scout.jobs.timeout', 30)]
    #[WithConfig('scout.jobs.fail_on_timeout', true)]
    public function test_subclass_job_properties_are_not_overridden_by_config()
    {
        $model = SearchableUserFactory::new()->create();

        $job = new OverriddenMakeSearchable(Collection::make([$model]));

        $this->assertSame(5, $job->tries);
        $this->assertSame([2, 4, 8, 16, 32], $job->backoff());
        $this->assertSame(3, $job->maxExceptions);
        $this->assertSame(90, $job->timeout);
        $this->assertFalse($job->failOnTimeout);
    }

    public function test_unique_id_is_based_on_the_class_and_scout_keys()
    {
        $models = SearchableUserFactory::new()->count(2)->create();

        $expected = md5(json_encode([
            SearchableUser::class,
            $models->map->getScoutKey()->sort()->values()->all(),
        ]));

        $this->assertSame($expected, (new MakeSearchableUniquely($models))->uniqueId());
    }

    public function test_unique_id_is_not_affected_by_model_order()
    {
        $models = SearchableUserFactory::new()->count(3)->create();

        $this->assertSame(
            (new MakeSearchableUniquely($models))->uniqueId(),
            (new MakeSearchableUniquely($models->reverse()->values()))->uniqueId()
        );
    }

    public function test_unique_id_differs_for_different_models()
    {
        $first = SearchableUserFactory::new()->count(2)->create();
        $second = SearchableUserFactory::new()->count(2)->create();

        $this->assertNotSame(
            (new MakeSearchableUniquely($first))->uniqueId(),
            (new MakeSearchableUniquely($second))->uniqueId()
        );
    }
}
