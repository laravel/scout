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

    public function test_builder_sets_the_relations_to_be_eager_loaded()
    {
        $builder = new Builder($model = Mockery::mock(), 'zonda');
        $builder->with('foo', 'bar.baz');

        $this->assertTrue($builder->hasEagerLoads());
        $this->assertEquals(['foo', 'bar.baz'], $builder->getEagerLoads());
    }

    public function test_builder_sets_an_array_of_relations_to_be_eager_loaded()
    {
        $builder = new Builder($model = Mockery::mock(), 'zonda');
        $builder->with(['foo', 'bar', 'baz']);

        $this->assertTrue($builder->hasEagerLoads());
        $this->assertEquals(['foo', 'bar', 'baz'], $builder->getEagerLoads());
    }

    public function test_builder_returns_the_query_builder_instance()
    {
        $builder = new Builder($model = Mockery::mock(), 'zonda');

        $model->shouldReceive('newQuery')
              ->andReturn(Mockery::mock('Illuminate\Database\Eloquent\Builder'));

        $builder->getQuery();
    }

    public function test_builder_returns_the_query_builder_instance_with_eager_loading()
    {
        $builder = (new Builder($model = Mockery::mock(), 'zonda'))
            ->with('foo');

        $model->shouldReceive('with')->with(['foo']);

        $builder->getQuery();
    }
}
