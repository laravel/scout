<?php

namespace Tests\Fixtures;

use Laravel\Scout\Searchable;

class SearchableTestModel extends TestModel
{
    use Searchable;
}
