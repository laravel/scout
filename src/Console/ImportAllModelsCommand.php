<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Laravel\Scout\Searchable;
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
        $configModels = config('scout.models') ?? [];

        // This flag is deteermines if we are auto discovering models.
        // It will be set to true if the config('scout.models') is empty.
        $autoDiscoveringModels = empty($configModels);

        if ($autoDiscoveringModels) {
            $models = $this->getSearchableModels();
        } else {
            $this->validateModelsAreSearchable($configModels);
            $models = $configModels;
        }

        if (empty($models)) {
            $this->error('No searchable models found.');
            return;
        }

        // Flush all models first
        foreach ($models as $model) {
            $this->call('scout:flush', ['model' => $model]);
        }
        $this->line('');

        // Import all models
        foreach ($models as $model) {
            $this->call('scout:import', ['model' => $model]);
        }

        $this->line('');
        $this->info('All models have been imported successfully.');
    }

    /**
     * Get models from config.
     *
     * @return array
     */
    protected function validateModelsAreSearchable(array $models): void
    {
        foreach ($models as $model) {
            if (!in_array(Searchable::class, class_uses_recursive($model))) {
                $this->error('Model ' . $model . ' is not searchable.');
                exit(1);
            }
        }
    }

    /**
     * Get all models that use the Searchable trait.
     *
     * @return array
     */
    protected function getSearchableModels(): array
    {
        $searchableModels = [];
        $modelPath = app_path('Models');

        // Fallback to app/ for older Laravel versions
        if (!is_dir($modelPath)) {
            $modelPath = app_path();
        }

        foreach (glob($modelPath . '/*.php') as $file) {
            $class = $this->getClassFromFile($file);

            if (
                $class && class_exists($class) &&
                in_array(Searchable::class, class_uses_recursive($class))
            ) {
                $searchableModels[] = $class;
            }
        }

        return $searchableModels;
    }

    /**
     * Get class name from file.
     *
     * @param string $file
     * @return string|null
     */
    protected function getClassFromFile(string $file): ?string
    {
        $class = basename($file, '.php');
        $fullClass = 'App\\Models\\' . $class;

        return class_exists($fullClass) ? $fullClass : null;
    }
}
