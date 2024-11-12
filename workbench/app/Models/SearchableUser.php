<?php

namespace Workbench\App\Models;

use Laravel\Scout\Searchable;

class SearchableUser extends User
{
    use Searchable;

    /** {@inheritDoc} */
    public function toSearchableArray()
    {
        return $_ENV['searchable.user'] ?? [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }

    /** {@inheritDoc} */
    public function searchIndexShouldBeUpdated()
    {
        return $_ENV['search-index.user'] ?? true;
    }
}
