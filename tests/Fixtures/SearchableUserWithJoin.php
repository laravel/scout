<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Workbench\App\Models\SearchableUser;

class SearchableUserWithJoin extends SearchableUser
{
    /**
     * Modify the query used to retrieve models when making all of the models searchable.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function makeAllSearchableUsing(Builder $query)
    {
        return $query->leftJoin('chirps', 'chirps.id', '=', 'users.id')->select('users.*');
    }
}
