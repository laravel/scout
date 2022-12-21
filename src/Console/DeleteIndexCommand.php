<?php

namespace Laravel\Scout\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
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
            $manager->engine()->deleteIndex($name = $this->indexName($this->argument('name')));

            $this->info('Index "'.$name.'" deleted.');
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
