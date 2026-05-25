#!/usr/bin/env bash

# Bootstrap a second Laravel app at ./playground-mysql that uses the
# STOCK Laravel Telescope storage driver (MySQL via the relational
# Eloquent backend). Intended to be a control group when benchmarking
# the MongoDB driver against the default driver. Designed to be run
# from inside the `php` container (working directory: /package).

set -euo pipefail

PLAYGROUND_DIR="playground-mysql"

if [[ -d "$PLAYGROUND_DIR" ]]; then
    echo "==> $PLAYGROUND_DIR already exists. Remove it first if you want a clean bootstrap."
    exit 1
fi

echo "==> Creating fresh Laravel app at $PLAYGROUND_DIR"
composer create-project laravel/laravel "$PLAYGROUND_DIR" --prefer-dist --no-interaction

cd "$PLAYGROUND_DIR"

echo "==> Installing stock Laravel Telescope"
composer require laravel/telescope --no-interaction

echo "==> Patching .env for MySQL and file-based sessions/cache"
sed -i 's/^DB_CONNECTION=sqlite$/DB_CONNECTION=mysql/' .env
sed -i 's/^# DB_HOST=127.0.0.1$/DB_HOST=mysql/' .env
sed -i 's/^# DB_PORT=3306$/DB_PORT=3306/' .env
sed -i 's/^# DB_DATABASE=laravel$/DB_DATABASE=telescope_bench/' .env
sed -i 's/^# DB_USERNAME=root$/DB_USERNAME=telescope/' .env
sed -i 's/^# DB_PASSWORD=$/DB_PASSWORD=secret/' .env
sed -i 's/^SESSION_DRIVER=database$/SESSION_DRIVER=file/' .env
sed -i 's/^CACHE_STORE=database$/CACHE_STORE=file/' .env
sed -i 's/^QUEUE_CONNECTION=database$/QUEUE_CONNECTION=sync/' .env

# Remove the SQLite database file we no longer use
rm -f database/database.sqlite

echo "==> Running telescope:install (publishes config, migrations, provider)"
php artisan telescope:install

echo "==> Running migrations (creates telescope_entries / telescope_entries_tags / telescope_monitoring)"
php artisan migrate --force

echo "==> Replacing routes/web.php with the same demo routes used by the Mongo playground"
cat > routes/web.php <<'PHP'
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
Route::get('/ping', fn () => ['pong' => true]);

Route::get('/log-something', function () {
    Log::info('hello from playground-mysql', ['ts' => now()->toIso8601String()]);

    return ['logged' => true];
});

Route::get('/db-query', function () {
    DB::statement('CREATE TABLE IF NOT EXISTS widgets (id BIGINT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255), created_at DATETIME)');
    DB::table('widgets')->insert(['name' => 'widget-' . random_int(1, 999), 'created_at' => now()]);
    $rows = DB::table('widgets')->latest('id')->limit(5)->get();

    return ['count' => $rows->count(), 'latest' => $rows->first()];
});

Route::get('/boom', function () {
    throw new \RuntimeException('intentional explosion');
});
PHP

echo ""
echo "==> MySQL playground ready. Start it with:"
echo "    docker compose --profile bench up -d laravel-mysql"
echo "    curl http://127.0.0.1:8001/ping"
