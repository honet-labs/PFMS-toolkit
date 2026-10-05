<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Helpers\SnmpValueHelper;
use SnmpBridge\Helpers\StringHelper;

final class FortinetDiscoveryModule implements DiscoveryModuleInterface
{
    private const string FORTINET_ROOT = '1.3.6.1.4.1.12356';
    private const string FORTIGATE_ROOT = '1.3.6.1.4.1.12356.101';

    // System Metrics
    private const string SYS_CPU = '1.3.6.1.4.1.12356.101.4.1.3.0';
    private const string SYS_MEM = '1.3.6.1.4.1.12356.101.4.1.4.0';
    private const string SYS_DISK = '1.3.6.1.4.1.12356.101.4.1.5.0';
    private const string SYS_DISK_CAPACITY = '1.3.6.1.4.1.12356.101.4.1.6.0';
    private const string SYS_SESSIONS = '1.3.6.1.4.1.12356.101.4.1.8.0';
    private const string SYS_SES_RATE = '1.3.6.1.4.1.12356.101.4.1.11.0';
    private const string SYS_LOW_MEM = '1.3.6.1.4.1.12356.101.4.1.9.0';

    // VPN Overview
    private const string VPN_IPSEC_UP_COUNT = '1.3.6.1.4.1.12356.101.12.1.1.0';
    private const string VPN_SSL_ACTIVE_TUNNELS = '1.3.6.1.4.1.12356.101.12.2.3.1.6';
    private const string VPN_SSL_LOGIN_USERS = '1.3.6.1.4.1.12356.101.12.2.3.1.2';
    private const string VPN_SSL_WEB_SESSIONS = '1.3.6.1.4.1.12356.101.12.2.3.1.4';

    // SSL-VPN Active Tunnels Table
    private const string VPN_SSL_TUNNEL_USER = '1.3.6.1.4.1.12356.101.12.2.4.1.2';
    private const string VPN_SSL_TUNNEL_IP = '1.3.6.1.4.1.12356.101.12.2.4.1.5';
    private const string VPN_SSL_TUNNEL_BYTES_IN = '1.3.6.1.4.1.12356.101.12.2.4.1.6';
    private const string VPN_SSL_TUNNEL_BYTES_OUT = '1.3.6.1.4.1.12356.101.12.2.4.1.7';

    // IPsec Tunnels Table
    private const string VPN_TUN_NAME = '1.3.6.1.4.1.12356.101.12.2.2.1.2';
    private const string VPN_TUN_STATUS = '1.3.6.1.4.1.12356.101.12.2.2.1.20';
    private const string VPN_TUN_IN_OCTETS = '1.3.6.1.4.1.12356.101.12.2.2.1.18';
    private const string VPN_TUN_OUT_OCTETS = '1.3.6.1.4.1.12356.101.12.2.2.1.19';

    // HA Cluster
    private const string HA_SYSTEM_MODE = '1.3.6.1.4.1.12356.101.13.1.1.0';

    public function __construct(
        private NormalizerInterface $normalizer,
    ) {
    }

    public function name(): string
    {
        return 'fortinet_discovery';
    }

    public function supports(DiscoveryContext $context): bool
    {
        if ($context->vendor->name() === 'Fortinet') {
            return true;
        }

        $sysObj = $context->sysObjectID();
        if ($sysObj !== '' && str_contains($sysObj, '12356')) {
            return true;
        }

        $sysDescr = $context->sysDescr();
        if ($sysDescr !== '' && preg_match('/Fortinet|FortiGate|FortiOS/i', $sysDescr) === 1) {
            return true;
        }

        return false;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        // 1. System Performance Metrics
        $this->discoverSystemMetrics($context, $sensors);

        // 2. SSL-VPN Summary Metrics
        $this->discoverSslVpnSummary($context, $sensors);

        // 3. SSL-VPN Active Tunnels Table
        $this->discoverSslVpnTunnels($context, $sensors);

        // 4. IPsec VPN Summary & Tunnels
        $this->discoverIpsecTunnels($context, $sensors);

        // 5. HA Status
        $this->discoverHaStatus($context, $sensors);

        return $sensors;
    }

