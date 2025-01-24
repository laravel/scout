<?php

namespace Laravel\Scout\Tests\Feature\Engines;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\MeilisearchEngine;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Laravel\Scout\Jobs\RemoveFromSearch;
use Meilisearch\Client as SearchClient;
use Meilisearch\Contracts\IndexesResults;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Search\SearchResult;
use Mockery as m;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use Workbench\App\Models\Chirp;
use Workbench\App\Models\SearchableUser;
use Workbench\Database\Factories\ChirpFactory;
use Workbench\Database\Factories\SearchableUserFactory;

use function Orchestra\Testbench\after_resolving;

#[WithConfig('scout.driver', 'meilisearch-testing')]
#[WithMigration]
class MeilisearchEngineTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithWorkbench;

    protected $client;

    protected function defineEnvironment($app)
    {
        after_resolving($app, EngineManager::class, function ($manager) {
            $this->client = m::spy(SearchClient::class);

            $manager->extend('meilisearch-testing', fn () => new MeilisearchEngine($this->client, config('scout.soft_delete')));
        });

        $this->beforeApplicationDestroyed(function () {
            unset($this->client);
        });
    }

    public function test_update_adds_objects_to_index()
    {
        $model = SearchableUserFactory::new()->createQuietly();

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('addDocuments')->once()->with(
            [$model->toSearchableArray()], 'id'
        );

        $engine->update(Collection::make([$model]));
    }

    public function test_delete_removes_objects_to_index()
    {
        $model = SearchableUserFactory::new()->createQuietly();

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteDocuments')->once()->with([1]);

        $engine->delete(Collection::make([$model]));
    }

    public function test_delete_removes_objects_to_index_with_a_custom_search_key()
    {
        $model = ChirpFactory::new()->createQuietly(['scout_id' => 'my-meilisearch-key.5']);

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('index')->once()->with('chirps')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteDocuments')->once()->with(['my-meilisearch-key.5']);

        $engine->delete(Collection::make([$model]));
    }

    public function test_delete_with_removeable_scout_collection_using_custom_search_key()
    {
        $model = ChirpFactory::new()->createQuietly(['scout_id' => 'my-meilisearch-key.5']);

        $job = new RemoveFromSearch(RemoveableScoutCollection::make([$model]));

        $engine = $this->app->make(EngineManager::class)->engine();

        $job = unserialize(serialize($job));

        $this->client->shouldReceive('index')->once()->with('chirps')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteDocuments')->once()->with(['my-meilisearch-key.5']);

        $job->handle();
    }

    public function test_search_sends_correct_parameters_to_meilisearch()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->once()->with('mustang', [
            'filter' => 'foo=1 AND bar=2',
        ]);

        $builder = new Builder(new SearchableUser, 'mustang', function ($meilisearch, $query, $options) {
            $options['filter'] = 'foo=1 AND bar=2';

            return $meilisearch->search($query, $options);
        });

        $engine->search($builder);
    }

    public function test_search_includes_at_least_scoutKeyName_in_attributesToRetrieve_on_builder_options()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->once()->with('mustang', [
            'filter' => 'foo=1 AND bar=2',
            'attributesToRetrieve' => ['id', 'foo'],
        ]);

        $builder = new Builder(new SearchableUser, 'mustang', function ($meilisearch, $query, $options) {
            $options['filter'] = 'foo=1 AND bar=2';

            return $meilisearch->search($query, $options);
        });
        $builder->options = ['attributesToRetrieve' => ['foo']];

        $engine->search($builder);
    }

    public function test_submitting_a_callable_search_with_search_method_returns_array()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $builder = new Builder(
            new SearchableUser,
            $query = 'mustang',
            $callable = function ($meilisearch, $query, $options) {
                $options['filter'] = 'foo=1';

                return $meilisearch->search($query, $options);
            }
        );

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->once()->with($query, ['filter' => 'foo=1'])->andReturn(new SearchResult($expectedResult = [
            'hits' => [],
            'page' => 1,
            'hitsPerPage' => $builder->limit,
            'totalPages' => 1,
            'totalHits' => 0,
            'processingTimeMs' => 1,
            'query' => 'mustang',
        ]));

        $result = $engine->search($builder);

        $this->assertSame($expectedResult, $result);
    }

    public function test_submitting_a_callable_search_with_raw_search_method_works()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $builder = new Builder(
            new SearchableUser,
            $query = 'mustang',
            $callable = function ($meilisearch, $query, $options) {
                $options['filter'] = 'foo=1';

                return $meilisearch->rawSearch($query, $options);
            }
        );

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($query, ['filter' => 'foo=1'])->andReturn($expectedResult = [
            'hits' => [],
            'page' => 1,
            'hitsPerPage' => $builder->limit,
            'totalPages' => 1,
            'totalHits' => 0,
            'processingTimeMs' => 1,
            'query' => $query,
        ]);

        $result = $engine->search($builder);

        $this->assertSame($expectedResult, $result);
    }

    public function test_where_in_conditions_are_applied()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $builder = new Builder(new SearchableUser, '');
        $builder->where('foo', 'bar');
        $builder->where('bar', 'baz');
        $builder->where('baz', null);
        $builder->whereIn('qux', [1, 2]);
        $builder->whereIn('quux', [1, 2]);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'foo="bar" AND bar="baz" AND baz IS NULL AND qux IN [1, 2] AND quux IN [1, 2]',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $engine->search($builder);
    }

    public function test_a_model_is_indexed_with_a_custom_meilisearch_key()
    {
        $model = ChirpFactory::new()->createQuietly(['scout_id' => 'my-meilisearch-key.5']);

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('index')->once()->with('chirps')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('addDocuments')->once()->with([[
            'scout_id' => 'my-meilisearch-key.5',
            'content' => $model->content,
        ]], 'scout_id');

        $engine->update(Collection::make([$model]));
    }

    public function test_flush_a_model_with_a_custom_meilisearch_key()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('index')->once()->with('chirps')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteAllDocuments');

        $engine->flush(new Chirp);
    }

    public function test_update_empty_searchable_array_does_not_add_documents_to_index()
    {
        $_ENV['user.toSearchableArray'] = [];

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldNotReceive('addDocuments');

        $engine->update(Collection::make([new SearchableUser]));

        unset($_ENV['user.toSearchableArray']);
    }

    public function test_pagination_correct_parameters()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $perPage = 5;
        $page = 2;

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->once()->with('mustang', [
            'filter' => 'foo=1',
            'hitsPerPage' => $perPage,
            'page' => $page,
        ]);

        $builder = new Builder(new SearchableUser, 'mustang', function ($meilisearch, $query, $options) {
            $options['filter'] = 'foo=1';

            return $meilisearch->search($query, $options);
        });

        $engine->paginate($builder, $perPage, $page);
    }

    public function test_pagination_sorted_parameter()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $perPage = 5;
        $page = 2;

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('search')->once()->with('mustang', [
            'filter' => 'foo=1',
            'hitsPerPage' => $perPage,
            'page' => $page,
            'sort' => ['name:asc'],
        ]);

        $builder = new Builder(new SearchableUser, 'mustang', function ($meilisearch, $query, $options) {
            $options['filter'] = 'foo=1';

            return $meilisearch->search($query, $options);
        });
        $builder->orderBy('name', 'asc');

        $engine->paginate($builder, $perPage, $page);
    }

    #[WithConfig('scout.soft_delete', true)]
    public function test_update_empty_searchable_array_from_soft_deleted_model_does_not_add_documents_to_index()
    {
        $_ENV['chirp.toSearchableArray'] = [];

        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('index')->once()->with('chirps')->andReturn($index = m::mock(Indexes::class));
        $index->shouldNotReceive('addDocuments');

        $engine->update(Collection::make([new Chirp]));

        unset($_ENV['chirp.toSearchableArray']);
    }

    public function test_performing_search_without_callback_works()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $builder = new Builder(new SearchableUser, '');

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->andReturn([]);

        $engine->search($builder);
    }

    public function test_where_conditions_are_applied()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $builder = new Builder(new SearchableUser, '');
        $builder->where('foo', 'bar');
        $builder->where('key', 'value');

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'foo="bar" AND key="value"',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $engine->search($builder);
    }

    public function test_where_not_in_conditions_are_applied()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $builder = new Builder(new SearchableUser, '');
        $builder->where('foo', 'bar');
        $builder->where('bar', 'baz');
        $builder->whereIn('qux', [1, 2]);
        $builder->whereIn('quux', [1, 2]);
        $builder->whereNotIn('eaea', [3]);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'foo="bar" AND bar="baz" AND qux IN [1, 2] AND quux IN [1, 2] AND eaea NOT IN [3]',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $engine->search($builder);
    }

    public function test_where_in_conditions_are_applied_without_other_conditions()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $builder = new Builder(new SearchableUser, '');
        $builder->whereIn('qux', [1, 2]);
        $builder->whereIn('quux', [1, 2]);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'qux IN [1, 2] AND quux IN [1, 2]',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $engine->search($builder);
    }

    public function test_where_not_in_conditions_are_applied_without_other_conditions()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $builder = new Builder(new SearchableUser, '');
        $builder->whereIn('qux', [1, 2]);
        $builder->whereIn('quux', [1, 2]);
        $builder->whereNotIn('eaea', [3]);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'qux IN [1, 2] AND quux IN [1, 2] AND eaea NOT IN [3]',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $engine->search($builder);
    }

    public function test_empty_where_in_conditions_are_applied_correctly()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $builder = new Builder(new SearchableUser, '');
        $builder->where('foo', 'bar');
        $builder->where('bar', 'baz');
        $builder->whereIn('qux', []);

        $this->client->shouldReceive('index')->once()->with('users')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->with($builder->query, array_filter([
            'filter' => 'foo="bar" AND bar="baz" AND qux IN []',
            'hitsPerPage' => $builder->limit,
        ]))->andReturn([]);

        $engine->search($builder);
    }

    public function test_delete_all_indexes_works_with_pagination()
    {
        $engine = $this->app->make(EngineManager::class)->engine();

        $this->client->shouldReceive('getIndexes')->andReturn($indexesResults = m::mock(IndexesResults::class));

        $indexesResults->shouldReceive('getResults')->once();

        $engine->deleteAllIndexes();
    }
}
