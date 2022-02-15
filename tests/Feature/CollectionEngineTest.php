<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Scout\ScoutServiceProvider;
use Laravel\Scout\Tests\Fixtures\SearchableUserModel;
use Laravel\Scout\Tests\Fixtures\SearchableUserModelWithCustomSearchableData;
use Orchestra\Testbench\Factories\UserFactory;
use Orchestra\Testbench\TestCase;

class CollectionEngineTest extends TestCase
{
    use WithFaker;

    protected function getPackageProviders($app)
    {
        return [ScoutServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app->make('config')->set('scout.driver', 'collection');
    }

    protected function defineDatabaseMigrations()
    {
        $this->setUpFaker();
        $this->loadLaravelMigrations();

        UserFactory::new()->create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
        ]);

        UserFactory::new()->create([
            'name' => 'Abigail Otwell',
            'email' => 'abigail@laravel.com',
        ]);
    }

    public function test_it_can_retrieve_results_with_empty_search()
    {
        $models = SearchableUserModel::search()->get();

        $this->assertCount(2, $models);
    }

    public function test_it_can_retrieve_results()
    {
        $models = SearchableUserModel::search('Taylor')->where('email', 'taylor@laravel.com')->get();
        $this->assertCount(1, $models);
        $this->assertEquals(1, $models[0]->id);

        $models = SearchableUserModel::search('Taylor')->query(function ($query) {
            $query->where('email', 'like', 'taylor@laravel.com');
        })->get();

        $this->assertCount(1, $models);
        $this->assertEquals(1, $models[0]->id);

        $models = SearchableUserModel::search('Abigail')->where('email', 'abigail@laravel.com')->get();
        $this->assertCount(1, $models);
        $this->assertEquals(2, $models[0]->id);

        $models = SearchableUserModel::search('Taylor')->where('email', 'abigail@laravel.com')->get();
        $this->assertCount(0, $models);

        $models = SearchableUserModel::search('Taylor')->where('email', 'taylor@laravel.com')->get();
        $this->assertCount(1, $models);

        $models = SearchableUserModel::search('otwell')->get();
        $this->assertCount(2, $models);

        $models = SearchableUserModel::search('laravel')->get();
        $this->assertCount(2, $models);

        $models = SearchableUserModel::search('foo')->get();
        $this->assertCount(0, $models);

        $models = SearchableUserModel::search('Abigail')->where('email', 'taylor@laravel.com')->get();
        $this->assertCount(0, $models);
    }

    public function test_it_can_retrieve_results_matching_to_custom_searchable_data()
    {
        $models = SearchableUserModelWithCustomSearchableData::search('rolyaT')->get();
        $this->assertCount(1, $models);
    }

    public function test_it_can_paginate_results()
    {
        $models = SearchableUserModel::search('Taylor')->where('email', 'taylor@laravel.com')->paginate();
        $this->assertCount(1, $models);

        $models = SearchableUserModel::search('Taylor')->where('email', 'abigail@laravel.com')->paginate();
        $this->assertCount(0, $models);

        $models = SearchableUserModel::search('Taylor')->where('email', 'taylor@laravel.com')->paginate();
        $this->assertCount(1, $models);

        $models = SearchableUserModel::search('laravel')->paginate();
        $this->assertCount(2, $models);

        $dummyQuery = function ($query) {
            $query->where('name', '!=', 'Dummy');
        };
        $models = SearchableUserModel::search('laravel')->query($dummyQuery)->orderBy('name')->paginate(1, 'page', 1);
        $this->assertCount(1, $models);
        $this->assertEquals('Abigail Otwell', $models[0]->name);

        $models = SearchableUserModel::search('laravel')->query($dummyQuery)->orderBy('name')->paginate(1, 'page', 2);
        $this->assertCount(1, $models);
        $this->assertEquals('Taylor Otwell', $models[0]->name);
    }

    public function test_limit_is_applied()
    {
        $models = SearchableUserModel::search('laravel')->get();
        $this->assertCount(2, $models);

        $models = SearchableUserModel::search('laravel')->take(1)->get();
        $this->assertCount(1, $models);
    }
}
