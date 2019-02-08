<?php

namespace Laravel\Scout;

use Error;
use Illuminate\Support\Manager;
use Laravel\Scout\Engines\NullEngine;
use Laravel\Scout\Engines\AlgoliaEngine;
use Algolia\AlgoliaSearch\Support\UserAgent;
use Algolia\AlgoliaSearch\SearchClient as Algolia;

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
        if (! class_exists(Algolia::class)) {
            if (class_exists('AlgoliaSearch\Client')) {
                throw new Error('Laravel Scout do not support Algolia API Client v1. Update your
                    `algolia/algoliasearch-client-php` dependency to `^2.2` in your `composer.json` file.');
            }

            throw new Error('Algolia API Client not found. Add the
                `"algolia/algoliasearch-client-php": "^2.2"` dependency to your `composer.json` file.');
        }

        UserAgent::addCustomUserAgent('Laravel Scout', '7.0.0');

        return new AlgoliaEngine(Algolia::create(
            config('scout.algolia.id'), config('scout.algolia.secret')
        ));
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
     * Get the default session driver name.
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
