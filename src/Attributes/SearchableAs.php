<?php

namespace Laravel\Scout\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class SearchableAs
{
    /**
     * Create a new attribute instance.
     */
    public function __construct(public string $index)
    {
        //
    }
}
