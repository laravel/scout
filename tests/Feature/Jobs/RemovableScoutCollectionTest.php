<?php

namespace Laravel\Scout\Tests\Feature\Jobs;

use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Orchestra\Testbench\TestCase;
use Workbench\Database\Factories\ChirpFactory;
use Workbench\Database\Factories\UserFactory;

class RemovableScoutCollectionTest extends TestCase
{
    public function test_removeable_scout_collection_returns_scout_keys()
    {
        $collection = RemoveableScoutCollection::make([
            ChirpFactory::new()->make(['scout_id' => '1234']),
            ChirpFactory::new()->make(['scout_id' => '2345']),
            UserFactory::new()->make(['id' => 3456]),
            UserFactory::new()->make(['id' => 7891]),
        ]);

        $this->assertEquals([
            '1234',
            '2345',
            3456,
            7891,
        ], $collection->getQueueableIds());
    }
}
