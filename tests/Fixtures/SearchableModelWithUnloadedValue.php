<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Model;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;

class SearchableModelWithUnloadedValue extends Model
{
    use Searchable;

    protected $table = 'users';

    public function toSearchableArray()
    {
        return [
            'value' => $this->unloadedValue,
        ];
    }

    public function makeSearchableUsing(Collection $models)
    {
        return $models->each(
            fn ($model) => $model->unloadedValue = 'loaded',
        );
    }
}
