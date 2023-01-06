<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\GeneratorCommand;

class CreateCommand extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:create {name : The name of the custom engine}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new scout custom engine class';

    protected function getStub()
    {
        return __DIR__ . '/stubs/create.stub';
    }

    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace . '\Scout';
    }
}
