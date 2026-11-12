<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Attributes\SearchableAs;
use Laravel\Scout\Searchable;

#[SearchableAs('posts_index')]
class SearchableAsModel extends Model
{
    use Searchable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'posts';
}
