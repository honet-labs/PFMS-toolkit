<?php
declare(strict_types=1);

/**
 * Bootstrap for SNMP Explorer in PFMS-Toolkit
 * Adapts SNMP Bridge engine directly onto PFMS-Toolkit PDO & Architecture.
 */

if (!defined('SNMP_BRIDGE_ROOT')) {
    define('SNMP_BRIDGE_ROOT', __DIR__);
}

require_once __DIR__ . '/autoload.php';

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return $default;
        }
        return match (strtolower($trimmed)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            default => trim($trimmed, "\"'"),
        };
    }
}

if (!function_exists('env_int')) {
    function env_int(string $key, int $default): int {
        return (int) env($key, $default);
    }
}

if (!function_exists('env_bool')) {
    function env_bool(string $key, bool $default): bool {
        $value = env($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('env_list')) {
    function env_list(string $key, array $default = []): array {
        $value = env($key);
        if ($value === null) {
            return array_values(array_unique(array_filter(
                $default,
                static fn (mixed $item): bool => is_string($item) && trim($item) !== '',
            )));
        }
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) $value)),
            static fn (string $item): bool => $item !== '',
        )));
    }
}

/**
 * Initialize and ensure database tables exist on $pdo
 */
function snmp_explorer_init_tables(\PDO $pdo): void {
    static $initialized = false;
    if ($initialized) return;

    $schema = <<<'SQL'
CREATE TABLE IF NOT EXISTS `devices` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address` VARCHAR(100) NOT NULL,
    `hostname` VARCHAR(255) NOT NULL DEFAULT '',
    `vendor` VARCHAR(80) NOT NULL DEFAULT 'Generic',
    `sys_object_id` VARCHAR(255) NOT NULL DEFAULT '',
    `sys_descr` TEXT NULL,
    `snmp_version` VARCHAR(20) NOT NULL DEFAULT '2c',
    `snmp_port` INT UNSIGNED NOT NULL DEFAULT 161,
    `snmp_community` VARCHAR(255) NOT NULL DEFAULT '',
    `snmp_security_level` VARCHAR(32) NULL,
    `snmp_security_name` VARCHAR(128) NULL,
    `snmp_auth_protocol` VARCHAR(32) NULL,
    `snmp_auth_passphrase` VARCHAR(255) NULL,
    `snmp_priv_protocol` VARCHAR(32) NULL,
    `snmp_priv_passphrase` VARCHAR(255) NULL,
    `snmp_context_name` VARCHAR(128) NULL,
    `last_scanned_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `devices_ip_unique` (`ip_address`),
    KEY `devices_vendor_idx` (`vendor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sensor_inventory` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id` BIGINT UNSIGNED NOT NULL,
    `vendor` VARCHAR(80) NOT NULL,
    `ip_address` VARCHAR(100) NOT NULL,
    `sensor_class` VARCHAR(50) NOT NULL,
    `sensor_name` VARCHAR(255) NOT NULL,
    `sensor_type` VARCHAR(80) NULL,
    `interface_index` INT NULL,
    `interface_name` VARCHAR(255) NULL,
    `entity_index` INT NULL,
    `oid` VARCHAR(512) NOT NULL,
    `raw_value` VARCHAR(255) NULL,
    `normalized_value` DOUBLE NULL,
    `unit` VARCHAR(40) NULL,
    `scale` VARCHAR(40) NULL,
    `precision` INT NULL,
    `status` VARCHAR(40) NOT NULL DEFAULT 'unknown',
    `metadata_json` JSON NULL,
    `provisioned` TINYINT(1) NOT NULL DEFAULT 0,
    `pandora_agent_id` INT UNSIGNED NULL,
    `pandora_module_id` INT UNSIGNED NULL,
    `provisioned_at` DATETIME NULL,
    `discovered_at` DATETIME NOT NULL,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `sensor_inventory_unique` (`device_id`, `oid`(384), `sensor_name`(191)),
    KEY `sensor_inventory_vendor_ip_idx` (`vendor`, `ip_address`),
    KEY `sensor_inventory_class_idx` (`sensor_class`),
    KEY `sensor_inventory_updated_idx` (`updated_at`),
    KEY `sensor_inventory_provisioned_idx` (`provisioned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    try {
        $pdo->exec($schema);

        // Safe auto-migration for existing installations
        $v3Cols = [
            'snmp_security_level' => "VARCHAR(32) NULL AFTER `snmp_community`",
            'snmp_security_name' => "VARCHAR(128) NULL AFTER `snmp_security_level`",
            'snmp_auth_protocol' => "VARCHAR(32) NULL AFTER `snmp_security_name`",
            'snmp_auth_passphrase' => "VARCHAR(255) NULL AFTER `snmp_auth_protocol`",
            'snmp_priv_protocol' => "VARCHAR(32) NULL AFTER `snmp_auth_passphrase`",
            'snmp_priv_passphrase' => "VARCHAR(255) NULL AFTER `snmp_priv_protocol`",
            'snmp_context_name' => "VARCHAR(128) NULL AFTER `snmp_priv_passphrase`",
        ];

        $existingCols = [];
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM `devices`");
            $existingCols = $colStmt ? ($colStmt->fetchAll(\PDO::FETCH_COLUMN) ?: []) : [];
        } catch (\Throwable $e) {
            $existingCols = [];
        }

        foreach ($v3Cols as $col => $def) {
            if (!in_array($col, $existingCols, true)) {
                try {
                    $pdo->exec("ALTER TABLE `devices` ADD COLUMN `$col` $def");
                } catch (\Throwable $e) {
                    // Column already exists or concurrent migration
                }
            }
        }

        $initialized = true;
    } catch (\Throwable $e) {
        error_log("SNMP Explorer table init error: " . $e->getMessage());
    }
}

