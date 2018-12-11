# Release Notes

## [v6.1.3](https://github.com/laravel/scout/compare/v6.1.2...v6.1.3)
### Fixed
- Pass plain array to newCollection method ([68fbcd1](https://github.com/laravel/scout/commit/68fbcd1e67fd1e0b9ee8ba32ece2e68e28630c7e))

## [v6.1.1](https://github.com/laravel/scout/compare/v6.1.0...v6.1.1)
### Added
- Builder implementation can be changed using the container ([#322](https://github.com/laravel/scout/pull/322))

## [v6.1.0](https://github.com/laravel/scout/compare/v6.0.0...v6.1.0)

### Fixed
- Fix soft delete on `Searchable` trait ([#321](https://github.com/laravel/scout/pull/321))

### Changed
- Skip empty updates for `AlgoliaEngine` ([#318](https://github.com/laravel/scout/pull/318))

## [v6.0.0](https://github.com/laravel/scout/compare/v5.0.3...v6.0.0)

### Changed
- Flush records of a model using the engine. **This removes the emitting of the `ModelsFlushed` event.** ([#310](https://github.com/laravel/scout/pull/310))
