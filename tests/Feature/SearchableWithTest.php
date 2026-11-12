<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Scout\Tests\Fixtures\SearchableWithChildModel;
use Laravel\Scout\Tests\Fixtures\SearchableWithModel;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\Chirp;
use Workbench\Database\Factories\BookmarkFactory;
use Workbench\Database\Factories\ChirpFactory;

class SearchableWithTest extends TestCase
{
    use LazilyRefreshDatabase, WithWorkbench;

    public function test_make_all_searchable_query_eager_loads_the_searchable_with_relations()
    {
        $query = SearchableWithModel::makeAllSearchableQuery();

        $this->assertSame(['chirp'], array_keys($query->getEagerLoads()));
    }

    public function test_make_all_searchable_query_eager_loads_nothing_without_the_attribute()
    {
        $this->assertSame([], Chirp::makeAllSearchableQuery()->getEagerLoads());
    }

    public function test_make_searchable_using_loads_missing_searchable_with_relations()
    {
        BookmarkFactory::new()->for(ChirpFactory::new())->create();

        $models = SearchableWithModel::all();

        $this->assertFalse($models->first()->relationLoaded('chirp'));

        $models->first()->makeSearchableUsing($models);

        $this->assertTrue($models->first()->relationLoaded('chirp'));
    }

    public function test_searchable_with_attribute_is_inherited()
    {
        $this->assertSame(['chirp'], (new SearchableWithChildModel)->resolveSearchableWithFromAttribute());
    }
}
