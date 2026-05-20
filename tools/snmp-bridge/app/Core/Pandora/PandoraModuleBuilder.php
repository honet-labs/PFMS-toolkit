<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Pandora;

use InvalidArgumentException;
use SnmpBridge\Services\SnmpNamingService;

final class PandoraModuleBuilder
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
        private readonly SnmpNamingService $namingService = new SnmpNamingService(),
    ) {
    }

    /**
     * @param array<string, mixed> $sensor
     * @return array<string, mixed>
     */
    public function build(array $sensor, int $agentId): array
    {
        if (($sensor['normalized_value'] ?? null) === null) {
            throw new InvalidArgumentException('Only numeric discovered sensors can be provisioned into Pandora.');
        }

        $moduleName = $this->moduleName($sensor);
        $customId = $this->customId((int) $sensor['id']);
        $extendedInfo = [
            'provisioned_by' => 'snmp-bridge',
            'sensor_inventory_id' => (int) $sensor['id'],
            'sensor_class' => $sensor['sensor_class'] ?? null,
            'vendor' => $sensor['vendor'] ?? null,
            'interface' => $sensor['interface_name'] ?? null,
            'entity_index' => $sensor['entity_index'] ?? null,
        ];

        return [
            'id_agente' => $agentId,
            'id_tipo_modulo' => (int) $this->config['remote_snmp_numeric_type_id'],
            'descripcion' => sprintf(
                'Provisioned by SNMP Bridge from %s sensor %s',
                (string) ($sensor['sensor_class'] ?? 'discovered'),
                (string) ($sensor['oid'] ?? ''),
            ),
            'extended_info' => json_encode($extendedInfo, JSON_THROW_ON_ERROR),
            'nombre' => $moduleName,
            'unit' => (string) ($sensor['unit'] ?? ''),
            'module_interval' => (int) $this->config['module_interval'],
            'snmp_community' => (string) ($sensor['snmp_community'] ?? ''),
            'snmp_oid' => (string) $sensor['oid'],
            'ip_target' => (string) $sensor['ip_address'],
            'id_module_group' => (int) $this->config['default_module_group_id'],
            'id_modulo' => (int) $this->config['network_server_module_id'],
            'disabled' => 0,
            'max_timeout' => (int) $this->config['module_timeout'],
            'max_retries' => (int) $this->config['module_retries'],
            'custom_id' => $customId,
            'history_data' => 1,
            'wizard_level' => 'nowizard',
            'quiet' => 0,
            'extra_data' => json_encode([
                'bridge' => 'snmp-bridge',
                'last_discovered_value' => $sensor['normalized_value'],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    public function customId(int $sensorId): string
    {
        return 'snmpbridge:sensor:' . $sensorId;
    }

    /**
     * @param array<string, mixed> $sensor
     */
    private function moduleName(array $sensor): string
    {
        return $this->namingService->formatPandoraModuleName($sensor);
    }
}