    private function discoverSystemMetrics(DiscoveryContext $context, array &$sensors): void
    {
        // CPU Usage
        $cpu = SnmpValueHelper::numeric($context->walker->get(self::SYS_CPU));
        if ($cpu !== null) {
            $this->addSensor($sensors, 'FortiGate CPU Usage', 'cpu_usage', 'processor', self::SYS_CPU, $cpu, '%', [
                'source' => 'FORTINET-FORTIGATE-MIB::fgSysCpuUsage',
            ]);
        }

        // Memory Usage
        $mem = SnmpValueHelper::numeric($context->walker->get(self::SYS_MEM));
        if ($mem !== null) {
            $this->addSensor($sensors, 'FortiGate Memory Usage', 'memory_usage', 'memory', self::SYS_MEM, $mem, '%', [
                'source' => 'FORTINET-FORTIGATE-MIB::fgSysMemUsage',
            ]);
        }

        // Disk Usage
        $disk = SnmpValueHelper::numeric($context->walker->get(self::SYS_DISK));
        if ($disk !== null) {
            $this->addSensor($sensors, 'FortiGate Log Disk Usage', 'disk_usage', 'storage', self::SYS_DISK, $disk, '%', [
                'source' => 'FORTINET-FORTIGATE-MIB::fgSysDiskUsage',
            ]);
        }

        // Active Sessions Count
        $sessions = SnmpValueHelper::numeric($context->walker->get(self::SYS_SESSIONS));
        if ($sessions !== null) {
            $this->addSensor($sensors, 'FortiGate Active Sessions', 'session_count', 'system', self::SYS_SESSIONS, $sessions, 'sessions', [
                'source' => 'FORTINET-FORTIGATE-MIB::fgSysSesCount',
            ]);
        }

        // Session Setup Rate
        $rate = SnmpValueHelper::numeric($context->walker->get(self::SYS_SES_RATE));
        if ($rate !== null) {
            $this->addSensor($sensors, 'FortiGate Session Rate', 'session_rate', 'system', self::SYS_SES_RATE, $rate, 'cps', [
                'source' => 'FORTINET-FORTIGATE-MIB::fgSysSesRate',
            ]);
        }
    }

    private function discoverSslVpnSummary(DiscoveryContext $context, array &$sensors): void
    {
        // SSL-VPN Active Tunnels by VDOM
        try {
            $tunnels = $context->walker->walkIndexed(self::VPN_SSL_ACTIVE_TUNNELS);
            foreach ($tunnels as $vdomIdx => $val) {
                $count = SnmpValueHelper::numeric($val);
                if ($count !== null) {
                    $oid = self::VPN_SSL_ACTIVE_TUNNELS . '.' . $vdomIdx;
                    $name = $vdomIdx === '1' ? 'SSL-VPN Active Tunnels' : "SSL-VPN Active Tunnels (VDOM {$vdomIdx})";
                    $this->addSensor($sensors, $name, 'ssl_tunnels', 'vpn', $oid, $count, 'tunnels', [
                        'source' => 'FORTINET-FORTIGATE-MIB::fgVpnSslStatsActiveTunnels',
                        'vdom_index' => $vdomIdx,
                    ]);
                }
            }
        } catch (\Throwable) {}

        // SSL-VPN Logged in Users by VDOM
        try {
            $users = $context->walker->walkIndexed(self::VPN_SSL_LOGIN_USERS);
            foreach ($users as $vdomIdx => $val) {
                $count = SnmpValueHelper::numeric($val);
                if ($count !== null) {
                    $oid = self::VPN_SSL_LOGIN_USERS . '.' . $vdomIdx;
                    $name = $vdomIdx === '1' ? 'SSL-VPN Logged-in Users' : "SSL-VPN Logged-in Users (VDOM {$vdomIdx})";
                    $this->addSensor($sensors, $name, 'ssl_users', 'vpn', $oid, $count, 'users', [
                        'source' => 'FORTINET-FORTIGATE-MIB::fgVpnSslStatsLoginUsers',
                        'vdom_index' => $vdomIdx,
                    ]);
                }
            }
        } catch (\Throwable) {}
    }

