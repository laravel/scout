<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ScoutImportAll extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:importAll {--path=\App : The path to the models that are going to be indexed.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import all models described in config/scout.php models array.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $path = $this->option('path');
        $models = config('scout.models');

        foreach ($models as $model) {
            $this->callSilent('scout:import', [
                'model' => $path . '\\' . $model,
            ]);
        }

        $this->info('All models from ' . $path . ' has been imported.');
    }
}
