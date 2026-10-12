<?php

namespace Laravel\Scout\Engines;

use Closure;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Attributes\SearchUsingPrefix;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase;
use Laravel\Scout\Contracts\SupportsSemanticSearch;
use Laravel\Scout\Engines\Concerns\PaginatesDatabaseVectorSearch;
use Laravel\Scout\Engines\Concerns\PerformsDatabaseVectorSearch;
use ReflectionMethod;

class DatabaseEngine extends Engine implements PaginatesEloquentModelsUsingDatabase, SupportsSemanticSearch
{
    use PaginatesDatabaseVectorSearch;
    use PerformsDatabaseVectorSearch;

    /**
     * The pg_trgm extension availability, keyed by connection name, database, and search path.
     *
     * @var array<string, bool>
     */
    protected static $trigramExtensionInstalled = [];

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
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function update($models)
    {
        $this->updateSearchableEmbeddings($models);
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        //
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        if ($this->shouldPerformHybridSearch($builder)) {
            $models = $this->hybridSearchModels(
                $builder,
                min((int) ($builder->limit ?? 1000), 1000)
            );

            return [
                'results' => $models,
                'total' => $models->count(),
            ];
        }

        $models = $this->searchModels($builder);

        return [
            'results' => $models,
            'total' => $models->count(),
        ];
    }

    /**
     * Get the Eloquent models for the given builder.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int|null  $page
     * @param  int|null  $perPage
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function searchModels(Builder $builder, $page = null, $perPage = null)
    {
        if ($builder->semanticSearch) {
            return $this->buildSemanticSearchQuery($builder)
                ->when(! is_null($page) && ! is_null($perPage), function ($query) use ($page, $perPage) {
                    $query->forPage($page, $perPage);
                })
                ->get();
        }

        return $this->searchWithTrigramFallback($builder, function (Builder $builder) use ($page, $perPage) {
            return $this->orderSearchQuery($builder, $this->buildSearchQuery($builder))
                ->when(! is_null($page) && ! is_null($perPage), function ($query) use ($page, $perPage) {
                    $query->forPage($page, $perPage);
                })
                ->get();
        }, fn ($models) => $models->isEmpty());
    }

    /**
     * Paginate the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->paginateUsingDatabase($builder, $perPage, 'page', $page);
    }

    /**
     * Paginate the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int  $page
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginateUsingDatabase(Builder $builder, $perPage, $pageName, $page)
    {
        if ($this->shouldPerformHybridSearch($builder)) {
            return $this->paginateHybridSearch($builder, $perPage, $pageName, $page);
        }

        if ($builder->semanticSearch) {
            return $this->paginateSemanticSearch($builder, $perPage, $pageName, $page);
        }

        return $this->searchWithTrigramFallback($builder, function (Builder $builder) use ($perPage, $pageName, $page) {
            return $this->orderSearchQuery($builder, $this->buildSearchQuery($builder))
                ->paginate($perPage, ['*'], $pageName, $page);
        }, fn ($paginator) => $paginator->total() === 0);
    }

    /**
     * Paginate the given search on the engine using simple pagination.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginate(Builder $builder, $perPage, $page)
    {
        return $this->simplePaginateUsingDatabase($builder, $perPage, 'page', $page);
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int|null  $page
     * @return \Illuminate\Contracts\Pagination\Paginator
     */
    public function simplePaginateUsingDatabase(Builder $builder, $perPage, $pageName, $page)
    {
        if ($this->shouldPerformHybridSearch($builder)) {
            return $this->simplePaginateHybridSearch($builder, $perPage, $pageName, $page);
        }

        if ($builder->semanticSearch) {
            return $this->simplePaginateSemanticSearch($builder, $perPage, $pageName, $page);
        }

        return $this->searchWithTrigramFallback($builder, function (Builder $builder) use ($perPage, $pageName, $page) {
            return $this->orderSearchQuery($builder, $this->buildSearchQuery($builder))
                ->simplePaginate($perPage, ['*'], $pageName, $page);
        }, fn ($paginator) => $paginator->isEmpty() &&
            ($paginator->onFirstPage() || ! $this->buildSearchQuery($builder)->exists()));
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

        return $this->constrainForSoftDeletes(
            $builder, $this->addAdditionalConstraints($builder, $query->take($builder->limit))
        );
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
        $query = $this->newSearchQuery($builder);

        return $this->addTextSearchConstraints(
            $query, $builder, $columns, $prefixColumns, $fullTextColumns
        );
    }

