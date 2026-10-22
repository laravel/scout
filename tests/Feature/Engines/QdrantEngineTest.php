<?php

namespace Laravel\Scout\Tests\Feature\Engines;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\QdrantEngine;
use Laravel\Scout\Exceptions\NotSupportedException;
use Laravel\Scout\Exceptions\ScoutException;
use Laravel\Scout\Scout;
use Laravel\Scout\Tests\Fixtures\FakeEmbeddings;
use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Laravel\Scout\Tests\Fixtures\SearchableModelWithPrecomputedEmbedding;
use Laravel\Scout\Tests\Fixtures\SearchableModelWithSoftDeletes;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;

class QdrantEngineTest extends TestCase
{
    use WithWorkbench;

    protected function defineEnvironment($app)
    {
        $app['config']->set('scout.driver', 'qdrant');
        $app['config']->set('scout.soft_delete', true);
        $app['config']->set('scout.qdrant', [
            'host' => 'https://qdrant.test',
            'api_key' => 'qdrant-test-key',
            'retries' => 0,
            'model-settings' => [
                SearchableModel::class => [
                    'searchable-attributes' => ['name', 'description'],
                    'payload-indexes' => ['status' => 'keyword'],
                ],
            ],
        ]);
    }

    public function test_driver_is_registered()
    {
        $this->assertInstanceOf(
            QdrantEngine::class,
            $this->app->make(EngineManager::class)->engine()
        );
    }

