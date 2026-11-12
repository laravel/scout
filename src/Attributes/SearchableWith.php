<?php

namespace Laravel\Scout\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class SearchableWith
{
    /**
     * The relationships to load when making the model searchable.
     *
     * @var array<int, string>
     */
    public array $relations;

    /**
     * Create a new attribute instance.
     *
     * @param  array<int, string>|string  ...$relations
     */
    public function __construct(array|string ...$relations)
    {
        $this->relations = is_array($relations[0]) ? $relations[0] : $relations;
    }
}
