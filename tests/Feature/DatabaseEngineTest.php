<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Scout\ScoutServiceProvider;
use Laravel\Scout\Tests\Fixtures\SearchableUserDatabaseModel;
use Orchestra\Testbench\Factories\UserFactory;
use Orchestra\Testbench\TestCase;

class DatabaseEngineTest extends TestCase
{
    use WithFaker;

    protected function getPackageProviders($app)
    {
        return [ScoutServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app->make('config')->set('scout.driver', 'database');
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
        $models = SearchableUserDatabaseModel::search()->get();

        $this->assertCount(2, $models);
    }

    public function test_it_can_retrieve_results()
    {
        $models = SearchableUserDatabaseModel::search('Taylor')->where('email', 'taylor@laravel.com')->get();
        $this->assertCount(1, $models);
        $this->assertEquals(1, $models[0]->id);

        $models = SearchableUserDatabaseModel::search('Taylor')->query(function ($query) {
            $query->where('email', 'like', 'taylor@laravel.com');
        })->get();

        $this->assertCount(1, $models);
        $this->assertEquals(1, $models[0]->id);

        $models = SearchableUserDatabaseModel::search('Abigail')->where('email', 'abigail@laravel.com')->get();
        $this->assertCount(1, $models);
        $this->assertEquals(2, $models[0]->id);

        $models = SearchableUserDatabaseModel::search('Taylor')->where('email', 'abigail@laravel.com')->get();
        $this->assertCount(0, $models);

        $models = SearchableUserDatabaseModel::search('Taylor')->where('email', 'taylor@laravel.com')->get();
        $this->assertCount(1, $models);

        $models = SearchableUserDatabaseModel::search('otwell')->get();
        $this->assertCount(2, $models);

        $models = SearchableUserDatabaseModel::search('laravel')->get();
        $this->assertCount(2, $models);

        $models = SearchableUserDatabaseModel::search('foo')->get();
        $this->assertCount(0, $models);

        $models = SearchableUserDatabaseModel::search('Abigail')->where('email', 'taylor@laravel.com')->get();
        $this->assertCount(0, $models);
    }

    public function test_it_can_paginate_results()
    {
        $models = SearchableUserDatabaseModel::search('Taylor')->where('email', 'taylor@laravel.com')->paginate();
        $this->assertCount(1, $models);

        $models = SearchableUserDatabaseModel::search('Taylor')->where('email', 'abigail@laravel.com')->paginate();
        $this->assertCount(0, $models);

        $models = SearchableUserDatabaseModel::search('Taylor')->where('email', 'taylor@laravel.com')->paginate();
        $this->assertCount(1, $models);

        $models = SearchableUserDatabaseModel::search('laravel')->paginate();
        $this->assertCount(2, $models);
    }
}
