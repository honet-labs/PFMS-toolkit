<?php

declare(strict_types=1);

use SnmpBridge\Core\Discovery\CapabilityResolver;
use SnmpBridge\Core\Discovery\DiscoveryPipeline;
use SnmpBridge\Core\Normalize\InvalidValueFilter;
use SnmpBridge\Core\Normalize\ScaleNormalizer;
use SnmpBridge\Core\Normalize\SensorNormalizer;
use SnmpBridge\Core\Normalize\SpeedDetector;
use SnmpBridge\Core\Normalize\UnitNormalizer;
use SnmpBridge\Core\Pandora\PandoraAgentResolver;
use SnmpBridge\Core\Pandora\PandoraModuleBuilder;
use SnmpBridge\Core\Pandora\PandoraProvisioner;
use SnmpBridge\Core\Snmp\SnmpScanner;
use SnmpBridge\Core\Vendor\ProfileMatcher;
use SnmpBridge\Core\Vendor\VendorRegistry;
use SnmpBridge\Database\ConnectionManager;
use SnmpBridge\DiscoveryModules\AlcatelInterfaceDiscoveryModule;
use SnmpBridge\DiscoveryModules\CiscoInterfaceDiscoveryModule;
use SnmpBridge\DiscoveryModules\CpuDiscoveryModule;
use SnmpBridge\DiscoveryModules\EnvironmentalDiscoveryModule;
use SnmpBridge\DiscoveryModules\GponDiscoveryModule;
use SnmpBridge\DiscoveryModules\HuaweiInterfaceDiscoveryModule;
use SnmpBridge\DiscoveryModules\HuaweiOpticalDiscoveryModule;
use SnmpBridge\DiscoveryModules\InterfaceDiscoveryModule;
use SnmpBridge\DiscoveryModules\InterfaceSpeedModule;
use SnmpBridge\DiscoveryModules\InterfaceStatsDiscoveryModule;
use SnmpBridge\DiscoveryModules\InventoryDiscoveryModule;
use SnmpBridge\DiscoveryModules\MemoryDiscoveryModule;
use SnmpBridge\DiscoveryModules\OpticalDomDiscoveryModule;
use SnmpBridge\DiscoveryModules\RaisecomInterfaceDiscoveryModule;
use SnmpBridge\DiscoveryModules\SystemMetricsDiscoveryModule;
use SnmpBridge\DiscoveryModules\UniversalSystemDiscoveryModule;
use SnmpBridge\DiscoveryModules\ZTEInterfaceDiscoveryModule;
use SnmpBridge\Http\Controllers\InventoryController;
use SnmpBridge\Http\Controllers\PandoraController;
use SnmpBridge\Http\Controllers\ScanController;
use SnmpBridge\Repository\AgentRepository;
use SnmpBridge\Repository\DeviceRepository;
use SnmpBridge\Repository\PandoraRepository;
use SnmpBridge\Repository\SensorInventoryRepository;
use SnmpBridge\VendorAdapter\Alcatel\AlcatelAdapter;
use SnmpBridge\VendorAdapter\Cisco\CiscoAdapter;
use SnmpBridge\VendorAdapter\Huawei\HuaweiAdapter;
use SnmpBridge\VendorAdapter\Raisecom\RaisecomAdapter;
use SnmpBridge\VendorAdapter\ZTE\ZTEAdapter;

require __DIR__ . '/vendor.php';

define('SNMP_BRIDGE_ROOT', dirname(__DIR__));

load_env(SNMP_BRIDGE_ROOT . '/.env');

date_default_timezone_set((string) env('APP_TIMEZONE', 'UTC'));

$config = [
    'app' => require SNMP_BRIDGE_ROOT . '/config/app.php',
    'database' => require SNMP_BRIDGE_ROOT . '/config/database.php',
    'snmp' => require SNMP_BRIDGE_ROOT . '/config/snmp.php',
    'vendors' => require SNMP_BRIDGE_ROOT . '/config/vendors.php',
    'pandora' => require SNMP_BRIDGE_ROOT . '/config/pandora.php',
];

/** @var ConnectionManager $connections */
$connections = require SNMP_BRIDGE_ROOT . '/bootstrap/database.php';

$vendorRegistry = new VendorRegistry([
    new HuaweiAdapter(),
    new CiscoAdapter(),
    new ZTEAdapter(),
    new AlcatelAdapter(),
    new RaisecomAdapter(),
]);

$normalizer = new SensorNormalizer(
    new InvalidValueFilter(),
    new ScaleNormalizer(),
    new UnitNormalizer(),
);

$speedDetector = new SpeedDetector();

$deviceRepository = new DeviceRepository($connections->internal());
$sensorRepository = new SensorInventoryRepository($connections->internal());
$pandoraRepository = new PandoraRepository($connections->pandora());
$agentRepository = new AgentRepository($connections->pandora());

$pipeline = new DiscoveryPipeline(
    new CapabilityResolver(),
    [
        new InterfaceDiscoveryModule($normalizer, $speedDetector),
        new InterfaceSpeedModule($speedDetector),
        new HuaweiInterfaceDiscoveryModule($normalizer, $speedDetector),
        new CiscoInterfaceDiscoveryModule($normalizer, $speedDetector),
        new ZTEInterfaceDiscoveryModule($normalizer, $speedDetector),
        new AlcatelInterfaceDiscoveryModule($normalizer, $speedDetector),
        new RaisecomInterfaceDiscoveryModule($normalizer, $speedDetector),
        new InventoryDiscoveryModule(),
        new SystemMetricsDiscoveryModule($normalizer),
        new UniversalSystemDiscoveryModule($normalizer),
        new CpuDiscoveryModule($normalizer),
        new MemoryDiscoveryModule($normalizer),
        new HuaweiOpticalDiscoveryModule($normalizer),
        new OpticalDomDiscoveryModule($normalizer),
        new EnvironmentalDiscoveryModule($normalizer),
        new GponDiscoveryModule($normalizer),
        new InterfaceStatsDiscoveryModule($normalizer),
    ],
);

$scanner = new SnmpScanner(
    new ProfileMatcher($vendorRegistry),
    $pipeline,
    $deviceRepository,
    $sensorRepository,
    $config['snmp'],
);

$moduleBuilder = new PandoraModuleBuilder($config['pandora']);
$provisioner = new PandoraProvisioner(
    $pandoraRepository,
    $sensorRepository,
    $moduleBuilder,
    new PandoraAgentResolver($agentRepository),
);

return [
    'config' => $config,
    'connections' => $connections,
    'scanner' => $scanner,
    'repositories' => [
        'devices' => $deviceRepository,
        'sensors' => $sensorRepository,
        'pandora' => $pandoraRepository,
        'agents' => $agentRepository,
    ],
    'controllers' => [
        InventoryController::class => new InventoryController($sensorRepository, $agentRepository),
        ScanController::class => new ScanController($scanner),
        PandoraController::class => new PandoraController($provisioner),
    ],
];

function env(string $key, mixed $default = null): mixed
{
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

function env_int(string $key, int $default): int
{
    return (int) env($key, $default);
}

function env_bool(string $key, bool $default): bool
{
    $value = env($key, $default);

    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

function load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        $value = trim($value, "\"'");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
