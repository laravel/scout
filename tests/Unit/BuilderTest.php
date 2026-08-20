<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\Paginator;
use Laravel\Scout\Builder;
use Laravel\Scout\Exceptions\NotSupportedException;
use Laravel\Scout\Exceptions\ScoutException;
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
        $builder->where('foo', 'bar');

        $this->assertSame([['field' => 'foo', 'operator' => '=', 'value' => 'bar']], $builder->wheres);

        $builder = new Builder($model = m::mock(), 'zonda', null, true);
        $builder->where('foo', 'bar');

        $this->assertSame([['field' => '__soft_deleted', 'operator' => '=', 'value' => 0], ['field' => 'foo', 'operator' => '=', 'value' => 'bar']], $builder->wheres);
    }

    public function test_soft_delete_sets_wheres()
    {
        $builder = new Builder($model = m::mock(), 'zonda', null, true);

        $this->assertSame([['field' => '__soft_deleted', 'operator' => '=', 'value' => 0]], $builder->wheres);
    }

    public function test_semantic_search_can_be_enabled()
    {
        $builder = (new Builder(m::mock(), 'conceptual query'))->semantic(minSimilarity: 0.7);

        $this->assertTrue($builder->semanticSearch);
        $this->assertNull($builder->hybridSearch);
        $this->assertSame(0.7, $builder->minimumSimilarity);
    }

    public function test_hybrid_search_can_be_enabled_with_weights()
    {
        $builder = (new Builder(m::mock(), 'combined query'))->hybrid(2, 3, minSimilarity: 0.8);

        $this->assertFalse($builder->semanticSearch);
        $this->assertSame([
            'text_weight' => 2,
            'semantic_weight' => 3,
        ], $builder->hybridSearch);
        $this->assertSame(0.8, $builder->minimumSimilarity);
    }

    public function test_semantic_and_hybrid_search_require_a_query()
    {
        foreach (['semantic', 'hybrid'] as $method) {
            try {
                (new Builder(m::mock(), ''))->{$method}();

                $this->fail("Expected [{$method}] to reject an empty query.");
            } catch (ScoutException $e) {
                $this->assertStringContainsString('non-empty query', $e->getMessage());
            }
        }
    }

    public function test_hybrid_search_requires_positive_weights()
    {
        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('positive numbers');

        (new Builder(m::mock(), 'query'))->hybrid(1, 0);
    }

    public function test_unsupported_engines_reject_semantic_search()
    {
        $model = m::mock();
        $model->shouldReceive('searchableUsing')->andReturn(m::mock());

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('does not support semantic search');

        (new Builder($model, 'query'))->semantic()->raw();
    }

    public function test_unsupported_engines_treat_hybrid_search_as_normal_text_search()
    {
        $model = m::mock();
        $engine = m::mock();
        $model->shouldReceive('searchableUsing')->andReturn($engine);
        $engine->shouldReceive('search')->once()->andReturn(['results']);

        $results = (new Builder($model, 'query'))->hybrid()->raw();

        $this->assertSame(['results'], $results);
    }
}