    private function discoverSslVpnTunnels(DiscoveryContext $context, array &$sensors): void
    {
        try {
            // Walk Tunnel IP addresses (.1.3.6.1.4.1.12356.101.12.2.4.1.5)
            $ips = $context->walker->walkIndexed(self::VPN_SSL_TUNNEL_IP);
            if (empty($ips)) {
                return;
            }

            $users = [];
            try { $users = $context->walker->walkIndexed(self::VPN_SSL_TUNNEL_USER); } catch (\Throwable) {}

            $bytesIn = [];
            try { $bytesIn = $context->walker->walkIndexed(self::VPN_SSL_TUNNEL_BYTES_IN); } catch (\Throwable) {}

            $bytesOut = [];
            try { $bytesOut = $context->walker->walkIndexed(self::VPN_SSL_TUNNEL_BYTES_OUT); } catch (\Throwable) {}

            foreach ($ips as $index => $ipVal) {
                $ipStr = trim((string)$ipVal, " \t\n\r\0\x0B\"");
                if ($ipStr === '') continue;

                $userName = trim((string)($users[$index] ?? "User_{$index}"), " \t\n\r\0\x0B\"");
                $label = "SSL-VPN Tunnel {$userName} ({$ipStr})";

                // IP Sensor (text / identity)
                $ipOid = self::VPN_SSL_TUNNEL_IP . '.' . $index;
                $this->addTextSensor($sensors, "{$label} IP", 'ssl_tunnel_ip', 'vpn', $ipOid, $ipStr, [
                    'source' => 'FORTINET-VPN-PANDORA-MIB::fgVpnSslTunnelIp',
                    'tunnel_user' => $userName,
                    'tunnel_index' => $index,
                ]);

                // Bytes In Counter
                if (isset($bytesIn[$index])) {
                    $bIn = SnmpValueHelper::numeric($bytesIn[$index]);
                    if ($bIn !== null) {
                        $inOid = self::VPN_SSL_TUNNEL_BYTES_IN . '.' . $index;
                        $this->addSensor($sensors, "{$label} Bytes In", 'traffic_in', 'vpn', $inOid, $bIn, 'bytes', [
                            'source' => 'FORTINET-VPN-PANDORA-MIB::fgVpnSslTunnelBytesIn',
                            'tunnel_user' => $userName,
                        ]);
                    }
                }

                // Bytes Out Counter
                if (isset($bytesOut[$index])) {
                    $bOut = SnmpValueHelper::numeric($bytesOut[$index]);
                    if ($bOut !== null) {
                        $outOid = self::VPN_SSL_TUNNEL_BYTES_OUT . '.' . $index;
                        $this->addSensor($sensors, "{$label} Bytes Out", 'traffic_out', 'vpn', $outOid, $bOut, 'bytes', [
                            'source' => 'FORTINET-VPN-PANDORA-MIB::fgVpnSslTunnelBytesOut',
                            'tunnel_user' => $userName,
                        ]);
                    }
                }
            }
        } catch (\Throwable) {}
    }

