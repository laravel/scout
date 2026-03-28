---
name: scout-development
description: "Develops full-text search with Laravel Scout. Activates when installing or configuring Scout; choosing a search engine (Algolia, Meilisearch, Typesense, Database, Collection); adding the Searchable trait to models; customizing toSearchableArray or searchableAs; importing or flushing search indexes; writing search queries with where clauses, pagination, or soft deletes; configuring index settings; troubleshooting search results; or when the user mentions Scout, full-text search, search indexing, or search engines in a Laravel project. Make sure to use this skill whenever the user works with search functionality in Laravel, even if they don't explicitly mention Scout."
license: MIT
metadata:
  author: laravel
---
@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Scout Full-Text Search

## Documentation First

**Always use `search-docs` before writing Scout code.** The documentation covers every engine, configuration option, and edge case in detail. This skill teaches you how to navigate Scout — the docs have the implementation specifics.

```
search-docs(queries: ["Scout installation"], packages: ["laravel/framework@12.x"])
```

The Scout docs live under the `laravel/framework` package — not `laravel/scout`.

Effective search patterns:

- Installation & setup: `"Scout installation"`, `"Scout queueing"`
- Engine setup: `"Scout Algolia"`, `"Scout Meilisearch"`, `"Scout Typesense"`
- Model configuration: `"Scout configuring searchable data"`, `"Scout configuring model indexes"`
- Searching: `"Scout searching"`, `"Scout where clauses"`, `"Scout pagination"`
- Indexing: `"Scout batch import"`, `"Scout adding records"`, `"Scout removing records"`
- Advanced: `"Scout soft deleting"`, `"Scout customizing engine searches"`, `"Scout custom engines"`

The docs are organized into these main sections: Installation, Driver Prerequisites, Configuration, Database/Collection Engines, Indexing, Searching, Custom Engines. Use these section names as search anchors.

## When to Apply

Activate this skill when:

- Installing or configuring Scout
- Choosing a search engine for a Laravel application
- Making Eloquent models searchable
- Customizing indexed data or index names
- Writing search queries, filters, or pagination
- Importing or flushing search indexes
- Troubleshooting search results or indexing issues
- Choosing between search engines

## Installation

Before installing, check if Scout is already in the project — look for `laravel/scout` in `composer.json` and `config/scout.php`. If already installed, skip to the relevant section (engine configuration, model setup, or searching).

### 1. Install Scout

```bash
composer require laravel/scout
{{ $assist->artisanCommand('vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"') }}
```

### 2. Add the Searchable trait

```php
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Post extends Model
{
    use Searchable;
}
```

This registers a model observer that automatically keeps your search index in sync with Eloquent records.

## Choosing an Engine

Before presenting engine options, check if Scout is already configured in the application:

1. Check `.env` for `SCOUT_DRIVER` — if set, the application already has a configured engine.
2. Check `config/scout.php` for the `driver` key and any engine-specific settings.
3. Check `composer.json` for engine SDKs (`algolia/algoliasearch-client-php`, `meilisearch/meilisearch-php`, `typesense/typesense-php`).

If an engine is already configured, skip the engine selection step and work with the existing setup. Only present the engine comparison if Scout is not yet installed or the user explicitly wants to switch engines.

When no engine is configured, present these options and let the user decide — never choose for them.

| Engine | Type | Best For | Tradeoffs |
|--------|------|----------|-----------|
| **Database** | Built-in | Typical applications, simple search | No external deps. MySQL/PostgreSQL only. LIKE + full-text indexes. No typo tolerance. |
| **Collection** | Built-in | Local dev, tiny datasets (<500 records) | Loads all records into memory. Most portable but least efficient. |
| **Algolia** | Hosted SaaS | Advanced search without managing infra | Typo tolerance, analytics, faceting. Paid service. No self-hosting. |
| **Meilisearch** | Self-hosted / Cloud | Teams wanting infrastructure control | Fast, open-source. Self-hostable or cloud. Requires filterable attribute config. |
| **Typesense** | Self-hosted / Cloud | Keyword, semantic, geo, vector search | Open-source. Self-hostable or cloud. Strict schema requirements. |

