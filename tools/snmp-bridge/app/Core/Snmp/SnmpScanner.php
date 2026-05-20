<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Snmp;

use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Discovery\DiscoveryPipeline;
use SnmpBridge\Core\Vendor\ProfileMatcher;
use SnmpBridge\Exceptions\DiscoveryException;
use SnmpBridge\Repository\DeviceRepository;
use SnmpBridge\Repository\SensorInventoryRepository;

final class SnmpScanner
{
    /**
     * @param array<string, mixed> $defaultSnmpConfig
     */
    public function __construct(
        private readonly ProfileMatcher $profileMatcher,
        private readonly DiscoveryPipeline $pipeline,
        private readonly DeviceRepository $deviceRepository,
        private readonly SensorInventoryRepository $sensorRepository,
        private readonly array $defaultSnmpConfig,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     * @return array{device:array<string, mixed>, vendor:string, sensors:list<array<string, mixed>>}
     */
    public function scan(array $request): array
    {
        $host = trim((string) ($request['ip_address'] ?? $request['host'] ?? ''));
        $community = trim((string) ($request['community'] ?? $this->defaultSnmpConfig['community']));
        $version = trim((string) ($request['version'] ?? $this->defaultSnmpConfig['version']));
        $port = (int) ($request['port'] ?? $this->defaultSnmpConfig['port']);

        if ($host === '') {
            throw new DiscoveryException('SNMP target host is required.');
        }

        $session = new SnmpSession(
            $host,
            $community,
            $version,
            $port,
            (int) $this->defaultSnmpConfig['timeout_usec'],
            (int) $this->defaultSnmpConfig['retries'],
            (int) $this->defaultSnmpConfig['max_oids'],
            (bool) $this->defaultSnmpConfig['quick_print'],
        );

        try {
            $walker = new SnmpWalker($session);
            $sysDescr = $walker->get(SnmpHelper::SYS_DESCR) ?? '';
            $sysObjectId = $walker->get(SnmpHelper::SYS_OBJECT_ID) ?? '';

            // Graceful handling - if SNMP not responding, return "not found" but don't error
            if ($sysDescr === '' || $sysObjectId === '') {
                return [
                    'device' => [
                        'ip_address' => $host,
                        'hostname' => $host,
                        'vendor' => 'unknown',
                        'sys_object_id' => 'not_found',
                        'sys_descr' => 'not_found',
                        'snmp_version' => $version,
                        'snmp_port' => $port,
                        'snmp_community' => $community,
                        'status' => 'not_found',
                    ],
                    'vendor' => 'unknown',
                    'sensors' => [
                        [
                            'type' => 'status',
                            'name' => 'SNMP Status',
                            'value' => 'Device did not respond to SNMP queries',
                            'description' => 'Check IP, UDP/161, SNMP version, and community',
                        ],
                    ],
                ];
            }

            $sysName = $walker->get(SnmpHelper::SYS_NAME) ?? $host;
            $vendor = $this->profileMatcher->match($sysObjectId, $sysDescr);

            $device = [
                'ip_address' => $host,
                'hostname' => $sysName,
                'vendor' => $vendor->name(),
                'sys_object_id' => $sysObjectId,
                'sys_descr' => $sysDescr,
                'snmp_version' => $version,
                'snmp_port' => $port,
                'snmp_community' => $community,
            ];

            $deviceId = $this->deviceRepository->upsert($device);
            $device['id'] = $deviceId;

            $context = new DiscoveryContext(
                $walker,
                $vendor,
                $vendor->capabilities(),
                $device,
                [
                    'version' => $version,
                    'community' => $community,
                    'port' => $port,
                ],
            );

            $sensors = $this->pipeline->discover($context);
            $this->sensorRepository->replaceForDevice($deviceId, $sensors);

            return [
                'device' => $device,
                'vendor' => $vendor->name(),
                'sensors' => $sensors,
            ];
        } catch (\Exception $e) {
            // Any other SNMP errors - return graceful "not found" instead of throwing
            error_log("SNMP scan error for {$host}: " . $e->getMessage());
            return [
                'device' => [
                    'ip_address' => $host,
                    'hostname' => $host,
                    'vendor' => 'unknown',
                    'sys_object_id' => 'error',
                    'sys_descr' => $e->getMessage(),
                    'snmp_version' => $version,
                    'snmp_port' => $port,
                    'snmp_community' => $community,
                    'status' => 'error',
                ],
                'vendor' => 'unknown',
                'sensors' => [
                    [
                        'type' => 'status',
                        'name' => 'SNMP Error',
                        'value' => 'SNMP error occurred',
                        'description' => $e->getMessage(),
                    ],
                ],
            ];
        } finally {
            $session->close();
        }
    }
}
