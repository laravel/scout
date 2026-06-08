<?php

namespace Laravel\Scout\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Laravel\Scout\Traits\UniqueByScoutKeys;

class MakeSearchableUnique extends MakeSearchable implements ShouldBeUniqueUntilProcessing
{
    use UniqueByScoutKeys;
}
