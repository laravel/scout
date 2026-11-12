<?php

namespace Laravel\Scout\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Attributes\SearchableWith;
use Laravel\Scout\Searchable;
use Workbench\App\Models\Chirp;

#[SearchableWith('chirp')]
class SearchableWithModel extends Model
{
    use Searchable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'bookmarks';

    public function chirp(): BelongsTo
    {
        return $this->belongsTo(Chirp::class, 'chirp_id');
    }
}
