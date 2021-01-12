# Release Notes

## [Unreleased](https://github.com/laravel/scout/compare/v8.5.4...8.x)


## [v8.5.4 (2021-01-12)](https://github.com/laravel/scout/compare/v8.5.3...v8.5.4)

### Changed
- Use the Config facade instead ([#442](https://github.com/laravel/scout/pull/442))


## [v8.5.3 (2021-01-05)](https://github.com/laravel/scout/compare/v8.5.2...v8.5.3)

### Fixed
- Allow running observer callbacks after database transactions have committed ([#440](https://github.com/laravel/scout/pull/440), [56ea20d](https://github.com/laravel/scout/commit/56ea20d8e46cc9cd03d04cbdd071e4deb94e7f26))


## [v8.5.2 (2020-12-30)](https://github.com/laravel/scout/compare/v8.5.1...v8.5.2)

### Changed
- Revert `$afterCommit` property ([ece6758](https://github.com/laravel/scout/commit/ece6758b82c51ff7f5e011f243a7c6b33711a847))


## [v8.5.1 (2020-12-22)](https://github.com/laravel/scout/compare/v8.5.0...v8.5.1)

### Changed
- Run observer callbacks after database transactions have committed ([#436](https://github.com/laravel/scout/pull/436))


## [v8.5.0 (2020-12-10)](https://github.com/laravel/scout/compare/v8.4.0...v8.5.0)

### Added
- PHP 8 Support ([#425](https://github.com/laravel/scout/pull/425))


## [v8.4.0 (2020-10-20)](https://github.com/laravel/scout/compare/v8.3.1...v8.4.0)

### Added
- Add `makeAllSearchableUsing` ([bf8585e](https://github.com/laravel/scout/commit/bf8585eaff9204d23602f9c064b7e3cc074212e2))


## [v8.3.1 (2020-09-01)](https://github.com/laravel/scout/compare/v8.3.0...v8.3.1)

### Fixed
- Fix HasManyThrough relationships ([#416](https://github.com/laravel/scout/pull/416))


## [v8.3.0 (2020-08-25)](https://github.com/laravel/scout/compare/v8.2.1...v8.3.0)

### Added
- Laravel 8 support ([#415](https://github.com/laravel/scout/pull/415))

### Changed
- Update builder class pagination methods to resolve LengthAwarePaginator using container ([#413](https://github.com/laravel/scout/pull/413))


## [v8.2.1 (2020-08-06)](https://github.com/laravel/scout/compare/v8.2.0...v8.2.1)

### Fixed
- Fix undefined `$user` variable bug ([e751cf4](https://github.com/laravel/scout/commit/e751cf4669ecab2fce887265280d1dfd29075aef))


## [v8.2.0 (2020-08-04)](https://github.com/laravel/scout/compare/v8.1.0...v8.2.0)

### Added
- Identifying users ([#411](https://github.com/laravel/scout/pull/411), [fe28ab2](https://github.com/laravel/scout/commit/fe28ab26bf1e5c9c3b46f2535bea746b69fa6fb1))


## [v8.1.0 (2020-07-14)](https://github.com/laravel/scout/compare/v8.0.1...v8.1.0)

### Added
- Optional param for chunk size on `scout:import` ([#407](https://github.com/laravel/scout/pull/407))


## [v8.0.1 (2020-04-21)](https://github.com/laravel/scout/compare/v8.0.0...v8.0.1)

### Fixed
- Merge default scout configs ([#402](https://github.com/laravel/scout/pull/402))


## [v8.0.0 (2020-03-03)](https://github.com/laravel/scout/compare/v7.2.1...v8.0.0)

### Changed
- Use chunkById instead of chunk ([#360](https://github.com/laravel/scout/pull/360))
- Drop support for Laravel 5.x
- Drop support for PHP 7.1


## [v7.2.1 (2019-09-24)](https://github.com/laravel/scout/compare/v7.2.0...v7.2.1)

### Fixed
- Proper version ([44c8924](https://github.com/laravel/scout/commit/44c8924815aab8dbbb1388bbd468e67f398ff3ef))


## [v7.2.0 (2019-09-24)](https://github.com/laravel/scout/compare/v7.1.3...v7.2.0)

### Added
- Add `__call()` method to AlgoliaEngine ([#384](https://github.com/laravel/scout/pull/384))


## [v7.1.3 (2019-07-30)](https://github.com/laravel/scout/compare/v7.1.2...v7.1.3)

### Changed
- Updated version constraints for Laravel 6 ([b31e612](https://github.com/laravel/scout/commit/b31e6123776ae7f5006dd8e12701e3d661c3db0d))



## [v7.1.2 (2019-04-30)](https://github.com/laravel/scout/compare/v7.1.1...v7.1.2)

### Fixed
- Calling `values()` on sorted collection to reset the array keys ([#372](https://github.com/laravel/scout/pull/372))


## [v7.1.1 (2019-04-02)](https://github.com/laravel/scout/compare/v7.1.0...v7.1.1)

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
