<?php

namespace Laravel\Scout\Tests\Fixtures;

use Laravel\Scout\Searchable;
use Illuminate\Database\Eloquent\Model;

class SearchableModel extends Model
{
    use Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['id'];

    public function searchableAs()
    {
        return 'table';
    }

    public function scoutMetadata()
    {
        return [];
    }
}
