<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;

/**
 * F5 BIG-IP Virtual Server Discovery Module
 *
 * Discovers virtual servers (listening endpoints) on F5 BIG-IP devices.
 * Virtual servers represent the endpoints where clients connect;
 * they listen on specific IP addresses and ports, directing traffic to pools.
 *
 * MIB: f5networks-LOAD-BAL-SYSTEM.mib
 * OID: 1.3.6.1.4.1.3375.2.1.1.3 (ltmVsModule)
 * Table: 1.3.6.1.4.1.3375.2.1.1.3.1 (ltmVsStatusTable)
 *
 * Virtual Server Index Structure:
 * - Root: ltmVsIndex
 * - Status Table Entry: ltmVsStatusEntry (row per virtual server)
 * - Name indexed by: vsName (string)
 *
 * Discovered Metrics per Virtual Server:
 * - Status: Enabled/Disabled/Inactive state
 * - Connections: Current client connections
 * - Throughput: Bytes per second (in/out)
 * - Traffic: Total octets (in/out)
 * - Member Pool: Associated pool name
 *
 * Discovery Strategy:
 * 1. Verify device is F5 (sysObjectID contains "3375")
 * 2. Query virtual server status table (OID .1.3.6.1.4.1.3375.2.1.1.3.1)
 * 3. Enumerate all virtual servers
 * 4. Collect metrics for each virtual server
 * 5. Create sensors for status, connections, throughput
 *
 * Note: Virtual server enumeration uses string indices (names)
 * in the status table, requiring special handling for SNMP walks.
 */
