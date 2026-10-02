<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Snmp\SnmpWalker;
use SnmpBridge\Helpers\SnmpValueHelper;

/**
 * Dahua CCTV/NVR inventory translator.
 *
 * This module intentionally emits provisioning inventory rows only. String
 * inventory rows are useful for review, while numeric rows use real SNMP cells
 * so they can still be provisioned into Pandora when appropriate.
 */
final class DahuaDiscoveryModule implements DiscoveryModuleInterface
{
    private const string ENTERPRISE = '1.3.6.1.4.1.1004849';

    private const array SYSTEM_OIDS = [
        'firmware_version' => self::ENTERPRISE . '.2.1.1.1.0',
        'protocol_version' => self::ENTERPRISE . '.2.1.1.2.0',
        'total_video_channels' => self::ENTERPRISE . '.2.1.2.1.0',
        'serial_number' => self::ENTERPRISE . '.2.1.2.4.0',
        'firmware_build' => self::ENTERPRISE . '.2.1.2.5.0',
        'model' => self::ENTERPRISE . '.2.1.2.6.0',
        'device_type' => self::ENTERPRISE . '.2.1.2.7.0',
        'hostname' => self::ENTERPRISE . '.2.1.2.9.0',
        'brand' => self::ENTERPRISE . '.2.1.2.11.0',
        'cpu_usage' => self::ENTERPRISE . '.2.1.3.0',
        'uptime' => self::ENTERPRISE . '.2.1.6.0',
        'device_time' => self::ENTERPRISE . '.2.1.8.0',
        'network_interface' => self::ENTERPRISE . '.2.1.11.1.0',
    ];

    private const array NETWORK_OIDS = [
        'tcp_port' => self::ENTERPRISE . '.2.2.1.1.0',
        'udp_port' => self::ENTERPRISE . '.2.2.1.2.0',
        'http_port' => self::ENTERPRISE . '.2.2.1.3.0',
        'rtsp_port' => self::ENTERPRISE . '.2.2.1.4.0',
        'https_port' => self::ENTERPRISE . '.2.2.1.6.0',
        'mac_address' => self::ENTERPRISE . '.2.2.2.2.0',
        'subnet_mask' => self::ENTERPRISE . '.2.2.2.4.0',
        'gateway' => self::ENTERPRISE . '.2.2.2.5.0',
        'primary_dns' => self::ENTERPRISE . '.2.2.2.6.0',
        'secondary_dns' => self::ENTERPRISE . '.2.2.2.7.0',
        'ip_address' => self::ENTERPRISE . '.2.2.2.8.0',
    ];

    private const array STORAGE_TABLES = [
        'index' => self::ENTERPRISE . '.2.4.1.1.2',
        'state' => self::ENTERPRISE . '.2.4.1.1.3',
        'mount' => self::ENTERPRISE . '.2.4.1.1.4',
        'status' => self::ENTERPRISE . '.2.4.1.1.5',
        'capacity_gb' => self::ENTERPRISE . '.2.4.1.1.6',
        'usable_gb' => self::ENTERPRISE . '.2.4.1.1.7',
    ];

    private const array CHANNEL_TABLES = [
        'camera_ip' => self::ENTERPRISE . '.2.10.2.2.1.2',
        'connection_status' => self::ENTERPRISE . '.2.10.2.2.1.3',
        'camera_name' => self::ENTERPRISE . '.2.10.2.2.1.4',
        'encoding' => self::ENTERPRISE . '.2.3.1.1.1.1.2',
        'fps' => self::ENTERPRISE . '.2.3.1.1.1.1.3',
        'resolution' => self::ENTERPRISE . '.2.3.1.1.1.1.4',
        'bitrate_kbps' => self::ENTERPRISE . '.2.3.1.1.1.1.5',
    ];

    public function name(): string
    {
        return 'dahua_discovery';
    }

