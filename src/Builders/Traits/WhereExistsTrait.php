<?php

namespace Laravel\Scout\Builders\Traits;

trait WhereExistsTrait
{
    /**
     * Add an exists clause to the query.
     *
     * @param  string  $field
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereExists($field, $boolean = 'AND', $not = false)
    {
        $type = 'Exists';

        $this->advancedWheres[] = compact('type', 'field', 'boolean', 'not');

        return $this;
    }

    /**
     * Add an or exists clause to the query.
     *
     * @param  string  $field
     * @param  bool  $not
     * @return $this
     */
    public function orWhereExists($field, $not = false)
    {
        return $this->whereExists($field, 'OR', $not);
    }

    /**
     * Add a where not exists clause to the query.
     *
     * @param  string  $field
     * @param  string  $boolean
     * @return $this
     */
    public function whereNotExists($field, $boolean = 'AND')
    {
        return $this->whereExists($field, $boolean, true);
    }

    /**
     * Add a where not exists clause to the query.
     *
     * @param  string  $field
     * @return $this
     */
    public function orWhereNotExists($field)
    {
        return $this->orWhereExists($field, true);
    }
}
