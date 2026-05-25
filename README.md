# Laravel Telescope MongoDB Driver

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
composer require telescope-mongodb/driver
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

If you prefer to run things on your host, point the suite at any reachable MongoDB instance:

```bash
export TELESCOPE_MONGODB_TEST_DSN="mongodb://127.0.0.1:27017"
export TELESCOPE_MONGODB_TEST_DATABASE="telescope_mongodb_tests"

vendor/bin/phpunit
```

## License

MIT
