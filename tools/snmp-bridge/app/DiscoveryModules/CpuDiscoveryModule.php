<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Helpers\SensorNameFormatter;

/**
 * Discover CPU usage metrics
 * 
 * Supports vendor-specific OIDs:
 * - Cisco: CISCO-PROCESS-MIB CPU metrics
 * - Huawei: hwSystemCpuUsage
 * - Generic: HOST-RESOURCES-MIB
 */
final class CpuDiscoveryModule implements DiscoveryModuleInterface
{
    // Cisco CISCO-PROCESS-MIB OIDs
    private const CISCO_CPU_5MIN = '1.3.6.1.4.1.9.9.109.1.1.1.1.5';
    
    // Huawei OIDs
    private const HUAWEI_CPU_USAGE = '1.3.6.1.4.1.2011.5.25.31.1.1.1.0';
    
    // Generic HOST-RESOURCES-MIB
    private const HOST_RESOURCES_CPU = '1.3.6.1.2.1.25.3.2.1.5';

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SensorNameFormatter $formatter = new SensorNameFormatter(),
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

        match ($context->vendor->name()) {
            'Cisco' => $sensors = $this->discoverCiscocpu($context),
            'Huawei' => $sensors = $this->discoverHuaweiCpu($context),
            default => $sensors = $this->discoverGenericCpu($context),
        };

        return $sensors;
    }

    private function discoverCiscocpu(DiscoveryContext $context): array
    {
        $sensors = [];

        // Try Cisco CPU 5-minute average
        $values = $context->walker->walkIndexed(self::CISCO_CPU_5MIN);

        foreach ($values as $index => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $cpuIndex = (int) $index;
            $cpuUsage = (int) $value;

            // Skip invalid values
            if ($cpuUsage > 100 || $cpuUsage < 0) {
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
        }

        return $sensors;
    }

    private function discoverHuaweiCpu(DiscoveryContext $context): array
    {
        $sensors = [];

        // Huawei CPU usage (single value)
        $value = $context->walker->get(self::HUAWEI_CPU_USAGE);

        if ($value !== null && $value !== '') {
            $cpuUsage = (int) $value;

            // Skip invalid values
            if ($cpuUsage > 100 || $cpuUsage < 0) {
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
        }

        return $sensors;
    }

    private function discoverGenericCpu(DiscoveryContext $context): array
    {
        $sensors = [];

        // Try generic HOST-RESOURCES-MIB
        $values = $context->walker->walkIndexed(self::HOST_RESOURCES_CPU);

        foreach ($values as $index => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $cpuUsage = (int) $value;

            // Skip invalid values
            if ($cpuUsage > 100 || $cpuUsage < 0) {
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
