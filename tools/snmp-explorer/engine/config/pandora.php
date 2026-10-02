<?php

declare(strict_types=1);

return [
    'module_interval' => env_int('PANDORA_MODULE_INTERVAL', 300),
    'module_timeout' => env_int('PANDORA_MODULE_TIMEOUT', 5),
    'module_retries' => env_int('PANDORA_MODULE_RETRIES', 1),
    'network_server_module_id' => 2,
    'remote_snmp_numeric_type_id' => 15,
    'default_module_group_id' => 0,
    
    /*
     * Pandora SNMP Wizard Compatibility Mode
     * 
     * When true: Modules are 100% identical to Pandora wizard modules
     *            (custom_id, extended_info, extra_data fields are omitted)
     * When false: Modules include tracking metadata
     *             (custom_id, extended_info, extra_data fields are populated)
     * 
     * Recommended: true (for seamless Pandora integration)
     */
    'wizard_mode' => env_bool('SNMP_BRIDGE_WIZARD_MODE', true),
];
