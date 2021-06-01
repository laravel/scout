<?php

namespace Laravel\Scout\Tests\Fixtures;

use Laravel\Scout\Jobs\MakeSearchable;

class OverriddenMakeSearchable extends MakeSearchable
{
    public $tries = 5;

    public function backoff(): array
    {
        return [2, 4, 8, 16, 32];
    }
}
