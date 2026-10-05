<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Helpers\SensorNameFormatter;
use SnmpBridge\Helpers\SnmpValueHelper;
use Throwable;

/**
 * Discover memory usage metrics
 * 
 * Supports vendor-specific OIDs:
 * - Cisco: CISCO-MEMORY-POOL-MIB
 * - Huawei: hwSystemMemUsage  
 * - Generic: HOST-RESOURCES-MIB
 */
final readonly class MemoryDiscoveryModule implements DiscoveryModuleInterface
{
    // Cisco CISCO-MEMORY-POOL-MIB OIDs
    private const string CISCO_MEMORY_POOL_USED = '1.3.6.1.4.1.9.9.48.1.1.1.5';
    private const string CISCO_MEMORY_POOL_FREE = '1.3.6.1.4.1.9.9.48.1.1.1.6';
    private const string CISCO_MEMORY_POOL_NAME = '1.3.6.1.4.1.9.9.48.1.1.1.2';

    // Huawei OIDs
    private const string HUAWEI_MEMORY_USAGE = '1.3.6.1.4.1.2011.5.25.31.1.1.2.0';
    private const string HOST_RESOURCES_MEMORY_SIZE = '1.3.6.1.2.1.25.2.3.1.5';
    private const string HOST_RESOURCES_MEMORY_USED = '1.3.6.1.2.1.25.2.3.1.6';
    private const string HOST_RESOURCES_MEMORY_DESCR = '1.3.6.1.2.1.25.2.3.1.3';

    public function __construct(
        private NormalizerInterface $normalizer,
        private SensorNameFormatter $formatter = new SensorNameFormatter(),
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

        try {
            $sensors = match ($context->vendor->name()) {
                'Cisco' => $this->discoverCiscoMemory($context),
                'Huawei' => $this->discoverHuaweiMemory($context),
                'H3C', 'H3C / HPE', 'HP' => $this->discoverH3cMemory($context),
                default => $this->discoverGenericMemory($context),
            };
        } catch (\Throwable $e) {
            // Log error but don't crash entire discovery
            trigger_error(
                'Memory discovery error: ' . $e->getMessage(),
                E_USER_WARNING
            );
        }

        return $sensors;
    }

    private function discoverCiscoMemory(DiscoveryContext $context): array
    {
        $sensors = [];

        $poolNames = $context->walker->walkIndexed(self::CISCO_MEMORY_POOL_NAME);
        $usedMemory = $context->walker->walkIndexed(self::CISCO_MEMORY_POOL_USED);
        $freeMemory = $context->walker->walkIndexed(self::CISCO_MEMORY_POOL_FREE);

        foreach ($usedMemory as $index => $used) {
            try {
                $usedVal = \SnmpBridge\Helpers\SnmpValueHelper::validateNonNegative($used);
                if ($usedVal === null) {
                    continue;
                }

                $free = \SnmpBridge\Helpers\SnmpValueHelper::validateNonNegative($freeMemory[$index] ?? null) ?? 0;
                $total = $usedVal + $free;

                if ($total <= 0) {
                    continue;
                }

                $usedPercent = (int) (($usedVal / $total) * 100);
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
                        'used_bytes' => $usedVal,
                        'free_bytes' => $free,
                    ],
                ];

                $normalized = $this->normalizer->normalize($sensor);
                if ($normalized !== null) {
                    $sensors[] = $normalized;
                }
            } catch (\Throwable $e) {
                // Skip this pool on error, continue with others
                trigger_error(
                    'Memory pool parse error (index ' . $index . '): ' . $e->getMessage(),
                    E_USER_NOTICE
                );
            }
        }

        return $sensors;
    }

    private function discoverHuaweiMemory(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            // Huawei memory usage (single percentage value)
            $value = $context->walker->get(self::HUAWEI_MEMORY_USAGE);
            $memUsage = \SnmpBridge\Helpers\SnmpValueHelper::validatePercentage($value);

            if ($memUsage === null) {
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
                    'source' => 'Huawei hwSystemMemUsage',
                    'vendor' => 'Huawei',
                ],
            ];

            $normalized = $this->normalizer->normalize($sensor);
            if ($normalized !== null) {
                $sensors[] = $normalized;
            }
        } catch (\Throwable $e) {
            trigger_error(
                'Huawei memory discovery error: ' . $e->getMessage(),
                E_USER_NOTICE
            );
        }

        return $sensors;
    }

    private function discoverH3cMemory(DiscoveryContext $context): array
    {
        $sensors = [];

        // 1. Try H3C Entity Ext Memory Usage (table)
        $values = $context->walker->walkIndexed('1.3.6.1.4.1.25506.2.6.1.1.1.1.8');
        if (!empty($values)) {
            foreach ($values as $index => $value) {
                $memUsage = \SnmpBridge\Helpers\SnmpValueHelper::validatePercentage($value);
                if ($memUsage === null) continue;

                $sensor = [
                    'sensor_class' => 'memory',
                    'sensor_name' => $this->formatter->memory("Slot {$index}"),
                    'sensor_type' => 'memory_usage_percent',
                    'interface_index' => null,
                    'interface_name' => null,
                    'entity_index' => (int) $index,
                    'oid' => '1.3.6.1.4.1.25506.2.6.1.1.1.1.8.' . $index,
                    'raw_value' => (string) $memUsage,
                    'unit' => '%',
                    'scale' => 'units',
                    'precision' => 0,
                    'status' => 'ok',
                    'metadata' => [
                        'discovery_module' => 'MemoryDiscoveryModule',
                        'source' => 'H3C hh3cEntityExtMemUsage',
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

        // 2. Try Huawei/H3C Comware mem dev duty
        $hwDevDuty = $context->walker->walkIndexed('1.3.6.1.4.1.2011.6.3.4.1.3');
        if (!empty($hwDevDuty)) {
            foreach ($hwDevDuty as $index => $value) {
                $memUsage = \SnmpBridge\Helpers\SnmpValueHelper::validatePercentage($value);
                if ($memUsage === null) continue;

                $sensor = [
                    'sensor_class' => 'memory',
                    'sensor_name' => $this->formatter->memory("Dev {$index}"),
                    'sensor_type' => 'memory_usage_percent',
                    'interface_index' => null,
                    'interface_name' => null,
                    'entity_index' => (int) $index,
                    'oid' => '1.3.6.1.4.1.2011.6.3.4.1.3.' . $index,
                    'raw_value' => (string) $memUsage,
                    'unit' => '%',
                    'scale' => 'units',
                    'precision' => 0,
                    'status' => 'ok',
                    'metadata' => [
                        'discovery_module' => 'MemoryDiscoveryModule',
                        'source' => 'Huawei/H3C hwMemDevDuty',
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

        return $this->discoverGenericMemory($context);
    }

    private function discoverGenericMemory(DiscoveryContext $context): array
    {
        $sensors = [];

        if ($context->vendor->name() === 'Epson') {
            // Epson devices crash on bulk walks and do not support standard memory pools
            return $sensors;
        }

        $sizes = $context->walker->walkIndexed(self::HOST_RESOURCES_MEMORY_SIZE);
        $used = $context->walker->walkIndexed(self::HOST_RESOURCES_MEMORY_USED);
        $descrs = $context->walker->walkIndexed(self::HOST_RESOURCES_MEMORY_DESCR);

        foreach ($sizes as $index => $size) {
            if ($size === null) {
                continue;
            }
            if ($size === '') {
                continue;
            }
            if ((int) $size <= 0) {
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
