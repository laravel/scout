<?php

namespace Workbench\App\Models;

use Laravel\Scout\Searchable;

class SearchableUser extends User
{
    use Searchable;

    /** {@inheritDoc} */
    public function toSearchableArray()
    {
        if (isset($_ENV['user.toSearchableArray'])) {
            return value($_ENV['user.toSearchableArray'], $this);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }

    /** {@inheritDoc} */
    public function wasSearchableBeforeUpdate()
    {
        if (isset($_ENV['user.wasSearchableBeforeUpdate'])) {
            return value($_ENV['user.wasSearchableBeforeUpdate'], $this);
        }

        return true;
    }

    /** {@inheritDoc} */
    public function wasSearchableBeforeDelete()
    {
        if (isset($_ENV['user.wasSearchableBeforeDelete'])) {
            return value($_ENV['user.wasSearchableBeforeDelete'], $this);
        }

        return true;
    }

    /** {@inheritDoc} */
    public function shouldBeSearchable()
    {
        if (isset($_ENV['user.shouldBeSearchable'])) {
            return value($_ENV['user.shouldBeSearchable'], $this);
        }

        return true;
    }

    /** {@inheritDoc} */
    public function searchIndexShouldBeUpdated()
    {
        if (isset($_ENV['user.searchIndexShouldBeUpdated'])) {
            return value($_ENV['user.searchIndexShouldBeUpdated'], $this);
        }

        return true;
    }
}
