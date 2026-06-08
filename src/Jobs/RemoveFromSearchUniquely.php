<?php

namespace Laravel\Scout\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Laravel\Scout\Traits\UniqueByScoutKeys;

class RemoveFromSearchUniquely extends RemoveFromSearch implements ShouldBeUniqueUntilProcessing
{
    use UniqueByScoutKeys;
}
