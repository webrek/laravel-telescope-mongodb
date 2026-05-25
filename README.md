# Laravel Telescope MongoDB Driver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/webrek/laravel-telescope-mongodb.svg?style=flat-square)](https://packagist.org/packages/webrek/laravel-telescope-mongodb)
[![Total Downloads](https://img.shields.io/packagist/dt/webrek/laravel-telescope-mongodb.svg?style=flat-square)](https://packagist.org/packages/webrek/laravel-telescope-mongodb)
[![Tests](https://img.shields.io/github/actions/workflow/status/webrek/laravel-telescope-mongodb/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/webrek/laravel-telescope-mongodb/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/webrek/laravel-telescope-mongodb.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/packagist/l/webrek/laravel-telescope-mongodb.svg?style=flat-square)](LICENSE)

A drop-in MongoDB storage driver for [Laravel Telescope](https://laravel.com/docs/telescope). Run Telescope on a MongoDB-only stack without touching MySQL or PostgreSQL.

## Quickstart

```bash
composer require webrek/laravel-telescope-mongodb
php artisan telescope-mongodb:install
```

That's it. The installer publishes Telescope's assets, removes the
relational migration Telescope ships (you do not need a SQL database),
creates the seven MongoDB indexes the driver relies on, and binds itself
to Telescope's `EntriesRepository`. Open `/telescope` and you will see
your requests, exceptions, jobs, queries, mails, notifications and
batches streaming straight from MongoDB.

Already running Telescope on MySQL or PostgreSQL? Migrate your existing
data in one command:

```bash
php artisan telescope-mongodb:migrate-from-sql --from=mysql
```

## Why

Telescope ships with an Eloquent-backed storage layer that relies on JSON columns, joins to a tag pivot table, and an auto-increment `sequence`. None of that maps cleanly onto MongoDB, which is why Telescope has historically required a relational database alongside your Mongo workload.

This package implements Telescope's `EntriesRepository`, `ClearableRepository`, `PrunableRepository`, and `TerminableRepository` contracts directly against MongoDB. Tags live on the entry document as an array, the `_id` ObjectId acts as the monotonic sequence, and indexes are managed for you.

## Why move Telescope off your primary database

A common Telescope-in-production story: traffic grows, the dashboard starts feeling sluggish, and you notice MySQL is suddenly pinned at 60-80% CPU. The application queries did not change — Telescope is the new hot tenant on your primary database.

Five mechanics make the default storage layer expensive on relational engines:

1. **Write amplification.** A typical request produces 5–20+ Telescope entries: one `request`, a handful of `query`, one or two `view`, optionally `cache`, `mail`, `notification`, `job`, `exception`. Multiply by your request rate and your primary database is absorbing writes that have nothing to do with your business data.
2. **Auto-increment contention.** Every insert into `telescope_entries` takes a row-level lock on the `sequence` `AUTO_INCREMENT`. Under concurrent traffic those inserts serialise against the same hot spot.
3. **JSON column scans.** The `content` field is `LONGTEXT` holding JSON. MySQL does not index JSON natively, so dashboard filters fall back to full scans or `JSON_EXTRACT` calls that fight for the same buffer pool your application queries depend on.
4. **Pivot writes.** Tags live in `telescope_entries_tags`. An entry with N tags is `1 + N` inserts, all of which take their own locks.
5. **Buffer pool contention.** The Telescope working set evicts your application's hot pages out of InnoDB's buffer pool, slowing down the queries that actually matter.

MongoDB removes each of these by construction:

- **No central sequence.** `_id` is an `ObjectId` minted by the driver, monotonic but contention-free.
- **Tags are an embedded array** with a native multikey index. One document, zero pivots.
- **Document-level indexes on `type`, `tags`, `family_hash`, `batch_id`, `should_display_on_index`** — the dashboard's filters hit indexes directly.
- **Separate database.** Telescope writes go to your Mongo server (or Atlas tier), not the SQL server that holds your real data — zero buffer-pool competition.
- **Optional TTL index.** MongoDB removes expired entries server-side, continuously, with no `telescope:prune` cron required.

### Benchmark: MySQL vs. MongoDB driver

The repository ships with a reproducible benchmark (`make bench-setup && make bench`) that boots two identical Laravel 13 applications side by side — one writing through stock Telescope to MySQL 8, one writing through this driver to MongoDB 7. Same routes, same request count, same `php artisan serve` process, same host. Numbers below were captured on a Docker Desktop host (Apple Silicon) with both database containers and the load generator competing for the same CPU — directional, not absolute.

| Workload                | Storage           | Throughput        | p50 latency  | p95 latency  | p99 latency  |
| ----------------------- | ----------------- | ----------------- | ------------ | ------------ | ------------ |
| 500 req × 10 concurrent | MongoDB driver    | **247.5 req/s**   | **33.9 ms**  | **39.5 ms**  | **41.8 ms**  |
| 500 req × 10 concurrent | Stock MySQL       | 130.2 req/s       | 69.0 ms      | 79.4 ms      | 92.0 ms      |
| 1000 req × 20 concurrent| MongoDB driver    | **262.9 req/s**   | **69.0 ms**  | **78.4 ms**  | **82.4 ms**  |
| 1000 req × 20 concurrent| Stock MySQL       | 131.3 req/s       | 144.5 ms     | 160.1 ms     | 184.8 ms     |

End-to-end the driver is **roughly 2× the throughput and 2× lower latency** of the stock MySQL backend on this workload. Production differences tend to be larger because in production your MySQL is also serving the application's own queries — the buffer-pool contention is real.

Reproduce it yourself:

```bash
make bench-setup
REQUESTS=1000 CONCURRENCY=20 make bench
```

## How this compares to existing packages

A few prior attempts exist on Packagist. They all take the same shape — a **complete fork** of `laravel/telescope` that re-vendors thousands of lines of PHP, Vue, JS and Blade so a handful of storage classes can be swapped. This package takes a different path: it ships a few hundred lines of storage code that implement Telescope's public contracts. Telescope itself is installed as a normal Composer dependency and updated by the Laravel team.

| Package | Approach | Latest Laravel | Maintenance shape |
| --- | --- | --- | --- |
| `webrek/laravel-telescope-mongodb` (this one) | Driver implementing the four Telescope contracts | 11 / 12 / 13 | Track upstream by bumping `laravel/telescope` |
| `dij-digital/telescope-mongodb` | Full fork of Telescope itself (~2k commits ahead of upstream) | 11 / 12 | Rebase or merge every Telescope release |
| `farmani/telescope-mongodb` | Full fork | up to 11 | Same fork rebase burden |
| `yektadg/laravel-telescope-mongodb` | Full fork (jenssegers/mongodb era) | up to 9 | Stale since early 2024 |

In practice, that means three things if you pick this package:

- **You stay on the Telescope your team already knows.** Same Vue dashboard, same watchers, same authorization gate. Only the storage layer changes.
- **Security and feature updates flow through Composer.** When `laravel/telescope` ships a fix, `composer update` brings it to you — there is no rebase queue waiting on a maintainer to merge.
- **The diff a security reviewer has to read is small.** All MongoDB-specific behaviour lives in `src/Storage/MongoDbEntriesRepository.php` and four artisan commands — under 1,000 lines total.

## Requirements

| Component                       | Version              |
| ------------------------------- | -------------------- |
| PHP                             | 8.3+                 |
| Laravel                         | 11.x / 12.x / 13.x   |
| Telescope                       | 5.x                  |
| MongoDB server                  | 6.0+                 |
| `mongodb/laravel-mongodb`       | 5.x                  |
| `ext-mongodb`                   | 1.18+ or 2.x         |

## Installation

```bash
composer require webrek/laravel-telescope-mongodb
```

Make sure `mongodb/laravel-mongodb` is configured in `config/database.php` with a `mongodb` connection:

```php
'mongodb' => [
    'driver'   => 'mongodb',
    'dsn'      => env('MONGODB_URI'),
    'database' => env('MONGODB_DATABASE', 'app'),
],
```

Then run the installer:

```bash
php artisan telescope-mongodb:install
```

That command will:

1. Publish `config/telescope-mongodb.php`.
2. Run `telescope:install` so the standard Telescope UI/config is in place.
3. Create the required MongoDB indexes via `telescope-mongodb:sync-indexes`.

You do **not** need to run Telescope's migrations — this driver does not use SQL tables. If your Laravel project has no SQL database at all, remove the `telescope:install` step and set `TELESCOPE_ENABLED=true` directly.

## Configuration

```php
return [

    'connection' => env('TELESCOPE_MONGODB_CONNECTION', 'mongodb'),

    'collections' => [
        'entries'    => env('TELESCOPE_MONGODB_ENTRIES_COLLECTION', 'telescope_entries'),
        'monitoring' => env('TELESCOPE_MONGODB_MONITORING_COLLECTION', 'telescope_monitoring'),
    ],

    'chunk' => [
        'store' => (int) env('TELESCOPE_MONGODB_STORE_CHUNK', 1000),
        'prune' => (int) env('TELESCOPE_MONGODB_PRUNE_CHUNK', 5000),
    ],

    'indexes' => [
        'auto_create' => (bool) env('TELESCOPE_MONGODB_AUTO_INDEXES', true),
    ],

];
```

## Document shape

Entries are stored as a single document per Telescope entry:

```json
{
    "_id": ObjectId("..."),
    "uuid": "0f24c1f0-...-...",
    "batch_id": "0f24c1f0-...-...",
    "family_hash": null,
    "should_display_on_index": true,
    "type": "request",
    "content": { "method": "GET", "uri": "/users", "...": "..." },
    "tags": ["status:200", "Auth:42"],
    "created_at": ISODate("...")
}
```

Indexes created by `telescope-mongodb:sync-indexes`:

| Name                  | Key                                                       | Notes                          |
| --------------------- | --------------------------------------------------------- | ------------------------------ |
| `uuid_unique`         | `{ uuid: 1 }`                                             | unique                         |
| `batch_id`            | `{ batch_id: 1 }`                                         |                                |
| `family_hash`         | `{ family_hash: 1 }`                                      | exception grouping             |
| `type_recent`         | `{ type: 1, _id: -1 }`                                    | listing                        |
| `tags`                | `{ tags: 1 }`                                             | multikey on tag array          |
| `display_type_recent` | `{ should_display_on_index: 1, type: 1, _id: -1 }`        | default Telescope index views  |
| `created_at`          | `{ created_at: 1 }`                                       | pruning                        |

## Pruning

Telescope's `telescope:prune` command works out of the box because this driver implements `PrunableRepository`:

```bash
php artisan telescope:prune --hours=48
php artisan telescope:prune --hours=72 --keep-exceptions
```

Pruning is chunked (see `chunk.prune` in config) so it stays gentle on the working set.

## Pagination

Telescope's `before` cursor is the entry sequence. Since MongoDB has no auto-increment, this driver returns the `ObjectId` as the `id` and `sequence` fields. ObjectIds are monotonic, so the existing Telescope UI pagination works without changes.

## Production checklist

The defaults are safe but conservative — review these knobs before you run this in front of real traffic.

### Lock down the dashboard

Telescope's `TelescopeServiceProvider` ships with a `gate()` method that whitelists which users can open `/telescope` outside `local`. The driver does not change that — make sure the published provider is updated:

```php
// app/Providers/TelescopeServiceProvider.php
protected function gate(): void
{
    Gate::define('viewTelescope', function (User $user) {
        return in_array($user->email, [
            'you@yourcompany.com',
        ]);
    });
}
```

### Pick a retention strategy

You have two options. They are mutually exclusive — pick one.

- **Cron-driven (`telescope:prune`)** — keeps you in control of *when* deletion happens. Schedule it in `app/Console/Kernel.php`:
  ```php
  $schedule->command('telescope:prune --hours=48 --keep-exceptions')->hourly();
  ```
- **Server-driven (TTL index)** — MongoDB removes documents automatically as they age past the configured TTL. No cron, no cold paths. Set it once and the `sync-indexes` command will install the TTL index:
  ```bash
  TELESCOPE_MONGODB_TTL_SECONDS=172800   # 48 hours
  php artisan telescope-mongodb:sync-indexes
  ```
  TTL is the right default for most teams — it removes a class of failure (cron never ran) and amortises deletion cost continuously instead of in large nightly bursts.

### Choose a write concern that matches your deployment

The `write_concern` config maps directly to MongoDB's [write concern](https://www.mongodb.com/docs/manual/reference/write-concern/). Recommended values:

| Deployment            | `w`        | `journal` | Why                                                       |
| --------------------- | ---------- | --------- | --------------------------------------------------------- |
| Single-node (dev)     | `1`        | `false`   | Default. Lowest latency, single-server durability.        |
| Replica set           | `majority` | `true`    | Survives a single-node failure mid-write.                 |
| High-volume tracing   | `0`        | `false`   | Fire-and-forget. Accept occasional loss for low latency.  |
| Atlas Serverless / M0 | `majority` | `false`   | Atlas enforces majority anyway; journal is implicit.      |

Configured via env:

```env
TELESCOPE_MONGODB_WRITE_W=majority
TELESCOPE_MONGODB_WRITE_JOURNAL=true
TELESCOPE_MONGODB_WRITE_TIMEOUT_MS=2000
```

### Verify the install

After every deploy, run the doctor to catch index drift, missing TTL, or a stale Mongo server:

```bash
php artisan telescope-mongodb:doctor
```

Exit code is `0` when everything is healthy and `1` if the connection or required indexes are missing.

### Scale: when the collection grows past a few million entries

MongoDB scales the `telescope_entries` collection naturally up to tens of millions of documents on a single node. Beyond that, the typical pattern is:

1. Reduce TTL so the collection stays bounded.
2. If you need long retention plus high write rate, shard on `{ _id: "hashed" }` — `_id` is monotonic but hashed sharding spreads the writes evenly while still giving you `_id`-based pagination.
3. Move heavy `content` payloads (views, large request bodies) off the hot path by filtering them in Telescope's `filter` callback before they reach storage.

## Testing

The repository ships with a fully containerised test environment so contributors do not need PHP or MongoDB installed locally. A PHP 8.3 container with `ext-mongodb` and Composer is wired against a `mongo:7` service in `docker-compose.yml`.

```bash
make build       # build the PHP image once
make install     # composer install inside the container
make test        # run the full PHPUnit suite against MongoDB
make shell       # drop into a bash shell inside the PHP container
make mongo-shell # open a mongosh shell against the test database
make clean       # tear everything down
```

### End-to-end Laravel playground

For an end-to-end smoke test against a real Laravel app, the repository also ships with a bootstrap script. It creates a fresh Laravel installation under `playground/`, wires this package via a Composer path repository, points it at the Mongo service, runs the installer, and seeds a handful of demo routes.

```bash
make build           # one-time
make playground      # composer create-project + install package + install Telescope
make playground-up   # boot php artisan serve on http://127.0.0.1:8000

curl http://127.0.0.1:8000/ping
curl http://127.0.0.1:8000/log-something
curl http://127.0.0.1:8000/mongo-query
curl http://127.0.0.1:8000/boom

open http://127.0.0.1:8000/telescope
```

After a few requests, `make mongo-shell` followed by `use telescope_playground; db.telescope_entries.find()` shows the captured documents stored natively. `make playground-reset` tears it all down and rebuilds.

If you prefer to run things on your host, point the suite at any reachable MongoDB instance:

```bash
export TELESCOPE_MONGODB_TEST_DSN="mongodb://127.0.0.1:27017"
export TELESCOPE_MONGODB_TEST_DATABASE="telescope_mongodb_tests"

vendor/bin/phpunit
```

## License

MIT
