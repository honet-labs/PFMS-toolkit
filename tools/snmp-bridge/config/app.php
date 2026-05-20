<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'SNMP Bridge Provisioning System'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env_bool('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://10.10.5.56/snmp-bridge/public'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'root_path' => dirname(__DIR__),
    'storage_path' => dirname(__DIR__) . '/storage',
    'log_path' => dirname(__DIR__) . '/' . env('LOG_PATH', 'storage/logs/app.log'),
    'log_level' => env('LOG_LEVEL', 'info'),
];
