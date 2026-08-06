<?php

namespace Laravel\Scout\Tests\Feature\Engines;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\TurbopufferEngine;
use Laravel\Scout\Exceptions\NotSupportedException;
use Laravel\Scout\Exceptions\ScoutException;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Laravel\Scout\Tests\Fixtures\SearchableModelWithSoftDeletes;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;

class TurbopufferEngineTest extends TestCase
{
    use WithWorkbench;

    protected function defineEnvironment($app)
    {
        $app['config']->set('scout.driver', 'turbopuffer');
        $app['config']->set('scout.soft_delete', true);
        $app['config']->set('scout.turbopuffer', [
            'api_key' => 'tpuf-test-key',
            'region' => 'gcp-us-central1',
            'base_url' => 'https://turbopuffer.test',
            'retries' => 0,
            'model-settings' => [
                SearchableModel::class => [
                    'searchable-attributes' => [
                        'name' => 3,
                        'description' => 1,
                    ],
                    'schema' => [
                        'name' => ['type' => 'string', 'full_text_search' => true],
                        'description' => ['type' => 'string', 'full_text_search' => true],
                    ],
                ],
            ],
        ]);
    }

    public function test_driver_is_registered()
    {
        $this->assertInstanceOf(
            TurbopufferEngine::class,
            $this->app->make(EngineManager::class)->engine()
        );
    }

    public function test_update_upserts_models_with_schema_and_scout_ids()
    {
        Http::fake(['*' => Http::response(['rows_affected' => 1])]);

        $model = new SearchableModel(['id' => 10, 'name' => 'Taylor']);

        $this->engine()->update($model->newCollection([$model]));

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST' &&
                $request->url() === 'https://turbopuffer.test/v2/namespaces/table' &&
                $request->hasHeader('Authorization', 'Bearer tpuf-test-key') &&
                $request['upsert_rows'] === [['id' => 10, 'name' => 'Taylor']] &&
                $request['schema'] === [
                    'name' => ['type' => 'string', 'full_text_search' => true],
                    'description' => ['type' => 'string', 'full_text_search' => true],
                ];
        });
    }

    public function test_update_adds_soft_delete_metadata()
    {
        Http::fake(['*' => Http::response(['rows_affected' => 1])]);

        $model = new SearchableModelWithSoftDeletes;
        $model->setAttribute('id', 10);

        $this->engine()->update($model->newCollection([$model]));

        Http::assertSent(fn (Request $request) => $request['upsert_rows'][0] === [
            'id' => 10,
            '__soft_deleted' => 0,
        ]);
    }

    public function test_delete_sends_one_batched_request()
    {
        Http::fake(['*' => Http::response(['rows_affected' => 2])]);

        $models = (new SearchableModel)->newCollection([
            new SearchableModel(['id' => 10]),
            new SearchableModel(['id' => 20]),
        ]);

        $this->engine()->delete($models);

        Http::assertSent(fn (Request $request) => $request['deletes'] === [10, 20]);
    }

    public function test_search_builds_weighted_bm25_and_scout_filters()
    {
        Http::fake(['*' => Http::response([
            'rows' => [['id' => 10, '$dist' => 1.25]],
            'billing' => ['billable_logical_bytes_queried' => 100],
        ])]);

        $builder = (new Builder(new SearchableModel, 'laravel'))
            ->where('status', 'published')
            ->where('age', '>=', 18)
            ->whereIn('language', ['en', 'fr'])
            ->whereNotIn('category', ['archived'])
            ->take(25);

        $results = $this->engine()->search($builder);

        $this->assertSame(10, $results['rows'][0]['id']);
        $this->assertSame(100, $results['billing']['billable_logical_bytes_queried']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://turbopuffer.test/v2/namespaces/table/query' &&
                $request['rank_by'] === ['Sum', [
                    ['Product', 3, ['name', 'BM25', 'laravel']],
                    ['description', 'BM25', 'laravel'],
                ]] &&
                $request['filters'] === ['And', [
                    ['status', 'Eq', 'published'],
                    ['age', 'Gte', 18],
                    ['language', 'In', ['en', 'fr']],
                    ['category', 'NotIn', ['archived']],
                ]] &&
                $request['limit'] === 25;
        });
    }

    public function test_search_accepts_a_native_vector_ranking_expression()
    {
        Http::fake(['*' => Http::response(['rows' => []])]);

        $builder = (new Builder(new SearchableModel, ''))
            ->options([
                'rank_by' => ['embedding', 'ANN', [0.1, 0.2]],
                'include_attributes' => ['name'],
                'consistency' => ['level' => 'eventual'],
            ]);

        $this->engine()->search($builder);

        Http::assertSent(fn (Request $request) => $request['rank_by'] === ['embedding', 'ANN', [0.1, 0.2]] &&
            $request['include_attributes'] === ['name', 'id'] &&
            $request['consistency'] === ['level' => 'eventual']
        );
    }

    public function test_match_all_search_uses_the_requested_order()
    {
        Http::fake(['*' => Http::response(['rows' => []])]);

        $builder = (new Builder(new SearchableModel, '*'))->orderByDesc('created_at');

        $this->engine()->search($builder);

        Http::assertSent(fn (Request $request) => $request['rank_by'] === ['created_at', 'desc']);
    }

    public function test_paginate_slices_the_ranked_window_and_reports_a_capped_candidate_count()
    {
        Http::fake(function (Request $request) {
            if (isset($request['aggregate_by'])) {
                return Http::response(['aggregations' => ['count' => 12000]]);
            }

            return Http::response(['rows' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
                ['id' => 4],
            ]]);
        });

        $builder = (new Builder(new SearchableModel, 'laravel'))->where('status', 'published');
        $results = $this->engine()->paginate($builder, 2, 2);

        $this->assertSame([3, 4], array_column($results['rows'], 'id'));
        $this->assertSame(10000, $results['total']);

        Http::assertSent(fn (Request $request) => isset($request['aggregate_by']) &&
            $request['filters'] === ['status', 'Eq', 'published']
        );
    }

    public function test_pagination_rejects_windows_above_turbopuffer_limit()
    {
        Http::preventStrayRequests();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('10,000');

        $this->engine()->paginate(new Builder(new SearchableModel, 'laravel'), 100, 101);
    }

    public function test_flush_deletes_the_namespace()
    {
        Http::fake(['*' => Http::response([])]);

        $this->engine()->flush(new SearchableModel);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' &&
            $request->url() === 'https://turbopuffer.test/v2/namespaces/table'
        );
    }

    public function test_create_index_is_not_supported()
    {
        $this->expectException(NotSupportedException::class);

        $this->engine()->createIndex('table');
    }

    public function test_an_index_building_response_throws_a_scout_exception()
    {
        Http::fake(['*' => Http::response([], 202)]);

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('still building');

        $this->engine()->search(new Builder(new SearchableModel, 'laravel'));
    }

    public function test_invalid_namespaces_are_rejected_before_a_request_is_sent()
    {
        Http::preventStrayRequests();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Invalid Turbopuffer namespace');

        $this->engine()->search(
            (new Builder(new SearchableModel, 'laravel'))->within('invalid namespace')
        );
    }

    protected function engine(): TurbopufferEngine
    {
        return $this->app->make(EngineManager::class)->engine('turbopuffer');
    }
}
