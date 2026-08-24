<?php

namespace Laravel\Scout\Engines;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\SupportsSemanticSearch;
use Laravel\Scout\Exceptions\NotSupportedException;
use Laravel\Scout\Exceptions\ScoutException;
use stdClass;
use Typesense\Client as Typesense;
use Typesense\Collection as TypesenseCollection;
use Typesense\Exceptions\ObjectAlreadyExists;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\TypesenseClientError;

class TypesenseEngine extends Engine implements SupportsSemanticSearch
{
    /**
     * The Typesense client instance.
     *
     * @var \Typesense\Client
     */
    protected Typesense $typesense;

    /**
     * The specified search parameters.
     *
     * @var array
     */
    protected array $searchParameters = [];

    /**
     * The maximum number of results that can be fetched per page.
     *
     * @var int
     */
    private int $maxPerPage = 250;

    /**
     * The maximum number of results that can be fetched during pagination.
     *
     * @var int
     */
    protected int $maxTotalResults;

    /**
     * The Typesense configuration.
     *
     * @var array
     */
    protected array $config;

    /**
     * Create new Typesense engine instance.
     *
     * @param  Typesense  $typesense
     */
    public function __construct(Typesense $typesense, int $maxTotalResults, array $config = [])
    {
        $this->typesense = $typesense;
        $this->maxTotalResults = $maxTotalResults;
        $this->config = $config;
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Model>|Model[]  $models
     *
     * @throws \Http\Client\Exception
     * @throws \JsonException
     * @throws \Typesense\Exceptions\TypesenseClientError
     *
     * @noinspection NotOptimalIfConditionsInspection
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $collection = $this->getOrCreateCollectionFromModel($models->first());

        if ($this->usesSoftDelete($models->first()) && config('scout.soft_delete', false)) {
            $models->each->pushSoftDeleteMetadata();
        }

        $records = $models->map(function ($model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return null;
            }

            return [
                'model' => $model,
                'object' => array_merge(
                    $searchableData,
                    $model->scoutMetadata(),
                ),
            ];
        })
            ->filter()
            ->values()
            ->all();

