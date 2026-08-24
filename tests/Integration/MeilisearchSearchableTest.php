<?php

namespace Laravel\Scout\Tests\Integration;

use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\MeilisearchEngine;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Laravel\Scout\Tests\Fixtures\VersionableModel;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Mockery as m;
use Orchestra\Testbench\Attributes\RequiresEnv;
use PHPUnit\Framework\Attributes\Group;
use Workbench\App\Models\SearchableUser;

/**
 * @group meilisearch
 * @group external-network
 */
#[Group('meilisearch')]
#[Group('external-network')]
#[RequiresEnv('MEILISEARCH_HOST')]
class MeilisearchSearchableTest extends TestCase
{
    use SearchableTests {
        defineScoutDatabaseMigrations as baseDefineScoutDatabaseMigrations;
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        $this->defineScoutEnvironment($app);

        $app['config']->set('scout.meilisearch.index-settings.'.SearchableUser::class.'.filterableAttributes', ['age']);
    }

    /**
     * Define database migrations.
     *
     * @return void
     */
    protected function defineDatabaseMigrations()
    {
        $this->defineScoutDatabaseMigrations();
    }

    protected function defineScoutDatabaseMigrations()
    {
        $this->baseDefineScoutDatabaseMigrations();

        $this->importScoutIndexFrom(SearchableUser::class);
    }

    public function test_it_can_use_basic_search()
    {
        $results = $this->itCanUseBasicSearch();

        $this->assertSame([
            1 => 'Laravel Framework',
            11 => 'Larry Casper',
            12 => 'Reta Larkin',
            39 => 'Linkwood Larkin',
            40 => 'Otis Larson MD',
            41 => 'Gudrun Larkin',
            42 => 'Dax Larkin',
            43 => 'Dana Larson Sr.',
            44 => 'Amos Larson Sr.',
            20 => 'Prof. Larry Prosacco DVM',
        ], $results->pluck('name', 'id')->all());
    }

    public function test_it_can_use_basic_search_with_query_callback()
    {
        $results = $this->itCanUseBasicSearchWithQueryCallback();

        $this->assertSame([
            1 => 'Laravel Framework',
            12 => 'Reta Larkin',
            40 => 'Otis Larson MD',
            41 => 'Gudrun Larkin',
            42 => 'Dax Larkin',
            43 => 'Dana Larson Sr.',
            44 => 'Amos Larson Sr.',
        ], $results->pluck('name', 'id')->all());
    }

    public function test_it_can_use_basic_search_to_fetch_keys()
    {
        $results = $this->itCanUseBasicSearchToFetchKeys();

        $this->assertSame([
            1,
            11,
            12,
            39,
            40,
            41,
            42,
            43,
            44,
            20,
        ], $results->all());
    }

    public function test_it_can_use_basic_search_with_query_callback_to_fetch_keys()
    {
        $results = $this->itCanUseBasicSearchWithQueryCallbackToFetchKeys();

        $this->assertSame([
            1,
            11,
            12,
            39,
            40,
            41,
            42,
            43,
            44,
            20,
        ], $results->all());
    }

    public function test_it_return_same_keys_with_query_callback()
    {
        $this->assertSame(
            $this->itCanUseBasicSearchToFetchKeys()->all(),
            $this->itCanUseBasicSearchWithQueryCallbackToFetchKeys()->all()
        );
    }

    public function test_it_can_use_paginated_search()
    {
        [$page1, $page2] = $this->itCanUsePaginatedSearch();

        $this->assertSame([
            1 => 'Laravel Framework',
            11 => 'Larry Casper',
            12 => 'Reta Larkin',
            39 => 'Linkwood Larkin',
            40 => 'Otis Larson MD',
        ], $page1->pluck('name', 'id')->all());

        $this->assertSame([
            41 => 'Gudrun Larkin',
            42 => 'Dax Larkin',
            43 => 'Dana Larson Sr.',
            44 => 'Amos Larson Sr.',
            20 => 'Prof. Larry Prosacco DVM',
        ], $page2->pluck('name', 'id')->all());
    }

