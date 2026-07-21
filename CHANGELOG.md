# Release Notes

## [Unreleased](https://github.com/laravel/sentinel/compare/v11.4.0...11.x)

## [v11.4.0](https://github.com/laravel/sentinel/compare/v11.3.0...v11.4.0) - 2026-07-21

* Bump shivammathur/setup-php from 2.37.1 to 2.37.2 in the github-actions group by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/laravel/scout/pull/999
* Bump actions/checkout from 6.0.3 to 7.0.0 in the github-actions group by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/laravel/scout/pull/1000
* Mark Scout jobs as failed on timeout by default by [@Bramvzw](https://github.com/Bramvzw) in https://github.com/laravel/scout/pull/1002
* [11.x] Add ability to adjust `scout:queue-import` order via `--order=desc` option by [@stevebauman](https://github.com/stevebauman) in https://github.com/laravel/scout/pull/1003

## [v11.3.0](https://github.com/laravel/sentinel/compare/v11.2.0...v11.3.0) - 2026-06-16

* Pin GitHub Actions to commit SHAs and add Dependabot config by [@joetannenbaum](https://github.com/joetannenbaum) in https://github.com/laravel/scout/pull/985
* Bump shivammathur/setup-php from 2.37.0 to 2.37.1 in the github-actions group by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/laravel/scout/pull/989
* Add Dependabot cooldown of 5 days by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/scout/pull/991
* Enable Dependabot auto-merge by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/scout/pull/995
* Add opt-in unique indexing jobs to prevent Scout reindexing already queued models by [@stevebauman](https://github.com/stevebauman) in https://github.com/laravel/scout/pull/996
* Bump actions/checkout from 6.0.2 to 6.0.3 in the github-actions group by [@dependabot](https://github.com/dependabot)[bot] in https://github.com/laravel/scout/pull/997

## [v11.2.0](https://github.com/laravel/sentinel/compare/v11.1.0...v11.2.0) - 2026-05-13

* Skip deleted handler during force delete on SoftDeletes models by [@jobjen02](https://github.com/jobjen02) in https://github.com/laravel/scout/pull/984

## [v11.1.0](https://github.com/laravel/sentinel/compare/v11.0.0...v11.1.0) - 2026-03-18

* Fix: meilisearch `not null` condition by [@cappuc](https://github.com/cappuc) in https://github.com/laravel/scout/pull/981

## [v11.0.0](https://github.com/laravel/sentinel/compare/v10.24.0...v11.0.0) - 2026-03-10

* Meilisearch: support filtering with backed enums by [@Carlwirkus](https://github.com/Carlwirkus) in https://github.com/laravel/scout/pull/759
* [11.x] Removed numeric filters by [@Boorinio](https://github.com/Boorinio) in https://github.com/laravel/scout/pull/839
* [11.x] Use scout prefix when deleting all indexes by [@macbookandrew](https://github.com/macbookandrew) in https://github.com/laravel/scout/pull/841
* Excludes `/types` via `.gitattributes` by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/scout/pull/907
* [11.x] Add support for `where($field, $operator, $value)` on `Builder` class by [@gdebrauwer](https://github.com/gdebrauwer) in https://github.com/laravel/scout/pull/969
* 11.x by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/scout/pull/975
* [11.x] Test Improvements by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/scout/pull/976
* [11.x] Fix typesense and meilisearch tests by [@gdebrauwer](https://github.com/gdebrauwer) in https://github.com/laravel/scout/pull/978
