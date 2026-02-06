<?php

namespace Laravel\Scout\Traits;

trait ConfiguresJobOptions
{
    /**
     * Configure the job.
     *
     * @return void
     */
    protected function configureJob(): void
    {
        if (! isset($this->tries) && ! is_null($tries = config('scout.jobs.tries'))) {
            /** @phpstan-ignore property.notFound */
            $this->tries = $tries;
        }

        if (! isset($this->backoff) &&
            ! method_exists($this, 'backoff') &&
            ! is_null($backoff = config('scout.jobs.backoff'))) {
            /** @phpstan-ignore property.notFound */
            $this->backoff = $backoff;
        }

        if (! isset($this->maxExceptions) &&
            ! is_null($maxExceptions = config('scout.jobs.max_exceptions'))) {
            /** @phpstan-ignore property.notFound */
            $this->maxExceptions = $maxExceptions;
        }
    }
}
