<?php

declare(strict_types=1);

$localMibDir = defined('SNMP_BRIDGE_ROOT')
    ? SNMP_BRIDGE_ROOT . '/resources/mibs'
    : dirname(__DIR__) . '/resources/mibs';

return [
    'version' => env('SNMP_VERSION', '2c'),
    'community' => env('SNMP_COMMUNITY', 'public'),
    'port' => env_int('SNMP_PORT', 161),
    'timeout_usec' => env_int('SNMP_TIMEOUT_USEC', 1000000),
    'retries' => env_int('SNMP_RETRIES', 1),
    'max_oids' => env_int('SNMP_MAX_OIDS', 25),
    'scan_timeout_sec' => env_int('SNMP_SCAN_TIMEOUT_SEC', 45),
    'scan_hard_timeout' => env_bool('SNMP_SCAN_HARD_TIMEOUT', true),
    'scan_max_sensors' => env_int('SNMP_SCAN_MAX_SENSORS', 10000),
    'scan_result_preview_limit' => env_int('SNMP_SCAN_RESULT_PREVIEW_LIMIT', 500),
    'scan_concurrency' => env_int('SNMP_SCAN_CONCURRENCY', 5),
    'identity_cache_ttl' => env_int('SNMP_IDENTITY_CACHE_TTL', 120),
    'adaptive_timeouts' => env_bool('SNMP_ADAPTIVE_TIMEOUTS', true),
    'quick_print' => env_bool('SNMP_QUICK_PRINT', true),
    'value_parsing' => env('SNMP_VALUE_PARSING', 'plain'),
    'translate_oids' => env_bool('SNMP_TRANSLATE_OIDS', true),
    'debug' => env_bool('SNMP_DEBUG', false),
    'translate_max_sensors' => env_int('DISCOVERY_TRANSLATE_MAX_SENSORS', 1500),
    'snmptranslate_binary' => (string) env('SNMPTRANSLATE_BINARY', '/usr/bin/snmptranslate'),
    'snmptranslate_timeout_sec' => (float) env('SNMPTRANSLATE_TIMEOUT_SEC', 2),
    'mibs' => (string) env('SNMP_MIBS', '+ALL'),
    'mibdirs' => env_list('SNMP_MIBDIRS', [
        '/usr/share/snmp/mibs',
        $localMibDir,
    ]),
];
