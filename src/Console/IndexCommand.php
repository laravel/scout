<?php

namespace Laravel\Scout\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
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

            if (class_exists($modelName = $this->argument('name'))) {
                $model = new $modelName;
            }

            $name = $this->indexName($this->argument('name'));

            $engine->createIndex($name, $options);

            if (method_exists($engine, 'updateIndexSettings')) {
                $driver = config('scout.driver');

                $class = isset($model) ? get_class($model) : null;

                $settings = config('scout.'.$driver.'.index-settings.'.$name)
                                ?? config('scout.'.$driver.'.index-settings.'.$class)
                                ?? [];

                if (isset($model) &&
                    config('scout.soft_delete', false) &&
                    in_array(SoftDeletes::class, class_uses_recursive($model))) {
                    $settings['filterableAttributes'][] = '__soft_deleted';
                }

                if ($settings) {
                    $engine->updateIndexSettings($name, $settings);
                }
            }

            $this->info('Index ["'.$name.'"] created successfully.');
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
        }
    }

    /**
     * Get the fully-qualified index name for the given index.
     *
     * @param  string  $name
     * @return string
     */
    protected function indexName($name)
    {
        if (class_exists($name)) {
            return (new $name)->searchableAs();
        }

        $prefix = config('scout.prefix');

        return ! Str::startsWith($name, $prefix) ? $prefix.$name : $name;
    }
}
