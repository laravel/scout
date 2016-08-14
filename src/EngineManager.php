<?php

namespace Laravel\Scout;

use AlgoliaSearch\Client as Algolia;
use Illuminate\Support\Manager;
use TeamTNT\TNTSearch\TNTSearch;

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
        return new Engines\AlgoliaEngine(new Algolia(
            config('scout.algolia.id'), config('scout.algolia.secret')
        ));
    }

    /**
     * Create a TNTSearch engine instance.
     *
     * @return Engines\TNTSearchEngine
     */
    public function createTNTSearchDriver()
    {
        return new Engines\TNTSearchEngine(new TNTSearch);
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
        return $this->app['config']['scout.driver'];
    }
}
