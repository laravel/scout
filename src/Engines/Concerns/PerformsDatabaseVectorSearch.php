<?php

namespace Laravel\Scout\Engines\Concerns;

use Laravel\Scout\Builder;
use Laravel\Scout\Exceptions\NotSupportedException;
use Laravel\Scout\Exceptions\ScoutException;

trait PerformsDatabaseVectorSearch
{
    /**
     * Update the searchable embeddings for the given models.
     */
    protected function updateSearchableEmbeddings($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $model = $models->first();

        if (! $this->supportsVectorSearch($model) || ! method_exists($model, 'toSearchableEmbedding')) {
            return;
        }

        foreach (array_chunk($models->all(), 100) as $batch) {
            $inputs = [];
            $vectors = [];

            foreach ($batch as $index => $model) {
                $input = $model->toSearchableEmbedding();

                // If it's already an embedding, skip it...
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
                $generatedVectors = $this->generateEmbeddings(array_values($inputs));

                foreach (array_keys($inputs) as $position => $index) {
                    $vectors[$index] = $generatedVectors[$position];
                }
            }

            foreach ($batch as $index => $model) {
                $column = $this->embeddingColumn($model);
                $vector = $vectors[$index];

                $model->newModelQuery()
                    ->where($model->getKeyName(), $model->getKey())
                    ->toBase()
                    ->update([$column => json_encode($vector, JSON_THROW_ON_ERROR)]);

                $model->setAttribute($column, $vector);
            }
        }
    }

    /**
     * Get hybrid search results using weighted reciprocal rank fusion.
     */
    protected function hybridSearchModels(Builder $builder, int $limit)
    {
        if (! empty($builder->orders)) {
            throw new ScoutException('Database order clauses cannot be combined with hybrid search.');
        }

        if ($limit <= 0) {
            return $builder->model->newCollection();
        }

        // Generatee embeddings for the query...
        $vector = $this->generateEmbeddings([$builder->query])[0];

        $column = $builder->model->qualifyColumn(
            $this->embeddingColumn($builder->model)
        );

        $baseQuery = $this->newSearchQuery($builder);

        // Build the text query...
        $textQuery = $this->constrainSearchQuery($builder, $this->addTextSearchConstraints(
            clone $baseQuery,
            $builder,
            array_keys($builder->model->toSearchableArray()),
            $this->getPrefixColumns($builder),
            $this->getFullTextColumns($builder)
        )->take(1000));

        // Order the text query...
        if (! $this->getFullTextColumns($builder)) {
            $textQuery->orderBy($builder->model->getTable().'.'.$builder->model->getScoutKeyName(), 'desc');
        } elseif ($this->shouldOrderByRelevance($builder)) {
            $this->orderByRelevance($builder, $textQuery);

            $textQuery->orderBy($builder->model->getQualifiedKeyName());
        }

        // Build the semantic query...
        $semanticQuery = $this->constrainSearchQuery($builder, (clone $baseQuery)
            ->whereVectorSimilarTo($column, $vector, $this->minimumSimilarity($builder), false)
            ->orderByVectorDistance($column, $vector)
            ->orderBy($builder->model->getQualifiedKeyName())
            ->take(1000));

        return $this->fuseSearchResults(
            $builder,
            $textQuery->get(),
            $semanticQuery->get()
        )->take($limit)->values();
    }

    /**
     * Fuse text and semantic results using weighted reciprocal rank fusion.
     */
    protected function fuseSearchResults(Builder $builder, $textModels, $semanticModels)
    {
        [$scores, $models] = [[], []];

        foreach ([
            [$textModels, $builder->hybridSearch['text_weight']],
            [$semanticModels, $builder->hybridSearch['semantic_weight']],
        ] as [$rankedModels, $weight]) {
            $seen = [];

            foreach ($rankedModels->values() as $position => $model) {
                $key = (string) $model->getScoutKey();

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $models[$key] = $model;
                $scores[$key] = ($scores[$key] ?? 0) + ($weight / (60 + $position + 1));
            }
        }

        uksort($scores, function ($left, $right) use ($scores) {
            return $scores[$right] <=> $scores[$left] ?: strcmp($left, $right);
        });

        return $builder->model->newCollection(
            array_map(fn ($key) => $models[$key], array_keys($scores))
        );
    }

