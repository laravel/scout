<?php

namespace Laravel\Scout\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Laravel\Scout\Traits\UniqueByScoutKeys;

class MakeSearchableUniquely extends MakeSearchable implements ShouldBeUniqueUntilProcessing
{
    use UniqueByScoutKeys;
}
