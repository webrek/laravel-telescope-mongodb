<?php

namespace TelescopeMongoDB\Driver;

use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\Contracts\ClearableRepository;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Contracts\PrunableRepository;
use Laravel\Telescope\Contracts\TerminableRepository;
use TelescopeMongoDB\Driver\Console\DoctorCommand;
use TelescopeMongoDB\Driver\Console\InstallCommand;
use TelescopeMongoDB\Driver\Console\MigrateFromSqlCommand;
use TelescopeMongoDB\Driver\Console\SyncIndexesCommand;
use TelescopeMongoDB\Driver\Storage\MongoDbEntriesRepository;

class TelescopeMongoDbServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/telescope-mongodb.php', 'telescope-mongodb');

        $this->app->singleton(MongoDbEntriesRepository::class, function ($app) {
            return new MongoDbEntriesRepository(
                (string) $app['config']->get('telescope-mongodb.connection'),
                (string) $app['config']->get('telescope-mongodb.collections.entries'),
                (string) $app['config']->get('telescope-mongodb.collections.monitoring'),
            );
        });
    }

    public function boot(): void
    {
        $this->bindRepository();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/telescope-mongodb.php' => config_path('telescope-mongodb.php'),
            ], 'telescope-mongodb-config');

            $this->commands([
                InstallCommand::class,
                SyncIndexesCommand::class,
                DoctorCommand::class,
                MigrateFromSqlCommand::class,
            ]);
        }
    }

    protected function bindRepository(): void
    {
        $this->app->singleton(EntriesRepository::class, MongoDbEntriesRepository::class);
        $this->app->singleton(ClearableRepository::class, MongoDbEntriesRepository::class);
        $this->app->singleton(PrunableRepository::class, MongoDbEntriesRepository::class);
        $this->app->singleton(TerminableRepository::class, MongoDbEntriesRepository::class);
    }
}
