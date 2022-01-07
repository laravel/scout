<?php

namespace Laravel\Scout\Engines\Database;

class MorphRelation extends Search
{
    /**
     * The available morph types.
     * @var array
     */
    public $types = [];

    /**
     * Construct a new search.
     *
     * @param  string  $relation
     * @param  \Illuminate\Database\Query\Expression|string  $column
     * @param  array  $types
     * @return void
     */
    public function __construct(string $relation, $column, array $types = [])
    {
        $this->types = $types;

        parent::__construct($relation, $column);
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
    public function apply(Builder $query, string $search, string $connectionType, string $prefix = '', string $suffix = '')
    {
        $types = ! empty($this->types) ? $this->types : '*';

        return $query->orWhereHasMorph($this->relation, $types, function ($query) use ($search, $connectionType, $prefix, $suffix) {
            return (new Search($this->column))->apply(
                $query, $search, $connectionType, $prefix, $suffix
            );
        });
    }
}
