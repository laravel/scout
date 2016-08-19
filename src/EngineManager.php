<?php

namespace Laravel\Scout;

use Illuminate\Support\Manager;
use AlgoliaSearch\Client as Algolia;

class EngineManager extends Manager
{
    /**
     * Get a driver instance.
     *
     * @param  string  $driver
     * @return mixed
     */
    public function engine($name = null)
    {
        return $this->driver($name);
    }

    /**
     * Create an Algolia engine instance.
     *
     * @return Engines\AlgoliaEngine
     */
    public function createAlgoliaDriver()
    {
        $driver = config('scout.drivers.algolia');

        return new Engines\AlgoliaEngine(new Algolia(
            $driver['id'], $driver['secret']
        ));
    }

    /**
     * Create a Null engine instance.
     *
     * @return Engines\NullEngine
     */
    public function createNullDriver()
    {
        return new Engines\NullEngine;
    }

    /**
     * Get the default session driver name.
     *
     * @return string
     */
    public function getDefaultDriver()
    {
        return $this->app['config']['scout.default'];
    }
}
