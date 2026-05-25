# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.1] - 2026-05-25

### Fixed
- Lower bound on `ext-mongodb` and `mongodb/mongodb` bumped from
  `^1.18` to `^1.21` to match what `mongodb/laravel-mongodb 5.x`
  actually requires. Previous declared minimums would have failed
  resolution under `composer install --prefer-lowest` (the constraint
  was effectively dead weight — Composer was always converging to 1.21+
  through the transitive Laravel package).
- README Requirements table updated accordingly.

## [1.1.0] - 2026-05-25

### Added
- README section "Why move Telescope off your primary database"
  documenting the five MySQL contention mechanics (write amplification,
  AUTO_INCREMENT row lock, JSON column scans, tag pivot writes, buffer
  pool contention) and how MongoDB removes each one structurally.
- Reproducible MySQL-vs-Mongo benchmark: `docker-compose.yml` ships
  `mysql:8` and `laravel-mysql` services behind a `bench` profile,
  `scripts/bootstrap-playground-mysql.sh` stands up a stock-Telescope
  control group, and `scripts/bench.sh` runs identical traffic against
  both backends and reports throughput plus p50/p95/p99.
- `make bench-setup`, `make bench`, `make bench-down` targets so the
  benchmark is one command end to end.
- Published benchmark numbers in the README: on the reference workload
  the driver delivers ~2× the throughput and ~50% lower p99 latency of
  the stock MySQL backend.

### Changed
- `update()` now uses a single `findOneAndUpdate` per change with dot-
  notation `content.<field>` updates instead of `findOne` + `updateOne`.
  Halves the round-trips per terminate callback (jobs, scheduled tasks,
  exceptions in the after-commit flow).
- `storeExceptions()` issues a single `bulkWrite` per batch, replacing
  the previous per-exception sequence of `countDocuments`, `updateMany`
  and `insertOne`. Existing occurrence counts are now fetched in one
  aggregation across all family hashes in the batch.

## [1.0.1] - 2026-05-25

### Added
- Comparison table in the README documenting how this package differs
  from the existing `dij-digital/telescope-mongodb`,
  `farmani/telescope-mongodb` and `yektadg/laravel-telescope-mongodb`
  forks (all of which re-vendor Telescope itself).
- Production checklist covering authorization gate, TTL vs cron-driven
  retention, write concern presets per deployment shape, the doctor
  command as a post-deploy gate, and sharding guidance for very large
  collections.

### Changed
- Expanded `composer.json` metadata: keyword list now mirrors the
  GitHub topics, plus a `support` block (issues / source / security),
  `homepage`, `authors`, and Composer scripts so contributors can run
  `composer test`, `composer stan`, `composer pint` without going
  through the Makefile.
- Tightened the Requirements table in the README to reflect the
  Laravel 13 support already shipped in 1.0.0.

## [1.0.0] - 2026-05-25

### Added
- Native MongoDB storage driver implementing Telescope's `EntriesRepository`,
  `ClearableRepository`, `PrunableRepository` and `TerminableRepository`
  contracts on top of `mongodb/laravel-mongodb`.
- Single-document storage with embedded tag arrays, `ObjectId` as the
  monotonic sequence cursor, and chunked store / prune operations.
- `telescope-mongodb:install` artisan command that publishes the package
  config, runs `telescope:install`, removes the relational migration
  Telescope ships (the SQL tables this driver does not need), and creates
  every required MongoDB index.
- `telescope-mongodb:sync-indexes` artisan command to (re)create the seven
  collection indexes; supports `--drop` for a clean rebuild.
- `telescope-mongodb:doctor` artisan command for diagnostics (connection
  check, index parity, server version, entry counts).
- `telescope-mongodb:migrate-from-sql` artisan command to migrate an
  existing Telescope SQL deployment into MongoDB without data loss.
- Optional TTL index on `created_at` via `indexes.ttl_seconds` config so
  MongoDB can auto-purge expired entries without a cron-driven prune.
- Configurable write concern (`write_concern` config block) for tuning
  durability vs. latency on bulk inserts.
- Docker-based development environment (`Dockerfile`, `docker-compose.yml`)
  with PHP 8.3 + `ext-mongodb`, Composer, and a `mongo:7` service.
- `Makefile` with targets for the test suite (`make test`), a full Laravel
  playground (`make playground`), formatting (`make pint`), and static
  analysis (`make stan`).
- `scripts/bootstrap-playground.sh` that creates a real Laravel app under
  `playground/`, wires this package via a Composer path repository, and
  installs Telescope so the integration can be smoke-tested in seconds.
- PHPUnit suite covering store, find, paginated get, exception family
  hash deduplication, update, monitoring tags, prune and clear against a
  real MongoDB instance.
- Unit tests for the filter builder and result hydration covering edge
  cases without a live database.
- GitHub Actions workflow matrix for PHP 8.3 / 8.4 and Laravel 11 / 12 / 13.

[Unreleased]: https://github.com/webrek/laravel-telescope-mongodb/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/webrek/laravel-telescope-mongodb/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/webrek/laravel-telescope-mongodb/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/webrek/laravel-telescope-mongodb/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/webrek/laravel-telescope-mongodb/releases/tag/v1.0.0
