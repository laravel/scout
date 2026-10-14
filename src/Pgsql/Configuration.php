<?php

namespace Laravel\Scout\Pgsql;

use InvalidArgumentException;

class Configuration
{
    /**
     * Create a new PostgreSQL configuration reader instance.
     *
     * @param  array  $config
     * @param  string  $context
     * @return void
     */
    public function __construct(protected array $config, protected string $context)
    {
        //
    }

    /**
     * Get the configured text search language.
     *
     * @return string
     */
    public function language()
    {
        $language = $this->config['language'] ?? 'english';

        if (! is_string($language) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $language) !== 1) {
            throw new InvalidArgumentException("The [pgsql] Scout {$this->context} language must be a valid PostgreSQL text search configuration name.");
        }

        return $language;
    }

    /**
     * Get the configured search vector column.
     *
     * @return string
     */
    public function vectorColumn()
    {
        $column = $this->config['vector_column'] ?? 'search_vector';

        if (! static::isColumnName($column)) {
            throw new InvalidArgumentException("The [pgsql] Scout {$this->context} vector column must be a valid column name.");
        }

        return $column;
    }

    /**
     * Get a validated column name.
     *
     * @param  mixed  $column
     * @param  string  $type
     * @return string
     */
    public function column($column, $type)
    {
        if (! static::isColumnName($column)) {
            throw new InvalidArgumentException(sprintf('The [pgsql] Scout %s %s column [%s] must be a valid column name.', $this->context, $type, $column));
        }

        return $column;
    }

    /**
     * Determine if the given value is a valid unqualified PostgreSQL column name.
     *
     * @param  mixed  $value
     * @return bool
     */
    public static function isColumnName($value)
    {
        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }
}
