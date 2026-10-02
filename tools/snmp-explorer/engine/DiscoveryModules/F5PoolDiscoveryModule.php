<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;

/**
 * F5 BIG-IP Pool Discovery Module
 *
 * Discovers server pools on F5 BIG-IP devices.
 * Pools are logical groupings of backend servers that virtual servers
 * distribute traffic to. Each pool has a set of members and collective metrics.
 *
 * MIB: f5networks-LOAD-BAL-SYSTEM.mib
 * OID: 1.3.6.1.4.1.3375.2.1.1.7 (ltmPoolModule)
 * Table: 1.3.6.1.4.1.3375.2.1.1.7.1 (ltmPoolStatusTable)
 *
 * Pool Index Structure:
 * - Root: ltmPoolIndex
 * - Status Table Entry: ltmPoolStatusEntry (row per pool)
 * - Name indexed by: poolName (string)
 *
 * Discovered Metrics per Pool:
 * - Status: Active/Inactive/Unavailable state
 * - Member Count: Number of members in pool
 * - Current Connections: Active client connections
 * - Total Connections: Cumulative connections
 * - Throughput: Bytes per second (in/out)
 *
 * Discovery Strategy:
 * 1. Verify device is F5 (sysObjectID contains "3375")
 * 2. Query pool status table (OID .1.3.6.1.4.1.3375.2.1.1.7.1)
 * 3. Enumerate all pools
 * 4. Collect metrics for each pool
 * 5. Create sensors for status, member count, connections, throughput
 *
 * Note: Pools are indexed by name in the status table,
 * requiring string-based OID construction.
 */
