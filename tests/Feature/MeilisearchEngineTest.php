<?php

namespace Laravel\Scout\Tests\Feature;

use Laravel\Scout\ScoutServiceProvider;
use Meilisearch\Client;
use Orchestra\Testbench\TestCase;

class MeilisearchEngineTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ScoutServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app->make('config')->set('scout.driver', 'meilisearch');
    }

    public function test_the_meilisearch_client_can_be_initialized()
    {
        $this->assertInstanceOf(Client::class, app(Client::class));
    }
}
