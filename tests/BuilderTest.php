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
        Paginator::currentPageResolver(function () {
            return 1;
        });
        Paginator::currentPathResolver(function () {
            return 'http://localhost/foo';
        });

        $builder = new Builder($model = Mockery::mock(), 'zonda');
        $model->shouldReceive('getPerPage')->andReturn(15);
        $model->shouldReceive('searchableUsing')->andReturn($engine = Mockery::mock());

        $engine->shouldReceive('paginate');
        $engine->shouldReceive('map')->andReturn(Collection::make([new StdClass]));
        $engine->shouldReceive('getTotalCount');

        $builder->paginate();
    }

    public function testMacroable()
    {
        Builder::macro('foo', function () {
            return 'bar';
        });

        $builder = new Builder($model = Mockery::mock(), 'zonda');
        $this->assertEquals(
            'bar', $builder->foo()
        );
    }
}
