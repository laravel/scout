<?php

namespace Tests;

use Mockery;
use StdClass;
use Laravel\Scout\Builder;
use Illuminate\Pagination\Paginator;
use Tests\Fixtures\SearchableTestModel;
use Illuminate\Database\Eloquent\Collection;
use Tests\Fixtures\SearchableSoftDeleteTestModel;

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

    public function test_macroable()
    {
        Builder::macro('foo', function () {
            return 'bar';
        });

        $builder = new Builder($model = Mockery::mock(), 'zonda');
        $this->assertEquals(
            'bar', $builder->foo()
        );
    }

    public function test_hard_delete_doesnt_set_wheres()
    {
        $builder = new Builder(new SearchableTestModel, 'zonda', null, true);

        $this->assertArrayNotHasKey('__soft_deleted', $builder->wheres);
    }

    public function test_soft_delete_sets_wheres()
    {
        $builder = new Builder(new SearchableSoftDeleteTestModel, 'zonda', null, true);

        $this->assertEquals(0, $builder->wheres['__soft_deleted']);
    }
}