        if (! empty($records)) {
            $settings = $this->modelSettings($models->first());

            $embeddingSettings = isset($settings['embedding'])
                ? $this->embeddingSettings($models->first())
                : null;

            $objects = $embeddingSettings && ! $this->usesNativeEmbeddings($embeddingSettings)
                ? $this->addEmbeddingsToRecords($records, $embeddingSettings)
                : array_column($records, 'object');

            $this->importDocuments(
                $collection,
                $objects
            );
        }
    }

    /**
     * Add generated embeddings to searchable records.
     */
    protected function addEmbeddingsToRecords(array $records, array $settings): array
    {
        $objects = [];

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
                $object = $record['object'];

                $object[$settings['attribute']] = $vectors[$index];

                $objects[] = $object;
            }
        }

        return $objects;
    }

    /**
     * Import the given documents into the index.
     *
     * @param  TypesenseCollection  $collectionIndex
     * @param  array  $documents
     * @param  string|null  $action
     * @return \Illuminate\Support\Collection
     *
     * @throws \JsonException
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    protected function importDocuments(TypesenseCollection $collectionIndex, array $documents, ?string $action = null): Collection
    {
        $action = $action ?? Config::get('scout.typesense.import_action', 'upsert');

        $importedDocuments = $collectionIndex->getDocuments()->import($documents, ['action' => $action]);

        $results = [];

        foreach ($importedDocuments as $importedDocument) {
            if (! $importedDocument['success']) {
                throw new TypesenseClientError("Error importing document: {$importedDocument['error']}");
            }

            $results[] = $this->createImportSortingDataObject(
                $importedDocument
            );
        }

        return collect($results);
    }

    /**
     * Create an import sorting data object for a given document.
     *
     * @param  array  $document
     * @return \stdClass
     *
     * @throws \JsonException
     */
    protected function createImportSortingDataObject($document)
    {
        $data = new stdClass;

        $data->code = $document['code'] ?? 0;
        $data->success = $document['success'];
        $data->error = $document['error'] ?? null;
        $data->document = json_decode($document['document'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     *
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    public function delete($models)
    {
        $models->each(function (Model $model) {
            $this->deleteDocument(
                $this->getOrCreateCollectionFromModel($model),
                $model->getScoutKey()
            );
        });
    }

    /**
     * Delete a document from the index.
     *
     * @param  TypesenseCollection  $collectionIndex
     * @param  mixed  $modelId
     * @return array
     *
     * @throws \Typesense\Exceptions\ObjectNotFound
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    protected function deleteDocument(TypesenseCollection $collectionIndex, $modelId): array
    {
        $document = $collectionIndex->getDocuments()[(string) $modelId];

        try {
            $document->retrieve();

            return $document->delete();
        } catch (Exception $exception) {
            return [];
        }
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     *
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    public function search(Builder $builder)
    {
        // If the limit exceeds Typesense's capabilities, perform a paginated search...
        if ($builder->limit >= $this->maxPerPage) {
            return $this->performPaginatedSearch($builder);
        }

        return $this->performSearch(
            $builder,
            $this->buildSearchParameters($builder, 1, $builder->limit ?? $this->maxPerPage)
        );
    }

    /**
     * Perform the given search on the engine with pagination.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     *
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $maxInt = 4294967295;

        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);

        if ($page * $perPage > $maxInt) {
            $page = floor($maxInt / $perPage);
        }

        return $this->performSearch(
            $builder,
            $this->buildSearchParameters($builder, $page, $perPage)
        );
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return mixed
     *
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    protected function performSearch(Builder $builder, array $options = []): mixed
    {
        $documents = $this->getOrCreateCollectionFromModel(
            $builder->model,
            $builder->index,
            false,
        )->getDocuments();

        if ($builder->callback) {
            return call_user_func($builder->callback, $documents, $builder->query, $options);
        }

        try {
            return $documents->search($options);
        } catch (ObjectNotFound) {
            $this->getOrCreateCollectionFromModel($builder->model, $builder->index, true);

            return $documents->search($options);
        }
    }

    /**
     * Perform a paginated search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     *
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    protected function performPaginatedSearch(Builder $builder)
    {
        $page = 1;
        $limit = min($builder->limit ?? $this->maxPerPage, $this->maxPerPage, $this->maxTotalResults);
        $remainingResults = min($builder->limit ?? $this->maxTotalResults, $this->maxTotalResults);

        $results = new Collection;

        while ($remainingResults > 0) {
            $searchResults = $this->performSearch(
                $builder,
                $this->buildSearchParameters($builder, $page, $limit)
            );

            $results = $results->concat($searchResults['hits'] ?? []);

            if ($page === 1) {
                $totalFound = $searchResults['found'] ?? 0;
            }

            $remainingResults -= $limit;
            $page++;

            if (count($searchResults['hits'] ?? []) < $limit) {
                break;
            }
        }

        return [
            'hits' => $results->all(),
            'found' => $results->count(),
            'out_of' => $totalFound,
            'page' => 1,
            'request_params' => $this->buildSearchParameters($builder, 1, $builder->limit ?? $this->maxPerPage),
        ];
    }

    /**
     * Build the search parameters for a given Scout query builder.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $page
     * @param  int|null  $perPage
     * @return array
     */
    public function buildSearchParameters(Builder $builder, int $page, ?int $perPage): array
    {
        $parameters = [
            'q' => $builder->query,
            'query_by' => config('scout.typesense.model-settings.'.get_class($builder->model).'.search-parameters.query_by') ?? '',
            'filter_by' => $this->filters($builder),
            'per_page' => $perPage,
            'page' => $page,
            'highlight_start_tag' => '<mark>',
            'highlight_end_tag' => '</mark>',
            'snippet_threshold' => 30,
            'exhaustive_search' => false,
            'use_cache' => false,
            'cache_ttl' => 60,
            'prioritize_exact_match' => true,
            'enable_overrides' => true,
            'highlight_affix_num_tokens' => 4,
            'prefix' => config('scout.typesense.model-settings.'.get_class($builder->model).'.search-parameters.prefix') ?? true,
        ];

        if (method_exists($builder->model, 'typesenseSearchParameters')) {
            $parameters = array_merge($parameters, $builder->model->typesenseSearchParameters());
        }

        if (! empty($builder->options)) {
            $parameters = array_merge($parameters, $builder->options);
        }

        if (! empty($builder->orders)) {
            if (! empty($parameters['sort_by'])) {
                $parameters['sort_by'] .= ',';
            } else {
                $parameters['sort_by'] = '';
            }

            $parameters['sort_by'] .= $this->parseOrderBy($builder->orders);
        }

        return $this->applySemanticSearchParameters($builder, $parameters);
    }

    /**
     * Apply semantic and hybrid search parameters to the search parameters.
     */
    protected function applySemanticSearchParameters(Builder $builder, array $parameters): array
    {
        if (! $builder->semanticSearch && is_null($builder->hybridSearch)) {
            return $parameters;
        }

        if (array_key_exists('vector_query', $builder->options)) {
            throw new ScoutException('Typesense semantic and hybrid searches cannot be combined with a custom [vector_query] option.');
        }

        $settings = $this->embeddingSettings($builder->model);

        unset($parameters['vector']);

        if ($builder->semanticSearch) {
            $parameters = $this->usesNativeEmbeddings($settings)
                ? $this->applyNativeSemanticQueryBy($parameters, $settings['attribute'])
                : array_merge($parameters, ['q' => '*']);
        } else {
            $parameters = $this->applyHybridQueryBy($parameters, $settings);
        }

        if (! is_null($vectorQuery = $this->vectorQuery($builder, $settings))) {
            $parameters['vector_query'] = $vectorQuery;
        }

        $parameters['exclude_fields'] = $this->appendField($parameters['exclude_fields'] ?? '', $settings['attribute']);

        return $parameters;
    }

    /**
     * Target the embedding field for a semantic search using native embeddings.
     */
    protected function applyNativeSemanticQueryBy(array $parameters, string $attribute): array
    {
        $parameters['query_by'] = $attribute;

        // Remote embedders reject prefix searches on embedding fields...
        $parameters['prefix'] = false;

        unset($parameters['query_by_weights']);

        foreach (['num_typos', 'infix'] as $parameter) {
            if (isset($parameters[$parameter]) && str_contains((string) $parameters[$parameter], ',')) {
                unset($parameters[$parameter]);
            }
        }

        return $parameters;
    }

    /**
     * Prepare the "query_by" fields and their per-field parameters for a hybrid search.
     */
    protected function applyHybridQueryBy(array $parameters, array $settings): array
    {
        $fields = array_filter(array_map('trim', explode(',', (string) ($parameters['query_by'] ?? ''))));

        if (empty(array_diff($fields, [$settings['attribute']]))) {
            throw new ScoutException('Typesense hybrid searches require at least one keyword field in the [query_by] search parameter.');
        }

        if (! $this->usesNativeEmbeddings($settings) || in_array($settings['attribute'], $fields, true)) {
            return $parameters;
        }

        $parameters['query_by'] = implode(',', [...$fields, $settings['attribute']]);

        if (isset($parameters['query_by_weights']) && $parameters['query_by_weights'] !== '') {
            $parameters['query_by_weights'] .= ',0';
        }

        foreach (['num_typos' => '0', 'prefix' => 'false', 'infix' => 'off'] as $parameter => $placeholder) {
            if (isset($parameters[$parameter]) && str_contains((string) $parameters[$parameter], ',')) {
                $parameters[$parameter] .= ','.$placeholder;
            }
        }

        // Remote embedders reject prefix searches on embedding fields, so a
        // global prefix must become a per-field list that excludes them...
        if (($parameters['prefix'] ?? null) === true || ($parameters['prefix'] ?? null) === 'true') {
            $parameters['prefix'] = implode(',', [...array_fill(0, count($fields), 'true'), 'false']);
        }

        return $parameters;
    }

    /**
     * Build the "vector_query" parameter for the search.
     */
    protected function vectorQuery(Builder $builder, array $settings): ?string
    {
        if ($this->usesNativeEmbeddings($settings)) {
            $vector = [];
        } else {
            $vector = $builder->options['vector'] ??
                $this->generateEmbeddings([$builder->query], $settings)[0];

            if (! is_array($vector) || empty($vector)) {
                throw new ScoutException('The Typesense query [vector] must be a non-empty embedding array.');
            }
        }

        $options = [];

        if (! is_null($builder->hybridSearch)) {
            $options[] = 'alpha: '.($builder->hybridSearch['semantic_weight'] / array_sum($builder->hybridSearch));
        }

        if (! is_null($builder->minimumSimilarity)) {
            $options[] = 'distance_threshold: '.$this->distanceThreshold($builder);
        }

        // Typesense rejects an empty vector query without parameters; native
        // semantic searches are expressed via "query_by" alone in that case...
        if (empty($vector) && empty($options)) {
            return null;
        }

        return sprintf(
            '%s:([%s]%s)',
            $settings['attribute'],
            implode(', ', $vector),
            empty($options) ? '' : ', '.implode(', ', $options)
        );
    }

    /**
     * Get the maximum vector distance for the search's minimum similarity.
     *
     * This assumes the collection uses the default cosine distance metric. On
     * hybrid searches, Typesense only applies the threshold to vector search
     * candidates; keyword matches are returned regardless of their distance.
     */
    protected function distanceThreshold(Builder $builder): float
    {
        $similarity = $builder->minimumSimilarity;

        if (! is_numeric($similarity) || $similarity < 0 || $similarity > 1) {
            throw new ScoutException('The minimum similarity must be between 0 and 1.');
        }

        return 1 - $similarity;
    }

    /**
     * Append a field to a comma-separated field list if not already present.
     */
    protected function appendField(string $fields, string $field): string
    {
        $fields = array_filter(array_map('trim', explode(',', $fields)));

        if (! in_array($field, $fields, true)) {
            $fields[] = $field;
        }

        return implode(',', $fields);
    }

    /**
     * Prepare the filters for a given search query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return string
     */
    protected function filters(Builder $builder): string
    {
        $whereFilter = collect($builder->wheres)
            ->map(fn ($where) => $this->parseWhereFilter($this->parseFilterValue($where['value']), $where['field'], $where['operator']))
            ->values()
            ->implode(' && ');

        $whereInFilter = collect($builder->whereIns)
            ->map(fn ($value, $key) => $this->parseWhereInFilter($this->parseFilterValue($value), $key))
            ->values()
            ->implode(' && ');

        $whereNotInFilter = collect($builder->whereNotIns)
            ->map(fn ($value, $key) => $this->parseWhereNotInFilter($this->parseFilterValue($value), $key))
            ->values()
            ->implode(' && ');

        $filters = collect([$whereFilter, $whereInFilter, $whereNotInFilter])
            ->filter()
            ->implode(' && ');

        return $filters;
    }

    /**
     * Parse the given filter value.
     *
     * @param  array|string|bool|int|float  $value
     * @return array|bool|float|int|string
     */
    protected function parseFilterValue(array|string|bool|int|float $value)
    {
        if (is_array($value)) {
            return array_map([$this, 'parseFilterValue'], $value);
        }

        if (gettype($value) == 'boolean') {
            return $value ? 'true' : 'false';
        }

        return $value;
    }

    /**
     * Create a "where" filter string.
     *
     * @param  array|string  $value
     * @param  string  $key
     * @param  string  $operator
     * @return string
     */
    protected function parseWhereFilter(array|string $value, string $key, string $operator = '='): string
    {
        if (is_array($value)) {
            return sprintf('%s:%s', $key, implode('', $value));
        }

        $operator = match ($operator) {
            '=' => ':=',
            '!=' => ':!=',
            '<' => ':<',
            '>' => ':>',
            '<=' => ':<=',
            '>=' => ':>=',
            default => ':=',
        };

        return sprintf('%s%s%s', $key, $operator, $value);
    }

    /**
     * Create a "where in" filter string.
     *
     * @param  array  $value
     * @param  string  $key
     * @return string
     */
    protected function parseWhereInFilter(array $value, string $key): string
    {
        return sprintf('%s:=[%s]', $key, implode(', ', $value));
    }

    /**
     * Create a "where not in" filter string.
     *
     * @param  array|string  $value
     * @param  string  $key
     * @return string
     */
    protected function parseWhereNotInFilter(array $value, string $key): string
    {
        return sprintf('%s:!=[%s]', $key, implode(', ', $value));
    }

    /**
     * Parse the order by fields for the query.
     *
     * @param  array  $orders
     * @return string
     */
    protected function parseOrderBy(array $orders): string
    {
        $orderBy = [];

        foreach ($orders as $order) {
            $orderBy[] = $order['column'].':'.$order['direction'];
        }

        return implode(',', $orderBy);
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits'])
            ->pluck('document.id')
            ->values();
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
        if ($this->getTotalCount($results) === 0) {
            return $model->newCollection();
        }

        $hits = isset($results['grouped_hits']) && ! empty($results['grouped_hits'])
            ? $results['grouped_hits']
            : $results['hits'];

        $pluck = isset($results['grouped_hits']) && ! empty($results['grouped_hits'])
            ? 'hits.0.document.id'
            : 'document.id';

        $objectIds = collect($hits)
            ->pluck($pluck)
            ->values()
            ->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(static fn ($model) => in_array($model->getScoutKey(), $objectIds, false))
            ->sortBy(static fn ($model) => $objectIdPositions[$model->getScoutKey()])
            ->values();
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
        if ((int) ($results['found'] ?? 0) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = collect($results['hits'])
            ->pluck('document.id')
            ->values()
            ->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(static fn ($model) => in_array($model->getScoutKey(), $objectIds, false))
            ->sortBy(static fn ($model) => $objectIdPositions[$model->getScoutKey()])
            ->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return (int) ($results['found'] ?? 0);
    }

    /**
     * Flush all the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     *
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    public function flush($model)
    {
        $this->getOrCreateCollectionFromModel($model)->delete();
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @param  array  $options
     * @return void
     *
     * @throws NotSupportedException
     */
    public function createIndex($name, array $options = [])
    {
        throw new NotSupportedException('Typesense indexes are created automatically upon adding objects.');
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return array
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\ObjectNotFound
     */
    public function deleteIndex($name)
    {
        return $this->typesense->getCollections()->{$name}->delete();
    }

    /**
     * Get collection from model or create new one.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Typesense\Collection
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    protected function getOrCreateCollectionFromModel($model, ?string $collectionName = null, bool $indexOperation = true): TypesenseCollection
    {
        if (! $indexOperation) {
            $collectionName = $collectionName ?? $model->searchableAs();
        } else {
            $collectionName = $model->indexableAs();
        }

        $collection = $this->typesense->getCollections()->{$collectionName};

        if (! $indexOperation) {
            return $collection;
        }

        // Determine if the collection exists in Typesense...
        try {
            $collection->retrieve();

            // No error means this collection exists on the server...
            $collection->setExists(true);

            return $collection;
        } catch (TypesenseClientError $e) {
            //
        }

        $schema = config('scout.typesense.model-settings.'.get_class($model).'.collection-schema') ?? [];

        if (method_exists($model, 'typesenseCollectionSchema')) {
            $schema = $model->typesenseCollectionSchema();
        }

        if (! isset($schema['name'])) {
            $schema['name'] = $model->searchableAs();
        }

        try {
            // Create the collection in Typesense...
            $this->typesense->getCollections()->create($schema);
        } catch (ObjectAlreadyExists $e) {
            // Collection already exists...
        }

        $collection->setExists(true);

        return $collection;
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
        $settings = $this->modelSettings($model)['embedding'] ?? null;

        if (! is_array($settings)) {
            throw new ScoutException('No Typesense embedding settings have been configured for ['.get_class($model).'].');
        }

        if (! isset($settings['attribute']) || ! is_string($settings['attribute']) || trim($settings['attribute']) === '') {
            throw new ScoutException('Typesense embedding settings must contain an [attribute].');
        }

        $driver = $settings['driver'] ?? 'laravel-ai';

        if (! in_array($driver, ['laravel-ai', 'typesense'], true)) {
            throw new ScoutException("The [{$driver}] Typesense embedding driver is not supported.");
        }

        $settings['driver'] = $driver;

        if ($this->usesNativeEmbeddings($settings)) {
            return $settings;
        }

        if (! isset($settings['dimensions']) ||
            filter_var($settings['dimensions'], FILTER_VALIDATE_INT) === false ||
            $settings['dimensions'] < 1) {
            throw new ScoutException('Typesense embedding settings must contain positive [dimensions].');
        }

        $settings['dimensions'] = (int) $settings['dimensions'];

        return $settings;
    }

    /**
     * Determine if Typesense should generate embeddings natively.
     */
    protected function usesNativeEmbeddings(array $settings): bool
    {
        return ($settings['driver'] ?? null) === 'typesense';
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
     * Determine if model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    /**
     * Dynamically proxy missing methods to the Typesense client instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->typesense->$method(...$parameters);
    }
}
