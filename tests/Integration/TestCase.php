<?php

namespace Laravel\Scout\Tests\Integration;

use Laravel\Scout\ScoutServiceProvider;
use function Orchestra\Testbench\artisan;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function importScoutIndexFrom($model = null)
    {
        rescue(function () use ($model) {
            artisan($this, 'scout:import', ['model' => $model]);
        });
    }

    protected function resetScoutIndexes()
    {
        rescue(function () {
            artisan($this, 'scout:delete-all-indexes');
        });
    }

    /**
     * Get package providers.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string<\Illuminate\Support\ServiceProvider>>
     */
    protected function getPackageProviders($app)
    {
        return [ScoutServiceProvider::class];
    }
}
