<?php

namespace Laravel\Scout\Tests\Integration;

class CollectionSearchableTest extends DatabaseSearchableTest
{
    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineEnvironment($app)
    {
        $app['config']->set('scout.driver', 'collection');
    }

    public function test_it_can_use_basic_search_with_query_callback_to_fetch_keys()
    {
        $results = $this->itCanUseBasicSearchWithQueryCallbackToFetchKeys();

        $this->assertNotSame([
            44,
            43,
            42,
            41,
            40,
            12,
            1,
        ], $results->all());

        $this->assertSame($this->itCanUseBasicSearchToFetchKeys()->all(), $results->all());
    }
}