    /**
     * Build a semantic search query.
     */
    protected function buildSemanticSearchQuery(Builder $builder)
    {
        $this->ensureSemanticSearchIsSupported($builder);

        if (! empty($builder->orders)) {
            throw new ScoutException('Database order clauses cannot be combined with semantic search.');
        }

        $vector = $this->generateEmbeddings([$builder->query])[0];

        $column = $builder->model->qualifyColumn(
            $this->embeddingColumn($builder->model)
        );

        $query = $this->newSearchQuery($builder)
            ->whereVectorSimilarTo($column, $vector, $this->minimumSimilarity($builder), false)
            ->orderByVectorDistance($column, $vector)
            ->orderBy($builder->model->getQualifiedKeyName())
            ->take($builder->limit);

        return $this->constrainSearchQuery($builder, $query);
    }

    /**
     * Get the minimum similarity for a semantic search.
     */
    protected function minimumSimilarity(Builder $builder): float
    {
        $similarity = $builder->minimumSimilarity ?? 0.6;

        if (! is_numeric($similarity) || $similarity < 0 || $similarity > 1) {
            throw new ScoutException('The minimum similarity must be between 0 and 1.');
        }

        return (float) $similarity;
    }

    /**
     * Apply Scout's non-search constraints to a query.
     */
    protected function constrainSearchQuery(Builder $builder, $query)
    {
        return $this->constrainForSoftDeletes($builder, $this->addAdditionalConstraints($builder, $query));
    }

    /**
     * Generate embeddings using the optional Laravel AI SDK.
     */
    protected function generateEmbeddings(array $inputs): array
    {
        $embeddingsClass = 'Laravel\\Ai\\Embeddings';

        if (! class_exists($embeddingsClass)) {
            throw new ScoutException('Semantic search requires the Laravel AI SDK. Please install the [laravel/ai] package.');
        }

        $embeddings = $embeddingsClass::for(array_values($inputs))
            ->cache()
            ->generate()->embeddings;

        if (! is_array($embeddings) || count($embeddings) !== count($inputs)) {
            throw new ScoutException('Laravel AI returned an unexpected number of embeddings.');
        }

        return $embeddings;
    }

    /**
     * Get the model's embedding column.
     */
    protected function embeddingColumn($model): string
    {
        $column = method_exists($model, 'searchableEmbeddingColumn')
            ? $model->searchableEmbeddingColumn()
            : 'embedding';

        if (! is_string($column) || trim($column) === '') {
            throw new ScoutException('The [searchableEmbeddingColumn] method must return a non-empty string.');
        }

        return $column;
    }

    /**
     * Determine if a hybrid search should use vector search.
     */
    protected function shouldPerformHybridSearch(Builder $builder): bool
    {
        return ! is_null($builder->hybridSearch) &&
            method_exists($builder->model, 'toSearchableEmbedding') &&
            $this->supportsVectorSearch($builder->model);
    }

    /**
     * Determine if the model's database supports vector queries.
     */
    protected function supportsVectorSearch($model): bool
    {
        return $model->getConnection()->getDriverName() === 'pgsql' &&
            method_exists($model->newQuery()->getQuery(), 'whereVectorSimilarTo');
    }

    /**
     * Ensure semantic search is supported by the model's database.
     */
    protected function ensureSemanticSearchIsSupported(Builder $builder): void
    {
        if (! $this->supportsVectorSearch($builder->model)) {
            throw new NotSupportedException('Database semantic search requires Laravel 13 and PostgreSQL with pgvector.');
        }

        if (! method_exists($builder->model, 'toSearchableEmbedding')) {
            throw new NotSupportedException('Database semantic search requires the model to define a [toSearchableEmbedding] method.');
        }
    }
}
