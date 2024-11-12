<?php

namespace Workbench\App\Models;

use Laravel\Scout\Searchable;

class SearchableUser extends User
{
    use Searchable;

    /** {@inheritDoc} */
    public function toSearchableArray()
    {
        return $_ENV['user.toSearchableArray'] ?? [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }

    /** {@inheritDoc} */
    public function wasSearchableBeforeUpdate()
    {
        return $_ENV['user.wasSearchableBeforeUpdate'] ?? true;
    }

    /** {@inheritDoc} */
    public function wasSearchableBeforeDelete()
    {
        return $_ENV['user.wasSearchableBeforeDelete'] ?? true;
    }

    /** {@inheritDoc} */
    public function shouldBeSearchable()
    {
        return $_ENV['user.shouldBeSearchable'] ?? true;
    }


    /** {@inheritDoc} */
    public function searchIndexShouldBeUpdated()
    {
        return $_ENV['user.searchIndexShouldBeUpdated'] ?? true;
    }
}
