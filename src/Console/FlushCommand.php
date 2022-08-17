<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;

class FlushCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:flush {model}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Flush all of the model's records from the index";

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $class = $this->lookupClass();

        if(!$class) {
            $this->error('Class ['.$this->argument('model').'] could not be found.');
            return self::FAILURE;
        }

        $model = new $class;

        $model::removeAllFromSearch();

        $this->info('All ['.$class.'] records have been flushed.');
    }

    protected function lookupClass()
    {
        $class = $this->argument('model');
        if(!class_exists($class)) {
            $rootNamespace = $this->laravel->getNamespace();
            $class = is_dir(app_path('Models'))
                ? $rootNamespace.'Models\\'.$class
                : $rootNamespace.$class;
        }

        if(!class_exists($class)) {
            return false;
        }

        return $class;
    }


}
