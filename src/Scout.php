<?php

namespace Laravel\Scout;

use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\RemoveFromSearch;

class Scout
{
    /**
     * The Scout library version.
     *
     * @var string
     */
    const VERSION = '10.20.0';

    /**
     * The job class that should make models searchable.
     *
     * @var string
     */
    public static $makeSearchableJob = MakeSearchable::class;

    /**
     * The job that should remove models from the search index.
     *
     * @var string
     */
    public static $removeFromSearchJob = RemoveFromSearch::class;

    /**
     * Get a Scout engine instance.
     */
    public static function engine(string $engine): Engine
    {
        return app(EngineManager::class)->engine($engine);
    }

    /**
     * Specify the job class that should make models searchable.
     *
     * @param  string  $class
     * @return void
     */
    public static function makeSearchableUsing(string $class)
    {
        static::$makeSearchableJob = $class;
    }

    /**
     * Specify the job class that should remove models from the search index.
     *
     * @param  string  $class
     * @return void
     */
    public static function removeFromSearchUsing(string $class)
    {
        static::$removeFromSearchJob = $class;
    }
}
