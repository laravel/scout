<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\SearchableUser;
use Workbench\Database\Factories\SearchableUserFactory;

#[WithConfig('scout.driver', 'database')]
#[WithMigration]
class BuilderTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithFaker;
    use WithWorkbench;

    protected function afterRefreshingDatabase()
    {
        $this->setUpFaker();

        SearchableUserFactory::new()->count(50)->state(new Sequence(function () {
            return ['name' => 'Laravel '.$this->faker()->name()];
        }))->create();

        SearchableUserFactory::new()->times(50)->create();
    }

    public function test_it_can_paginate_without_custom_query_callback()
    {
        $paginator = SearchableUser::search('Laravel')->paginate();

        $this->assertSame(50, $paginator->total());
        $this->assertSame(4, $paginator->lastPage());
        $this->assertSame(15, $paginator->perPage());
    }

    public function test_it_can_paginate_with_custom_query_callback()
    {
        $paginator = SearchableUser::search('Laravel')->query(function ($builder) {
            return $builder->where('id', '<', 11);
        })->paginate();

        $this->assertSame(10, $paginator->total());
        $this->assertSame(1, $paginator->lastPage());
        $this->assertSame(15, $paginator->perPage());
    }

    public function test_it_can_paginate_raw_without_custom_query_callback()
    {
        $paginator = SearchableUser::search('Laravel')->paginateRaw();

        $this->assertSame(50, $paginator->total());
        $this->assertSame(4, $paginator->lastPage());
        $this->assertSame(15, $paginator->perPage());
    }
}
