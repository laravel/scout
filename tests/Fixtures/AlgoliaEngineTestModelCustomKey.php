<?php

namespace Tests\Fixtures;

class AlgoliaEngineTestModelCustomKey extends TestModel
{
    public function getSearchableKey()
    {
        return "custom-{$this->getKey()}";
    }

    public function getReverseSearchableKey($key = '')
    {
        return str_replace('custom-1', '', $key);
    }
}
