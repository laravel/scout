<?php

namespace Laravel\Scout\Tests\Feature\Jobs;

//use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Mockery as m;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\Chirp;
use Workbench\App\Models\User;
use Workbench\Database\Factories\ChirpFactory;
use Workbench\Database\Factories\UserFactory;

#[WithConfig('scout.driver', 'testing')]
#[WithConfig('scout.after_commit', false)]
#[WithConfig('scout.soft_delete', false)]
#[WithMigration]
class RemoveFromSearchTest extends TestCase
{
    use RefreshDatabase;
    use WithWorkbench;

    public function test_handle_passes_the_collection_to_engine()
    {
        $model = UserFactory::new()->create();

        $job = new RemoveFromSearch($models = RemoveableScoutCollection::make([$model]));

        $this->app->make('scout.spied')->shouldReceive('delete')->with(m::type(RemoveableScoutCollection::class))->once();

        $job->handle();
    }

    public function test_models_are_deserialized_without_the_database()
    {
        $model = UserFactory::new()->create(['id' => 1234]);

        $job = new RemoveFromSearch($models = RemoveableScoutCollection::make([$model]));

        $job = unserialize(serialize($job));

        $this->assertInstanceOf(RemoveableScoutCollection::class, $job->models);
        $this->assertCount(1, $job->models);
        $this->assertInstanceOf(User::class, $job->models->first());
        $this->assertSame(1234, $job->models->first()->getScoutKey());
    }

    public function test_models_are_deserialized_without_the_database_using_custom_scout_key()
    {
        $model = ChirpFactory::new()->create(['scout_id' => $uuid = Str::uuid()]);

        $job = new RemoveFromSearch($models = RemoveableScoutCollection::make([$model]));

        $job = unserialize(serialize($job));

        $this->assertInstanceOf(RemoveableScoutCollection::class, $job->models);
        $this->assertCount(1, $job->models);
        $this->assertInstanceOf(Chirp::class, $job->models->first());
        $this->assertEquals($uuid, $job->models->first()->getScoutKey());
        $this->assertEquals('scout_id', $job->models->first()->getScoutKeyName());
    }
}
