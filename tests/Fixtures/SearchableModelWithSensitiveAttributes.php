<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class SearchableModelWithSensitiveAttributes extends Model
{
    use Searchable;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['first_name', 'last_name', 'remember_token', 'password'];

    /**
     * When updating a model, this method determines if we
     * should perform a search engine update or not.
     *
     * @return bool
     */
    public function searchShouldUpdate(): bool
    {
        $sensitiveAttributeKeys = ['first_name', 'last_name'];

        $dirtyKeys = collect($this->getDirty())->keys();

        if ($dirtyKeys->isEmpty()) {
            // Empty dirty attributes means delete/restore.
            return true;
        }

        return collect($this->getDirty())->keys()
            ->intersect($sensitiveAttributeKeys)
            ->isNotEmpty();
    }
}
