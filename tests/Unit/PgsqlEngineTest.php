<?php

namespace Laravel\Scout\Tests\Unit;

use InvalidArgumentException;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\PgsqlEngine;
use PHPUnit\Framework\TestCase;

class PgsqlEngineTest extends TestCase
{
    public function test_pgsql_engine_rejects_non_postgresql_connections()
    {
        $engine = new PgsqlEngine([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The [pgsql] Scout driver may only be used with PostgreSQL connections.');

        $engine->search(new Builder(new class
        {
            public function getConnection()
            {
                return new class
                {
                    public function getDriverName()
                    {
                        return 'sqlite';
                    }
                };
            }
        }, 'Taylor'));
    }
}
