<?php

namespace Workbench\Database\Factories;

use Workbench\App\Models\SearchableUser;

class SearchableUserFactory extends UserFactory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<TModel>
     */
    protected $model = SearchableUser::class;
}
