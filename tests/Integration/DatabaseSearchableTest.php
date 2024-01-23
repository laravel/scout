<?php

namespace Laravel\Scout\Tests\Integration;

class DatabaseSearchableTest extends TestCase
{
    use SearchableTests;

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        $this->defineScoutEnvironment($app);
    }

    /**
     * Define database migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->defineScoutDatabaseMigrations();
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
            44,
            43,
            42,
            41,
            40,
            39,
            20,
            12,
            11,
            1,
        ], $results->all());
    }

    public function test_it_can_use_basic_search_with_query_callback_to_fetch_keys()
    {
        $results = $this->itCanUseBasicSearchWithQueryCallbackToFetchKeys();

        $this->assertSame([
            44,
            43,
            42,
            41,
            40,
            12,
            1,
        ], $results->all());
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

    protected static function scoutDriver(): string
    {
        return 'database';
    }
}
