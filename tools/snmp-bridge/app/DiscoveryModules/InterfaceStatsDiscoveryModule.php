<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Helpers\SensorNameFormatter;

/**
 * Discover interface statistics from IF-MIB
 * 
 * Discovers:
 * - Octets In/Out (traffic volume)
 * - Errors In/Out
 * - Discards In/Out
 * - Collisions
 * 
 * OIDs from RFC 1213 (IF-MIB) and RFC 2863 (IF-MIB revision)
 */
final class InterfaceStatsDiscoveryModule implements DiscoveryModuleInterface
{
    // IF-MIB OIDs for interface table
    private const IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';
    private const IF_IN_OCTETS = '1.3.6.1.2.1.2.2.1.10';
    private const IF_OUT_OCTETS = '1.3.6.1.2.1.2.2.1.16';
    private const IF_IN_ERRORS = '1.3.6.1.2.1.2.2.1.14';
    private const IF_OUT_ERRORS = '1.3.6.1.2.1.2.2.1.20';
    private const IF_IN_DISCARDS = '1.3.6.1.2.1.2.2.1.13';
    private const IF_OUT_DISCARDS = '1.3.6.1.2.1.2.2.1.19';
    private const IF_ADMIN_STATUS = '1.3.6.1.2.1.2.2.1.3';
    private const IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SensorNameFormatter $formatter = new SensorNameFormatter(),
    ) {
    }

    public function name(): string
    {
        return 'interface_stats';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // Interface statistics available on all devices
        return true;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        // Get interface names
        $names = $context->walker->walkIndexed(self::IF_NAME);
        
        // If IF-MIB::ifName not available, try to build from context
        if (empty($names)) {
            return $sensors;
        }

        // Get all the metrics
        $inOctets = $context->walker->walkIndexed(self::IF_IN_OCTETS);
        $outOctets = $context->walker->walkIndexed(self::IF_OUT_OCTETS);
        $inErrors = $context->walker->walkIndexed(self::IF_IN_ERRORS);
        $outErrors = $context->walker->walkIndexed(self::IF_OUT_ERRORS);
        $inDiscards = $context->walker->walkIndexed(self::IF_IN_DISCARDS);
        $outDiscards = $context->walker->walkIndexed(self::IF_OUT_DISCARDS);
        $adminStatus = $context->walker->walkIndexed(self::IF_ADMIN_STATUS);
        $operStatus = $context->walker->walkIndexed(self::IF_OPER_STATUS);

        foreach ($names as $index => $ifName) {
            $ifName = trim((string) $ifName);
            
            if ($ifName === '' || $ifName === 'lo') {
                continue; // Skip empty names and loopback
            }

            $status = $this->getOperStatusLabel($operStatus[$index] ?? null);
            if ($status !== 'up') {
                continue; // Skip interfaces that are not up
            }

            // Only discover octets if we have the value (indicates active interface)
            if (!isset($inOctets[$index]) || $inOctets[$index] === null) {
                continue;
            }

            // In Octets
            if (isset($inOctets[$index]) && $inOctets[$index] !== null && $inOctets[$index] !== '') {
                $sensor = [
                    'sensor_class' => 'interface',
                    'sensor_name' => $this->formatter->interfaceMetric($ifName, 'Octets In'),
                    'sensor_type' => 'octets',
                    'interface_index' => (int) $index,
                    'interface_name' => $ifName,
                    'entity_index' => null,
                    'oid' => self::IF_IN_OCTETS . '.' . $index,
                    'raw_value' => (string) $inOctets[$index],
                    'unit' => 'bytes',
                    'scale' => 'units',
                    'precision' => 0,
                    'status' => 'ok',
                    'metadata' => [
                        'discovery_module' => 'InterfaceStatsDiscoveryModule',
                        'source' => 'IF-MIB ifInOctets',
                        'interface_index' => (int) $index,
                        'metric_type' => 'traffic',
                    ],
                ];

                $normalized = $this->normalizer->normalize($sensor);
                if ($normalized !== null) {
                    $sensors[] = $normalized;
                }
            }

            // Out Octets
            if (isset($outOctets[$index]) && $outOctets[$index] !== null && $outOctets[$index] !== '') {
                $sensor = [
                    'sensor_class' => 'interface',
                    'sensor_name' => $this->formatter->interfaceMetric($ifName, 'Octets Out'),
                    'sensor_type' => 'octets',
                    'interface_index' => (int) $index,
                    'interface_name' => $ifName,
                    'entity_index' => null,
                    'oid' => self::IF_OUT_OCTETS . '.' . $index,
                    'raw_value' => (string) $outOctets[$index],
                    'unit' => 'bytes',
                    'scale' => 'units',
                    'precision' => 0,
                    'status' => 'ok',
                    'metadata' => [
                        'discovery_module' => 'InterfaceStatsDiscoveryModule',
                        'source' => 'IF-MIB ifOutOctets',
                        'interface_index' => (int) $index,
                        'metric_type' => 'traffic',
                    ],
                ];

                $normalized = $this->normalizer->normalize($sensor);
                if ($normalized !== null) {
                    $sensors[] = $normalized;
                }
            }

            // In Errors
            if (isset($inErrors[$index]) && (int) $inErrors[$index] > 0) {
                $sensor = [
                    'sensor_class' => 'interface',
                    'sensor_name' => $this->formatter->interfaceMetric($ifName, 'Errors In'),
                    'sensor_type' => 'errors',
                    'interface_index' => (int) $index,
                    'interface_name' => $ifName,
                    'entity_index' => null,
                    'oid' => self::IF_IN_ERRORS . '.' . $index,
                    'raw_value' => (string) $inErrors[$index],
                    'unit' => 'errors',
                    'scale' => 'units',
                    'precision' => 0,
                    'status' => 'ok',
                    'metadata' => [
                        'discovery_module' => 'InterfaceStatsDiscoveryModule',
                        'source' => 'IF-MIB ifInErrors',
                        'interface_index' => (int) $index,
                        'metric_type' => 'errors',
                    ],
                ];

                $normalized = $this->normalizer->normalize($sensor);
                if ($normalized !== null) {
                    $sensors[] = $normalized;
                }
            }

            // Out Errors
            if (isset($outErrors[$index]) && (int) $outErrors[$index] > 0) {
                $sensor = [
                    'sensor_class' => 'interface',
                    'sensor_name' => $this->formatter->interfaceMetric($ifName, 'Errors Out'),
                    'sensor_type' => 'errors',
                    'interface_index' => (int) $index,
                    'interface_name' => $ifName,
                    'entity_index' => null,
                    'oid' => self::IF_OUT_ERRORS . '.' . $index,
                    'raw_value' => (string) $outErrors[$index],
                    'unit' => 'errors',
                    'scale' => 'units',
                    'precision' => 0,
                    'status' => 'ok',
                    'metadata' => [
                        'discovery_module' => 'InterfaceStatsDiscoveryModule',
                        'source' => 'IF-MIB ifOutErrors',
                        'interface_index' => (int) $index,
                        'metric_type' => 'errors',
                    ],
                ];

                $normalized = $this->normalizer->normalize($sensor);
                if ($normalized !== null) {
                    $sensors[] = $normalized;
                }
            }

            // In Discards
            if (isset($inDiscards[$index]) && (int) $inDiscards[$index] > 0) {
                $sensor = [
                    'sensor_class' => 'interface',
                    'sensor_name' => $this->formatter->interfaceMetric($ifName, 'Discards In'),
                    'sensor_type' => 'discards',
                    'interface_index' => (int) $index,
                    'interface_name' => $ifName,
                    'entity_index' => null,
                    'oid' => self::IF_IN_DISCARDS . '.' . $index,
                    'raw_value' => (string) $inDiscards[$index],
                    'unit' => 'packets',
                    'scale' => 'units',
                    'precision' => 0,
                    'status' => 'ok',
                    'metadata' => [
                        'discovery_module' => 'InterfaceStatsDiscoveryModule',
                        'source' => 'IF-MIB ifInDiscards',
                        'interface_index' => (int) $index,
                        'metric_type' => 'discards',
                    ],
                ];

                $normalized = $this->normalizer->normalize($sensor);
                if ($normalized !== null) {
                    $sensors[] = $normalized;
                }
            }

            // Out Discards
            if (isset($outDiscards[$index]) && (int) $outDiscards[$index] > 0) {
                $sensor = [
                    'sensor_class' => 'interface',
                    'sensor_name' => $this->formatter->interfaceMetric($ifName, 'Discards Out'),
                    'sensor_type' => 'discards',
                    'interface_index' => (int) $index,
                    'interface_name' => $ifName,
                    'entity_index' => null,
                    'oid' => self::IF_OUT_DISCARDS . '.' . $index,
                    'raw_value' => (string) $outDiscards[$index],
                    'unit' => 'packets',
                    'scale' => 'units',
                    'precision' => 0,
                    'status' => 'ok',
                    'metadata' => [
                        'discovery_module' => 'InterfaceStatsDiscoveryModule',
                        'source' => 'IF-MIB ifOutDiscards',
                        'interface_index' => (int) $index,
                        'metric_type' => 'discards',
                    ],
                ];

                $normalized = $this->normalizer->normalize($sensor);
                if ($normalized !== null) {
                    $sensors[] = $normalized;
                }
            }
        }

        return $sensors;
    }

    private function getOperStatusLabel(mixed $status): string
    {
        return match ((string) $status) {
            '1' => 'up',
            '2' => 'down',
            '3' => 'testing',
            '4' => 'unknown',
            '5' => 'dormant',
            '6' => 'notPresent',
            '7' => 'lowerLayerDown',
            default => 'unknown',
        };
    }
}
