# Upgrade Guide

## Upgrading To 7.0 From 6.0

### Updating Dependencies

Update your `laravel/scout` dependency to `^7.0` in your `composer.json` file.

### When using the Algolia driver

Update your `algolia/algoliasearch-client-php` dependency to `^2.2` in your `composer.json` file.

If you were relying on the exception `AlgoliaSearch\AlgoliaException`, you may need to adapt your code since it got renamed to `Algolia\AlgoliaSearch\Exceptions\AlgoliaException`.

If you were customizing engine searches giving a callback as the second argument of the method `search` in your models, the first argument of the given callback is now an instance of `Algolia\AlgoliaSearch\SearchIndex`.

If you were using the Algolia API Client source code directly, consider review the [full changelog here](https://github.com/algolia/algoliasearch-client-php/blob/master/docs/UPGRADE-from-v1-to-v2.md).
