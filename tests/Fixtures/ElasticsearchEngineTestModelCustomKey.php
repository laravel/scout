<?php

namespace Tests\Fixtures;

class ElasticsearchEngineTestModelCustomKey extends TestModel
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
