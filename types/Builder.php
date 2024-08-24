<?php

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;

use function PHPStan\Testing\assertType;

/** @param \Laravel\Scout\Builder<User> $builder */
function test(
    Builder $builder,
): void {
    assertType('Illuminate\Database\Eloquent\Collection<int, User>', $builder->get());
    assertType('User', $builder->first());
}

class User extends Model
{
}