final readonly class F5VirtualServerDiscoveryModule implements DiscoveryModuleInterface
{
    private const string VS_INDEX = '1.3.6.1.4.1.3375.2.1.1.3.1.1';
    private const string VS_STATUS = '1.3.6.1.4.1.3375.2.1.1.3.1.2';
    private const string VS_ENABLED_STATUS = '1.3.6.1.4.1.3375.2.1.1.3.1.3';
    private const string VS_STAT_CURRENT_CONNS = '1.3.6.1.4.1.3375.2.1.1.3.2.1.5';
    private const string VS_STAT_TOTAL_CONNS = '1.3.6.1.4.1.3375.2.1.1.3.2.1.6';
    private const string VS_STAT_BYTES_IN = '1.3.6.1.4.1.3375.2.1.1.3.2.1.2';
    private const string VS_STAT_BYTES_OUT = '1.3.6.1.4.1.3375.2.1.1.3.2.1.3';
    private const string VS_STAT_PACKETS_IN = '1.3.6.1.4.1.3375.2.1.1.3.2.1.8';
    private const string VS_STAT_PACKETS_OUT = '1.3.6.1.4.1.3375.2.1.1.3.2.1.9';

    // Status constants
    private const array VS_STATUS_MAP = [
        0 => 'unknown',
        1 => 'green',      // Healthy
        2 => 'yellow',     // Degraded
        3 => 'red',        // Down
        4 => 'blue',       // Offline
    ];

    private const array VS_ENABLED_MAP = [
        0 => 'unknown',
        1 => 'enabled',
        2 => 'disabled',
        3 => 'inactive',
    ];

    public function __construct(
        private NormalizerInterface $normalizer,
    ) {
    }

    public function name(): string
    {
        return 'F5VirtualServerDiscovery';
    }

    /**
     * Check if this is an F5 device with virtual server support
     */
    public function supports(DiscoveryContext $context): bool
    {
        // Verify device is F5
        if (!$this->isF5Device($context)) {
            return false;
        }

        // Check if virtual server table exists
        try {
            $vsTable = $context->snmp()->walk(self::VS_INDEX);
            return $vsTable !== [];
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Discover virtual servers on F5 device
     */
    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            // Walk virtual server index/names
            $vsIndexes = $context->snmp()->walk(self::VS_INDEX);

            if ($vsIndexes === []) {
                return [];
            }

            // Virtual servers are indexed by name, extract names from OID
            foreach ($vsIndexes as $vsName) {
                $vsName = trim((string) $vsName);
                // Filter out internal/placeholder names
                if ($vsName === '' || $vsName === '0') {
                    continue;
                }
                if (str_starts_with($vsName, '__internal__')) {
                    continue;
                }

                try {
                    // Extract VS name from OID path if needed
                    // OID format: 1.3.6.1.4.1.3375.2.1.1.3.1.1.<length>.<name_bytes>
                    // In some cases, the name is returned directly

                    // Get status
                    $vsStatus = $this->getVsStatus($context, $vsName);
                    $vsEnabled = $this->getVsEnabled($context, $vsName);

                    // Virtual Server Status Sensor
                    $statusValue = self::VS_STATUS_MAP[$vsStatus] ?? 'unknown';
                    $enabledValue = self::VS_ENABLED_MAP[$vsEnabled] ?? 'unknown';

                    $statusSensor = [
                        'sensor_type' => 'f5_vs_status',
                        'sensor_class' => 'virtual_server_status',
                        'sensor_name' => "{$vsName} - Status",
                        'oid' => self::VS_STATUS . ".{$vsName}",
                        'unit' => 'status',
                        'raw_value' => $statusValue,
                        'description' => "F5 Virtual Server {$vsName} - Health: {$statusValue}, State: {$enabledValue}",
                    ];
                    $normalizedStatus = $this->normalizer->normalize($statusSensor);
                    if ($normalizedStatus) {
                        $sensors[] = $normalizedStatus;
                    }

                    // Get current connections
                    $currentConns = $this->getVsStat($context, $vsName, self::VS_STAT_CURRENT_CONNS);
                    if ($currentConns !== null) {
                        $connsSensor = [
                            'sensor_type' => 'f5_vs_current_connections',
                            'sensor_class' => 'virtual_server_connections',
                            'sensor_name' => "{$vsName} - Current Connections",
                            'oid' => self::VS_STAT_CURRENT_CONNS . ".{$vsName}",
                            'unit' => 'connections',
                            'raw_value' => $currentConns,
                            'description' => "Active connections on F5 Virtual Server {$vsName}",
                        ];
                        $normalizedConns = $this->normalizer->normalize($connsSensor);
                        if ($normalizedConns) {
                            $sensors[] = $normalizedConns;
                        }
                    }

                    // Total connections
                    $totalConns = $this->getVsStat($context, $vsName, self::VS_STAT_TOTAL_CONNS);
                    if ($totalConns !== null) {
                        $totalSensor = [
                            'sensor_type' => 'f5_vs_total_connections',
                            'sensor_class' => 'virtual_server_connections',
                            'sensor_name' => "{$vsName} - Total Connections",
                            'oid' => self::VS_STAT_TOTAL_CONNS . ".{$vsName}",
                            'unit' => 'connections',
                            'raw_value' => $totalConns,
                            'description' => "Total connections on F5 Virtual Server {$vsName}",
                        ];
                        $normalizedTotal = $this->normalizer->normalize($totalSensor);
                        if ($normalizedTotal) {
                            $sensors[] = $normalizedTotal;
                        }
                    }

                    // Bytes In
                    $bytesIn = $this->getVsStat($context, $vsName, self::VS_STAT_BYTES_IN);
                    if ($bytesIn !== null) {
                        $bytesInSensor = [
                            'sensor_type' => 'f5_vs_bytes_in',
                            'sensor_class' => 'virtual_server_traffic',
                            'sensor_name' => "{$vsName} - Bytes In",
                            'oid' => self::VS_STAT_BYTES_IN . ".{$vsName}",
                            'unit' => 'bytes',
                            'raw_value' => $bytesIn,
                            'description' => "Incoming traffic on F5 Virtual Server {$vsName}",
                        ];
                        $normalizedBytesIn = $this->normalizer->normalize($bytesInSensor);
                        if ($normalizedBytesIn) {
                            $sensors[] = $normalizedBytesIn;
                        }
                    }

                    // Bytes Out
                    $bytesOut = $this->getVsStat($context, $vsName, self::VS_STAT_BYTES_OUT);
                    if ($bytesOut !== null) {
                        $bytesOutSensor = [
                            'sensor_class' => 'virtual_server_traffic',
                            'sensor_name' => "{$vsName} - Bytes Out",
                            'oid' => self::VS_STAT_BYTES_OUT . ".{$vsName}",
                            'unit' => 'bytes',
                            'raw_value' => $bytesOut,
                            'description' => "Outgoing traffic on F5 Virtual Server {$vsName}",
                        ];
                        $normalizedBytesOut = $this->normalizer->normalize($bytesOutSensor);
                        if ($normalizedBytesOut) {
                            $sensors[] = $normalizedBytesOut;
                        }
                    }

                    // Packets In
                    $packetsIn = $this->getVsStat($context, $vsName, self::VS_STAT_PACKETS_IN);
                    if ($packetsIn !== null) {
                        $packetsInSensor = [
                            'sensor_type' => 'f5_vs_packets_in',
                            'sensor_class' => 'virtual_server_packets',
                            'sensor_name' => "{$vsName} - Packets In",
                            'oid' => self::VS_STAT_PACKETS_IN . ".{$vsName}",
                            'unit' => 'packets',
                            'raw_value' => $packetsIn,
                            'description' => "Incoming packets on F5 Virtual Server {$vsName}",
                        ];
                        $normalizedPacketsIn = $this->normalizer->normalize($packetsInSensor);
                        if ($normalizedPacketsIn) {
                            $sensors[] = $normalizedPacketsIn;
                        }
                    }

                    // Packets Out
                    $packetsOut = $this->getVsStat($context, $vsName, self::VS_STAT_PACKETS_OUT);
                    if ($packetsOut !== null) {
                        $packetsOutSensor = [
                            'sensor_type' => 'f5_vs_packets_out',
                            'sensor_class' => 'virtual_server_packets',
                            'sensor_name' => "{$vsName} - Packets Out",
                            'oid' => self::VS_STAT_PACKETS_OUT . ".{$vsName}",
                            'unit' => 'packets',
                            'raw_value' => $packetsOut,
                            'description' => "Outgoing packets on F5 Virtual Server {$vsName}",
                        ];
                        $normalizedPacketsOut = $this->normalizer->normalize($packetsOutSensor);
                        if ($normalizedPacketsOut) {
                            $sensors[] = $normalizedPacketsOut;
                        }
                    }
                } catch (\Exception) {
                    // Skip this VS on error, continue with next
                    continue;
                }
            }
        } catch (\Exception) {
            // Return empty array on SNMP error
            return [];
        }

        return $sensors;
    }

    /**
     * Check if device is F5
     */
    private function isF5Device(DiscoveryContext $context): bool
    {
        try {
            $sysObjectID = $context->snmp()->get('1.3.6.1.2.1.1.2.0');
            return !in_array($sysObjectID, [null, '', '0'], true) && str_contains($sysObjectID, '3375');
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Get virtual server status
     */
    private function getVsStatus(DiscoveryContext $context, string $vsName): ?int
    {
        try {
            $oid = self::VS_STATUS . ".{$vsName}";
            $value = $context->snmp()->get($oid);
            return $value !== null ? (int) $value : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get virtual server enabled status
     */
    private function getVsEnabled(DiscoveryContext $context, string $vsName): ?int
    {
        try {
            $oid = self::VS_ENABLED_STATUS . ".{$vsName}";
            $value = $context->snmp()->get($oid);
            return $value !== null ? (int) $value : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get virtual server statistic
     */
    private function getVsStat(DiscoveryContext $context, string $vsName, string $statOid): ?int
    {
        try {
            $oid = "{$statOid}.{$vsName}";
            $value = $context->snmp()->get($oid);
            return $value !== null ? (int) $value : null;
        } catch (\Exception) {
            return null;
        }
    }
}
