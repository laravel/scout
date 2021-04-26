<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;
use Laravel\Scout\Builder;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use stdClass;

class BuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_pagination_correctly_handles_paginated_results()
    {
        Paginator::currentPageResolver(function () {
            return 1;
        });
        Paginator::currentPathResolver(function () {
            return 'http://localhost/foo';
        });

        $builder = new Builder($model = m::mock(), 'zonda');
        $model->shouldReceive('getPerPage')->andReturn(15);
        $model->shouldReceive('searchableUsing')->andReturn($engine = m::mock());

        $engine->shouldReceive('paginate');
        $engine->shouldReceive('map')->andReturn($results = Collection::times(15, function () {
            return new stdClass;
        }));
        $engine->shouldReceive('getTotalCount')->andReturn(16);

        $model->shouldReceive('newCollection')->andReturn($results);

        $paginated = $builder->paginate();

        $this->assertSame($results->all(), $paginated->items());
        $this->assertSame(16, $paginated->total());
        $this->assertSame(15, $paginated->perPage());
        $this->assertSame(1, $paginated->currentPage());
        $this->assertSame([
            'path' => 'http://localhost/foo',
            'pageName' => 'page',
        ], $paginated->getOptions());
    }

    public function test_simple_pagination_correctly_handles_paginated_results()
    {
        Paginator::currentPageResolver(function () {
            return 1;
        });
        Paginator::currentPathResolver(function () {
            return 'http://localhost/foo';
        });

        $builder = new Builder($model = m::mock(), 'zonda');
        $model->shouldReceive('getPerPage')->andReturn(15);
        $model->shouldReceive('searchableUsing')->andReturn($engine = m::mock());

        $engine->shouldReceive('paginate');
        $engine->shouldReceive('map')->andReturn($results = Collection::times(15, function () {
            return new stdClass;
        }));
        $engine->shouldReceive('getTotalCount')->andReturn(16);

        $model->shouldReceive('newCollection')->andReturn($results);

        $paginated = $builder->simplePaginate();

        $this->assertSame($results->all(), $paginated->items());
        $this->assertTrue($paginated->hasMorePages());
        $this->assertSame(15, $paginated->perPage());
        $this->assertSame(1, $paginated->currentPage());
        $this->assertSame([
            'path' => 'http://localhost/foo',
            'pageName' => 'page',
        ], $paginated->getOptions());
    }

    public function test_simple_pagination_correctly_handles_paginated_results_without_more_pages()
    {
        Paginator::currentPageResolver(function () {
            return 1;
        });
        Paginator::currentPathResolver(function () {
            return 'http://localhost/foo';
        });

        $builder = new Builder($model = m::mock(), 'zonda');
        $model->shouldReceive('getPerPage')->andReturn(15);
        $model->shouldReceive('searchableUsing')->andReturn($engine = m::mock());

        $engine->shouldReceive('paginate');
        $engine->shouldReceive('map')->andReturn($results = Collection::times(10, function () {
            return new stdClass;
        }));
        $engine->shouldReceive('getTotalCount')->andReturn(10);

        $model->shouldReceive('newCollection')->andReturn($results);

        $paginated = $builder->simplePaginate();

        $this->assertSame($results->all(), $paginated->items());
        $this->assertFalse($paginated->hasMorePages());
        $this->assertSame(15, $paginated->perPage());
        $this->assertSame(1, $paginated->currentPage());
        $this->assertSame([
            'path' => 'http://localhost/foo',
            'pageName' => 'page',
        ], $paginated->getOptions());
    }

    public function test_macroable()
    {
        Builder::macro('foo', function () {
            return 'bar';
        });

        $builder = new Builder($model = m::mock(), 'zonda');
        $this->assertSame(
            'bar', $builder->foo()
        );
    }

    public function test_hard_delete_doesnt_set_wheres()
    {
        $builder = new Builder($model = m::mock(), 'zonda', null, false);

        $this->assertArrayNotHasKey('__soft_deleted', $builder->wheres);
    }

    public function test_soft_delete_sets_wheres()
    {
        $builder = new Builder($model = m::mock(), 'zonda', null, true);

        $this->assertSame(0, $builder->wheres['__soft_deleted']);
    }
}
