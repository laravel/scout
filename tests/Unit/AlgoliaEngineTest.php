<?php

namespace Laravel\Scout\Tests\Unit;

use Algolia\AlgoliaSearch\SearchClient;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\AlgoliaEngine;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Laravel\Scout\Tests\Fixtures\EmptySearchableModel;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Laravel\Scout\Tests\Fixtures\SoftDeletedEmptySearchableModel;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use stdClass;

class AlgoliaEngineTest extends TestCase
{
    protected function setUp(): void
    {
        Config::shouldReceive('get')->with('scout.after_commit', m::any())->andReturn(false);
        Config::shouldReceive('get')->with('scout.soft_delete', m::any())->andReturn(false);
    }

    protected function tearDown(): void
    {
        Container::getInstance()->flush();
        m::close();
    }

    public function test_update_adds_objects_to_index()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('saveObjects')->with([[
            'id' => 1,
            'objectID' => 1,
        ]]);

        $engine = new AlgoliaEngine($client);
        $engine->update(Collection::make([new SearchableModel]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('deleteObjects')->with([1]);

        $engine = new AlgoliaEngine($client);
        $engine->delete(Collection::make([new SearchableModel(['id' => 1])]));
    }

    public function test_delete_removes_objects_to_index_with_a_custom_search_key()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteObjects')->once()->with(['my-algolia-key.5']);

        $engine = new AlgoliaEngine($client);
        $engine->delete(Collection::make([new AlgoliaCustomKeySearchableModel(['id' => 5])]));
    }

    public function test_delete_with_removeable_scout_collection_using_custom_search_key()
    {
        $job = new RemoveFromSearch(Collection::make([
            new AlgoliaCustomKeySearchableModel(['id' => 5]),
        ]));

        $job = unserialize(serialize($job));

        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('deleteObjects')->once()->with(['my-algolia-key.5']);

        $engine = new AlgoliaEngine($client);
        $engine->delete($job->models);
    }

    public function test_remove_from_search_job_uses_custom_search_key()
    {
        $job = new RemoveFromSearch(Collection::make([
            new AlgoliaCustomKeySearchableModel(['id' => 5]),
        ]));

        $job = unserialize(serialize($job));

        Container::getInstance()->bind(EngineManager::class, function () {
            $engine = m::mock(AlgoliaEngine::class);

            $engine->shouldReceive('delete')->once()->with(m::on(function ($collection) {
                $keyName = ($model = $collection->first())->getScoutKeyName();

                return $model->getAttributes()[$keyName] === 'my-algolia-key.5';
            }));

            $manager = m::mock(EngineManager::class);

            $manager->shouldReceive('engine')->andReturn($engine);

            return $manager;
        });

        $job->handle();
    }

    public function test_search_sends_correct_parameters_to_algolia()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->with('zonda', [
            'filters' => 'foo:1',
        ]);

