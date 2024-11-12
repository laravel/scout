<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Chirp extends Model
{
    use HasUuids;
    use Searchable;
    use SoftDeletes;

    /** {@inheritDoc} */
    protected $fillable = ['scout_id'];

    /** {@inheritDoc} */
    public function uniqueIds()
    {
        return [$this->getScoutKeyName()];
    }

    /** {@inheritDoc} */
    public function getScoutKey()
    {
        return $this->scout_id;
    }

    /** {@inheritDoc} */
    public function getScoutKeyName()
    {
        return 'scout_id';
    }

    public function toSearchableArray()
    {
        if (isset($_ENV['chirp.toSearchableArray'])) {
            return value($_ENV['chirp.toSearchableArray'], $this);
        }

        return [
            'content' => $this->content,
        ];
    }
}
