<?php

namespace Laravel\Scout\Tests\Feature\Jobs;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Scout\Jobs\MakeSearchable;
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
}
