<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Helpers\SensorNameFormatter;

/**
 * Discover memory usage metrics
 * 
 * Supports vendor-specific OIDs:
 * - Cisco: CISCO-MEMORY-POOL-MIB
 * - Huawei: hwSystemMemUsage  
 * - Generic: HOST-RESOURCES-MIB
 */
final class MemoryDiscoveryModule implements DiscoveryModuleInterface
{
    // Cisco CISCO-MEMORY-POOL-MIB OIDs
    private const CISCO_MEMORY_POOL_USED = '1.3.6.1.4.1.9.9.48.1.1.1.5';
    private const CISCO_MEMORY_POOL_FREE = '1.3.6.1.4.1.9.9.48.1.1.1.6';
    private const CISCO_MEMORY_POOL_NAME = '1.3.6.1.4.1.9.9.48.1.1.1.2';

    // Huawei OIDs
    private const HUAWEI_MEMORY_USAGE = '1.3.6.1.4.1.2011.5.25.31.1.1.2.0';

    // Generic HOST-RESOURCES-MIB  
    private const HOST_RESOURCES_MEMORY = '1.3.6.1.2.1.25.2.3.1';
    private const HOST_RESOURCES_MEMORY_SIZE = '1.3.6.1.2.1.25.2.3.1.5';
    private const HOST_RESOURCES_MEMORY_USED = '1.3.6.1.2.1.25.2.3.1.6';
    private const HOST_RESOURCES_MEMORY_DESCR = '1.3.6.1.2.1.25.2.3.1.3';

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SensorNameFormatter $formatter = new SensorNameFormatter(),
    ) {
    }

    public function name(): string
    {
        return 'memory_usage';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // Memory metrics available on most systems
        return true;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        match ($context->vendor->name()) {
            'Cisco' => $sensors = $this->discoverCiscoMemory($context),
            'Huawei' => $sensors = $this->discoverHuaweiMemory($context),
            default => $sensors = $this->discoverGenericMemory($context),
        };

        return $sensors;
    }

    private function discoverCiscoMemory(DiscoveryContext $context): array
    {
        $sensors = [];

        $poolNames = $context->walker->walkIndexed(self::CISCO_MEMORY_POOL_NAME);
        $usedMemory = $context->walker->walkIndexed(self::CISCO_MEMORY_POOL_USED);
        $freeMemory = $context->walker->walkIndexed(self::CISCO_MEMORY_POOL_FREE);

        foreach ($usedMemory as $index => $used) {
            if ($used === null || $used === '') {
                continue;
            }

            $free = (int) ($freeMemory[$index] ?? 0);
            $total = (int) $used + $free;

            if ($total <= 0) {
                continue;
            }

            $usedPercent = (int) (((int) $used / $total) * 100);
            $poolName = trim((string) ($poolNames[$index] ?? "Memory Pool {$index}"));

            $sensor = [
                'sensor_class' => 'memory',
                'sensor_name' => $this->formatter->memory("Used ({$poolName})", '%'),
                'sensor_type' => 'percentage',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => (int) $index,
                'oid' => self::CISCO_MEMORY_POOL_USED . '.' . $index,
                'raw_value' => (string) $usedPercent,
                'unit' => '%',
                'scale' => 'units',
                'precision' => 0,
                'status' => 'ok',
                'metadata' => [
                    'discovery_module' => 'MemoryDiscoveryModule',
                    'source' => 'CISCO-MEMORY-POOL-MIB',
                    'vendor' => 'Cisco',
                    'pool_name' => $poolName,
                    'total_bytes' => $total,
                    'used_bytes' => $used,
                    'free_bytes' => $free,
                ],
            ];

            $normalized = $this->normalizer->normalize($sensor);
            if ($normalized !== null) {
                $sensors[] = $normalized;
            }
        }

        return $sensors;
    }

    private function discoverHuaweiMemory(DiscoveryContext $context): array
    {
        $sensors = [];

        // Huawei memory usage (single percentage value)
        $value = $context->walker->get(self::HUAWEI_MEMORY_USAGE);

        if ($value !== null && $value !== '') {
            $memUsage = (int) $value;

            // Skip invalid values
            if ($memUsage > 100 || $memUsage < 0) {
                return $sensors;
            }

            $sensor = [
                'sensor_class' => 'memory',
                'sensor_name' => $this->formatter->memory('Used', '%'),
                'sensor_type' => 'percentage',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => null,
                'oid' => self::HUAWEI_MEMORY_USAGE,
                'raw_value' => (string) $memUsage,
                'unit' => '%',
                'scale' => 'units',
                'precision' => 0,
                'status' => 'ok',
                'metadata' => [
                    'discovery_module' => 'MemoryDiscoveryModule',
                    'source' => 'Huawei-specific hwSystemMemUsage',
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

    private function discoverGenericMemory(DiscoveryContext $context): array
    {
        $sensors = [];

        $sizes = $context->walker->walkIndexed(self::HOST_RESOURCES_MEMORY_SIZE);
        $used = $context->walker->walkIndexed(self::HOST_RESOURCES_MEMORY_USED);
        $descrs = $context->walker->walkIndexed(self::HOST_RESOURCES_MEMORY_DESCR);

        foreach ($sizes as $index => $size) {
            if ($size === null || $size === '' || (int) $size <= 0) {
                continue;
            }

            $usedValue = (int) ($used[$index] ?? 0);
            $totalSize = (int) $size;

            if ($totalSize <= 0) {
                continue;
            }

            $usedPercent = (int) (($usedValue / $totalSize) * 100);
            $descr = trim((string) ($descrs[$index] ?? "Memory {$index}"));

            $sensor = [
                'sensor_class' => 'memory',
                'sensor_name' => $this->formatter->memory("Used ({$descr})", '%'),
                'sensor_type' => 'percentage',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => (int) $index,
                'oid' => self::HOST_RESOURCES_MEMORY_SIZE . '.' . $index,
                'raw_value' => (string) $usedPercent,
                'unit' => '%',
                'scale' => 'units',
                'precision' => 0,
                'status' => 'ok',
                'metadata' => [
                    'discovery_module' => 'MemoryDiscoveryModule',
                    'source' => 'HOST-RESOURCES-MIB',
                    'vendor' => $context->vendor->name(),
                    'description' => $descr,
                    'total_units' => $totalSize,
                    'used_units' => $usedValue,
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
