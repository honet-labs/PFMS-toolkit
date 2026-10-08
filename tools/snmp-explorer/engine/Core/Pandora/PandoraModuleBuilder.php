<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Pandora;

use InvalidArgumentException;
use SnmpBridge\Services\SnmpNamingService;

/**
 * Builds Pandora-compatible SNMP modules from discovered sensors
 */
final class PandoraModuleBuilder
{
    // Pandora module type constants
    private const MODULE_TYPE_REMOTE_SNMP = 15;
    private const MODULE_TYPE_REMOTE_SNMP_INC = 16;
    private const MODULE_TYPE_REMOTE_SNMP_STRING = 17;
    private const MODULE_TYPE_REMOTE_SNMP_PROC = 18;

    // Pandora module server types
    private const MODULE_SERVER_LOCAL = 1;
    private const MODULE_SERVER_NETWORK = 2;
    private const MODULE_SERVER_WEB = 3;

    // Default values matching Pandora
    private const DEFAULT_SNMP_TIMEOUT = 10;
    private const DEFAULT_SNMP_RETRIES = 3;
    private const DEFAULT_INTERVAL = 300;

    public function __construct(
        private readonly array $config = [],
        private readonly SnmpNamingService $namingService = new SnmpNamingService(),
    ) {
    }

    /**
     * Build Pandora SNMP module for Direct DB insertion
     */
    public function build(array $sensor, int $agentId, array $overrides = []): array
    {
        $rawValue = $sensor['raw_value'] ?? null;
        $hasRawString = is_string($rawValue) && trim($rawValue) !== '';
        $isNumeric = ($sensor['normalized_value'] ?? null) !== null;

        if (!$isNumeric && !$hasRawString) {
            throw new InvalidArgumentException(
                'Only numeric or string discovered sensors can be provisioned into Pandora.'
            );
        }

        $idTipoModulo = $this->moduleTypeDb($sensor, !$isNumeric && $hasRawString);
        $moduleName = $this->moduleName($sensor);
        $customId = $this->customId((int) $sensor['id']);
        
        $wizardMode = $this->config['wizard_mode'] ?? true;
        
        $extendedInfo = $wizardMode ? '' : $this->buildExtendedInfo($sensor);
        $extraData = $wizardMode ? '' : $this->buildExtraData($sensor);

        $snmpVersion = (string) ($sensor['snmp_version'] ?? '2c');
        $lowerVersion = strtolower(trim($snmpVersion));
        $isV3 = str_contains($lowerVersion, '3') || in_array($lowerVersion, ['3', 'v3', 'snmp3', 'snmpv3', 'snmp v3'], true);
        $isV1 = !$isV3 && (str_contains($lowerVersion, '1') || in_array($lowerVersion, ['1', 'v1', 'snmp1', 'snmpv1', 'snmp v1'], true));

        $interval = (int) ($overrides['module_interval'] ?? $this->config['module_interval'] ?? self::DEFAULT_INTERVAL);
        if ($interval <= 0) {
            $interval = self::DEFAULT_INTERVAL;
        }

        $secLevel = (string) ($sensor['snmp_security_level'] ?? '');
        if ($secLevel === '' && $isV3) {
            $secLevel = 'authNoPriv';
        }

        $authProto = (string) ($sensor['snmp_auth_protocol'] ?? '');
        if ($authProto === '' && $isV3) {
            $authProto = 'SHA';
        }

        $unit = (string) ($sensor['unit'] ?? '');

        // Resolve post_process multiplier for percentage metrics or raw conversions
        $postProcess = $sensor['post_process'] ?? null;
        if ($postProcess === null && isset($sensor['metadata']) && is_array($sensor['metadata'])) {
            $postProcess = $sensor['metadata']['post_process'] ?? null;
        }
        if ($postProcess === null && !empty($sensor['metadata_json']) && is_string($sensor['metadata_json'])) {
            $decodedMeta = json_decode($sensor['metadata_json'], true);
            if (is_array($decodedMeta)) {
                $postProcess = $decodedMeta['post_process'] ?? null;
            }
        }

        // Automatic fallback calculation for percentage storage/memory metrics if missing
        if ($postProcess === null && $unit === '%') {
            $meta = $sensor['metadata'] ?? [];
            if (empty($meta) && !empty($sensor['metadata_json']) && is_string($sensor['metadata_json'])) {
                $meta = json_decode($sensor['metadata_json'], true) ?: [];
            }
            if (!empty($meta['total_units']) && (float) $meta['total_units'] > 0) {
                $postProcess = 100.0 / (float) $meta['total_units'];
            } elseif (!empty($meta['total_bytes']) && (float) $meta['total_bytes'] > 0) {
                $postProcess = 100.0 / (float) $meta['total_bytes'];
            } elseif (!empty($meta['storage_size_units']) && (float) $meta['storage_size_units'] > 0) {
                $postProcess = 100.0 / (float) $meta['storage_size_units'];
            }
        }

        // Automatic scale calculation from scale & precision
        if ($postProcess === null && isset($sensor['scale']) && isset($sensor['precision'])) {
            $scaleVal = trim((string) $sensor['scale']);
            $precVal = (int) $sensor['precision'];
            if ($precVal > 0 || ($scaleVal !== '9' && $scaleVal !== 'units' && $scaleVal !== 'unit' && $scaleVal !== '')) {
                $scaleNormalizer = new \SnmpBridge\Core\Normalize\ScaleNormalizer();
                $calcFactor = $scaleNormalizer->normalize(1.0, $scaleVal, $precVal);
                if ($calcFactor > 0 && abs($calcFactor - 1.0) > 0.00001) {
                    $postProcess = $calcFactor;
                }
            }
        }

        // Automatic fallback from raw vs normalized values
        if ($postProcess === null && !empty($sensor['raw_value']) && !empty($sensor['normalized_value']) && is_numeric($sensor['raw_value']) && is_numeric($sensor['normalized_value'])) {
            $rawNum = (float) $sensor['raw_value'];
            $normNum = (float) $sensor['normalized_value'];
            if ($rawNum != 0.0 && abs($rawNum - $normNum) > 0.00001) {
                $postProcess = $normNum / $rawNum;
            }
        }

        // Heuristic fallback for temperature in deci-degrees (raw integer 150 - 1200 with unit C)
        if ($postProcess === null && ($unit === 'C' || $unit === '°C' || strtolower((string)($sensor['sensor_type'] ?? '')) === 'celsius' || strtolower((string)($sensor['sensor_type'] ?? '')) === 'temperature')) {
            $rawNum = (float) ($sensor['raw_value'] ?? 0);
            if ($rawNum >= 150.0 && $rawNum <= 1200.0) {
                $postProcess = 0.1;
            }
        }

        // Heuristic fallback for current in milliAmperes (raw integer >= 100 with unit A)
        if ($postProcess === null && ($unit === 'A' || strtolower((string)($sensor['sensor_type'] ?? '')) === 'amperes' || strtolower((string)($sensor['sensor_type'] ?? '')) === 'current')) {
            $rawNum = (float) ($sensor['raw_value'] ?? 0);
            if ($rawNum >= 100.0) {
                $postProcess = 0.001;
            }
        }

        $formattedPostProcess = null;
        if ($postProcess !== null && is_numeric($postProcess)) {
            $num = (float) $postProcess;
            if ($num > 0) {
                $formattedPostProcess = (float) rtrim(rtrim(sprintf('%.12f', $num), '0'), '.');
            }
        }

        $minVal = null;
        $maxVal = null;
        if ($unit === '%') {
            $minVal = 0.0;
            $maxVal = 100.0;
        }

        return [
            'id_agente' => $agentId,
            'id_tipo_modulo' => $idTipoModulo,
            'id_modulo' => self::MODULE_SERVER_NETWORK,
            'id_module_group' => (int) ($overrides['id_module_group'] ?? $sensor['id_module_group'] ?? ($this->config['default_module_group_id'] ?? 1)),
            'nombre' => $moduleName,
            'descripcion' => $overrides['descripcion'] ?? $overrides['description'] ?? $this->buildDescription($sensor),
            'tcp_port' => (int) ($sensor['snmp_port'] ?? 161),
            'snmp_oid' => (string) $sensor['oid'],
            'snmp_community' => $isV3 ? '' : (string) ($sensor['snmp_community'] ?? 'public'),
            'ip_target' => (string) $sensor['ip_address'],
            'module_interval' => $interval,
            'max_timeout' => (int) ($this->config['module_timeout'] ?? self::DEFAULT_SNMP_TIMEOUT),
            'max_retries' => (int) ($this->config['module_retries'] ?? self::DEFAULT_SNMP_RETRIES),
            'unit' => $unit,
            'post_process' => $formattedPostProcess,
            'min' => $minVal,
            'max' => $maxVal,
            'history_data' => 1,
            'disabled' => 0,
            'wizard_level' => 'nowizard',
            'quiet' => 0,
            'custom_id' => $wizardMode ? '' : $customId,
            'extended_info' => $extendedInfo,
            'extra_data' => $extraData,
            'snmp_version' => $isV3 ? 3 : ($isV1 ? 1 : 2),
            'snmp3_sec_level' => $isV3 ? $secLevel : null,
            'snmp3_security_level' => $isV3 ? $secLevel : null,
            'snmp3_auth_user' => $isV3 ? ($sensor['snmp_security_name'] ?? null) : null,
            'snmp3_auth_method' => $isV3 ? $authProto : null,
            'snmp3_auth_pass' => $isV3 ? ($sensor['snmp_auth_passphrase'] ?? null) : null,
            'snmp3_priv_method' => $isV3 ? ($sensor['snmp_priv_protocol'] ?? null) : null,
            'snmp3_privacy_method' => $isV3 ? ($sensor['snmp_priv_protocol'] ?? null) : null,
            'snmp3_priv_pass' => $isV3 ? ($sensor['snmp_priv_passphrase'] ?? null) : null,
            'snmp3_privacy_pass' => $isV3 ? ($sensor['snmp_priv_passphrase'] ?? null) : null,
            'snmp3_context' => $isV3 ? ($sensor['snmp_context_name'] ?? null) : null,
            'snmp3_context_name' => $isV3 ? ($sensor['snmp_context_name'] ?? null) : null,
            'plugin_user' => $isV3 ? ($sensor['snmp_security_name'] ?? null) : null,
            'plugin_pass' => $isV3 ? ($sensor['snmp_auth_passphrase'] ?? null) : null,
            'plugin_parameter' => $isV3 ? ($sensor['snmp_priv_passphrase'] ?? null) : null,
        ];
    }

