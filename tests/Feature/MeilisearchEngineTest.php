<?php

namespace Laravel\Scout\Tests\Feature;

use Meilisearch\Client;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;

class MeilisearchEngineTest extends TestCase
{
    use WithWorkbench;

    protected function defineEnvironment($app)
    {
        $app->make('config')->set([
            'scout.driver' => 'meilisearch',
            'scout.meilisearch.host' => 'http://localhost:7700',
        ]);
    }

    public function test_the_meilisearch_client_can_be_initialized()
    {
        $this->assertInstanceOf(Client::class, app(Client::class));
    }
}
