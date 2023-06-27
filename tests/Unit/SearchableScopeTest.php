<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\SearchableScope;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class SearchableScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_chunks_by_id()
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('macro')->with('searchable', m::on(function ($callback) use ($builder) {
            $model = m::mock(Model::class);
            $model->shouldReceive('getScoutKeyName')->once()->andReturn('id');

            $builder->shouldReceive('chunkById')->with(500, m::type(\Closure::class), 'users.id', 'id');
            $builder->shouldReceive('getModel')->once()->andReturn($model);
            $builder->shouldReceive('qualifyColumn')->once()->andReturn('users.id');

            $callback($builder, 500);

            return true;
        }));

        $builder->shouldReceive('macro')->with('unsearchable', m::on(function ($callback) use ($builder) {
            $model = m::mock(Model::class);
            $model->shouldReceive('getScoutKeyName')->once()->andReturn('id');

            $builder->shouldReceive('chunkById')->with(500, m::type(\Closure::class), 'users.id', 'id');
            $builder->shouldReceive('getModel')->once()->andReturn($model);
            $builder->shouldReceive('qualifyColumn')->once()->andReturn('users.id');

            $callback($builder, 500);

            return true;
        }));

        (new SearchableScope())->extend($builder);
    }
}
