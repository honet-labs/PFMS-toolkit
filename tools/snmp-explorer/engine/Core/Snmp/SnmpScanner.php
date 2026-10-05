<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Snmp;

use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Discovery\DiscoveryPipeline;
use SnmpBridge\Core\Vendor\ProfileMatcher;
use SnmpBridge\Exceptions\DiscoveryException;
use SnmpBridge\Repository\DeviceRepository;
use SnmpBridge\Repository\SensorInventoryRepository;
use Throwable;

final readonly class SnmpScanner
{
    /**
     * @param array<string, mixed> $defaultSnmpConfig
     */
    public function __construct(
        private ProfileMatcher $profileMatcher,
        private DiscoveryPipeline $pipeline,
        private DeviceRepository $deviceRepository,
        private SensorInventoryRepository $sensorRepository,
        private array $defaultSnmpConfig,
    ) {
    }

    /**
     * @param array<string, mixed> $request
     * @return array{device:array<string, mixed>, vendor:string, sensors:list<array<string, mixed>>, scan:array<string, mixed>}
     */
    public function scan(array $request): array
    {
        $host = trim((string) ($request['ip_address'] ?? $request['host'] ?? ''));
        $community = trim((string) ($request['community'] ?? $this->defaultSnmpConfig['community']));
        $version = trim((string) ($request['version'] ?? $this->defaultSnmpConfig['version']));
        $port = (int) ($request['port'] ?? $this->defaultSnmpConfig['port']);
        $discoveryProfile = trim((string) ($request['discovery_profile'] ?? $this->defaultSnmpConfig['discovery_profile'] ?? ''));
        $scanTimeoutSec = $this->scanTimeoutSeconds();
        $scanMaxSensors = $this->scanMaxSensors();
        $previewLimit = $this->scanResultPreviewLimit();
        $translateMaxSensors = $this->translateMaxSensors();
        $scanStartedAt = microtime(true);
        $scanDeadline = $scanStartedAt + max(5, $scanTimeoutSec - 5);

        if (!$this->isValidHost($host)) {
            throw new DiscoveryException('Use a valid IPv4, IPv6, or DNS hostname for the SNMP target.');
        }

        $normalizedVersion = strtolower($version);
        if (!in_array($normalizedVersion, ['1', 'v1', '2', '2c', 'v2c', '3', 'v3'], true)) {
            throw new DiscoveryException('Only SNMP v1, v2c, and v3 are supported.');
        }

        if ($port < 1 || $port > 65535) {
            throw new DiscoveryException('SNMP port must be between 1 and 65535.');
        }

        $isV3 = in_array($normalizedVersion, ['3', 'v3'], true);

        // SNMP v3 parameters
        $secName = trim((string) ($request['v3_user'] ?? $request['sec_name'] ?? $request['username'] ?? ''));
        $secLevel = trim((string) ($request['v3_sec_level'] ?? $request['sec_level'] ?? $this->defaultSnmpConfig['v3_sec_level'] ?? 'authPriv'));
        $authProto = trim((string) ($request['v3_auth_proto'] ?? $request['auth_protocol'] ?? $this->defaultSnmpConfig['v3_auth_proto'] ?? 'SHA'));
        $authPass = (string) ($request['v3_auth_pass'] ?? $request['auth_pass'] ?? $this->defaultSnmpConfig['v3_auth_pass'] ?? '');
        $privProto = trim((string) ($request['v3_priv_proto'] ?? $request['priv_protocol'] ?? $this->defaultSnmpConfig['v3_priv_proto'] ?? 'AES'));
        $privPass = (string) ($request['v3_priv_pass'] ?? $request['priv_pass'] ?? $this->defaultSnmpConfig['v3_priv_pass'] ?? '');
        $contextName = trim((string) ($request['v3_context'] ?? $request['context_name'] ?? $this->defaultSnmpConfig['v3_context'] ?? ''));

        if ($isV3) {
            if ($secName === '' && $community !== '') {
                $secName = $community;
            }
            if ($secName === '' || strlen($secName) > 128 || preg_match('/[\x00-\x1F\x7F]/', $secName) === 1) {
                throw new DiscoveryException('SNMP v3 Security Name (Username) is required and must not contain control characters.');
            }
            if (!in_array($secLevel, ['noAuthNoPriv', 'authNoPriv', 'authPriv'], true)) {
                $secLevel = 'authPriv';
            }
            if (($secLevel === 'authNoPriv' || $secLevel === 'authPriv') && $authPass === '') {
                throw new DiscoveryException('SNMP v3 Auth Passphrase is required for ' . $secLevel . '.');
            }
            if ($secLevel === 'authPriv' && $privPass === '') {
                throw new DiscoveryException('SNMP v3 Privacy Passphrase is required for authPriv.');
            }
        } else {
            if ($community === '' || strlen($community) > 128 || preg_match('/[\x00-\x1F\x7F]/', $community) === 1) {
                throw new DiscoveryException('SNMP community is required and must not contain control characters.');
            }
        }

        $this->applyRuntimeTimeout($scanTimeoutSec);

        $cacheKey = md5($host . ':' . $port . ':' . ($isV3 ? $secName . ':' . $secLevel : $community));
        $cacheFile = SNMP_BRIDGE_ROOT . '/storage/cache/identity_' . $cacheKey . '.json';
        $cacheTtl = (int) ($this->defaultSnmpConfig['identity_cache_ttl'] ?? 120);
        $cachedIdentity = null;
        
        if ($cacheTtl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            $cachedContent = file_get_contents($cacheFile);
            if ($cachedContent !== false) {
                $cachedIdentity = json_decode($cachedContent, true);
            }
        }

        $sessionTimeoutUsec = (int) $this->defaultSnmpConfig['timeout_usec'];
        if (isset($this->defaultSnmpConfig['adaptive_timeouts']) && $this->defaultSnmpConfig['adaptive_timeouts']) {
            if ($cachedIdentity && isset($cachedIdentity['latency_usec'])) {
                // If we know it responds fast, tighten the timeout to 3x the observed latency, min 200ms
                $sessionTimeoutUsec = max(200000, $cachedIdentity['latency_usec'] * 3);
            }
        }

        $session = new SnmpSession(
            $host,
            $isV3 ? $secName : $community,
            $version,
            $port,
            $sessionTimeoutUsec,
            (int) $this->defaultSnmpConfig['retries'],
            (int) $this->defaultSnmpConfig['max_oids'],
            (bool) $this->defaultSnmpConfig['quick_print'],
            (bool) ($this->defaultSnmpConfig['debug'] ?? false),
            $secLevel,
            $authProto,
            $authPass,
            $privProto,
            $privPass,
            $contextName,
        );

        try {
            $walker = new SnmpWalker($session);
            
            if ($cachedIdentity !== null && isset($cachedIdentity['sysDescr'], $cachedIdentity['sysObjectId'])) {
                $sysDescr = $cachedIdentity['sysDescr'];
                $sysObjectId = $cachedIdentity['sysObjectId'];
                $latencyUsec = $cachedIdentity['latency_usec'] ?? $sessionTimeoutUsec;
            } else {
                $startId = microtime(true);
                $sysDescr = $walker->get(SnmpHelper::SYS_DESCR) ?? '';
                $sysObjectId = $walker->get(SnmpHelper::SYS_OBJECT_ID) ?? '';
                $latencyUsec = (int) ((microtime(true) - $startId) * 1000000);

                if ((bool) ($this->defaultSnmpConfig['debug'] ?? false)) {
                    error_log(sprintf(
                        'SNMP identity resolved for %s: sysObjectID=%s, sysDescr=%s',
                        $host,
                        $sysObjectId,
                        $sysDescr,
                    ));
                }

                // Fallback for embedded devices (like CCTV) that don't support standard MIB-II
                if ($sysDescr === '' || $sysObjectId === '') {
                    // Try Dahua Device Type OID
                    $dahuaType = $walker->get('1.3.6.1.4.1.1004849.2.1.2.6.0');
                    if ($dahuaType !== null && $dahuaType !== '') {
                        $sysDescr = 'Dahua CCTV Device: ' . $dahuaType;
                        $sysObjectId = '1.3.6.1.4.1.1004849';
                    } else {
                        return $this->failureResult(
                            $host,
                            $version,
                            $port,
                            $isV3 ? '' : $community,
                            'not_found',
                            'Device did not return sysDescr/sysObjectID, and fallback probes failed. Check IP, UDP/161, SNMP version, and credentials.',
                            $this->scanMetadata(
                                $scanStartedAt,
                                $scanTimeoutSec,
                                $scanMaxSensors,
                                $previewLimit,
                                1,
                                microtime(true) >= $scanDeadline,
                            ),
                            $isV3 ? [
                                'snmp_security_level' => $secLevel,
                                'snmp_security_name' => $secName,
                            ] : [],
                        );
                    }
                }
                
                if ($cacheTtl > 0) {
                    @file_put_contents($cacheFile, json_encode([
                        'sysDescr' => $sysDescr,
                        'sysObjectId' => $sysObjectId,
                        'latency_usec' => $latencyUsec,
                    ]));
                }
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
                'snmp_community' => $isV3 ? '' : $community,
                'snmp_security_level' => $isV3 ? $secLevel : null,
                'snmp_security_name' => $isV3 ? $secName : null,
                'snmp_auth_protocol' => ($isV3 && $secLevel !== 'noAuthNoPriv') ? $authProto : null,
                'snmp_auth_passphrase' => ($isV3 && $secLevel !== 'noAuthNoPriv') ? $authPass : null,
                'snmp_priv_protocol' => ($isV3 && $secLevel === 'authPriv') ? $privProto : null,
                'snmp_priv_passphrase' => ($isV3 && $secLevel === 'authPriv') ? $privPass : null,
                'snmp_context_name' => $isV3 ? $contextName : null,
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
                    'community' => $isV3 ? '' : $community,
                    'port' => $port,
                    'discovery_profile' => $discoveryProfile,
                    'scan_started_at' => $scanStartedAt,
                    'scan_deadline' => $scanDeadline,
                    'scan_timeout_sec' => $scanTimeoutSec,
                    'scan_max_sensors' => $scanMaxSensors,
                    'scan_result_preview_limit' => $previewLimit,
                    'translate_max_sensors' => $translateMaxSensors,
                    'v3_user' => $isV3 ? $secName : null,
                    'v3_sec_level' => $isV3 ? $secLevel : null,
                ],
            );

            $sensors = $this->pipeline->discover($context);
            $this->sensorRepository->replaceForDevice($deviceId, $sensors);
            $deadlineReached = microtime(true) >= $scanDeadline;

            return [
                'device' => $device,
                'vendor' => $vendor->name(),
                'sensors' => $sensors,
                'scan' => $this->scanMetadata(
                    $scanStartedAt,
                    $scanTimeoutSec,
                    $scanMaxSensors,
                    $previewLimit,
                    count($sensors),
                    $deadlineReached,
                ),
            ];
        } catch (Throwable $throwable) {
            error_log(sprintf('[SNMP Explorer] Scan error for %s: %s', $host, $throwable->getMessage()));

            return $this->failureResult(
                $host,
                $version,
                $port,
                $isV3 ? '' : $community,
                'error',
                $throwable->getMessage(),
                $this->scanMetadata(
                    $scanStartedAt,
                    $scanTimeoutSec,
                    $scanMaxSensors,
                    $previewLimit,
                    1,
                    microtime(true) >= $scanDeadline,
                ),
                $isV3 ? [
                    'snmp_security_level' => $secLevel,
                    'snmp_security_name' => $secName,
                ] : [],
            );
        } finally {
            $session->close();
        }
    }

    /**
     * @param array<string, mixed>|null $scan
     * @param array<string, mixed> $extra
     * @return array{device:array<string, mixed>, vendor:string, sensors:list<array<string, mixed>>, scan:array<string, mixed>}
     */
    private function failureResult(
        string $host,
        string $version,
        int $port,
        string $community,
        string $status,
        string $message,
        ?array $scan = null,
        array $extra = [],
    ): array {
        return [
            'device' => array_merge([
                'ip_address' => $host,
                'hostname' => $host,
                'vendor' => 'unknown',
                'sys_object_id' => $status,
                'sys_descr' => $message,
                'snmp_version' => $version,
                'snmp_port' => $port,
                'snmp_community' => $community,
                'status' => $status,
            ], $extra),
            'vendor' => 'unknown',
            'sensors' => [
                [
                    'sensor_class' => 'status',
                    'sensor_name' => 'SNMP Status',
                    'sensor_type' => $status,
                    'interface_name' => null,
                    'raw_value' => $message,
                    'normalized_value' => null,
                    'unit' => '',
                    'oid' => SnmpHelper::SYS_DESCR,
                    'status' => $status,
                    'metadata' => [
                        'description' => $message,
                    ],
                ],
            ],
            'scan' => $scan ?? [
                'duration_sec' => 0.0,
                'timeout_sec' => $this->scanTimeoutSeconds(),
                'max_sensors' => $this->scanMaxSensors(),
                'preview_limit' => $this->scanResultPreviewLimit(),
                'sensor_count' => 1,
                'limit_reached' => false,
                'deadline_reached' => false,
            ],
        ];
    }

    private function scanTimeoutSeconds(): int
    {
        return max(10, (int) ($this->defaultSnmpConfig['scan_timeout_sec'] ?? 45));
    }

    private function scanMaxSensors(): int
    {
        return max(1, (int) ($this->defaultSnmpConfig['scan_max_sensors'] ?? 10000));
    }

    private function scanResultPreviewLimit(): int
    {
        return max(50, min(2000, (int) ($this->defaultSnmpConfig['scan_result_preview_limit'] ?? 500)));
    }

    private function translateMaxSensors(): int
    {
        return max(0, (int) ($this->defaultSnmpConfig['translate_max_sensors'] ?? 1500));
    }

    private function applyRuntimeTimeout(int $scanTimeoutSec): void
    {
        if (!((bool) ($this->defaultSnmpConfig['scan_hard_timeout'] ?? true))) {
            return;
        }

        $runtimeLimit = $scanTimeoutSec + 15;

        @set_time_limit($runtimeLimit);
        @ini_set('max_execution_time', (string) $runtimeLimit);
    }

    private function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || preg_match('/[\s\/\\\\@\x00-\x1F\x7F]/', $host) === 1) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (str_contains($host, ':')) {
            return false;
        }

        return preg_match('/^(?=.{1,253}$)(?!-)(?:[A-Za-z0-9-]{1,63}\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $host) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function scanMetadata(
        float $startedAt,
        int $timeoutSec,
        int $maxSensors,
        int $previewLimit,
        int $sensorCount,
        bool $deadlineReached,
    ): array {
        return [
            'duration_sec' => round(microtime(true) - $startedAt, 3),
            'timeout_sec' => $timeoutSec,
            'max_sensors' => $maxSensors,
            'preview_limit' => $previewLimit,
            'sensor_count' => $sensorCount,
            'limit_reached' => $sensorCount >= $maxSensors,
            'deadline_reached' => $deadlineReached,
        ];
    }
}
