<?php

namespace Tests\Fixtures;

use Laravel\Scout\Searchable;
use Illuminate\Database\Eloquent\SoftDeletes;

class SearchableSoftDeleteTestModel extends TestModel
{
    use Searchable, SoftDeletes;
}
