<?php

namespace Laravel\Scout\Contracts;

use Laravel\Scout\Builder;

interface PaginatesEloquentModelsUsingDatabase
{
    /**
     * Paginate the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginateDatabase(Builder $builder, $perPage, $pageName, $page);

    /**
     * Paginate the given search on the engine using simple pagination.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int  $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginateDatabase(Builder $builder, $perPage, $pageName, $page);
}
