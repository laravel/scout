<?php

namespace Laravel\Scout\Tests\Feature\Engines;

use Elastic\Elasticsearch\Client;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\ElasticsearchEngine;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;

class ElasticsearchEngineTest extends TestCase
{
    use WithWorkbench;

    protected function defineEnvironment($app)
    {
        $app['config']->set('scout.driver', 'elasticsearch');
        $app['config']->set('scout.elasticsearch.hosts', ['http://localhost:9200']);
    }

    public function test_the_elasticsearch_client_can_be_initialized()
    {
        $this->assertInstanceOf(Client::class, app(Client::class));
    }

    public function test_driver_is_registered()
    {
        $this->assertInstanceOf(
            ElasticsearchEngine::class,
            $this->app->make(EngineManager::class)->engine()
        );
    }
}
