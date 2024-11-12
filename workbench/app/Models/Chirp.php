<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Chirp extends Model
{
    use HasUuids;
    use Searchable;

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
}
