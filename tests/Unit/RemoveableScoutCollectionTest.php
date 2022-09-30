<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Config;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class RemoveableScoutCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        Config::shouldReceive('get')->with('scout.after_commit', m::any())->andReturn(false);
        Config::shouldReceive('get')->with('scout.soft_delete', m::any())->andReturn(false);
    }

    public function test_get_queuable_ids()
    {
        $collection = RemoveableScoutCollection::make([
            new SearchableModel(['id' => 1]),
            new SearchableModel(['id' => 2]),
        ]);

        $this->assertEquals([1, 2], $collection->getQueueableIds());
    }

    public function test_get_queuable_ids_resolves_custom_scout_keys()
    {
        $collection = RemoveableScoutCollection::make([
            new SearchCustomKeySearchableModel(['id' => 1]),
            new SearchCustomKeySearchableModel(['id' => 2]),
            new SearchCustomKeySearchableModel(['id' => 3]),
            new SearchCustomKeySearchableModel(['id' => 4]),
        ]);

        $this->assertEquals([
            'custom-key.1',
            'custom-key.2',
            'custom-key.3',
            'custom-key.4',
        ], $collection->getQueueableIds());
    }
}

class SearchCustomKeySearchableModel extends SearchableModel
{
    public function getScoutKey()
    {
        return 'custom-key.'.$this->getKey();
    }
}
