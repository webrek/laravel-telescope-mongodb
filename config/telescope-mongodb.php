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
    ],

];
