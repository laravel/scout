# Release Notes

## [Unreleased](https://github.com/laravel/scout/compare/v7.1.1...7.0)


## [v7.1.1](https://github.com/laravel/scout/compare/v7.1.0...v7.1.1)

### Changed
- Remove support for PHP 7.0 ([217c2ee](https://github.com/laravel/scout/commit/217c2eebacb2fb242083102222428fa492b637bd))

### Fixed
- Fix engine results order ([#369](https://github.com/laravel/scout/pull/369), [bde4969](https://github.com/laravel/scout/commit/bde49694850e1c025bea7a77f3bd422862c7ab87))
- Fix empty update with soft delete ([#370](https://github.com/laravel/scout/pull/370))


## [v7.1.0 (2019-02-14)](https://github.com/laravel/scout/compare/v7.0.0...v7.1.0)

### Added
- Added support for Laravel 5.8 ([694d83b](https://github.com/laravel/scout/commit/694d83bfc735cc2147c5ad57b034ea89a7893e08))


## [v7.0.0 (2019-02-08)](https://github.com/laravel/scout/compare/v6.1.3...v7.0.0)

### Changed
- Upgraded Algolia API client to v2 ([#349](https://github.com/laravel/scout/pull/349), [#353](https://github.com/laravel/scout/pull/353))


## [v6.1.3 (2018-12-11)](https://github.com/laravel/scout/compare/v6.1.2...v6.1.3)

### Fixed
- Pass plain array to newCollection method ([68fbcd1](https://github.com/laravel/scout/commit/68fbcd1e67fd1e0b9ee8ba32ece2e68e28630c7e))


## [v6.1.2 (2018-12-11)](https://github.com/laravel/scout/compare/v6.1.1...v6.1.2)

### Fixed
- Use Model collection where appropriate ([#334](https://github.com/laravel/scout/pull/334))


## [v6.1.1 (2018-11-20)](https://github.com/laravel/scout/compare/v6.1.0...v6.1.1)

### Added
- Builder implementation can be changed using the container ([#322](https://github.com/laravel/scout/pull/322))


## [v6.1.0 (2018-11-19)](https://github.com/laravel/scout/compare/v6.0.0...v6.1.0)

### Fixed
- Fix soft delete on `Searchable` trait ([#321](https://github.com/laravel/scout/pull/321))

### Changed
- Skip empty updates for `AlgoliaEngine` ([#318](https://github.com/laravel/scout/pull/318))


## [v6.0.0 (2018-10-08)](https://github.com/laravel/scout/compare/v5.0.3...v6.0.0)

### Changed
- Adds default `$query` value on `Searchable::search` ([#309](https://github.com/laravel/scout/pull/309))
- Flush records of a model using the engine. **This removes the emitting of the `ModelsFlushed` event.** ([#310](https://github.com/laravel/scout/pull/310))
