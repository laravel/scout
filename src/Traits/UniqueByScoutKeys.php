<?php

namespace Laravel\Scout\Traits;

use Illuminate\Database\Eloquent\Model;

trait UniqueByScoutKeys
{
    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 3600;

    /**
     * Get the unique identifier for the job.
     *
     * @return string
     */
    public function uniqueId()
    {
        return md5(json_encode([
            $this->models->getQueueableClass(),
            $this->models->map(function (Model $model) {
                return $model->getScoutKey();
            })->sort()->values()->all(),
        ]));
    }
}
