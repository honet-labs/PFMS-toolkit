<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;

/**
 * F5 BIG-IP Pool Member Discovery Module
 *
 * Discovers individual pool members (backend servers) on F5 BIG-IP devices.
 * Pool members are the actual servers in a pool that handle client connections.
 * Each member has its own health status and connection statistics.
 *
 * MIB: f5networks-LOAD-BAL-SYSTEM.mib
 * OID: 1.3.6.1.4.1.3375.2.1.1.8 (ltmPoolMemberModule)
 * Table: 1.3.6.1.4.1.3375.2.1.1.8.1 (ltmPoolMemberStatusTable)
 *
 * Pool Member Index Structure:
 * - Compound index: poolName, memberIP, memberPort
 * - Status Table Entry: ltmPoolMemberStatusEntry (row per member)
 * - Indexed by: pool name + member IP + port
 *
 * Discovered Metrics per Pool Member:
 * - Status: Up/Down/Draining state
 * - Current Connections: Active connections to this member
 * - Total Connections: Cumulative connections
 * - Throughput: Bytes per second (in/out)
 * - Connection Limit: Max allowed connections
 *
 * Discovery Strategy:
 * 1. Verify device is F5 (sysObjectID contains "3375")
 * 2. Query pool member status table (OID .1.3.6.1.4.1.3375.2.1.1.8.1)
 * 3. Enumerate all pool members (requires compound index handling)
 * 4. Collect metrics for each member
 * 5. Create sensors for status, connections, throughput
 *
 * Note: Pool members use compound indices (pool, IP, port),
 * making enumeration more complex than other tables.
 * This module handles multiple potential members per pool.
 *
 * Expected Scale: 10-500+ members per F5 device (depends on configuration)
 */
