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
    public function build(array $sensor, int $agentId): array
    {
        $rawValue = $sensor['raw_value'] ?? null;
        $isAlphaNumeric = is_string($rawValue) && preg_match('/[a-zA-Z]/', $rawValue);

        if (($sensor['normalized_value'] ?? null) === null && !$isAlphaNumeric) {
            throw new InvalidArgumentException(
                'Only numeric or string discovered sensors can be provisioned into Pandora.'
            );
        }

        $idTipoModulo = $this->moduleTypeDb($sensor, $isAlphaNumeric);
        $moduleName = $this->moduleName($sensor);
        $customId = $this->customId((int) $sensor['id']);
        
        $wizardMode = $this->config['wizard_mode'] ?? true;
        
        $extendedInfo = $wizardMode ? '' : $this->buildExtendedInfo($sensor);
        $extraData = $wizardMode ? '' : $this->buildExtraData($sensor);

        $snmpVersion = (string) ($sensor['snmp_version'] ?? '2c');
        $isV3 = in_array(strtolower($snmpVersion), ['3', 'v3'], true);

        return [
            'id_agente' => $agentId,
            'id_tipo_modulo' => $idTipoModulo,
            'id_modulo' => self::MODULE_SERVER_NETWORK,
            'id_module_group' => (int) ($this->config['default_module_group_id'] ?? 1),
            'nombre' => $moduleName,
            'descripcion' => $this->buildDescription($sensor),
            'tcp_port' => (int) ($sensor['snmp_port'] ?? 161),
            'snmp_oid' => (string) $sensor['oid'],
            'snmp_community' => (string) ($sensor['snmp_community'] ?? 'public'),
            'ip_target' => (string) $sensor['ip_address'],
            'module_interval' => (int) ($this->config['module_interval'] ?? self::DEFAULT_INTERVAL),
            'max_timeout' => (int) ($this->config['module_timeout'] ?? self::DEFAULT_SNMP_TIMEOUT),
            'max_retries' => (int) ($this->config['module_retries'] ?? self::DEFAULT_SNMP_RETRIES),
            'unit' => (string) ($sensor['unit'] ?? ''),
            'history_data' => 1,
            'disabled' => 0,
            'wizard_level' => 'nowizard',
            'quiet' => 0,
            'custom_id' => $wizardMode ? '' : $customId,
            'extended_info' => $extendedInfo,
            'extra_data' => $extraData,
            'snmp_version' => $isV3 ? 3 : (in_array(strtolower($snmpVersion), ['1', 'v1'], true) ? 1 : 2),
            'snmp3_sec_level' => $sensor['snmp_security_level'] ?? null,
            'snmp3_auth_user' => $sensor['snmp_security_name'] ?? null,
            'snmp3_auth_method' => $sensor['snmp_auth_protocol'] ?? null,
            'snmp3_auth_pass' => $sensor['snmp_auth_passphrase'] ?? null,
            'snmp3_priv_method' => $sensor['snmp_priv_protocol'] ?? null,
            'snmp3_priv_pass' => $sensor['snmp_priv_passphrase'] ?? null,
            'plugin_user' => $isV3 ? ($sensor['snmp_security_name'] ?? null) : null,
            'plugin_pass' => $isV3 ? ($sensor['snmp_auth_passphrase'] ?? null) : null,
            'plugin_parameter' => $isV3 ? ($sensor['snmp_priv_passphrase'] ?? null) : null,
        ];
    }

    /**
     * Build Pandora SNMP module for XML format
     */
    public function buildForXml(array $sensor): array
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
            'description' => $this->buildDescription($sensor),
            'type' => $moduleType,
            'data' => (string) $value,
            'unit' => (string) ($sensor['unit'] ?? ''),
            'module_group' => 'snmp-bridge',
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

    private function moduleType(array $sensor, bool $isAlphaNumeric): string
    {
        if ($this->isPrinterPagesCountUsed($sensor)) {
            return 'generic_data';
        }

        if ($isAlphaNumeric && ($sensor['normalized_value'] ?? null) === null) {
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

    private function buildDescription(array $sensor): string
    {
        $class = $sensor['sensor_class'] ?? '';
        if ($class === 'interface' && isset($sensor['interface_name'])) {
            return $sensor['interface_name'];
        }
        if ($class === 'dahua_camera' && !empty($sensor['metadata']['ip_address'])) {
            return 'Camera IP: ' . $sensor['metadata']['ip_address'];
        }
        return $class ?: 'Sensor';
    }

    private function moduleName(array $sensor): string
    {
        $name = $this->namingService->formatPandoraModuleName($sensor);
        $name = preg_replace('/[^\w\s\-.\/:()%]/', '', $name);
        $name = trim($name);
        return substr($name, 0, 100);
    }
}
