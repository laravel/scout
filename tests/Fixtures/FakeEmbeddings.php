<?php

namespace Laravel\Scout\Tests\Fixtures;

class FakeEmbeddings
{
    public static $requests = [];

    public static $responses = [];

    public static function fake(array $responses)
    {
        static::$requests = [];
        static::$responses = $responses;
    }

    public static function for(array $inputs)
    {
        return new FakePendingEmbeddings($inputs);
    }
}

class FakePendingEmbeddings
{
    protected $cacheSeconds = false;

    protected $dimensions;

    public function __construct(protected array $inputs)
    {
        //
    }

    public function dimensions($dimensions)
    {
        $this->dimensions = $dimensions;

        return $this;
    }

    public function cache($seconds = null)
    {
        $this->cacheSeconds = $seconds;

        return $this;
    }

    public function generate($provider = null, $model = null)
    {
        FakeEmbeddings::$requests[] = [
            'inputs' => $this->inputs,
            'cache' => $this->cacheSeconds,
            'dimensions' => $this->dimensions,
            'provider' => $provider,
            'model' => $model,
        ];

        return (object) [
            'embeddings' => array_shift(FakeEmbeddings::$responses),
        ];
    }
}
