# Laravel Telescope MongoDB Driver

[![Latest Version on Packagist](https://img.shields.io/packagist/v/webrek/laravel-telescope-mongodb.svg?style=flat-square)](https://packagist.org/packages/webrek/laravel-telescope-mongodb)
[![Total Downloads](https://img.shields.io/packagist/dt/webrek/laravel-telescope-mongodb.svg?style=flat-square)](https://packagist.org/packages/webrek/laravel-telescope-mongodb)
[![Tests](https://img.shields.io/github/actions/workflow/status/webrek/laravel-telescope-mongodb/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/webrek/laravel-telescope-mongodb/actions/workflows/tests.yml)
[![PHP Version](https://img.shields.io/packagist/php-v/webrek/laravel-telescope-mongodb.svg?style=flat-square)](https://php.net)
[![License](https://img.shields.io/packagist/l/webrek/laravel-telescope-mongodb.svg?style=flat-square)](LICENSE)

A drop-in MongoDB storage driver for [Laravel Telescope](https://laravel.com/docs/telescope). Run Telescope on a MongoDB-only stack without touching MySQL or PostgreSQL.

## Why

Telescope ships with an Eloquent-backed storage layer that relies on JSON columns, joins to a tag pivot table, and an auto-increment `sequence`. None of that maps cleanly onto MongoDB, which is why Telescope has historically required a relational database alongside your Mongo workload.

This package implements Telescope's `EntriesRepository`, `ClearableRepository`, `PrunableRepository`, and `TerminableRepository` contracts directly against MongoDB. Tags live on the entry document as an array, the `_id` ObjectId acts as the monotonic sequence, and indexes are managed for you.

## Requirements

| Component                       | Version           |
| ------------------------------- | ----------------- |
| PHP                             | 8.3+              |
| Laravel                         | 11.x or 12.x      |
| Telescope                       | 5.x               |
| MongoDB server                  | 6.0+              |
| `mongodb/laravel-mongodb`       | 5.x               |
| `ext-mongodb`                   | 1.18+ or 2.x      |

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
