# Release Notes

## [Unreleased](https://github.com/laravel/scout/compare/v10.12.1...10.x)

## [v10.12.1](https://github.com/laravel/scout/compare/v10.12.0...v10.12.1) - 2025-01-21

* [10.x] Check for default total count return in meilisearch by [@Boorinio](https://github.com/Boorinio) in https://github.com/laravel/scout/pull/900
* Fix filtering `null` values in `where()` with Meilisearch by [@tobz-nz](https://github.com/tobz-nz) in https://github.com/laravel/scout/pull/901

## [v10.12.0](https://github.com/laravel/scout/compare/v10.11.9...v10.12.0) - 2025-01-14

* feat: Algolia settings sync by [@joostdebruijn](https://github.com/joostdebruijn) in https://github.com/laravel/scout/pull/889
* perf(typesense): skip collection check for search operations by [@tharropoulos](https://github.com/tharropoulos) in https://github.com/laravel/scout/pull/898

## [v10.11.9](https://github.com/laravel/scout/compare/v10.11.8...v10.11.9) - 2024-12-10

* fix: merge Algolia4 options into query parameters by [@MingJen](https://github.com/MingJen) in https://github.com/laravel/scout/pull/891
* Adding orderByDesc function to Builder by [@jdavidbakr](https://github.com/jdavidbakr) in https://github.com/laravel/scout/pull/893

## [v10.11.8](https://github.com/laravel/scout/compare/v10.11.7...v10.11.8) - 2024-11-26

* [10.x] Test Improvements by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/scout/pull/880
* Fix: ambigious queries when adding other tables to the query builder by [@adminfriso](https://github.com/adminfriso) in https://github.com/laravel/scout/pull/887

## [v10.11.7](https://github.com/laravel/scout/compare/v10.11.6...v10.11.7) - 2024-11-13

* [10.x] Fix Algolia 3/4 engines by [@dwightwatson](https://github.com/dwightwatson) in https://github.com/laravel/scout/pull/884

## [v10.11.6](https://github.com/laravel/scout/compare/v10.11.5...v10.11.6) - 2024-11-12

* feat(typesense): add `whereNotIn` filter to typesense engine by [@tharropoulos](https://github.com/tharropoulos) in https://github.com/laravel/scout/pull/878
* Supports for `algolia/algoliasearch-client-php` v4 by [@3bd-ulrahman](https://github.com/3bd-ulrahman) in https://github.com/laravel/scout/pull/872
* [10.x] Supports PHP 8.4 by [@crynobone](https://github.com/crynobone) in https://github.com/laravel/scout/pull/879

## [v10.11.5](https://github.com/laravel/scout/compare/v10.11.4...v10.11.5) - 2024-10-30

* fix(typesense): properly format boolean filters in Typesense by [@tharropoulos](https://github.com/tharropoulos) in https://github.com/laravel/scout/pull/874

## [v10.11.4](https://github.com/laravel/scout/compare/v10.11.3...v10.11.4) - 2024-10-01

* Fix Typesense pagination issue when using query callback by [@tharropoulos](https://github.com/tharropoulos) in https://github.com/laravel/scout/pull/867
* Update logo to support dark/light theme by [@milewski](https://github.com/milewski) in https://github.com/laravel/scout/pull/869

## [v10.11.3](https://github.com/laravel/scout/compare/v10.11.2...v10.11.3) - 2024-09-11

* [Typesense] Fix Paginate Function Returning Limited Records in Laravel Scout with Typesense Engine (#824) by [@tharropoulos](https://github.com/tharropoulos) in https://github.com/laravel/scout/pull/858

## [v10.11.2](https://github.com/laravel/scout/compare/v10.11.1...v10.11.2) - 2024-09-03

* [10.x] Add Generic Docblocks To Builder by [@Magnesium38](https://github.com/Magnesium38) in https://github.com/laravel/scout/pull/857

## [v10.11.1](https://github.com/laravel/scout/compare/v10.11.0...v10.11.1) - 2024-08-06

* refactor(typesense): remove unused exists checks by [@saibotk](https://github.com/saibotk) in https://github.com/laravel/scout/pull/847

## [v10.11.0](https://github.com/laravel/scout/compare/v10.10.2...v10.11.0) - 2024-07-30

* [10.x] Allow setting custom scout builder class by [@gdebrauwer](https://github.com/gdebrauwer) in https://github.com/laravel/scout/pull/852

## [v10.10.2](https://github.com/laravel/scout/compare/v10.10.1...v10.10.2) - 2024-07-23

* [Typesense]  Sync server state in getOrCreateCollectionFromModel #845 by [@tharropoulos](https://github.com/tharropoulos) in https://github.com/laravel/scout/pull/846

## [v10.10.1](https://github.com/laravel/scout/compare/v10.10.0...v10.10.1) - 2024-07-02

* [10.x] Get the key name through getScoutKeyName() on the Database engine by [@antonioribeiro](https://github.com/antonioribeiro) in https://github.com/laravel/scout/pull/843

## [v10.10.0](https://github.com/laravel/scout/compare/v10.9.0...v10.10.0) - 2024-06-18

* Added possibility to version indexes.  by [@Boorinio](https://github.com/Boorinio) in https://github.com/laravel/scout/pull/836

## [v10.9.0](https://github.com/laravel/scout/compare/v10.8.6...v10.9.0) - 2024-05-07

* Allow Typesense Search Parameter Definition on Models by [@stammbach](https://github.com/stammbach) in https://github.com/laravel/scout/pull/827
* Fixing issue 825 by [@AbdullahFaqeir](https://github.com/AbdullahFaqeir) in https://github.com/laravel/scout/pull/826

## [v10.8.6](https://github.com/laravel/scout/compare/v10.8.5...v10.8.6) - 2024-04-16

* Prevent unnecessary api calls on Collection (index) by [@AbdullahFaqeir](https://github.com/AbdullahFaqeir) in https://github.com/laravel/scout/pull/820

## [v10.8.5](https://github.com/laravel/scout/compare/v10.8.4...v10.8.5) - 2024-04-02

* [Typesense] Issue when searching with queryCallback by [@karakhanyans](https://github.com/karakhanyans) in https://github.com/laravel/scout/pull/817

## [v10.8.4](https://github.com/laravel/scout/compare/v10.8.3...v10.8.4) - 2024-03-26

* Repaces when and tap functions with Conditionable and Tappable traits by [@ncharalampidis](https://github.com/ncharalampidis) in https://github.com/laravel/scout/pull/811
* [10.x] Make commands lazy by [@timacdonald](https://github.com/timacdonald) in https://github.com/laravel/scout/pull/815
* [10.x] Optional field definitions on model by [@MortenDHansen](https://github.com/MortenDHansen) in https://github.com/laravel/scout/pull/812

## [v10.8.3](https://github.com/laravel/scout/compare/v10.8.2...v10.8.3) - 2024-02-13

* [10.x] Fix zero integer value in options parameter for Typesense by [@alignwebs](https://github.com/alignwebs) in https://github.com/laravel/scout/pull/802

## [v10.8.2](https://github.com/laravel/scout/compare/v10.8.1...v10.8.2) - 2024-01-30

* [10.x] Ordering by model's custom `created_at` column by [@stevebauman](https://github.com/stevebauman) in https://github.com/laravel/scout/pull/801

## [v10.8.1](https://github.com/laravel/scout/compare/v10.8.0...v10.8.1) - 2024-01-23

* [10.x] Fix Typesense search parameters issue  by [@karakhanyans](https://github.com/karakhanyans) in https://github.com/laravel/scout/pull/795

## [v10.8.0](https://github.com/laravel/scout/compare/v10.7.0...v10.8.0) - 2024-01-16

* [10.x] Laravel v11 support by [@nunomaduro](https://github.com/nunomaduro) in https://github.com/laravel/scout/pull/789

## [v10.7.0](https://github.com/laravel/scout/compare/v10.6.1...v10.7.0) - 2024-01-09

* [10.x] Add Typesense engine by [@jasonbosco](https://github.com/jasonbosco) in https://github.com/laravel/scout/pull/773

## [v10.6.1](https://github.com/laravel/scout/compare/v10.6.0...v10.6.1) - 2023-12-05

* Fix unsearchable config by [@Casmo](https://github.com/Casmo) in https://github.com/laravel/scout/pull/783

## [v10.6.0](https://github.com/laravel/scout/compare/v10.5.1...v10.6.0) - 2023-11-28

* Add search engine meta data to results by [@tobz-nz](https://github.com/tobz-nz) in https://github.com/laravel/scout/pull/780

## [v10.5.1](https://github.com/laravel/scout/compare/v10.5.0...v10.5.1) - 2023-10-31

- Call makeSearchableUsing before searching on CollectionEngine by [@Magnesium38](https://github.com/Magnesium38) in https://github.com/laravel/scout/pull/777

## [v10.5.0](https://github.com/laravel/scout/compare/v10.4.0...v10.5.0) - 2023-10-10

- Adds latest and oldest sorting options by [@peterfox](https://github.com/peterfox) in https://github.com/laravel/scout/pull/770

## [v10.4.0](https://github.com/laravel/scout/compare/v10.3.0...v10.4.0) - 2023-09-26

- Allow configuration of Algolia batch size by [@samlev](https://github.com/samlev) in https://github.com/laravel/scout/pull/768
  **Full Changelog**: https://github.com/laravel/scout/compare/v10.3.0...v10.4.0

## [v10.3.0](https://github.com/laravel/scout/compare/v10.2.4...v10.3.0) - 2023-09-05

- Add "whereNotIn" native support to Meilisearch, Database and Collection engines by [@guestpectacular](https://github.com/guestpectacular) in https://github.com/laravel/scout/pull/760

## [v10.2.4](https://github.com/laravel/scout/compare/v10.2.3...v10.2.4) - 2023-08-08

- Add Configurable Scout Key Type by [@geisi](https://github.com/geisi) in https://github.com/laravel/scout/pull/752

## [v10.2.3](https://github.com/laravel/scout/compare/v10.2.2...v10.2.3) - 2023-07-11

- Override attributesToRetrieve config to ensure search result is complete by [@mmachatschek](https://github.com/mmachatschek) in https://github.com/laravel/scout/pull/751

## [v10.2.2](https://github.com/laravel/scout/compare/v10.2.1...v10.2.2) - 2023-05-24

- Fixes usage with `sqlsrv` databases by @nunomaduro in https://github.com/laravel/scout/pull/740

## [v10.2.1](https://github.com/laravel/scout/compare/v10.2.0...v10.2.1) - 2023-05-10

- Fixes `makeSearchableUsing` not being able to be called by @nunomaduro in https://github.com/laravel/scout/pull/739

## [v10.2.0](https://github.com/laravel/scout/compare/v10.1.1...v10.2.0) - 2023-05-09

- Add 'makeSearchableUsing' method (to allow eager loading when making specific models searchable) by @gdebrauwer in https://github.com/laravel/scout/pull/732
- Re-add options for search requests by @patrickweh in https://github.com/laravel/scout/pull/734

## [v10.1.1](https://github.com/laravel/scout/compare/v10.1.0...v10.1.1) - 2023-04-18

- Handle empty whereIn clause by @jshah4517 in https://github.com/laravel/scout/pull/729

## [v10.1.0](https://github.com/laravel/scout/compare/v10.0.2...v10.1.0) - 2023-04-11

- Removed redundant default value for config file by @siarheipashkevich in https://github.com/laravel/scout/pull/714
- Adds support for custom page names using the database engine by @lukeraymonddowning in https://github.com/laravel/scout/pull/728

## [v10.0.2](https://github.com/laravel/scout/compare/v10.0.1...v10.0.2) - 2023-03-07

- Use `newQuery()` instead of `query()` in `DatabaseEngine` by @jbelien in https://github.com/laravel/scout/pull/713

## [v10.0.1](https://github.com/laravel/scout/compare/v10.0.0...v10.0.1) - 2023-03-05

- Fix missing function issue by @JerryBels in https://github.com/laravel/scout/pull/711

## [v10.0.0](https://github.com/laravel/scout/compare/v9.8.1...v10.0.0) - 2023-03-02

- Refactor the use of getScoutKeyName by @driesvints in https://github.com/laravel/scout/pull/509
- Remove obsolete code for scout key name by @mmachatschek in https://github.com/laravel/scout/pull/545
- Provide searchable data array with primary key and value for MeiliSearch by @mmachatschek in https://github.com/laravel/scout/pull/546
- Fix custom scout keys not being utilized when deleting from queue by @stevebauman in https://github.com/laravel/scout/pull/657
- Drop old PHP and Laravel versions by @driesvints in https://github.com/laravel/scout/pull/675
- Meilisearch v1 support by @mmachatschek in https://github.com/laravel/scout/pull/678

## [v9.8.1](https://github.com/laravel/scout/compare/v9.8.0...v9.8.1) - 2023-02-14

### Changed

- Adds types to `makeAllSearchableUsing` by @nunomaduro in https://github.com/laravel/scout/pull/660

## [v9.8.0](https://github.com/laravel/scout/compare/v9.7.2...v9.8.0) - 2023-01-17

### Added

- Laravel v10 Support by @driesvints in https://github.com/laravel/scout/pull/696
- Enable order by for `collection` & `database` engines by @stein-j in https://github.com/laravel/scout/pull/695

## [v9.7.2](https://github.com/laravel/scout/compare/v9.7.1...v9.7.2) - 2023-01-09

### Fixed

- Handle non-consecutive key collection on MeiliSearch document deletion by @pyrou in https://github.com/laravel/scout/pull/688
- Fix missing variable in closure by @driesvints in https://github.com/laravel/scout/pull/694

## [v9.7.1](https://github.com/laravel/scout/compare/v9.7.0...v9.7.1) - 2023-01-06

### Fixed

- Make scout compatible with new meilisearch casing by @mmachatschek in https://github.com/laravel/scout/pull/687

## [v9.7.0](https://github.com/laravel/scout/compare/v9.6.2...v9.7.0) - 2023-01-03

### Changed

- Add analytics for Meilisearch engine by @mmachatschek in https://github.com/laravel/scout/pull/681
- Allow options for search requests by @driesvints in https://github.com/laravel/scout/pull/683

## [v9.6.2](https://github.com/laravel/scout/compare/v9.6.1...v9.6.2) - 2022-12-21

### Fixed

- Added a missing import by @driesvints in https://github.com/laravel/scout/commit/56adabcc1575a692824ffa8009719b20e7778f28

## [v9.6.1](https://github.com/laravel/scout/compare/v9.6.0...v9.6.1) - 2022-12-20

### Changed

- Allow FQCN to delete-index command by @kichetof in https://github.com/laravel/scout/pull/677

## [v9.6.0](https://github.com/laravel/scout/compare/v9.5.1...v9.6.0) - 2022-12-15

### Added

- Add command delete-all-indexes, update scout:index to allow FQCN and apply filterable on SoftDeletes by @kichetof in https://github.com/laravel/scout/pull/671
- Added soft deleted to Meilisearch by @kichetof in https://github.com/laravel/scout/pull/672

## [v9.5.1](https://github.com/laravel/scout/compare/v9.5.0...v9.5.1) - 2022-12-08

### Fixed

- Fix the sync index settings command not using the scout prefix by @tonysm in https://github.com/laravel/scout/pull/670

## [v9.5.0](https://github.com/laravel/scout/compare/v9.4.12...v9.5.0) - 2022-12-06

### Added

- Support Meilisearch index settings by @driesvints in https://github.com/laravel/scout/pull/669

## [v9.4.12](https://github.com/laravel/scout/compare/v9.4.11...v9.4.12) - 2022-10-04

### Fixed

- Fix custom scout keys not being utilized when deleting from queue by @stevebauman in https://github.com/laravel/scout/pull/656

## [v9.4.11](https://github.com/laravel/scout/compare/v9.4.10...v9.4.11) - 2022-09-27

### Fixed

- Use scout key when mapping keys from search results by @flexchar in https://github.com/laravel/scout/pull/652

## [v9.4.10](https://github.com/laravel/scout/compare/v9.4.9...v9.4.10) - 2022-07-19

### Fixed

- Return collection by @driesvints in https://github.com/laravel/scout/pull/635

## [v9.4.9](https://github.com/laravel/scout/compare/v9.4.8...v9.4.9) - 2022-05-05

### Fixed

- Apply `limit` on `DatabaseEngine` before applying additional constraints by @crynobone in https://github.com/laravel/scout/pull/621

## [v9.4.8](https://github.com/laravel/scout/compare/v9.4.7...v9.4.8) - 2022-05-03

### Changed

- Add limit to database engine by @keithbrink in https://github.com/laravel/scout/pull/619

## [v9.4.7](https://github.com/laravel/scout/compare/v9.4.6...v9.4.7) - 2022-04-06

### Fixed

- Fixed access to undefined key by @den1n in https://github.com/laravel/scout/pull/612

## [v9.4.6](https://github.com/laravel/scout/compare/v9.4.5...v9.4.6) - 2022-03-29

### Changed

- Added the ability to pass an array of options to full-text search. by @den1n in https://github.com/laravel/scout/pull/606
- Update suggested SDK versions of Algolia and Meilisearch by @mmachatschek in https://github.com/laravel/scout/pull/608

## [v9.4.5](https://github.com/laravel/scout/compare/v9.4.4...v9.4.5) - 2022-02-22

### Changed

- Remove redundant `return` key like for all `when` methods for databas… by @siarheipashkevich in https://github.com/laravel/scout/pull/592

### Fixed

- Implements Meilisearch sort on paginate by @mrABR in https://github.com/laravel/scout/pull/587
- Remove default order by model key desc in database engine when full-text index is used by @smknstd in https://github.com/laravel/scout/pull/590
- Call queryCallback in DatabaseEngine by @Alanaktion in https://github.com/laravel/scout/pull/591

## [v9.4.4](https://github.com/laravel/scout/compare/v9.4.3...v9.4.4) - 2022-02-15

### Fixed

- Fix collection engine `mapIds` bug by @amir9480 in https://github.com/laravel/scout/pull/585

## [v9.4.3](https://github.com/laravel/scout/compare/v9.4.2...v9.4.3) - 2022-02-08

### Fixed

- Skip adding search constraints with empty search on DatabaseEngine ([#582](https://github.com/laravel/scout/pull/582))

## [v9.4.2 (2022-01-18)](https://github.com/laravel/scout/compare/v9.4.1...v9.4.2)

### Added

- Add sorting for Meilisearch ([#537](https://github.com/laravel/scout/pull/537))

## [v9.4.1 (2022-01-14)](https://github.com/laravel/scout/compare/v9.4.0...v9.4.1)

### Fixed

- Fix return for `paginateRaw` ([#574](https://github.com/laravel/scout/pull/574))

## [v9.4.0 (2022-01-12)](https://github.com/laravel/scout/compare/v9.3.4...v9.4.0)

### Added

- Add a DatabaseEngine ([#564](https://github.com/laravel/scout/pull/564))

### Changed

- Optimize whereIn to use whereIntegerInRaw when primaryKey is integer ([#568](https://github.com/laravel/scout/pull/568))
- Add limit to collection engine ([#569](https://github.com/laravel/scout/pull/569))
- Laravel 9 support ([#571](https://github.com/laravel/scout/pull/571))

## [v9.3.4 (2021-12-23)](https://github.com/laravel/scout/compare/v9.3.2...v9.3.4)

No significant changes.

## [v9.3.2 (2021-11-16)](https://github.com/laravel/scout/compare/v9.3.1...v9.3.2)

### Fixed

- Fix issues for users providing searchable array without primary key ([#547](https://github.com/laravel/scout/pull/547))

## [v9.3.1 (2021-10-12)](https://github.com/laravel/scout/compare/v9.3.0...v9.3.1)

### Fixed

- Return correct output of mapIds method for MeiliSearch ([#538](https://github.com/laravel/scout/pull/538))

## [v9.3.0 (2021-10-05)](https://github.com/laravel/scout/compare/v9.2.10...v9.3.0)

### Added

- Add simplePaginateRaw query ([#534](https://github.com/laravel/scout/pull/534))

## [v9.2.10 (2021-09-28)](https://github.com/laravel/scout/compare/v9.2.9...v9.2.10)

### Changed

- Collection Engine: add support for non-scalar values ([#528](https://github.com/laravel/scout/pull/528))

### Fixed

- Support boolean filters ([#524](https://github.com/laravel/scout/pull/524))

## [v9.2.9 (2021-09-14)](https://github.com/laravel/scout/compare/v9.2.8...v9.2.9)

### Fixed

- Searching on custom searchable data when using collection driver ([#521](https://github.com/laravel/scout/pull/521))

## [v9.2.8 (2021-08-31)](https://github.com/laravel/scout/compare/v9.2.7...v9.2.8)

### Changed

- Add the ability to omit the search argument in the `CollectionEngine` ([#515](https://github.com/laravel/scout/pull/515))

### Fixed

- Update meilisearch-sdk version to v0.19.0 ([#511](https://github.com/laravel/scout/pull/511))
- Check for meilisearch-php 0.19.0 instead ([#513](https://github.com/laravel/scout/pull/513))

## [v9.2.7 (2021-08-24)](https://github.com/laravel/scout/compare/v9.2.6...v9.2.7)

### Changed

- Support rename of filters to filter in meilisearch 0.21.x ([#510](https://github.com/laravel/scout/pull/510))

## [v9.2.6 (2021-08-17)](https://github.com/laravel/scout/compare/v9.2.5...v9.2.6)

### Fixed

- Fixed non string columns breaking model filter with collection driver ([#507](https://github.com/laravel/scout/pull/507))

## [v9.2.5 (2021-08-10)](https://github.com/laravel/scout/compare/v9.2.4...v9.2.5)

### Fixed

- `HasManyThrough::macro('unsearchable')` fix ([#505](https://github.com/laravel/scout/pull/505))

## [v9.2.4 (2021-08-03)](https://github.com/laravel/scout/compare/v9.2.3...v9.2.4)

### Changed

- Timeout options for algolia client ([#501](https://github.com/laravel/scout/pull/501))

### Fixed

- Fix meilisearch where in ([#498](https://github.com/laravel/scout/pull/498))

## [v9.2.3 (2021-07-13)](https://github.com/laravel/scout/compare/v9.2.2...v9.2.3)

### Changed

- Filter on sensitive attributes ([#491](https://github.com/laravel/scout/pull/491), [1dfde65](https://github.com/laravel/scout/commit/1dfde65d4d9fa78512c68020f6fa05ee0f19eae8))

## [v9.2.2 (2021-07-06)](https://github.com/laravel/scout/compare/v9.2.1...v9.2.2)

### Changed

- Improve observer strategy ([#490](https://github.com/laravel/scout/pull/490), [19cff04](https://github.com/laravel/scout/commit/19cff04e97f3fbaf67bf2bbe68a5d4daba6ba8b1))
- Downcase attribute and query for case-insensitive search ([#493](https://github.com/laravel/scout/pull/493))
- Use numeric check ([996256a](https://github.com/laravel/scout/commit/996256abf3b59db3e8dd3b428e027c0c1b2c37d3))
- Custom callback support on collection engine ([7da9dd6](https://github.com/laravel/scout/commit/7da9dd69df7e63d48c53f5e92fa777b2b67d352e))

## [v9.2.1 (2021-06-29)](https://github.com/laravel/scout/compare/v9.2.0...v9.2.1)

### Added

- Add `whereIn` support ([2b1dd75](https://github.com/laravel/scout/commit/2b1dd75adb533d71d3430ea91cd061bfe2fa0f32))

### Fixed

- Filter on should be searchable ([ad60f5b](https://github.com/laravel/scout/commit/ad60f5bf38b735e8a4178039515f4e30f44126b6))
- Handle soft deletes ([f04927d](https://github.com/laravel/scout/commit/f04927d21bf48b79040189b41464e33b6d26dd1d), [b95af2e](https://github.com/laravel/scout/commit/b95af2e7a231f4403c7add1a7eba96cac1b415fb), [31073e4](https://github.com/laravel/scout/commit/31073e4ad5c0977a9a088b8793bba0e2c3d29c5d))
- Fix pagination ([733eda3](https://github.com/laravel/scout/commit/733eda3d44140f87e235805531cd4c8c9ac04b59))

## [v9.2.0 (2021-06-29)](https://github.com/laravel/scout/compare/v9.1.2...v9.2.0)

### Added

- Collection Engine ([#488](https://github.com/laravel/scout/pull/488))

## [v9.1.2 (2021-06-15)](https://github.com/laravel/scout/compare/v9.1.1...v9.1.2)

### Fixed

- Fix removing queued models with custom Scout keys ([#480](https://github.com/laravel/scout/pull/480))
- Re-query scout engine when paginate results contains insufficient keys to generate proper pagination count query ([#483](https://github.com/laravel/scout/pull/483))

## [v9.1.1 (2021-06-08)](https://github.com/laravel/scout/compare/v9.1.0...v9.1.1)

### Changed

- Overridable jobs ([#476](https://github.com/laravel/scout/pull/476))

## [v9.1.0 (2021-05-13)](https://github.com/laravel/scout/compare/v9.0.0...v9.1.0)

### Added

- Use queued job for "unsearching" when Scout queue is enabled ([#471](https://github.com/laravel/scout/pull/471))

### Changed

- Remove useless variable in `simplePaginate` ([#472](https://github.com/laravel/scout/pull/472))

## [v9.0.0 (2021-04-27)](https://github.com/laravel/scout/compare/v8.6.1...v9.0.0)

### Added

- Support MeiliSearch Engine ([#455](https://github.com/laravel/scout/pull/455), [#457](https://github.com/laravel/scout/pull/457))
- Add support for cursor and LazyCollection on scout ([#439](https://github.com/laravel/scout/pull/439), [1ebcd0d](https://github.com/laravel/scout/commit/1ebcd0d11185d43cea18e9b774b2926314311e41), [#470](https://github.com/laravel/scout/pull/470))

### Changed

- Drop support for old Laravel versions and PHP 7.2 ([#459](https://github.com/laravel/scout/pull/459))

### Fixed

- Fixes pagination count when `Laravel\Scout\Builder` contains custom query callback ([#469](https://github.com/laravel/scout/pull/469))

## [v8.6.1 (2021-04-06)](https://github.com/laravel/scout/compare/v8.6.0...v8.6.1)

### Changed

- Move booting of services ([#453](https://github.com/laravel/scout/pull/453))
- Add reset method ([fb8ce0c](https://github.com/laravel/scout/commit/fb8ce0c3a0ea33fc67be9a0916bf049a7e86bd54))

## [v8.6.0 (2021-01-19)](https://github.com/laravel/scout/compare/v8.5.4...v8.6.0)

### Added

- Add ability to use simplePaginate ([#443](https://github.com/laravel/scout/pull/443))

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
