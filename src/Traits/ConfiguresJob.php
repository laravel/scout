<?php

namespace Laravel\Scout\Traits;

trait ConfiguresJob
{
    /**
     * The number of times the job may be attempted.
     *
     * @var int|null
     */
    public $tries;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int|array<int>|null
     */
    public $backoff;

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

        if (! isset($this->backoff) && ! method_exists($this, 'backoff') && ! is_null($backoff = config('scout.jobs.backoff'))) {
            $this->backoff = $backoff;
        }
    }
}
