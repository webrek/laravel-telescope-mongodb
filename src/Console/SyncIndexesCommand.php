<?php

namespace TelescopeMongoDB\Driver\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MongoDB\Collection;
use MongoDB\Database;

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

        $createdAtIndex = ['key' => ['created_at' => 1], 'name' => 'created_at'];

        if (is_int($ttlSeconds) && $ttlSeconds > 0) {
            $this->ensureTtlIndexParity($entries, $ttlSeconds);

            $createdAtIndex['expireAfterSeconds'] = $ttlSeconds;
            $createdAtIndex['name'] = 'created_at_ttl';
        }

        $this->components->task("Creating indexes on {$entriesName}", function () use ($entries, $createdAtIndex) {
            $entries->createIndexes([
                ['key' => ['uuid' => 1], 'unique' => true, 'name' => 'uuid_unique'],
                ['key' => ['batch_id' => 1], 'name' => 'batch_id'],
                ['key' => ['family_hash' => 1], 'name' => 'family_hash'],
                ['key' => ['type' => 1, '_id' => -1], 'name' => 'type_recent'],
                ['key' => ['tags' => 1], 'name' => 'tags'],
                ['key' => ['should_display_on_index' => 1, 'type' => 1, '_id' => -1], 'name' => 'display_type_recent'],
                $createdAtIndex,
            ]);

            return true;
        });

        $this->components->task("Creating indexes on {$monitoringName}", function () use ($monitoring) {
            $monitoring->createIndexes([
                ['key' => ['tag' => 1], 'unique' => true, 'name' => 'tag_unique'],
            ]);

            return true;
        });

        $this->newLine();
        $this->components->info('MongoDB indexes are in sync.');

        if (is_int($ttlSeconds) && $ttlSeconds > 0) {
            $this->line(sprintf('  TTL is active — entries older than %d seconds will be removed automatically by MongoDB.', $ttlSeconds));
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

    /**
     * Drop the previous created_at index if its TTL configuration differs.
     *
     * MongoDB does not allow modifying expireAfterSeconds via createIndexes
     * if an index with the same keys but a different TTL already exists;
     * we drop the stale variant so the new one can be created cleanly.
     */
    protected function ensureTtlIndexParity(Collection $entries, int $ttlSeconds): void
    {
        foreach ($entries->listIndexes() as $index) {
            $info = $index->__debugInfo();

            if (($info['key'] ?? null) !== ['created_at' => 1]) {
                continue;
            }

            $currentTtl = $info['expireAfterSeconds'] ?? null;

            if ($currentTtl === $ttlSeconds) {
                continue;
            }

            $entries->dropIndex($info['name']);
        }
    }
}
