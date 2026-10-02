<?php

declare(strict_types=1);

namespace SnmpBridge\Services;

use Exception;
use Throwable;
use SnmpBridge\Core\Discovery\DiscoveryPipeline;
use SnmpBridge\Repository\SensorInventoryRepository;

final class SubnetScannerService
{
    public function __construct(
        private readonly DiscoveryPipeline $discoveryPipeline,
        private readonly SensorInventoryRepository $sensorInventoryRepository,
        private readonly ?\SnmpBridge\Core\Snmp\SnmpScanner $snmpScanner = null,
        private readonly ?\SnmpBridge\Repository\DeviceRepository $deviceRepository = null,
    ) {
    }

    /**
     * Expand CIDR string (e.g. 10.10.5.0/28) or IP range into array of IP strings.
     *
     * @return array<int, string>
     */
    public function expandSubnet(string $cidr): array
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return [];
        }

        // Single IP check
        if (filter_var($cidr, FILTER_VALIDATE_IP)) {
            return [$cidr];
        }

        // CIDR notation
        if (str_contains($cidr, '/')) {
            [$net, $mask] = explode('/', $cidr, 2);
            $mask = (int) $mask;
            if ($mask < 16 || $mask > 32) {
                // Cap at /16 to prevent excessive memory/time usage
                $mask = max(16, min(32, $mask));
            }

            $ipLong = ip2long($net);
            if ($ipLong === false) {
                return [];
            }

            $numHosts = 1 << (32 - $mask);
            $networkLong = $ipLong & (~($numHosts - 1));

            $ips = [];
            // Skip network (.0) and broadcast (.255) for /24 to /30
            $start = ($mask <= 30) ? 1 : 0;
            $end = ($mask <= 30) ? ($numHosts - 2) : ($numHosts - 1);

            for ($i = $start; $i <= $end; $i++) {
                $ips[] = long2ip($networkLong + $i);
                if (count($ips) >= 512) {
                    break; // Cap max hosts per subnet scan
                }
            }

            return $ips;
        }

        // Range notation e.g. 192.168.1.1-192.168.1.50
        if (str_contains($cidr, '-')) {
            [$startIp, $endIp] = explode('-', $cidr, 2);
            $startIp = trim($startIp);
            $endIp = trim($endIp);
            if (filter_var($startIp, FILTER_VALIDATE_IP) && filter_var($endIp, FILTER_VALIDATE_IP)) {
                $startLong = ip2long($startIp);
                $endLong = ip2long($endIp);
                if ($startLong !== false && $endLong !== false && $startLong <= $endLong) {
                    $ips = [];
                    for ($current = $startLong; $current <= $endLong; $current++) {
                        $ips[] = long2ip($current);
                        if (count($ips) >= 512) {
                            break;
                        }
                    }
                    return $ips;
                }
            }
        }

        return [];
    }

    /**
     * Check if UDP 161 (SNMP) or ICMP reachability test passes for target IP.
     */
    public function checkReachability(string $ip, float $timeoutSec = 0.3): bool
    {
        // Fast UDP 161 socket check
        $socket = @fsockopen("udp://$ip", 161, $errno, $errstr, $timeoutSec);
        if ($socket) {
            fclose($socket);
            return true;
        }

        // Fallback ICMP ping
        exec("ping -c 1 -W 1 " . escapeshellarg($ip) . " 2>&1", $output, $status);
        return $status === 0;
    }

    /**
     * Classify device OS type using sysObjectID and sysDescr string.
     */
    public function classifyOs(?string $sysObjId, ?string $sysDescr): string
    {
        $sysObjId = (string) $sysObjId;
        $sysDescr = strtolower((string) $sysDescr);

        if (str_contains($sysObjId, '.1.3.6.1.4.1.3375.') || str_contains($sysDescr, 'big-ip') || str_contains($sysDescr, 'f5')) {
            return 'F5 BIG-IP';
        }
        if (str_contains($sysObjId, '.1.3.6.1.4.1.9.') || str_contains($sysDescr, 'cisco')) {
            return 'Cisco IOS / XE';
        }
        if (str_contains($sysObjId, '.1.3.6.1.4.1.2011.') || str_contains($sysDescr, 'huawei')) {
            return 'Huawei VRP';
        }
        if (str_contains($sysObjId, '.1.3.6.1.4.1.2636.') || str_contains($sysDescr, 'junos')) {
            return 'Juniper JunOS';
        }
        if (str_contains($sysObjId, '.1.3.6.1.4.1.14988.') || str_contains($sysDescr, 'routeros') || str_contains($sysDescr, 'mikrotik')) {
            return 'MikroTik RouterOS';
        }
        if (str_contains($sysObjId, '.1.3.6.1.4.1.3808.') || str_contains($sysDescr, 'dahua') || str_contains($sysDescr, 'cctv')) {
            return 'Dahua CCTV / IP Camera';
        }
        if (str_contains($sysObjId, '.1.3.6.1.4.1.311.') || str_contains($sysDescr, 'windows')) {
            return 'Windows';
        }
        if (str_contains($sysObjId, '.1.3.6.1.4.1.8072.') || str_contains($sysDescr, 'linux') || str_contains($sysDescr, 'unix') || str_contains($sysDescr, 'bsd')) {
            return 'Linux / Unix';
        }
        if (str_contains($sysDescr, 'printer') || str_contains($sysDescr, 'epson') || str_contains($sysDescr, 'hp jetdirect')) {
            return 'Network Printer';
        }
        if (str_contains($sysDescr, 'rectifier') || str_contains($sysDescr, 'power system') || str_contains($sysDescr, 'emerson') || str_contains($sysDescr, 'zte')) {
            return 'Rectifier / Power System';
        }
        if (str_contains($sysDescr, 'olt') || str_contains($sysDescr, 'gpon')) {
            return 'Optical Line Terminal (OLT)';
        }

        return 'Generic SNMP Device';
    }

    /**
     * Scan subnet and execute discovery for responsive hosts.
     *
     * @param array<int, string> $communities
     * @return array{
     *     subnet: string,
     *     scanned_count: int,
     *     active_hosts_count: int,
     *     results: array<int, array<string, mixed>>
     * }
     */
    public function scanSubnet(
        string $subnet,
        array $communities = ['public', 'private', 'snmp-read', 'community'],
        int $version = 2,
        string $profile = 'comprehensive'
    ): array {
        $ips = $this->expandSubnet($subnet);
        $discoveredHosts = [];

        foreach ($ips as $ip) {
            if (!$this->checkReachability($ip)) {
                continue;
            }

            // Try community strings until one succeeds
            $validCommunity = null;
            $sysObjId = null;
            $sysDescr = null;
            $sysName = null;

            foreach ($communities as $community) {
                $community = trim($community);
                if ($community === '') {
                    continue;
                }

                // Test SNMP GET for sysObjectID.0 (.1.3.6.1.2.1.1.2.0)
                $resObj = @snmp2_get($ip, $community, '.1.3.6.1.2.1.1.2.0', 300000, 1);
                if ($resObj === false) {
                    $resObj = @snmpget($ip, $community, '.1.3.6.1.2.1.1.2.0', 300000, 1);
                }

                if ($resObj !== false) {
                    $validCommunity = $community;
                    $sysObjId = trim(str_replace(['OID:', 'STRING:', '"'], '', (string) $resObj));

                    // Query sysName.0 (.1.3.6.1.2.1.1.5.0)
                    $resName = @snmp2_get($ip, $community, '.1.3.6.1.2.1.1.5.0', 300000, 1);
                    if ($resName !== false) {
                        $sysName = trim(str_replace(['STRING:', '"'], '', (string) $resName));
                    }

                    // Query sysDescr.0 (.1.3.6.1.2.1.1.1.0)
                    $resDescr = @snmp2_get($ip, $community, '.1.3.6.1.2.1.1.1.0', 300000, 1);
                    if ($resDescr !== false) {
                        $sysDescr = trim(str_replace(['STRING:', '"'], '', (string) $resDescr));
                    }

                    break; // Community string verified!
                }
            }

            if ($validCommunity !== null) {
                $osClass = $this->classifyOs($sysObjId, $sysDescr);
                $hostname = !empty($sysName) ? $sysName : "Device-$ip";

                // Execute Discovery for this host if scanner is available
                $sensorsCount = 0;
                if ($this->snmpScanner !== null) {
                    try {
                        $scanResult = $this->snmpScanner->scan([
                            'ip_address' => $ip,
                            'community' => $validCommunity,
                            'version' => (string) $version,
                            'discovery_profile' => $profile,
                        ]);
                        $sensorsCount = count($scanResult['sensors'] ?? []);
                    } catch (Throwable $e) {
                        error_log(sprintf('Subnet scanner discovery error for %s: %s', $ip, $e->getMessage()));
                    }
                }

                $discoveredHosts[] = [
                    'ip' => $ip,
                    'community' => $validCommunity,
                    'hostname' => $hostname,
                    'sysObjectID' => $sysObjId ?? 'N/A',
                    'sysDescr' => $sysDescr ?? 'N/A',
                    'os' => $osClass,
                    'sensors_count' => $sensorsCount,
                    'scanned_at' => date('Y-m-d H:i:s'),
                ];
            }
        }

        return [
            'subnet' => $subnet,
            'scanned_count' => count($ips),
            'active_hosts_count' => count($discoveredHosts),
            'results' => $discoveredHosts,
        ];
    }
}
