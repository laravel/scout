<?php

namespace Laravel\Scout\Engines\Database;

use Illuminate\Database\Eloquent\Builder;

class MorphRelation extends Relation
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
     * @param  string  $whereOperator
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function apply(Builder $query, $search, string $connectionType, string $prefix = '', string $suffix = '', string $whereOperator = 'orWhere')
    {
        return $query->{$whereOperator.'HasMorph'}($this->relation, $this->morphTypes(), function ($query) use ($search, $connectionType, $prefix, $suffix) {
            return (new Search($this->column))->apply(
                $query, $search, $connectionType, 'where', $prefix, $suffix
            );
        });
    }

    /**
     * Get available morph types.
     *
     * @return array|string
     */
    protected function morphTypes()
    {
        return ! empty($this->types) ? $this->types : '*';
    }
}
