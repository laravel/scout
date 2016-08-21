<?php

namespace Tests;

use Mockery;
use StdClass;
use Laravel\Scout\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Database\Eloquent\Collection;

class BuilderTest extends AbstractTestCase
{
    public function test_pagination_correctly_handles_paginated_results()
    {
        $builder = new Builder($model = Mockery::mock(), 'zonda');
        $model->shouldReceive('searchableUsing')->andReturn($engine = Mockery::mock());
        Paginator::currentPageResolver(function () {
            return 1;
        });
        Paginator::currentPathResolver(function () {
            return 'http://localhost/foo';
        });
        $engine->shouldReceive('paginate');
        $engine->shouldReceive('map')->andReturn(Collection::make([new StdClass]));

        $builder->paginate();
    }
}
