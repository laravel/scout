<?php

namespace Laravel\Scout\Tests\Fixtures;

class SoftDeletedEmptySearchableModel extends SearchableModel
{
    public function toSearchableArray()
    {
        return [];
    }

    public function pushSoftDeleteMetadata()
    {
        //
    }

    public function scoutMetadata()
    {
        return ['__soft_deleted' => 1];
    }
}
