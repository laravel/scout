<?php

namespace Laravel\Scout\Builders\Traits;

use Illuminate\Support\Arr;

trait WhereNullTrait
{
    /**
     * Add a "where null" clause to the query.
     *
     * @param  string|array  $fields
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereNull($fields, $boolean = 'AND', $not = false)
    {
        $type = 'Null';

        foreach (Arr::wrap($fields) as $field) {
            $this->advancedWheres[] = compact('type', 'field', 'boolean', 'not');
        }

        return $this;
    }

    /**
     * Add an "or where null" clause to the query.
     *
     * @param  string|array  $field
     * @return $this
     */
    public function orWhereNull($field)
    {
        return $this->whereNull($field, 'OR');
    }

    /**
     * Add a "where not null" clause to the query.
     *
     * @param  string|array  $fields
     * @param  string  $boolean
     * @return $this
     */
    public function whereNotNull($fields, $boolean = 'AND')
    {
        return $this->whereNull($fields, $boolean, true);
    }

    /**
     * Add an "or where not null" clause to the query.
     *
     * @param  string  $field
     * @return $this
     */
    public function orWhereNotNull($field)
    {
        return $this->whereNotNull($field, 'OR');
    }
}
