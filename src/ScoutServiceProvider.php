<?php

namespace Laravel\Scout;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\Console\DeleteIndexCommand;
use Laravel\Scout\Console\FlushCommand;
use Laravel\Scout\Console\ImportCommand;
use Laravel\Scout\Console\IndexCommand;
use MeiliSearch\Client as MeiliSearch;

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

        if (class_exists(MeiliSearch::class)) {
            $this->app->singleton(MeiliSearch::class, function ($app) {
                $config = $app['config']->get('scout.meilisearch');

                return new MeiliSearch($config['host'], $config['key']);
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
                DeleteIndexCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/scout.php' => $this->app['path.config'].DIRECTORY_SEPARATOR.'scout.php',
            ]);
        }
    }
}
