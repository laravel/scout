<?php

namespace Laravel\Scout;

use Illuminate\Database\Eloquent\SoftDeletes;

class SearchableProxy
{
    /**
     * The model instance
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    private $model;

    /**
     * Callback before database query execution
     *
     * @var \Closure
     */
    private $callback;

    /**
     * Create the SearchableProxy
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param \Closure $callback
     */
    public function __construct($model, $callback)
    {
        $this->model = $model;
        $this->callback = $callback;
    }

    /**
     * Get the requested models from an array of object IDs;
     *
     * @param  array  $ids
     * @return mixed
     */
    public function getScoutModelsByIds(array $ids)
    {
        $builder = in_array(SoftDeletes::class, class_uses_recursive($this->model))
            ? $this->withTrashed() : $this->newQuery();

        call_user_func($this->callback, $builder);

        return $builder->whereIn(
            $this->getScoutKeyName(), $ids
        )->get();
    }

    /**
     * Determine if an attribute exists on the model.
     *
     * @param  string  $key
     * @return bool
     */
    public function __isset($key)
    {
        return isset($this->model->{$key});
    }

    /**
     * Unset an attribute on the model.
     *
     * @param  string  $key
     * @return void
     */
    public function __unset($key)
    {
        unset($this->model->{$key});
    }

    /**
     * Dynamically get properties from the underlying model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->model->{$key};
    }

    /**
     * Dynamically pass method calls to the underlying model.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->model->{$method}(...$parameters);
    }
}
