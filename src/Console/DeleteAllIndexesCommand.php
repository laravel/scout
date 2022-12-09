<?php

namespace Laravel\Scout\Console;

use Exception;
use Illuminate\Console\Command;
use Laravel\Scout\EngineManager;

class DeleteAllIndexesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:delete-all-indexes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all indexes';

    /**
     * Execute the console command.
     *
     * @param  \Laravel\Scout\EngineManager  $manager
     * @return void
     */
    public function handle(EngineManager $manager)
    {
        $engine = $manager->engine();

        $driver = config('scout.driver');

        if (! method_exists($engine, 'deleteAllIndexes')) {
            return $this->error('The "'.$driver.'" engine does not support delete all indexes.');
        }

        try {
            $manager->engine()->deleteAllIndexes();

            $this->info('All indexes have been deleted.');
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
        }
    }
}
