<?php

namespace Tests\Fixtures;

class AlgoliaEngineTestCustomKeyModel extends TestModel
{
    public function getScoutKey() {
        return 'my-algolia-key.'.$this->getKey();
    }
}