        $engine = new AlgoliaEngine($client);
        $builder = new Builder(new SearchableModel, 'zonda');
        $builder->where('foo', 1);
        $engine->search($builder);
    }

    public function test_search_sends_correct_parameters_to_algolia_for_where_in_search()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->with('zonda', [
            'filters' => 'foo:1 AND (bar:1 OR bar:2)',
        ]);

        $engine = new AlgoliaEngine($client);
        $builder = new Builder(new SearchableModel, 'zonda');
        $builder->where('foo', 1)->whereIn('bar', [1, 2]);
        $engine->search($builder);
    }

    public function test_search_sends_correct_parameters_to_algolia_for_empty_where_in_search()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->with('zonda', [
            'filters' => 'foo:1 AND (0=1)',
        ]);

        $engine = new AlgoliaEngine($client);
        $builder = new Builder(new SearchableModel, 'zonda');
        $builder->where('foo', 1)->whereIn('bar', []);
        $engine->search($builder);
    }

    public function test_advanced_where_query_are_constructed_correctly()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->with('zonda', [
            'filters' => 'field1>1 OR field2<2 OR NOT field3:"test word" AND NOT field4:true',
        ]);

        $engine = new AlgoliaEngine($client);
        $builder = new Builder(new SearchableModel, 'zonda');
        $builder->where('field1', '>', 1)
            ->orWhere('field2', '<', 2)
            ->orWhereNot('field3', '=', 'test word')
            ->whereNot('field4', '=', true);

        $engine->search($builder);
    }

    public function test_advanced_where_between_query_are_constructed_correctly()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->with('zonda', [
            'filters' => 'field1: 1 TO 2 OR field2: 3 TO 4 OR NOT field3: 5 TO 6 AND NOT field4: 7 TO 8',
        ]);

        $engine = new AlgoliaEngine($client);
        $builder = new Builder(new SearchableModel, 'zonda');
        $builder->whereBetween('field1', [1,2])
            ->orWhereBetween('field2', [3,4])
            ->orWhereNotBetween('field3', [5,6])
            ->whereNotBetween('field4', [7,8]);

        $engine->search($builder);
    }

    public function test_advanced_where_in_query_are_constructed_correctly()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->with('zonda', [
            'filters' => '(field1:1 OR field1:2 OR field1:3) OR (field2:4 OR field2:5 OR field2:6) OR NOT (field3:7 OR field3:8 OR field3:9) AND NOT (field4:"string1" OR field4:"string2" OR field4:"string3")',
        ]);

        $engine = new AlgoliaEngine($client);
        $builder = new Builder(new SearchableModel, 'zonda');
        $builder->whereInAdvanced('field1', [1,2,3])
            ->orWhereIn('field2', [4,5,6])
            ->orWhereNotIn('field3', [7,8,9])
            ->whereNotIn('field4', ['string1', 'string2', 'string3']);

        $engine->search($builder);
    }

    public function test_advanced_nested_where_query_are_constructed_correctly()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('search')->with('zonda', [
            'filters' => 'field1:1 OR (subField1:"string1" AND subField2>2 OR subField3:"string3")',
        ]);

        $engine = new AlgoliaEngine($client);
        $builder = new Builder(new SearchableModel, 'zonda');
        $builder->where('field1', '=', 1)
                ->orWhere(fn(Builder $subBuilder) =>
                                $subBuilder->where('subField1', 'string1')
                                            ->where('subField2', '>', 2)
                                            ->orWhere('subField3', '=', 'string3'));
        $engine->search($builder);
    }


    public function test_map_correctly_maps_results_to_models()
    {
        $client = m::mock(SearchClient::class);
        $engine = new AlgoliaEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive('getScoutModelsByIds')->andReturn($models = Collection::make([
            new SearchableModel(['id' => 1]),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, ['nbHits' => 1, 'hits' => [
            ['objectID' => 1, 'id' => 1],
        ]], $model);

        $this->assertCount(1, $results);
    }

    public function test_map_method_respects_order()
    {
        $client = m::mock(SearchClient::class);
        $engine = new AlgoliaEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive('getScoutModelsByIds')->andReturn($models = Collection::make([
            new SearchableModel(['id' => 1]),
            new SearchableModel(['id' => 2]),
            new SearchableModel(['id' => 3]),
            new SearchableModel(['id' => 4]),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->map($builder, ['nbHits' => 4, 'hits' => [
            ['objectID' => 1, 'id' => 1],
            ['objectID' => 2, 'id' => 2],
            ['objectID' => 4, 'id' => 4],
            ['objectID' => 3, 'id' => 3],
        ]], $model);

        $this->assertCount(4, $results);

        // It's important we assert with array keys to ensure
        // they have been reset after sorting.
        $this->assertEquals([
            0 => ['id' => 1],
            1 => ['id' => 2],
            2 => ['id' => 4],
            3 => ['id' => 3],
        ], $results->toArray());
    }

    public function test_lazy_map_correctly_maps_results_to_models()
    {
        $client = m::mock(SearchClient::class);
        $engine = new AlgoliaEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive('queryScoutModelsByIds->cursor')->andReturn($models = LazyCollection::make([
            new SearchableModel(['id' => 1]),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, ['nbHits' => 1, 'hits' => [
            ['objectID' => 1, 'id' => 1],
        ]], $model);

        $this->assertCount(1, $results);
    }

    public function test_lazy_map_method_respects_order()
    {
        $client = m::mock(SearchClient::class);
        $engine = new AlgoliaEngine($client);

        $model = m::mock(stdClass::class);
        $model->shouldReceive('queryScoutModelsByIds->cursor')->andReturn($models = LazyCollection::make([
            new SearchableModel(['id' => 1]),
            new SearchableModel(['id' => 2]),
            new SearchableModel(['id' => 3]),
            new SearchableModel(['id' => 4]),
        ]));

        $builder = m::mock(Builder::class);

        $results = $engine->lazyMap($builder, ['nbHits' => 4, 'hits' => [
            ['objectID' => 1, 'id' => 1],
            ['objectID' => 2, 'id' => 2],
            ['objectID' => 4, 'id' => 4],
            ['objectID' => 3, 'id' => 3],
        ]], $model);

        $this->assertCount(4, $results);

        // It's important we assert with array keys to ensure
        // they have been reset after sorting.
        $this->assertEquals([
            0 => ['id' => 1],
            1 => ['id' => 2],
            2 => ['id' => 4],
            3 => ['id' => 3],
        ], $results->toArray());
    }

    public function test_a_model_is_indexed_with_a_custom_algolia_key()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('saveObjects')->with([[
            'id' => 1,
            'objectID' => 'my-algolia-key.1',
        ]]);

        $engine = new AlgoliaEngine($client);
        $engine->update(Collection::make([new AlgoliaCustomKeySearchableModel]));
    }

    public function test_a_model_is_removed_with_a_custom_algolia_key()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('deleteObjects')->with(['my-algolia-key.1']);

        $engine = new AlgoliaEngine($client);
        $engine->delete(Collection::make([new AlgoliaCustomKeySearchableModel(['id' => 1])]));
    }

    public function test_flush_a_model_with_a_custom_algolia_key()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldReceive('clearObjects');

        $engine = new AlgoliaEngine($client);
        $engine->flush(new AlgoliaCustomKeySearchableModel);
    }

    public function test_update_empty_searchable_array_does_not_add_objects_to_index()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock(stdClass::class));
        $index->shouldNotReceive('saveObjects');

        $engine = new AlgoliaEngine($client);
        $engine->update(Collection::make([new EmptySearchableModel]));
    }

    public function test_update_empty_searchable_array_from_soft_deleted_model_does_not_add_objects_to_index()
    {
        $client = m::mock(SearchClient::class);
        $client->shouldReceive('initIndex')->with('table')->andReturn($index = m::mock('StdClass'));
        $index->shouldNotReceive('saveObjects');

        $engine = new AlgoliaEngine($client, true);
        $engine->update(Collection::make([new SoftDeletedEmptySearchableModel]));
    }
}

class AlgoliaCustomKeySearchableModel extends SearchableModel
{
    public function getScoutKey()
    {
        return 'my-algolia-key.'.$this->getKey();
    }
}
