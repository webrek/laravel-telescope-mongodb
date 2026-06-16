<?php

namespace TelescopeMongoDB\Driver\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MongoDB\Collection;
use MongoDB\Database;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'telescope-mongodb:doctor';

    protected $description = 'Diagnose the MongoDB driver: connection, indexes, server version, entry counts.';

    /**
     * @var array<string, array<string, mixed>>
     */
    protected const EXPECTED_ENTRY_INDEXES = [
        'uuid_unique' => ['uuid' => 1],
        'batch_id' => ['batch_id' => 1],
        'family_hash' => ['family_hash' => 1],
        'type_recent' => ['type' => 1, '_id' => -1],
        'tags' => ['tags' => 1],
        'display_type_recent' => ['should_display_on_index' => 1, 'type' => 1, '_id' => -1],
    ];

    public function handle(): int
    {
        $connectionName = (string) config('telescope-mongodb.connection');
        $entriesName = (string) config('telescope-mongodb.collections.entries');
        $monitoringName = (string) config('telescope-mongodb.collections.monitoring');

        $this->components->info("Diagnosing the MongoDB driver on connection [{$connectionName}].");

        $database = $this->checkConnection($connectionName);

        if ($database === null) {
            return self::FAILURE;
        }

        $serverInfo = $this->checkServer($database);
        $this->checkIndexes($database, $entriesName, $monitoringName);
        $this->checkCounts($database, $entriesName, $monitoringName);
        $this->checkTtl($database, $entriesName);

        $this->newLine();
        $this->components->info(sprintf('Doctor finished. Server version: %s.', $serverInfo['version'] ?? 'unknown'));

        return self::SUCCESS;
    }

    protected function checkConnection(string $connectionName): ?Database
    {
        try {
            $connection = DB::connection($connectionName);
        } catch (Throwable $e) {
            $this->components->error("Cannot resolve connection [{$connectionName}]: {$e->getMessage()}");

            return null;
        }

        if (! method_exists($connection, 'getDatabase') && ! method_exists($connection, 'getMongoDB')) {
            $this->components->error("Connection [{$connectionName}] is not a MongoDB connection.");

            return null;
        }

        $database = method_exists($connection, 'getDatabase')
            ? $connection->getDatabase()
            : $connection->getMongoDB();

        try {
            $database->command(['ping' => 1]);
        } catch (Throwable $e) {
            $this->components->error("Ping failed: {$e->getMessage()}");

            return null;
        }

        $this->components->task('Connection reachable', fn () => true);

        return $database;
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkServer(Database $database): array
    {
        try {
            $cursor = $database->command(['buildInfo' => 1]);
            $info = (array) $cursor->toArray()[0];
        } catch (Throwable $e) {
            $this->components->warn("Could not read server build info: {$e->getMessage()}");

            return [];
        }

        $version = $info['version'] ?? 'unknown';

        $this->components->task("Server version {$version}", fn () => true);

        if (is_string($version) && version_compare($version, '6.0.0', '<')) {
            $this->components->warn('MongoDB server is older than 6.0; this driver targets 6.0+. Behaviour is undefined on older versions.');
        }

        return $info;
    }

    protected function checkIndexes(Database $database, string $entriesName, string $monitoringName): void
    {
        $entries = $database->selectCollection($entriesName);
        $monitoring = $database->selectCollection($monitoringName);

        $entryIndexes = $this->collectIndexNames($entries);
        $monitoringIndexes = $this->collectIndexNames($monitoring);

        $missing = array_diff(array_keys(self::EXPECTED_ENTRY_INDEXES), $entryIndexes);

        if ($missing === []) {
            $this->components->task("All required indexes present on {$entriesName}", fn () => true);
        } else {
            $this->components->warn(sprintf(
                'Missing indexes on %s: %s. Run `php artisan telescope-mongodb:sync-indexes`.',
                $entriesName,
                implode(', ', $missing),
            ));
        }

        if (in_array('tag_unique', $monitoringIndexes, true)) {
            $this->components->task("Required index present on {$monitoringName}", fn () => true);
        } else {
            $this->components->warn("Missing index `tag_unique` on {$monitoringName}. Run sync-indexes.");
        }
    }

    protected function checkCounts(Database $database, string $entriesName, string $monitoringName): void
    {
        $entries = $database->selectCollection($entriesName);
        $monitoring = $database->selectCollection($monitoringName);

        $entryCount = $entries->estimatedDocumentCount();
        $monitoringCount = $monitoring->estimatedDocumentCount();

        $this->components->task(sprintf('%d entries, %d monitored tags', $entryCount, $monitoringCount), fn () => true);
    }

    protected function checkTtl(Database $database, string $entriesName): void
    {
        $ttl = config('telescope-mongodb.indexes.ttl_seconds');

        if (! is_numeric($ttl) || (int) $ttl <= 0) {
            $this->components->warn('TTL pruning is disabled (indexes.ttl_seconds is null). Entries are kept until removed manually. Set TELESCOPE_MONGODB_TTL_SECONDS to let MongoDB purge them automatically.');

            return;
        }

        $ttl = (int) $ttl;

        $entries = $database->selectCollection($entriesName);
        $found = false;

        foreach ($entries->listIndexes() as $index) {
            $info = $index->__debugInfo();

            if (($info['key'] ?? null) === ['created_at' => 1] && ($info['expireAfterSeconds'] ?? null) === $ttl) {
                $found = true;
                break;
            }
        }

        if ($found) {
            $this->components->task(sprintf('TTL index active (%d seconds)', $ttl), fn () => true);
        } else {
            $this->components->warn(sprintf('TTL configured to %d seconds but no matching TTL index found. Run sync-indexes.', $ttl));
        }
    }

    /**
     * @return array<int, string>
     */
    protected function collectIndexNames(Collection $collection): array
    {
        $names = [];

        foreach ($collection->listIndexes() as $index) {
            $info = $index->__debugInfo();
            $names[] = (string) ($info['name'] ?? '');
        }

        return array_values(array_filter($names));
    }
}