    private function discoverIpsecTunnels(DiscoveryContext $context, array &$sensors): void
    {
        // IPsec Tunnel Up Count
        $upCount = SnmpValueHelper::numeric($context->walker->get(self::VPN_IPSEC_UP_COUNT));
        if ($upCount !== null) {
            $this->addSensor($sensors, 'IPsec VPN Up Tunnels', 'ipsec_tunnels', 'vpn', self::VPN_IPSEC_UP_COUNT, $upCount, 'tunnels', [
                'source' => 'FORTINET-FORTIGATE-MIB::fgVpnTunnelUpCount',
            ]);
        }

        // IPsec Tunnel Table Status
        try {
            $statuses = $context->walker->walkIndexed(self::VPN_TUN_STATUS);
            if (!empty($statuses)) {
                $names = [];
                try { $names = $context->walker->walkIndexed(self::VPN_TUN_NAME); } catch (\Throwable) {}

                foreach ($statuses as $index => $statusVal) {
                    $statusNum = SnmpValueHelper::numeric($statusVal);
                    if ($statusNum === null) continue;

                    $tunName = trim((string)($names[$index] ?? "Tunnel_{$index}"), " \t\n\r\0\x0B\"");
                    $oid = self::VPN_TUN_STATUS . '.' . $index;
                    $this->addSensor($sensors, "IPsec Tunnel {$tunName} Status", 'tunnel_status', 'vpn', $oid, $statusNum, 'status', [
                        'source' => 'FORTINET-FORTIGATE-MIB::fgVpnTunEntStatus',
                        'tunnel_name' => $tunName,
                        'state_map' => [1 => 'down', 2 => 'up'],
                    ]);
                }
            }
        } catch (\Throwable) {}
    }

    private function discoverHaStatus(DiscoveryContext $context, array &$sensors): void
    {
        $haMode = SnmpValueHelper::numeric($context->walker->get(self::HA_SYSTEM_MODE));
        if ($haMode !== null) {
            $modeText = match ((int)$haMode) {
                1 => 'standalone',
                2 => 'active-passive',
                3 => 'active-active',
                default => 'unknown',
            };
            $this->addSensor($sensors, 'FortiGate HA Mode', 'ha_mode', 'system', self::HA_SYSTEM_MODE, $haMode, '', [
                'source' => 'FORTINET-FORTIGATE-MIB::fgHaSystemMode',
                'mode_name' => $modeText,
            ]);
        }
    }

    private function addSensor(
        array &$sensors,
        string $metricName,
        string $sensorType,
        string $sensorClass,
        string $oid,
        int|float $numericValue,
        string $unit,
        array $metadata,
    ): void {
        $sensor = [
            'sensor_class' => $sensorClass,
            'sensor_name' => StringHelper::safeModuleName($metricName),
            'sensor_type' => $sensorType,
            'interface_index' => null,
            'interface_name' => null,
            'entity_index' => null,
            'oid' => $oid,
            'raw_value' => (string) $numericValue,
            'unit' => $unit,
            'scale' => 'units',
            'precision' => 0,
            'status' => 'ok',
            'metadata' => array_merge(['discovery_module' => 'FortinetDiscoveryModule'], $metadata),
        ];

        $normalized = $this->normalizer->normalize($sensor);
        if ($normalized !== null) {
            $sensors[] = $normalized;
        }
    }

    private function addTextSensor(
        array &$sensors,
        string $metricName,
        string $sensorType,
        string $sensorClass,
        string $oid,
        string $textValue,
        array $metadata,
    ): void {
        $sensor = [
            'sensor_class' => $sensorClass,
            'sensor_name' => StringHelper::safeModuleName($metricName),
            'sensor_type' => $sensorType,
            'interface_index' => null,
            'interface_name' => null,
            'entity_index' => null,
            'oid' => $oid,
            'raw_value' => $textValue,
            'unit' => '',
            'scale' => 'units',
            'precision' => 0,
            'status' => 'ok',
            'metadata' => array_merge([
                'discovery_module' => 'FortinetDiscoveryModule',
                'value_type' => 'string',
            ], $metadata),
        ];

        $normalized = $this->normalizer->normalize($sensor);
        if ($normalized !== null) {
            $sensors[] = $normalized;
        }
    }
}
