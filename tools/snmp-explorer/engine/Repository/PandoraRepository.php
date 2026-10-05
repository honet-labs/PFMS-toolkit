<?php

declare(strict_types=1);

namespace SnmpBridge\Repository;

use PDO;

/**
 * Pandora database repository
 * 
 * Handles:
 * - Module creation (tagente_modulo)
 * - Module status initialization (tagente_estado)
 * - Agent validation
 * - Transactions
 */
final class PandoraRepository
{
    private const int MODULE_STATUS_NO_DATA = 4;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Check if agent exists in Pandora
     */
    public function agentExists(int $agentId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tagente WHERE id_agente = ?'
        );
        $statement->execute([$agentId]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Find existing module by custom_id
     * Used for idempotent provisioning (no duplicate modules)
     */
    public function findModuleByCustomId(int $agentId, string $customId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT id_agente_modulo 
             FROM tagente_modulo 
             WHERE id_agente = ? AND custom_id = ? 
             LIMIT 1'
        );
        $statement->execute([
            $agentId,
            $customId,
        ]);

        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param list<string> $customIds
     * @return array<string, int>
     */
    public function findModulesByCustomIds(int $agentId, array $customIds): array
    {
        $customIds = array_values(array_unique(array_filter(
            $customIds,
            static fn (string $customId): bool => $customId !== '',
        )));

        if ($customIds === []) {
            return [];
        }

        $modules = [];

        foreach (array_chunk($customIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                'SELECT custom_id, id_agente_modulo FROM tagente_modulo WHERE id_agente = ? AND custom_id IN (' . $placeholders . ')'
            );
            $statement->execute([$agentId, ...$chunk]);

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $modules[(string) $row['custom_id']] = (int) $row['id_agente_modulo'];
            }
        }

        return $modules;
    }

    /**
     * Insert SNMP module into Pandora
     * 
     * Creates both:
     * 1. tagente_modulo entry (module definition)
     * 2. tagente_estado entry (module status/data)
     * 
     * @param array<string, mixed> $module Module data from PandoraModuleBuilder
     * @return int Module ID (id_agente_modulo)
     */
    public function insertModule(array $module, bool $updateCounters = true): int
    {
        // Insert into tagente_modulo (module definition)
        $moduleId = $this->insertModuleDefinition($module);
        
        // Initialize module status (tagente_estado)
        // Pandora requires this entry for every module
        $this->initializeModuleStatus($moduleId, $module);
        
        if ($updateCounters) {
            $this->incrementAgentCounters((int) $module['id_agente']);
        }

        return $moduleId;
    }

    /** @var list<string>|null */
    private static ?array $tagenteModuloColumns = null;

