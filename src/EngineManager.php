<?php

namespace Laravel\Scout;

use Algolia\AlgoliaSearch\Config\SearchConfig;
use Algolia\AlgoliaSearch\SearchClient as Algolia;
use Algolia\AlgoliaSearch\Support\UserAgent;
use Exception;
use Illuminate\Support\Manager;
use Laravel\Scout\Engines\AlgoliaEngine;
use Laravel\Scout\Engines\CollectionEngine;
use Laravel\Scout\Engines\DatabaseEngine;
use Laravel\Scout\Engines\MeiliSearchEngine;
use Laravel\Scout\Engines\NullEngine;
use MeiliSearch\Client as MeiliSearch;

class EngineManager extends Manager
{
    /**
     * Get a driver instance.
     *
     * @param  string|null  $name
     * @return \Laravel\Scout\Engines\Engine
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

        UserAgent::addCustomUserAgent('Laravel Scout', '9.4.10');

        $config = SearchConfig::create(
            config('scout.algolia.id'),
            config('scout.algolia.secret')
        )->setDefaultHeaders(
            $this->defaultAlgoliaHeaders()
        );

        if (is_int($connectTimeout = config('scout.algolia.connect_timeout'))) {
            $config->setConnectTimeout($connectTimeout);
        }

        if (is_int($readTimeout = config('scout.algolia.read_timeout'))) {
            $config->setReadTimeout($readTimeout);
        }

        if (is_int($writeTimeout = config('scout.algolia.write_timeout'))) {
            $config->setWriteTimeout($writeTimeout);
        }

        return new AlgoliaEngine(Algolia::createWithConfig($config), config('scout.soft_delete'));
    }

    /**
     * Ensure the Algolia API client is installed.
     *
     * @return void
     *
     * @throws \Exception
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
     * Set the default Algolia configuration headers.
     *
     * @return array
     */
    protected function defaultAlgoliaHeaders()
    {
        if (! config('scout.identify')) {
            return [];
        }

        $headers = [];

        if (! config('app.debug') &&
            filter_var($ip = request()->ip(), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        ) {
            $headers['X-Forwarded-For'] = $ip;
        }

        if (($user = request()->user()) && method_exists($user, 'getKey')) {
            $headers['X-Algolia-UserToken'] = $user->getKey();
        }

        return $headers;
    }

    /**
     * Create an MeiliSearch engine instance.
     *
     * @return \Laravel\Scout\Engines\MeiliSearchEngine
     */
    public function createMeilisearchDriver()
    {
        $this->ensureMeiliSearchClientIsInstalled();

        return new MeiliSearchEngine(
            $this->container->make(MeiliSearch::class),
            config('scout.soft_delete', false)
        );
    }

    /**
     * Ensure the MeiliSearch client is installed.
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function ensureMeiliSearchClientIsInstalled()
    {
        if (class_exists(MeiliSearch::class)) {
            return;
        }

        throw new Exception('Please install the MeiliSearch client: meilisearch/meilisearch-php.');
    }

    /**
     * Create a database engine instance.
     *
     * @return \Laravel\Scout\Engines\DatabaseEngine
     */
    public function createDatabaseDriver()
    {
        return new DatabaseEngine;
    }

    /**
     * Create a collection engine instance.
     *
     * @return \Laravel\Scout\Engines\CollectionEngine
     */
    public function createCollectionDriver()
    {
        return new CollectionEngine;
    }

    /**
     * Create a null engine instance.
     *
     * @return \Laravel\Scout\Engines\NullEngine
     */
    public function createNullDriver()
    {
        return new NullEngine;
    }

    /**
     * Forget all of the resolved engine instances.
     *
     * @return $this
     */
    public function forgetEngines()
    {
        $this->drivers = [];

        return $this;
    }

    /**
     * Get the default Scout driver name.
     *
     * @return string
     */
    public function getDefaultDriver()
    {
        if (is_null($driver = config('scout.driver'))) {
            return 'null';
        }

        return $driver;
    }
}
