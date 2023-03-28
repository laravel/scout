<?php

namespace Laravel\Scout\Tests\Feature;

use Laravel\Scout\ScoutServiceProvider;
use OpenSearch\Client;
use Orchestra\Testbench\TestCase;

class OpensearchEngineTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [ScoutServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app->make('config')->set('scout.driver', 'opensearch');
    }

    public function test_the_opensearch_client_can_be_initialized()
    {
        $this->assertInstanceOf(Client::class, app(Client::class));
    }
}
