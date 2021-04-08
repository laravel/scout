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
            {--d|delete : Delete an existing index}
            {--k|key= : The name of primary key}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or delete an index';

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
            if ($this->option('delete')) {
                $engine->deleteIndex($this->argument('name'));

                $this->info('Index "'.$this->argument('name').'" deleted.');

                return;
            }

            $options = [];

            if ($this->option('key')) {
                $options = ['primaryKey' => $this->option('key')];
            }

            $engine->createIndex($this->argument('name'), $options);

            $this->info('Index "'.$this->argument('name').'" created.');
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
        }
    }
}
