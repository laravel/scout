<?php

namespace Laravel\Scout\Tests\Integration;

use Orchestra\Testbench\Concerns\WithWorkbench;

use function Orchestra\Testbench\artisan;
use function Orchestra\Testbench\remote;

abstract class TestCase extends \Orchestra\Testbench\TestCase
{
    use WithWorkbench;

    protected function importScoutIndexFrom($model = null)
    {
        if (class_exists($model)) {
            artisan($this, 'scout:index', ['name' => $model]);
        }

        artisan($this, 'scout:import', ['model' => $model]);

        sleep(1);
    }

    /**
     * Clean up the testing environment before the next test case.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        remote('scout:delete-all-indexes', [
            'SCOUT_DRIVER' => static::scoutDriver(),
        ])->mustRun();

        parent::tearDownAfterClass();
    }

    abstract protected static function scoutDriver(): string;
}
