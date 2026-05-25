<?php

namespace TelescopeMongoDB\Driver\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncIndexesCommand extends Command
{
    protected $signature = 'telescope-mongodb:sync-indexes
                            {--drop : Drop existing indexes before creating them}';

    protected $description = 'Create or refresh the MongoDB indexes used by the Telescope driver.';

    public function handle(): int
    {
        $connectionName = config('telescope-mongodb.connection');
        $entriesName = config('telescope-mongodb.collections.entries');
        $monitoringName = config('telescope-mongodb.collections.monitoring');

        $connection = DB::connection($connectionName);

        if (! method_exists($connection, 'getDatabase') && ! method_exists($connection, 'getMongoDB')) {
            $this->components->error("Connection [{$connectionName}] is not a MongoDB connection.");

            return self::FAILURE;
        }

        $database = method_exists($connection, 'getDatabase')
            ? $connection->getDatabase()
            : $connection->getMongoDB();

        $entries = $database->selectCollection($entriesName);
        $monitoring = $database->selectCollection($monitoringName);

        if ($this->option('drop')) {
            $this->components->task("Dropping indexes on {$entriesName}", fn () => $entries->dropIndexes() && true);
            $this->components->task("Dropping indexes on {$monitoringName}", fn () => $monitoring->dropIndexes() && true);
        }

        $this->components->task("Creating indexes on {$entriesName}", function () use ($entries) {
            $entries->createIndexes([
                ['key' => ['uuid' => 1], 'unique' => true, 'name' => 'uuid_unique'],
                ['key' => ['batch_id' => 1], 'name' => 'batch_id'],
                ['key' => ['family_hash' => 1], 'name' => 'family_hash'],
                ['key' => ['type' => 1, '_id' => -1], 'name' => 'type_recent'],
                ['key' => ['tags' => 1], 'name' => 'tags'],
                ['key' => ['should_display_on_index' => 1, 'type' => 1, '_id' => -1], 'name' => 'display_type_recent'],
                ['key' => ['created_at' => 1], 'name' => 'created_at'],
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

        return self::SUCCESS;
    }
}
