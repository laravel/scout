<?php

namespace Laravel\Scout\Tests\Fixtures;

class AlgoliaEngineTestCustomKeyModel extends TestModel
{
    public function getScoutKey()
    {
        return 'my-algolia-key.'.$this->getKey();
    }
}