    /**
     * @return list<string>
     */
    private function getTagenteModuloColumns(): array
    {
        if (self::$tagenteModuloColumns === null) {
            try {
                $statement = $this->pdo->query('SHOW COLUMNS FROM tagente_modulo');
                self::$tagenteModuloColumns = $statement ? ($statement->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
            } catch (\Throwable $e) {
                self::$tagenteModuloColumns = [];
            }
        }

        return self::$tagenteModuloColumns;
    }

    /**
     * Insert module definition into tagente_modulo table
     */
    private function insertModuleDefinition(array $module): int
    {
        $existingCols = $this->getTagenteModuloColumns();
        $colMap = [];
        foreach ($existingCols as $actualCol) {
            $colMap[strtolower((string) $actualCol)] = (string) $actualCol;
        }

        $fields = [
            'id_agente' => $module['id_agente'] ?? 0,
            'id_tipo_modulo' => $module['id_tipo_modulo'] ?? 0,
            'descripcion' => $module['descripcion'] ?? null,
            'extended_info' => $module['extended_info'] ?? null,
            'nombre' => $module['nombre'] ?? null,
            'unit' => $module['unit'] ?? null,
            'module_interval' => $module['module_interval'] ?? 0,
            'snmp_community' => $module['snmp_community'] ?? 'public',
            'snmp_oid' => $module['snmp_oid'] ?? null,
            'ip_target' => $module['ip_target'] ?? null,
            'tcp_port' => $module['tcp_port'] ?? 161,
            'id_module_group' => $module['id_module_group'] ?? 0,
            'id_modulo' => $module['id_modulo'] ?? 0,
            'disabled' => $module['disabled'] ?? 0,
            'max_timeout' => $module['max_timeout'] ?? 0,
            'max_retries' => $module['max_retries'] ?? 0,
            'custom_id' => $module['custom_id'] ?? null,
            'history_data' => $module['history_data'] ?? 1,
            'wizard_level' => $module['wizard_level'] ?? 'nowizard',
            'quiet' => $module['quiet'] ?? 0,
            'extra_data' => $module['extra_data'] ?? null,
        ];

        // Optional SNMP v3 parameters supporting both short and full column names
        $v3Candidates = [
            'snmp_version' => $module['snmp_version'] ?? null,
            'snmp3_sec_level' => $module['snmp3_sec_level'] ?? null,
            'snmp3_security_level' => $module['snmp3_security_level'] ?? $module['snmp3_sec_level'] ?? null,
            'snmp3_auth_user' => $module['snmp3_auth_user'] ?? null,
            'snmp3_auth_method' => $module['snmp3_auth_method'] ?? null,
            'snmp3_auth_pass' => $module['snmp3_auth_pass'] ?? null,
            'snmp3_priv_method' => $module['snmp3_priv_method'] ?? $module['snmp3_privacy_method'] ?? null,
            'snmp3_privacy_method' => $module['snmp3_privacy_method'] ?? $module['snmp3_priv_method'] ?? null,
            'snmp3_priv_pass' => $module['snmp3_priv_pass'] ?? $module['snmp3_privacy_pass'] ?? null,
            'snmp3_privacy_pass' => $module['snmp3_privacy_pass'] ?? $module['snmp3_priv_pass'] ?? null,
            'snmp3_context' => $module['snmp3_context'] ?? $module['snmp3_context_name'] ?? null,
            'snmp3_context_name' => $module['snmp3_context_name'] ?? $module['snmp3_context'] ?? null,
            'plugin_user' => $module['plugin_user'] ?? null,
            'plugin_pass' => $module['plugin_pass'] ?? null,
            'plugin_parameter' => $module['plugin_parameter'] ?? null,
        ];

        foreach ($v3Candidates as $col => $val) {
            if ($val !== null) {
                $lowerCol = strtolower($col);
                if (empty($colMap) || isset($colMap[$lowerCol])) {
                    $realCol = $colMap[$lowerCol] ?? $col;
                    $fields[$realCol] = $val;
                }
            }
        }

        if (!empty($colMap)) {
            $filteredFields = [];
            foreach ($fields as $col => $val) {
                $lowerCol = strtolower((string) $col);
                if (isset($colMap[$lowerCol])) {
                    $filteredFields[$colMap[$lowerCol]] = $val;
                }
            }
            $fields = $filteredFields;
        }

        $colNames = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));

