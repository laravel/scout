<?php

namespace Laravel\Scout\Engines\Database;

use Illuminate\Database\Eloquent\Builder;

class PrimaryKey extends Search
{
    /**
     * Max primary key size.
     *
     * @var int
     */
    protected $maxPrimaryKeySize;

    /**
     * Construct a new search.
     *
     * @param  \Illuminate\Database\Query\Expression|string  $column
     * @param  int  $maxPrimaryKeySize
     * @return void
     */
    public function __construct($column, $maxPrimaryKeySize = PHP_INT_MAX)
    {
        $this->column = $column;
        $this->maxPrimaryKeySize = $maxPrimaryKeySize;
    }

    /**
     * Apply the search.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string|int  $search
     * @param  string  $connectionType
     * @param  string  $whereOperator
     * @param  string  $prefix
     * @param  string  $suffix
     * @param  string  $whereOperator
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Builder $query, $search, string $connectionType, string $prefix = '', string $suffix = '', string $whereOperator = 'orWhere')
    {
        $model = $query->getModel();

        $canSearchPrimaryKey = ctype_digit($search) &&
                               in_array($model->getKeyType(), ['int', 'integer']) &&
                               ($connectionType != 'pgsql' || $search <= $this->maxPrimaryKeySize);

        if (! $canSearchPrimaryKey) {
            return parent::apply($query, $search, $connectionType, $prefix, $suffix, $whereOperator);
        }

        return $query->{$whereOperator}($model->getQualifiedKeyName(), $search);
    }
}