    /**
     * Create the model query used for a Scout search.
     */
    protected function newSearchQuery(Builder $builder)
    {
        return method_exists($builder->model, 'newScoutQuery')
            ? $builder->model->newScoutQuery($builder)
            : $builder->model->newQuery();
    }

    /**
     * Add text search constraints to the given query.
     */
    protected function addTextSearchConstraints($query, Builder $builder, array $columns, array $prefixColumns = [], array $fullTextColumns = [])
    {
        if (method_exists($builder->model, 'toSearchableEmbedding')) {
            $embeddingColumn = $this->embeddingColumn($builder->model);

            $columns = array_values(array_diff($columns, [$embeddingColumn]));
            $prefixColumns = array_values(array_diff($prefixColumns, [$embeddingColumn]));
            $fullTextColumns = array_values(array_diff($fullTextColumns, [$embeddingColumn]));
        }

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

            $trigramColumns = $this->getTrigramColumns($builder);

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

            // Laravel's grammar swaps custom text search configs for "english", so match stored vectors ourselves...
            if (count($fullTextColumns) > 0 && $this->searchesStoredVectors($builder)) {
                foreach ($fullTextColumns as $column) {
                    $query->orWhereRaw(
                        sprintf('%s @@ %s', $this->wrapColumn($builder, $column), $this->tsQueryExpression($builder)),
                        $this->tsQueryBindings($builder)
                    );
                }
            } elseif (count($fullTextColumns) > 0) {
                $query->orWhereFullText(
                    array_map(fn ($column) => $builder->model->qualifyColumn($column), $fullTextColumns),
                    $builder->query,
                    $this->getFullTextOptions($builder)
                );
            }

            foreach ($trigramColumns as $column) {
                $query->orWhereRaw($this->wrapColumn($builder, $column).' % ?', [$builder->query]);
            }
        });
    }

    /**
     * Run the given search, retrying with trigram matching when it finds nothing.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Closure  $search
     * @param  \Closure  $isEmpty
     * @return mixed
     */
    protected function searchWithTrigramFallback(Builder $builder, Closure $search, Closure $isEmpty)
    {
        $results = $search($builder);

        if (! $this->shouldFallbackToTrigram($builder) || ! $isEmpty($results)) {
            return $results;
        }

        // Retrying in "blend" mode adds the trigram matches the first search left out...
        return $search((clone $builder)->options(array_merge($builder->options, ['trigram_mode' => 'blend'])));
    }

    /**
     * Determine if an empty search should be retried using trigram matching.
     */
    protected function shouldFallbackToTrigram(Builder $builder): bool
    {
        $options = $this->getFullTextOptions($builder);

        return ($options['trigram_mode'] ?? 'fallback') === 'fallback' &&
            $this->trigramIsSupported($builder);
    }

    /**
     * Get the columns that should be matched using trigram similarity for the current search.
     */
    protected function getTrigramColumns(Builder $builder): array
    {
        $options = $this->getFullTextOptions($builder);

        if (($options['trigram_mode'] ?? 'fallback') === 'fallback') {
            return [];
        }

        return $this->trigramIsSupported($builder) ? Arr::wrap($options['trigram']) : [];
    }

    /**
     * Determine if trigram matching is configured and usable for the given search.
     */
    protected function trigramIsSupported(Builder $builder): bool
    {
        return ! blank($builder->query) &&
            ! empty($this->getFullTextOptions($builder)['trigram']) &&
            $this->tsQueryFunction($builder) === 'plainto_tsquery' &&
            $builder->modelConnectionType() === 'pgsql' &&
            $this->trigramExtensionIsInstalled($builder);
    }

    /**
     * Determine if the pg_trgm functions are reachable on the model's connection search path.
     */
    protected function trigramExtensionIsInstalled(Builder $builder): bool
    {
        $connection = $builder->model->getConnection();

        $key = implode('|', [
            $connection->getName(),
            $connection->getDatabaseName(),
            json_encode($connection->getConfig('search_path') ?? $connection->getConfig('schema')),
        ]);

        return static::$trigramExtensionInstalled[$key] ??= tap(
            (bool) $connection->selectOne("select to_regprocedure('similarity(text,text)') is not null as installed")->installed,
            function ($installed) use ($connection) {
                if (! $installed) {
                    Log::warning("Scout trigram search is disabled because the pg_trgm extension is not available on the [{$connection->getName()}] connection's search path.");
                }
            }
        );
    }

    /**
     * Determine if the full-text columns are stored tsvector columns (Postgres only).
     */
    protected function searchesStoredVectors(Builder $builder): bool
    {
        return ! empty($this->getFullTextOptions($builder)['vector']) && $builder->modelConnectionType() === 'pgsql';
    }

    /**
     * Get the tsquery function for the configured full-text mode.
     */
    protected function tsQueryFunction(Builder $builder): string
    {
        return $this->tsQueryFunctionFor($this->getFullTextOptions($builder));
    }

    /**
     * Get the tsquery function for the given full-text options.
     */
    protected function tsQueryFunctionFor(array $options): string
    {
        return match ($options['mode'] ?? null) {
            'phrase' => 'phraseto_tsquery',
            'websearch' => 'websearch_to_tsquery',
            'raw' => 'to_tsquery',
            default => 'plainto_tsquery',
        };
    }

    /**
     * Get the tsquery SQL expression for the search.
     */
    protected function tsQueryExpression(Builder $builder): string
    {
        return $this->tsQueryFunction($builder).'(?::regconfig, ?)';
    }

    /**
     * Get the bindings for the tsquery SQL expression.
     */
    protected function tsQueryBindings(Builder $builder): array
    {
        return [$this->getFullTextOptions($builder)['language'] ?? 'english', $builder->query];
    }

    /**
     * Qualify and wrap the given column for use in raw SQL.
     */
    protected function wrapColumn(Builder $builder, string $column): string
    {
        return $builder->model->getConnection()->getQueryGrammar()->wrap($builder->model->qualifyColumn($column));
    }

    /**
     * Add the ordering clauses to the search query.
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

            $query->orderBy($builder->model->getQualifiedKeyName(), 'desc');
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
        $options = $this->getFullTextOptions($builder);

        // Per-query options skip validation, so never interpolate the raw value...
        $rank = ($options['rank'] ?? null) === 'ts_rank_cd' ? 'ts_rank_cd' : 'ts_rank';

        $isVector = ! empty($options['vector']);

        $vectors = collect($this->getFullTextColumns($builder))->map(function ($column) use ($builder, $isVector) {
            return sprintf($isVector ? "coalesce(%s, ''::tsvector)" : "coalesce(to_tsvector(?::regconfig, %s), ''::tsvector)", $this->wrapColumn($builder, $column));
        })->implode(' || ');
        $bindings = $isVector ? [] : array_fill(0, count($this->getFullTextColumns($builder)), $options['language'] ?? 'english');

        $score = sprintf('%s(%s, %s)', $rank, $vectors, $this->tsQueryExpression($builder));
        $bindings = array_merge($bindings, $this->tsQueryBindings($builder));

        if ($trigramColumns = $this->getTrigramColumns($builder)) {
            $score = sprintf(
                '(%s * %F) + (coalesce(greatest(%s), 0) * %F)',
                $score,
                (float) ($options['weights']['rank'] ?? 1.0),
                collect($trigramColumns)->map(fn ($column) => sprintf('similarity(%s, ?)', $this->wrapColumn($builder, $column)))->implode(', '),
                (float) ($options['weights']['trigram'] ?? 0.25),
            );
            $bindings = array_merge($bindings, array_fill(0, count($trigramColumns), $builder->query));
        }

        return $query->orderByRaw($score.' desc', $bindings);
    }

    /**
     * Add additional, developer defined constraints to the search query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function addAdditionalConstraints(Builder $builder, $query)
    {
        return $query->when(! is_null($builder->callback), function ($query) use ($builder) {
            call_user_func($builder->callback, $query, $builder, $builder->query);
        })->when(! $builder->callback && count($builder->wheres) > 0, function ($query) use ($builder) {
            foreach ($builder->wheres as $where) {
                if ($where['field'] !== '__soft_deleted') {
                    $query->where($where['field'], $where['operator'], $where['value']);
                }
            }
        })->when(! $builder->callback && count($builder->whereIns) > 0, function ($query) use ($builder) {
            foreach ($builder->whereIns as $key => $values) {
                $query->whereIn($key, $values);
            }
        })->when(! $builder->callback && count($builder->whereNotIns) > 0, function ($query) use ($builder) {
            foreach ($builder->whereNotIns as $key => $values) {
                $query->whereNotIn($key, $values);
            }
        })->when(! is_null($builder->queryCallback), function ($query) use ($builder) {
            call_user_func($builder->queryCallback, $query);
        });
    }

    /**
     * Ensure that soft delete constraints are properly applied to the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function constrainForSoftDeletes($builder, $query)
    {
        $softDeleteWhere = collect($builder->wheres)->firstWhere('field', '__soft_deleted');

        if ($softDeleteWhere && $softDeleteWhere['value'] === 0) {
            return $query->withoutTrashed();
        } elseif ($softDeleteWhere && $softDeleteWhere['value'] === 1) {
            return $query->onlyTrashed();
        } elseif (in_array(SoftDeletes::class, class_uses_recursive(get_class($builder->model))) &&
                  config('scout.soft_delete', false)) {
            return $query->withTrashed();
        }

        return $query;
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

        if ($builder->modelConnectionType() === 'pgsql') {
            $this->ensureValidFullTextOptions($options);
        }

        return array_merge($options, $builder->options);
    }

    /**
     * Ensure the given PostgreSQL full-text options are supported.
     *
     * @throws \InvalidArgumentException
     */
    protected function ensureValidFullTextOptions(array $options): void
    {
        if (isset($options['rank']) && ! in_array($options['rank'], ['ts_rank', 'ts_rank_cd'], true)) {
            throw new InvalidArgumentException('Invalid full-text search option: [rank] must be one of [ts_rank, ts_rank_cd].');
        }

        if (isset($options['trigram_mode']) && ! in_array($options['trigram_mode'], ['fallback', 'blend'], true)) {
            throw new InvalidArgumentException('Invalid full-text search option: [trigram_mode] must be one of [fallback, blend].');
        }

        foreach ($options['weights'] ?? [] as $key => $weight) {
            if (! in_array($key, ['rank', 'trigram'], true) || ! is_numeric($weight)) {
                throw new InvalidArgumentException('Invalid full-text search option: [weights] accepts numeric [rank] and [trigram] values.');
            }
        }

        if (! empty($options['trigram']) && $this->tsQueryFunctionFor($options) !== 'plainto_tsquery') {
            throw new InvalidArgumentException('Invalid full-text search option: [trigram] requires the "plain" [mode].');
        }
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        $results = $results['results'];

        return count($results) > 0
            ? collect($results->modelKeys())
            : collect();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        return $results['results'];
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        return new LazyCollection($results['results']->all());
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['total'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        //
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @param  array  $options
     * @return mixed
     *
     * @throws \Exception
     */
    public function createIndex($name, array $options = [])
    {
        //
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     */
    public function deleteIndex($name)
    {
        //
    }
}
