<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Config;
use Laravel\Scout\Builder;
use Laravel\Scout\Builders\CollectionBuilder;
use Laravel\Scout\Engines\CollectionEngine;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class CollectionEngineTest extends TestCase
{
    protected function setUp(): void
    {
        Config::shouldReceive('get')->with('scout.after_commit', m::any())->andReturn(false);
        Config::shouldReceive('get')->with('scout.soft_delete', m::any())->andReturn(false);
    }

    protected function tearDown(): void
    {
        Container::getInstance()->flush();
        m::close();
    }

    public function test_advanced_where_query_are_constructed_correctly()
    {
        [$model, $dbQuery] = $this->prepareQueryBuilderMock();

        $dbQuery->shouldReceive('where')->with('field1', '>', 1, 'AND', false)->andReturn($dbQuery);
        $dbQuery->shouldReceive('where')->with('field2', '<', 2, 'OR', false)->andReturn($dbQuery);
        $dbQuery->shouldReceive('where')->with('field3', '=', 'test word', 'OR', true)->andReturn($dbQuery);
        $dbQuery->shouldReceive('where')->with('field4', '=', true, 'AND', true)->andReturn($dbQuery);

        $engine = new CollectionEngine();
        $builder = new CollectionBuilder($model, '');
        $builder->where('field1', '>', 1)
            ->orWhere('field2', '<', 2)
            ->orWhereNot('field3', '=', 'test word')
            ->whereNot('field4', '=', true);

        $engine->search($builder);
    }

    public function test_advanced_where_between_query_are_constructed_correctly()
    {
        [$model, $dbQuery] = $this->prepareQueryBuilderMock();

        $dbQuery->shouldReceive('whereBetween')->with('field1', [1, 2], 'AND', false)->andReturn($dbQuery);
        $dbQuery->shouldReceive('whereBetween')->with('field2', [3, 4], 'OR', false)->andReturn($dbQuery);
        $dbQuery->shouldReceive('whereBetween')->with('field3', [5, 6], 'OR', true)->andReturn($dbQuery);
        $dbQuery->shouldReceive('whereBetween')->with('field4', [7, 8], 'AND', true)->andReturn($dbQuery);

        $engine = new CollectionEngine();
        $builder = new CollectionBuilder($model, '');
        $builder->whereBetween('field1', [1, 2])
            ->orWhereBetween('field2', [3, 4])
            ->orWhereNotBetween('field3', [5, 6])
            ->whereNotBetween('field4', [7, 8]);

        $engine->search($builder);
    }

    public function test_advanced_where_in_query_are_constructed_correctly()
    {
        [$model, $dbQuery] = $this->prepareQueryBuilderMock();

        $dbQuery->shouldReceive('whereIn')->with('field1', [1, 2, 3], 'AND', false)->andReturn($dbQuery);
        $dbQuery->shouldReceive('whereIn')->with('field2', [4, 5, 6], 'OR', false)->andReturn($dbQuery);
        $dbQuery->shouldReceive('whereIn')->with('field3', [7, 8, 9], 'OR', true)->andReturn($dbQuery);
        $dbQuery->shouldReceive('whereIn')->with('field4', ['string1', 'string2', 'string3'], 'AND', true)->andReturn($dbQuery);

        $engine = new CollectionEngine();
        $builder = new CollectionBuilder($model, '');
        $builder->whereInAdvanced('field1', [1, 2, 3])
            ->orWhereIn('field2', [4, 5, 6])
            ->orWhereNotIn('field3', [7, 8, 9])
            ->whereNotIn('field4', ['string1', 'string2', 'string3']);

        $engine->search($builder);
    }

    public function test_advanced_nested_where_query_are_constructed_correctly()
    {
        [$model, $dbQuery] = $this->prepareQueryBuilderMock();

        $dbSubQuery = m::mock(\Illuminate\Database\Query\Builder::class);
        $dbSubQuery->grammar = m::mock(\Illuminate\Database\Query\Grammars\Grammar::class)->makePartial();
        $dbQuery->shouldReceive('forNestedWhere')->andReturn($dbSubQuery);
        $dbQuery->shouldReceive('addNestedWhereQuery');
        $dbQuery->shouldReceive('where')->with('field1', '=', 1, 'AND', false)->andReturn($dbQuery);
        $dbSubQuery->shouldReceive('where')->with('subField1', '=', 'string1')->andReturn($dbSubQuery);
        $dbSubQuery->shouldReceive('where')->with('subField2', '>', 2, 'AND', false)->andReturn($dbSubQuery);
        $dbSubQuery->shouldReceive('where')->with('subField3', '=', 'string3', 'OR', false)->andReturn($dbSubQuery);

        $engine = new CollectionEngine();
        $builder = new CollectionBuilder($model, '');
        $builder->where('field1', '=', 1)
                ->orWhere(fn (Builder $subBuilder) => $subBuilder->where('subField1', 'string1')
                                            ->where('subField2', '>', 2)
                                            ->orWhere('subField3', '=', 'string3'));
        $engine->search($builder);
    }

    protected function prepareQueryBuilderMock()
    {
        $dbQuery = m::mock(\Illuminate\Database\Query\Builder::class);
        $dbQuery->grammar = m::mock(\Illuminate\Database\Query\Grammars\Grammar::class)->makePartial();
        $eloquentBuilder = m::mock(\Illuminate\Database\Eloquent\Builder::class)->makePartial();
        $eloquentBuilder->setQuery($dbQuery);
        $eloquentBuilder->shouldReceive('get')->andReturn(collect([]));
        $model = m::mock(SearchableModel::class)->makePartial();
        $model->shouldReceive('toSearchableArray')->andReturns([]);
        $model->shouldReceive('qualifyColumn')->with('id')->andReturns('id');
        $model->shouldReceive('query')->andReturns($eloquentBuilder);
        $model->shouldReceive('newQuery')->andReturns($eloquentBuilder);
        $dbQuery->shouldReceive('where')->with(\Mockery::type(\Closure::class))->andReturn($dbQuery);
        $dbQuery->shouldReceive('take')->with(null)->andReturn($dbQuery);
        $dbQuery->shouldReceive('orderBy')->with('id', 'desc')->andReturn($dbQuery);

        return [$model, $dbQuery];
    }
}
