<?php

namespace Laravel\Scout\Engines\Database;

use Illuminate\Database\Eloquent\Builder;

class PrimaryKey extends Search
{
    /**
     * Apply the search.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string|int  $search
     * @param  string  $connectionType
     * @param  string  $prefix
     * @param  string  $suffix
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Builder $query, $search, string $connectionType, string $prefix = '', string $suffix = '')
    {
        $model = $query->getModel();

        $canSearchPrimaryKey = ctype_digit($search) &&
                               in_array($model->getKeyType(), ['int', 'integer']) &&
                               ($connectionType != 'pgsql' || $search <= PHP_INT_MAX);

        if (! $canSearchPrimaryKey) {
            return parent::apply($query, $search, $connectionType, $prefix, $suffix);
        }

        return $query->orWhere($model->getQualifiedKeyName(), $search);
    }
}
