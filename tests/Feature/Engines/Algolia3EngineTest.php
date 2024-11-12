<?php

namespace Laravel\Scout\Tests\Feature\Engines;

use Algolia\AlgoliaSearch\SearchClient;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\Assert;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Algolia3Engine;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Mockery as m;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\User;
use Workbench\Database\Factories\ChirpFactory;
use Workbench\Database\Factories\UserFactory;

use function Orchestra\Testbench\after_resolving;

#[WithConfig('scout.driver', 'algolia')]
#[WithMigration]
class Algolia3EngineTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithWorkbench;

    protected $client;

    protected function defineEnvironment($app)
    {
        after_resolving($app, EngineManager::class, function ($manager) {
            $this->client = m::spy(SearchClient::class);

            $manager->extend('algolia', fn () => new Algolia3Engine($this->client));
        });

        $this->beforeApplicationDestroyed(function () {
            unset($this->client);
        });
    }

    public function test_update_adds_objects_to_index()
    {
        $model = UserFactory::new()->createQuietly();

        $engine = $this->app->make(EngineManager::class)->engine('algolia');

        $this->client->shouldReceive('initIndex')->with('users')->once()->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('saveObjects')->with([[
            'id' => $model->getKey(),
            'name' => $model->name,
            'email' => $model->email,
            'objectID' => $model->getScoutKey(),
        ]])->once();

        $engine->update(Collection::make([$model]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $model = UserFactory::new()->createQuietly();

        $engine = $this->app->make(EngineManager::class)->engine('algolia');

        $this->client->shouldReceive('initIndex')->with('users')->once()->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('deleteObjects')->with([1])->once();

        $engine->delete(Collection::make([$model]));
    }

    public function test_delete_removes_objects_to_index_with_a_custom_search_key()
    {
        $model = ChirpFactory::new()->createQuietly([
            'scout_id' => 'my-algolia-key.5',
        ]);

        $engine = $this->app->make(EngineManager::class)->engine('algolia');

        $this->client->shouldReceive('initIndex')->with('chirps')->once()->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteObjects')->once()->with(['my-algolia-key.5']);

        $engine->delete(Collection::make([$model]));
    }

    public function test_delete_with_removeable_scout_collection_using_custom_search_key()
    {
        $model = ChirpFactory::new()->createQuietly([
            'scout_id' => 'my-algolia-key.5',
        ]);

        $engine = $this->app->make(EngineManager::class)->engine('algolia');

        $job = new RemoveFromSearch(RemoveableScoutCollection::make([$model]));

        $job = unserialize(serialize($job));

        $this->client->shouldReceive('initIndex')->with('chirps')->once()->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('deleteObjects')->once()->with(['my-algolia-key.5']);

        $job->handle();
    }

    public function test_search_sends_correct_parameters_to_algolia()
    {
        UserFactory::new()->createQuietly(['name' => 'zonda']);

        $engine = $this->app->make(EngineManager::class)->engine('algolia');

        $this->client->shouldReceive('initIndex')->with('users')->once()->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->with('zonda', [
            'numericFilters' => ['foo=1'],
        ])->once();

        $builder = new Builder(new User, 'zonda');
        $builder->where('foo', 1);

        $engine->search($builder);
    }

    public function test_search_sends_correct_parameters_to_algolia_for_where_in_search()
    {
        UserFactory::new()->createQuietly(['name' => 'zonda']);

        $engine = $this->app->make(EngineManager::class)->engine('algolia');

        $this->client->shouldReceive('initIndex')->with('users')->once()->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->with('zonda', [
            'numericFilters' => ['foo=1', ['bar=1', 'bar=2']],
        ]);

        $builder = new Builder(new User, 'zonda');
        $builder->where('foo', 1)->whereIn('bar', [1, 2]);

        $engine->search($builder);
    }

    public function test_search_sends_correct_parameters_to_algolia_for_empty_where_in_search()
    {
        UserFactory::new()->createQuietly(['name' => 'zonda']);

        $engine = $this->app->make(EngineManager::class)->engine('algolia');

        $this->client->shouldReceive('initIndex')->with('users')->once()->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->with('zonda', [
            'numericFilters' => ['foo=1', '0=1'],
        ]);

        $builder = new Builder(new User, 'zonda');
        $builder->where('foo', 1)->whereIn('bar', []);
        $engine->search($builder);
    }

    public function test_map_correctly_maps_results_to_models()
    {
        $model = UserFactory::new()->createQuietly(['name' => 'zonda']);

        $engine = $this->app->make(EngineManager::class)->engine('algolia');

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, [
            'nbHits' => 1,
            'hits' => [
                ['objectID' => 1, 'id' => 1, '_rankingInfo' => ['nbTypos' => 0]],
            ],
        ], $model);

        $this->assertCount(1, $results);
        $this->assertEquals(['_rankingInfo' => ['nbTypos' => 0]], $results->first()->scoutMetaData());
        Assert::assertArraySubset(['id' => 1, 'name' => 'zonda'], $results->first()->toArray());
    }
}
