<?php

declare(strict_types=1);

namespace SnmpBridge\Repository;

use PDO;
use PDOStatement;
use Throwable;

final readonly class SensorInventoryRepository
{
    private const int STALE_DELETE_INLINE_LIMIT = 500;
    private const string TEMP_KEEP_TABLE = 'snmp_bridge_sensor_keep';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<array<string, mixed>> $sensors
     */
    public function replaceForDevice(int $deviceId, array $sensors): void
    {
        $startedTransaction = !$this->pdo->inTransaction();

        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $uniqueSensors = $this->uniqueSensors($sensors);

            $this->deleteStaleForDevice($deviceId, $uniqueSensors);

            $this->bulkUpsert($deviceId, $uniqueSensors);

            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $throwable) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $sensor
     */
    public function upsert(int $deviceId, array $sensor): void
    {
        $this->executeUpsert($this->pdo->prepare($this->upsertSql()), $deviceId, $sensor);
    }

    private function upsertSql(): string
    {
        return <<<'SQL'
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
    }

    /**
     * @param array<string, mixed> $sensor
     */
    private function executeUpsert(PDOStatement $statement, int $deviceId, array $sensor): void
    {
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
     * @param list<array<string, mixed>> $sensors
     */
    private function bulkUpsert(int $deviceId, array $sensors): void
    {
        if ($sensors === []) {
            return;
        }

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isMysql = $driver === 'mysql';

        $sqlTemplate = $isMysql
            ? <<<'SQL'
                INSERT INTO sensor_inventory (
                    device_id, vendor, ip_address, sensor_class, sensor_name, sensor_type,
                    interface_index, interface_name, entity_index, oid, raw_value,
                    normalized_value, unit, scale, `precision`, `status`, metadata_json,
                    discovered_at, updated_at
                ) VALUES %s
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
                SQL
            : <<<'SQL'
                INSERT INTO sensor_inventory (
                    device_id, vendor, ip_address, sensor_class, sensor_name, sensor_type,
                    interface_index, interface_name, entity_index, oid, raw_value,
                    normalized_value, unit, scale, `precision`, `status`, metadata_json,
                    created_at, updated_at
                ) VALUES %s
                SQL;

        $nowPlaceholder = $isMysql ? 'NOW(), NOW()' : "datetime('now'), datetime('now')";
        $chunkSize = 500; // max rows per batched query

        foreach (array_chunk($sensors, $chunkSize) as $chunk) {
            $placeholders = [];
            $params = [];

            foreach ($chunk as $sensor) {
                $placeholders[] = sprintf('(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, %s)', $nowPlaceholder);
                
                $params[] = $deviceId;
                $params[] = $sensor['vendor'] ?? null;
                $params[] = $sensor['ip_address'] ?? null;
                $params[] = (string) $sensor['sensor_class'];
                $params[] = (string) $sensor['sensor_name'];
                $params[] = $sensor['sensor_type'] ?? null;
                $params[] = $sensor['interface_index'] ?? null;
                $params[] = $sensor['interface_name'] ?? null;
                $params[] = $sensor['entity_index'] ?? null;
                $params[] = (string) $sensor['oid'];
                $params[] = isset($sensor['raw_value']) ? (string) $sensor['raw_value'] : null;
                $params[] = $sensor['normalized_value'] ?? null;
                $params[] = $sensor['unit'] ?? null;
                $params[] = isset($sensor['scale']) ? (string) $sensor['scale'] : null;
                $params[] = $sensor['precision'] ?? null;
                $params[] = (string) ($sensor['status'] ?? 'unknown');
                $params[] = (string) $this->metadataJson($sensor);
            }

            if ($placeholders !== []) {
                $sql = sprintf($sqlTemplate, implode(', ', $placeholders));
                $statement = $this->pdo->prepare($sql);
                $statement->execute($params);
            }
        }
    }

    /**
     * @param array<string, mixed> $sensor
     */
    private function metadataJson(array $sensor): string
    {
        if (isset($sensor['metadata_json']) && is_string($sensor['metadata_json']) && $sensor['metadata_json'] !== '') {
            return $sensor['metadata_json'];
        }

        return json_encode($sensor['metadata'] ?? [], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
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
     * @param array<string, string|null> $filters
     * @return array{rows:list<array<string, mixed>>,total:int,page:int,per_page:int,pages:int}
     */
    public function paginate(array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $page = max(1, $page);
        $perPage = max(25, min(500, $perPage));
        [$whereSql, $params] = $this->filterSql($filters);

        $countStatement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM sensor_inventory si INNER JOIN devices d ON d.id = si.device_id' . $whereSql
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT si.*, d.hostname FROM sensor_inventory si INNER JOIN devices d ON d.id = si.device_id'
            . $whereSql
            . ' ORDER BY si.updated_at DESC, si.vendor ASC, si.ip_address ASC, si.sensor_name ASC LIMIT :limit OFFSET :offset';

        $statement = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }

        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'rows' => $statement->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
        ];
    }

    /**
     * @param array<string, string|null> $filters
     * @return array{total:int,provisioned:int,pending:int,provisionable:int,vendors:int}
     */
    public function stats(array $filters = []): array
    {
        [$whereSql, $params] = $this->filterSql($filters);
        $statement = $this->pdo->prepare(
            'SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN si.provisioned = 1 THEN 1 ELSE 0 END), 0) AS provisioned,
                COALESCE(SUM(CASE WHEN si.normalized_value IS NOT NULL OR si.raw_value REGEXP \'[[:alpha:]]\' THEN 1 ELSE 0 END), 0) AS provisionable,
                COUNT(DISTINCT si.vendor) AS vendors
            FROM sensor_inventory si
            INNER JOIN devices d ON d.id = si.device_id'
            . $whereSql
        );
        $statement->execute($params);
        $row = $statement->fetch() ?: [];
        $total = (int) ($row['total'] ?? 0);
        $provisioned = (int) ($row['provisioned'] ?? 0);

        return [
            'total' => $total,
            'provisioned' => $provisioned,
            'pending' => max(0, $total - $provisioned),
            'provisionable' => (int) ($row['provisionable'] ?? 0),
            'vendors' => (int) ($row['vendors'] ?? 0),
        ];
    }

    /**
     * @param array<string, string|null> $filters
     * @return array<string, int>
     */
    public function classCounts(array $filters = []): array
    {
        return $this->groupCounts('si.sensor_class', $filters);
    }

    /**
     * @param array<string, string|null> $filters
     * @return array<string, int>
     */
    public function vendorCounts(array $filters = []): array
    {
        return $this->groupCounts('si.vendor', $filters);
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
            'UPDATE sensor_inventory SET provisioned = 1, pandora_agent_id = :agent_id, pandora_module_id = :module_id, provisioned_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute([
            'id' => $sensorId,
            'agent_id' => $agentId,
            'module_id' => $moduleId,
        ]);
    }

    /**
     * @param list<array{0:int,1:int,2:int}> $rows
     */
    public function markProvisionedBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE sensor_inventory SET provisioned = 1, pandora_agent_id = :agent_id, pandora_module_id = :module_id, provisioned_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );

        foreach ($rows as [$sensorId, $agentId, $moduleId]) {
            $statement->execute([
                'id' => $sensorId,
                'agent_id' => $agentId,
                'module_id' => $moduleId,
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $sensors
     * @return list<array<string, mixed>>
     */
    private function uniqueSensors(array $sensors): array
    {
        $unique = [];

        foreach ($sensors as $sensor) {
            if (!isset($sensor['sensor_class'], $sensor['sensor_name'], $sensor['oid'])) {
                continue;
            }

            $key = implode('|', [
                (string) $sensor['sensor_class'],
                (string) $sensor['sensor_name'],
                (string) $sensor['oid'],
            ]);
            $unique[$key] = $sensor;
        }

        return array_values($unique);
    }

    /**
     * @param list<array<string, mixed>> $sensors
     */
    private function deleteStaleForDevice(int $deviceId, array $sensors): void
    {
        if ($sensors === []) {
            $statement = $this->pdo->prepare('DELETE FROM sensor_inventory WHERE device_id = :device_id');
            $statement->execute(['device_id' => $deviceId]);
            return;
        }

        if (count($sensors) > self::STALE_DELETE_INLINE_LIMIT) {
            $this->deleteStaleForDeviceUsingTemporaryTable($deviceId, $sensors);
            return;
        }

        $clauses = [];
        $params = ['device_id' => $deviceId];

        foreach ($sensors as $index => $sensor) {
            if (!isset($sensor['sensor_class'], $sensor['sensor_name'], $sensor['oid'])) {
                continue;
            }

            $classKey = 'sensor_class_' . $index;
            $nameKey = 'sensor_name_' . $index;
            $oidKey = 'oid_' . $index;
            $clauses[] = sprintf(
                '(sensor_class = :%s AND sensor_name = :%s AND oid = :%s)',
                $classKey,
                $nameKey,
                $oidKey,
            );
            $params[$classKey] = (string) $sensor['sensor_class'];
            $params[$nameKey] = (string) $sensor['sensor_name'];
            $params[$oidKey] = (string) $sensor['oid'];
        }

        if ($clauses === []) {
            return;
        }

        $statement = $this->pdo->prepare(
            'DELETE FROM sensor_inventory WHERE device_id = :device_id AND NOT (' . implode(' OR ', $clauses) . ')'
        );
        $statement->execute($params);
    }

    /**
     * @param list<array<string, mixed>> $sensors
     */
    private function deleteStaleForDeviceUsingTemporaryTable(int $deviceId, array $sensors): void
    {
        $table = self::TEMP_KEEP_TABLE;

        try {
            $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
            $this->pdo->exec(
                'CREATE TEMPORARY TABLE `' . $table . '` (
                    sensor_class VARCHAR(64) NOT NULL,
                    sensor_name VARCHAR(255) NOT NULL,
                    oid VARCHAR(512) NOT NULL,
                    INDEX keep_sensor_lookup (sensor_class, sensor_name(191), oid(191))
                ) ENGINE=InnoDB'
            );

            $this->insertTemporaryKeepRows($table, $sensors);

            $statement = $this->pdo->prepare(
                'DELETE si
                FROM sensor_inventory si
                LEFT JOIN `' . $table . '` keep_rows
                    ON keep_rows.sensor_class = si.sensor_class
                    AND keep_rows.sensor_name = si.sensor_name
                    AND keep_rows.oid = si.oid
                WHERE si.device_id = :device_id
                    AND keep_rows.oid IS NULL'
            );
            $statement->execute(['device_id' => $deviceId]);
        } finally {
            $this->pdo->exec('DROP TEMPORARY TABLE IF EXISTS `' . $table . '`');
        }
    }

    /**
     * @param list<array<string, mixed>> $sensors
     */
    private function insertTemporaryKeepRows(string $table, array $sensors): void
    {
        foreach (array_chunk($sensors, 500) as $chunk) {
            $placeholders = [];
            $params = [];

            foreach ($chunk as $sensor) {
                if (!isset($sensor['sensor_class'], $sensor['sensor_name'], $sensor['oid'])) {
                    continue;
                }

                $placeholders[] = '(?, ?, ?)';
                $params[] = (string) $sensor['sensor_class'];
                $params[] = (string) $sensor['sensor_name'];
                $params[] = (string) $sensor['oid'];
            }

            if ($placeholders === []) {
                continue;
            }

            $statement = $this->pdo->prepare(
                'INSERT INTO `' . $table . '` (sensor_class, sensor_name, oid) VALUES ' . implode(', ', $placeholders)
            );
            $statement->execute($params);
        }
    }

    /**
     * @param array<string, string|null> $filters
     * @return array{0:string,1:array<string, string>}
     */
    private function filterSql(array $filters): array
    {
        $where = [];
        $params = [];

        if (($filters['vendor'] ?? '') !== '') {
            $where[] = 'si.vendor = :vendor';
            $params['vendor'] = (string) $filters['vendor'];
        }

        if (($filters['ip_address'] ?? '') !== '') {
            $where[] = 'si.ip_address LIKE :ip_address';
            $params['ip_address'] = '%' . $filters['ip_address'] . '%';
        }

        if (($filters['q'] ?? '') !== '') {
            $query = '%' . $filters['q'] . '%';
            $columns = [
                'q_sensor_name' => 'si.sensor_name',
                'q_sensor_class' => 'si.sensor_class',
                'q_interface_name' => 'si.interface_name',
                'q_oid' => 'si.oid',
                'q_metadata' => 'si.metadata_json',
                'q_hostname' => 'd.hostname',
                'q_ip_address' => 'si.ip_address',
            ];
            $clauses = [];

            foreach ($columns as $key => $column) {
                $clauses[] = $column . ' LIKE :' . $key;
                $params[$key] = $query;
            }

            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }

        return [
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            $params,
        ];
    }

    /**
     * @param array<string, string|null> $filters
     * @return array<string, int>
     */
    private function groupCounts(string $column, array $filters): array
    {
        [$whereSql, $params] = $this->filterSql($filters);
        $statement = $this->pdo->prepare(
            'SELECT ' . $column . ' AS group_name, COUNT(*) AS total
            FROM sensor_inventory si
            INNER JOIN devices d ON d.id = si.device_id'
            . $whereSql
            . ' GROUP BY ' . $column . ' ORDER BY total DESC, group_name ASC'
        );
        $statement->execute($params);
        $counts = [];

        foreach ($statement->fetchAll() as $row) {
            $name = (string) ($row['group_name'] ?? '');

            if ($name !== '') {
                $counts[$name] = (int) $row['total'];
            }
        }

        return $counts;
    }
}
