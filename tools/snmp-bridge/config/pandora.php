<?php

declare(strict_types=1);

return [
    'module_interval' => env_int('PANDORA_MODULE_INTERVAL', 300),
    'module_timeout' => env_int('PANDORA_MODULE_TIMEOUT', 5),
    'module_retries' => env_int('PANDORA_MODULE_RETRIES', 1),
    'network_server_module_id' => 2,
    'remote_snmp_numeric_type_id' => 15,
    'default_module_group_id' => 0,
];
