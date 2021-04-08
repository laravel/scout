<?php

namespace Laravel\Scout\Console;

use Exception;
use Illuminate\Console\Command;
use Laravel\Scout\EngineManager;

class DeleteIndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:delete-index {name : The name of the index}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete an index';

    /**
     * Execute the console command.
     *
     * @param  \Laravel\Scout\EngineManager  $manager
     * @return void
     */
    public function handle(EngineManager $manager)
    {
        try {
            $manager->engine()->deleteIndex($this->argument('name'));

            $this->info('Index "'.$this->argument('name').'" deleted.');
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
        }
    }
}
