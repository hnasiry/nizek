<?php

declare(strict_types=1);

return [
    'import' => [
        'disk' => env('STOCK_IMPORT_DISK', 'local'),
        'queue' => env('STOCK_IMPORT_QUEUE', 'imports'),
        'chunk_size' => (int) env('STOCK_IMPORT_CHUNK_SIZE', 500),
    ],
    'reporting' => [
        'cache_ttl' => (int) env('STOCK_REPORT_CACHE_TTL', 300),
    ],
];
