<?php

namespace Tests\Fixtures;

class ElasticsearchEngineTestModel extends TestModel
{
    private $searchableArray;

    public function setSearchableArray($searchable)
    {
        $this->searchableArray = $searchable;
    }

    public function toSearchableArray()
    {
        if ($this->searchableArray) {
            return $this->searchableArray;
        }
        return parent::toSearchableArray();
    }


}
