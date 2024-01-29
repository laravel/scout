<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Model;
use Laravel\Scout\Searchable;

class SearchableUserModelWithCustomCreatedAt extends Model
{
    use Searchable;

    protected $table = 'users';

    public const CREATED_AT = 'created';
}
