<?php

namespace Laravel\Scout;

use Illuminate\Support\Manager;
use AlgoliaSearch\Client as Algolia;
use AlgoliaSearch\Version as AlgoliaUserAgent;
use Laravel\Scout\Engines\NullEngine;
use Laravel\Scout\Engines\AlgoliaEngine;
use AlgoliaSearch\Version as AlgoliaUserAgent;

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
        AlgoliaUserAgent::$custom_value = '; Laravel Scout integration';

        return new AlgoliaEngine(new Algolia(
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
        return $this->app['config']['scout.driver'];
    }
}
