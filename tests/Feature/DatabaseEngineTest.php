<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Scout\Tests\Fixtures\SearchableUserDatabaseModel;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\Factories\UserFactory;
use Orchestra\Testbench\TestCase;

class DatabaseEngineTest extends TestCase
{
    use LazilyRefreshDatabase, WithFaker, WithLaravelMigrations, WithWorkbench;

    protected function defineEnvironment($app)
    {
        $app->make('config')->set('scout.driver', 'database');
    }

    protected function afterRefreshingDatabase()
    {
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

    public function test_it_does_not_add_search_where_clauses_with_empty_search()
    {
        SearchableUserDatabaseModel::search('')->query(function ($builder) {
            $this->assertSame('select * from "users"', $builder->toSql());
        })->get();
    }

    public function test_it_adds_search_where_clauses_with_non_empty_search()
    {
        SearchableUserDatabaseModel::search('Taylor')->query(function ($builder) {
            $this->assertSame('select * from "users" where ("users"."id" like ? or "users"."name" like ? or "users"."email" like ?)', $builder->toSql());
        })->get();
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

    public function test_it_can_paginate_using_a_custom_page_name()
    {
        $models = SearchableUserDatabaseModel::search('Taylor')->where('email', 'taylor@laravel.com')->paginate();
        $this->assertStringContainsString('page=1', $models->url(1));

        $models = SearchableUserDatabaseModel::search('Taylor')->where('email', 'taylor@laravel.com')->paginate(pageName: 'foo');
        $this->assertStringContainsString('foo=1', $models->url(1));

        $models = SearchableUserDatabaseModel::search('Taylor')->where('email', 'taylor@laravel.com')->paginate(pageName: 'bar');
        $this->assertStringContainsString('bar=1', $models->url(1));
    }

    public function test_it_can_simple_paginate_using_a_custom_page_name()
    {
        $models = SearchableUserDatabaseModel::search('Taylor')->where('email', 'taylor@laravel.com')->simplePaginate();
        $this->assertStringContainsString('page=1', $models->url(1));

        $models = SearchableUserDatabaseModel::search('Taylor')->where('email', 'taylor@laravel.com')->simplePaginate(pageName: 'foo');
        $this->assertStringContainsString('foo=1', $models->url(1));

        $models = SearchableUserDatabaseModel::search('Taylor')->where('email', 'taylor@laravel.com')->simplePaginate(pageName: 'bar');
        $this->assertStringContainsString('bar=1', $models->url(1));
    }

    public function test_limit_is_applied()
    {
        $models = SearchableUserDatabaseModel::search('laravel')->get();
        $this->assertCount(2, $models);

        $models = SearchableUserDatabaseModel::search('laravel')->take(1)->get();
        $this->assertCount(1, $models);
    }

    public function test_tap_is_applied()
    {
        $models = SearchableUserDatabaseModel::search('laravel')->get();
        $this->assertCount(2, $models);

        $models = SearchableUserDatabaseModel::search('laravel')->tap(function ($query) {
            return $query->take(1);
        })->get();
        $this->assertCount(1, $models);
    }

    public function test_it_can_order_results()
    {
        $models = SearchableUserDatabaseModel::search('laravel')->orderBy('name', 'asc')->take(1)->get();
        $this->assertCount(1, $models);
        $this->assertEquals('Abigail Otwell', $models[0]->name);

        $modelsPaginate = SearchableUserDatabaseModel::search('laravel')->orderBy('name', 'asc')->paginate(1, 'page', 1);
        $this->assertCount(1, $modelsPaginate);
        $this->assertEquals('Abigail Otwell', $modelsPaginate[0]->name);

        $modelsSimplePaginate = SearchableUserDatabaseModel::search('laravel')->orderBy('name', 'asc')->simplePaginate(1, 'page', 1);
        $this->assertCount(1, $modelsPaginate);
        $this->assertEquals('Abigail Otwell', $modelsSimplePaginate[0]->name);

        $models = SearchableUserDatabaseModel::search('laravel')->orderBy('name', 'desc')->take(1)->get();
        $this->assertCount(1, $models);
        $this->assertEquals('Taylor Otwell', $models[0]->name);

        $modelsPaginate = SearchableUserDatabaseModel::search('laravel')->orderBy('name', 'desc')->paginate(1, 'page', 1);
        $this->assertCount(1, $modelsPaginate);
        $this->assertEquals('Taylor Otwell', $modelsPaginate[0]->name);

        $modelsSimplePaginate = SearchableUserDatabaseModel::search('laravel')->orderBy('name', 'desc')->simplePaginate(1, 'page', 1);
        $this->assertCount(1, $modelsSimplePaginate);
        $this->assertEquals('Taylor Otwell', $modelsSimplePaginate[0]->name);
    }
}