/**
 * Factory to create SNMP Explorer core container with all services
 */
function snmp_explorer_bootstrap(\PDO $pdo): array {
    snmp_explorer_init_tables($pdo);

    $config_dir = __DIR__ . '/config';
    $config = [
        'app' => file_exists("$config_dir/app.php") ? require "$config_dir/app.php" : [],
        'discovery' => file_exists("$config_dir/discovery.php") ? require "$config_dir/discovery.php" : [],
        'snmp' => file_exists("$config_dir/snmp.php") ? require "$config_dir/snmp.php" : [],
        'vendors' => file_exists("$config_dir/vendors.php") ? require "$config_dir/vendors.php" : [],
        'pandora' => file_exists("$config_dir/pandora.php") ? require "$config_dir/pandora.php" : [],
    ];

    // Local JSON settings override if exists
    $local_settings_file = dirname(__DIR__) . '/snmp_explorer_settings.json';
    if (file_exists($local_settings_file)) {
        $local_settings = json_decode((string)@file_get_contents($local_settings_file), true);
        if (is_array($local_settings)) {
            if (isset($local_settings['snmp']) && is_array($local_settings['snmp'])) {
                $config['snmp'] = array_merge($config['snmp'], $local_settings['snmp']);
            }
            if (isset($local_settings['discovery']) && is_array($local_settings['discovery'])) {
                $config['discovery'] = array_merge($config['discovery'], $local_settings['discovery']);
            }
        }
    }

    $vendorRegistry = new \SnmpBridge\Core\Vendor\VendorRegistry([
        new \SnmpBridge\VendorAdapter\Huawei\HuaweiAdapter(),
        new \SnmpBridge\VendorAdapter\Cisco\CiscoAdapter(),
        new \SnmpBridge\VendorAdapter\ZTE\ZTEAdapter(),
        new \SnmpBridge\VendorAdapter\Alcatel\AlcatelAdapter(),
        new \SnmpBridge\VendorAdapter\Raisecom\RaisecomAdapter(),
        new \SnmpBridge\VendorAdapter\Epson\EpsonAdapter(),
        new \SnmpBridge\VendorAdapter\F5\F5VendorAdapter(),
        new \SnmpBridge\VendorAdapter\Dahua\DahuaVendorAdapter(),
    ]);

    $normalizer = new \SnmpBridge\Core\Normalize\SensorNormalizer(
        new \SnmpBridge\Core\Normalize\InvalidValueFilter(),
        new \SnmpBridge\Core\Normalize\ScaleNormalizer(),
        new \SnmpBridge\Core\Normalize\UnitNormalizer(),
    );

    $speedDetector = new \SnmpBridge\Core\Normalize\SpeedDetector();
    $thresholdDetector = new \SnmpBridge\Core\Normalize\OpticalPowerThresholdDetector();
    $localMibDir = __DIR__ . '/mibs';
    $resolvedMibDirs = [$localMibDir];
    $possiblePandoraDirs = [
        realpath(__DIR__ . '/../../../../attachment/mibs'),
        realpath(__DIR__ . '/../../../../../attachment/mibs'),
        '/var/www/html/pandora_console/attachment/mibs',
    ];
    foreach ($possiblePandoraDirs as $pDir) {
        if ($pDir && is_dir($pDir) && !in_array($pDir, $resolvedMibDirs, true)) {
            $resolvedMibDirs[] = $pDir;
            break;
        }
    }
    if (is_dir('/usr/share/snmp/mibs') && !in_array('/usr/share/snmp/mibs', $resolvedMibDirs, true)) {
        $resolvedMibDirs[] = '/usr/share/snmp/mibs';
    }
    if (!empty($config['snmp']['mibdirs']) && is_array($config['snmp']['mibdirs'])) {
        foreach ($config['snmp']['mibdirs'] as $extraDir) {
            if (is_dir($extraDir) && !in_array($extraDir, $resolvedMibDirs, true)) {
                $resolvedMibDirs[] = $extraDir;
            }
        }
    }

    $oidTranslator = new \SnmpBridge\Core\Snmp\OidTranslator(
        (bool) ($config['snmp']['translate_oids'] ?? true),
        (string) ($config['snmp']['snmptranslate_binary'] ?? '/usr/bin/snmptranslate'),
        $resolvedMibDirs,
        (string) ($config['snmp']['mibs'] ?? '+ALL'),
        (float) ($config['snmp']['snmptranslate_timeout_sec'] ?? 2.0),
    );

    $deviceRepository = new \SnmpBridge\Repository\DeviceRepository($pdo);
    $sensorRepository = new \SnmpBridge\Repository\SensorInventoryRepository($pdo);
    $agentRepository = new \SnmpBridge\Repository\AgentRepository($pdo);
    $pandoraRepository = new \SnmpBridge\Repository\PandoraRepository($pdo);

    $pipeline = new \SnmpBridge\Core\Discovery\DiscoveryPipeline(
        new \SnmpBridge\Core\Discovery\CapabilityResolver(),
        [
            new \SnmpBridge\DiscoveryModules\PrinterDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\InterfaceDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\InterfaceSpeedModule(),
            new \SnmpBridge\DiscoveryModules\BridgePortSpeedDiscoveryModule($speedDetector),
            new \SnmpBridge\DiscoveryModules\HuaweiInterfaceDiscoveryModule($speedDetector),
            new \SnmpBridge\DiscoveryModules\CiscoInterfaceDiscoveryModule($speedDetector),
            new \SnmpBridge\DiscoveryModules\ZTEInterfaceDiscoveryModule($speedDetector),
            new \SnmpBridge\DiscoveryModules\AlcatelInterfaceDiscoveryModule($speedDetector),
            new \SnmpBridge\DiscoveryModules\RaisecomInterfaceDiscoveryModule($speedDetector),
            new \SnmpBridge\DiscoveryModules\F5InterfaceDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\F5VirtualServerDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\F5PoolDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\F5PoolMemberDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\DahuaDiscoveryModule(),
            new \SnmpBridge\DiscoveryModules\InventoryDiscoveryModule(),
            new \SnmpBridge\DiscoveryModules\SystemMetricsDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\UniversalSystemDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\CpuDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\MemoryDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\CustomMetricsDiscoveryModule(),
            new \SnmpBridge\DiscoveryModules\ComprehensiveOidDiscoveryModule($normalizer, $oidTranslator),
            new \SnmpBridge\DiscoveryModules\HuaweiOpticalDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\OpticalDomDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\OpticalPowerWithThresholdsModule($thresholdDetector),
            new \SnmpBridge\DiscoveryModules\EnvironmentalDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\GponDiscoveryModule($normalizer),
            new \SnmpBridge\DiscoveryModules\InterfaceStatsDiscoveryModule($normalizer),
        ],
        $config['discovery'],
        $oidTranslator,
    );

    $scanner = new \SnmpBridge\Core\Snmp\SnmpScanner(
        new \SnmpBridge\Core\Vendor\ProfileMatcher($vendorRegistry),
        $pipeline,
        $deviceRepository,
        $sensorRepository,
        $config['snmp'],
    );

    $subnetScanner = new \SnmpBridge\Services\SubnetScannerService(
        $pipeline,
        $sensorRepository,
        $scanner,
        $deviceRepository,
    );

    $provisioner = new \SnmpBridge\Core\Pandora\PandoraProvisioner(
        $pandoraRepository,
        $sensorRepository,
        new \SnmpBridge\Core\Pandora\PandoraModuleBuilder($config['pandora'] ?? []),
        new \SnmpBridge\Core\Pandora\PandoraAgentResolver($agentRepository),
    );

    return [
        'config' => $config,
        'scanner' => $scanner,
        'pipeline' => $pipeline,
        'subnetScanner' => $subnetScanner,
        'provisioner' => $provisioner,
        'deviceRepo' => $deviceRepository,
        'sensorRepo' => $sensorRepository,
        'agentRepo' => $agentRepository,
        'pandoraRepo' => $pandoraRepository,
        'oidTranslator' => $oidTranslator,
        'mibDirs' => $resolvedMibDirs,
    ];
}
