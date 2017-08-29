<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ScoutFlushAll extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:flushAll {--path=\App : The path to the models that are going to be flushed from index.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush all models described in config/scout.php models array.';

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
            $this->callSilent('scout:flush', [
                'model' => $path . '\\' . $model,
            ]);
        }

        $this->info('All models from ' . $path . ' has been flushed from the index.');
    }
}
