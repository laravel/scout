<?php

namespace Laravel\Scout\Tests\Fixtures;

class SearchableModelWithPrecomputedEmbedding extends SearchableModel
{
    public function toSearchableEmbedding()
    {
        return $this->embedding ?? parent::toSearchableEmbedding();
    }
}
