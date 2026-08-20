<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class SearchableModelWithNativeEmbedding extends Model
{
    use Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['id', 'name', 'embedding'];

    public function searchableAs()
    {
        return 'table';
    }

    public function indexableAs()
    {
        return 'table';
    }
}
