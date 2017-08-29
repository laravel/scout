<?php

namespace Laravel\Scout;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\Console\FlushAllCommand;
use Laravel\Scout\Console\FlushCommand;
use Laravel\Scout\Console\ImportAllCommand;
use Laravel\Scout\Console\ImportCommand;

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
                ImportAllCommand::class,
                ImportCommand::class,
                FlushAllCommand::class,
                FlushCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/scout.php' => config_path('scout.php'),
            ]);
        }
    }
}
