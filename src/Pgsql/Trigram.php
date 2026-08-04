<?php

namespace Laravel\Scout\Pgsql;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Laravel\Scout\Builder;

class Trigram
{
    /**
     * The warning emitted when pg_trgm is unavailable.
     */
    protected const MISSING_EXTENSION_WARNING = 'Scout [pgsql] trigram search is enabled, but the [pg_trgm] extension is not available. Falling back to PostgreSQL full-text search.';

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
        } catch (QueryException) {
            Log::warning(self::MISSING_EXTENSION_WARNING);

            return $this->availability[$key] = false;
        }

        $available = (bool) ($result->available ?? false);

        if (! $available) {
            Log::warning(self::MISSING_EXTENSION_WARNING);
        }

        return $this->availability[$key] = $available;
    }

    /**
     * Get the current trigram threshold for the connection.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return string|null
     */
    public function currentThreshold(Builder $builder)
    {
        $result = $builder->model->getConnection()->selectOne(
            "select current_setting('pg_trgm.similarity_threshold', true) as threshold"
        );

        return $result->threshold ?? null;
    }

    /**
     * Apply the configured trigram threshold for the connection.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return void
     */
    public function applyThreshold(Builder $builder)
    {
        $builder->model->getConnection()->select(
            "select set_config('pg_trgm.similarity_threshold', ?::text, false)",
            [$this->threshold()]
        );
    }

    /**
     * Restore the previous trigram threshold for the connection.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  string|null  $threshold
     * @return void
     */
    public function restoreThreshold(Builder $builder, $threshold)
    {
        if (is_null($threshold)) {
            return;
        }

        $builder->model->getConnection()->select(
            "select set_config('pg_trgm.similarity_threshold', ?::text, false)",
            [$threshold]
        );
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
     * Get the indexable trigram predicate for the query.
     *
     * @param  array  $columns
     * @return string
     */
    public function predicateExpression(array $columns)
    {
        return empty($columns) ? 'false' : sprintf(
            '(%s)',
            collect($columns)->map(fn ($column) => sprintf('%s %% ?', $column))->implode(' or ')
        );
    }

    /**
     * Get the indexable trigram predicate bindings for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $columns
     * @return array
     */
    public function predicateBindings(Builder $builder, array $columns)
    {
        return array_fill(0, count($columns), $builder->query);
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
        $threshold = $this->config['trigram']['threshold'] ?? 0.3;

        if (! is_numeric($threshold) || $threshold < 0 || $threshold > 1) {
            throw new InvalidArgumentException('The [pgsql] Scout driver trigram threshold must be numeric and between 0 and 1.');
        }

        return $threshold;
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
        $weight = $this->config['weights'][$key] ?? $default;

        if (! is_numeric($weight)) {
            throw new InvalidArgumentException(sprintf('The [pgsql] Scout driver score weight [%s] must be numeric.', $key));
        }

        return $weight;
    }
}
