<?php

namespace Laravel\Scout\Engines;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
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
     * @var array
     */
    private array $groupBy = [];

    /**
     * @var int
     */
    private int $groupByLimit = 3;

    /**
     * @var string
     */
    private string $startTag = '<mark>';

    /**
     * @var string
     */
    private string $endTag = '</mark>';

    /**
     * @var int
     */
    private int $limitHits = -1;

    /**
     * @var array
     */
    private array $locationOrderBy = [];

    /**
     * @var array
     */
    private array $facetBy = [];

    /**
     * @var int
     */
    private int $maxFacetValues = 10;

    /**
     * @var bool
     */
    private bool $useCache = false;

    /**
     * @var int
     */
    private int $cacheTtl = 60;

    /**
     * @var int
     */
    private int $snippetThreshold = 30;

    /**
     * @var bool
     */
    private bool $exhaustiveSearch = false;

    /**
     * @var bool
     */
    private bool $prioritizeExactMatch = true;

    /**
     * @var bool
     */
    private bool $enableOverrides = true;

    /**
     * @var int
     */
    private int $highlightAffixNumTokens = 4;

    /**
     * @var string
     */
    private string $facetQuery = '';

    /**
     * @var array
     */
    private array $includeFields = [];

    /**
     * @var array
     */
    private array $excludeFields = [];

    /**
     * @var array
     */
    private array $highlightFields = [];

    /**
     * @var array
     */
    private array $highlightFullFields = [];

    /**
     * @var array
     */
    private array $pinnedHits = [];

    /**
     * @var array
     */
    private array $hiddenHits = [];

    /**
     * @var array
     */
    private array $optionsMulti = [];

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
     * @param \Illuminate\Database\Eloquent\Collection<int, Model>|Model[] $models
     *
     * @throws \Http\Client\Exception
     * @throws \JsonException
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @noinspection NotOptimalIfConditionsInspection
     */
    public function update($models): void
    {
        $collection = $this->getCollectionIndex($models->first());

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
            $collectionIndex = $this->getCollectionIndex($model);

            // TODO look into this vs $model->getKey()
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
     * @param \Laravel\Scout\Builder $builder
     * @param int $page
     * @param int|null $perPage
     *
     * @return array
     */
    private function buildSearchParams(Builder $builder, int $page, int|null $perPage): array
    {
        $params = [
            'q' => $builder->query,
            'query_by' => implode(',', $builder->model->typesenseQueryBy()),
            'filter_by' => $this->filters($builder),
            'per_page' => $perPage,
            'page' => $page,
            'highlight_start_tag' => $this->startTag,
            'highlight_end_tag' => $this->endTag,
            'exhaustive_search' => $this->exhaustiveSearch,
            'q'                          => $builder->query,
            'query_by'                   => implode(',', $builder->model->typesenseQueryBy()),
            'filter_by'                  => $this->filters($builder),
            'per_page'                   => $perPage,
            'page'                       => $page,
            'highlight_start_tag'        => $this->startTag,
            'highlight_end_tag'          => $this->endTag,
            'snippet_threshold'          => $this->snippetThreshold,
            'exhaustive_search'          => $this->exhaustiveSearch,
            'use_cache'                  => $this->useCache,
            'cache_ttl'                  => $this->cacheTtl,
            'prioritize_exact_match'     => $this->prioritizeExactMatch,
            'enable_overrides'           => $this->enableOverrides,
            'highlight_affix_num_tokens' => $this->highlightAffixNumTokens,
        ];

        if ($this->limitHits > 0) {
            $params['limit_hits'] = $this->limitHits;
        }

        if (!empty($this->groupBy)) {
            $params['group_by'] = implode(',', $this->groupBy);
            $params['group_limit'] = $this->groupByLimit;
        }

        if (!empty($this->facetBy)) {
            $params['facet_by'] = implode(',', $this->facetBy);
            $params['max_facet_values'] = $this->maxFacetValues;
        }

        if (!empty($this->facetQuery)) {
            $params['facet_query'] = $this->facetQuery;
        }

        if (!empty($this->includeFields)) {
            $params['include_fields'] = implode(',', $this->includeFields);
        }

        if (!empty($this->excludeFields)) {
            $params['exclude_fields'] = implode(',', $this->excludeFields);
        }

        if (!empty($this->highlightFields)) {
            $params['highlight_fields'] = implode(',', $this->highlightFields);
        }

        if (!empty($this->highlightFullFields)) {
            $params['highlight_full_fields'] = implode(',', $this->highlightFullFields);
        }

        if (!empty($this->pinnedHits)) {
            $params['pinned_hits'] = implode(',', $this->pinnedHits);
        }

        if (!empty($this->hiddenHits)) {
            $params['hidden_hits'] = implode(',', $this->hiddenHits);
        }

        if (!empty($this->locationOrderBy)) {
            $params['sort_by'] = $this->parseOrderByLocation(...$this->locationOrderBy);
        }

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
     * Parse location order by for sort_by.
     *
     * @param string $column
     * @param float $lat
     * @param float $lng
     * @param string $direction
     *
     * @return string
     * @noinspection PhpPureAttributeCanBeAddedInspection
     */
    private function parseOrderByLocation(string $column, float $lat, float $lng, string $direction = 'asc'): string
    {
        $direction = Str::lower($direction) === 'asc' ? 'asc' : 'desc';
        $str = $column . '(' . $lat . ', ' . $lng . ')';

        return $str . ':' . $direction;
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
        $documents = $this->getCollectionIndex($builder->model)
            ->getDocuments();
        if ($builder->callback) {
            return call_user_func($builder->callback, $documents, $builder->query, $options);
        }
        if(!$this->optionsMulti)
        {
            $documents = $this->getCollectionIndex($builder->model)
                ->getDocuments();
            if ($builder->callback) {
                return call_user_func($builder->callback, $documents, $builder->query, $options);
            }

            return $documents->search($options);
        } else {
            return $this->multiSearch(["searches" => $this->optionsMulti], $options);
        }
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
        $collection = $this->getCollectionIndex($model);
        $collection->delete();
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
     * You can aggregate search results into groups or buckets by specify one or more group_by fields. Separate multiple fields with a comma.
     *
     * @param mixed $groupBy
     *
     * @return $this
     */
    public function groupBy(array $groupBy): static
    {
        $this->groupBy = $groupBy;

        return $this;
    }

    /**
     * Maximum number of hits to be returned for every group. (default: 3).
     *
     * @param int $groupByLimit
     *
     * @return $this
     */
    public function groupByLimit(int $groupByLimit): static
    {
        $this->groupByLimit = $groupByLimit;

        return $this;
    }

    /**
     * The start tag used for the highlighted snippets. (default: <mark>).
     *
     * @param string $startTag
     *
     * @return $this
     */
    public function setHighlightStartTag(string $startTag): static
    {
        $this->startTag = $startTag;

        return $this;
    }

    /**
     * The end tag used for the highlighted snippets. (default: </mark>).
     *
     * @param string $endTag
     *
     * @return $this
     */
    public function setHighlightEndTag(string $endTag): static
    {
        $this->endTag = $endTag;

        return $this;
    }

    /**
     * Maximum number of hits that can be fetched from the collection (default: no limit).
     *
     * (page * per_page) should be less than this number for the search request to return results.
     *
     * @param int $limitHits
     *
     * @return $this
     */
    public function limitHits(int $limitHits): static
    {
        $this->limitHits = $limitHits;

        return $this;
    }

    /**
     * A list of fields that will be used for faceting your results on. Separate multiple fields with a comma.
     *
     * @param mixed $facetBy
     *
     * @return $this
     */
    public function facetBy(array $facetBy): static
    {
        $this->facetBy = $facetBy;

        return $this;
    }

    /**
     * Maximum number of facet values to be returned.
     *
     * @param int $maxFacetValues
     *
     * @return $this
     */
    public function setMaxFacetValues(int $maxFacetValues): static
    {
        $this->maxFacetValues = $maxFacetValues;

        return $this;
    }

    /**
     * Facet values that are returned can now be filtered via this parameter.
     *
     * The matching facet text is also highlighted. For example, when faceting by category,
     * you can set facet_query=category:shoe to return only facet values that contain the prefix "shoe".
     *
     * @param string $facetQuery
     *
     * @return $this
     */
    public function facetQuery(string $facetQuery): static
    {
        $this->facetQuery = $facetQuery;

        return $this;
    }

    /**
     * Comma-separated list of fields from the document to include in the search result.
     *
     * @param mixed $includeFields
     *
     * @return $this
     */
    public function setIncludeFields(array $includeFields): static
    {
        $this->includeFields = $includeFields;

        return $this;
    }

    /**
     * Comma-separated list of fields from the document to exclude in the search result.
     *
     * @param mixed $excludeFields
     *
     * @return $this
     */
    public function setExcludeFields(array $excludeFields): static
    {
        $this->excludeFields = $excludeFields;

        return $this;
    }

    /**
     * Comma separated list of fields that should be highlighted with snippetting.
     *
     * You can use this parameter to highlight fields that you don't query for, as well.
     *
     * @param mixed $highlightFields
     *
     * @return $this
     */
    public function setHighlightFields(array $highlightFields): static
    {
        $this->highlightFields = $highlightFields;

        return $this;
    }

    /**
     * A list of records to unconditionally include in the search results at specific positions.
     *
     * @param mixed $pinnedHits
     *
     * @return $this
     */
    public function setPinnedHits(array $pinnedHits): static
    {
        $this->pinnedHits = $pinnedHits;

        return $this;
    }

    /**
     * A list of records to unconditionally hide from search results.
     *
     * @param mixed $hiddenHits
     *
     * @return $this
     */
    public function setHiddenHits(array $hiddenHits): static
    {
        $this->hiddenHits = $hiddenHits;

        return $this;
    }

    /**
     * Comma separated list of fields which should be highlighted fully without snippeting.
     *
     * @param mixed $highlightFullFields
     *
     * @return $this
     */
    public function setHighlightFullFields(array $highlightFullFields): static
    {
        $this->highlightFullFields = $highlightFullFields;

        return $this;
    }

    /**
     * The number of tokens that should surround the highlighted text on each side.
     *
     * This controls the length of the snippet.
     *
     * @param int $highlightAffixNumTokens
     *
     * @return $this
     */
    public function setHighlightAffixNumTokens(int $highlightAffixNumTokens): static
    {
        $this->highlightAffixNumTokens = $highlightAffixNumTokens;

        return $this;
    }

    /**
     * Field values under this length will be fully highlighted, instead of showing a snippet of relevant portion.
     *
     * @param int $snippetThreshold
     *
     * @return $this
     */
    public function setSnippetThreshold(int $snippetThreshold): static
    {
        $this->snippetThreshold = $snippetThreshold;

        return $this;
    }

    /**
     * Setting this to true will make Typesense consider all variations of prefixes and typo corrections of the words
     *
     * in the query exhaustively, without stopping early when enough results are found.
     *
     * @param bool $exhaustiveSearch
     *
     * @return $this
     */
    public function exhaustiveSearch(bool $exhaustiveSearch): static
    {
        $this->exhaustiveSearch = $exhaustiveSearch;

        return $this;
    }

    /**
     * Enable server side caching of search query results. By default, caching is disabled.
     *
     * @param bool $useCache
     *
     * @return $this
     */
    public function setUseCache(bool $useCache): static
    {
        $this->useCache = $useCache;

        return $this;
    }

    /**
     * The duration (in seconds) that determines how long the search query is cached.
     *
     * @param int $cacheTtl
     *
     * @return $this
     */
    public function setCacheTtl(int $cacheTtl): static
    {
        $this->cacheTtl = $cacheTtl;

        return $this;
    }

    /**
     * By default, Typesense prioritizes documents whose field value matches exactly with the query.
     *
     * @param bool $prioritizeExactMatch
     *
     * @return $this
     */
    public function setPrioritizeExactMatch(bool $prioritizeExactMatch): static
    {
        $this->prioritizeExactMatch = $prioritizeExactMatch;

        return $this;
    }

    /**
     * If you have some overrides defined but want to disable all of them for a particular search query
     *
     * @param bool $enableOverrides
     *
     * @return $this
     */
    public function enableOverrides(bool $enableOverrides): static
    {
        $this->enableOverrides = $enableOverrides;

        return $this;
    }

    /**
     * If you want to search multi queries in the same call
     *
     * @param array $optionsMulti
     *
     * @return $this
     */
    public function searchMulti(array $optionsMulti): static
    {
        $this->optionsMulti = $optionsMulti;

        return $this;
    }

    /**
     * Add location to order by clause.
     *
     * @param string $column
     * @param float $lat
     * @param float $lng
     * @param string $direction
     *
     * @return $this
     */
    public function orderByLocation(string $column, float $lat, float $lng, string $direction): static
    {
        $this->locationOrderBy = [
            'column' => $column,
            'lat' => $lat,
            'lng' => $lng,
            'direction' => $direction,
        ];

        return $this;
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
        return $this->deleteCollection($name);
    }







    /**
     * @return \Typesense\Client
     */
    public function getClient(): Typesense
    {
        return $this->typesense;
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
     * @param $model
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return TypesenseCollection
     */
    public function getCollectionIndex($model): TypesenseCollection
    {
        return $this->getOrCreateCollectionFromModel($model);
    }

    /**
     * @param TypesenseCollection $collectionIndex
     * @param                       $array
     *
     * @throws \Typesense\Exceptions\ObjectNotFound
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return \Laravel\Scout\Classes\TypesenseDocumentIndexResponse
     */
    public function upsertDocument(TypesenseCollection $collectionIndex, $array): TypesenseDocumentIndexResponse
    {
        /**
         * @var $document Document
         */
        $document = $collectionIndex->getDocuments()[$array['id']];

        try {
            $document->retrieve();
            $document->delete();

            return new TypesenseDocumentIndexResponse(200, true, null, $collectionIndex->getDocuments()
                                                                                       ->create($array));
        } catch (ObjectNotFound) {
            return new TypesenseDocumentIndexResponse(200, true, null, $collectionIndex->getDocuments()
                                                                                       ->create($array));
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
    public function deleteDocument(TypesenseCollection $collectionIndex, $modelId): array
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
     * @param TypesenseCollection $collectionIndex
     * @param array                 $query
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return array
     */
    public function deleteDocuments(TypesenseCollection $collectionIndex, array $query): array
    {
        return $collectionIndex->getDocuments()
                               ->delete($query);
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
    public function importDocuments(TypesenseCollection $collectionIndex, $documents, string $action = 'upsert'): \Illuminate\Support\Collection
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

    /**
     * @param string $collectionName
     *
     * @throws \Typesense\Exceptions\ObjectNotFound
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @return array
     */
    public function deleteCollection(string $collectionName): array
    {
        return $this->typesense->getCollections()->{$collectionName}->delete();
    }

    /**
     * @param array $searchRequests
     * @param array $commonSearchParams
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     */
    public function multiSearch(array $searchRequests, array $commonSearchParams): array
    {
        return $this->typesense->multiSearch->perform($searchRequests, $commonSearchParams);
    }
}