    public function test_it_can_use_user_provided_embeddings_for_semantic_search()
    {
        $model = new class extends SearchableModel
        {
            public static $index;

            public function searchableAs()
            {
                return static::$index;
            }

            public function indexableAs()
            {
                return static::$index;
            }

            public function toSearchableEmbedding()
            {
                return $this->embedding;
            }
        };

        $modelClass = get_class($model);
        $modelClass::$index = config('scout.prefix').'semantic_'.str()->random(12);

        $client = $this->app->make(Client::class);
        $index = $client->index($modelClass::$index);
        $task = $index->updateEmbedders([
            'default' => [
                'source' => 'userProvided',
                'dimensions' => 2,
            ],
        ]);

        $client->waitForTask($task['taskUid']);

        $engine = new MeilisearchEngine($client, false, [
            'model-settings' => [
                $modelClass => [
                    'embedding' => [
                        'embedder' => 'default',
                        'dimensions' => 2,
                    ],
                ],
            ],
        ]);

        try {
            $cat = new $modelClass(['id' => 1, 'name' => 'A sleeping cat']);
            $cat->setAttribute('embedding', [1, 0]);

            $rocket = new $modelClass(['id' => 2, 'name' => 'A rocket launch']);
            $rocket->setAttribute('embedding', [0, 1]);

            $engine->update($model->newCollection([$cat, $rocket]));

            sleep(1);

            $results = $engine->search(
                (new Builder($model, 'a relaxed pet'))
                    ->options(['vector' => [1, 0]])
                    ->semantic()
            );

            $this->assertSame(1, $results['hits'][0]['id']);
        } finally {
            $client->deleteIndex($modelClass::$index);
        }
    }

    public function test_uses_different_indexes()
    {
        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table_v2')->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('deleteDocuments')->with([1]);

        $engine = new MeilisearchEngine($client);
        $engine->delete(Collection::make([new VersionableModel(['id' => 1])]));

        $client = m::mock(Client::class);
        $client->shouldReceive('index')->with('table')->once()->andReturn($index = m::mock(Indexes::class));
        $index->shouldReceive('rawSearch')->once()->andReturn([]);

        $engine = new MeilisearchEngine($client);
        $builder = new Builder(new VersionableModel, '');
        $engine->search($builder);
    }

    public function test_it_can_use_paginated_search_with_query_callback()
    {
        [$page1, $page2] = $this->itCanUsePaginatedSearchWithQueryCallback();

        $this->assertSame([
            1 => 'Laravel Framework',
            12 => 'Reta Larkin',
            40 => 'Otis Larson MD',
        ], $page1->pluck('name', 'id')->all());

        $this->assertSame([
            41 => 'Gudrun Larkin',
            42 => 'Dax Larkin',
            43 => 'Dana Larson Sr.',
            44 => 'Amos Larson Sr.',
        ], $page2->pluck('name', 'id')->all());
    }

    public function test_it_can_use_paginated_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfPaginateUsingAfterRawSearchCallback();

        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMs', $rawResults);
    }

    public function test_it_can_use_raw_paginated_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfPaginateRawUsingAfterRawSearchCallback();

        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMs', $rawResults);
    }

    public function test_it_can_use_simple_paginated_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfSimplePaginateUsingAfterRawSearchCallback();

        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMs', $rawResults);
    }

    public function test_it_can_use_raw_simple_paginated_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfSimplePaginateRawUsingAfterRawSearchCallback();

        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMs', $rawResults);
    }

    public function test_it_can_use_raw_get_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfGetUsingAfterRawSearchCallback();

        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMs', $rawResults);
    }

    public function test_it_can_use_raw_cursor_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfCursorUsingAfterRawSearchCallback();

        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('processingTimeMs', $rawResults);
    }

    public function test_it_can_filter_with_where_comparisons()
    {
        $this->itCanMakeWhereComparisons();
    }

    protected static function scoutDriver(): string
    {
        return 'meilisearch';
    }
}
