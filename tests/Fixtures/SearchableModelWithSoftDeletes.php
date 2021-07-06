<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class SearchableModelWithSoftDeletes extends Model
{
    use SoftDeletes;
    use Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['published_at'];

    public function shouldBeSearchable()
    {
        return ! $this->trashed() && ! is_null($this->published_at);
    }
}
