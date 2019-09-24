<?php

namespace Laravel\Scout;

use Algolia\AlgoliaSearch\SearchClient as Algolia;
use Algolia\AlgoliaSearch\Support\UserAgent;
use Exception;
use Illuminate\Support\Manager;
use Laravel\Scout\Engines\AlgoliaEngine;
use Laravel\Scout\Engines\NullEngine;

class EngineManager extends Manager
{
    /**
     * Get a driver instance.
     *
     * @param  string|null  $name
     * @return mixed
     */
    public function engine($name = null)
    {
        return $this->driver($name);
    }

    /**
     * Create an Algolia engine instance.
     *
     * @return \Laravel\Scout\Engines\AlgoliaEngine
     */
    public function createAlgoliaDriver()
    {
        $this->ensureAlgoliaClientIsInstalled();

        UserAgent::addCustomUserAgent('Laravel Scout', '7.2.1');

        return new AlgoliaEngine(
            Algolia::create(config('scout.algolia.id'), config('scout.algolia.secret')),
            config('scout.soft_delete')
        );
    }

    /**
     * Ensure the Algolia API client is installed.
     *
     * @return void
     */
    protected function ensureAlgoliaClientIsInstalled()
    {
        if (class_exists(Algolia::class)) {
            return;
        }

        if (class_exists('AlgoliaSearch\Client')) {
            throw new Exception('Please upgrade your Algolia client to version: ^2.2.');
        }

        throw new Exception('Please install the Algolia client: algolia/algoliasearch-client-php.');
    }

    /**
     * Create a Null engine instance.
     *
     * @return \Laravel\Scout\Engines\NullEngine
     */
    public function createNullDriver()
    {
        return new NullEngine;
    }

    /**
     * Get the default Scout driver name.
     *
     * @return string
     */
    public function getDefaultDriver()
    {
        if (is_null($this->app['config']['scout.driver'])) {
            return 'null';
        }

        return $this->app['config']['scout.driver'];
    }
}
