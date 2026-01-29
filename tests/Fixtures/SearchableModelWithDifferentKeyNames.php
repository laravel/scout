<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class SearchableModelWithDifferentKeyNames extends Model
{
    use Searchable;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['uuid', 'name'];

    public function searchableAs()
    {
        return 'table';
    }

    public function getScoutKeyName()
    {
        return 'id';
    }

    public function getScoutKey()
    {
        return $this->uuid;
    }
}
