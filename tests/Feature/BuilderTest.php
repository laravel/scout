<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\MeiliSearchEngine;
use Laravel\Scout\ScoutServiceProvider;
use Laravel\Scout\Tests\Fixtures\SearchableUserModel;
use Mockery as m;
use Orchestra\Testbench\Factories\UserFactory;
use Orchestra\Testbench\TestCase;

class BuilderTest extends TestCase
{
    use WithFaker;

    protected function getPackageProviders($app)
    {
        return [ScoutServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app->make('config')->set('scout.driver', 'fake');
    }

    protected function defineDatabaseMigrations()
    {
        $this->setUpFaker();
        $this->loadLaravelMigrations();

        UserFactory::new()->count(50)->state(new Sequence(function () {
            return ['name' => 'Laravel '.$this->faker()->name()];
        }))->create();

        UserFactory::new()->times(50)->create();
    }

    public function test_it_can_paginate_without_custom_query_callback()
    {
        $this->prepareScoutSearchMockUsing('Laravel');

        $paginator = SearchableUserModel::search('Laravel')->paginate();

        $this->assertSame(50, $paginator->total());
        $this->assertSame(4, $paginator->lastPage());
        $this->assertSame(15, $paginator->perPage());
    }

    public function test_it_can_paginate_with_custom_query_callback()
    {
        $this->prepareScoutSearchMockUsing('Laravel');

        $paginator = SearchableUserModel::search('Laravel')->query(function ($builder) {
            return $builder->where('id', '<', 11);
        })->paginate();

        $this->assertSame(10, $paginator->total());
        $this->assertSame(1, $paginator->lastPage());
        $this->assertSame(15, $paginator->perPage());
    }

    protected function prepareScoutSearchMockUsing($searchQuery)
    {
        $engine = m::mock('MeiliSearch\Client');
        $indexes = m::mock('MeiliSearch\Endpoints\Indexes');

        $manager = $this->app->make(EngineManager::class);
        $manager->extend('fake', function () use ($engine) {
            return new MeiliSearchEngine($engine);
        });

        $query = User::where('name', 'like', $searchQuery.'%');

        $engine->shouldReceive('index')->with('users')->andReturn($indexes);
        $indexes->shouldReceive('rawSearch')->with($searchQuery, ['limit' => 15])->andReturn([
            'hits' => $query->get()->transform(function ($result) {
                return [
                    'id' => $result->getKey(),
                    'name' => $result->name,
                ];
            }),
            'nbHits' => $query->count(),
        ]);
    }
}
