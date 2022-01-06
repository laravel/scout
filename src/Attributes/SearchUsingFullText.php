<?php

namespace Laravel\Scout\Attributes;

use Attribute;
use Illuminate\Support\Arr;

#[Attribute]
class SearchUsingFullText
{
    /**
     * The full-text columns.
     *
     * @var array
     */
    public $columns = [];

    /**
     * Create a new attribute instance.
     *
     * @param  array|string  $columns
     * @return void
     */
    public function __construct($columns)
    {
        $this->columns = Arr::wrap($columns);
    }
}
