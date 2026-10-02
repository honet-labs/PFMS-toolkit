<?php

declare(strict_types=1);

return [
    'internal' => [
        'driver' => 'mysql',
        'host' => env('INTERNAL_DB_HOST', '127.0.0.1'),
        'port' => env_int('INTERNAL_DB_PORT', 3306),
        'database' => env('INTERNAL_DB_DATABASE', 'snmp_bridge'),
        'username' => env('INTERNAL_DB_USERNAME', 'snmp_bridge'),
        'password' => env('INTERNAL_DB_PASSWORD', ''),
        'charset' => env('INTERNAL_DB_CHARSET', 'utf8mb4'),
        'persistent' => env_bool('INTERNAL_DB_PERSISTENT', false),
        'slow_query_threshold_ms' => env_int('DB_LOG_SLOW_QUERIES_MS', 0),
    ],
    'pandora' => [
        'driver' => 'mysql',
        'host' => env('PANDORA_DB_HOST', '127.0.0.1'),
        'port' => env_int('PANDORA_DB_PORT', 3306),
        'database' => env('PANDORA_DB_DATABASE', 'pandora'),
        'username' => env('PANDORA_DB_USERNAME', 'pandora'),
        'password' => env('PANDORA_DB_PASSWORD', ''),
        'charset' => env('PANDORA_DB_CHARSET', 'utf8mb4'),
        'persistent' => env_bool('PANDORA_DB_PERSISTENT', false),
        'slow_query_threshold_ms' => env_int('DB_LOG_SLOW_QUERIES_MS', 0),
    ],
];
