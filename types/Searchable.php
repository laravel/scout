<?php

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

use function PHPStan\Testing\assertType;

assertType('Laravel\Scout\Builder<Post>', Post::search());

class Post extends Model
{
    use Searchable;
}
