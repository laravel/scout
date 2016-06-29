<?php

namespace Laravel\Scout;

use Illuminate\Support\ServiceProvider;

class ScoutServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(EngineManager::class, function ($app) {
            return new EngineManager($app);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\ImportCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/scout.php' => config_path('scout.php'),
            ]);
        }
    }
}
