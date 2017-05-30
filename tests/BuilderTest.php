<?php

namespace Tests;

use Mockery;
use StdClass;
use Laravel\Scout\Builder;
use Illuminate\Pagination\Paginator;
use Tests\Fixtures\AdditionalActions;
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

    public function test_that_builder_can_be_extended()
    {
      $builder = new Builder($model = Mockery::mock(), 'zonda');
      $builder->extendWith(new AdditionalActions);
      $builder->whereIn('id', [1,2,3,4]);

      $this->assertTrue(array_key_exists('id', $builder->whereIns));
      $this->assertCount(4, $builder->whereIns['id']);
    }
}
