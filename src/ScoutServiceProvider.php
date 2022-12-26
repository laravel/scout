<?php

namespace Laravel\Scout;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\Console\DeleteAllIndexesCommand;
use Laravel\Scout\Console\DeleteIndexCommand;
use Laravel\Scout\Console\FlushCommand;
use Laravel\Scout\Console\ImportCommand;
use Laravel\Scout\Console\IndexCommand;
use Laravel\Scout\Console\SyncIndexSettingsCommand;
use MeiliSearch\Client as MeiliSearchClient;
use MeiliSearch\MeiliSearch;

class ScoutServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scout.php', 'scout');

        if (class_exists(MeiliSearchClient::class)) {
            $this->app->singleton(MeiliSearchClient::class, function ($app) {
                $config = $app['config']->get('scout.meilisearch');

                if (version_compare(MeiliSearch::VERSION, '0.24.2') >= 0) {
                    return new MeiliSearchClient(
                        $config['host'],
                        $config['key'],
                        null,
                        null,
                        [sprintf('Meilisearch Laravel Scout (v%s)', Scout::VERSION)],
                    );
                }

                return new MeiliSearchClient($config['host'], $config['key']);
            });
        }

        $this->app->singleton(EngineManager::class, function ($app) {
            return new EngineManager($app);
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                FlushCommand::class,
                ImportCommand::class,
                IndexCommand::class,
                SyncIndexSettingsCommand::class,
                DeleteIndexCommand::class,
                DeleteAllIndexesCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/scout.php' => $this->app['path.config'].DIRECTORY_SEPARATOR.'scout.php',
            ]);
        }
    }
}
