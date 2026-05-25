# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/webrek/laravel-telescope-mongodb/compare/HEAD...HEAD
