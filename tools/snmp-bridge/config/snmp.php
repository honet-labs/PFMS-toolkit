<?php

declare(strict_types=1);

return [
    'version' => env('SNMP_VERSION', '2c'),
    'community' => env('SNMP_COMMUNITY', 'public'),
    'port' => env_int('SNMP_PORT', 161),
    'timeout_usec' => env_int('SNMP_TIMEOUT_USEC', 1000000),
    'retries' => env_int('SNMP_RETRIES', 1),
    'max_oids' => env_int('SNMP_MAX_OIDS', 10),
    'quick_print' => env_bool('SNMP_QUICK_PRINT', true),
    'value_parsing' => env('SNMP_VALUE_PARSING', 'plain'),
];
