<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

class SearchableModelWithSoftDeletes extends SearchableModel
{
    use SoftDeletes;
}
