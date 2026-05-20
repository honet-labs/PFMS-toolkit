<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Helpers\SensorNameFormatter;
use SnmpBridge\Helpers\StringHelper;

/**
 * Universal SNMP system discovery for all vendors
 * 
 * Discovers comprehensive system metrics available on any SNMP device:
 * - Disk/Storage usage
 * - Network interface details
 * - Network stack statistics
 * - Process information
 * - System load/averages
 * - UPS/Power information
 * 
 * Uses standard MIBs:
 * - HOST-RESOURCES-MIB (storage, processes)
 * - SNMPv2-MIB (system)
 * - IF-MIB (interfaces)
 * - UPS-MIB (power systems)
 */
final class UniversalSystemDiscoveryModule implements DiscoveryModuleInterface
{
    // HOST-RESOURCES-MIB storage discovery
    private const HR_STORAGE_INDEX = '1.3.6.1.2.1.25.2.3.1.1';
    private const HR_STORAGE_TYPE = '1.3.6.1.2.1.25.2.3.1.2';
    private const HR_STORAGE_DESCR = '1.3.6.1.2.1.25.2.3.1.3';
    private const HR_STORAGE_ALLOC_UNITS = '1.3.6.1.2.1.25.2.3.1.4';
    private const HR_STORAGE_SIZE = '1.3.6.1.2.1.25.2.3.1.5';
    private const HR_STORAGE_USED = '1.3.6.1.2.1.25.2.3.1.6';

    // Storage type OIDs
    private const STORAGE_TYPE_FIXED_DISK = '1.3.6.1.2.1.25.2.1.4';
    private const STORAGE_TYPE_REMOVABLE_DISK = '1.3.6.1.2.1.25.2.1.5';
    private const STORAGE_TYPE_MEMORY = '1.3.6.1.2.1.25.2.1.2';
    private const STORAGE_TYPE_VIRTUAL_MEMORY = '1.3.6.1.2.1.25.2.1.3';

    // System processes
    private const HR_PROC_RUN_INDEX = '1.3.6.1.2.1.25.1.6.0';
    private const HR_PROC_NUM = '1.3.6.1.2.1.25.1.5.0';

    // Network layer discovery
    private const IP_FORWARD_NUM = '1.3.6.1.2.1.4.24.4.0';
    private const TCP_CONN_TABLE_SIZE = '1.3.6.1.2.1.6.13.0';
    private const UDP_ENDPOINT_COUNT = '1.3.6.1.2.1.7.7.0';

    // IP statistics
    private const IP_IN_RECEIVES = '1.3.6.1.2.1.4.3.0';
    private const IP_OUT_REQUESTS = '1.3.6.1.2.1.4.10.0';
    private const IP_IN_DISCARDS = '1.3.6.1.2.1.4.8.0';
    private const IP_OUT_DISCARDS = '1.3.6.1.2.1.4.11.0';

