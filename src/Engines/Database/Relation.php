<?php

namespace Laravel\Scout\Engines\Database;

class Relation extends Search
{
    /**
     * The relationship name.
     *
     * @var string
     */
    public $relation;

    /**
     * Construct a new search.
     *
     * @param  string  $relation
     * @param  \Illuminate\Database\Query\Expression|string  $column
     * @return void
     */
    public function __construct(string $relation, $column)
    {
        $this->relation = $relation;

        parent::__construct($column);
    }

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
        return $query->orWhereHas($this->relation, function ($query) use ($search, $connectionType, $prefix, $suffix) {
            return (new Search($this->column))->apply(
                $query, $search, $connectionType, $prefix, $suffix
            );
        });
    }
}
