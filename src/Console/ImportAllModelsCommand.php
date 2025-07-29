<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'scout:import-all')]
class ImportAllModelsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:import-all';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import all models to scout index';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // $this->info('Importing all models to scout index...');


        $models = config('scout.models') ?? [];

        if (empty($models)) {
            $this->error('No models found in config/scout.php');

            return;
        }

        // $this->info('Found ' . count($models) . ' models to import');

        foreach ($models as $model) {
            // $this->info("Flushing $model...");
            $this->call('scout:flush', ['model' => $model]);
        }
        $this->line('');

        foreach ($models as $model) {
            // $this->info("Syncing $model...");
            $this->call('scout:import', ['model' => $model]);
        }

        $this->line('');


        $this->info('Scout index synced');
    }
}
