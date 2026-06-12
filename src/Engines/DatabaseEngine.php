<?php

namespace Laravel\Scout\Engines;

use Illuminate\Support\Arr;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Builder;
use ReflectionMethod;

class DatabaseEngine extends DatabaseModelEngine
{
    /**
     * Create a new engine instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Initialize / build the search query for the given Scout builder.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildSearchQuery(Builder $builder)
    {
        $query = $this->initializeSearchQuery(
            $builder,
            array_keys($builder->model->toSearchableArray()),
            $this->getPrefixColumns($builder),
            $this->getFullTextColumns($builder)
        );

        return $this->finalizeSearchQuery($builder, $query);
    }

    /**
     * Build the initial text search database query for all relevant columns.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $columns
     * @param  array  $prefixColumns
     * @param  array  $fullTextColumns
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function initializeSearchQuery(Builder $builder, array $columns, array $prefixColumns = [], array $fullTextColumns = [])
    {
        $query = $this->newModelQuery($builder);

        if (blank($builder->query)) {
            return $query;
        }

        [$connectionType] = [
            $builder->modelConnectionType(),
        ];

        return $query->where(function ($query) use ($connectionType, $builder, $columns, $prefixColumns, $fullTextColumns) {
            $canSearchPrimaryKey = ctype_digit($builder->query) &&
                                   in_array($builder->model->getKeyType(), ['int', 'integer']) &&
                                   ($connectionType != 'pgsql' || $builder->query <= PHP_INT_MAX) &&
                                   in_array($builder->model->getScoutKeyName(), $columns);

            if ($canSearchPrimaryKey) {
                $query->orWhere($builder->model->getQualifiedKeyName(), $builder->query);
            }

            $likeOperator = $connectionType == 'pgsql' ? 'ilike' : 'like';

            foreach ($columns as $column) {
                if (in_array($column, $fullTextColumns)) {
                    continue;
                } else {
                    if ($canSearchPrimaryKey && $column === $builder->model->getScoutKeyName()) {
                        continue;
                    }

                    $query->orWhere(
                        $builder->model->qualifyColumn($column),
                        $likeOperator,
                        in_array($column, $prefixColumns) ? $builder->query.'%' : '%'.$builder->query.'%',
                    );
                }
            }

            if (count($fullTextColumns) > 0) {
                $query->orWhereFullText(
                    array_map(fn ($column) => $builder->model->qualifyColumn($column), $fullTextColumns),
                    $builder->query,
                    $this->getFullTextOptions($builder)
                );
            }
        });
    }

    /**
     * Add ordering to the search query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function orderSearchQuery(Builder $builder, $query)
    {
        return $query->when($builder->orders, function ($query) use ($builder) {
            foreach ($builder->orders as $order) {
                $query->orderBy($order['column'], $order['direction']);
            }
        })->when(! $this->getFullTextColumns($builder), function ($query) use ($builder) {
            $query->orderBy($builder->model->getTable().'.'.$builder->model->getScoutKeyName(), 'desc');
        })->when($this->shouldOrderByRelevance($builder), function ($query) use ($builder) {
            $this->orderByRelevance($builder, $query);
        });
    }

    /**
     * Determine if the query should be ordered by relevance.
     */
    protected function shouldOrderByRelevance(Builder $builder): bool
    {
        // MySQL orders by relevance by default, so we will only order by relevance on
        // Postgres with no developer-defined orders. If there is developer defined
        // order by clauses we will let those take precedence over the relevance.
        return $builder->modelConnectionType() === 'pgsql' &&
            count($this->getFullTextColumns($builder)) > 0 &&
            empty($builder->orders);
    }

    /**
     * Add an "order by" clause that orders by relevance (Postgres only).
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function orderByRelevance(Builder $builder, $query)
    {
        $fullTextColumns = $this->getFullTextColumns($builder);

        $language = $this->getFullTextOptions($builder)['language'] ?? 'english';

        $vectors = collect($fullTextColumns)->map(function ($column) use ($builder, $language) {
            return sprintf("to_tsvector('%s', %s)", $language, $builder->model->qualifyColumn($column));
        })->implode(' || ');

        return $query->orderByRaw(
            sprintf(
                'ts_rank('.$vectors.', %s(?)) desc',
                match ($this->getFullTextOptions($builder)['mode'] ?? 'plainto_tsquery') {
                    'phrase' => 'phraseto_tsquery',
                    'websearch' => 'websearch_to_tsquery',
                    default => 'plainto_tsquery',
                },
            ),
            [$builder->query]
        );
    }

    /**
     * Get the full-text columns for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function getFullTextColumns(Builder $builder)
    {
        return $this->getAttributeColumns($builder, SearchUsingFullText::class);
    }

    /**
     * Get the prefix search columns for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function getPrefixColumns(Builder $builder)
    {
        return $this->getAttributeColumns($builder, SearchUsingPrefix::class);
    }

    /**
     * Get the columns marked with a given attribute.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  string  $attributeClass
     * @return array
     */
    protected function getAttributeColumns(Builder $builder, $attributeClass)
    {
        $columns = [];

        foreach ((new ReflectionMethod($builder->model, 'toSearchableArray'))->getAttributes() as $attribute) {
            if ($attribute->getName() !== $attributeClass) {
                continue;
            }

            $columns = array_merge($columns, Arr::wrap($attribute->getArguments()[0]));
        }

        return $columns;
    }

    /**
     * Get the full-text search options for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function getFullTextOptions(Builder $builder)
    {
        $options = [];

        foreach ((new ReflectionMethod($builder->model, 'toSearchableArray'))->getAttributes(SearchUsingFullText::class) as $attribute) {
            $arguments = $attribute->getArguments()[1] ?? [];

            $options = array_merge($options, Arr::wrap($arguments));
        }

        return $options;
    }
}
