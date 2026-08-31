<?php

namespace Laravel\Scout\Tests\Integration;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\TypesenseEngine;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Orchestra\Testbench\Attributes\RequiresEnv;
use PHPUnit\Framework\Attributes\Group;
use Typesense\Client;
use Workbench\App\Models\SearchableUser;

/**
 * @group typesense
 * @group external-network
 */
#[Group('typesense')]
#[Group('external-network')]
#[RequiresEnv('TYPESENSE_API_KEY')]
class TypesenseSearchableTest extends TestCase
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

        $app['config']->set('scout.typesense.model-settings.'.SearchableUser::class, [
            'collection-schema' => [
                'fields' => [
                    [
                        'name' => 'id',
                        'type' => 'string',
                    ],
                    [
                        'name' => 'name',
                        'type' => 'string',
                    ],
                    [
                        'name' => 'age',
                        'type' => 'int64',
                    ],
                ],
            ],
            'search-parameters' => [
                'query_by' => 'name',
            ],
        ]);
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
            44 => 'Amos Larson Sr.',
            43 => 'Dana Larson Sr.',
            42 => 'Dax Larkin',
            41 => 'Gudrun Larkin',
            40 => 'Otis Larson MD',
            39 => 'Linkwood Larkin',
            20 => 'Prof. Larry Prosacco DVM',
            12 => 'Reta Larkin',
            11 => 'Larry Casper',
            1 => 'Laravel Framework',
        ], $results->pluck('name', 'id')->all());
    }

    public function test_it_can_use_basic_search_with_query_callback()
    {
        $results = $this->itCanUseBasicSearchWithQueryCallback();

        $this->assertSame([
            44 => 'Amos Larson Sr.',
            43 => 'Dana Larson Sr.',
            42 => 'Dax Larkin',
            41 => 'Gudrun Larkin',
            40 => 'Otis Larson MD',
            12 => 'Reta Larkin',
            1 => 'Laravel Framework',
        ], $results->pluck('name', 'id')->all());
    }

    public function test_it_can_use_basic_search_to_fetch_keys()
    {
        $results = $this->itCanUseBasicSearchToFetchKeys();

        $this->assertSame([
            '44',
            '43',
            '42',
            '41',
            '40',
            '39',
            '20',
            '12',
            '11',
            '1',
        ], $results->all());
    }

    public function test_it_can_use_basic_search_with_query_callback_to_fetch_keys()
    {
        $results = $this->itCanUseBasicSearchWithQueryCallbackToFetchKeys();

        $this->assertSame([
            '44',
            '43',
            '42',
            '41',
            '40',
            '39',
            '20',
            '12',
            '11',
            '1',
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
            44 => 'Amos Larson Sr.',
            43 => 'Dana Larson Sr.',
            42 => 'Dax Larkin',
            41 => 'Gudrun Larkin',
            40 => 'Otis Larson MD',
        ], $page1->pluck('name', 'id')->all());

        $this->assertSame([
            39 => 'Linkwood Larkin',
            20 => 'Prof. Larry Prosacco DVM',
            12 => 'Reta Larkin',
            11 => 'Larry Casper',
            1 => 'Laravel Framework',
        ], $page2->pluck('name', 'id')->all());
    }

    public function test_it_can_use_paginated_search_with_query_callback()
    {
        [$page1, $page2] = $this->itCanUsePaginatedSearchWithQueryCallback();

        $this->assertSame([
            44 => 'Amos Larson Sr.',
            43 => 'Dana Larson Sr.',
            42 => 'Dax Larkin',
            41 => 'Gudrun Larkin',
            40 => 'Otis Larson MD',
        ], $page1->pluck('name', 'id')->all());

        $this->assertSame([
            12 => 'Reta Larkin',
            1 => 'Laravel Framework',
        ], $page2->pluck('name', 'id')->all());
    }

    public function test_it_can_usePaginatedSearchWithEmptyQueryCallback()
    {
        $res = $this->itCanUsePaginatedSearchWithEmptyQueryCallback();

        $this->assertSame($res->total(), 44);
        $this->assertSame($res->lastPage(), 3);
    }

    public function test_it_can_use_paginated_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfPaginateUsingAfterRawSearchCallback();

        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('search_time_ms', $rawResults);
    }

    public function test_it_can_use_raw_paginated_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfPaginateRawUsingAfterRawSearchCallback();

        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('search_time_ms', $rawResults);
    }

    public function test_it_can_use_simple_paginated_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfSimplePaginateUsingAfterRawSearchCallback();

        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('search_time_ms', $rawResults);
    }

    public function test_it_can_use_raw_simple_paginated_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfSimplePaginateRawUsingAfterRawSearchCallback();

        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('search_time_ms', $rawResults);
    }

    public function test_it_can_use_raw_get_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfGetUsingAfterRawSearchCallback();

        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('search_time_ms', $rawResults);
    }

    public function test_it_can_use_raw_cursor_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfCursorUsingAfterRawSearchCallback();

        $this->assertIsArray($rawResults);
        $this->assertArrayHasKey('hits', $rawResults);
        $this->assertArrayHasKey('search_time_ms', $rawResults);
    }

    public function test_it_handles_pagination_with_max_int_overflow()
    {
        $maxInt = 4294967295;
        $perPage = 10;
        $overflowPage = 4294967296; // max int + 1
        $expectedPage = floor($maxInt / $perPage);

        $rawSearchResult = null;

        $results = SearchableUser::search('lar')
            ->withRawResults(function ($result) use (&$rawSearchResult) {
                $rawSearchResult = $result;
            })
            ->paginate($perPage, null, $overflowPage);

        // Verify the page was adjusted correctly
        $this->assertEquals($overflowPage, $results->currentPage());
        $this->assertEquals($perPage, $results->perPage());
        $this->assertEquals($expectedPage, $rawSearchResult['page']);
        $this->assertEquals($perPage, $rawSearchResult['request_params']['per_page']);
    }

    public function test_it_can_filter_with_where_comparisons()
    {
        $this->itCanMakeWhereComparisons();
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

            public function toSearchableArray()
            {
                return [
                    'id' => (string) $this->id,
                    'name' => $this->name,
                ];
            }

            public function toSearchableEmbedding()
            {
                return $this->embedding;
            }
        };

        $modelClass = get_class($model);
        $modelClass::$index = config('scout.prefix').'semantic_'.str()->random(12);

        $dimensions = 384;

        config()->set('scout.typesense.model-settings.'.$modelClass, [
            'collection-schema' => [
                'fields' => [
                    ['name' => 'id', 'type' => 'string'],
                    ['name' => 'name', 'type' => 'string'],
                    ['name' => 'embedding', 'type' => 'float[]', 'num_dim' => $dimensions],
                ],
            ],
            'search-parameters' => [
                'query_by' => 'name',
            ],
        ]);

        $engine = new TypesenseEngine(
            new Client(config('scout.typesense.client-settings')),
            1000,
            [
                'model-settings' => [
                    $modelClass => [
                        'embedding' => [
                            'attribute' => 'embedding',
                            'dimensions' => $dimensions,
                        ],
                    ],
                ],
            ]
        );

        try {
            $catVector = array_fill(0, $dimensions, 0.001953125);
            $catVector[0] = 1.0;

            $rocketVector = array_fill(0, $dimensions, 0.001953125);
            $rocketVector[$dimensions - 1] = 1.0;

            $cat = new $modelClass(['id' => 1, 'name' => 'A sleeping cat']);
            $cat->setAttribute('embedding', $catVector);

            $rocket = new $modelClass(['id' => 2, 'name' => 'A rocket launch']);
            $rocket->setAttribute('embedding', $rocketVector);

            $engine->update($model->newCollection([$cat, $rocket]));

            // The serialized query vector exceeds Typesense's 4,000 character
            // query string limit, requiring the multi-search endpoint...
            $this->assertGreaterThan(4000, strlen(implode(', ', $catVector)));

            $results = $engine->search(
                (new Builder($model, 'a relaxed pet'))
                    ->options(['vector' => $catVector])
                    ->semantic()
            );

            $this->assertSame('1', $results['hits'][0]['document']['id']);

            $results = $engine->search(
                (new Builder($model, 'rocket'))
                    ->options(['vector' => $rocketVector])
                    ->hybrid()
            );

            $this->assertSame('2', $results['hits'][0]['document']['id']);
        } finally {
            $engine->deleteIndex($modelClass::$index);
        }
    }

    protected static function scoutDriver(): string
    {
        return 'typesense';
    }
}
