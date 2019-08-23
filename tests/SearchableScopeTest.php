<?php

namespace Laravel\Scout\Tests;

use Mockery as m;
use PHPUnit\Framework\TestCase;
use Laravel\Scout\SearchableScope;
use Illuminate\Database\Eloquent\Builder;

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
            $builder->shouldReceive('chunkById')->with(500, m::type(\Closure::class));
            $callback($builder, 500);

            return true;
        }));
        $builder->shouldReceive('macro')->with('unsearchable', m::on(function ($callback) use ($builder) {
            $builder->shouldReceive('chunkById')->with(500, m::type(\Closure::class));
            $callback($builder, 500);

            return true;
        }));

        (new SearchableScope())->extend($builder);
    }
}
