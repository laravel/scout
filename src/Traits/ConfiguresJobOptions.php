<?php

namespace Laravel\Scout\Traits;

trait ConfiguresJobOptions
{
    /**
     * The number of times the job may be attempted.
     *
     * @var int|null
     */
    public $tries;

    /**
     * The number of seconds to wait before retrying the job when encountering an uncaught exception.
     *
     * @var int|null
     */
    public $backoff;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     *
     * @var int|null
     */
    public $maxExceptions;

    /**
     * Indicates if the job should be marked as failed on timeout.
     *
     * @var bool
     */
    public $failOnTimeout = true;

    /**
     * Configure the job.
     *
     * @return void
     */
    protected function configureJob(): void
    {
        if (! isset($this->tries) && ! is_null($tries = config('scout.jobs.tries'))) {
            $this->tries = $tries;
        }

        if (! isset($this->backoff) &&
            ! method_exists($this, 'backoff') &&
            ! is_null($backoff = config('scout.jobs.backoff'))) {
            $this->backoff = $backoff;
        }

        if (! isset($this->maxExceptions) &&
            ! is_null($maxExceptions = config('scout.jobs.max_exceptions'))) {
            $this->maxExceptions = $maxExceptions;
        }
    }
}
