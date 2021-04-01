<?php

namespace Laravel\Scout;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\Console\FlushCommand;
use Laravel\Scout\Console\ImportCommand;
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

        $this->app->singleton(EngineManager::class, function ($app) {
            return new EngineManager($app);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportCommand::class,
                FlushCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/scout.php' => $this->app['path.config'].DIRECTORY_SEPARATOR.'scout.php',
            ]);
        }

        if (class_exists(MeiliSearch::class)) {
            $this->app->singleton(MeiliSearch::class, function () {
                return new MeiliSearch(config('meilisearch.host'), config('meilisearch.key'));
            });
        }
    }
}
