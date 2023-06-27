<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Model;
use Laravel\Scout\Searchable;

class SearchableUserDatabaseModel extends Model
{
    use Searchable;

    protected $table = 'users';

    /**
     * Get the indexable data array for the model.
     *
     * @return array
     */
    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
