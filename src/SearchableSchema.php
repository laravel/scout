<?php

namespace Laravel\Scout;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class SearchableSchema
{
    /**
     * Register the "searchable" schema blueprint macros.
     *
     * @return void
     */
    public static function register()
    {
        if (! method_exists(Grammar::class, 'typeTsvector')) {
            Grammar::macro('typeTsvector', function (Fluent $column) {
                SearchableSchema::ensurePostgres($this);

                return 'tsvector';
            });
        }

        Grammar::macro('compileScoutTrigramExtension', function () {
            SearchableSchema::ensurePostgres($this);

            return 'create extension if not exists pg_trgm';
        });

        Blueprint::macro('searchable', function (array $columns, string $language = 'english', array $trigram = [], string $tsvector = 'search_vector') {
            $this->addColumn('tsvector', $tsvector)->storedAs(
                SearchableSchema::vectorExpression($columns, $language)
            );

            $this->index($tsvector, null, 'gin');

            if ($trigram === []) {
                return;
            }

            // @phpstan-ignore-next-line
            $this->addCommand('scoutTrigramExtension');

            foreach ($trigram as $column) {
                SearchableSchema::ensureValidIdentifier($column);

                // @phpstan-ignore-next-line
                $this->rawIndex("\"{$column}\" gin_trgm_ops", $this->createIndexName('trigram', [$column]))->algorithm('gin');
            }
        });

        Blueprint::macro('dropSearchable', function (array $trigram = [], string $tsvector = 'search_vector') {
            $table = $this->getTable();
            $schema = str_contains($table, '.') ? Str::beforeLast($table, '.').'.' : '';

            foreach ($trigram as $column) {
                // @phpstan-ignore-next-line
                $this->dropIndex($schema.$this->createIndexName('trigram', [$column]));
            }

            $this->dropColumn($tsvector);
        });
    }

    /**
     * Build the weighted tsvector expression for the given columns.
     *
     * @internal
     *
     * @param  array<int|string, string>  $columns  Column names, optionally keyed by column with an A-D weight value.
     */
    public static function vectorExpression(array $columns, string $language): string
    {
        if ($columns === []) {
            throw new InvalidArgumentException('At least one searchable column is required.');
        }

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $language)) {
            throw new InvalidArgumentException("Invalid full-text search language [{$language}].");
        }

        return collect($columns)->map(function ($weight, $column) use ($language) {
            [$column, $weight] = is_int($column) ? [$weight, 'D'] : [$column, strtoupper($weight)];

            static::ensureValidIdentifier($column);

            if (! in_array($weight, ['A', 'B', 'C', 'D'], true)) {
                throw new InvalidArgumentException("Invalid full-text weight [{$weight}] for column [{$column}]. Use A, B, C, or D.");
            }

            return "setweight(to_tsvector('{$language}', coalesce(\"{$column}\"::text, '')), '{$weight}')";
        })->implode(' || ');
    }

    /**
     * Ensure the given column name is a plain identifier.
     *
     * @internal
     */
    public static function ensureValidIdentifier(string $column): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new InvalidArgumentException("Invalid searchable column [{$column}].");
        }
    }

    /**
     * Ensure the given grammar targets PostgreSQL.
     *
     * @internal
     */
    public static function ensurePostgres($grammar): void
    {
        if (! $grammar instanceof PostgresGrammar) {
            throw new RuntimeException('The searchable() schema helper requires PostgreSQL.');
        }
    }
}
