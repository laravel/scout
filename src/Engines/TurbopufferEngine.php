<?php

namespace Laravel\Scout\Engines;

use BackedEnum;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\SupportsSemanticSearch;
use Laravel\Scout\Exceptions\NotSupportedException;
use Laravel\Scout\Exceptions\ScoutException;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Laravel\Scout\Services\Turbopuffer\TurbopufferClient;

class TurbopufferEngine extends Engine implements SupportsSemanticSearch
{
    /**
     * Create a new Turbopuffer engine instance.
     */
    public function __construct(
        protected TurbopufferClient $turbopuffer,
        protected array $config = [],
        protected bool $softDelete = false
    ) {
        //
    }

    /**
     * Update the given models in the index.
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $model = $models->first();

        if ($this->usesSoftDelete($model) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $records = $models->map(function ($model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return;
            }

            return [
                'model' => $model,
                'row' => array_merge(
                    $searchableData,
                    $model->scoutMetadata(),
                    ['id' => $model->getScoutKey()],
                ),
            ];
        })->filter()->values()->all();

        if (empty($records)) {
            return;
        }

        $settings = $this->modelSettings($model);

        $embeddingSettings = isset($settings['embedding'])
            ? $this->embeddingSettings($model)
            : null;

        $rows = $embeddingSettings && ! $this->usesNativeEmbeddings($embeddingSettings)
            ? $this->addEmbeddingsToRecords($records, $embeddingSettings)
            : array_column($records, 'row');

        if ($embeddingSettings && $this->usesNativeEmbeddings($embeddingSettings)) {
            $rows = array_map(function ($row) use ($embeddingSettings) {
                unset($row[$embeddingSettings['generated_attribute']]);

                return $row;
            }, $rows);
        }

        $parameters = ['upsert_rows' => $rows];

        foreach (['schema', 'distance_metric'] as $option) {
            if (isset($settings[$option])) {
                $parameters[$option] = $settings[$option];
            }
        }

        if ($embeddingSettings && $this->usesNativeEmbeddings($embeddingSettings)) {
            $embed = &$parameters['schema'][$embeddingSettings['attribute']]['embed'];

            if (is_array($embed) && isset($embed['dimensions'])) {
                $embed['dims'] = (int) $embed['dimensions'];

                unset($embed['dimensions']);
            }
        }

        if (isset($settings['embedding']) && ! isset($parameters['distance_metric'])) {
            $parameters['distance_metric'] = 'cosine_distance';
        }

        $this->turbopuffer->namespace($model->indexableAs())->write($parameters);
    }

    /**
     * Add generated embeddings to searchable records.
     */
    protected function addEmbeddingsToRecords(array $records, array $settings): array
    {
        $settings = $this->validateEmbeddingSettings($settings);

        $rows = [];

        foreach (array_chunk($records, 100) as $batch) {
            $inputs = [];
            $vectors = [];

            foreach ($batch as $index => $record) {
                if (! method_exists($record['model'], 'toSearchableEmbedding')) {
                    throw new ScoutException('Searchable models using generated embeddings must define a [toSearchableEmbedding] method.');
                }

                $input = $record['model']->toSearchableEmbedding();

                if (is_array($input)) {
                    $vectors[$index] = $input;

                    continue;
                }

                if (! is_string($input) || trim($input) === '') {
                    throw new ScoutException('The [toSearchableEmbedding] method must return a non-empty string or an embedding array.');
                }

                $inputs[$index] = $input;
            }

            if (! empty($inputs)) {
                $generatedVectors = $this->generateEmbeddings(array_values($inputs), $settings);

                foreach (array_keys($inputs) as $position => $index) {
                    $vectors[$index] = $generatedVectors[$position];
                }
            }

            foreach ($batch as $index => $record) {
                $record['row'][$settings['attribute']] = $vectors[$index];
                $rows[] = $record['row'];
            }
        }

        return $rows;
    }

