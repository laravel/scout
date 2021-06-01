<?php

namespace Laravel\Scout;

use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\RemoveFromSearch;

class Scout
{
    /**
     * The make searchable job class name.
     *
     * @var string
     */
    public static $makeSearchableJob = MakeSearchable::class;

    /**
     * The remove from search job class name.
     *
     * @var string
     */
    public static $removeFromSearchJob = RemoveFromSearch::class;

    /**
     * Set the make searchable job class name.
     *
     * @param  string  $makeSearchableJob
     * @return void
     */
    public static function useMakeSearchableJob(string $makeSearchableJob): void
    {
        static::$makeSearchableJob = $makeSearchableJob;
    }

    /**
     * Set the remove from search job class name.
     *
     * @param  string  $removeFromSearchJob
     * @return void
     */
    public static function useRemoveFromSearchJob(string $removeFromSearchJob): void
    {
        static::$removeFromSearchJob = $removeFromSearchJob;
    }
}
