<?php

namespace Laravel\Scout\Tests\Integration;

use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Tests\Fixtures\User;
use Orchestra\Testbench\Factories\UserFactory;

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
        $app['config']->set('scout.driver', static::scoutDriver());
    }

    /**
     * Define database migrations.
     */
    protected function defineScoutDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();

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
        return User::search('lar')->take(10)->get();
    }

    protected function itCanUseBasicSearchWithQueryCallback()
    {
        return User::search('lar')->take(10)->query(function ($query) {
            return $query->whereNotNull('email_verified_at');
        })->get();
    }

    protected function itCanUseBasicSearchToFetchKeys()
    {
        return User::search('lar')->take(10)->keys();
    }

    protected function itCanUseBasicSearchWithQueryCallbackToFetchKeys()
    {
        return User::search('lar')->take(10)->query(function ($query) {
            return $query->whereNotNull('email_verified_at');
        })->keys();
    }

    protected function itCanUsePaginatedSearch()
    {
        return [
            User::search('lar')->take(10)->paginate(5, 'page', 1),
            User::search('lar')->take(10)->paginate(5, 'page', 2),
        ];
    }

    protected function itCanUsePaginatedSearchWithQueryCallback()
    {
        $queryCallback = function ($query) {
            return $query->whereNotNull('email_verified_at');
        };

        return [
            User::search('lar')->take(10)->query($queryCallback)->paginate(5, 'page', 1),
            User::search('lar')->take(10)->query($queryCallback)->paginate(5, 'page', 2),
        ];
    }

    protected function itCanUsePaginatedSearchWithEmptyQueryCallback()
    {
        $queryCallback = function ($query) {
        };

        return User::search('*')->query($queryCallback)->paginate();
    }
}
