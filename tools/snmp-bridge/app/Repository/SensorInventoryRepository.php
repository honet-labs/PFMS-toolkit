<?php

declare(strict_types=1);

namespace SnmpBridge\Repository;

use PDO;

final class SensorInventoryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<array<string, mixed>> $sensors
     */
    public function replaceForDevice(int $deviceId, array $sensors): void
    {
        foreach ($sensors as $sensor) {
            $this->upsert($deviceId, $sensor);
        }
    }

    /**
     * @param array<string, mixed> $sensor
     */
    public function upsert(int $deviceId, array $sensor): void
    {
        $sql = <<<'SQL'
            INSERT INTO sensor_inventory (
                device_id,
                vendor,
                ip_address,
                sensor_class,
                sensor_name,
                sensor_type,
                interface_index,
                interface_name,
                entity_index,
                oid,
                raw_value,
                normalized_value,
                unit,
                scale,
                `precision`,
                `status`,
                metadata_json,
                discovered_at,
                updated_at
            ) VALUES (
                :device_id,
                :vendor,
                :ip_address,
                :sensor_class,
                :sensor_name,
                :sensor_type,
                :interface_index,
                :interface_name,
                :entity_index,
                :oid,
                :raw_value,
                :normalized_value,
                :unit,
                :scale,
                :precision,
                :status,
                :metadata_json,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                vendor = VALUES(vendor),
                ip_address = VALUES(ip_address),
                sensor_type = VALUES(sensor_type),
                interface_index = VALUES(interface_index),
                interface_name = VALUES(interface_name),
                entity_index = VALUES(entity_index),
                raw_value = VALUES(raw_value),
                normalized_value = VALUES(normalized_value),
                unit = VALUES(unit),
                scale = VALUES(scale),
                `precision` = VALUES(`precision`),
                `status` = VALUES(`status`),
                metadata_json = VALUES(metadata_json),
                updated_at = NOW()
            SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            'device_id' => $deviceId,
            'vendor' => $sensor['vendor'] ?? null,
            'ip_address' => $sensor['ip_address'] ?? null,
            'sensor_class' => $sensor['sensor_class'],
            'sensor_name' => $sensor['sensor_name'],
            'sensor_type' => $sensor['sensor_type'] ?? null,
            'interface_index' => $sensor['interface_index'] ?? null,
            'interface_name' => $sensor['interface_name'] ?? null,
            'entity_index' => $sensor['entity_index'] ?? null,
            'oid' => $sensor['oid'],
            'raw_value' => isset($sensor['raw_value']) ? (string) $sensor['raw_value'] : null,
            'normalized_value' => $sensor['normalized_value'] ?? null,
            'unit' => $sensor['unit'] ?? null,
            'scale' => isset($sensor['scale']) ? (string) $sensor['scale'] : null,
            'precision' => $sensor['precision'] ?? null,
            'status' => $sensor['status'] ?? 'unknown',
            'metadata_json' => $this->metadataJson($sensor),
        ]);
    }

    /**
     * @param array<string, mixed> $sensor
     */
    private function metadataJson(array $sensor): string
    {
        if (isset($sensor['metadata_json']) && is_string($sensor['metadata_json']) && $sensor['metadata_json'] !== '') {
            return $sensor['metadata_json'];
        }

        return json_encode($sensor['metadata'] ?? [], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string|null> $filters
     * @return list<array<string, mixed>>
     */
    public function all(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (($filters['vendor'] ?? '') !== '') {
            $where[] = 'si.vendor = :vendor';
            $params['vendor'] = $filters['vendor'];
        }

        if (($filters['ip_address'] ?? '') !== '') {
            $where[] = 'si.ip_address LIKE :ip_address';
            $params['ip_address'] = '%' . $filters['ip_address'] . '%';
        }

        $sql = 'SELECT si.*, d.hostname FROM sensor_inventory si INNER JOIN devices d ON d.id = si.device_id';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY si.updated_at DESC, si.vendor ASC, si.ip_address ASC, si.sensor_name ASC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @return list<string>
     */
    public function vendors(): array
    {
        $statement = $this->pdo->query('SELECT DISTINCT vendor FROM sensor_inventory WHERE vendor IS NOT NULL ORDER BY vendor');

        return array_map(static fn (array $row): string => (string) $row['vendor'], $statement->fetchAll());
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            'SELECT si.*, d.snmp_community FROM sensor_inventory si INNER JOIN devices d ON d.id = si.device_id WHERE si.id IN (' . $placeholders . ')'
        );
        $statement->execute($ids);

        return $statement->fetchAll();
    }

    public function markProvisioned(int $sensorId, int $agentId, int $moduleId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE sensor_inventory SET provisioned = 1, pandora_agent_id = :agent_id, pandora_module_id = :module_id, provisioned_at = NOW(), updated_at = NOW() WHERE id = :id'
        );
        $statement->execute([
            'id' => $sensorId,
            'agent_id' => $agentId,
            'module_id' => $moduleId,
        ]);
    }
}
