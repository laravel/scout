<?php

namespace Laravel\Scout\Builders;

use Laravel\Scout\Builders\Traits\WhereExistsTrait;
use Laravel\Scout\Builders\Traits\WhereNullTrait;

class MeilisearchBuilder extends \Laravel\Scout\Builder
{
    use WhereNullTrait, WhereExistsTrait;
}
