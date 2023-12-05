<?php

namespace Laravel\Scout\Tests\Integration;

use Illuminate\Support\ProcessUtil;
use Orchestra\Testbench\Concerns\WithWorkbench;

use function Orchestra\Testbench\remote;

class TestCase extends \Orchestra\Testbench\TestCase
{
    use WithWorkbench;

    protected function importScoutIndexFrom($model = null)
    {
        rescue(fn () => remote(sprintf('scout:import --model=%s', ProcessUtil::escapeArgument($model)))->mustRun());
    }

    protected function resetScoutIndexes()
    {
        rescue(fn () => remote('scout:delete-all-indexes')->mustRun());
    }
}
