<?php

namespace Laravel\Scout\Engines;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Classes\TypesenseDocumentIndexResponse;
use Typesense\Client as Typesense;
use Typesense\Collection as TypesenseCollection;
use Typesense\Document;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\TypesenseClientError;

/**
 * Class TypesenseEngine.
 *
 * @date    4/5/20
 *
 * @author  Abdullah Al-Faqeir <abdullah@devloops.net>
 */
class TypesenseEngine extends Engine
{
    /**
     * @var Typesense
     */
    private Typesense $typesense;

    /**
     * TypesenseEngine constructor.
     *
     * @param Typesense $typesense
     */
    public function __construct(Typesense $typesense)
    {
        $this->typesense = $typesense;
    }

    /**
     * Dynamically call the Typesense client instance.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->typesense->$method(...$parameters);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, Model>|Model[] $models
     *
     * @throws \Http\Client\Exception
     * @throws \JsonException
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @noinspection NotOptimalIfConditionsInspection
     */
    public function update($models): void
    {
        $collection = $this->getOrCreateCollectionFromModel($models->first());

        if ($this->usesSoftDelete($models->first()) && config('scout.soft_delete', false)) {
            $models->each->pushSoftDeleteMetadata();
        }

        if (!$this->usesSoftDelete($models->first()) || is_null($models->first()?->deleted_at) || config('scout.soft_delete', false)) {
            $this->importDocuments($collection, $models->map(fn($m) => $m->toSearchableArray())
                ->toArray());
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection $models
     *
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    public function delete($models): void
    {
        $models->each(function (Model $model) {
            $collectionIndex = $this->getOrCreateCollectionFromModel($model);
            $this->deleteDocument($collectionIndex, $model->getScoutKey());
        });
    }

    /**
     * @param \Laravel\Scout\Builder $builder
     *
     * @return mixed
     * @throws \Http\Client\Exception
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    public function search(Builder $builder): mixed
    {
        return $this->performSearch($builder, array_filter($this->buildSearchParams($builder, 1, $builder->limit)));
    }

    /**
     * @param \Laravel\Scout\Builder $builder
     * @param int $perPage
     * @param int $page
     *
     * @return mixed
     * @throws \Http\Client\Exception
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    public function paginate(Builder $builder, $perPage, $page): mixed
    {
        return $this->performSearch($builder, array_filter($this->buildSearchParams($builder, $page, $perPage)));
    }

    /**
     * @param mixed $results
     *
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results): Collection
    {
        return collect($results['hits'])
            ->pluck('document.id')
            ->values();
    }

    /**
     * @param \Laravel\Scout\Builder $builder
     * @param mixed $results
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->getTotalCount($results) === 0) {
            return $model->newCollection();
        }

        $hits = isset($results['grouped_hits']) && !empty($results['grouped_hits']) ?
            $results['grouped_hits'] :
            $results['hits'];
        $pluck = isset($results['grouped_hits']) && !empty($results['grouped_hits']) ?
            'hits.0.document.id' :
            'document.id';

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
     * @param \Laravel\Scout\Builder $builder
     * @param mixed $results
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        if ((int)($results['found'] ?? 0) === 0) {
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
     * @inheritDoc
     */
    public function getTotalCount($results): int
    {
        return (int)($results['found'] ?? 0);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    public function flush($model): void
    {
        $collection = $this->getOrCreateCollectionFromModel($model);
        $collection->delete();
    }

    /**
     * @param string $name
     * @param array $options
     *
     * @return void
     * @throws \Exception
     *
     */
    public function createIndex($name, array $options = []): void
    {
        throw new Exception('Typesense indexes are created automatically upon adding objects.');
    }

    /**
     * @param string $name
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @throws \Typesense\Exceptions\ObjectNotFound
     */
    public function deleteIndex($name): array
    {
        return $this->typesense->getCollections()->{$name}->delete();;
    }

    /**
     * Parse typesense where filter.
     *
     * @param array|string $value
     * @param string $key
     *
     * @return string
     */
    public function parseWhereFilter(array|string $value, string $key): string
    {
        if (is_array($value)) {
            return sprintf('%s:%s', $key, implode('', $value));
        }

        return sprintf('%s:=%s', $key, $value);
    }

    /**
     * Parse typesense  whereIn filter.
     *
     * @param array $value
     * @param string $key
     *
     * @return string
     */
    public function parseWhereInFilter(array $value, string $key): string
    {
        return sprintf('%s:=%s', $key, '[' . implode(', ', $value) . ']');
    }

    /**
     * @param \Laravel\Scout\Builder $builder
     * @param array $options
     *
     * @return mixed
     * @throws \Http\Client\Exception
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    protected function performSearch(Builder $builder, array $options = []): mixed
    {
        $documents = $this->getOrCreateCollectionFromModel($builder->model)
            ->getDocuments();
        if ($builder->callback) {
            return call_user_func($builder->callback, $documents, $builder->query, $options);
        }

        return $documents->search($options);
    }

    /**
     * @param $model
     *
     * @return bool
     */
    protected function usesSoftDelete($model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    /**
     * Prepare filters.
     *
     * @param Builder $builder
     *
     * @return string
     */
    protected function filters(Builder $builder): string
    {
        $whereFilter = collect($builder->wheres)
            ->map([
                $this,
                'parseWhereFilter',
            ])
            ->values()
            ->implode(' && ');

        $whereInFilter = collect($builder->whereIns)
            ->map([
                $this,
                'parseWhereInFilter',
            ])
            ->values()
            ->implode(' && ');

        return $whereFilter . (
            ($whereFilter !== '' && $whereInFilter !== '') ? ' && ' : ''
            ) . $whereInFilter;
    }

    /**
     * @param \Laravel\Scout\Builder $builder
     * @param int $page
     * @param int|null $perPage
     *
     * @return array
     */
    private function buildSearchParams(Builder $builder, int $page, int|null $perPage): array
    {
        $params = [
            'q'                          => $builder->query,
            'query_by'                   => implode(',', $builder->model->typesenseQueryBy()),
            'filter_by'                  => $this->filters($builder),
            'per_page'                   => $perPage,
            'page'                       => $page,
            'highlight_start_tag'        => '<mark>',
            'highlight_end_tag'          => '</mark>',
            'snippet_threshold'          => 30,
            'exhaustive_search'          => false,
            'use_cache'                  => false,
            'cache_ttl'                  => 60,
            'prioritize_exact_match'     => true,
            'enable_overrides'           => true,
            'highlight_affix_num_tokens' => 4,
        ];

        if (!empty($builder->orders)) {
            if (!empty($params['sort_by'])) {
                $params['sort_by'] .= ',';
            } else {
                $params['sort_by'] = '';
            }
            $params['sort_by'] .= $this->parseOrderBy($builder->orders);
        }

        return $params;
    }

    /**
     * Parse sort_by fields.
     *
     * @param array $orders
     *
     * @return string
     */
    private function parseOrderBy(array $orders): string
    {
        $sortByArr = [];
        foreach ($orders as $order) {
            $sortByArr[] = $order['column'] . ':' . $order['direction'];
        }

        return implode(',', $sortByArr);
    }

    /**
     * @param $model
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return TypesenseCollection
     */
    private function getOrCreateCollectionFromModel($model): TypesenseCollection
    {
        $index = $this->typesense->getCollections()->{$model->searchableAs()};

        try {
            $index->retrieve();

            return $index;
        } catch (ObjectNotFound $exception) {
            $this->typesense->getCollections()
                         ->create($model->getCollectionSchema());

            return $this->typesense->getCollections()->{$model->searchableAs()};
        }
    }

    /**
     * @param TypesenseCollection $collectionIndex
     * @param                       $modelId
     *
     * @throws \Typesense\Exceptions\ObjectNotFound
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return array
     */
    private function deleteDocument(TypesenseCollection $collectionIndex, $modelId): array
    {
        /**
         * @var $document Document
         */
        $document = $collectionIndex->getDocuments()[(string) $modelId];

        try {
            $document->retrieve();

            return $document->delete();
        } catch (\Exception $exception) {
            return [];
        }
    }

    /**
     * @param TypesenseCollection   $collectionIndex
     * @param                       $documents
     * @param string                $action
     *
     * @throws \JsonException
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return \Illuminate\Support\Collection
     */
    private function importDocuments(TypesenseCollection $collectionIndex, $documents, string $action = 'upsert'): \Illuminate\Support\Collection
    {
        $importedDocuments = $collectionIndex->getDocuments()
                                             ->import($documents, ['action' => $action]);

        $result = [];
        foreach ($importedDocuments as $importedDocument) {
            if (!$importedDocument['success']) {
                throw new TypesenseClientError("Error importing document: {$importedDocument['error']}");
            }

            $result[] = new TypesenseDocumentIndexResponse($importedDocument['code'] ?? 0, $importedDocument['success'], $importedDocument['error'] ?? null, json_decode($importedDocument['document'] ?? '[]', true, 512, JSON_THROW_ON_ERROR));
        }

        return collect($result);
    }
}
