<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;
use Mockery as m;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('scout.spied', function () {
            return m::spy(NullEngine::class);
        });

        $this->callAfterResolving(EngineManager::class, function ($engine) {
            $engine->extend('testing', function ($app) {
                return $app->make('scout.spied');
            });
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
