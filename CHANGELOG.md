# Release Notes

## [v6.2.0 (unreleased)](https://github.com/laravel/scout/compare/v6.1.0...v6.2.0)
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
