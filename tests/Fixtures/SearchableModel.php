<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Laravel\Scout\Searchable;
use Mockery as m;

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

    public function newQueryWithoutRelationships()
    {
        $mock = m::mock(Builder::class);
        $mock->shouldReceive('tap')->andReturnSelf();
        $mock->shouldReceive('eagerLoadRelations')->andReturnArg(0);

        return $mock;
    }
}
