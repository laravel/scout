<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\SearchableScope;
use Mockery as m;
use Orchestra\Testbench\Concerns\InteractsWithMockery;
use PHPUnit\Framework\TestCase;

class SearchableScopeTest extends TestCase
{
    use InteractsWithMockery;

    protected function tearDown(): void
    {
        $this->tearDownTheTestEnvironmentUsingMockery();
    }

    public function test_chunks_by_id()
    {
        $builder = m::spy(Builder::class);

        $builder->shouldReceive('macro')->with('searchable', m::on(function ($callback) use ($builder) {
            $model = m::mock(Model::class);
            $model->shouldReceive('getScoutKeyColumnName')->once()->andReturn('id');

            $builder->shouldReceive('chunkById')->with(500, m::type(\Closure::class), 'users.id', 'id')->once();
            $builder->shouldReceive('getModel')->once()->andReturn($model);
            $builder->shouldReceive('qualifyColumn')->once()->andReturn('users.id');

            $callback($builder, 500);

            return true;
        }))->once();

        $builder->shouldReceive('macro')->with('unsearchable', m::on(function ($callback) use ($builder) {
            $model = m::mock(Model::class);
            $model->shouldReceive('getScoutKeyColumnName')->once()->andReturn('id');

            $builder->shouldReceive('chunkById')->with(500, m::type(\Closure::class), 'users.id', 'id')->once();
            $builder->shouldReceive('getModel')->once()->andReturn($model);
            $builder->shouldReceive('qualifyColumn')->once()->andReturn('users.id');

            $callback($builder, 500);

            return true;
        }))->once();

        (new SearchableScope)->extend($builder);
    }
}
