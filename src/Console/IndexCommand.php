<?php

namespace Laravel\Scout\Console;

use Exception;
use Illuminate\Console\Command;
use Laravel\Scout\EngineManager;

class IndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:index
            {name : The name of the index}
            {--k|key= : The name of the primary key}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an index';

    /**
     * Execute the console command.
     *
     * @param  \Laravel\Scout\EngineManager  $manager
     * @return void
     */
    public function handle(EngineManager $manager)
    {
        $engine = $manager->engine();

        try {
            $options = [];

            if ($this->option('key')) {
                $options = ['primaryKey' => $this->option('key')];
            }

            $engine->createIndex($name = $this->argument('name'), $options);

            if (method_exists($engine, 'updateIndexSettings')) {
                $driver = config('scout.driver');

                if ($settings = config('scout.'.$driver.'.index-settings.'.$name, [])) {
                    $engine->updateIndexSettings($name, $settings);
                }
            }

            $this->info('Index ["'.$this->argument('name').'"] created successfully.');
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
        }
    }
}
