<?php

namespace TelescopeMongoDB\Driver\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection as MongoCollection;
use MongoDB\Database;

class MigrateFromSqlCommand extends Command
{
    protected $signature = 'telescope-mongodb:migrate-from-sql
                            {--from= : The SQL connection name that currently stores Telescope entries (defaults to the app default)}
                            {--chunk=500 : Number of entries to read per batch}
                            {--truncate : Empty the destination Mongo collections before importing}
                            {--dry-run : Count and report without writing to MongoDB}';

    protected $description = 'Import an existing relational Telescope dataset into MongoDB.';

    public function handle(): int
    {
        $sourceConnection = (string) ($this->option('from') ?? config('database.default'));
        $destinationConnection = (string) config('telescope-mongodb.connection');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $truncate = (bool) $this->option('truncate');
        $dryRun = (bool) $this->option('dry-run');

        $this->components->info(sprintf(
            'Migrating Telescope entries from SQL connection [%s] into MongoDB connection [%s].',
            $sourceConnection,
            $destinationConnection,
        ));

        $database = $this->resolveDatabase($destinationConnection);

        if ($database === null) {
            $this->components->error("Connection [{$destinationConnection}] is not a MongoDB connection.");

            return self::FAILURE;
        }

        $entries = $database->selectCollection((string) config('telescope-mongodb.collections.entries'));
        $monitoring = $database->selectCollection((string) config('telescope-mongodb.collections.monitoring'));

        if ($truncate && ! $dryRun) {
            $this->components->task('Truncating destination collections', function () use ($entries, $monitoring) {
                $entries->deleteMany([]);
                $monitoring->deleteMany([]);

                return true;
            });
        }

        $totalEntries = $this->migrateEntries($sourceConnection, $entries, $chunkSize, $dryRun);
        $totalMonitoring = $this->migrateMonitoring($sourceConnection, $monitoring, $dryRun);

        $this->newLine();

        if ($dryRun) {
            $this->components->info(sprintf(
                'Dry run: would import %d entries and %d monitoring tags.',
                $totalEntries,
                $totalMonitoring,
            ));
        } else {
            $this->components->info(sprintf(
                'Imported %d entries and %d monitoring tags.',
                $totalEntries,
                $totalMonitoring,
            ));
        }

        return self::SUCCESS;
    }

    protected function migrateEntries(string $sourceConnection, MongoCollection $entries, int $chunkSize, bool $dryRun): int
    {
        $source = DB::connection($sourceConnection);

        if (! $source->getSchemaBuilder()->hasTable('telescope_entries')) {
            $this->components->warn('Source connection has no `telescope_entries` table. Nothing to do.');

            return 0;
        }

        $total = (int) $source->table('telescope_entries')->count();

        if ($total === 0) {
            $this->components->info('No entries to migrate.');

            return 0;
        }

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $imported = 0;

        $source->table('telescope_entries')
            ->orderBy('sequence')
            ->chunk($chunkSize, function ($rows) use ($source, $entries, $dryRun, $progressBar, &$imported) {
                $uuids = collect($rows)->pluck('uuid')->all();

                $tagsByUuid = $source->table('telescope_entries_tags')
                    ->whereIn('entry_uuid', $uuids)
                    ->get()
                    ->groupBy('entry_uuid')
                    ->map(fn ($group) => $group->pluck('tag')->values()->all());

                $documents = [];

                foreach ($rows as $row) {
                    $documents[] = [
                        '_id' => new ObjectId,
                        'uuid' => (string) $row->uuid,
                        'batch_id' => $row->batch_id ?? null,
                        'family_hash' => $row->family_hash ?? null,
                        'should_display_on_index' => (bool) ($row->should_display_on_index ?? true),
                        'type' => (string) $row->type,
                        'content' => $this->decodeContent($row->content ?? null),
                        'tags' => array_values($tagsByUuid->get($row->uuid, [])),
                        'created_at' => $this->toUtcDateTime($row->created_at ?? null),
                    ];
                }

                if (! $dryRun && $documents !== []) {
                    $entries->insertMany($documents);
                }

                $imported += count($documents);
                $progressBar->advance(count($documents));
            });

        $progressBar->finish();
        $this->newLine();

        return $imported;
    }

    protected function migrateMonitoring(string $sourceConnection, MongoCollection $monitoring, bool $dryRun): int
    {
        $source = DB::connection($sourceConnection);

        if (! $source->getSchemaBuilder()->hasTable('telescope_monitoring')) {
            return 0;
        }

        $tags = $source->table('telescope_monitoring')->pluck('tag')->filter()->unique()->values()->all();

        if ($tags === []) {
            return 0;
        }

        if (! $dryRun) {
            $monitoring->insertMany(array_map(fn (string $tag): array => ['tag' => $tag], $tags));
        }

        return count($tags);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeContent(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function toUtcDateTime(mixed $raw): UTCDateTime
    {
        if ($raw instanceof UTCDateTime) {
            return $raw;
        }

        $carbon = $raw === null ? Carbon::now() : Carbon::parse($raw);

        return new UTCDateTime((int) ($carbon->getTimestamp() * 1000));
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
