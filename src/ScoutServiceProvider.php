<?php

namespace Laravel\Scout;

use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Support\ServiceProvider;
use Laravel\Lumen\Application as LumenApplication;
use Laravel\Scout\Console\FlushCommand;
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
                ImportCommand::class,
                FlushCommand::class,
            ]);
        }

        $config = realpath(__DIR__ . '/../config/scout.php');

        if ($this->app instanceof LaravelApplication) {
            $this->publishes([$config => config_path('scout.php')]);
        } elseif ($this->app instanceof LumenApplication) {
            $this->app->configure('scout');
        }

        $this->mergeConfigFrom($config, 'scout');
    }
}
