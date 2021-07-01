<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class SearchableModelWithSensitiveAttributes extends Model
{
    use Searchable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['first_name', 'last_name', 'remember_token', 'password'];

    /**
     * Get the update-sensitive attributes that, when changed, trigger an engine update.
     *
     * @return string[]
     */
    public function scoutSensitiveAttributes()
    {
        return ['first_name', 'last_name'];
    }
}
