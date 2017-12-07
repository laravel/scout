<?php

namespace Tests\Fixtures;

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

    public function toSearchableArray()
    {
        return ['id' => 1];
    }

    public function scoutMetadata()
    {
        return [];
    }
}
