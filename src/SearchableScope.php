<?php

namespace Laravel\Scout;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Scout\Events\ModelsImported;

class SearchableScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function apply(Builder $builder, Model $model)
    {
        //
    }

    /**
     * Extend the query builder with the needed functions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @return void
     */
    public function extend(Builder $builder)
    {
        $builder->macro('searchable', function (Builder $builder) {
            $builder->chunk(100, function ($models) use ($builder) {
                $models->searchable();

                event(new ModelsImported($models));
            });
        });

        $builder->macro('unsearchable', function (Builder $builder) {
            $builder->chunk(100, function ($models) use ($builder) {
                $models->unsearchable();
            });
        });
    }
}
