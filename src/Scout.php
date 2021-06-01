<?php

namespace Laravel\Scout;

use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\RemoveFromSearch;

class Scout
{
    /**
     * The make searchable job name.
     *
     * @var string
     */
    public static $makeSearchableJob = MakeSearchable::class;

    /**
     * The remove from search job name.
     *
     * @var string
     */
    public static $removeFromSearchJob = RemoveFromSearch::class;
}
