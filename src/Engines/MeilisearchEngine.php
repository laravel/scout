<?php

namespace Laravel\Scout\Engines;

use BackedEnum;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\SupportsSemanticSearch;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
use Laravel\Scout\Exceptions\ScoutException;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Meilisearch\Client as MeilisearchClient;
use Meilisearch\Contracts\IndexesQuery;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Meilisearch;
use Meilisearch\Search\SearchResult;

class MeilisearchEngine extends Engine implements SupportsSemanticSearch, UpdatesIndexSettings
{
    /**
     * The Meilisearch client.
     *
     * @var \Meilisearch\Client
     */
    protected $meilisearch;

    /**
     * Determines if soft deletes for Scout are enabled or not.
     *
     * @var bool
     */
    protected $softDelete;

    /**
     * The Meilisearch configuration.
     *
     * @var array
     */
    protected $config;

    /**
     * Create a new MeilisearchEngine instance.
     *
     * @param  bool  $softDelete
     * @return void
     */
    public function __construct(MeilisearchClient $meilisearch, $softDelete = false, array $config = [])
    {
        $this->meilisearch = $meilisearch;
        $this->softDelete = $softDelete;
        $this->config = $config;
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     *
     * @throws \Meilisearch\Exceptions\ApiException
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $this->meilisearch->index($models->first()->indexableAs());

        if ($this->usesSoftDelete($models->first()) && $this->softDelete) {
            $models->each->pushSoftDeleteMetadata();
        }

        $records = $models->map(function ($model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return;
            }

            return [
                'model' => $model,
                'object' => array_merge(
                    $searchableData,
                    $model->scoutMetadata(),
                    [$model->getScoutKeyName() => $model->getScoutKey()],
                ),
            ];
        })
            ->filter()
            ->values()
            ->all();

