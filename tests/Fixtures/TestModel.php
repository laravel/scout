<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class TestModel extends Model
{
    public $id = 1;

    public function searchableAs()
    {
        return 'table';
    }

    public function getKey()
    {
        return $this->id;
    }

    /**
     * The default key of Models with Searchable-Trait
     * @return mixed
     */
    public function getScoutKey()
    {
        return $this->getKey();
    }

    public function toSearchableArray()
    {
        return ['id' => 1];
    }

    public function scoutMetadata()
    {
        return [];
    }
}
