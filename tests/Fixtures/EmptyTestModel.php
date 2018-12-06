<?php

namespace Laravel\Scout\Tests\Fixtures;

class EmptyTestModel extends TestModel
{
    public function toSearchableArray()
    {
        return [];
    }
}
