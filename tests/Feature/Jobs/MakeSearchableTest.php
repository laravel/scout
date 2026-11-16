<?php

namespace Laravel\Scout\Tests\Feature\Jobs;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\MakeSearchableUniquely;
use Laravel\Scout\Tests\Fixtures\OverriddenMakeSearchable;
use Laravel\Scout\Tests\Fixtures\SearchableModelWithSoftDeletes;
use Mockery as m;
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

    public function test_handle_skips_models_soft_deleted_after_the_job_was_dispatched()
    {
        $job = new MakeSearchable(Collection::make([
            $this->softDeletableModel(1),
            $this->softDeletableModel(2, trashed: true),
            $this->softDeletableModel(3),
        ]));

        $this->app->make('scout.spied')->shouldReceive('update')->with(m::on(function ($models) {
            return $models->modelKeys() === [1, 3];
        }))->once();

        $job->handle();
    }

    public function test_handle_does_not_call_engine_when_all_models_were_soft_deleted()
    {
        $job = new MakeSearchable(Collection::make([
            $this->softDeletableModel(1, trashed: true),
            $this->softDeletableModel(2, trashed: true),
        ]));

        $this->app->make('scout.spied')->shouldNotReceive('update');

        $job->handle();
    }

    #[WithConfig('scout.soft_delete', true)]
    public function test_handle_keeps_soft_deleted_models_when_soft_delete_is_enabled()
    {
        $job = new MakeSearchable(Collection::make([
            $this->softDeletableModel(1),
            $this->softDeletableModel(2, trashed: true),
        ]));

        $this->app->make('scout.spied')->shouldReceive('update')->with(m::on(function ($models) {
            return $models->modelKeys() === [1, 2];
        }))->once();

        $job->handle();
    }

    #[WithConfig('scout.jobs.tries', 3)]
    #[WithConfig('scout.jobs.backoff', [1, 5, 10])]
    #[WithConfig('scout.jobs.max_exceptions', 2)]
    public function test_job_properties_are_set_from_config()
    {
        $model = SearchableUserFactory::new()->create();

        $job = new MakeSearchable(Collection::make([$model]));

        $this->assertSame(3, $job->tries);
        $this->assertSame([1, 5, 10], $job->backoff);
        $this->assertSame(2, $job->maxExceptions);
    }

    public function test_job_properties_are_not_set_without_config()
    {
        $model = SearchableUserFactory::new()->create();

        $job = new MakeSearchable(Collection::make([$model]));

        $this->assertNull($job->tries);
        $this->assertNull($job->backoff);
        $this->assertNull($job->maxExceptions);
    }

    #[WithConfig('scout.jobs.tries', 1)]
    #[WithConfig('scout.jobs.backoff', [1, 5, 10])]
    #[WithConfig('scout.jobs.max_exceptions', 1)]
    public function test_subclass_job_properties_are_not_overridden_by_config()
    {
        $model = SearchableUserFactory::new()->create();

        $job = new OverriddenMakeSearchable(Collection::make([$model]));

        $this->assertSame(5, $job->tries);
        $this->assertSame([2, 4, 8, 16, 32], $job->backoff());
        $this->assertSame(3, $job->maxExceptions);
    }

    public function test_job_fails_on_timeout_by_default()
    {
        $model = SearchableUserFactory::new()->create();

        $job = new MakeSearchable(Collection::make([$model]));

        $this->assertTrue($job->failOnTimeout);
    }

    public function test_subclass_can_opt_out_of_failing_on_timeout()
    {
        $model = SearchableUserFactory::new()->create();

        $job = new OverriddenMakeSearchable(Collection::make([$model]));

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

    protected function softDeletableModel($id, $trashed = false)
    {
        return (new SearchableModelWithSoftDeletes)->forceFill([
            'id' => $id,
            'deleted_at' => $trashed ? now() : null,
        ]);
    }
}
