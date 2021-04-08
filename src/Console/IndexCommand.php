<?php

namespace Laravel\Scout\Console;

use Illuminate\Console\Command;
use MeiliSearch\Client;
use MeiliSearch\Exceptions\ApiException;

class IndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scout:index
            {--d|delete : Delete an existing index}
            {--k|key= : The name of primary key}
            {name : The name of the index}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create or delete an index';

    /**
     * Execute the console command.
     *
     * @param  \MeiliSearch\Client  $client
     * @return void
     */
    public function handle(Client $client)
    {
        try {
            if ($this->option('delete')) {
                $client->deleteIndex($this->argument('name'));

                $this->info('Index "'.$this->argument('name').'" deleted.');

                return;
            }

            $options = [];

            if ($this->option('key')) {
                $options = ['primaryKey' => $this->option('key')];
            }

            $client->createIndex($this->argument('name'), $options);

            $this->info('Index "'.$this->argument('name').'" created.');
        } catch (ApiException $exception) {
            $this->error($exception->getMessage());
        }
    }
}