    /**
     * Build Pandora SNMP module for XML format
     */
    public function buildForXml(array $sensor, array $overrides = []): array
    {
        $rawValue = $sensor['raw_value'] ?? null;
        $isAlphaNumeric = is_string($rawValue) && preg_match('/[a-zA-Z]/', $rawValue);

        if (($sensor['normalized_value'] ?? null) === null && !$isAlphaNumeric) {
            throw new InvalidArgumentException(
                "Cannot build Pandora XML module for sensor ID {$sensor['id']}: no numeric or string value found."
            );
        }

        $moduleType = $this->moduleType($sensor, $isAlphaNumeric);
        $moduleName = $this->moduleName($sensor);
        $value = $sensor['normalized_value'] ?? $sensor['raw_value'];

        return [
            'name' => $moduleName,
            'description' => $overrides['descripcion'] ?? $overrides['description'] ?? $this->buildDescription($sensor),
            'type' => $moduleType,
            'data' => (string) $value,
            'unit' => (string) ($sensor['unit'] ?? ''),
            'module_group' => $overrides['module_group'] ?? $this->formatModuleGroupName((string) ($sensor['sensor_class'] ?? 'General')),
        ];
    }

    public function customId(int $sensorId): string
    {
        return 'snmpbridge:sensor:' . $sensorId;
    }

