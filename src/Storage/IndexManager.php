<?php

namespace TelescopeMongoDB\Driver\Storage;

use MongoDB\Collection;
use MongoDB\Database;

/**
 * Single source of truth for the indexes the driver relies on.
 *
 * Both the sync-indexes command and the lazy auto-create path in
 * MongoDbEntriesRepository build their index definitions from here so the two
 * can never drift apart.
 */
class IndexManager
{
    /**
     * Create or refresh every index, idempotently.
     *
     * createIndexes is a no-op when an identical index already exists; the
     * created_at index is reconciled first so a TTL change (including enabling
     * or disabling it) does not collide with the existing definition.
     */
    public static function ensure(Database $database, string $entriesName, string $monitoringName, ?int $ttlSeconds): void
    {
        $entries = $database->selectCollection($entriesName);

        self::reconcileCreatedAtIndex($entries, $ttlSeconds);

        $entries->createIndexes(self::entryIndexSpecs($ttlSeconds));
        $database->selectCollection($monitoringName)->createIndexes(self::monitoringIndexSpecs());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function entryIndexSpecs(?int $ttlSeconds): array
    {
        $createdAt = ['key' => ['created_at' => 1], 'name' => 'created_at'];

        if (self::ttlEnabled($ttlSeconds)) {
            $createdAt['name'] = 'created_at_ttl';
            $createdAt['expireAfterSeconds'] = $ttlSeconds;
        }

        return [
            ['key' => ['uuid' => 1], 'unique' => true, 'name' => 'uuid_unique'],
            ['key' => ['batch_id' => 1], 'name' => 'batch_id'],
            ['key' => ['family_hash' => 1], 'name' => 'family_hash'],
            ['key' => ['type' => 1, '_id' => -1], 'name' => 'type_recent'],
            ['key' => ['tags' => 1], 'name' => 'tags'],
            ['key' => ['should_display_on_index' => 1, 'type' => 1, '_id' => -1], 'name' => 'display_type_recent'],
            $createdAt,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function monitoringIndexSpecs(): array
    {
        return [
            ['key' => ['tag' => 1], 'unique' => true, 'name' => 'tag_unique'],
        ];
    }

    /**
     * Drop the existing created_at index when its TTL no longer matches the
     * desired configuration.
     *
     * MongoDB rejects createIndexes when an index with the same key pattern but
     * a different expireAfterSeconds (or name) already exists, so the stale
     * variant is removed first. Handles enabling, changing and disabling TTL.
     */
    public static function reconcileCreatedAtIndex(Collection $entries, ?int $ttlSeconds): void
    {
        $desiredTtl = self::ttlEnabled($ttlSeconds) ? $ttlSeconds : null;

        foreach ($entries->listIndexes() as $index) {
            $info = $index->__debugInfo();

            if (($info['key'] ?? null) !== ['created_at' => 1]) {
                continue;
            }

            if (($info['expireAfterSeconds'] ?? null) === $desiredTtl) {
                continue;
            }

            $entries->dropIndex((string) $info['name']);
        }
    }

    public static function ttlEnabled(?int $ttlSeconds): bool
    {
        return is_int($ttlSeconds) && $ttlSeconds > 0;
    }
}
