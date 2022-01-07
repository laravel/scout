<?php

namespace Laravel\Scout\Engines\Database;

use Illuminate\Database\Eloquent\Builder;

class PrimaryKey extends Search
{
    /**
     * Apply the search.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $search
     * @param  string  $connectionType
     * @param  string  $prefix
     * @param  string  $suffix
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Builder $query, $search, string $connectionType, string $prefix = '', string $suffix = '')
    {
        if (in_array($connectionType, ['mysql', 'pgsql'])) {
            $query->orWhereFullText(
                $this->columnName($query), $search
            );
        }

        return $query;
    }
}
