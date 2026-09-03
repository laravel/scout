<?php

namespace Laravel\Scout\Tests\Integration;

use Elastic\Elasticsearch\Client;
use Orchestra\Testbench\Attributes\RequiresEnv;
use PHPUnit\Framework\Attributes\Group;
use Workbench\App\Models\SearchableUser;

/**
 * @group elasticsearch
 * @group external-network
 */
#[Group('elasticsearch')]
#[Group('external-network')]
#[RequiresEnv('ELASTICSEARCH_HOST')]
class ElasticsearchSearchableTest extends TestCase
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

        $app['config']->set('scout.elasticsearch.hosts', [env('ELASTICSEARCH_HOST', 'http://localhost:9200')]);
    }

    /**
     * Define database migrations.
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
            20 => 'Prof. Larry Prosacco DVM',
            39 => 'Linkwood Larkin',
            40 => 'Otis Larson MD',
            41 => 'Gudrun Larkin',
            42 => 'Dax Larkin',
            43 => 'Dana Larson Sr.',
            44 => 'Amos Larson Sr.',
        ], $results->pluck('name', 'id')->all());
    }

    public function test_it_can_use_basic_search_with_query_callback()
    {
        $results = $this->itCanUseBasicSearchWithQueryCallback();

        $this->assertEqualsCanonicalizing([
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

        $this->assertEqualsCanonicalizing([
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

        $this->assertEqualsCanonicalizing([
            1,
            11,
            12,
            20,
            39,
            40,
            41,
            42,
            43,
            44,
        ], $results->all());
    }

    public function test_it_return_same_keys_with_query_callback()
    {
        $this->assertEqualsCanonicalizing(
            $this->itCanUseBasicSearchToFetchKeys()->all(),
            $this->itCanUseBasicSearchWithQueryCallbackToFetchKeys()->all()
        );
    }

    public function test_it_can_use_paginated_search()
    {
        [$page1, $page2] = $this->itCanUsePaginatedSearch();

        $this->assertSame(10, $page1->total());
        $this->assertCount(5, $page1);
        $this->assertCount(5, $page2);

        $this->assertEqualsCanonicalizing([
            'Laravel Framework',
            'Larry Casper',
            'Reta Larkin',
            'Prof. Larry Prosacco DVM',
            'Linkwood Larkin',
        ], $page1->pluck('name')->all());

        $this->assertEqualsCanonicalizing([
            'Otis Larson MD',
            'Gudrun Larkin',
            'Dax Larkin',
            'Dana Larson Sr.',
            'Amos Larson Sr.',
        ], $page2->pluck('name')->all());
    }

    public function test_it_can_use_paginated_search_with_query_callback()
    {
        [$page1, $page2] = $this->itCanUsePaginatedSearchWithQueryCallback();

        $this->assertSame(7, $page1->total());

        $this->assertEqualsCanonicalizing([
            'Laravel Framework',
            'Reta Larkin',
        ], $page1->pluck('name')->all());

        $this->assertEqualsCanonicalizing([
            'Otis Larson MD',
            'Gudrun Larkin',
            'Dax Larkin',
            'Dana Larson Sr.',
            'Amos Larson Sr.',
        ], $page2->pluck('name')->all());
    }

    public function test_it_can_use_paginated_search_with_empty_query_callback()
    {
        $results = $this->itCanUsePaginatedSearchWithEmptyQueryCallback();

        $this->assertSame(44, $results->total());
    }

    public function test_it_can_use_raw_paginated_search_with_after_raw_search_callback()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfPaginateUsingAfterRawSearchCallback();

        $this->assertInstanceOf(\Elastic\Elasticsearch\Response\Elasticsearch::class, $rawResults);
        $this->assertSame(44, $rawResults['hits']['total']['value']);
    }

    public function test_it_can_access_raw_search_results_of_paginate_raw()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfPaginateRawUsingAfterRawSearchCallback();

        $this->assertSame(44, $rawResults['hits']['total']['value']);
    }

    public function test_it_can_access_raw_search_results_of_simple_paginate()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfSimplePaginateUsingAfterRawSearchCallback();

        $this->assertSame(44, $rawResults['hits']['total']['value']);
    }

    public function test_it_can_access_raw_search_results_of_get()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfGetUsingAfterRawSearchCallback();

        $this->assertSame(44, $rawResults['hits']['total']['value']);
    }

    public function test_it_can_access_raw_search_results_of_cursor()
    {
        $rawResults = $this->itCanAccessRawSearchResultsOfCursorUsingAfterRawSearchCallback();

        $this->assertSame(44, $rawResults['hits']['total']['value']);
    }

    public function test_it_can_filter_with_where_comparisons()
    {
        $this->itCanMakeWhereComparisons();
    }

    protected function importScoutIndexFrom($model = null)
    {
        parent::importScoutIndexFrom($model);

        $this->app->make(Client::class)->indices()->refresh(['index' => '*']);
    }

    protected static function scoutDriver(): string
    {
        return 'elasticsearch';
    }
}