    public function test_update_upserts_points_with_bm25_documents_and_scout_ids()
    {
        $this->fakeExistingCollection();

        $model = new SearchableModel(['id' => 10, 'name' => 'Taylor']);

        $this->engine()->update($model->newCollection([$model]));

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT' &&
                $request->url() === 'https://qdrant.test/collections/table/points?wait=true' &&
                $request->hasHeader('api-key', 'qdrant-test-key') &&
                $request->hasHeader('User-Agent', 'laravel-scout/'.Scout::VERSION) &&
                $request['points'] === [[
                    'id' => 10,
                    'vector' => ['bm25' => ['text' => 'Taylor', 'model' => 'qdrant/bm25']],
                    'payload' => ['id' => 10, 'name' => 'Taylor', '__scout_key' => 10],
                ]];
        });
    }

    public function test_update_creates_a_missing_collection_with_vectors_and_payload_indexes()
    {
        $this->configureEmbeddings();
        $this->fakeEmbeddings([[[0.1, 0.2]]]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return $this->missingCollectionResponse();
            }

            return Http::response(['result' => true]);
        });

        $model = new SearchableModel(['id' => 10, 'name' => 'Taylor']);

        $this->engine()->update($model->newCollection([$model]));

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' &&
            $request->url() === 'https://qdrant.test/collections/table' &&
            $request['vectors'] === ['embedding' => ['size' => 2, 'distance' => 'Cosine']] &&
            $request['sparse_vectors'] === ['bm25' => ['modifier' => 'idf']]
        );

        Http::assertSent(fn (Request $request) => $request->url() === 'https://qdrant.test/collections/table/index' &&
            $request['field_name'] === 'status' &&
            $request['field_schema'] === 'keyword'
        );

        Http::assertSent(fn (Request $request) => $request->url() === 'https://qdrant.test/collections/table/index' &&
            $request['field_name'] === '__scout_key' &&
            $request['field_schema'] === 'integer'
        );

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/points?wait=true') &&
            $request['points'][0]['vector']['embedding'] === [0.1, 0.2]
        );
    }

    public function test_update_only_checks_the_collection_once()
    {
        $this->fakeExistingCollection();

        $model = new SearchableModel(['id' => 10, 'name' => 'Taylor']);
        $engine = $this->engine();

        $engine->update($model->newCollection([$model]));
        $engine->update($model->newCollection([$model]));

        Http::assertSentCount(3);
    }

    public function test_update_adds_soft_delete_metadata_and_hashes_non_numeric_keys()
    {
        $this->fakeExistingCollection();

        $model = new SearchableModelWithSoftDeletes;
        $model->setKeyType('string');
        $model->setAttribute('id', 'abc');

        $this->engine()->update($model->newCollection([$model]));

        Http::assertSent(function (Request $request) {
            return isset($request['points']) &&
                $request['points'][0]['id'] === '90015098-3cd2-4fb0-d696-3f7d28e17f72' &&
                $request['points'][0]['payload'] === ['id' => 'abc', '__soft_deleted' => 0, '__scout_key' => 'abc'];
        });
    }

    public function test_update_generates_embeddings_in_batches_and_accepts_precomputed_vectors()
    {
        $this->configureEmbeddings([], SearchableModelWithPrecomputedEmbedding::class);
        $this->fakeEmbeddings([
            array_fill(0, 99, [0.3, 0.4]),
            [[0.5, 0.6]],
        ]);
        $this->fakeExistingCollection();

        $precomputed = new SearchableModelWithPrecomputedEmbedding(['id' => 0, 'name' => 'Precomputed']);
        $precomputed->setAttribute('embedding', [0.1, 0.2]);

        $models = $precomputed->newCollection([$precomputed, ...array_map(
            fn ($id) => new SearchableModelWithPrecomputedEmbedding(['id' => $id, 'name' => "Document {$id}"]),
            range(1, 100)
        )]);

        $this->engine()->update($models);

        $this->assertCount(2, FakeEmbeddings::$requests);
        $this->assertCount(99, FakeEmbeddings::$requests[0]['inputs']);
        $this->assertSame('Document 1', FakeEmbeddings::$requests[0]['inputs'][0]);
        $this->assertSame(['Document 100'], FakeEmbeddings::$requests[1]['inputs']);

        Http::assertSent(fn (Request $request) => isset($request['points']) &&
            count($request['points']) === 100 &&
            $request['points'][0]['vector']['embedding'] === [0.1, 0.2] &&
            $request['points'][1]['vector']['embedding'] === [0.3, 0.4]
        );

        Http::assertSent(fn (Request $request) => isset($request['points']) &&
            count($request['points']) === 1 &&
            $request['points'][0]['vector']['embedding'] === [0.5, 0.6]
        );
    }

    public function test_update_can_use_qdrant_native_embeddings()
    {
        $this->configureEmbeddings([
            'driver' => 'qdrant',
            'model' => 'sentence-transformers/all-minilm-l6-v2',
        ]);
        $this->fakeExistingCollection();

        $model = new SearchableModel(['id' => 10, 'name' => 'Native embedding source']);

        $this->engine()->update($model->newCollection([$model]));

        Http::assertSent(fn (Request $request) => isset($request['points']) &&
            $request['points'][0]['vector']['embedding'] === [
                'text' => 'Native embedding source',
                'model' => 'sentence-transformers/all-minilm-l6-v2',
            ]
        );
    }

    public function test_delete_sends_one_batched_request()
    {
        Http::fake(['*' => Http::response(['result' => ['status' => 'completed']])]);

        $models = (new SearchableModel)->newCollection([
            new SearchableModel(['id' => 10]),
            new SearchableModel(['id' => 20]),
        ]);

        $this->engine()->delete($models);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST' &&
            $request->url() === 'https://qdrant.test/collections/table/points/delete?wait=true' &&
            $request['points'] === [10, 20]
        );
    }

    public function test_search_builds_a_bm25_query_and_scout_filters()
    {
        Http::fake(['*' => Http::response([
            'result' => ['points' => [['id' => 10, 'score' => 1.25, 'payload' => ['__scout_key' => 10]]]],
            'time' => 0.002,
        ])]);

        $builder = (new Builder(new SearchableModel, 'laravel'))
            ->where('status', 'published')
            ->where('age', '>=', 18)
            ->where('kind', '!=', 'draft')
            ->whereIn('language', ['en', 'fr'])
            ->whereNotIn('category', ['archived', 'hidden'])
            ->take(25);

        $results = $this->engine()->search($builder);

        $this->assertSame(10, $results['points'][0]['payload']['__scout_key']);
        $this->assertSame([10], $this->engine()->mapIds($results)->all());

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://qdrant.test/collections/table/points/query' &&
                $request['query'] === ['text' => 'laravel', 'model' => 'qdrant/bm25'] &&
                $request['using'] === 'bm25' &&
                $request['filter'] === [
                    'must' => [
                        ['key' => 'status', 'match' => ['value' => 'published']],
                        ['key' => 'age', 'range' => ['gte' => 18]],
                        ['key' => 'language', 'match' => ['any' => ['en', 'fr']]],
                    ],
                    'must_not' => [
                        ['key' => 'kind', 'match' => ['value' => 'draft']],
                        ['key' => 'category', 'match' => ['any' => ['archived', 'hidden']]],
                    ],
                ] &&
                $request['with_payload'] === true &&
                $request['limit'] === 25;
        });
    }

    public function test_search_accepts_a_native_query_and_filter()
    {
        Http::fake(['*' => Http::response(['result' => ['points' => []]])]);

        $builder = (new Builder(new SearchableModel, ''))
            ->where('status', 'published')
            ->options([
                'query' => [0.1, 0.2],
                'using' => 'embedding',
                'filter' => ['must' => [['key' => 'team', 'match' => ['value' => 1]]]],
                'with_payload' => ['name'],
            ]);

        $this->engine()->search($builder);

        Http::assertSent(fn (Request $request) => $request['query'] === [0.1, 0.2] &&
            $request['using'] === 'embedding' &&
            $request['with_payload'] === ['name', '__scout_key'] &&
            $request['filter'] === ['must' => [
                ['must' => [['key' => 'team', 'match' => ['value' => 1]]]],
                ['must' => [['key' => 'status', 'match' => ['value' => 'published']]]],
            ]]
        );
    }

    public function test_semantic_search_generates_a_query_embedding()
    {
        $this->configureEmbeddings();
        $this->fakeEmbeddings([[[0.25, 0.75]]]);
        Http::fake(['*' => Http::response(['result' => ['points' => [['id' => 10, 'score' => 0.95, 'payload' => ['__scout_key' => 10]]]]])]);

        $builder = (new Builder(new SearchableModel, 'conceptual query'))
            ->semantic(minSimilarity: 0.9)
            ->where('status', 'published')
            ->take(10);

        $this->engine()->search($builder);

        $this->assertSame(['conceptual query'], FakeEmbeddings::$requests[0]['inputs']);

        Http::assertSent(fn (Request $request) => $request['query'] === [0.25, 0.75] &&
            $request['using'] === 'embedding' &&
            $request['score_threshold'] === 0.9 &&
            $request['filter'] === ['must' => [['key' => 'status', 'match' => ['value' => 'published']]]] &&
            $request['limit'] === 10
        );
    }

    public function test_semantic_search_can_use_qdrant_native_query_embeddings()
    {
        $this->configureEmbeddings([
            'driver' => 'qdrant',
            'model' => 'sentence-transformers/all-minilm-l6-v2',
        ]);
        Http::fake(['*' => Http::response(['result' => ['points' => []]])]);

        $this->engine()->search(
            (new Builder(new SearchableModel, 'conceptual query'))->semantic()
        );

        Http::assertSent(fn (Request $request) => $request['query'] === [
            'text' => 'conceptual query',
            'model' => 'sentence-transformers/all-minilm-l6-v2',
        ] && $request['using'] === 'embedding');
    }

    public function test_native_embeddings_require_a_model()
    {
        $this->configureEmbeddings(['driver' => 'qdrant']);
        Http::preventStrayRequests();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('require a [model]');

        $this->engine()->search(
            (new Builder(new SearchableModel, 'conceptual query'))->semantic()
        );
    }

    public function test_hybrid_search_uses_weighted_rrf_over_prefetches()
    {
        $this->configureEmbeddings();
        $this->fakeEmbeddings([[[0.4, 0.6]]]);
        Http::fake(['*' => Http::response(['result' => ['points' => [
            ['id' => 20, 'score' => 0.5, 'payload' => ['__scout_key' => 20]],
            ['id' => 10, 'score' => 0.4, 'payload' => ['__scout_key' => 10]],
        ]]])]);

        $builder = (new Builder(new SearchableModel, 'combined query'))
            ->hybrid(textWeight: 1, semanticWeight: 2, minSimilarity: 0.5)
            ->where('status', 'published')
            ->take(2);

        $results = $this->engine()->search($builder);

        $this->assertSame([20, 10], $this->engine()->mapIds($results)->all());
        $this->assertSame(2, $results['total']);

        Http::assertSent(function (Request $request) {
            $filter = ['must' => [['key' => 'status', 'match' => ['value' => 'published']]]];

            return $request['query'] === ['rrf' => ['weights' => [1, 2]]] &&
                $request['prefetch'] === [
                    [
                        'query' => ['text' => 'combined query', 'model' => 'qdrant/bm25'],
                        'using' => 'bm25',
                        'filter' => $filter,
                        'limit' => 1000,
                    ],
                    [
                        'query' => [0.4, 0.6],
                        'using' => 'embedding',
                        'score_threshold' => 0.5,
                        'filter' => $filter,
                        'limit' => 1000,
                    ],
                ] &&
                ! isset($request['filter']) &&
                $request['limit'] === 2;
        });
    }

    public function test_match_all_search_uses_the_requested_order()
    {
        Http::fake(['*' => Http::response(['result' => ['points' => []]])]);

        $builder = (new Builder(new SearchableModel, '*'))->orderByDesc('created_at');

        $this->engine()->search($builder);

        Http::assertSent(fn (Request $request) => $request['query'] === [
            'order_by' => ['key' => 'created_at', 'direction' => 'desc'],
        ]);
    }

    public function test_full_text_search_rejects_order_clauses()
    {
        Http::preventStrayRequests();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('cannot be combined with full-text search');

        $this->engine()->search((new Builder(new SearchableModel, 'laravel'))->orderBy('name'));
    }

    public function test_filters_match_floats_booleans_and_nulls()
    {
        Http::fake(['*' => Http::response(['result' => ['points' => []]])]);

        $this->engine()->search((new Builder(new SearchableModel, ''))
            ->where('featured', true)
            ->where('deleted', '!=', null)
            ->whereIn('rating', [3.5, null])
            ->whereIn('id', []));

        Http::assertSent(fn (Request $request) => $request['filter'] === [
            'must' => [
                ['key' => 'featured', 'match' => ['value' => true]],
                ['should' => [
                    ['key' => 'rating', 'range' => ['gte' => 3.5, 'lte' => 3.5]],
                    ['is_null' => ['key' => 'rating']],
                    ['key' => 'rating', 'match' => ['value' => '3.5']],
                ]],
                ['key' => '__scout_key', 'match' => ['any' => []]],
            ],
            'must_not' => [
                ['is_null' => ['key' => 'deleted']],
            ],
        ]);
    }

    public function test_filter_values_use_the_payload_index_type()
    {
        $config = $this->app['config']->get('scout.qdrant');
        $config['model-settings'][SearchableModel::class]['payload-indexes'] = [
            'status' => 'keyword',
            'rating' => 'integer',
            'price' => ['type' => 'float'],
        ];
        $this->app['config']->set('scout.qdrant', $config);

        Http::fake(['*' => Http::response(['result' => ['points' => []]])]);

        $this->engine()->search((new Builder(new SearchableModel, ''))
            ->where('id', '2')
            ->where('rating', '4')
            ->where('price', '9.5')
            ->where('status', 5)
            ->where('code', '007'));

        Http::assertSent(fn (Request $request) => $request['filter'] === ['must' => [
            ['key' => '__scout_key', 'match' => ['value' => 2]],
            ['key' => 'rating', 'match' => ['value' => 4]],
            ['key' => 'price', 'range' => ['gte' => 9.5, 'lte' => 9.5]],
            ['key' => 'status', 'match' => ['value' => '5']],
            ['key' => 'code', 'match' => ['value' => '007']],
        ]]);
    }

    public function test_dates_and_stringables_are_matched_as_strings()
    {
        Http::fake(['*' => Http::response(['result' => ['points' => []]])]);

        $this->engine()->search((new Builder(new SearchableModel, ''))
            ->where('published_on', Carbon::parse('2024-01-02 03:04:05', 'UTC'))
            ->whereIn('slug', [Str::of('a')]));

        Http::assertSent(fn (Request $request) => $request['filter'] === ['must' => [
            ['key' => 'published_on', 'match' => ['value' => '2024-01-02T03:04:05.000000Z']],
            ['key' => 'slug', 'match' => ['value' => 'a']],
        ]]);
    }

    public function test_paginate_uses_an_offset_and_counts_matching_points()
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/points/count')) {
                return Http::response(['result' => ['count' => 12]]);
            }

            return Http::response(['result' => ['points' => [
                ['id' => 3, 'payload' => ['__scout_key' => 3]],
                ['id' => 4, 'payload' => ['__scout_key' => 4]],
            ]]]);
        });

        $builder = (new Builder(new SearchableModel, ''))->where('status', 'published');
        $results = $this->engine()->paginate($builder, 2, 2);

        $this->assertSame([3, 4], $this->engine()->mapIds($results)->all());
        $this->assertSame(12, $results['total']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/points/query') &&
            $request['limit'] === 2 &&
            $request['offset'] === 2
        );

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/points/count') &&
            $request['exact'] === true &&
            $request['filter'] === ['must' => [['key' => 'status', 'match' => ['value' => 'published']]]]
        );
    }

    public function test_ordered_pagination_only_counts_points_with_the_order_key()
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/points/count')) {
                return Http::response(['result' => ['count' => 3]]);
            }

            return Http::response(['result' => ['points' => []]]);
        });

        $this->engine()->paginate((new Builder(new SearchableModel, ''))->orderBy('rank'), 2, 1);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/points/count') &&
            $request['filter'] === ['must_not' => [['is_empty' => ['key' => 'rank']]]]
        );
    }

    public function test_paginate_respects_the_builder_limit()
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/points/count')) {
                return Http::response(['result' => ['count' => 50]]);
            }

            return Http::response(['result' => ['points' => [['id' => 5, 'payload' => ['__scout_key' => 5]]]]]);
        });

        $results = $this->engine()->paginate(
            (new Builder(new SearchableModel, ''))->take(5),
            4,
            2
        );

        $this->assertSame(5, $results['total']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/points/query') &&
            $request['limit'] === 1 &&
            $request['offset'] === 4
        );
    }

    public function test_ranked_pagination_slices_one_ranked_query_and_retrieves_the_page()
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/points/query')) {
                return Http::response(['result' => ['points' => [
                    ['id' => 1, 'score' => 0.9],
                    ['id' => 2, 'score' => 0.8],
                    ['id' => 3, 'score' => 0.7],
                ]]]);
            }

            return Http::response(['result' => [
                ['id' => 3, 'payload' => ['__scout_key' => 3]],
                ['id' => 2, 'payload' => ['__scout_key' => 2]],
            ]]);
        });

        $results = $this->engine()->paginate(new Builder(new SearchableModel, 'laravel'), 2, 2);

        $this->assertSame([3], $this->engine()->mapIds($results)->all());
        $this->assertSame(0.7, $results['points'][0]['score']);
        $this->assertSame(3, $results['total']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/points/query') &&
            $request['limit'] === 1000 &&
            $request['with_payload'] === false &&
            ! isset($request['offset'])
        );

        Http::assertSent(fn (Request $request) => $request->url() === 'https://qdrant.test/collections/table/points' &&
            $request['ids'] === [3] &&
            $request['with_payload'] === true
        );
    }

    public function test_take_zero_returns_no_results()
    {
        Http::preventStrayRequests();

        $this->assertSame([], $this->engine()->search((new Builder(new SearchableModel, 'laravel'))->take(0))['points']);
        $this->assertSame(0, $this->engine()->paginate((new Builder(new SearchableModel, 'laravel'))->take(0), 5, 1)['total']);
    }

    public function test_delete_ignores_a_missing_collection()
    {
        Http::fake(['*' => $this->missingCollectionResponse()]);

        $this->engine()->delete((new SearchableModel)->newCollection([new SearchableModel(['id' => 1])]));

        Http::assertSentCount(1);
    }

    public function test_numeric_strings_match_numbers()
    {
        Http::fake(['*' => Http::response(['result' => ['points' => []]])]);

        $this->engine()->search((new Builder(new SearchableModel, ''))
            ->where('rating', '4')
            ->where('price', '>=', '9.5')
            ->whereIn('code', ['02134', 7]));

        Http::assertSent(fn (Request $request) => $request['filter'] === ['must' => [
            ['should' => [
                ['key' => 'rating', 'match' => ['value' => '4']],
                ['key' => 'rating', 'match' => ['value' => 4]],
            ]],
            ['key' => 'price', 'range' => ['gte' => 9.5]],
            ['should' => [
                ['key' => 'code', 'match' => ['any' => ['02134', '7']]],
                ['key' => 'code', 'match' => ['value' => 7]],
            ]],
        ]]);
    }

    public function test_point_ids_never_collide()
    {
        $pointId = fn ($key) => (fn () => $this->pointId($key))->call($this->engine());

        $this->assertSame(5, $pointId(5));
        $this->assertSame(5, $pointId('5'));
        $this->assertSame(0, $pointId('0'));
        $this->assertSame('0b7e0b8a-7f6e-4c1e-9d55-3c0e7c9d2f11', $pointId('0b7e0b8a-7f6e-4c1e-9d55-3c0e7c9d2f11'));
        $this->assertSame('0b7e0b8a-7f6e-4c1e-9d55-3c0e7c9d2f11', $pointId('0B7E0B8A-7F6E-4C1E-9D55-3C0E7C9D2F11'));

        $hashed = array_map($pointId, [' 5', '+5', '-0', '-5', '007', '99999999999999999999']);

        $this->assertCount(6, array_unique($hashed));
        $this->assertSame($hashed, array_filter($hashed, 'is_string'));
    }

    public function test_searching_a_missing_collection_returns_no_results()
    {
        Http::fake(['*' => $this->missingCollectionResponse()]);

        $this->assertSame([], $this->engine()->search(new Builder(new SearchableModel, 'laravel'))['points']);
        $this->assertSame(0, $this->engine()->paginate(new Builder(new SearchableModel, ''), 5, 1)['total']);
        $this->assertSame(0, $this->engine()->paginate(new Builder(new SearchableModel, 'laravel'), 5, 1)['total']);
    }

    public function test_other_not_found_errors_are_thrown()
    {
        Http::fake(['*' => Http::response('', 404)]);

        $this->expectException(RequestException::class);

        $this->engine()->search(new Builder(new SearchableModel, 'laravel'));
    }

    public function test_missing_payload_indexes_are_created_for_an_existing_collection()
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['result' => ['payload_schema' => ['__scout_key' => ['data_type' => 'integer']]]]);
            }

            return Http::response(['result' => ['status' => 'completed']]);
        });

        $model = new SearchableModel(['id' => 10, 'name' => 'Taylor']);

        $this->engine()->update($model->newCollection([$model]));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/index') && $request['field_name'] === 'status');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/index') && $request['field_name'] === '__scout_key');
        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT' && $request->url() === 'https://qdrant.test/collections/table');
    }

    public function test_minimum_similarity_requires_a_similarity_distance()
    {
        $this->configureEmbeddings(['distance' => 'Euclid']);
        $this->fakeEmbeddings([[[0.1, 0.2]]]);
        Http::preventStrayRequests();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Cosine or Dot');

        $this->engine()->search((new Builder(new SearchableModel, 'query'))->semantic(0.5));
    }

    public function test_flush_deletes_the_collection()
    {
        Http::fake(['*' => Http::response(['result' => true])]);

        $this->engine()->flush(new SearchableModel);

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' &&
            $request->url() === 'https://qdrant.test/collections/table'
        );
    }

    public function test_create_index_is_not_supported()
    {
        $this->expectException(NotSupportedException::class);

        $this->engine()->createIndex('table');
    }

    public function test_invalid_collections_are_rejected_before_a_request_is_sent()
    {
        Http::preventStrayRequests();

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Invalid Qdrant collection');

        $this->engine()->search(
            (new Builder(new SearchableModel, 'laravel'))->within('invalid collection')
        );
    }

    protected function engine(): QdrantEngine
    {
        return $this->app->make(EngineManager::class)->engine('qdrant');
    }

    protected function fakeExistingCollection(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response(['result' => ['payload_schema' => [
                    'status' => ['data_type' => 'keyword'],
                    '__scout_key' => ['data_type' => 'integer'],
                    '__soft_deleted' => ['data_type' => 'integer'],
                ]]]);
            }

            return Http::response(['result' => ['status' => 'completed']]);
        });
    }

    protected function missingCollectionResponse()
    {
        return Http::response(['status' => ['error' => 'Not found: Collection `table` doesn\'t exist!']], 404);
    }

    protected function configureEmbeddings(array $overrides = [], string $model = SearchableModel::class): void
    {
        $config = $this->app['config']->get('scout.qdrant');
        $settings = $config['model-settings'][SearchableModel::class];

        $settings['embedding'] = array_merge([
            'attribute' => 'embedding',
            'dimensions' => 2,
        ], $overrides);

        $config['model-settings'][$model] = $settings;

        $this->app['config']->set('scout.qdrant', $config);
    }

    protected function fakeEmbeddings(array $responses): void
    {
        if (! class_exists('Laravel\\Ai\\Embeddings')) {
            class_alias(FakeEmbeddings::class, 'Laravel\\Ai\\Embeddings');
        }

        FakeEmbeddings::fake($responses);
    }
}