        $sql = "INSERT INTO tagente_modulo ($colNames) VALUES ($placeholders)";
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_values($fields));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update existing module definition with latest sensor attributes and SNMP version/credentials
     */
    public function updateModuleDefinition(int $moduleId, array $module): void
    {
        $existingCols = $this->getTagenteModuloColumns();
        $colMap = [];
        foreach ($existingCols as $actualCol) {
            $colMap[strtolower((string) $actualCol)] = (string) $actualCol;
        }

        $fields = [
            'snmp_community' => $module['snmp_community'] ?? '',
            'snmp_oid' => $module['snmp_oid'] ?? null,
            'ip_target' => $module['ip_target'] ?? null,
            'tcp_port' => $module['tcp_port'] ?? 161,
            'module_interval' => $module['module_interval'] ?? 0,
            'unit' => $module['unit'] ?? null,
            'snmp_version' => $module['snmp_version'] ?? null,
            'snmp3_sec_level' => $module['snmp3_sec_level'] ?? null,
            'snmp3_security_level' => $module['snmp3_security_level'] ?? $module['snmp3_sec_level'] ?? null,
            'snmp3_auth_user' => $module['snmp3_auth_user'] ?? null,
            'snmp3_auth_method' => $module['snmp3_auth_method'] ?? null,
            'snmp3_auth_pass' => $module['snmp3_auth_pass'] ?? null,
            'snmp3_priv_method' => $module['snmp3_priv_method'] ?? $module['snmp3_privacy_method'] ?? null,
            'snmp3_privacy_method' => $module['snmp3_privacy_method'] ?? $module['snmp3_priv_method'] ?? null,
            'snmp3_priv_pass' => $module['snmp3_priv_pass'] ?? $module['snmp3_privacy_pass'] ?? null,
            'snmp3_privacy_pass' => $module['snmp3_privacy_pass'] ?? $module['snmp3_priv_pass'] ?? null,
            'snmp3_context' => $module['snmp3_context'] ?? $module['snmp3_context_name'] ?? null,
            'snmp3_context_name' => $module['snmp3_context_name'] ?? $module['snmp3_context'] ?? null,
        ];

        $updates = [];
        $params = [];
        foreach ($fields as $col => $val) {
            $lowerCol = strtolower($col);
            if (empty($colMap) || isset($colMap[$lowerCol])) {
                $realCol = $colMap[$lowerCol] ?? $col;
                $updates[] = "$realCol = ?";
                $params[] = $val;
            }
        }

        if (!empty($updates)) {
            $params[] = $moduleId;
            $sql = "UPDATE tagente_modulo SET " . implode(', ', $updates) . " WHERE id_agente_modulo = ?";
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
        }
    }

    /**
     * Initialize module status in tagente_estado
     * 
     * Pandora FMS requires every module to have a status entry.
     * SNMP modules start in NO_DATA status until first poll.
     */
    private function initializeModuleStatus(int $moduleId, array $module): void
    {
        $agentId = (int) $module['id_agente'];
        $interval = (int) ($module['module_interval'] ?? 300);

        // Modules start in NO_DATA status (code 4)
        // Timestamp set to old value so agent knows it needs updating
        $oldTimestamp = time() - $interval;

        $sql = <<<'SQL'
            INSERT INTO tagente_estado (
                id_agente_modulo,
                id_agente,
                datos,
                timestamp,
                estado,
                known_status,
                utimestamp,
                status_changes,
                last_status,
                last_known_status,
                current_interval
            ) VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?
            )
            SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->execute([
            $moduleId,
            $agentId,
            '',
            '01-01-1970 00:00:00',
            self::MODULE_STATUS_NO_DATA,
            self::MODULE_STATUS_NO_DATA,
            $oldTimestamp,
            0,
            self::MODULE_STATUS_NO_DATA,
            self::MODULE_STATUS_NO_DATA,
            $interval,
        ]);
    }

    /**
     * Update agent counters
     * 
     * Pandora tracks module counts:
     * - total_count: total modules
     * - normal_count: modules in normal state
     * - notinit_count: modules not initialized
     * 
     * New modules increment total_count and notinit_count
     */
    public function incrementAgentCounters(int $agentId, int $count = 1): void
    {
        if ($count < 1) {
            return;
        }

        $sql = <<<'SQL'
            UPDATE tagente 
            SET 
                total_count = total_count + ?,
                notinit_count = notinit_count + ?,
                update_module_count = 1
            WHERE id_agente = ?
            SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->execute([$count, $count, $agentId]);
    }

    /**
     * Get module details for verification
     */
    public function getModule(int $moduleId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM tagente_modulo WHERE id_agente_modulo = ? LIMIT 1'
        );
        $statement->execute([$moduleId]);

        $result = $statement->fetch(PDO::FETCH_ASSOC);

        return $result === false ? null : $result;
    }

    /**
     * Get agent details for verification
     */
    public function getAgent(int $agentId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id_agente, nombre, id_grupo, disabled FROM tagente WHERE id_agente = ? LIMIT 1'
        );
        $statement->execute([$agentId]);

        $result = $statement->fetch(PDO::FETCH_ASSOC);

        return $result === false ? null : $result;
    }

    /**
     * Count provisioned modules in Pandora
     */
    public function countProvisionedModules(string $customIdPattern = 'snmpbridge:%'): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tagente_modulo WHERE custom_id LIKE ?'
        );
        $statement->execute([$customIdPattern]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Find all provisioned modules
     */
    public function findProvisionedModules(int $limit = 100, int $offset = 0): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id_agente_modulo, nombre, snmp_oid, ip_target, custom_id 
             FROM tagente_modulo 
             WHERE custom_id LIKE :pattern
             ORDER BY id_agente_modulo DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue('pattern', 'snmpbridge:%');
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
