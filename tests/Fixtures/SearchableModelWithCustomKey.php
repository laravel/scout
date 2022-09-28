<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class SearchableModelWithCustomKey extends Model
{
    use Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['other_id'];

    public function getScoutKey()
    {
        return $this->other_id;
    }

    public function getScoutKeyName()
    {
        return $this->qualifyColumn('other_id');
    }
}