    /**
     * Remove the given models from the index.
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $keys = $models instanceof RemoveableScoutCollection
            ? $models->pluck($models->first()->getScoutKeyName())
            : $models->map->getScoutKey();

        $this->turbopuffer
            ->namespace($models->first()->indexableAs())
            ->write(['deletes' => $keys->values()->all()]);
    }

    /**
     * Perform the given search on the engine.
     */
    public function search(Builder $builder)
    {
        return $this->performSearch(
            $builder,
            $builder->limit ?? $builder->model->getPerPage()
        );
    }

    /**
     * Perform the given paginated search on the engine.
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);
        $maximum = min((int) ($builder->limit ?? 10000), 10000);
        $window = $page * $perPage;

        if ($window > 10000) {
            throw new ScoutException('Turbopuffer search results may not be paginated beyond 10,000 records.');
        }

        $results = $this->performSearch($builder, min($window, $maximum));

        $results['rows'] = array_slice(
            $results['rows'] ?? [],
            ($page - 1) * $perPage,
            $perPage
        );

        $nativeFilters = $builder->options['filters'] ?? null;

        $filters = $this->combineFilters($nativeFilters, $this->filters($builder));

        $count = $this->namespace($builder)->query(array_filter([
            'aggregate_by' => ['count' => ['Count']],
            'filters' => $filters,
            'consistency' => $builder->options['consistency'] ?? null,
        ], fn ($value) => ! is_null($value)));

        $results['total'] = min((int) ($count['aggregations']['count'] ?? 0), $maximum);

        return $results;
    }

    /**
     * Perform a search against Turbopuffer.
     */
    protected function performSearch(Builder $builder, int $limit): array
    {
        $namespace = $this->namespace($builder);

        $parameters = $this->buildSearchParameters($builder, $limit);

        $results = $builder->callback
            ? call_user_func($builder->callback, $namespace, $builder->query, $parameters)
            : $namespace->query($parameters);

        if (! is_null($builder->hybridSearch)) {
            $results['rows'] = array_slice($results['results'][0]['rows'] ?? [], 0, $limit);
        }

        $results['total'] = count($results['rows'] ?? []);

        return $results;
    }

    /**
     * Build Turbopuffer search parameters for the query.
     */
    public function buildSearchParameters(Builder $builder, int $limit): array
    {
        if (isset($builder->options['queries'])) {
            throw new ScoutException('Turbopuffer multi-query searches are not supported by this Scout engine.');
        }

        if (! is_null($builder->hybridSearch)) {
            return $this->buildHybridSearchParameters($builder, $limit);
        }

        $parameters = $builder->options;
        $nativeFilters = $parameters['filters'] ?? null;
        $scoutFilters = $this->filters($builder);

        unset($parameters['filters']);

        if ($builder->semanticSearch && isset($parameters['rank_by'])) {
            throw new ScoutException('Turbopuffer semantic searches cannot be combined with a custom ranking expression.');
        }

        if (! isset($parameters['rank_by'])) {
            $parameters['rank_by'] = $this->rankBy($builder);
        } elseif (! empty($builder->orders)) {
            throw new ScoutException('Turbopuffer order clauses cannot be combined with a custom ranking expression.');
        }

        if ($filters = $this->combineFilters($nativeFilters, $scoutFilters)) {
            $parameters['filters'] = $filters;
        }

        $parameters = $this->ensureIdIsReturned($parameters);
        $parameters['limit'] = min(max(1, $limit), 10000);

        return $parameters;
    }

    /**
     * Build a hybrid full-text and semantic query.
     */
    protected function buildHybridSearchParameters(Builder $builder, int $limit): array
    {
        if (! empty($builder->orders)) {
            throw new ScoutException('Turbopuffer order clauses cannot be combined with hybrid search.');
        }

        foreach (['rank_by', 'rerank_by'] as $option) {
            if (isset($builder->options[$option])) {
                throw new ScoutException("Turbopuffer hybrid searches cannot be combined with a custom [{$option}] option.");
            }
        }

        $parameters = $builder->options;
        $nativeFilters = $parameters['filters'] ?? null;
        $rootParameters = array_intersect_key($parameters, array_flip(['consistency', 'vector_encoding']));

        unset($parameters['consistency'], $parameters['filters'], $parameters['vector_encoding']);

        if ($filters = $this->combineFilters($nativeFilters, $this->filters($builder))) {
            $parameters['filters'] = $filters;
        }

        $parameters = $this->ensureIdIsReturned($parameters);
        $parameters['limit'] = min(max(1, $limit), 10000);

        return array_merge($rootParameters, [
            'queries' => [
                array_merge($parameters, ['rank_by' => $this->fullTextRankBy($builder)]),
                array_merge($parameters, ['rank_by' => $this->semanticRankBy($builder)]),
            ],
            'rerank_by' => ['RRF', [
                'weights' => [
                    $builder->hybridSearch['text_weight'],
                    $builder->hybridSearch['semantic_weight'],
                ],
            ]],
        ]);
    }