    private function moduleTypeDb(array $sensor, bool $isAlphaNumeric): int
    {
        $typeStr = $this->moduleType($sensor, $isAlphaNumeric);
        return match ($typeStr) {
            'generic_data_string' => self::MODULE_TYPE_REMOTE_SNMP_STRING,
            'generic_data_inc' => self::MODULE_TYPE_REMOTE_SNMP_INC,
            'generic_proc' => self::MODULE_TYPE_REMOTE_SNMP_PROC,
            default => self::MODULE_TYPE_REMOTE_SNMP,
        };
    }

    private function moduleType(array $sensor, bool $isStringSensor): string
    {
        if ($this->isPrinterPagesCountUsed($sensor)) {
            return 'generic_data';
        }

        if ($isStringSensor) {
            return 'generic_data_string';
        }

        $sensorType = strtolower((string) ($sensor['sensor_type'] ?? ''));

        foreach (['octets', 'packets', 'errors', 'discards', 'counter', 'queue'] as $counterToken) {
            if (str_contains($sensorType, $counterToken)) {
                return 'generic_data_inc';
            }
        }

        return 'generic_data';
    }

    private function isPrinterPagesCountUsed(array $sensor): bool
    {
        if (strtolower((string) ($sensor['sensor_class'] ?? '')) !== 'printer') {
            return false;
        }

        $sensorName = strtolower(trim((string) ($sensor['sensor_name'] ?? '')));
        $oid = trim((string) ($sensor['oid'] ?? ''), '.');
        $source = strtolower((string) ($sensor['metadata']['source'] ?? ''));

        return preg_match('/^printer\s*-\s*pages\s+count\s+used(?:\s*\[.+\])?$/i', $sensorName) === 1
            || str_starts_with($oid, '1.3.6.1.2.1.43.10.2.1.4.')
            || str_contains($source, 'prtmarkerlifecount');
    }

