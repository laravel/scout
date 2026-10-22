<?php

namespace Laravel\Scout\Engines;

use BackedEnum;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use JsonSerializable;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\SupportsSemanticSearch;
use Laravel\Scout\Exceptions\NotSupportedException;
use Laravel\Scout\Exceptions\ScoutException;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Laravel\Scout\Services\Qdrant\QdrantClient;
use Laravel\Scout\Services\Qdrant\QdrantCollection;
use Stringable;

class QdrantEngine extends Engine implements SupportsSemanticSearch
{
    /**
     * The existing collections.
     *
     * @var array<string, bool>
     */
    protected array $existingCollections = [];

    /**
     * Create a new Qdrant engine instance.
     */
    public function __construct(
        protected QdrantClient $qdrant,
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
                'point' => [
                    'id' => $this->pointId($model->getScoutKey()),
                    'vector' => [],
                    'payload' => array_merge(
                        $searchableData,
                        Arr::except($model->scoutMetadata(), '_qdrant_score'),
                        ['__scout_key' => $model->getScoutKey()],
                    ),
                ],
            ];
        })->filter()->values()->all();

        if (empty($records)) {
            return;
        }

        $settings = $this->modelSettings($model);

        if (! empty($settings['searchable-attributes'])) {
            $records = $this->addFullTextDocumentsToRecords($records, $model);
        }

        if (isset($settings['embedding'])) {
            $records = $this->addEmbeddingsToRecords($records, $this->embeddingSettings($model));
        }

        $points = array_map(fn ($record) => array_merge($record['point'], [
            'vector' => $record['point']['vector'] ?: (object) [],
        ]), $records);

        foreach (array_chunk($points, 100) as $chunk) {
            try {
                $this->ensureCollectionExists($model)->upsert($chunk);
            } catch (RequestException $e) {
                if (! $this->isMissingCollection($e)) {
                    throw $e;
                }

                unset($this->existingCollections[$model->indexableAs()]);

                $this->ensureCollectionExists($model)->upsert($chunk);
            }
        }
    }

    /**
     * Add full-text documents to searchable records.
     */
    protected function addFullTextDocumentsToRecords(array $records, $model): array
    {
        $settings = $this->fullTextSettings($model);

        return array_map(function ($record) use ($settings) {
            $values = array_map(fn ($attribute) => $record['point']['payload'][$attribute] ?? null, $settings['attributes']);

            $text = collect(Arr::flatten($values))
                ->filter(fn ($value) => (is_string($value) || is_int($value) || is_float($value)) && $value !== '')
                ->implode("\n");

            if ($text !== '') {
                $record['point']['vector'][$settings['vector']] = $this->document($text, $settings);
            }

            return $record;
        }, $records);
    }

    /**
     * Add generated embeddings to searchable records.
     */
    protected function addEmbeddingsToRecords(array $records, array $settings): array
    {
        foreach (array_chunk(array_keys($records), 100) as $batch) {
            $inputs = [];

            foreach ($batch as $index) {
                $model = $records[$index]['model'];

                if (! method_exists($model, 'toSearchableEmbedding')) {
                    throw new ScoutException('Searchable models using embeddings must define a [toSearchableEmbedding] method.');
                }

                $input = $model->toSearchableEmbedding();

                if (is_array($input)) {
                    $records[$index]['point']['vector'][$settings['attribute']] = $input;

                    continue;
                }

                if (! is_string($input) || trim($input) === '') {
                    throw new ScoutException('The [toSearchableEmbedding] method must return a non-empty string or an embedding array.');
                }

                if ($this->usesNativeEmbeddings($settings)) {
                    $records[$index]['point']['vector'][$settings['attribute']] = $this->document($input, $settings);

                    continue;
                }

                $inputs[$index] = $input;
            }

            if (! empty($inputs)) {
                $vectors = $this->generateEmbeddings(array_values($inputs), $settings);

                foreach (array_keys($inputs) as $position => $index) {
                    $records[$index]['point']['vector'][$settings['attribute']] = $vectors[$position];
                }
            }
        }

        return $records;
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

        $this->ignoreMissingCollection(fn () => $this->qdrant
            ->collection($models->first()->indexableAs())
            ->deletePoints($keys->map(fn ($key) => $this->pointId($key))->values()->all()));
    }

    /**
     * Perform the given search on the engine.
     */
    public function search(Builder $builder)
    {
        $limit = (int) ($builder->limit ?? $builder->model->getPerPage());

        if ($limit < 1) {
            return ['points' => [], 'total' => 0];
        }

        return $this->performSearch($builder, $this->buildSearchParameters($builder, $limit));
    }

    /**
     * Perform the given paginated search on the engine.
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $page = max(1, (int) $page);
        $perPage = max(1, (int) $perPage);
        $offset = ($page - 1) * $perPage;

        if ($this->isRanked($builder)) {
            return $this->paginateRanked($builder, $perPage, $offset);
        }

        $limit = is_null($builder->limit)
            ? $perPage
            : min($perPage, (int) $builder->limit - $offset);

        $results = $limit > 0
            ? $this->performSearch($builder, $this->buildSearchParameters($builder, $limit, $offset))
            : ['points' => []];

        $filter = $this->combineFilters($builder->options['filter'] ?? null, $this->filters($builder));

        if (! empty($builder->orders)) {
            $filter = $this->combineFilters($filter, ['must_not' => [
                ['is_empty' => ['key' => $this->field($builder, $builder->orders[0]['column'])]],
            ]]);
        }

        $count = $this->ignoreMissingCollection(
            fn () => $this->collection($builder)->count(array_filter(['filter' => $filter, 'exact' => true]))
        );

        $results['total'] = (int) ($count['result']['count'] ?? 0);

        if (! is_null($builder->limit)) {
            $results['total'] = min($results['total'], (int) $builder->limit);
        }

        return $results;
    }

    /**
     * Paginate a ranked search by slicing the same ranked results for every page.
     */
    protected function paginateRanked(Builder $builder, int $perPage, int $offset): array
    {
        $maximum = (int) ($builder->limit ?? $this->maxResults());

        if ($maximum < 1) {
            return ['points' => [], 'total' => 0];
        }

        $parameters = $this->buildSearchParameters($builder, $maximum);

        $results = $this->performSearch($builder, array_merge($parameters, [
            'with_payload' => false,
            'with_vector' => false,
        ]));

        $total = count($results['points']);
        $points = array_slice($results['points'], $offset, $perPage);

        if (! empty($points)) {
            $retrieved = $this->ignoreMissingCollection(fn () => $this->collection($builder)->retrieve(array_filter([
                'ids' => array_column($points, 'id'),
                'with_payload' => $parameters['with_payload'],
                'with_vector' => $parameters['with_vector'] ?? null,
            ], fn ($value) => ! is_null($value))));

            $retrieved = collect($retrieved['result'] ?? [])->keyBy('id');

            $points = collect($points)
                ->filter(fn ($point) => $retrieved->has($point['id']))
                ->map(fn ($point) => array_merge($point, Arr::only($retrieved[$point['id']], ['payload', 'vector'])))
                ->values()
                ->all();
        }

        return array_merge($results, ['points' => $points, 'total' => $total]);
    }

    /**
     * Perform a search against Qdrant.
     */
    protected function performSearch(Builder $builder, array $parameters): array
    {
        $collection = $this->collection($builder);

        $results = $builder->callback
            ? call_user_func($builder->callback, $collection, $builder->query, $parameters)
            : $this->ignoreMissingCollection(fn () => $collection->query($parameters)) ?? [];

        $results['points'] = $results['result']['points'] ?? $results['points'] ?? [];
        $results['total'] = count($results['points']);

        unset($results['result']);

        return $results;
    }

    /**
     * Run the callback, treating a missing collection as an empty result.
     */
    protected function ignoreMissingCollection(callable $callback)
    {
        try {
            return $callback();
        } catch (RequestException $e) {
            if (! $this->isMissingCollection($e)) {
                throw $e;
            }
        }
    }

    /**
     * Determine if the exception was caused by a missing collection.
     */
    protected function isMissingCollection(RequestException $e): bool
    {
        return $e->response->status() === 404 && is_string($e->response->json('status.error'));
    }

    /**
     * Determine if the search ranks its results with a query.
     */
    protected function isRanked(Builder $builder): bool
    {
        return ! is_null($builder->hybridSearch) ||
            $builder->semanticSearch ||
            isset($builder->options['query']) ||
            isset($builder->options['prefetch']) ||
            ! $this->isMatchAll($builder);
    }

    /**
     * Determine if the search matches all documents.
     */
    protected function isMatchAll(Builder $builder): bool
    {
        return in_array(trim((string) $builder->query), ['', '*'], true);
    }

    /**
     * Get the maximum number of results a ranked search may return.
     */
    protected function maxResults(): int
    {
        return (int) ($this->config['max_results'] ?? 1000);
    }

    /**
     * Build Qdrant query parameters for the search.
     */
    public function buildSearchParameters(Builder $builder, int $limit, int $offset = 0): array
    {
        $parameters = $builder->options;
        $filter = $this->combineFilters($parameters['filter'] ?? null, $this->filters($builder));

        unset($parameters['filter']);

        if (! is_null($builder->hybridSearch)) {
            $parameters = array_merge($parameters, $this->hybridQuery(
                $builder, $filter, max($this->maxResults(), $limit + $offset)
            ));
        } else {
            if (! isset($parameters['query']) && ! isset($parameters['prefetch'])) {
                $parameters = array_merge($parameters, $this->query($builder));
            } elseif ($builder->semanticSearch || ! empty($builder->orders)) {
                throw new ScoutException('Qdrant semantic searches and order clauses cannot be combined with a custom [query] option.');
            }

            if ($filter) {
                $parameters['filter'] = $filter;
            }
        }

        $parameters = $this->ensureIdIsReturned($parameters);
        $parameters['limit'] = max(1, $limit);

        if ($offset > 0) {
            $parameters['offset'] = $offset;
        }

        return $parameters;
    }

    /**
     * Build the query for the search.
     */
    protected function query(Builder $builder): array
    {
        if ($builder->semanticSearch) {
            if (! empty($builder->orders)) {
                throw new ScoutException('Qdrant order clauses cannot be combined with semantic search.');
            }

            return $this->semanticQuery($builder);
        }

        if ($this->isMatchAll($builder)) {
            if (count($builder->orders) > 1) {
                throw new ScoutException('Qdrant supports one order clause per search.');
            }

            if (empty($builder->orders)) {
                return [];
            }

            return ['query' => ['order_by' => [
                'key' => $this->field($builder, $builder->orders[0]['column']),
                'direction' => $builder->orders[0]['direction'],
            ]]];
        }

        if (! empty($builder->orders)) {
            throw new ScoutException('Qdrant order clauses cannot be combined with full-text search.');
        }

        return $this->fullTextQuery($builder);
    }

    /**
     * Build a hybrid full-text and semantic query.
     */
    protected function hybridQuery(Builder $builder, ?array $filter, int $limit): array
    {
        if (! empty($builder->orders)) {
            throw new ScoutException('Qdrant order clauses cannot be combined with hybrid search.');
        }

        foreach (['query', 'prefetch'] as $option) {
            if (isset($builder->options[$option])) {
                throw new ScoutException("Qdrant hybrid searches cannot be combined with a custom [{$option}] option.");
            }
        }

        $prefetch = array_map(
            fn ($query) => array_merge($query, array_filter(['filter' => $filter]), ['limit' => $limit]),
            [$this->fullTextQuery($builder), $this->semanticQuery($builder)]
        );

        return [
            'prefetch' => $prefetch,
            'query' => ['rrf' => [
                'weights' => [
                    $builder->hybridSearch['text_weight'],
                    $builder->hybridSearch['semantic_weight'],
                ],
            ]],
        ];
    }

    /**
     * Build the full-text query.
     */
    protected function fullTextQuery(Builder $builder): array
    {
        $settings = $this->fullTextSettings($builder->model);

        return [
            'query' => $this->document($builder->query, $settings),
            'using' => $settings['vector'],
        ];
    }

    /**
     * Build the semantic query.
     */
    protected function semanticQuery(Builder $builder): array
    {
        $settings = $this->embeddingSettings($builder->model);

        $distance = $this->modelSettings($builder->model)['collection']['vectors'][$settings['attribute']]['distance']
            ?? $settings['distance'] ?? 'Cosine';

        if (! is_null($builder->minimumSimilarity) && in_array($distance, ['Euclid', 'Manhattan'], true)) {
            throw new ScoutException("Qdrant minimum similarity requires the Cosine or Dot distance, not [{$distance}].");
        }

        return array_filter([
            'query' => $this->usesNativeEmbeddings($settings)
                ? $this->document($builder->query, $settings)
                : $this->generateEmbeddings([$builder->query], $settings)[0],
            'using' => $settings['attribute'],
            'score_threshold' => $builder->minimumSimilarity,
        ], fn ($value) => ! is_null($value));
    }

    /**
     * Build filters for the query.
     */
    protected function filters(Builder $builder): ?array
    {
        $must = [];
        $mustNot = [];

        $ranges = ['<' => 'lt', '<=' => 'lte', '>' => 'gt', '>=' => 'gte'];

        foreach ($builder->wheres as $where) {
            $key = $this->field($builder, $where['field']);

            if ($where['operator'] === '=') {
                $must[] = $this->matchCondition($builder, $key, [$where['value']]);
            } elseif ($where['operator'] === '!=') {
                $mustNot[] = $this->matchCondition($builder, $key, [$where['value']]);
            } elseif (isset($ranges[$where['operator']])) {
                $must[] = ['key' => $key, 'range' => [$ranges[$where['operator']] => $this->rangeValue($where['value'])]];
            } else {
                throw new ScoutException("The [{$where['operator']}] operator is not supported by the Qdrant engine.");
            }
        }

        foreach ($builder->whereIns as $field => $values) {
            $must[] = $this->matchCondition($builder, $this->field($builder, $field), $values);
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $mustNot[] = $this->matchCondition($builder, $this->field($builder, $field), $values);
        }

        $filter = array_filter(['must' => $must, 'must_not' => $mustNot]);

        return empty($filter) ? null : $filter;
    }

    /**
     * Build a condition that matches any of the given values.
     */
    protected function matchCondition(Builder $builder, string $key, array $values): array
    {
        $type = $this->payloadIndexType($builder->model, $key);

        $conditions = [];
        $strings = [];
        $integers = [];

        foreach (array_map([$this, 'filterValue'], $values) as $value) {
            if (is_null($value)) {
                $conditions[] = ['is_null' => ['key' => $key]];

                continue;
            }

            if (is_bool($value)) {
                $conditions[] = ['key' => $key, 'match' => ['value' => $value]];

                continue;
            }

            $number = $this->numberValue($value);

            if (is_null($number) || in_array($type, ['keyword', 'uuid'], true)) {
                $strings[] = is_null($number) ? $value : (string) $value;

                continue;
            }

            if (is_int($number)) {
                $integers[] = $number;
            } else {
                $conditions[] = ['key' => $key, 'range' => ['gte' => $number, 'lte' => $number]];
            }

            if (! in_array($type, ['integer', 'float'], true)) {
                $strings[] = (string) $value;
            }
        }

        foreach ([array_values(array_unique($strings, SORT_REGULAR)), array_values(array_unique($integers))] as $matches) {
            if (! empty($matches)) {
                $conditions[] = ['key' => $key, 'match' => count($matches) === 1 ? ['value' => $matches[0]] : ['any' => $matches]];
            }
        }

        if (empty($conditions)) {
            return ['key' => $key, 'match' => ['any' => []]];
        }

        return count($conditions) === 1 ? $conditions[0] : ['should' => $conditions];
    }

    /**
     * Get the number a filter value represents.
     */
    protected function numberValue($value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/', $value) ? $value + 0 : null;
    }

    /**
     * Get the payload index type of a field.
     */
    protected function payloadIndexType($model, string $key): ?string
    {
        $schema = $this->payloadIndexes($model)[$key] ?? null;

        return is_array($schema) ? ($schema['type'] ?? null) : $schema;
    }

    /**
     * Normalize a range value.
     */
    protected function rangeValue($value)
    {
        $value = $this->filterValue($value);

        return is_string($value) && is_numeric($value) ? $value + 0 : $value;
    }

    /**
     * Combine native and Scout filters.
     */
    protected function combineFilters(?array $nativeFilter, ?array $scoutFilter): ?array
    {
        if (is_null($nativeFilter)) {
            return $scoutFilter;
        }

        if (is_null($scoutFilter)) {
            return $nativeFilter;
        }

        return ['must' => [$nativeFilter, $scoutFilter]];
    }

    /**
     * Normalize a filter value.
     */
    protected function filterValue($value)
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof JsonSerializable => $value->jsonSerialize(),
            $value instanceof Stringable => (string) $value,
            default => $value,
        };
    }

    /**
     * Ensure the Scout key is returned for model hydration.
     */
    protected function ensureIdIsReturned(array $parameters): array
    {
        $withPayload = $parameters['with_payload'] ?? true;

        $parameters['with_payload'] = match (true) {
            $withPayload === false => ['__scout_key'],
            is_array($withPayload) && array_is_list($withPayload) => array_values(array_unique([...$withPayload, '__scout_key'])),
            isset($withPayload['include']) => ['include' => array_values(array_unique([...(array) $withPayload['include'], '__scout_key']))],
            isset($withPayload['exclude']) => ['exclude' => array_values(array_diff((array) $withPayload['exclude'], ['__scout_key']))],
            default => $withPayload,
        };

        return $parameters;
    }

    /**
     * Resolve a Scout field name to a Qdrant payload key.
     */
    protected function field(Builder $builder, string $field): string
    {
        return $field === $builder->model->getScoutKeyName() ? '__scout_key' : $field;
    }

    /**
     * Get the Qdrant point ID for a Scout key.
     */
    protected function pointId($key): int|string
    {
        if (is_int($key) && $key >= 0) {
            return $key;
        }

        $key = (string) $key;

        if (preg_match('/^(0|[1-9][0-9]*)$/', $key) && ($id = filter_var($key, FILTER_VALIDATE_INT)) !== false) {
            return $id;
        }

        if (Str::isUuid($key)) {
            return strtolower($key);
        }

        return preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', md5($key));
    }

    /**
     * Pluck and return the primary keys of the given results.
     */
    public function mapIds($results)
    {
        return collect($results['points'] ?? [])->pluck('payload.__scout_key')->values();
    }

    /**
     * Map the given results to model instances.
     */
    public function map(Builder $builder, $results, $model)
    {
        if (empty($results['points'])) {
            return $model->newCollection();
        }

        $points = collect($results['points']);
        $objectIds = $points->pluck('payload.__scout_key')->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(fn ($model) => in_array($model->getScoutKey(), $objectIds, false))
            ->map(function ($model) use ($points, $objectIdPositions) {
                $point = $points[$objectIdPositions[$model->getScoutKey()]];

                if (array_key_exists('score', $point)) {
                    $model->withScoutMetadata('_qdrant_score', $point['score']);
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
        if (empty($results['points'])) {
            return LazyCollection::make($model->newCollection());
        }

        $points = collect($results['points']);
        $objectIds = $points->pluck('payload.__scout_key')->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(fn ($model) => in_array($model->getScoutKey(), $objectIds, false))
            ->map(function ($model) use ($points, $objectIdPositions) {
                $point = $points[$objectIdPositions[$model->getScoutKey()]];

                if (array_key_exists('score', $point)) {
                    $model->withScoutMetadata('_qdrant_score', $point['score']);
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
        return (int) ($results['total'] ?? count($results['points'] ?? []));
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
        throw new NotSupportedException('Qdrant collections are created automatically upon adding documents.');
    }

    /**
     * Delete a search index.
     */
    public function deleteIndex($name)
    {
        unset($this->existingCollections[$name]);

        return $this->qdrant->collection($name)->delete();
    }

    /**
     * Ensure the collection for the model exists.
     */
    protected function ensureCollectionExists($model): QdrantCollection
    {
        $name = $model->indexableAs();
        $collection = $this->qdrant->collection($name);

        if (isset($this->existingCollections[$name])) {
            return $collection;
        }

        $existingIndexes = $this->ignoreMissingCollection(
            fn () => $collection->info()['result']['payload_schema'] ?? []
        );

        if (is_null($existingIndexes)) {
            try {
                $collection->create($this->collectionParameters($model));
            } catch (RequestException $e) {
                if ($e->response->status() !== 409) {
                    throw $e;
                }
            }

            $existingIndexes = [];
        }

        foreach (array_diff_key($this->payloadIndexes($model), $existingIndexes) as $field => $schema) {
            $collection->createPayloadIndex($field, $schema);
        }

        $this->existingCollections[$name] = true;

        return $collection;
    }

    /**
     * Get the payload indexes for a model.
     */
    protected function payloadIndexes($model): array
    {
        $payloadIndexes = $this->modelSettings($model)['payload-indexes'] ?? [];

        $payloadIndexes['__scout_key'] ??= in_array($model->getScoutKeyType(), ['int', 'integer'], true) ? 'integer' : 'keyword';

        if ($this->softDelete && $this->usesSoftDelete($model)) {
            $payloadIndexes['__soft_deleted'] ??= 'integer';
        }

        return $payloadIndexes;
    }

    /**
     * Get the collection parameters for a model.
     */
    protected function collectionParameters($model): array
    {
        $settings = $this->modelSettings($model);

        $parameters = $settings['collection'] ?? [];

        if (isset($settings['embedding'])) {
            $embedding = $this->embeddingSettings($model);

            $parameters['vectors'][$embedding['attribute']] ??= [
                'size' => $embedding['dimensions'],
                'distance' => $embedding['distance'] ?? 'Cosine',
            ];
        }

        if (! empty($settings['searchable-attributes'])) {
            $parameters['sparse_vectors'][$this->fullTextSettings($model)['vector']] ??= ['modifier' => 'idf'];
        }

        $parameters['vectors'] ??= (object) [];

        return $parameters;
    }

    /**
     * Get the configured settings for a model.
     */
    protected function modelSettings($model): array
    {
        return $this->config['model-settings'][get_class($model)] ?? [];
    }

    /**
     * Get the validated full-text settings for a model.
     */
    protected function fullTextSettings($model): array
    {
        $settings = $this->modelSettings($model);

        $attributes = $settings['searchable-attributes'] ?? [];

        if (empty($attributes)) {
            throw new ScoutException('No Qdrant searchable attributes have been configured for ['.get_class($model).'].');
        }

        if (! array_is_list($attributes)) {
            throw new ScoutException('Qdrant searchable attributes must be a list of attribute names.');
        }

        return array_merge([
            'vector' => 'bm25',
            'model' => 'qdrant/bm25',
        ], $settings['full-text'] ?? [], ['attributes' => $attributes]);
    }

    /**
     * Get the validated embedding settings for a model.
     */
    protected function embeddingSettings($model): array
    {
        $settings = $this->modelSettings($model)['embedding'] ?? null;

        if (! is_array($settings)) {
            throw new ScoutException('No Qdrant embedding settings have been configured for ['.get_class($model).'].');
        }

        if (empty($settings['attribute'])) {
            throw new ScoutException('Qdrant embedding settings must contain an [attribute].');
        }

        $driver = $settings['driver'] ?? 'laravel-ai';

        if (! in_array($driver, ['laravel-ai', 'qdrant'], true)) {
            throw new ScoutException("The [{$driver}] Qdrant embedding driver is not supported.");
        }

        $settings['driver'] = $driver;

        if (! isset($settings['dimensions']) ||
            filter_var($settings['dimensions'], FILTER_VALIDATE_INT) === false ||
            $settings['dimensions'] < 1) {
            throw new ScoutException('Qdrant embedding settings must contain positive [dimensions].');
        }

        $settings['dimensions'] = (int) $settings['dimensions'];

        if ($this->usesNativeEmbeddings($settings) && empty($settings['model'])) {
            throw new ScoutException('Qdrant native embeddings require a [model].');
        }

        return $settings;
    }

    /**
     * Determine if Qdrant should generate embeddings natively.
     */
    protected function usesNativeEmbeddings(array $settings): bool
    {
        return ($settings['driver'] ?? null) === 'qdrant';
    }

    /**
     * Build an inference document.
     */
    protected function document(string $text, array $settings): array
    {
        return array_filter([
            'text' => $text,
            'model' => $settings['model'],
            'options' => $settings['options'] ?? null,
        ], fn ($value) => ! is_null($value));
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
     * Get the Qdrant collection for a search.
     */
    protected function collection(Builder $builder): QdrantCollection
    {
        return $this->qdrant->collection(
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
