<?php

namespace Laravel\Scout\Tests\Feature\Jobs;

use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Orchestra\Testbench\TestCase;
use Workbench\Database\Factories\ChirpFactory;
use Workbench\Database\Factories\SearchableUserFactory;

class RemovableScoutCollectionTest extends TestCase
{
    public function test_get_queuable_ids()
    {
        $collection = RemoveableScoutCollection::make([
            SearchableUserFactory::new()->make(['id' => 1]),
            SearchableUserFactory::new()->make(['id' => 2]),
        ]);

        $this->assertEquals([1, 2], $collection->getQueueableIds());
    }

    public function test_get_queuable_ids_resolves_custom_scout_keys()
    {
        $collection = RemoveableScoutCollection::make([
            ChirpFactory::new()->make(['scout_id' => 'custom-key.1']),
            ChirpFactory::new()->make(['scout_id' => 'custom-key.2']),
            ChirpFactory::new()->make(['scout_id' => 'custom-key.3']),
            ChirpFactory::new()->make(['scout_id' => 'custom-key.4']),
        ]);

        $this->assertEquals([
            'custom-key.1',
            'custom-key.2',
            'custom-key.3',
            'custom-key.4',
        ], $collection->getQueueableIds());
    }

    public function test_removeable_scout_collection_returns_scout_keys()
    {
        $collection = RemoveableScoutCollection::make([
            ChirpFactory::new()->make(['scout_id' => '1234']),
            ChirpFactory::new()->make(['scout_id' => '2345']),
            SearchableUserFactory::new()->make(['id' => 3456]),
            SearchableUserFactory::new()->make(['id' => 7891]),
        ]);

        $this->assertEquals([
            '1234',
            '2345',
            3456,
            7891,
        ], $collection->getQueueableIds());
    }
}