    private function buildExtendedInfo(array $sensor): string
    {
        $info = [
            'provisioned_by' => 'snmp-bridge',
            'sensor_inventory_id' => (int) ($sensor['id'] ?? 0),
            'sensor_class' => $sensor['sensor_class'] ?? null,
            'vendor' => $sensor['vendor'] ?? null,
            'interface_name' => $sensor['interface_name'] ?? null,
            'entity_index' => $sensor['entity_index'] ?? null,
            'device_ip' => $sensor['ip_address'] ?? null,
        ];

        try {
            return json_encode($info, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            return json_encode([]);
        }
    }

    private function buildExtraData(array $sensor): string
    {
        $data = [
            'bridge' => 'snmp-bridge',
            'last_discovered_value' => $sensor['normalized_value'] ?? null,
            'raw_value' => $sensor['raw_value'] ?? null,
            'discovered_at' => date('Y-m-d H:i:s'),
        ];

        try {
            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            return json_encode([]);
        }
    }

    public function formatModuleGroupName(string $sensorClass): string
    {
        $class = strtolower(trim($sensorClass));
        return match ($class) {
            'environmental', 'environment' => 'Environmental',
            'interface', 'network', 'networking' => 'Networking',
            'storage' => 'Storage',
            'memory' => 'Memory',
            'system' => 'System',
            'optical_dom', 'optical_power', 'optical' => 'Optical',
            'printer' => 'Printer',
            'gpon' => 'GPON',
            'dahua_camera', 'camera' => 'Camera',
            'bgp', 'routing' => 'Routing',
            'f5_virtual_server', 'f5_pool_member' => 'F5 BIG-IP',
            default => ucwords(str_replace('_', ' ', $class)) ?: 'General',
        };
    }

    private function buildDescription(array $sensor): string
    {
        $sensorName = trim((string) ($sensor['sensor_name'] ?? ''));
        $class = trim((string) ($sensor['sensor_class'] ?? ''));

        if ($class === 'interface' && !empty($sensor['interface_name'])) {
            $interfaceName = trim((string) $sensor['interface_name']);
            if ($sensorName !== '' && stripos($sensorName, $interfaceName) === false) {
                return $interfaceName . ' - ' . $sensorName;
            }
            return $sensorName ?: $interfaceName;
        }

        if ($class === 'dahua_camera' && !empty($sensor['metadata']['ip_address'])) {
            return ($sensorName ? $sensorName . ' ' : '') . '(Camera IP: ' . $sensor['metadata']['ip_address'] . ')';
        }

        if ($sensorName !== '') {
            return $sensorName;
        }

        return $class ? $this->formatModuleGroupName($class) : 'SNMP Sensor';
    }

    public function moduleName(array $sensor): string
    {
        $name = $this->namingService->formatPandoraModuleName($sensor);
        $name = preg_replace('/[^\w\s\-.\/:()%]/', '', $name);
        $name = trim($name);
        return substr($name, 0, 100);
    }
}
