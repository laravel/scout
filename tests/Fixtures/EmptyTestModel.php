<?php

namespace Tests\Fixtures;

class EmptyTestModel extends TestModel
{
    public function toSearchableArray()
    {
        return [];
    }
}
