<?php

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

class Bookmark extends Model
{
    use HasFactory;
    use Searchable;

    public function chirp(): BelongsTo
    {
        return $this->belongsTo(Chirp::class);
    }

    public function newScoutQuery(): Builder
    {
        return $this->newQuery()->join('chirps', 'chirps.id', '=', 'bookmarks.chirp_id');
    }

    public function toSearchableArray()
    {
        if (isset($_ENV['bookmark.toSearchableArray'])) {
            return value($_ENV['bookmark.toSearchableArray'], $this);
        }

        return [
            'label' => $this->label,
            'chirps.content' => '',
        ];
    }
}