    /**
     * Build the ranking expression for the query.
     */
    protected function rankBy(Builder $builder): array
    {
        if ($builder->semanticSearch) {
            if (! empty($builder->orders)) {
                throw new ScoutException('Turbopuffer order clauses cannot be combined with semantic search.');
            }

            return $this->semanticRankBy($builder);
        }

        if ($builder->query === '' || $builder->query === '*') {
            if (count($builder->orders) > 1) {
                throw new ScoutException('Turbopuffer supports one order clause per search.');
            }

            $order = $builder->orders[0] ?? ['column' => 'id', 'direction' => 'asc'];

            return [$this->field($builder, $order['column']), $order['direction']];
        }

        if (! empty($builder->orders)) {
            throw new ScoutException('Turbopuffer order clauses cannot be combined with full-text search.');
        }

        return $this->fullTextRankBy($builder);
    }

    /**
     * Build the full-text ranking expression for a query.
     */
    protected function fullTextRankBy(Builder $builder): array
    {
        $attributes = $this->modelSettings($builder->model)['searchable-attributes'] ?? [];

        if (empty($attributes)) {
            throw new ScoutException('No Turbopuffer searchable attributes have been configured for ['.get_class($builder->model).'].');
        }

        $expressions = [];

        foreach ($attributes as $key => $value) {
            [$attribute, $weight] = is_int($key) ? [$value, 1] : [$key, $value];

            if (! is_string($attribute) || ! is_numeric($weight) || $weight < 0) {
                throw new ScoutException('Turbopuffer searchable attributes must contain attribute names with non-negative numeric weights.');
            }

            $expression = [$attribute, 'BM25', $builder->query];

            $expressions[] = (float) $weight === 1.0
                ? $expression
                : ['Product', $weight, $expression];
        }

        return count($expressions) === 1
            ? $expressions[0]
            : ['Sum', $expressions];
    }

    /**
     * Build the semantic ranking expression for a query.
     */
    protected function semanticRankBy(Builder $builder): array
    {
        $settings = $this->embeddingSettings($builder->model);

        return [
            $settings['attribute'],
            'ANN',
            $this->usesNativeEmbeddings($settings)
                ? ['Embed', $builder->query]
                : $this->generateEmbeddings([$builder->query], $settings)[0],
        ];
    }

    /**
     * Build filters for the query.
     */
    protected function filters(Builder $builder): ?array
    {
        $filters = [];

        $operators = [
            '=' => 'Eq',
            '!=' => 'NotEq',
            '<' => 'Lt',
            '<=' => 'Lte',
            '>' => 'Gt',
            '>=' => 'Gte',
        ];

        foreach ($builder->wheres as $where) {
            if (! isset($operators[$where['operator']])) {
                throw new ScoutException("The [{$where['operator']}] operator is not supported by the Turbopuffer engine.");
            }

            $filters[] = [
                $this->field($builder, $where['field']),
                $operators[$where['operator']],
                $this->filterValue($where['value']),
            ];
        }

        foreach ($builder->whereIns as $field => $values) {
            $filters[] = [$this->field($builder, $field), 'In', array_map([$this, 'filterValue'], $values)];
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $filters[] = [$this->field($builder, $field), 'NotIn', array_map([$this, 'filterValue'], $values)];
        }

        return match (count($filters)) {
            0 => null,
            1 => $filters[0],
            default => ['And', $filters],
        };
    }

