<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Model;
use Laravel\Scout\Searchable;

class SearchableUserModelWithCustomSearchableData extends Model
{
    use Searchable;

    protected $table = 'users';

    public function toSearchableArray(): array
    {
        return [
            'reversed_name' => strrev($this->name),
        ];
    }
}
