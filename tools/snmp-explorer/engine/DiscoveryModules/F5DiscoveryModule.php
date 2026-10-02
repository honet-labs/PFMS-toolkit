<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;

/**
 * F5 Networks Load Balancer Discovery Module
 * 
 * Discovers F5-specific sensors:
 * - Virtual servers
 * - Pool members
 * - System metrics (CPU, memory)
 * - Performance indicators
 */
final class F5DiscoveryModule implements DiscoveryModuleInterface
{
    public function name(): string
    {
        return 'f5';
    }

    public function supports(DiscoveryContext $context): bool
    {
        $sysObjectId = $context->sysObjectID();
        $vendorName = strtolower($context->vendor->name());

        return str_contains($vendorName, 'f5') || str_contains($sysObjectId, '1.3.6.1.4.1.3375');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            $sensors = array_merge($sensors, $this->discoverVirtualServers($context));
        } catch (\Throwable) {
        }

        try {
            $sensors = array_merge($sensors, $this->discoverPoolMembers($context));
        } catch (\Throwable) {
        }

        try {
            $sensors = array_merge($sensors, $this->discoverSystemMetrics($context));
        } catch (\Throwable) {
        }

        return $sensors;
    }

    /**
     * Discover F5 virtual servers
     * OID: 1.3.6.1.4.1.3375.2.1.1.2 (vsTable)
     * @return array<int, array<string, mixed>>
     */
    private function discoverVirtualServers(DiscoveryContext $context): array
    {
        $sensors = [];
        $baseOid = '1.3.6.1.4.1.3375.2.1.1.2';

        try {
            $vsNames = $context->walker->walk($baseOid . '.1.1');

            foreach ($vsNames as $oid => $name) {
                if (in_array(trim((string) $name), ['', '0'], true)) {
                    continue;
                }

                $index = $this->extractIndex($oid);
                $vsName = trim((string) $name);

                $sensors[] = [
                    'sensor_class' => 'f5_virtual_server',
                    'sensor_name' => 'F5 VS - ' . $vsName,
                    'interface_name' => $vsName,
                    'oid' => $baseOid . '.2.1.' . $index,
                    'unit' => 'status',
                    'entity_index' => (int) $index,
                    'description' => 'Virtual server operational status',
                ];

                $sensors[] = [
                    'sensor_class' => 'f5_connections',
                    'sensor_name' => 'F5 VS ' . $vsName . ' - Connections',
                    'interface_name' => $vsName,
                    'oid' => $baseOid . '.3.1.' . $index,
                    'unit' => 'count',
                    'entity_index' => (int) $index,
                    'description' => 'Active connections to virtual server',
                ];

                $sensors[] = [
                    'sensor_class' => 'f5_bytes_in',
                    'sensor_name' => 'F5 VS ' . $vsName . ' - Inbound Bytes',
                    'interface_name' => $vsName,
                    'oid' => $baseOid . '.6.1.' . $index,
                    'unit' => 'bytes',
                    'entity_index' => (int) $index,
                    'description' => 'Bytes received by virtual server',
                ];

                $sensors[] = [
                    'sensor_class' => 'f5_bytes_out',
                    'sensor_name' => 'F5 VS ' . $vsName . ' - Outbound Bytes',
                    'interface_name' => $baseOid . '.7.1.' . $index,
                    'oid' => $baseOid . '.7.1.' . $index,
                    'unit' => 'bytes',
                    'entity_index' => (int) $index,
                    'description' => 'Bytes sent by virtual server',
                ];
            }
        } catch (\Throwable) {
        }

        return $sensors;
    }

    /**
     * Discover F5 pool members
     * OID: 1.3.6.1.4.1.3375.2.1.1.4 (memberTable)
     * @return array<int, array<string, mixed>>
     */
    private function discoverPoolMembers(DiscoveryContext $context): array
    {
        $sensors = [];
        $baseOid = '1.3.6.1.4.1.3375.2.1.1.4';

        try {
            $memberNames = $context->walker->walk($baseOid . '.1.1');

            foreach ($memberNames as $oid => $name) {
                if (in_array(trim((string) $name), ['', '0'], true)) {
                    continue;
                }

                $index = $this->extractIndex($oid);
                $memberName = trim((string) $name);

                $sensors[] = [
                    'sensor_class' => 'f5_pool_member',
                    'sensor_name' => 'F5 Member - ' . $memberName,
                    'interface_name' => $memberName,
                    'oid' => $baseOid . '.2.1.' . $index,
                    'unit' => 'status',
                    'entity_index' => (int) $index,
                    'description' => 'Pool member status (up/down/offline)',
                ];

                $sensors[] = [
                    'sensor_class' => 'f5_connections',
                    'sensor_name' => 'F5 ' . $memberName . ' - Connections',
                    'interface_name' => $memberName,
                    'oid' => $baseOid . '.3.1.' . $index,
                    'unit' => 'count',
                    'entity_index' => (int) $index,
                    'description' => 'Current connections to pool member',
                ];

                $sensors[] = [
                    'sensor_class' => 'f5_pkt_rate',
                    'sensor_name' => 'F5 ' . $memberName . ' - Pkt/sec',
                    'interface_name' => $memberName,
                    'oid' => $baseOid . '.4.1.' . $index,
                    'unit' => 'pps',
                    'entity_index' => (int) $index,
                    'description' => 'Packets per second to pool member',
                ];
            }
        } catch (\Throwable) {
        }

        return $sensors;
    }

    /**
     * Discover F5 system metrics
     * OID: 1.3.6.1.4.1.3375.2.1.1.1 (sysGlobals)
     * @return array<int, array<string, mixed>>
     */
    private function discoverSystemMetrics(DiscoveryContext $context): array
    {
        $sensors = [];
        try {
            $sensors[] = [
                'sensor_class' => 'f5_cpu',
                'sensor_name' => 'F5 CPU Usage',
                'interface_name' => 'system',
                'oid' => '1.3.6.1.4.1.3375.2.1.1.1.1.0',
                'unit' => '%',
                'entity_index' => 0,
                'description' => 'System CPU utilization percentage',
            ];

            $sensors[] = [
                'sensor_class' => 'f5_memory',
                'sensor_name' => 'F5 Memory Usage',
                'interface_name' => 'system',
                'oid' => '1.3.6.1.4.1.3375.2.1.1.1.2.0',
                'unit' => 'bytes',
                'entity_index' => 0,
                'description' => 'System memory usage in bytes',
            ];

            $sensors[] = [
                'sensor_class' => 'f5_throughput',
                'sensor_name' => 'F5 System Throughput',
                'interface_name' => 'system',
                'oid' => '1.3.6.1.4.1.3375.2.1.1.1.3.0',
                'unit' => 'bytes/s',
                'entity_index' => 0,
                'description' => 'System throughput in bytes per second',
            ];

            $sensors[] = [
                'sensor_class' => 'f5_system',
                'sensor_name' => 'F5 System Uptime',
                'interface_name' => 'system',
                'oid' => '1.3.6.1.4.1.3375.2.1.1.1.4.0',
                'unit' => 'timeticks',
                'entity_index' => 0,
                'description' => 'System uptime in 100ths of seconds',
            ];
        } catch (\Throwable) {
        }
        return $sensors;
    }

    private function extractIndex(string $oid): string
    {
        $parts = explode('.', $oid);
        return end($parts);
    }
}
