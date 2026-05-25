<?php

return [

    'connection' => env('TELESCOPE_MONGODB_CONNECTION', 'mongodb'),

    'collections' => [
        'entries' => env('TELESCOPE_MONGODB_ENTRIES_COLLECTION', 'telescope_entries'),
        'monitoring' => env('TELESCOPE_MONGODB_MONITORING_COLLECTION', 'telescope_monitoring'),
    ],

    'chunk' => [
        'store' => (int) env('TELESCOPE_MONGODB_STORE_CHUNK', 1000),
        'prune' => (int) env('TELESCOPE_MONGODB_PRUNE_CHUNK', 5000),
    ],

    'indexes' => [
        'auto_create' => (bool) env('TELESCOPE_MONGODB_AUTO_INDEXES', true),

        /*
         * Seconds before an entry document is automatically removed by
         * MongoDB. When set to a positive integer the sync-indexes
         * command will create (or maintain) a TTL index on `created_at`
         * so the server can purge expired entries without relying on
         * `php artisan telescope:prune`. Set to null to disable.
         */
        'ttl_seconds' => env('TELESCOPE_MONGODB_TTL_SECONDS') !== null
            ? (int) env('TELESCOPE_MONGODB_TTL_SECONDS')
            : null,
    ],

    /*
     * Write concern applied to all insert/update/delete operations. The
     * `w` value mirrors MongoDB's write acknowledgement settings:
     *   - 'majority' or an integer N: wait for that many members to ack
     *   - 0:                          fire-and-forget, lowest latency
     * `journal` controls whether the primary must commit to the journal
     * before acknowledging. Default is sensible for most workloads.
     */
    'write_concern' => [
        'w' => env('TELESCOPE_MONGODB_WRITE_W', 1),
        'journal' => (bool) env('TELESCOPE_MONGODB_WRITE_JOURNAL', false),
        'timeout_ms' => (int) env('TELESCOPE_MONGODB_WRITE_TIMEOUT_MS', 1000),
    ],

];
