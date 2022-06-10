<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class SearchableModelWithCustomKeyForCollectionEngineTest extends Model
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
        return 'other_id';
    }

    public function toSearchableArray()
    {
        return [
            $this->getScoutKeyName() => $this->getScoutKey(),
            'test' => 'test',
        ];
    }
}
