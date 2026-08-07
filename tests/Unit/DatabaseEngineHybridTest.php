<?php

namespace Laravel\Scout\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\DatabaseEngine;
use PHPUnit\Framework\TestCase;

class DatabaseEngineHybridTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => '/');
    }

    public function test_it_fuses_text_and_semantic_results_using_weighted_rrf()
    {
        $model = new DatabaseHybridModel;
        $textModels = $model->newCollection([
            new DatabaseHybridModel(['id' => 1, 'name' => 'Text first']),
            new DatabaseHybridModel(['id' => 2, 'name' => 'Both']),
        ]);
        $semanticModels = $model->newCollection([
            new DatabaseHybridModel(['id' => 2, 'name' => 'Both']),
            new DatabaseHybridModel(['id' => 3, 'name' => 'Semantic only']),
        ]);

        $results = $this->fuse(
            (new Builder($model, 'query'))->hybrid(),
            $textModels,
            $semanticModels
        );

        $this->assertSame([2, 1, 3], $results->modelKeys());
    }

    public function test_hybrid_weights_affect_rrf_ordering()
    {
        $model = new DatabaseHybridModel;
        $textModels = $model->newCollection([
            new DatabaseHybridModel(['id' => 1, 'name' => 'Text first']),
            new DatabaseHybridModel(['id' => 2, 'name' => 'Text second']),
        ]);
        $semanticModels = $model->newCollection([
            new DatabaseHybridModel(['id' => 3, 'name' => 'Semantic first']),
        ]);

        $results = $this->fuse(
            (new Builder($model, 'query'))->hybrid(textWeight: 1, semanticWeight: 10),
            $textModels,
            $semanticModels
        );

        $this->assertSame([3, 1, 2], $results->modelKeys());
    }

    public function test_hybrid_pagination_fetches_and_slices_the_ranked_window()
    {
        $model = new DatabaseHybridModel;
        $engine = new class extends DatabaseEngine
        {
            public $requestedWindow;

            protected function shouldPerformHybridSearch(Builder $builder): bool
            {
                return true;
            }

            protected function hybridSearchModels(Builder $builder, int $limit)
            {
                $this->requestedWindow = $limit;

                return $builder->model->newCollection(array_map(
                    fn ($id) => new DatabaseHybridModel(['id' => $id]),
                    range(1, 10)
                ));
            }
        };

        $results = $engine->paginateUsingDatabase(
            (new Builder($model, 'query'))->hybrid(),
            2,
            'page',
            2
        );

        $this->assertSame(1000, $engine->requestedWindow);
        $this->assertSame([3, 4], $results->getCollection()->modelKeys());
        $this->assertSame(10, $results->total());
    }

    public function test_hybrid_pagination_resolves_default_page_and_per_page_values()
    {
        Paginator::currentPageResolver(fn ($pageName) => $pageName === 'documents' ? 2 : 1);

        $model = new DatabaseHybridModel;
        $model->setPerPage(2);
        $engine = new class extends DatabaseEngine
        {
            protected function shouldPerformHybridSearch(Builder $builder): bool
            {
                return true;
            }

            protected function hybridSearchModels(Builder $builder, int $limit)
            {
                return $builder->model->newCollection(array_map(
                    fn ($id) => new DatabaseHybridModel(['id' => $id]),
                    range(1, 5)
                ));
            }
        };

        $results = $engine->paginateUsingDatabase(
            (new Builder($model, 'query'))->hybrid(),
            null,
            'documents',
            null
        );

        $this->assertSame(2, $results->currentPage());
        $this->assertSame(2, $results->perPage());
        $this->assertSame([3, 4], $results->getCollection()->modelKeys());
    }

    protected function fuse(Builder $builder, $textModels, $semanticModels)
    {
        $engine = new class extends DatabaseEngine
        {
            public function fuse(Builder $builder, $textModels, $semanticModels)
            {
                return $this->fuseSearchResults($builder, $textModels, $semanticModels);
            }
        };

        return $engine->fuse($builder, $textModels, $semanticModels);
    }
}

class DatabaseHybridModel extends Model
{
    protected $guarded = [];

    public function getScoutKey()
    {
        return $this->getKey();
    }
}
