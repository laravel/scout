<?php

namespace Laravel\Scout;

use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\RemoveFromSearch;

class Scout
{
    public static $makeSearchableJob = MakeSearchable::class;

    public static $removeFromSearchJob = RemoveFromSearch::class;
}
