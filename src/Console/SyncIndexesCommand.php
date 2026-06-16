<?php

namespace TelescopeMongoDB\Driver\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MongoDB\Database;
use TelescopeMongoDB\Driver\Storage\IndexManager;

class SyncIndexesCommand extends Command
{
    protected $signature = 'telescope-mongodb:sync-indexes
                            {--drop : Drop existing indexes before creating them}';

    protected $description = 'Create or refresh the MongoDB indexes used by the Telescope driver.';

    public function handle(): int
    {
        $connectionName = (string) config('telescope-mongodb.connection');
        $entriesName = (string) config('telescope-mongodb.collections.entries');
        $monitoringName = (string) config('telescope-mongodb.collections.monitoring');
        $ttlSeconds = config('telescope-mongodb.indexes.ttl_seconds');

        $database = $this->resolveDatabase($connectionName);

        if ($database === null) {
            $this->components->error("Connection [{$connectionName}] is not a MongoDB connection.");

            return self::FAILURE;
        }

        $entries = $database->selectCollection($entriesName);
        $monitoring = $database->selectCollection($monitoringName);

        if ($this->option('drop')) {
            $this->components->task("Dropping indexes on {$entriesName}", function () use ($entries) {
                $entries->dropIndexes();

                return true;
            });
            $this->components->task("Dropping indexes on {$monitoringName}", function () use ($monitoring) {
                $monitoring->dropIndexes();

                return true;
            });
        }

        $ttlSeconds = is_numeric($ttlSeconds) ? (int) $ttlSeconds : null;

        $this->components->task("Creating indexes on {$entriesName}", function () use ($entries, $ttlSeconds) {
            IndexManager::reconcileCreatedAtIndex($entries, $ttlSeconds);
            $entries->createIndexes(IndexManager::entryIndexSpecs($ttlSeconds));

            return true;
        });

        $this->components->task("Creating indexes on {$monitoringName}", function () use ($monitoring) {
            $monitoring->createIndexes(IndexManager::monitoringIndexSpecs());

            return true;
        });

        $this->newLine();
        $this->components->info('MongoDB indexes are in sync.');

        if (IndexManager::ttlEnabled($ttlSeconds)) {
            $this->line(sprintf('  TTL is active — entries older than %d seconds will be removed automatically by MongoDB.', $ttlSeconds));
        } else {
            $this->line('  TTL pruning is disabled. Set TELESCOPE_MONGODB_TTL_SECONDS to let MongoDB purge old entries automatically.');
        }

        return self::SUCCESS;
    }

    protected function resolveDatabase(string $connectionName): ?Database
    {
        $connection = DB::connection($connectionName);

        if (method_exists($connection, 'getDatabase')) {
            return $connection->getDatabase();
        }

        if (method_exists($connection, 'getMongoDB')) {
            return $connection->getMongoDB();
        }

        return null;
    }
}