    public function supports(DiscoveryContext $context): bool
    {
        $sysObjectId = '.' . trim((string) ($context->device['sys_object_id'] ?? ''), '.');
        $sysDescr = (string) ($context->device['sys_descr'] ?? '');
        if ($context->vendor->name() === 'Dahua') {
            return true;
        }
        if (str_starts_with($sysObjectId, '.' . self::ENTERPRISE)) {
            return true;
        }
        return preg_match('/\b(?:Dahua|DHI-(?:NVR|XVR|DVR)|DH-(?:NVR|XVR|DVR)|HCVR|HCNVR)\b/i', $sysDescr) === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function discover(DiscoveryContext $context): array
    {
        $walker = $context->walker;

        return array_values(array_merge(
            $this->discoverSystemInformation($walker),
            $this->discoverNetworkConfiguration($walker),
            $this->discoverStorage($walker),
            $this->discoverCameraChannels($walker),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoverSystemInformation(SnmpWalker $walker): array
    {
        $rows = [];
        $firmware = $this->get($walker, self::SYSTEM_OIDS['firmware_build'])
            ?? $this->get($walker, self::SYSTEM_OIDS['firmware_version']);
        $buildDate = $this->extractBuildDate($firmware);

        $rows[] = $this->textRow('cctv_system', 'System - Hostname', 'hostname', self::SYSTEM_OIDS['hostname'], $this->get($walker, self::SYSTEM_OIDS['hostname']));
        $rows[] = $this->textRow('cctv_system', 'System - Brand', 'brand', self::SYSTEM_OIDS['brand'], $this->get($walker, self::SYSTEM_OIDS['brand']));
        $rows[] = $this->textRow('cctv_system', 'System - Model', 'model', self::SYSTEM_OIDS['model'], $this->get($walker, self::SYSTEM_OIDS['model']));
        $rows[] = $this->textRow('cctv_system', 'System - Device Type', 'device_type', self::SYSTEM_OIDS['device_type'], $this->get($walker, self::SYSTEM_OIDS['device_type']));
        $rows[] = $this->textRow('cctv_system', 'System - Serial Number', 'serial_number', self::SYSTEM_OIDS['serial_number'], $this->get($walker, self::SYSTEM_OIDS['serial_number']));
        $rows[] = $this->textRow('cctv_system', 'System - Firmware Version', 'firmware_version', self::SYSTEM_OIDS['firmware_version'], $this->get($walker, self::SYSTEM_OIDS['firmware_version']));
        $rows[] = $this->textRow('cctv_system', 'System - Firmware Build', 'firmware_build', self::SYSTEM_OIDS['firmware_build'], $firmware, metadata: ['build_date' => $buildDate]);
        $rows[] = $this->textRow('cctv_system', 'System - Build Date', 'firmware_build_date', self::SYSTEM_OIDS['firmware_build'], $buildDate);
        $rows[] = $this->textRow('cctv_system', 'System - Device Time', 'device_time', self::SYSTEM_OIDS['device_time'], $this->get($walker, self::SYSTEM_OIDS['device_time']));
        $rows[] = $this->textRow('cctv_system', 'System - Uptime', 'uptime', self::SYSTEM_OIDS['uptime'], $this->formatUptime($this->get($walker, self::SYSTEM_OIDS['uptime'])));
        $rows[] = $this->numericRow('processor', 'System - CPU Usage', 'cpu_usage', self::SYSTEM_OIDS['cpu_usage'], $this->get($walker, self::SYSTEM_OIDS['cpu_usage']), '%');
        $rows[] = $this->numericRow('cctv_system', 'System - Total Video Channels', 'video_channels', self::SYSTEM_OIDS['total_video_channels'], $this->get($walker, self::SYSTEM_OIDS['total_video_channels']), 'channels');

        return $this->validRows($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoverNetworkConfiguration(SnmpWalker $walker): array
    {
        $interfaceName = $this->get($walker, self::SYSTEM_OIDS['network_interface']) ?? 'network';
        $rows = [
            $this->textRow('cctv_network', 'Network - Interface', 'network_interface', self::SYSTEM_OIDS['network_interface'], $interfaceName, interfaceName: $interfaceName),
            $this->textRow('cctv_network', 'Network - IP Address', 'ip_address', self::NETWORK_OIDS['ip_address'], $this->get($walker, self::NETWORK_OIDS['ip_address']), interfaceName: $interfaceName),
            $this->textRow('cctv_network', 'Network - Subnet Mask', 'subnet_mask', self::NETWORK_OIDS['subnet_mask'], $this->get($walker, self::NETWORK_OIDS['subnet_mask']), interfaceName: $interfaceName),
            $this->textRow('cctv_network', 'Network - Gateway', 'gateway', self::NETWORK_OIDS['gateway'], $this->get($walker, self::NETWORK_OIDS['gateway']), interfaceName: $interfaceName),
            $this->textRow('cctv_network', 'Network - Primary DNS', 'dns_server', self::NETWORK_OIDS['primary_dns'], $this->get($walker, self::NETWORK_OIDS['primary_dns']), interfaceName: $interfaceName),
            $this->textRow('cctv_network', 'Network - Secondary DNS', 'dns_server', self::NETWORK_OIDS['secondary_dns'], $this->get($walker, self::NETWORK_OIDS['secondary_dns']), interfaceName: $interfaceName),
            $this->textRow('cctv_network', 'Network - MAC Address', 'mac_address', self::NETWORK_OIDS['mac_address'], $this->get($walker, self::NETWORK_OIDS['mac_address']), interfaceName: $interfaceName),
        ];

        foreach ($this->networkPorts() as $key => $name) {
            $rows[] = $this->numericRow(
                'cctv_network',
                'Network - ' . $name,
                'network_port',
                self::NETWORK_OIDS[$key],
                $this->get($walker, self::NETWORK_OIDS[$key]),
                'port',
                interfaceName: $interfaceName,
                metadata: ['service' => $name],
            );
        }

        return $this->validRows($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoverStorage(SnmpWalker $walker): array
    {
        $mounts = $this->walkClean($walker, self::STORAGE_TABLES['mount']);
        $statuses = $this->walkClean($walker, self::STORAGE_TABLES['status']);
        $capacities = $this->walkClean($walker, self::STORAGE_TABLES['capacity_gb']);
        $usableCapacities = $this->walkClean($walker, self::STORAGE_TABLES['usable_gb']);
        $rows = [];

        foreach ($mounts as $index => $mount) {
            if ($mount === '') {
                continue;
            }

            $status = $statuses[$index] ?? 'Unknown';
            $capacity = $capacities[$index] ?? null;
            $usable = $usableCapacities[$index] ?? null;
            $storageName = sprintf('Storage - Disk %s %s', $index, $mount);
            $metadata = [
                'disk_index' => (int) $index,
                'mount_point' => $mount,
                'disk_status' => $status,
                'capacity_human' => $this->formatGigabytes($capacity),
                'usable_capacity_human' => $this->formatGigabytes($usable),
            ];

            $rows[] = $this->textRow(
                'cctv_storage',
                $storageName . ' Status',
                'disk_status',
                self::STORAGE_TABLES['status'] . '.' . $index,
                $status,
                (int) $index,
                $mount,
                $this->statusFromText($status),
                $metadata,
            );
            $rows[] = $this->numericRow(
                'cctv_storage',
                $storageName . ' Capacity',
                'disk_capacity',
                self::STORAGE_TABLES['capacity_gb'] . '.' . $index,
                $capacity,
                'GB',
                (int) $index,
                $mount,
                $this->statusFromText($status),
                $metadata,
            );
        }

        return $this->validRows($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoverCameraChannels(SnmpWalker $walker): array
    {
        $names = $this->walkClean($walker, self::CHANNEL_TABLES['camera_name']);
        $statuses = $this->walkClean($walker, self::CHANNEL_TABLES['connection_status']);
        $ips = $this->walkClean($walker, self::CHANNEL_TABLES['camera_ip']);
        $encodings = $this->walkClean($walker, self::CHANNEL_TABLES['encoding']);
        $frameRates = $this->walkClean($walker, self::CHANNEL_TABLES['fps']);
        $resolutions = $this->walkClean($walker, self::CHANNEL_TABLES['resolution']);
        $bitrates = $this->walkClean($walker, self::CHANNEL_TABLES['bitrate_kbps']);
        $rows = [];
        $connected = 0;
        $configured = 0;
        $totalChannels = SnmpValueHelper::integer($this->get($walker, self::SYSTEM_OIDS['total_video_channels']));

        $indexes = array_unique(array_merge(array_keys($names), array_keys($statuses), array_keys($ips)));
        usort($indexes, static fn (string $a, string $b): int => (int) $a <=> (int) $b);

        foreach ($indexes as $index) {
            if ((int) $index <= 0) {
                continue;
            }

            $status = $statuses[$index] ?? '';
            $cameraName = $names[$index] ?? sprintf('Channel %02d', (int) $index);
            $cameraIp = $ips[$index] ?? '';

            if ($this->isEmptyChannel($status, $cameraName, $cameraIp)) {
                continue;
            }

            $configured++;
            if (strcasecmp($status, 'Connected') === 0) {
                $connected++;
            }

            $encoding = $encodings[$index] ?? '';
            $frameRate = $frameRates[$index] ?? '';
            $resolution = $resolutions[$index] ?? '';
            $nameParts = array_filter([$cameraName, $encoding, $frameRate !== '' ? $frameRate . 'fps' : '', $resolution]);
            $sensorName = sprintf('Camera %02d - %s', (int) $index, implode(' - ', $nameParts));

            $rows[] = $this->textRow(
                'cctv_channel',
                $sensorName,
                'camera_status',
                self::CHANNEL_TABLES['connection_status'] . '.' . $index,
                $status !== '' ? $status : 'Unknown',
                (int) $index,
                'Channel ' . (int) $index,
                $this->statusFromText($status),
                [
                    'channel' => (int) $index,
                    'camera_name' => $cameraName,
                    'camera_ip' => $cameraIp,
                    'encoding' => $encoding,
                    'framerate_fps' => SnmpValueHelper::integer($frameRate),
                    'resolution' => $resolution,
                    'bitrate_kbps' => SnmpValueHelper::integer($bitrates[$index] ?? null),
                ],
            );
        }

        $summaryValue = $totalChannels !== null
            ? sprintf('%d connected / %d total (%d empty)', $connected, $totalChannels, max(0, $totalChannels - $connected))
            : sprintf('%d connected / %d configured', $connected, $configured);
        $rows[] = $this->textRow(
            'cctv_channel',
            'Camera Channels - Connected Summary',
            'camera_summary',
            self::CHANNEL_TABLES['connection_status'],
            $summaryValue,
            metadata: [
                'connected_channels' => $connected,
                'configured_channels' => $configured,
                'total_channels' => $totalChannels,
            ],
        );

        return $this->validRows($rows);
    }

    /**
     * @return array<string, string>
     */
    private function networkPorts(): array
    {
        return [
            'tcp_port' => 'Dahua TCP Port',
            'udp_port' => 'Dahua UDP Port',
            'http_port' => 'HTTP Port',
            'https_port' => 'HTTPS Port',
            'rtsp_port' => 'RTSP Port',
        ];
    }

    /**
     * @param list<array<string, mixed>|null> $rows
     * @return list<array<string, mixed>>
     */
    private function validRows(array $rows): array
    {
        return array_values(array_filter($rows));
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>|null
     */
    private function textRow(
        string $class,
        string $name,
        string $type,
        string $oid,
        mixed $value,
        ?int $interfaceIndex = null,
        ?string $interfaceName = null,
        string $status = 'ok',
        array $metadata = [],
    ): ?array {
        $value = $this->clean($value);

        if ($value === null) {
            return null;
        }

        return [
            'sensor_class' => $class,
            'sensor_name' => $name,
            'sensor_type' => $type,
            'interface_index' => $interfaceIndex,
            'interface_name' => $interfaceName,
            'entity_index' => $interfaceIndex,
            'oid' => $oid,
            'raw_value' => $value,
            'normalized_value' => null,
            'unit' => '',
            'status' => $status,
            'metadata' => $metadata + ['discovery_module' => $this->name()],
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>|null
     */
    private function numericRow(
        string $class,
        string $name,
        string $type,
        string $oid,
        mixed $value,
        string $unit,
        ?int $interfaceIndex = null,
        ?string $interfaceName = null,
        string $status = 'ok',
        array $metadata = [],
    ): ?array {
        $numeric = SnmpValueHelper::numeric($value);

        if ($numeric === null) {
            return null;
        }

        return [
            'sensor_class' => $class,
            'sensor_name' => $name,
            'sensor_type' => $type,
            'interface_index' => $interfaceIndex,
            'interface_name' => $interfaceName,
            'entity_index' => $interfaceIndex,
            'oid' => $oid,
            'raw_value' => (string) $this->clean($value),
            'normalized_value' => round($numeric, 6),
            'unit' => $unit,
            'status' => $status,
            'metadata' => $metadata + ['discovery_module' => $this->name()],
        ];
    }

    private function get(SnmpWalker $walker, string $oid): ?string
    {
        return $this->clean($walker->get($oid));
    }

    /**
     * @return array<string, string>
     */
    private function walkClean(SnmpWalker $walker, string $baseOid): array
    {
        $values = [];

        foreach ($walker->walkIndexed($baseOid) as $index => $value) {
            $clean = $this->clean($value);

            if ($clean !== null) {
                $values[(string) $index] = $clean;
            }
        }

        return $values;
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        $text = preg_replace('/^(?:STRING|INTEGER|Gauge32|Counter32|Counter64|OID|Timeticks|Hex-STRING):\s*/i', '', $text) ?? $text;
        $text = trim($text, "\" \t\n\r\0\x0B");

        return $text === '' || $text === '--' ? null : $text;
    }

    private function extractBuildDate(?string $firmware): ?string
    {
        if ($firmware === null) {
            return null;
        }

        if (preg_match('/Build\s+Date\s*:\s*([0-9]{4}-[0-9]{1,2}-[0-9]{1,2})/i', $firmware, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    private function formatUptime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/\((\d+)\)/', $value, $match) === 1) {
            return $this->durationFromCentiseconds((int) $match[1]);
        }

        if (ctype_digit($value)) {
            return $this->durationFromCentiseconds((int) $value);
        }

        return $value;
    }

    private function durationFromCentiseconds(int $centiseconds): string
    {
        $seconds = intdiv($centiseconds, 100);
        $days = intdiv($seconds, 86400);
        $seconds %= 86400;
        $hours = intdiv($seconds, 3600);
        $seconds %= 3600;
        $minutes = intdiv($seconds, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . ' day' . ($days === 1 ? '' : 's');
        }
        if ($hours > 0) {
            $parts[] = $hours . ' hour' . ($hours === 1 ? '' : 's');
        }
        if ($minutes > 0) {
            $parts[] = $minutes . ' minute' . ($minutes === 1 ? '' : 's');
        }

        return $parts !== [] ? implode(', ', $parts) : 'less than 1 minute';
    }

    private function formatGigabytes(mixed $value): ?string
    {
        $gigabytes = SnmpValueHelper::numeric($value);

        if ($gigabytes === null) {
            return null;
        }

        if ($gigabytes >= 1000.0) {
            return sprintf('%.2f TB (%d GB)', $gigabytes / 1000.0, (int) round($gigabytes));
        }

        return sprintf('%d GB', (int) round($gigabytes));
    }

    private function statusFromText(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        return match (true) {
            $status === '',
            $status === 'unknown' => 'unknown',
            str_contains($status, 'running'),
            str_contains($status, 'connected'),
            str_contains($status, 'available'),
            str_contains($status, 'normal') => 'ok',
            str_contains($status, 'empty') => 'unknown',
            default => 'warning',
        };
    }

    private function isEmptyChannel(string $status, string $name, string $ip): bool
    {
        if (strcasecmp($status, 'Empty') === 0) {
            return true;
        }

        return (in_array($ip, ['', '192.168.0.0', '0.0.0.0'], true))
            && preg_match('/^Channel\d+$/i', trim($name)) === 1;
    }
}
