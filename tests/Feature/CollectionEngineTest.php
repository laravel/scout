<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\Factories\UserFactory;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\SearchableUser;

#[WithConfig('scout.driver', 'collection')]
#[WithMigration]
class CollectionEngineTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithWorkbench;

    protected function afterRefreshingDatabase()
    {
        UserFactory::new()->create([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'created_at' => now()->addDay(),
        ]);

        UserFactory::new()->create([
            'name' => 'Abigail Otwell',
            'email' => 'abigail@laravel.com',
            'created_at' => now()->addDays(2),
        ]);
    }

    public function test_it_can_retrieve_results_with_empty_search()
    {
        $models = SearchableUser::search()->get();

        $this->assertCount(2, $models);
    }

    public function test_it_can_retrieve_results()
    {
        $models = SearchableUser::search('Taylor')->where('email', 'taylor@laravel.com')->get();
        $this->assertCount(1, $models);
        $this->assertEquals(1, $models[0]->id);

        $models = SearchableUser::search('Taylor')->query(function ($query) {
            $query->where('email', 'like', 'taylor@laravel.com');
        })->get();

        $this->assertCount(1, $models);
        $this->assertEquals(1, $models[0]->id);

        $models = SearchableUser::search('Abigail')->where('email', 'abigail@laravel.com')->get();
        $this->assertCount(1, $models);
        $this->assertEquals(2, $models[0]->id);

        $models = SearchableUser::search('Taylor')->where('email', 'abigail@laravel.com')->get();
        $this->assertCount(0, $models);

        $models = SearchableUser::search('Taylor')->where('email', 'taylor@laravel.com')->get();
        $this->assertCount(1, $models);

        $models = SearchableUser::search('otwell')->get();
        $this->assertCount(2, $models);

        $models = SearchableUser::search('laravel')->get();
        $this->assertCount(2, $models);

        $models = SearchableUser::search('foo')->get();
        $this->assertCount(0, $models);

        $models = SearchableUser::search('Abigail')->where('email', 'taylor@laravel.com')->get();
        $this->assertCount(0, $models);
    }

    public function test_it_can_retrieve_results_matching_to_custom_searchable_data()
    {
        $models = SearchableUserWithCustomSearchableData::search('rolyaT')->get();
        $this->assertCount(1, $models);
    }

    public function test_it_can_paginate_results()
    {
        $models = SearchableUser::search('Taylor')->where('email', 'taylor@laravel.com')->paginate();
        $this->assertCount(1, $models);

        $models = SearchableUser::search('Taylor')->where('email', 'abigail@laravel.com')->paginate();
        $this->assertCount(0, $models);

        $models = SearchableUser::search('Taylor')->where('email', 'taylor@laravel.com')->paginate();
        $this->assertCount(1, $models);

        $models = SearchableUser::search('laravel')->paginate();
        $this->assertCount(2, $models);

        $dummyQuery = function ($query) {
            $query->where('name', '!=', 'Dummy');
        };
        $models = SearchableUser::search('laravel')->query($dummyQuery)->orderBy('name')->paginate(1, 'page', 1);
        $this->assertCount(1, $models);
        $this->assertEquals('Abigail Otwell', $models[0]->name);

        $models = SearchableUser::search('laravel')->query($dummyQuery)->orderBy('name')->paginate(1, 'page', 2);
        $this->assertCount(1, $models);
        $this->assertEquals('Taylor Otwell', $models[0]->name);

        $models = SearchableUser::search('laravel')->query($dummyQuery)->orderByDesc('name')->paginate(1, 'page', 1);
        $this->assertCount(1, $models);
        $this->assertEquals('Taylor Otwell', $models[0]->name);

        $models = SearchableUser::search('laravel')->query($dummyQuery)->orderByDesc('name')->paginate(1, 'page', 2);
        $this->assertCount(1, $models);
        $this->assertEquals('Abigail Otwell', $models[0]->name);
    }

    public function test_limit_is_applied()
    {
        $models = SearchableUser::search('laravel')->get();
        $this->assertCount(2, $models);

        $models = SearchableUser::search('laravel')->take(1)->get();
        $this->assertCount(1, $models);
    }

    public function test_it_can_order_results()
    {
        $models = SearchableUser::search('laravel')->orderBy('name', 'asc')->paginate(1, 'page', 1);
        $this->assertCount(1, $models);
        $this->assertEquals('Abigail Otwell', $models[0]->name);

        $models = SearchableUser::search('laravel')->orderBy('name', 'desc')->paginate(1, 'page', 1);
        $this->assertCount(1, $models);
        $this->assertEquals('Taylor Otwell', $models[0]->name);

        $models = SearchableUser::search('laravel')->orderByDesc('name')->paginate(1, 'page', 1);
        $this->assertCount(1, $models);
        $this->assertEquals('Taylor Otwell', $models[0]->name);
    }

    public function test_it_can_order_by_latest_and_oldest()
    {
        $models = SearchableUser::search('laravel')->latest()->paginate(1, 'page', 1);
        $this->assertCount(1, $models);
        $this->assertEquals('Abigail Otwell', $models[0]->name);

        $models = SearchableUser::search('laravel')->oldest()->paginate(1, 'page', 1);
        $this->assertCount(1, $models);
        $this->assertEquals('Taylor Otwell', $models[0]->name);
    }

    public function test_it_can_order_by_custom_model_created_at_timestamp()
    {
        $query = SearchableUserWithCustomCreatedAt::search()->latest();

        $this->assertCount(1, $query->orders);
        $this->assertEquals('created', $query->orders[0]['column']);
    }

    public function test_it_calls_make_searchable_using_before_searching()
    {
        Model::preventAccessingMissingAttributes(true);

        $models = SearchableUserWithUnloadedValue::search('loaded')->get();

        $this->assertCount(2, $models);
    }
}

class SearchableUserWithCustomCreatedAt extends SearchableUser
{
    public const CREATED_AT = 'created';
}

class SearchableUserWithCustomSearchableData extends SearchableUser
{
    /** {@inheritDoc} */
    public function toSearchableArray(): array
    {
        return [
            'reversed_name' => strrev($this->name),
        ];
    }
}

class SearchableUserWithUnloadedValue extends SearchableUser
{
    /** {@inheritDoc} */
    public function toSearchableArray()
    {
        return [
            'value' => $this->unloadedValue,
        ];
    }

    /** {@inheritDoc} */
    public function makeSearchableUsing(Collection $models)
    {
        return $models->each(
            fn ($model) => $model->unloadedValue = 'loaded',
        );
    }
}
