<?php

namespace Laravel\Scout\Pgsql;

use Laravel\Scout\Builder;
use Throwable;

class Trigram
{
    /**
     * The cached pg_trgm availability results.
     *
     * @var array
     */
    protected array $availability = [];

    /**
     * Create a new trigram helper instance.
     *
     * @param  array  $config
     * @return void
     */
    public function __construct(protected array $config)
    {
        //
    }

    /**
     * Determine if trigram search should be used for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return bool
     */
    public function uses(Builder $builder)
    {
        return filled($builder->query) &&
            (bool) ($this->config['trigram']['enabled'] ?? false) &&
            $this->available($builder);
    }

    /**
     * Determine if the pg_trgm extension is available.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return bool
     */
    public function available(Builder $builder)
    {
        $connection = $builder->model->getConnection();

        $key = spl_object_id($connection);

        if (array_key_exists($key, $this->availability)) {
            return $this->availability[$key];
        }

        try {
            $result = $connection->selectOne(
                "select exists (select 1 from pg_extension where extname = 'pg_trgm') as available"
            );
        } catch (Throwable) {
            return $this->availability[$key] = false;
        }

        return $this->availability[$key] = (bool) ($result->available ?? false);
    }

    /**
     * Get the trigram similarity expression for the query.
     *
     * @param  array  $columns
     * @return string
     */
    public function similarityExpression(array $columns)
    {
        if (empty($columns)) {
            return '0';
        }

        return sprintf('greatest(%s)', collect($columns)->map(function ($column) {
            return sprintf("similarity(coalesce(cast(%s as text), ''), ?)", $column);
        })->implode(', '));
    }

    /**
     * Get the trigram similarity bindings for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $columns
     * @return array
     */
    public function similarityBindings(Builder $builder, array $columns)
    {
        return array_fill(0, count($columns), $builder->query);
    }

    /**
     * Get the configured trigram threshold.
     *
     * @return float|int|string
     */
    public function threshold()
    {
        return $this->config['trigram']['threshold'] ?? 0.3;
    }

    /**
     * Get the configured score weight.
     *
     * @param  string  $key
     * @param  float|int  $default
     * @return float|int|string
     */
    public function scoreWeight($key, $default)
    {
        return $this->config['weights'][$key] ?? $default;
    }
}
