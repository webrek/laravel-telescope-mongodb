#!/usr/bin/env bash

# Bootstrap a real Laravel app at ./playground that uses this package
# via a Composer path repository, configures MongoDB, runs the installer
# and registers a handful of demo routes. Designed to be run from inside
# the `php` container (working directory: /package).

set -euo pipefail

PLAYGROUND_DIR="playground"

if [[ -d "$PLAYGROUND_DIR" ]]; then
    echo "==> $PLAYGROUND_DIR already exists. Remove it first if you want a clean bootstrap."
    exit 1
fi

echo "==> Creating fresh Laravel app at $PLAYGROUND_DIR"
composer create-project laravel/laravel "$PLAYGROUND_DIR" --prefer-dist --no-interaction

cd "$PLAYGROUND_DIR"

echo "==> Wiring the local Composer path repository"
composer config repositories.telescope-mongodb path '../'
composer require 'webrek/laravel-telescope-mongodb:@dev'

echo "==> Patching .env for MongoDB and file-based sessions/cache"
{
    echo ""
    echo "# Telescope MongoDB driver"
    echo "MONGODB_URI=mongodb://mongo:27017"
    echo "MONGODB_DATABASE=telescope_playground"
    echo "TELESCOPE_MONGODB_CONNECTION=mongodb"
    echo "TELESCOPE_ENABLED=true"
} >> .env

sed -i 's/^SESSION_DRIVER=database$/SESSION_DRIVER=file/' .env
sed -i 's/^CACHE_STORE=database$/CACHE_STORE=file/' .env
sed -i 's/^QUEUE_CONNECTION=database$/QUEUE_CONNECTION=sync/' .env

echo "==> Adding the mongodb connection to config/database.php"
cat > /tmp/patch-database.php <<'PHP'
<?php
$path = 'config/database.php';
$contents = file_get_contents($path);
$snippet = <<<'BLOCK'
    'connections' => [

        'mongodb' => [
            'driver' => 'mongodb',
            'dsn' => env('MONGODB_URI', 'mongodb://127.0.0.1:27017'),
            'database' => env('MONGODB_DATABASE', 'laravel'),
        ],

BLOCK;
$new = preg_replace("/    'connections' => \[\n/", $snippet, $contents, 1, $count);
if ($count !== 1) {
    fwrite(STDERR, "Could not patch config/database.php — expected pattern not found.\n");
    exit(1);
}
file_put_contents($path, $new);
PHP
php /tmp/patch-database.php
rm /tmp/patch-database.php

echo "==> Installing Telescope assets and creating MongoDB indexes"
php artisan telescope-mongodb:install --force

echo "==> Replacing routes/web.php with demo routes"
cat > routes/web.php <<'PHP'
<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));
Route::get('/ping', fn () => ['pong' => true]);

Route::get('/log-something', function () {
    Log::info('hello from playground', ['ts' => now()->toIso8601String()]);

    return ['logged' => true];
});

Route::get('/mongo-query', function () {
    $widgets = DB::connection('mongodb')->table('playground_widgets');

    $widgets->insert(['name' => 'widget-' . random_int(1, 999), 'created_at' => now()]);

    $rows = $widgets->where('name', 'like', 'widget-%')->limit(5)->get();

    return ['count' => $rows->count(), 'latest' => $rows->first()];
});

Route::get('/boom', function () {
    throw new \RuntimeException('intentional explosion');
});
PHP

echo ""
echo "==> Playground ready. Start the Laravel container with:"
echo "    docker compose up -d laravel"
echo "    open http://127.0.0.1:8000/telescope"
