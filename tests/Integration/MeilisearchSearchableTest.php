<?php

namespace Laravel\Scout\Tests\Integration;

use Illuminate\Support\Env;
use Laravel\Scout\Tests\Fixtures\User;

/**
 * @group meilisearch
 * @group external-network
 */
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
        if (is_null(Env::get('MEILISEARCH_HOST'))) {
            $this->markTestSkipped();

            return;
        }

        $this->defineScoutEnvironment($app);
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

        $this->importScoutIndexFrom(User::class);
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

    protected static function scoutDriver(): string
    {
        return 'meilisearch';
    }
}
