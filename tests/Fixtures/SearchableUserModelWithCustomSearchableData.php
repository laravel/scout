<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Model;
use Laravel\Scout\Searchable;

class SearchableUserModelWithCustomSearchableData extends Model
{
    use Searchable;

    protected $table = 'users';

    public function toSearchableArray()
    {
        $searchable = $this->toArray();

        return array_merge(
            $searchable,
            [
                'reversed_name' => strrev($searchable['name']),
            ]
        );
    }
}