After the user chooses, use `search-docs` for that engine's prerequisites and configuration.

Set the engine in `.env`:

```ini
SCOUT_DRIVER=database
```

For third-party engines (Algolia, Meilisearch, Typesense): install their PHP SDK and strongly consider enabling queue support in `config/scout.php` for production.

## Model Configuration

Use `search-docs` for full configuration details. Key customization points:

```php
// Control what data gets indexed
public function toSearchableArray(): array
{
    return [
        'id' => $this->id,
        'title' => $this->title,
        'body' => $this->body,
    ];
}

// Custom index name (no effect with database engine)
public function searchableAs(): string
{
    return 'posts_index';
}
```

For Algolia/Meilisearch/Typesense: configure index settings (filterable, sortable, searchable attributes) in `config/scout.php`, then sync:

```bash
{{ $assist->artisanCommand('scout:sync-index-settings') }}
```

## Searching

Basic patterns:

```php
// Simple search
$results = Model::search('query')->get();

// With filtering
$results = Model::search('query')->where('status', 'active')->get();

// Paginated
$results = Model::search('query')->paginate(15);

// Eager load relationships on results
$results = Model::search('query')
    ->query(fn ($q) => $q->with('category'))
    ->get();

// Raw engine results
$results = Model::search('query')->raw();
```

Use `search-docs` for advanced querying — `whereIn`, `whereNotIn`, soft deletes, custom indexes, and engine-specific options.

## Key Artisan Commands

| Command | Purpose |
|---------|---------|
| `{{ $assist->artisanCommand('scout:import "App\\Models\\Post"') }}` | Import model records into search index |
| `{{ $assist->artisanCommand('scout:queue-import "App\\Models\\Post"') }}` | Import via queued jobs (large datasets) |
| `{{ $assist->artisanCommand('scout:flush "App\\Models\\Post"') }}` | Remove all model records from search index |
| `{{ $assist->artisanCommand('scout:sync-index-settings') }}` | Sync index settings to the search engine |
| `{{ $assist->artisanCommand('scout:index posts') }}` | Create a search index |
| `{{ $assist->artisanCommand('scout:delete-index posts') }}` | Delete a search index |

## Testing

Scout does **not** have a `Scout::fake()` method. Available approaches:

- **NullEngine** — set `SCOUT_DRIVER=null` to disable all indexing in tests
- **CollectionEngine** — set `SCOUT_DRIVER=collection` for in-memory search without external services
- **`Model::withoutSyncingToSearch(fn() => ...)`** — temporarily pause indexing in a callback
- **`Model::disableSearchSyncing()`** — globally disable syncing in test setUp

Use `search-docs` for detailed testing patterns.

## Common Pitfalls

- **Meilisearch filterable attributes** — you must configure filterable attributes in `config/scout.php` under `meilisearch.index-settings` and run `scout:sync-index-settings` **before** using `where`, `whereIn`, or `whereNotIn`. Without this, filtering silently returns wrong results.
- **Meilisearch data type casting** — `toSearchableArray()` must return properly cast values: integers as `(int)`, floats as `(float)`. Wrong types cause silent filter failures.
- **Database engine limitations** — `searchableAs()`, `getScoutKey()`, `getScoutKeyName()`, and `shouldBeSearchable()` have no effect with the database engine. It queries your actual tables directly.
- **Global scopes break pagination** — search engines are not aware of Eloquent global scopes. Recreate scope constraints using Scout `where` clauses instead.
- **`query()` is not for filtering** — with third-party engines, the `query()` callback runs after results are already retrieved. Use Scout `where` clauses for filtering; use `query()` only for eager-loading or customizing the Eloquent hydration query.
- **Missing queue configuration** — third-party engines should always have `scout.queue` enabled in production. Without it, indexing runs synchronously and slows down requests.
- **Typesense schema requirements** — `id` must be cast as `(string)` and timestamps as Unix integers (`$this->created_at->timestamp`).
- **`shouldBeSearchable()` bypass** — this method only applies via `save()`, `create()`, queries, or relationships. Calling `searchable()` directly on a model or collection bypasses it entirely.
