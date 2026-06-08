<?php

namespace Laravel\Scout\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Laravel\Scout\Traits\UniqueByScoutKeys;

class RemoveFromSearchUnique extends RemoveFromSearch implements ShouldBeUniqueUntilProcessing
{
    use UniqueByScoutKeys;
}
