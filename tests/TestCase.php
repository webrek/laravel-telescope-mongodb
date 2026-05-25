<?php

namespace TelescopeMongoDB\Driver\Tests;

use Laravel\Telescope\TelescopeServiceProvider;
use MongoDB\Laravel\MongoDBServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use TelescopeMongoDB\Driver\TelescopeMongoDbServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            MongoDBServiceProvider::class,
            TelescopeServiceProvider::class,
            TelescopeMongoDbServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $dsn = getenv('TELESCOPE_MONGODB_TEST_DSN') ?: null;
        $database = getenv('TELESCOPE_MONGODB_TEST_DATABASE') ?: 'telescope_mongodb_tests';

        $app['config']->set('database.default', 'mongodb');
        $app['config']->set('database.connections.mongodb', [
            'driver' => 'mongodb',
            'dsn' => $dsn ?? 'mongodb://127.0.0.1:27017',
            'database' => $database,
        ]);

        $app['config']->set('telescope.enabled', true);
        $app['config']->set('telescope.storage.database.connection', 'mongodb');

        $app['config']->set('telescope-mongodb.connection', 'mongodb');
        $app['config']->set('telescope-mongodb.collections.entries', 'telescope_entries');
        $app['config']->set('telescope-mongodb.collections.monitoring', 'telescope_monitoring');
    }

    protected function skipUnlessMongoAvailable(): void
    {
        if (! getenv('TELESCOPE_MONGODB_TEST_DSN')) {
            $this->markTestSkipped('Set TELESCOPE_MONGODB_TEST_DSN to run MongoDB-backed tests.');
        }
    }
}