    /**
     * Combine native and Scout filters.
     */
    protected function combineFilters(?array $nativeFilters, ?array $scoutFilters): ?array
    {
        if (is_null($nativeFilters)) {
            return $scoutFilters;
        }

        if (is_null($scoutFilters)) {
            return $nativeFilters;
        }

        return ['And', [$nativeFilters, $scoutFilters]];
    }

    /**
     * Normalize a filter value.
     */
    protected function filterValue($value)
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /**
     * Ensure the Scout key is returned for model hydration.
     */
    protected function ensureIdIsReturned(array $parameters): array
    {
        if (isset($parameters['include_attributes']) && is_array($parameters['include_attributes'])) {
            $parameters['include_attributes'] = array_values(array_unique([
                ...$parameters['include_attributes'],
                'id',
            ]));
        }

        if (isset($parameters['exclude_attributes']) && is_array($parameters['exclude_attributes'])) {
            $parameters['exclude_attributes'] = array_values(array_diff($parameters['exclude_attributes'], ['id']));
        }

        return $parameters;
    }

    /**
     * Resolve a Scout field name to a Turbopuffer field name.
     */
    protected function field(Builder $builder, string $field): string
    {
        return $field === $builder->model->getScoutKeyName() ? 'id' : $field;
    }

    /**
     * Pluck and return the primary keys of the given results.
     */
    public function mapIds($results)
    {
        return collect($results['rows'] ?? [])->pluck('id')->values();
    }

    /**
     * Map the given results to model instances.
     */
    public function map(Builder $builder, $results, $model)
    {
        if (empty($results['rows'])) {
            return $model->newCollection();
        }

        $rows = collect($results['rows']);
        $objectIds = $rows->pluck('id')->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(fn ($model) => in_array($model->getScoutKey(), $objectIds, false))
            ->map(function ($model) use ($rows, $objectIdPositions) {
                $row = $rows[$objectIdPositions[$model->getScoutKey()]];

                if (array_key_exists('$dist', $row)) {
                    $model->withScoutMetadata('_turbopuffer_dist', $row['$dist']);
                }

                return $model;
            })
            ->sortBy(fn ($model) => $objectIdPositions[$model->getScoutKey()])
            ->values();
    }

    /**
     * Map the given results to model instances via a lazy collection.
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        if (empty($results['rows'])) {
            return LazyCollection::make($model->newCollection());
        }

        $rows = collect($results['rows']);
        $objectIds = $rows->pluck('id')->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(fn ($model) => in_array($model->getScoutKey(), $objectIds, false))
            ->map(function ($model) use ($rows, $objectIdPositions) {
                $row = $rows[$objectIdPositions[$model->getScoutKey()]];

                if (array_key_exists('$dist', $row)) {
                    $model->withScoutMetadata('_turbopuffer_dist', $row['$dist']);
                }

                return $model;
            })
            ->sortBy(fn ($model) => $objectIdPositions[$model->getScoutKey()])
            ->values();
    }

    /**
     * Get the total count from the raw results.
     */
    public function getTotalCount($results)
    {
        return (int) ($results['total'] ?? count($results['rows'] ?? []));
    }

    /**
     * Flush all of the model's records from the engine.
     */
    public function flush($model)
    {
        return $this->deleteIndex($model->indexableAs());
    }

    /**
     * Create a search index.
     */
    public function createIndex($name, array $options = [])
    {
        throw new NotSupportedException('Turbopuffer namespaces are created automatically upon adding documents.');
    }

    /**
     * Delete a search index.
     */
    public function deleteIndex($name)
    {
        return $this->turbopuffer->namespace($name)->delete();
    }

    /**
     * Get the configured settings for a model.
     */
    protected function modelSettings($model): array
    {
        return $this->config['model-settings'][get_class($model)] ?? [];
    }