        if (! empty($records)) {
            $settings = $this->modelSettings($models->first());

            $objects = isset($settings['embedding'])
                ? $this->addEmbeddingsToRecords($records, $this->embeddingSettings($models->first()))
                : array_column($records, 'object');

            $index->addDocuments($objects, $models->first()->getScoutKeyName());
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

                if (isset($object['_vectors']) && ! is_array($object['_vectors'])) {
                    throw new ScoutException('The Meilisearch [_vectors] attribute must be an array.');
                }

                $object['_vectors'][$settings['embedder']] = $vectors[$index];
                $objects[] = $object;
            }
        }

        return $objects;
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $index = $this->meilisearch->index($models->first()->indexableAs());

        $keys = $models instanceof RemoveableScoutCollection
            ? $models->pluck($models->first()->getScoutKeyName())
            : $models->map->getScoutKey();

        $index->deleteDocuments($keys->values()->all());
    }

    /**
     * Perform the given search on the engine.
     *
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_merge(array_filter([
            'filter' => $this->filters($builder),
            'hitsPerPage' => $builder->limit,
            'sort' => $this->buildSortFromOrderByClauses($builder),
        ]), $this->semanticSearchParameters($builder)));
    }

    /**
     * Perform the given search on the engine.
     *
     * page/hitsPerPage ensures that the search is exhaustive.
     *
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, array_merge(array_filter([
            'filter' => $this->filters($builder),
            'hitsPerPage' => (int) $perPage,
            'page' => $page,
            'sort' => $this->buildSortFromOrderByClauses($builder),
        ]), $this->semanticSearchParameters($builder)));
    }

    /**
     * Build semantic and hybrid search parameters.
     */
    protected function semanticSearchParameters(Builder $builder): array
    {
        if (! $builder->semanticSearch && is_null($builder->hybridSearch)) {
            return [];
        }

        if (array_key_exists('hybrid', $builder->options)) {
            throw new ScoutException('Meilisearch semantic and hybrid searches cannot be combined with a custom [hybrid] option.');
        }

        $settings = $this->embeddingSettings($builder->model);

        $vector = $builder->options['vector'] ??
            $this->generateEmbeddings([$builder->query], $settings)[0];

        if (! is_array($vector)) {
            throw new ScoutException('The Meilisearch query [vector] must be an embedding array.');
        }

        $semanticRatio = $builder->semanticSearch
            ? 1.0
            : $builder->hybridSearch['semantic_weight'] / array_sum($builder->hybridSearch);

        $parameters = [
            'vector' => $vector,
            'hybrid' => [
                'embedder' => $settings['embedder'],
                'semanticRatio' => $semanticRatio,
            ],
        ];

        if (! is_null($builder->minimumSimilarity)) {
            $parameters['rankingScoreThreshold'] = $builder->minimumSimilarity;
        }

        return $parameters;
    }

    /**
     * Perform the given search on the engine.
     *
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $searchParams = [])
    {
        $meilisearch = $this->meilisearch->index($builder->index ?: $builder->model->searchableAs());

        $searchParams = array_merge($builder->options, $searchParams);

        if (array_key_exists('attributesToRetrieve', $searchParams)) {
            $searchParams['attributesToRetrieve'] = array_merge(
                [$builder->model->getScoutKeyName()],
                $searchParams['attributesToRetrieve'],
            );
        }

        if ($builder->callback) {
            $result = call_user_func(
                $builder->callback,
                $meilisearch,
                $builder->query,
                $searchParams
            );

            $searchResultClass = class_exists(SearchResult::class)
                ? SearchResult::class
                : \Meilisearch\Search\SearchResult;

            return $result instanceof $searchResultClass ? $result->getRaw() : $result;
        }

        return $meilisearch->rawSearch($builder->query, $searchParams);
    }

    /**
     * Get the filter array for the query.
     *
     * @return string
     */
    protected function filters(Builder $builder)
    {
        $filters = collect($builder->wheres)
            ->map(function ($where) {
                $field = $where['field'];
                $value = $where['value'];
                $operator = $where['operator'];

                if ($value instanceof BackedEnum) {
                    return sprintf('%s%s%s', $field, $operator, $value->value);
                }

                if (is_bool($value)) {
                    return sprintf('%s%s%s', $field, $operator, $value ? 'true' : 'false');
                }

                if (is_null($value)) {
                    return sprintf('%s %s', $field, $operator === '!=' ? 'IS NOT NULL' : 'IS NULL');
                }

                return is_numeric($value)
                    ? sprintf('%s%s%s', $field, $operator, $value)
                    : sprintf('%s%s"%s"', $field, $operator, $value);
            });

        $whereInOperators = [
            'whereIns' => 'IN',
            'whereNotIns' => 'NOT IN',
        ];

        foreach ($whereInOperators as $property => $operator) {
            if (property_exists($builder, $property)) {
                foreach ($builder->{$property} as $key => $values) {
                    $filters->push(sprintf('%s %s [%s]', $key, $operator, collect($values)->map(function ($value) {
                        if (is_bool($value)) {
                            return sprintf('%s', $value ? 'true' : 'false');
                        }

                        return filter_var($value, FILTER_VALIDATE_INT) !== false
                            ? sprintf('%s', $value)
                            : sprintf('"%s"', $value);
                    })->values()->implode(', ')));
                }
            }
        }

        return $filters->values()->implode(' AND ');
    }

    /**
     * Get the sort array for the query.
     */
    protected function buildSortFromOrderByClauses(Builder $builder): array
    {
        return collect($builder->orders)
            ->map(fn (array $order) => $order['column'].':'.$order['direction'])
            ->toArray();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * This expects the first item of each search item array to be the primary key.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        if (count($results['hits']) === 0) {
            return collect();
        }

        $hits = collect($results['hits']);

        $key = key($hits->first());

        return $hits->pluck($key)->values();
    }

    /**
     * Pluck the given results with the given primary key name.
     *
     * @param  mixed  $results
     * @param  string  $key
     * @return \Illuminate\Support\Collection
     */
    public function mapIdsFrom($results, $key)
    {
        return count($results['hits']) === 0
            ? collect()
            : collect($results['hits'])->pluck($key)->values();
    }

    /**
     * Get the results of the query as a Collection of primary keys.
     *
     * @return \Illuminate\Support\Collection
     */
    public function keys(Builder $builder)
    {
        $scoutKey = $builder->model->getScoutKeyName();

        return $this->mapIdsFrom($this->search($builder), $scoutKey);
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        if (is_null($results) || count($results['hits']) === 0) {
            return $model->newCollection();
        }

        $objectIds = collect($results['hits'])->pluck($model->getScoutKeyName())->values()->all();

        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(fn ($model) => in_array($model->getScoutKey(), $objectIds))
            ->map(function ($model) use ($results, $objectIdPositions) {
                $result = $results['hits'][$objectIdPositions[$model->getScoutKey()]] ?? [];

                foreach ($result as $key => $value) {
                    if (substr($key, 0, 1) === '_') {
                        $model->withScoutMetadata($key, $value);
                    }
                }

                return $model;
            })
            ->sortBy(fn ($model) => $objectIdPositions[$model->getScoutKey()])
            ->values();
    }

    /**
     * Map the given results to instances of the given model via a lazy collection.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        if (count($results['hits']) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = collect($results['hits'])->pluck($model->getScoutKeyName())->values()->all();
        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(fn ($model) => in_array($model->getScoutKey(), $objectIds))
            ->map(function ($model) use ($results, $objectIdPositions) {
                $result = $results['hits'][$objectIdPositions[$model->getScoutKey()]] ?? [];

                foreach ($result as $key => $value) {
                    if (substr($key, 0, 1) === '_') {
                        $model->withScoutMetadata($key, $value);
                    }
                }

                return $model;
            })
            ->sortBy(fn ($model) => $objectIdPositions[$model->getScoutKey()])
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
        return $results['totalHits'] ?? $results['estimatedTotalHits'];
    }

    /**
     * Flush all of the model's records from the engine.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return void
     */
    public function flush($model)
    {
        $index = $this->meilisearch->index($model->indexableAs());

        $index->deleteAllDocuments();
    }

    /**
     * Create a search index.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \Meilisearch\Exceptions\ApiException
     */
    public function createIndex($name, array $options = [])
    {
        try {
            $index = $this->meilisearch->getIndex($name);
        } catch (ApiException $e) {
            $index = null;
        }

        if ($index?->getUid() !== null) {
            return $index;
        }

        return $this->meilisearch->createIndex($name, $options);
    }

    /**
     * Update the index settings for the given index.
     *
     * @return void
     */
    public function updateIndexSettings($name, array $settings = [])
    {
        $index = $this->meilisearch->index($name);

        $index->updateSettings(Arr::except($settings, 'embedders'));

        if (! empty($settings['embedders'])) {
            $index->updateEmbedders($settings['embedders']);
        }
    }

    /**
     * Configure the soft delete filter within the given settings.
     *
     * @return array
     */
    public function configureSoftDeleteFilter(array $settings = [])
    {
        $settings['filterableAttributes'][] = '__soft_deleted';

        return $settings;
    }

    /**
     * Delete a search index.
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws \Meilisearch\Exceptions\ApiException
     */
    public function deleteIndex($name)
    {
        return $this->meilisearch->deleteIndex($name);
    }

    /**
     * Delete all search indexes.
     *
     * @return mixed
     */
    public function deleteAllIndexes()
    {
        $tasks = [];
        $limit = 1000000;

        $query = new IndexesQuery;
        $query->setLimit($limit);

        $indexes = $this->meilisearch->getIndexes($query);

        foreach ($indexes->getResults() as $index) {
            if (str($index->getUid())->startsWith(Config::get('scout.prefix'))) {
                $tasks[] = $index->delete();
            }
        }

        return $tasks;
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
            throw new ScoutException('No Meilisearch embedding settings have been configured for ['.get_class($model).'].');
        }

        if (! isset($settings['embedder']) || ! is_string($settings['embedder']) || trim($settings['embedder']) === '') {
            throw new ScoutException('Meilisearch embedding settings must contain an [embedder].');
        }

        if (! isset($settings['dimensions']) ||
            filter_var($settings['dimensions'], FILTER_VALIDATE_INT) === false ||
            $settings['dimensions'] < 1) {
            throw new ScoutException('Meilisearch embedding settings must contain positive [dimensions].');
        }

        $settings['dimensions'] = (int) $settings['dimensions'];

        return $settings;
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
     * Determine if the given model uses soft deletes.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return bool
     */
    protected function usesSoftDelete($model)
    {
        return in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model));
    }

    /**
     * Dynamically call the Meilisearch client instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->meilisearch->$method(...$parameters);
    }
}
