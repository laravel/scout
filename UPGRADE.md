# Upgrade Guide

## Upgrading To 8.0 From 7.x

### Minimum Laravel Version

Laravel 6.0 is now the minimum supported version of the framework.

### Minimum PHP Version

PHP 7.2 is now the minimum supported version of the language.

## Upgrading To 7.0 From 6.x

### Updating Dependencies

Update your `laravel/scout` dependency to `^7.0` in your `composer.json` file.

### Algolia Driver

If you are using Algolia as your search provider, update your `algolia/algoliasearch-client-php` dependency to `^2.2` in your `composer.json` file.

#### Exception Renaming

The `AlgoliaSearch\AlgoliaException` exception class was renamed to `Algolia\AlgoliaSearch\Exceptions\AlgoliaException`.

#### The `search` Method Callback

If you are passing a callback to the `search` method, the callback will now receive an instance of `Algolia/AlgoliaSearch/SearchIndex` as its first argument.

#### Misc.

If you are using the Algolia API client directly, consider reviewing the [full changelog provided by Algolia](https://github.com/algolia/algoliasearch-client-php/blob/master/docs/UPGRADE-from-v1-to-v2.md).
