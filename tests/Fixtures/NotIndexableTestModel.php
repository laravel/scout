<?php

namespace Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class NotIndexableTestModel extends TestModel
{
    public function searchableAs() {
        return [];
    }
}
