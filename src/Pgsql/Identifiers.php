<?php

namespace Laravel\Scout\Pgsql;

class Identifiers
{
    /**
     * Determine if the given value is a safe PostgreSQL column name.
     *
     * @param  mixed  $value
     * @return bool
     */
    public function isColumnName($value)
    {
        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }

    /**
     * Determine if the given value is a safe PostgreSQL configuration name.
     *
     * @param  mixed  $value
     * @return bool
     */
    public function isConfigurationName($value)
    {
        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $value) === 1;
    }
}