    // ICMP statistics
    private const ICMP_IN_MSGS = '1.3.6.1.2.1.5.1.0';
    private const ICMP_OUT_MSGS = '1.3.6.1.2.1.5.11.0';
    private const ICMP_IN_ERRORS = '1.3.6.1.2.1.5.3.0';
    private const ICMP_OUT_ERRORS = '1.3.6.1.2.1.5.13.0';

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SensorNameFormatter $formatter = new SensorNameFormatter(),
    ) {
    }

    public function name(): string
    {
        return 'universal_system';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // Supported on all vendors
        return true;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        // Discover storage information
        $sensors = array_merge($sensors, $this->discoverStorage($context));

        // Discover process information
        $sensors = array_merge($sensors, $this->discoverProcesses($context));

        // Discover network layer information
        $sensors = array_merge($sensors, $this->discoverNetworkLayer($context));

        // Discover IP statistics
        $sensors = array_merge($sensors, $this->discoverIpStatistics($context));

        // Discover ICMP statistics
        $sensors = array_merge($sensors, $this->discoverIcmpStatistics($context));

        return $sensors;
    }

    /**
     * Discover storage/disk information
     */
    private function discoverStorage(DiscoveryContext $context): array
    {
        $sensors = [];

        $indices = $context->walker->walkIndexed(self::HR_STORAGE_INDEX);
        $types = $context->walker->walkIndexed(self::HR_STORAGE_TYPE);
        $descriptions = $context->walker->walkIndexed(self::HR_STORAGE_DESCR);
        $allocUnits = $context->walker->walkIndexed(self::HR_STORAGE_ALLOC_UNITS);
        $sizes = $context->walker->walkIndexed(self::HR_STORAGE_SIZE);
        $used = $context->walker->walkIndexed(self::HR_STORAGE_USED);

        foreach ($indices as $index => $value) {
            $type = $types[$index] ?? null;
            $descr = trim((string) ($descriptions[$index] ?? ''));
            $allocUnit = (int) ($allocUnits[$index] ?? 1);
            $size = (int) ($sizes[$index] ?? 0);
            $usedVal = (int) ($used[$index] ?? 0);

            // Skip non-disk storage
            if (!$this->isStorageType($type, ['disk', 'memory'])) {
                continue;
            }

            // Skip very small storage (< 1MB)
            if ($size * $allocUnit < 1024 * 1024) {
                continue;
            }

            // Calculate percentage
            if ($size > 0) {
                $usedPercent = round(($usedVal / $size) * 100, 2);
            } else {
                $usedPercent = 0;
            }

            $normalized = $this->normalizer->normalize(
                $usedPercent,
                unit: '%'
            );

            if ($normalized === null) {
                continue;
            }

            $componentName = $this->sanitizeComponentName($descr) ?: ('Storage-' . $index);

            $sensors[] = [
                'sensor_class' => 'storage',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric($componentName, 'Used', '%')
                ),
                'sensor_type' => 'storage_used_percent',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => (int) $index,
                'oid' => self::HR_STORAGE_USED . '.' . $index,
                'raw_value' => $usedVal,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'storage_used_percent',
                    'storage_size_bytes' => $size * $allocUnit,
                    'storage_used_bytes' => $usedVal * $allocUnit,
                    'alloc_unit' => $allocUnit,
                    'description' => $descr,
                    'source' => 'HOST-RESOURCES-MIB::hrStorageUsed',
                ]),
            ];
        }

        return $sensors;
    }

    /**
     * Discover process information
     */
    private function discoverProcesses(DiscoveryContext $context): array
    {
        $sensors = [];

        $procRun = $context->walker->walkSingle(self::HR_PROC_RUN_INDEX);
        $procNum = $context->walker->walkSingle(self::HR_PROC_NUM);

        if ($procRun !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $procRun,
                unit: 'count'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'system',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('Processes', 'Running', 'count')
                ),
                'sensor_type' => 'processes_running',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::HR_PROC_RUN_INDEX,
                'raw_value' => $procRun,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'processes_running',
                    'source' => 'HOST-RESOURCES-MIB::hrSystemProcesses',
                ]),
            ];
        }

        if ($procNum !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $procNum,
                unit: 'count'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'system',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('Processes', 'Total', 'count')
                ),
                'sensor_type' => 'processes_total',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::HR_PROC_NUM,
                'raw_value' => $procNum,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'processes_total',
                    'source' => 'HOST-RESOURCES-MIB::hrSystemNumProcesses',
                ]),
            ];
        }

        return $sensors;
    }

    /**
     * Discover network layer information
     */
    private function discoverNetworkLayer(DiscoveryContext $context): array
    {
        $sensors = [];

        $ipForwardNum = $context->walker->walkSingle(self::IP_FORWARD_NUM);
        $tcpConnSize = $context->walker->walkSingle(self::TCP_CONN_TABLE_SIZE);
        $udpEndpoints = $context->walker->walkSingle(self::UDP_ENDPOINT_COUNT);

        if ($ipForwardNum !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $ipForwardNum,
                unit: 'count'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'network',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('Network', 'Route Entries', 'count')
                ),
                'sensor_type' => 'network_routes',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::IP_FORWARD_NUM,
                'raw_value' => $ipForwardNum,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'network_routes',
                    'source' => 'IP-MIB::ipForwardNumber',
                ]),
            ];
        }

        if ($tcpConnSize !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $tcpConnSize,
                unit: 'count'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'network',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('Network', 'TCP Connections', 'count')
                ),
                'sensor_type' => 'network_tcp_connections',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::TCP_CONN_TABLE_SIZE,
                'raw_value' => $tcpConnSize,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'network_tcp_connections',
                    'source' => 'TCP-MIB::tcpConnectionTableSize',
                ]),
            ];
        }

        if ($udpEndpoints !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $udpEndpoints,
                unit: 'count'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'network',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('Network', 'UDP Endpoints', 'count')
                ),
                'sensor_type' => 'network_udp_endpoints',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::UDP_ENDPOINT_COUNT,
                'raw_value' => $udpEndpoints,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'network_udp_endpoints',
                    'source' => 'UDP-MIB::udpEndpointCount',
                ]),
            ];
        }

        return $sensors;
    }

    /**
     * Discover IP statistics
     */
    private function discoverIpStatistics(DiscoveryContext $context): array
    {
        $sensors = [];

        $ipInReceives = $context->walker->walkSingle(self::IP_IN_RECEIVES);
        $ipOutRequests = $context->walker->walkSingle(self::IP_OUT_REQUESTS);
        $ipInDiscards = $context->walker->walkSingle(self::IP_IN_DISCARDS);
        $ipOutDiscards = $context->walker->walkSingle(self::IP_OUT_DISCARDS);

        if ($ipInReceives !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $ipInReceives,
                unit: 'packets'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'network',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('IP', 'In Packets', 'packets')
                ),
                'sensor_type' => 'ip_in_receives',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::IP_IN_RECEIVES,
                'raw_value' => $ipInReceives,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'ip_in_receives',
                    'source' => 'IP-MIB::ipInReceives',
                ]),
            ];
        }

        if ($ipOutRequests !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $ipOutRequests,
                unit: 'packets'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'network',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('IP', 'Out Packets', 'packets')
                ),
                'sensor_type' => 'ip_out_requests',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::IP_OUT_REQUESTS,
                'raw_value' => $ipOutRequests,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'ip_out_requests',
                    'source' => 'IP-MIB::ipOutRequests',
                ]),
            ];
        }

        if ($ipInDiscards !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $ipInDiscards,
                unit: 'packets'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'network',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('IP', 'In Discards', 'packets')
                ),
                'sensor_type' => 'ip_in_discards',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::IP_IN_DISCARDS,
                'raw_value' => $ipInDiscards,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'ip_in_discards',
                    'source' => 'IP-MIB::ipInDiscards',
                ]),
            ];
        }

        if ($ipOutDiscards !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $ipOutDiscards,
                unit: 'packets'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'network',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('IP', 'Out Discards', 'packets')
                ),
                'sensor_type' => 'ip_out_discards',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::IP_OUT_DISCARDS,
                'raw_value' => $ipOutDiscards,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'ip_out_discards',
                    'source' => 'IP-MIB::ipOutDiscards',
                ]),
            ];
        }

        return $sensors;
    }

    /**
     * Discover ICMP statistics
     */
    private function discoverIcmpStatistics(DiscoveryContext $context): array
    {
        $sensors = [];

        $icmpInMsgs = $context->walker->walkSingle(self::ICMP_IN_MSGS);
        $icmpOutMsgs = $context->walker->walkSingle(self::ICMP_OUT_MSGS);
        $icmpInErrors = $context->walker->walkSingle(self::ICMP_IN_ERRORS);
        $icmpOutErrors = $context->walker->walkSingle(self::ICMP_OUT_ERRORS);

        if ($icmpInMsgs !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $icmpInMsgs,
                unit: 'messages'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'network',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('ICMP', 'In Messages', 'messages')
                ),
                'sensor_type' => 'icmp_in_msgs',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::ICMP_IN_MSGS,
                'raw_value' => $icmpInMsgs,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'icmp_in_msgs',
                    'source' => 'ICMP-MIB::icmpInMsgs',
                ]),
            ];
        }

        if ($icmpOutMsgs !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $icmpOutMsgs,
                unit: 'messages'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'network',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('ICMP', 'Out Messages', 'messages')
                ),
                'sensor_type' => 'icmp_out_msgs',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::ICMP_OUT_MSGS,
                'raw_value' => $icmpOutMsgs,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'icmp_out_msgs',
                    'source' => 'ICMP-MIB::icmpOutMsgs',
                ]),
            ];
        }

        if ($icmpInErrors !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $icmpInErrors,
                unit: 'errors'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'network',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('ICMP', 'In Errors', 'errors')
                ),
                'sensor_type' => 'icmp_in_errors',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::ICMP_IN_ERRORS,
                'raw_value' => $icmpInErrors,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'icmp_in_errors',
                    'source' => 'ICMP-MIB::icmpInErrors',
                ]),
            ];
        }

        if ($icmpOutErrors !== null) {
            $normalized = $this->normalizer->normalize(
                (float) $icmpOutErrors,
                unit: 'errors'
            );

            if ($normalized === null) {
                return $sensors;
            }

            $sensors[] = [
                'sensor_class' => 'network',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->formatter->systemMetric('ICMP', 'Out Errors', 'errors')
                ),
                'sensor_type' => 'icmp_out_errors',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => 0,
                'oid' => self::ICMP_OUT_ERRORS,
                'raw_value' => $icmpOutErrors,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'universal_system',
                    'sensor_type' => 'icmp_out_errors',
                    'source' => 'ICMP-MIB::icmpOutErrors',
                ]),
            ];
        }

        return $sensors;
    }

    /**
     * Check if storage type matches allowed types
     */
    private function isStorageType(?string $typeOid, array $allowedTypes): bool
    {
        if ($typeOid === null) {
            return false;
        }

        $typeMap = [
            self::STORAGE_TYPE_FIXED_DISK => 'disk',
            self::STORAGE_TYPE_REMOVABLE_DISK => 'disk',
            self::STORAGE_TYPE_MEMORY => 'memory',
            self::STORAGE_TYPE_VIRTUAL_MEMORY => 'memory',
        ];

        return in_array($typeMap[$typeOid] ?? null, $allowedTypes, true);
    }

    /**
     * Sanitize component name for use in sensor names
     */
    private function sanitizeComponentName(string $name): string
    {
        // Remove common path prefixes
        $name = preg_replace('@^.*[/\\\\]@', '', (string) $name);
        
        // Keep only alphanumeric, spaces, dashes, underscores
        $name = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $name);
        
        // Trim to reasonable length
        $name = mb_substr(trim($name), 0, 64);
        
        return $name;
    }
}
