<?php

namespace Laravel\Scout\Tests\Integration;

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\LazyCollection;
use Workbench\App\Models\SearchableUser;
use Workbench\Database\Factories\UserFactory;

use function Orchestra\Testbench\workbench_path;

trait SearchableTests
{
    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function defineScoutEnvironment($app)
    {
        $_ENV['user.toSearchableArray'] = function ($model) {
            return [
                'id' => static::scoutDriver() === 'typesense' ? (string) $model->id : (int) $model->id,
                'name' => $model->name,
                'age' => $model->age,
            ];
        };

        $app['config']->set('scout.driver', static::scoutDriver());
    }

    /**
     * Define database migrations.
     */
    protected function defineScoutDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(workbench_path('database', 'migrations'));

        $collect = LazyCollection::make(function () {
            yield ['name' => 'Laravel Framework'];

            foreach (range(2, 10) as $key) {
                yield ['name' => "Example {$key}"];
            }

            yield ['name' => 'Larry Casper', 'email_verified_at' => null];
            yield ['name' => 'Reta Larkin'];

            foreach (range(13, 19) as $key) {
                yield ['name' => "Example {$key}"];
            }

            yield ['name' => 'Prof. Larry Prosacco DVM', 'email_verified_at' => null];

            foreach (range(21, 38) as $key) {
                yield ['name' => "Example {$key}", 'email_verified_at' => null];
            }

            yield ['name' => 'Linkwood Larkin', 'email_verified_at' => null];
            yield ['name' => 'Otis Larson MD'];
            yield ['name' => 'Gudrun Larkin'];
            yield ['name' => 'Dax Larkin'];
            yield ['name' => 'Dana Larson Sr.'];
            yield ['name' => 'Amos Larson Sr.'];
        });

        UserFactory::new()
            ->times(44)
            ->state(new Sequence(...$collect->all()))
            ->create();
    }

    protected function itCanUseBasicSearch()
    {
        return SearchableUser::search('lar')->take(10)->get();
    }

    protected function itCanUseBasicSearchWithQueryCallback()
    {
        return SearchableUser::search('lar')->take(10)->query(function ($query) {
            return $query->whereNotNull('email_verified_at');
        })->get();
    }

    protected function itCanUseBasicSearchToFetchKeys()
    {
        return SearchableUser::search('lar')->take(10)->keys();
    }

    protected function itCanUseBasicSearchWithQueryCallbackToFetchKeys()
    {
        return SearchableUser::search('lar')->take(10)->query(function ($query) {
            return $query->whereNotNull('email_verified_at');
        })->keys();
    }

    protected function itCanUsePaginatedSearch()
    {
        return [
            SearchableUser::search('lar')->take(10)->paginate(5, 'page', 1),
            SearchableUser::search('lar')->take(10)->paginate(5, 'page', 2),
        ];
    }

    protected function itCanUsePaginatedSearchWithQueryCallback()
    {
        $queryCallback = function ($query) {
            return $query->whereNotNull('email_verified_at');
        };

        return [
            SearchableUser::search('lar')->take(10)->query($queryCallback)->paginate(5, 'page', 1),
            SearchableUser::search('lar')->take(10)->query($queryCallback)->paginate(5, 'page', 2),
        ];
    }

    protected function itCanUsePaginatedSearchWithEmptyQueryCallback()
    {
        $queryCallback = function ($query) {
            //
        };

        return SearchableUser::search('*')->query($queryCallback)->paginate();
    }

    protected function itCanAccessRawSearchResultsOfPaginateUsingAfterRawSearchCallback()
    {
        $result = null;

        SearchableUser::search('*')
            ->withRawResults(function ($rawSearchResult) use (&$result) {
                $result = $rawSearchResult;
            })
            ->paginate();

        return $result;
    }

    protected function itCanAccessRawSearchResultsOfPaginateRawUsingAfterRawSearchCallback()
    {
        $result = null;

        SearchableUser::search('*')
            ->withRawResults(function ($rawSearchResult) use (&$result) {
                $result = $rawSearchResult;
            })
            ->paginateRaw();

        return $result;
    }

    protected function itCanAccessRawSearchResultsOfSimplePaginateUsingAfterRawSearchCallback()
    {
        $result = null;

        SearchableUser::search('*')
            ->withRawResults(function ($rawSearchResult) use (&$result) {
                $result = $rawSearchResult;
            })
            ->simplePaginate();

        return $result;
    }

    protected function itCanAccessRawSearchResultsOfSimplePaginateRawUsingAfterRawSearchCallback()
    {
        $result = null;

        SearchableUser::search('*')
            ->withRawResults(function ($rawSearchResult) use (&$result) {
                $result = $rawSearchResult;
            })
            ->simplePaginateRaw();

        return $result;
    }

    protected function itCanAccessRawSearchResultsOfGetUsingAfterRawSearchCallback()
    {
        $result = null;

        SearchableUser::search('*')
            ->withRawResults(function ($rawSearchResult) use (&$result) {
                $result = $rawSearchResult;
            })
            ->get();

        return $result;
    }

    protected function itCanAccessRawSearchResultsOfCursorUsingAfterRawSearchCallback()
    {
        $result = null;

        SearchableUser::search('*')
            ->withRawResults(function ($rawSearchResult) use (&$result) {
                $result = $rawSearchResult;
            })
            ->cursor();

        return $result;
    }

    public function itCanMakeWhereComparisons()
    {
        SearchableUser::all()->each->delete();

        UserFactory::new()->create(['name' => 'Taylor Otwell', 'age' => 35]);
        UserFactory::new()->create(['name' => 'Abigail Otwell', 'age' => 30]);

        $this->importScoutIndexFrom(SearchableUser::class);

        $this->assertSame(['Taylor Otwell'], SearchableUser::search('*')->where('age', '>', 30)->get()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Taylor Otwell', 'Abigail Otwell'], SearchableUser::search('*')->where('age', '>=', 30)->get()->pluck('name')->all());

        $this->assertSame(['Abigail Otwell'], SearchableUser::search('*')->where('age', '<', 35)->get()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Taylor Otwell', 'Abigail Otwell'], SearchableUser::search('*')->where('age', '<=', 35)->get()->pluck('name')->all());

        $this->assertSame(['Abigail Otwell'], SearchableUser::search('*')->where('age', '!=', 35)->get()->pluck('name')->all());
        $this->assertSame(['Taylor Otwell'], SearchableUser::search('*')->where('age', '!=', 30)->get()->pluck('name')->all());

        $this->assertSame(['Taylor Otwell'], SearchableUser::search('*')->where('age', '>', 30)->where('age', '<', 40)->get()->pluck('name')->all());
        $this->assertSame(['Abigail Otwell'], SearchableUser::search('*')->where('age', '>', 25)->where('age', '<', 35)->get()->pluck('name')->all());
    }
}
