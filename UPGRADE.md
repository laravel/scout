# Upgrade Guide

## Upgrading To 10.0 From 9.x

### Minimum Versions

The following dependency versions have been updated:

- The minimum PHP version is now v8.0
- The minimum Laravel version is now v9.0

### The `getScoutKeyName` Method

PR: https://github.com/laravel/scout/pull/509

In Scout 10.x, the `getScoutKeyName` method will return the unqualified key name and no longer qualifies the key name with the table name. If you are overriding the `getScoutKeyName` method you will need to account for this change and make sure you return an unqualified key name.

```diff
public function getScoutKeyName()
{
-    return 'posts.id';
+    return 'id';
}
```

### Removal Of `getUnqualifiedScoutKeyName`

PR: https://github.com/laravel/scout/pull/657

Due to the `getScoutKeyName` change discussed above, the `getUnqualifiedScoutKeyName` method was removed as it is no longer necessary.

## Upgrading To 9.0 From 8.x

### Minimum Laravel Version

Laravel 8.0 is now the minimum supported version of the framework.

### Minimum PHP Version

PHP 7.3 is now the minimum supported version of the language.

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
