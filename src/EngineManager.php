<?php

namespace Laravel\Scout;

use Illuminate\Support\Manager;
use AlgoliaSearch\Client as Algolia;
use Laravel\Scout\Engines\NullEngine;
use Laravel\Scout\Engines\AlgoliaEngine;
use Laravel\Scout\Engines\ElasticsearchEngine;
use Elasticsearch\ClientBuilder as Elasticsearch;

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
        return new AlgoliaEngine(new Algolia(
            config('scout.algolia.id'), config('scout.algolia.secret')
        ));
    }

    /**
     * Create an Elasticsearch engine instance.
     *
     * @return \Laravel\Scout\Engines\ElasticsearchEngine
     */
    public function createElasticsearchDriver()
    {
        return new ElasticsearchEngine(
            Elasticsearch::fromConfig(config('scout.elasticsearch.config')),
            config('scout.elasticsearch.index')
        );
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
