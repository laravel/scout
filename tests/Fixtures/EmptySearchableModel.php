<?php

namespace Laravel\Scout\Tests\Fixtures;

class EmptySearchableModel extends SearchableModel
{
    public function toSearchableArray()
    {
        return [];
    }
}
