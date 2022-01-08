<?php

namespace Laravel\Scout\Engines\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

class Search
{
    /**
     * The search column.
     *
     * @var \Illuminate\Database\Query\Expression|string
     */
    public $column;

    /**
     * Construct a new search.
     *
     * @param  \Illuminate\Database\Query\Expression|string  $column
     * @return void
     */
    public function __construct($column)
    {
        $this->column = $column;
    }

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
        return $query->{$whereOperator}(
            $this->columnName($query),
            $connectionType == 'pgsql' ? 'ilike' : 'like',
            $prefix.$search.$suffix
        );
    }

    /**
     * Get the column name.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return string
     */
    protected function columnName(Builder $query)
    {
        return $this->column instanceof Expression ? $this->column : $query->qualifyColumn($this->column);
    }
}
