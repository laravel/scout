<?php

namespace Laravel\Scout\Engines;

use BackedEnum;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Exceptions\NotSupportedException;
use Laravel\Scout\Exceptions\ScoutException;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use Laravel\Scout\Services\Turbopuffer\TurbopufferClient;

class TurbopufferEngine extends Engine
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

        $rows = $models->map(function ($model) {
            if (empty($searchableData = $model->toSearchableArray())) {
                return;
            }

            return array_merge(
                $searchableData,
                $model->scoutMetadata(),
                ['id' => $model->getScoutKey()],
            );
        })->filter()->values()->all();

        if (empty($rows)) {
            return;
        }

        $settings = $this->modelSettings($model);

        $parameters = ['upsert_rows' => $rows];

        foreach (['schema', 'distance_metric'] as $option) {
            if (isset($settings[$option])) {
                $parameters[$option] = $settings[$option];
            }
        }

        $this->turbopuffer->namespace($model->indexableAs())->write($parameters);
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

        $parameters = $this->buildSearchParameters($builder, 1);

        $count = $this->namespace($builder)->query(array_filter([
            'aggregate_by' => ['count' => ['Count']],
            'filters' => $parameters['filters'] ?? null,
            'consistency' => $parameters['consistency'] ?? null,
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

        if ($builder->callback) {
            return call_user_func($builder->callback, $namespace, $builder->query, $parameters);
        }

        $results = $namespace->query($parameters);

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

        $parameters = $builder->options;
        $nativeFilters = $parameters['filters'] ?? null;
        $scoutFilters = $this->filters($builder);

        unset($parameters['filters']);

        if (! isset($parameters['rank_by'])) {
            $parameters['rank_by'] = $this->rankBy($builder);
        } elseif (! empty($builder->orders)) {
            throw new ScoutException('Turbopuffer order clauses cannot be combined with a custom ranking expression.');
        }

        if ($filters = $this->combineFilters($nativeFilters, $scoutFilters)) {
            $parameters['filters'] = $filters;
        }

        if (isset($parameters['include_attributes']) && is_array($parameters['include_attributes'])) {
            $parameters['include_attributes'] = array_values(array_unique([
                ...$parameters['include_attributes'],
                'id',
            ]));
        }

        if (isset($parameters['exclude_attributes']) && is_array($parameters['exclude_attributes'])) {
            $parameters['exclude_attributes'] = array_values(array_diff($parameters['exclude_attributes'], ['id']));
        }

        $parameters['limit'] = min(max(1, $limit), 10000);

        return $parameters;
    }

    /**
     * Build the ranking expression for the query.
     */
    protected function rankBy(Builder $builder): array
    {
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