    /**
     * Get the validated embedding settings for a model.
     */
    protected function embeddingSettings($model): array
    {
        $modelSettings = $this->modelSettings($model);

        $settings = $modelSettings['embedding'] ?? null;

        if (! is_array($settings)) {
            throw new ScoutException('No Turbopuffer embedding settings have been configured for ['.get_class($model).'].');
        }

        $settings = $this->validateEmbeddingSettings($settings);

        if ($this->usesNativeEmbeddings($settings)) {
            $schema = $modelSettings['schema'][$settings['attribute']] ?? null;

            if (! is_array($schema) || ($schema['type'] ?? null) !== 'string') {
                throw new ScoutException("Turbopuffer native embeddings require a string schema configuration for the [{$settings['attribute']}] attribute.");
            }

            $settings['generated_attribute'] = $this->validateNativeEmbeddingSchema($settings['attribute'], $schema['embed'] ?? null);
        }

        return $settings;
    }

    /**
     * Validate embedding configuration shared by indexing and querying.
     */
    protected function validateEmbeddingSettings(array $settings): array
    {
        if (! isset($settings['attribute']) || ! is_string($settings['attribute']) || trim($settings['attribute']) === '') {
            throw new ScoutException('Turbopuffer embedding settings must contain an [attribute].');
        }

        $driver = $settings['driver'] ?? 'laravel-ai';

        if (! in_array($driver, ['laravel-ai', 'turbopuffer'], true)) {
            throw new ScoutException("The [{$driver}] Turbopuffer embedding driver is not supported.");
        }

        $settings['driver'] = $driver;

        if ($this->usesNativeEmbeddings($settings)) {
            return $settings;
        }

        if (! isset($settings['dimensions']) ||
            filter_var($settings['dimensions'], FILTER_VALIDATE_INT) === false ||
            $settings['dimensions'] < 1) {
            throw new ScoutException('Turbopuffer embedding settings must contain positive [dimensions].');
        }

        $settings['dimensions'] = (int) $settings['dimensions'];

        return $settings;
    }

    /**
     * Determine if Turbopuffer should generate embeddings natively.
     */
    protected function usesNativeEmbeddings(array $settings): bool
    {
        return ($settings['driver'] ?? null) === 'turbopuffer';
    }

    /**
     * Validate a native embedding schema and return its vector attribute.
     */
    protected function validateNativeEmbeddingSchema(string $attribute, $embed): string
    {
        if (is_string($embed) && trim($embed) !== '') {
            return 'embed_'.$attribute;
        }

        if (! is_array($embed) || ! isset($embed['model']) || ! is_string($embed['model']) || trim($embed['model']) === '') {
            throw new ScoutException("Turbopuffer native embeddings require a valid [embed] schema configuration for the [{$attribute}] attribute.");
        }

        if (isset($embed['dimensions']) && (filter_var($embed['dimensions'], FILTER_VALIDATE_INT) === false || $embed['dimensions'] < 1)) {
            throw new ScoutException('Turbopuffer native embedding [dimensions] must be a positive integer.');
        }

        if (isset($embed['attribute']) && (! is_string($embed['attribute']) || trim($embed['attribute']) === '')) {
            throw new ScoutException('Turbopuffer native embedding [attribute] must be a non-empty string.');
        }

        return $embed['attribute'] ?? 'embed_'.$attribute;
    }

    /**
     * Generate and validate embeddings using the optional Laravel AI SDK.
     */
    protected function generateEmbeddings(array $inputs, array $settings): array
    {
        $embeddingsClass = 'Laravel\\Ai\\Embeddings';

        if (! class_exists($embeddingsClass)) {
            throw new ScoutException('Semantic search requires the Laravel AI SDK. Please install the [laravel/ai] package.');
        }

        $response = $embeddingsClass::for(array_values($inputs))
            ->dimensions($settings['dimensions'])
            ->cache()
            ->generate($settings['provider'] ?? null, $settings['model'] ?? null);

        $embeddings = $response->embeddings;

        if (! is_array($embeddings) || count($embeddings) !== count($inputs)) {
            throw new ScoutException('Laravel AI returned an unexpected number of embeddings.');
        }

        return $embeddings;
    }

    /**
     * Get the Turbopuffer namespace for a search.
     */
    protected function namespace(Builder $builder)
    {
        return $this->turbopuffer->namespace(
            $builder->index ?: $builder->model->searchableAs()
        );
    }

    /**
     * Determine if the model uses soft deletes.
     */
    protected function usesSoftDelete($model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
