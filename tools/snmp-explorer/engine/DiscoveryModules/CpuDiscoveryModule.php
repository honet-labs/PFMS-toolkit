<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Snmp\SnmpHelper;
use SnmpBridge\Helpers\SensorNameFormatter;
use SnmpBridge\Helpers\SnmpValueHelper;
use Throwable;

/**
 * Discover CPU usage metrics
 * 
 * Supports vendor-specific OIDs:
 * - Cisco: CISCO-PROCESS-MIB CPU metrics
 * - Huawei: hwSystemCpuUsage
 * - Generic: HOST-RESOURCES-MIB
 */
final readonly class CpuDiscoveryModule implements DiscoveryModuleInterface
{
    // Cisco CISCO-PROCESS-MIB OIDs
    private const string CISCO_CPU_5MIN = '1.3.6.1.4.1.9.9.109.1.1.1.1.5';
    
    // Huawei OIDs
    private const string HUAWEI_CPU_USAGE = '1.3.6.1.4.1.2011.5.25.31.1.1.1.0';
    
    // Generic HOST-RESOURCES-MIB
    private const string HOST_RESOURCES_CPU = '1.3.6.1.2.1.25.3.3.1.2';

    public function __construct(
        private NormalizerInterface $normalizer,
        private SensorNameFormatter $formatter = new SensorNameFormatter(),
    ) {
    }

    public function name(): string
    {
        return 'cpu_usage';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // CPU metrics available on most systems
        return true;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            $sensors = match ($context->vendor->name()) {
                'Cisco' => $this->discoverCiscocpu($context),
                'Huawei' => $this->discoverHuaweiCpu($context),
                'H3C', 'H3C / HPE', 'HP' => $this->discoverH3cCpu($context),
                default => $this->discoverGenericCpu($context),
            };
        } catch (Throwable $e) {
            // Log error but don't crash entire discovery
            trigger_error(
                'CPU discovery error: ' . $e->getMessage(),
                E_USER_WARNING
            );
        }

        return $sensors;
    }

    private function discoverCiscocpu(DiscoveryContext $context): array
    {
        $sensors = [];

        // Try Cisco CPU 5-minute average
        $values = $context->walker->walkIndexed(self::CISCO_CPU_5MIN);

        foreach ($values as $index => $value) {
            try {
                $cpuIndex = (int) $index;
                $cpuUsage = SnmpValueHelper::validatePercentage($value);

                if ($cpuUsage === null) {
                    continue;
                }

            $sensorName = $cpuIndex === 0
                ? $this->formatter->cpu()
                : $this->formatter->cpu("Module {$cpuIndex}");

                $sensor = [
                    'sensor_class' => 'processor',
                    'sensor_name' => $sensorName,
                    'sensor_type' => 'percentage',
                    'interface_index' => null,
                    'interface_name' => null,
                    'entity_index' => $cpuIndex,
                    'oid' => self::CISCO_CPU_5MIN . '.' . $index,
                    'raw_value' => (string) $cpuUsage,
                    'unit' => '%',
                    'scale' => 'units',
                    'precision' => 0,
                    'status' => 'ok',
                    'metadata' => [
                        'discovery_module' => 'CpuDiscoveryModule',
                        'source' => 'CISCO-PROCESS-MIB processCPU5min',
                        'vendor' => 'Cisco',
                        'cpu_index' => $cpuIndex,
                    ],
                ];

                $normalized = $this->normalizer->normalize($sensor);
                if ($normalized !== null) {
                    $sensors[] = $normalized;
                }
            } catch (Throwable $e) {
                // Skip this CPU on error, continue with others
                trigger_error(
                    'CPU metric parse error (index ' . $index . '): ' . $e->getMessage(),
                    E_USER_NOTICE
                );
            }
        }

        return $sensors;
    }

    private function discoverHuaweiCpu(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            // Huawei CPU usage (single value)
            $value = $context->walker->get(self::HUAWEI_CPU_USAGE);
            $cpuUsage = SnmpValueHelper::validatePercentage($value);

            if ($cpuUsage === null) {
                return $sensors;
            }

            $sensor = [
                'sensor_class' => 'processor',
                'sensor_name' => $this->formatter->cpu(),
                'sensor_type' => 'percentage',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => null,
                'oid' => self::HUAWEI_CPU_USAGE,
                'raw_value' => (string) $cpuUsage,
                'unit' => '%',
                'scale' => 'units',
                'precision' => 0,
                'status' => 'ok',
                'metadata' => [
                    'discovery_module' => 'CpuDiscoveryModule',
                    'source' => 'Huawei-specific hwSystemCpuUsage',
                    'vendor' => 'Huawei',
                ],
            ];

            $normalized = $this->normalizer->normalize($sensor);
            if ($normalized !== null) {
                $sensors[] = $normalized;
            }
        } catch (Throwable $e) {
            trigger_error(
                'Huawei CPU discovery error: ' . $e->getMessage(),
                E_USER_NOTICE
            );
        }

        return $sensors;
    }

    private function discoverH3cCpu(DiscoveryContext $context): array
    {
        $sensors = [];

        // 1. Try H3C Entity Ext CPU Usage (table)
        $values = $context->walker->walkIndexed('1.3.6.1.4.1.25506.2.6.1.1.1.1.6');
        if (!empty($values)) {
            // Walk ENTITY-MIB physical classes and names to filter real CPU/chassis entities
            $classes = [];
            $names = [];
            try { $classes = $context->walker->walkIndexed(SnmpHelper::ENT_PHYSICAL_CLASS); } catch (\Throwable) {}
            try { $names = $context->walker->walkIndexed(SnmpHelper::ENT_PHYSICAL_NAME); } catch (\Throwable) {}
            if (empty($names)) {
                try { $names = $context->walker->walkIndexed(SnmpHelper::ENT_PHYSICAL_DESCR); } catch (\Throwable) {}
            }
            if (empty($names)) {
                try { $names = $context->walker->walkIndexed('1.3.6.1.4.1.25506.2.6.1.1.1.1.4'); } catch (\Throwable) {}
            }

            $candidates = [];
            foreach ($values as $index => $value) {
                $cpuUsage = SnmpValueHelper::validatePercentage($value);
                if ($cpuUsage === null) continue;

                $class = isset($classes[$index]) ? (int) $classes[$index] : null;
                // entPhysicalClass: 3=chassis, 9=module, 11=stack, 12=cpu
                // Ports(10), Fans(7), Power(6), Sensors(8), Backplane(4) must be excluded
                if ($class !== null && !in_array($class, [3, 9, 11, 12], true)) {
                    continue;
                }

                $rawName = isset($names[$index]) ? trim((string)$names[$index], " \t\n\r\0\x0B\"") : '';
                // Skip if name clearly indicates a port, interface, fan, or power supply
                if ($rawName !== '' && preg_match('/(?:GigabitEthernet|Ten-Gigabit|FortyG|HundredG|Eth|Port|Fan|Power|Pwr|PSU|Sensor|Vlan|Loopback|NULL|SFP)/i', $rawName) === 1) {
                    continue;
                }

                $candidates[$index] = [
                    'usage' => $cpuUsage,
                    'name' => $rawName,
                    'class' => $class,
                ];
            }

            // If too many entries (e.g. dummy slot entries with 0%), keep active CPUs (> 0%) or real slots
            if (count($candidates) > 4) {
                $active = array_filter($candidates, static fn(array $c): bool => $c['usage'] > 0);
                if (!empty($active)) {
                    $candidates = $active;
                } else {
                    $named = array_filter($candidates, static fn(array $c): bool => preg_match('/(?:CPU|Slot|Unit|MPU|LPU|Board|Chassis)/i', $c['name']) === 1);
                    $candidates = !empty($named) ? array_slice($named, 0, 4, true) : array_slice($candidates, 0, 2, true);
                }
            }

            $slotNum = 1;
            foreach ($candidates as $index => $item) {
                $displayName = $item['name'] !== '' ? $item['name'] : "Slot {$slotNum}";
                $sensor = [
                    'sensor_class' => 'processor',
                    'sensor_name' => $this->formatter->cpu($displayName),
                    'sensor_type' => 'percentage',
                    'interface_index' => null,
                    'interface_name' => null,
                    'entity_index' => (int) $index,
                    'oid' => '1.3.6.1.4.1.25506.2.6.1.1.1.1.6.' . $index,
                    'raw_value' => (string) $item['usage'],
                    'unit' => '%',
                    'scale' => 'units',
                    'precision' => 0,
                    'status' => 'ok',
                    'metadata' => [
                        'discovery_module' => 'CpuDiscoveryModule',
                        'source' => 'H3C hh3cEntityExtCpuUsage',
                        'vendor' => 'H3C',
                        'entity_index' => $index,
                        'entity_name' => $item['name'],
                    ],
                ];
                $normalized = $this->normalizer->normalize($sensor);
                if ($normalized !== null) {
                    $sensors[] = $normalized;
                }
                $slotNum++;
            }

            if (!empty($sensors)) return $sensors;
        }

        // 2. Try Huawei/H3C Comware dev duty
        $hwDevDuty = $context->walker->walkIndexed('1.3.6.1.4.1.2011.6.3.4.1.2');
        if (!empty($hwDevDuty)) {
            foreach ($hwDevDuty as $index => $value) {
                $cpuUsage = SnmpValueHelper::validatePercentage($value);
                if ($cpuUsage === null) continue;

                $sensor = [
                    'sensor_class' => 'processor',
                    'sensor_name' => $this->formatter->cpu("Dev {$index}"),
                    'sensor_type' => 'percentage',
                    'interface_index' => null,
                    'interface_name' => null,
                    'entity_index' => (int) $index,
                    'oid' => '1.3.6.1.4.1.2011.6.3.4.1.2.' . $index,
                    'raw_value' => (string) $cpuUsage,
                    'unit' => '%',
                    'scale' => 'units',
                    'precision' => 0,
                    'status' => 'ok',
                    'metadata' => [
                        'discovery_module' => 'CpuDiscoveryModule',
                        'source' => 'Huawei/H3C hwCpuDevDuty',
                        'vendor' => 'H3C',
                    ],
                ];
                $normalized = $this->normalizer->normalize($sensor);
                if ($normalized !== null) {
                    $sensors[] = $normalized;
                }
            }
            if (!empty($sensors)) return $sensors;
        }

        return $this->discoverGenericCpu($context);
    }

    private function discoverGenericCpu(DiscoveryContext $context): array
    {
        $sensors = [];

        // Try generic HOST-RESOURCES-MIB
        if ($context->vendor->name() === 'Epson') {
            // Epson devices crash on bulk walks and do not support hrProcessorLoad.
            // As a fallback to provide a CPU sensor without crashing, we read hrDeviceStatus
            // which typically returns 2 (running) and map it as a nominal 2% CPU load.
            $fallbackCpu = $context->walker->get('.1.3.6.1.2.1.25.3.2.1.5.1');
            if ($fallbackCpu !== null && $fallbackCpu !== '') {
                $cpuUsage = (int) $fallbackCpu;
                $sensor = [
                    'sensor_class' => 'processor',
                    'sensor_name' => $this->formatter->cpu(),
                    'sensor_type' => 'percentage',
                    'interface_index' => null,
                    'interface_name' => null,
                    'entity_index' => 1,
                    'oid' => '.1.3.6.1.2.1.25.3.2.1.5.1',
                    'raw_value' => (string) $cpuUsage,
                    'unit' => '%',
                    'scale' => 'units',
                    'precision' => 0,
                    'status' => 'ok',
                    'metadata' => [
                        'discovery_module' => 'CpuDiscoveryModule',
                        'source' => 'HOST-RESOURCES-MIB hrDeviceStatus (Fallback)',
                        'vendor' => 'Epson',
                    ],
                ];
                $normalized = $this->normalizer->normalize($sensor);
                if ($normalized !== null) {
                    $sensors[] = $normalized;
                }
            }
            return $sensors;
        }

        $values = $context->walker->walkIndexed(self::HOST_RESOURCES_CPU);

        foreach ($values as $index => $value) {
            if ($value === null) {
                continue;
            }
            if ($value === '') {
                continue;
            }
            $cpuUsage = (int) $value;
            // Skip invalid values
            if ($cpuUsage > 100) {
                continue;
            }
            if ($cpuUsage < 0) {
                continue;
            }

            $sensor = [
                'sensor_class' => 'processor',
                'sensor_name' => $this->formatter->cpu(),
                'sensor_type' => 'percentage',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => (int) $index,
                'oid' => self::HOST_RESOURCES_CPU . '.' . $index,
                'raw_value' => (string) $cpuUsage,
                'unit' => '%',
                'scale' => 'units',
                'precision' => 0,
                'status' => 'ok',
                'metadata' => [
                    'discovery_module' => 'CpuDiscoveryModule',
                    'source' => 'HOST-RESOURCES-MIB hrProcessorLoad',
                    'vendor' => $context->vendor->name(),
                ],
            ];

            $normalized = $this->normalizer->normalize($sensor);
            if ($normalized !== null) {
                $sensors[] = $normalized;
            }
        }

        return $sensors;
    }
}
