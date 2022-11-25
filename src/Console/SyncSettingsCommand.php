<?php

namespace Laravel\Scout\Console;

use Exception;
use Illuminate\Console\Command;
use Laravel\Scout\EngineManager;

class SyncSettingsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:sync-settings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync the settings of an index';

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

        if (! method_exists($engine, 'updateIndexSettings')) {
            return $this->error('The "'.$driver.'" engine does not support updating index settings.');
        }

        try {
            $indexes = (array) config('scout.'.$driver.'.settings', []);

            if (count($indexes)) {
                foreach ($indexes as $name => $settings) {
                    $engine->updateIndexSettings($name, $settings);

                    $this->info('Index settings for the ["'.$name.'"] index synced successfully.');
                }
            } else {
                $this->info('No index settings found for the "'.$driver.'" engine.');
            }
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
        }
    }
}