final readonly class F5PoolMemberDiscoveryModule implements DiscoveryModuleInterface
{
    private const string MEMBER_INDEX = '1.3.6.1.4.1.3375.2.1.1.8.1.1';
    private const string MEMBER_STATUS = '1.3.6.1.4.1.3375.2.1.1.8.1.2';
    private const string MEMBER_ENABLED = '1.3.6.1.4.1.3375.2.1.1.8.1.3';
    private const string MEMBER_CONN_LIMIT = '1.3.6.1.4.1.3375.2.1.1.8.1.10';
    private const string MEMBER_STAT_CURRENT_CONNS = '1.3.6.1.4.1.3375.2.1.1.8.2.1.5';
    private const string MEMBER_STAT_TOTAL_CONNS = '1.3.6.1.4.1.3375.2.1.1.8.2.1.6';
    private const string MEMBER_STAT_BYTES_IN = '1.3.6.1.4.1.3375.2.1.1.8.2.1.2';
    private const string MEMBER_STAT_BYTES_OUT = '1.3.6.1.4.1.3375.2.1.1.8.2.1.3';
    private const string MEMBER_STAT_PACKETS_IN = '1.3.6.1.4.1.3375.2.1.1.8.2.1.8';
    private const string MEMBER_STAT_PACKETS_OUT = '1.3.6.1.4.1.3375.2.1.1.8.2.1.9';

    // Status constants
    private const array MEMBER_STATUS_MAP = [
        0 => 'unknown',
        1 => 'up',
        2 => 'down',
        3 => 'disabled',
        4 => 'draining',     // Connection draining
        5 => 'unavailable',
    ];

    private const array MEMBER_ENABLED_MAP = [
        0 => 'unknown',
        1 => 'enabled',
        2 => 'disabled',
    ];

    public function __construct(
        private NormalizerInterface $normalizer,
    ) {
    }

    public function name(): string
    {
        return 'F5PoolMemberDiscovery';
    }

    /**
     * Check if this is an F5 device with pool member support
     */
    public function supports(DiscoveryContext $context): bool
    {
        // Verify device is F5
        if (!$this->isF5Device($context)) {
            return false;
        }

        // Check if pool member table exists
        try {
            $memberTable = $context->snmp()->walk(self::MEMBER_INDEX);
            return $memberTable !== [];
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Discover pool members on F5 device
     */
    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            // Walk pool member index/names (compound index: pool.ip.port)
            $memberIndexes = $context->snmp()->walk(self::MEMBER_INDEX);

            if ($memberIndexes === []) {
                return [];
            }

            // Pool members are indexed by compound key: pool name, IP, port
            // Example: "pool1.10.0.0.1.80" means pool1, member 10.0.0.1:80
            foreach ($memberIndexes as $memberName) {
                $memberName = trim((string) $memberName);
                // Filter out empty names
                if ($memberName === '') {
                    continue;
                }
                if ($memberName === '0') {
                    continue;
                }

                try {
                    // Parse compound index to extract details
                    $parts = $this->parseCompoundIndex($memberName);
                    if ($parts === null) {
                        continue;
                    }
                    if ($parts === []) {
                        continue;
                    }

                    ['pool' => $poolName, 'ip' => $memberIp, 'port' => $memberPort] = $parts;
                    $memberLabel = "{$poolName}/{$memberIp}:{$memberPort}";

                    // Get member status and enabled state
                    $memberStatus = $this->getMemberStatus($context, $memberName);
                    $memberEnabled = $this->getMemberEnabled($context, $memberName);
                    $connLimit = $this->getMemberConnLimit($context, $memberName);

                    // Member Status Sensor
                    $statusValue = self::MEMBER_STATUS_MAP[$memberStatus] ?? 'unknown';
                    $enabledValue = self::MEMBER_ENABLED_MAP[$memberEnabled] ?? 'unknown';

                    $statusSensor = [
                        'sensor_type' => 'f5_pool_member_status',
                        'sensor_class' => 'pool_member_status',
                        'sensor_name' => "{$memberLabel} - Status",
                        'oid' => self::MEMBER_STATUS . ".{$memberName}",
                        'unit' => 'status',
                        'raw_value' => $statusValue,
                        'description' => "F5 Pool Member {$memberLabel} - Health: {$statusValue}, State: {$enabledValue}",
                    ];
                    $normalizedStatus = $this->normalizer->normalize($statusSensor);
                    if ($normalizedStatus) {
                        $sensors[] = $normalizedStatus;
                    }

                    // Current Connections
                    $currentConns = $this->getMemberStat($context, $memberName, self::MEMBER_STAT_CURRENT_CONNS);
                    if ($currentConns !== null) {
                        $connsSensor = [
                            'sensor_type' => 'f5_pool_member_current_connections',
                            'sensor_class' => 'pool_member_connections',
                            'sensor_name' => "{$memberLabel} - Current Connections",
                            'oid' => self::MEMBER_STAT_CURRENT_CONNS . ".{$memberName}",
                            'unit' => 'connections',
                            'raw_value' => $currentConns,
                            'description' => "Active connections on F5 Pool Member {$memberLabel}",
                        ];
                        $normalizedConns = $this->normalizer->normalize($connsSensor);
                        if ($normalizedConns) {
                            $sensors[] = $normalizedConns;
                        }
                    }

                    // Total Connections
                    $totalConns = $this->getMemberStat($context, $memberName, self::MEMBER_STAT_TOTAL_CONNS);
                    if ($totalConns !== null) {
                        $totalSensor = [
                            'sensor_type' => 'f5_pool_member_total_connections',
                            'sensor_class' => 'pool_member_connections',
                            'sensor_name' => "{$memberLabel} - Total Connections",
                            'oid' => self::MEMBER_STAT_TOTAL_CONNS . ".{$memberName}",
                            'unit' => 'connections',
                            'raw_value' => $totalConns,
                            'description' => "Cumulative connections on F5 Pool Member {$memberLabel}",
                        ];
                        $normalizedTotal = $this->normalizer->normalize($totalSensor);
                        if ($normalizedTotal) {
                            $sensors[] = $normalizedTotal;
                        }
                    }

                    // Bytes In
                    $bytesIn = $this->getMemberStat($context, $memberName, self::MEMBER_STAT_BYTES_IN);
                    if ($bytesIn !== null) {
                        $bytesInSensor = [
                            'sensor_type' => 'f5_pool_member_bytes_in',
                            'sensor_class' => 'pool_member_traffic',
                            'sensor_name' => "{$memberLabel} - Bytes In",
                            'oid' => self::MEMBER_STAT_BYTES_IN . ".{$memberName}",
                            'unit' => 'bytes',
                            'raw_value' => $bytesIn,
                            'description' => "Incoming traffic on F5 Pool Member {$memberLabel}",
                        ];
                        $normalizedBytesIn = $this->normalizer->normalize($bytesInSensor);
                        if ($normalizedBytesIn) {
                            $sensors[] = $normalizedBytesIn;
                        }
                    }

                    // Bytes Out
                    $bytesOut = $this->getMemberStat($context, $memberName, self::MEMBER_STAT_BYTES_OUT);
                    if ($bytesOut !== null) {
                        $bytesOutSensor = [
                            'sensor_type' => 'f5_pool_member_bytes_out',
                            'sensor_class' => 'pool_member_traffic',
                            'sensor_name' => "{$memberLabel} - Bytes Out",
                            'oid' => self::MEMBER_STAT_BYTES_OUT . ".{$memberName}",
                            'unit' => 'bytes',
                            'raw_value' => $bytesOut,
                            'description' => "Outgoing traffic on F5 Pool Member {$memberLabel}",
                        ];
                        $normalizedBytesOut = $this->normalizer->normalize($bytesOutSensor);
                        if ($normalizedBytesOut) {
                            $sensors[] = $normalizedBytesOut;
                        }
                    }

                    // Packets In
                    $packetsIn = $this->getMemberStat($context, $memberName, self::MEMBER_STAT_PACKETS_IN);
                    if ($packetsIn !== null) {
                        $packetsInSensor = [
                            'sensor_type' => 'f5_pool_member_packets_in',
                            'sensor_class' => 'pool_member_packets',
                            'sensor_name' => "{$memberLabel} - Packets In",
                            'oid' => self::MEMBER_STAT_PACKETS_IN . ".{$memberName}",
                            'unit' => 'packets',
                            'raw_value' => $packetsIn,
                            'description' => "Incoming packets on F5 Pool Member {$memberLabel}",
                        ];
                        $normalizedPacketsIn = $this->normalizer->normalize($packetsInSensor);
                        if ($normalizedPacketsIn) {
                            $sensors[] = $normalizedPacketsIn;
                        }
                    }

                    // Packets Out
                    $packetsOut = $this->getMemberStat($context, $memberName, self::MEMBER_STAT_PACKETS_OUT);
                    if ($packetsOut !== null) {
                        $packetsOutSensor = [
                            'sensor_type' => 'f5_pool_member_packets_out',
                            'sensor_class' => 'pool_member_packets',
                            'sensor_name' => "{$memberLabel} - Packets Out",
                            'oid' => self::MEMBER_STAT_PACKETS_OUT . ".{$memberName}",
                            'unit' => 'packets',
                            'raw_value' => $packetsOut,
                            'description' => "Outgoing packets on F5 Pool Member {$memberLabel}",
                        ];
                        $normalizedPacketsOut = $this->normalizer->normalize($packetsOutSensor);
                        if ($normalizedPacketsOut) {
                            $sensors[] = $normalizedPacketsOut;
                        }
                    }
                } catch (\Exception $e) {
                    // Skip this member on error, continue with next
                    continue;
                }
            }
        } catch (\Exception $e) {
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
        $sysObjectID = $context->sysObjectID();
        return $sysObjectID !== '' && str_contains($sysObjectID, '3375');
    }

    /**
     * Parse compound index into pool, IP, and port
     * Format: "poolName.octets.octets.octets.octets.port"
     * Example: "pool1.10.0.0.1.80"
     */
    private function parseCompoundIndex(string $index): ?array
    {
        // Try to split by dots to extract pool, IP, port
        $parts = explode('.', $index);

        if (count($parts) < 6) {
            return null;
        }

        // Last element is port
        $port = array_pop($parts);

        // Last 4 elements are IP octets
        $octets = array_splice($parts, -4);
        $ip = implode('.', $octets);

        // Remaining is pool name
        $poolName = implode('.', $parts);

        if ($poolName === '' || $poolName === '0' || ($ip === '' || $ip === '0') || empty($port)) {
            return null;
        }

        return [
            'pool' => $poolName,
            'ip' => $ip,
            'port' => $port,
        ];
    }

    /**
     * Get pool member status
     */
    private function getMemberStatus(DiscoveryContext $context, string $memberName): ?int
    {
        try {
            $oid = self::MEMBER_STATUS . ".{$memberName}";
            $value = $context->snmp()->get($oid);
            return $value !== null ? (int) $value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get pool member enabled status
     */
    private function getMemberEnabled(DiscoveryContext $context, string $memberName): ?int
    {
        try {
            $oid = self::MEMBER_ENABLED . ".{$memberName}";
            $value = $context->snmp()->get($oid);
            return $value !== null ? (int) $value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get pool member connection limit
     */
    private function getMemberConnLimit(DiscoveryContext $context, string $memberName): ?int
    {
        try {
            $oid = self::MEMBER_CONN_LIMIT . ".{$memberName}";
            $value = $context->snmp()->get($oid);
            return $value !== null ? (int) $value : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get pool member statistic
     */
    private function getMemberStat(DiscoveryContext $context, string $memberName, string $statOid): ?int
    {
        try {
            $oid = "{$statOid}.{$memberName}";
            $value = $context->snmp()->get($oid);
            return $value !== null ? (int) $value : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
