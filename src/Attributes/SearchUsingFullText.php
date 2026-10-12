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
     * The full-text options.
     */
    public $options = [];

    /**
     * Create a new attribute instance.
     *
     * @param  array  $columns
     * @param  array{
     *     mode?: 'plain'|'phrase'|'websearch'|'raw'|'natural'|'boolean',
     *     language?: string,
     *     vector?: bool,
     *     trigram?: array<int, string>,
     *     trigram_mode?: 'fallback'|'blend',
     *     rank?: 'ts_rank'|'ts_rank_cd',
     *     weights?: array{rank?: float|int, trigram?: float|int},
     *     expanded?: bool,
     *     ...
     * }  $options
     * @return void
     */
    public function __construct($columns, $options = [])
    {
        $this->columns = Arr::wrap($columns);
        $this->options = Arr::wrap($options);
    }
}
