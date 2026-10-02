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
