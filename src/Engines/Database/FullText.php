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
     * @param  string  $whereOperator
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Builder $query, $search, string $connectionType, string $prefix = '', string $suffix = '', string $whereOperator = 'orWhere')
    {
        if (in_array($connectionType, ['mysql', 'pgsql'])) {
            $query->{$whereOperator.'FullText'}(
                $this->columnName($query), $search
            );
        }

        return $query;
    }
}
