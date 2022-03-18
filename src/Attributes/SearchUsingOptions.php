<?php

namespace Laravel\Scout\Attributes;

use Attribute;
use Illuminate\Support\Arr;

#[Attribute]
class SearchUsingOptions
{
    /**
     * The full-text search options.
     */
    public $options = [];

    /**
     * Create a new attribute instance.
     */
    public function __construct($options)
    {
        $this->options = Arr::wrap($options);
    }
}
