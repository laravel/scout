<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use Laravel\Scout\Events\ModelsFlushed;
use Illuminate\Contracts\Events\Dispatcher;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Laravel Scout';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->info('Setting Laravel Scout version...');

        $version = collect(
            json_decode(file_get_contents(realpath(__DIR__ . '/../../../../composer/installed.json')))
        )->where('name', 'laravel/scout')->first()->version;

        $manager = realpath(__DIR__ . '/../EngineManager.php');

        $content = str_replace(
            'const VERSION = null;',
            "const VERSION = '{$version}';",
            file_get_contents($manager)
        );

        file_put_contents($manager, $content);
    }
}
