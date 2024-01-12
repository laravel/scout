<?php

namespace Laravel\Scout\Engines;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use stdClass;
use Typesense\Client as Typesense;
use Typesense\Collection as TypesenseCollection;
use Typesense\Document;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\TypesenseClientError;

class TypesenseEngine extends Engine
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
     * Create new Typesense engine instance.
     *
     * @param  \Typesense  $typesense
     */
    public function __construct(Typesense $typesense)
    {
        $this->typesense = $typesense;
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

        $objects = $models->map(function ($model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return;
            }

            return array_merge(
                $searchableData,
                $model->scoutMetadata(),
            );
        })->filter()->values()->all();

        if (! empty($objects)) {
            $this->importDocuments(
                $collection,
                $objects
            );
        }
    }

    /**
     * Import the given documents into the index.
     *
     * @param  \TypesenseCollection  $collectionIndex
     * @param  array  $documents
     * @param  string  $action
     * @return \Illuminate\Support\Collection
     *
     * @throws \JsonException
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    protected function importDocuments(TypesenseCollection $collectionIndex, array $documents, string $action = 'upsert'): Collection
    {
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
     * @param  \TypesenseCollection  $collectionIndex
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
        return $this->performSearch(
            $builder,
            array_filter($this->buildSearchParameters($builder, 1, $builder->limit))
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
        return $this->performSearch(
            $builder,
            array_filter($this->buildSearchParameters($builder, $page, $perPage))
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
        $documents = $this->getOrCreateCollectionFromModel($builder->model)->getDocuments();

        if ($builder->callback) {
            return call_user_func($builder->callback, $documents, $builder->query, $options);
        }

        return $documents->search($options);
    }

    /**
     * Build the search parameters for a given Scout query builder.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $page
     * @param  int|null  $perPage
     * @return array
     */
    public function buildSearchParameters(Builder $builder, int $page, int|null $perPage): array
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
        ];

        if (! empty($this->searchParameters)) {
            $parameters = array_merge($parameters, $this->searchParameters);
        }

        if (! empty($builder->orders)) {
            if (! empty($parameters['sort_by'])) {
                $parameters['sort_by'] .= ',';
            } else {
                $parameters['sort_by'] = '';
            }

            $parameters['sort_by'] .= $this->parseOrderBy($builder->orders);
        }

        return $parameters;
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
            ->map(fn ($value, $key) => $this->parseWhereFilter($value, $key))
            ->values()
            ->implode(' && ');

        $whereInFilter = collect($builder->whereIns)
            ->map(fn ($value, $key) => $this->parseWhereInFilter($value, $key))
            ->values()
            ->implode(' && ');

        return $whereFilter.(
            ($whereFilter !== '' && $whereInFilter !== '') ? ' && ' : ''
        ).$whereInFilter;
    }

    /**
     * Create a "where" filter string.
     *
     * @param  array|string  $value
     * @param  string  $key
     * @return string
     */
    protected function parseWhereFilter(array|string $value, string $key): string
    {
        return is_array($value)
            ? sprintf('%s:%s', $key, implode('', $value))
            : sprintf('%s:=%s', $key, $value);
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
        return sprintf('%s:=%s', $key, '['.implode(', ', $value).']');
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
            ->filter(static function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds, false);
            })
            ->sortBy(static function ($model) use ($objectIdPositions) {
                return $objectIdPositions[$model->getScoutKey()];
            })
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
            ->filter(static function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds, false);
            })
            ->sortBy(static function ($model) use ($objectIdPositions) {
                return $objectIdPositions[$model->getScoutKey()];
            })
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
     * @throws \Exception
     */
    public function createIndex($name, array $options = [])
    {
        throw new Exception('Typesense indexes are created automatically upon adding objects.');
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
     * @return \TypesenseCollection
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    protected function getOrCreateCollectionFromModel($model): TypesenseCollection
    {
        $index = $this->typesense->getCollections()->{$model->searchableAs()};

        try {
            $index->retrieve();

            return $index;
        } catch (ObjectNotFound $exception) {
            $schema = config('scout.typesense.model-settings.'.get_class($model).'.collection-schema') ?? [];

            if (! isset($schema['name'])) {
                $schema['name'] = $model->searchableAs();
            }

            $this->typesense->getCollections()->create($schema);

            return $this->typesense->getCollections()->{$model->searchableAs()};
        }
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
     * Set the search options provided by user.
     *
     * @param  array  $options
     * @return $this
     */
    public function withSearchParameters(array $options): static
    {
        return $this->setSearchParameters($options);
    }

    /**
     * Set the search options provided by user.
     *
     * @param  array  $options
     * @return $this
     */
    public function setSearchParameters(array $options): static
    {
        $this->searchParameters = $options;

        return $this;
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
