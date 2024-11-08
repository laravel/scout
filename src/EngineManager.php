<?php

namespace Laravel\Scout;

use Algolia\AlgoliaSearch\Algolia;
use Algolia\AlgoliaSearch\Support\AlgoliaAgent as Algolia4UserAgent;
use Algolia\AlgoliaSearch\Support\UserAgent as Algolia3UserAgent;
use Exception;
use Illuminate\Support\Manager;
use Laravel\Scout\Engines\Algolia3Engine;
use Laravel\Scout\Engines\Algolia4Engine;
use Laravel\Scout\Engines\CollectionEngine;
use Laravel\Scout\Engines\DatabaseEngine;
use Laravel\Scout\Engines\MeilisearchEngine;
use Laravel\Scout\Engines\NullEngine;
use Laravel\Scout\Engines\TypesenseEngine;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Meilisearch;
use Typesense\Client as Typesense;

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

        return version_compare(Algolia::VERSION, '4.0.0', '>=')
            ? $this->configureAlgolia4Driver()
            : $this->configureAlgolia3Driver();
    }

    /**
     * Create an Algolia v3 engine instance.
     *
     * @return \Laravel\Scout\Engines\Algolia3Engine
     */
    protected function configureAlgolia3Driver()
    {
        Algolia3UserAgent::addCustomUserAgent('Laravel Scout', Scout::VERSION); // @phpstan-ignore class.notFound

        return Algolia3Engine::make(
            config: config('scout.algolia'),
            headers: $this->defaultAlgoliaHeaders(),
            softDelete: config('scout.soft_delete')
        );
    }

    /**
     * Create an Algolia v4 engine instance.
     *
     * @return \Laravel\Scout\Engines\Algolia4Engine
     */
    protected function configureAlgolia4Driver()
    {
        Algolia4UserAgent::addAlgoliaAgent('Laravel Scout', 'Laravel Scout', Scout::VERSION);

        return Algolia4Engine::make(
            config: config('scout.algolia'),
            headers: $this->defaultAlgoliaHeaders(),
            softDelete: config('scout.soft_delete')
        );
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

        throw new Exception('Please install the suggested Algolia client: algolia/algoliasearch-client-php.');
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
     * Create a Meilisearch engine instance.
     *
     * @return \Laravel\Scout\Engines\MeilisearchEngine
     */
    public function createMeilisearchDriver()
    {
        $this->ensureMeilisearchClientIsInstalled();

        return new MeilisearchEngine(
            $this->container->make(MeilisearchClient::class),
            config('scout.soft_delete', false)
        );
    }

    /**
     * Ensure the Meilisearch client is installed.
     *
     * @return void
     *
     * @throws \Exception
     */
    protected function ensureMeilisearchClientIsInstalled()
    {
        if (class_exists(Meilisearch::class) && version_compare(Meilisearch::VERSION, '1.0.0') >= 0) {
            return;
        }

        throw new Exception('Please install the suggested Meilisearch client: meilisearch/meilisearch-php.');
    }

    /**
     * Create a Typesense engine instance.
     *
     * @return \Laravel\Scout\Engines\TypesenseEngine
     *
     * @throws \Typesense\Exceptions\ConfigError
     */
    public function createTypesenseDriver()
    {
        $config = config('scout.typesense');
        $this->ensureTypesenseClientIsInstalled();

        return new TypesenseEngine(new Typesense($config['client-settings']), $config['max_total_results'] ?? 1000);
    }

    /**
     * Ensure the Typesense client is installed.
     *
     * @return void
     *
     * @throws Exception
     */
    protected function ensureTypesenseClientIsInstalled()
    {
        if (! class_exists(Typesense::class)) {
            throw new Exception('Please install the suggested Typesense client: typesense/typesense-php.');
        }
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
