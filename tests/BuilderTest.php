<?php

use Illuminate\Database\Eloquent\Collection;

use Illuminate\Pagination\Paginator;

class BuilderTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        Mockery::close();
    }

    public function test_pagination_correctly_handles_paginated_results()
    {
        $builder = new Laravel\Scout\Builder($model = Mockery::mock(), 'zonda');
        $model->shouldReceive('searchableUsing')->andReturn($engine = Mockery::mock());
        Paginator::currentPageResolver(function () {
            return 1;
        });
        Paginator::currentPathResolver(function () {
            return 'http://localhost/foo';
        });
        $engine->shouldReceive('paginate');
        $engine->shouldReceive('map')->andReturn(Collection::make([new StdClass]));

        $paginator = $builder->paginate();
    }
}