final readonly class F5PoolDiscoveryModule implements DiscoveryModuleInterface
{
    private const string POOL_INDEX = '1.3.6.1.4.1.3375.2.1.1.7.1.1';
    private const string POOL_STATUS = '1.3.6.1.4.1.3375.2.1.1.7.1.2';
    private const string POOL_ENABLED = '1.3.6.1.4.1.3375.2.1.1.7.1.3';
    private const string POOL_ACTIVE_MEMBERS = '1.3.6.1.4.1.3375.2.1.1.7.1.4';
    private const string POOL_STAT_CURRENT_CONNS = '1.3.6.1.4.1.3375.2.1.1.7.2.1.5';
    private const string POOL_STAT_TOTAL_CONNS = '1.3.6.1.4.1.3375.2.1.1.7.2.1.6';
    private const string POOL_STAT_BYTES_IN = '1.3.6.1.4.1.3375.2.1.1.7.2.1.2';
    private const string POOL_STAT_BYTES_OUT = '1.3.6.1.4.1.3375.2.1.1.7.2.1.3';
    private const string POOL_STAT_PACKETS_IN = '1.3.6.1.4.1.3375.2.1.1.7.2.1.8';
    private const string POOL_STAT_PACKETS_OUT = '1.3.6.1.4.1.3375.2.1.1.7.2.1.9';

    // Status constants
    private const array POOL_STATUS_MAP = [
        0 => 'unknown',
        1 => 'green',      // Healthy
        2 => 'yellow',     // Degraded
        3 => 'red',        // Down
        4 => 'blue',       // Offline
    ];

    private const array POOL_ENABLED_MAP = [
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
        return 'F5PoolDiscovery';
    }

    /**
     * Check if this is an F5 device with pool support
     */
    public function supports(DiscoveryContext $context): bool
    {
        // Verify device is F5
        if (!$this->isF5Device($context)) {
            return false;
        }

        // Check if pool table exists
        try {
            $poolTable = $context->snmp()->walk(self::POOL_INDEX);
            return $poolTable !== [];
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Discover pools on F5 device
     */
    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            // Walk pool index/names
            $poolIndexes = $context->snmp()->walk(self::POOL_INDEX);

            if ($poolIndexes === []) {
                return [];
            }

            // Pools are indexed by name
            foreach ($poolIndexes as $poolName) {
                $poolName = trim((string) $poolName);
                // Filter out empty names
                if ($poolName === '') {
                    continue;
                }
                if ($poolName === '0') {
                    continue;
                }

                try {
                    // Get pool status and enabled state
                    $poolStatus = $this->getPoolStatus($context, $poolName);
                    $poolEnabled = $this->getPoolEnabled($context, $poolName);
                    $activeMembers = $this->getPoolStat($context, $poolName, self::POOL_ACTIVE_MEMBERS);

                    // Pool Status Sensor
                    $statusValue = self::POOL_STATUS_MAP[$poolStatus] ?? 'unknown';
                    $enabledValue = self::POOL_ENABLED_MAP[$poolEnabled] ?? 'unknown';
                    $memberCount = $activeMembers ?? 0;

                    $statusSensor = [
                        'sensor_type' => 'f5_pool_status',
                        'sensor_class' => 'pool_status',
                        'sensor_name' => "{$poolName} - Status",
                        'oid' => self::POOL_STATUS . ".{$poolName}",
                        'unit' => 'status',
                        'raw_value' => $statusValue,
                        'description' => "F5 Pool {$poolName} - Health: {$statusValue}, State: {$enabledValue}, Members: {$memberCount}",
                    ];
                    $normalizedStatus = $this->normalizer->normalize($statusSensor);
                    if ($normalizedStatus) {
                        $sensors[] = $normalizedStatus;
                    }

                    // Active Members Count
                    if ($activeMembers !== null) {
                        $membersSensor = [
                            'sensor_type' => 'f5_pool_active_members',
                            'sensor_class' => 'pool_members',
                            'sensor_name' => "{$poolName} - Active Members",
                            'oid' => self::POOL_ACTIVE_MEMBERS . ".{$poolName}",
                            'unit' => 'members',
                            'raw_value' => $activeMembers,
                            'description' => "Number of active members in F5 Pool {$poolName}",
                        ];
                        $normalizedMembers = $this->normalizer->normalize($membersSensor);
                        if ($normalizedMembers) {
                            $sensors[] = $normalizedMembers;
                        }
                    }

                    // Current Connections
                    $currentConns = $this->getPoolStat($context, $poolName, self::POOL_STAT_CURRENT_CONNS);
                    if ($currentConns !== null) {
                        $currentSensor = [
                            'sensor_type' => 'f5_pool_current_connections',
                            'sensor_class' => 'pool_connections',
                            'sensor_name' => "{$poolName} - Current Connections",
                            'oid' => self::POOL_STAT_CURRENT_CONNS . ".{$poolName}",
                            'unit' => 'connections',
                            'raw_value' => $currentConns,
                            'description' => "Active connections in F5 Pool {$poolName}",
                        ];
                        $normalizedCurrent = $this->normalizer->normalize($currentSensor);
                        if ($normalizedCurrent) {
                            $sensors[] = $normalizedCurrent;
                        }
                    }

                    // Total Connections
                    $totalConns = $this->getPoolStat($context, $poolName, self::POOL_STAT_TOTAL_CONNS);
                    if ($totalConns !== null) {
                        $totalSensor = [
                            'sensor_type' => 'f5_pool_total_connections',
                            'sensor_class' => 'pool_connections',
                            'sensor_name' => "{$poolName} - Total Connections",
                            'oid' => self::POOL_STAT_TOTAL_CONNS . ".{$poolName}",
                            'unit' => 'connections',
                            'raw_value' => $totalConns,
                            'description' => "Cumulative connections in F5 Pool {$poolName}",
                        ];
                        $normalizedTotal = $this->normalizer->normalize($totalSensor);
                        if ($normalizedTotal) {
                            $sensors[] = $normalizedTotal;
                        }
                    }

                    // Bytes In
                    $bytesIn = $this->getPoolStat($context, $poolName, self::POOL_STAT_BYTES_IN);
                    if ($bytesIn !== null) {
                        $bytesInSensor = [
                            'sensor_type' => 'f5_pool_bytes_in',
                            'sensor_class' => 'pool_traffic',
                            'sensor_name' => "{$poolName} - Bytes In",
                            'oid' => self::POOL_STAT_BYTES_IN . ".{$poolName}",
                            'unit' => 'bytes',
                            'raw_value' => $bytesIn,
                            'description' => "Incoming traffic in F5 Pool {$poolName}",
                        ];
                        $normalizedBytesIn = $this->normalizer->normalize($bytesInSensor);
                        if ($normalizedBytesIn) {
                            $sensors[] = $normalizedBytesIn;
                        }
                    }

                    // Bytes Out
                    $bytesOut = $this->getPoolStat($context, $poolName, self::POOL_STAT_BYTES_OUT);
                    if ($bytesOut !== null) {
                        $bytesOutSensor = [
                            'sensor_type' => 'f5_pool_bytes_out',
                            'sensor_class' => 'pool_traffic',
                            'sensor_name' => "{$poolName} - Bytes Out",
                            'oid' => self::POOL_STAT_BYTES_OUT . ".{$poolName}",
                            'unit' => 'bytes',
                            'raw_value' => $bytesOut,
                            'description' => "Outgoing traffic in F5 Pool {$poolName}",
                        ];
                        $normalizedBytesOut = $this->normalizer->normalize($bytesOutSensor);
                        if ($normalizedBytesOut) {
                            $sensors[] = $normalizedBytesOut;
                        }
                    }

                    // Packets In
                    $packetsIn = $this->getPoolStat($context, $poolName, self::POOL_STAT_PACKETS_IN);
                    if ($packetsIn !== null) {
                        $packetsInSensor = [
                            'sensor_type' => 'f5_pool_packets_in',
                            'sensor_class' => 'pool_packets',
                            'sensor_name' => "{$poolName} - Packets In",
                            'oid' => self::POOL_STAT_PACKETS_IN . ".{$poolName}",
                            'unit' => 'packets',
                            'raw_value' => $packetsIn,
                            'description' => "Incoming packets in F5 Pool {$poolName}",
                        ];
                        $normalizedPacketsIn = $this->normalizer->normalize($packetsInSensor);
                        if ($normalizedPacketsIn) {
                            $sensors[] = $normalizedPacketsIn;
                        }
                    }

                    // Packets Out
                    $packetsOut = $this->getPoolStat($context, $poolName, self::POOL_STAT_PACKETS_OUT);
                    if ($packetsOut !== null) {
                        $packetsOutSensor = [
                            'sensor_type' => 'f5_pool_packets_out',
                            'sensor_class' => 'pool_packets',
                            'sensor_name' => "{$poolName} - Packets Out",
                            'oid' => self::POOL_STAT_PACKETS_OUT . ".{$poolName}",
                            'unit' => 'packets',
                            'raw_value' => $packetsOut,
                            'description' => "Outgoing packets in F5 Pool {$poolName}",
                        ];
                        $normalizedPacketsOut = $this->normalizer->normalize($packetsOutSensor);
                        if ($normalizedPacketsOut) {
                            $sensors[] = $normalizedPacketsOut;
                        }
                    }
                } catch (\Exception) {
                    // Skip this pool on error, continue with next
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
     * Get pool status
     */
    private function getPoolStatus(DiscoveryContext $context, string $poolName): ?int
    {
        try {
            $oid = self::POOL_STATUS . ".{$poolName}";
            $value = $context->snmp()->get($oid);
            return $value !== null ? (int) $value : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get pool enabled status
     */
    private function getPoolEnabled(DiscoveryContext $context, string $poolName): ?int
    {
        try {
            $oid = self::POOL_ENABLED . ".{$poolName}";
            $value = $context->snmp()->get($oid);
            return $value !== null ? (int) $value : null;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get pool statistic
     */
    private function getPoolStat(DiscoveryContext $context, string $poolName, string $statOid): ?int
    {
        try {
            $oid = "{$statOid}.{$poolName}";
            $value = $context->snmp()->get($oid);
            return $value !== null ? (int) $value : null;
        } catch (\Exception) {
            return null;
        }
    }
}
