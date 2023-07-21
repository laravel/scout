<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Laravel\Scout\Scout;
use Laravel\Scout\Tests\Fixtures\OverriddenMakeSearchable;
use Laravel\Scout\Tests\Fixtures\OverriddenRemoveFromSearch;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Laravel\Scout\Tests\Fixtures\SearchableModelWithSoftDeletes;
use Mockery as m;
use Orchestra\Testbench\TestCase;

class SearchableTest extends TestCase
{
    public function test_searchable_using_update_is_called_on_collection()
    {
        $collection = m::mock();
        $collection->shouldReceive('isEmpty')->andReturn(false);
        $collection->shouldReceive('first->makeSearchableUsing')->with($collection)->andReturn($collection);
        $collection->shouldReceive('first->searchableUsing->update')->with($collection);

        $model = new SearchableModel();
        $model->queueMakeSearchable($collection);
    }

    public function test_searchable_using_update_is_not_called_on_empty_collection()
    {
        $collection = m::mock();
        $collection->shouldReceive('isEmpty')->andReturn(true);
        $collection->shouldNotReceive('first->searchableUsing->update');

        $model = new SearchableModel;
        $model->queueMakeSearchable($collection);
    }

    public function test_overridden_make_searchable_is_dispatched()
    {
        Queue::fake();

        config()->set('scout.queue', true);
        Scout::makeSearchableUsing(OverriddenMakeSearchable::class);

        $collection = m::mock();
        $collection->shouldReceive('isEmpty')->andReturn(false);
        $collection->shouldReceive('first->syncWithSearchUsingQueue');
        $collection->shouldReceive('first->syncWithSearchUsing');

        $model = new SearchableModel;
        $model->queueMakeSearchable($collection);

        Scout::makeSearchableUsing(MakeSearchable::class);
    }

    public function test_searchable_using_delete_is_called_on_collection()
    {
        $collection = m::mock();
        $collection->shouldReceive('isEmpty')->andReturn(false);
        $collection->shouldReceive('first->searchableUsing->delete')->with($collection);

        $model = new SearchableModel;
        $model->queueRemoveFromSearch($collection);
    }

    public function test_searchable_using_delete_is_not_called_on_empty_collection()
    {
        $collection = m::mock();
        $collection->shouldReceive('isEmpty')->andReturn(true);
        $collection->shouldNotReceive('first->searchableUsing->delete');

        $model = new SearchableModel;
        $model->queueRemoveFromSearch($collection);
    }

    public function test_overridden_remove_from_search_is_dispatched()
    {
        Queue::fake();

        config()->set('scout.queue', true);
        Scout::removeFromSearchUsing(OverriddenRemoveFromSearch::class);

        $collection = m::mock();
        $collection->shouldReceive('isEmpty')->andReturn(false);
        $collection->shouldReceive('first->syncWithSearchUsingQueue');
        $collection->shouldReceive('first->syncWithSearchUsing');

        $model = new SearchableModel;
        $model->queueRemoveFromSearch($collection);

        Scout::removeFromSearchUsing(RemoveFromSearch::class);
    }

    public function test_was_searchable_on_model_without_soft_deletes()
    {
        $model = new SearchableModel;
        $model->syncOriginal();

        $this->assertTrue($model->wasSearchableBeforeUpdate());
        $this->assertTrue($model->wasSearchableBeforeDelete());
    }

    public function test_it_queries_searchable_models_by_their_ids_with_integer_key_type()
    {
        $model = M::mock(SearchableModel::class)->makePartial();
        $model->shouldReceive('newQuery')->andReturnSelf();
        $model->shouldReceive('getScoutKeyType')->andReturn('int');
        $model->shouldReceive('getScoutKeyName')->andReturn('id');
        $model->shouldReceive('qualifyColumn')->with('id')->andReturn('qualified_id');
        $model->shouldReceive('whereIntegerInRaw')->with('qualified_id', [1, 2, 3])->andReturnSelf();

        $scoutBuilder = M::mock(\Laravel\Scout\Builder::class);
        $scoutBuilder->queryCallback = null;

        $model->queryScoutModelsByIds($scoutBuilder, [1, 2, 3]);
    }

    public function test_it_queries_searchable_models_by_their_ids_with_string_key_type()
    {
        $model = M::mock(SearchableModel::class)->makePartial();
        $model->shouldReceive('newQuery')->andReturnSelf();
        $model->shouldReceive('getScoutKeyType')->andReturn('string');
        $model->shouldReceive('getScoutKeyName')->andReturn('id');
        $model->shouldReceive('qualifyColumn')->with('id')->andReturn('qualified_id');
        $model->shouldReceive('whereIn')->with('qualified_id', [1, 2, 3])->andReturnSelf();

        $scoutBuilder = M::mock(\Laravel\Scout\Builder::class);
        $scoutBuilder->queryCallback = null;

        $model->queryScoutModelsByIds($scoutBuilder, [1, 2, 3]);
    }

    public function test_was_searchable_before_update_works_from_true_to_false()
    {
        $model = new SearchableModelWithSoftDeletes([
            'published_at' => now(),
        ]);
        $model->syncOriginal();

        $model->published_at = null;

        $this->assertTrue($model->wasSearchableBeforeUpdate());
        $this->assertFalse($model->shouldBeSearchable());
    }

    public function test_was_searchable_before_delete_works_when_deleting()
    {
        $model = new SearchableModelWithSoftDeletes([
            'published_at' => now(),
        ]);
        $model->syncOriginal();

        // Mark as deleted!
        $model->setAttribute($model->getDeletedAtColumn(), now());

        $this->assertTrue($model->wasSearchableBeforeDelete());
        $this->assertFalse($model->shouldBeSearchable());
    }

    public function test_make_all_searchable_uses_order_by()
    {
        ModelStubForMakeAllSearchable::makeAllSearchable();
    }
}

class ModelStubForMakeAllSearchable extends SearchableModel
{
    public function newQuery()
    {
        $mock = m::mock(Builder::class);

        $mock->shouldReceive('when')
                ->with(true, m::type('Closure'))
                ->andReturnUsing(function ($condition, $callback) use ($mock) {
                    $callback($mock);

                    return $mock;
                });

        $mock->shouldReceive('orderBy')
            ->with('model_stub_for_make_all_searchables.id')
            ->andReturnSelf()
            ->shouldReceive('searchable');

        $mock->shouldReceive('when')->andReturnSelf();

        return $mock;
    }
}
