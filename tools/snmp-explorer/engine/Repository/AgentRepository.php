<?php

declare(strict_types=1);

namespace SnmpBridge\Repository;

use PDO;

final readonly class AgentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array{id_agente:int,nombre:string,direccion:string|null}>
     */
    public function all(): array
    {
        $statement = $this->pdo->query(
            'SELECT id_agente, nombre, direccion FROM tagente WHERE disabled = 0 ORDER BY nombre ASC'
        );

        return $statement->fetchAll();
    }

    public function exists(int $agentId): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM tagente WHERE id_agente = :id');
        $statement->execute(['id' => $agentId]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @return list<array{id_grupo:int,nombre:string}>
     */
    public function getGroups(): array
    {
        $statement = $this->pdo->query('SELECT id_grupo, nombre FROM tgrupo ORDER BY nombre ASC');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array{id_os:int,name:string}>
     */
    public function getOperatingSystems(): array
    {
        $statement = $this->pdo->query('SELECT id_os, name FROM tconfig_os ORDER BY name ASC');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array{id_server:int,name:string}>
     */
    public function getServers(): array
    {
        $statement = $this->pdo->query('SELECT id_server, name FROM tserver ORDER BY name ASC');
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array{id_field:int,name:string}>
     */
    public function getCustomFields(): array
    {
        try {
            $statement = $this->pdo->query('SELECT id_field, name FROM tagent_custom_fields ORDER BY name ASC');
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createAgent(array $data): void
    {
        $serverName = '';
        if (!empty($data['server'])) {
            $stmt = $this->pdo->prepare('SELECT name FROM tserver WHERE id_server = :id');
            $stmt->execute(['id' => $data['server']]);
            $serverName = (string) $stmt->fetchColumn();
        }

        $alias = (string) ($data['agent_alias'] ?? '');
        $nombre = empty($data['use_alias_as_name']) ? md5(uniqid('', true)) : $alias;
        if ($nombre === '') {
            $nombre = $alias !== '' ? $alias : 'Agent_' . time();
        }

        $ipAddress = (string) ($data['ip_address'] ?? '');
        $interval = empty($data['interval']) ? 300 : (int) $data['interval'];

        // Collect custom fields into JSON extra_data
        $extraDataMap = [];
        if (isset($data['custom_key']) && is_array($data['custom_key']) && isset($data['custom_val']) && is_array($data['custom_val'])) {
            foreach ($data['custom_key'] as $idx => $key) {
                $k = trim((string) $key);
                $v = trim((string) ($data['custom_val'][$idx] ?? ''));
                if ($k !== '') {
                    $extraDataMap[$k] = $v;
                }
            }
        }

        if (isset($data['custom_field']) && is_array($data['custom_field'])) {
            foreach ($data['custom_field'] as $fieldId => $val) {
                $v = trim((string) $val);
                if ($v !== '') {
                    $extraDataMap['field_' . $fieldId] = $v;
                }
            }
        }

        $extraDataJson = json_encode($extraDataMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';

        $sql = 'INSERT INTO tagente (
            nombre, alias, direccion, comentarios, id_grupo, intervalo, id_os, 
            os_version, server_name, id_parent, url_address, extra_data
        ) VALUES (
            :nombre, :alias, :direccion, :comentarios, :id_grupo, :intervalo, :id_os, 
            :os_version, :server_name, :id_parent, :url_address, :extra_data
        )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'nombre' => $nombre,
            'alias' => $alias,
            'direccion' => $ipAddress,
            'comentarios' => (string) ($data['description'] ?? ''),
            'id_grupo' => empty($data['primary_group']) ? 0 : (int) $data['primary_group'],
            'intervalo' => $interval,
            'id_os' => empty($data['os']) ? 0 : (int) $data['os'],
            'os_version' => (string) ($data['os_version'] ?? ''),
            'server_name' => $serverName,
            'id_parent' => empty($data['parent_agent']) ? 0 : (int) $data['parent_agent'],
            'url_address' => (string) ($data['url_address'] ?? ''),
            'extra_data' => $extraDataJson,
        ]);

        $agentId = (int) $this->pdo->lastInsertId();

        // Agent initial state
        $stateSql = 'INSERT INTO tagente_estado (id_agente, timestamp, id_agente_modulo, estado) VALUES (:id_agente, NOW(), 0, 0)';
        $stmt = $this->pdo->prepare($stateSql);
        $stmt->execute(['id_agente' => $agentId]);

        // Add default monitoring modules: "Host Alive" and "Host Latency" (matching Pandora Console logic)
        if (!empty($data['add_default_monitoring']) || isset($data['add_default_monitoring'])) {
            try {
                $defaultModules = [
                    [
                        'nombre' => 'Host Alive',
                        'id_tipo_modulo' => 6, // generic_proc / ICMP Ping
                        'descripcion' => 'Check if host is alive using ICMP ping check.',
                        'unit' => '',
                    ],
                    [
                        'nombre' => 'Host Latency',
                        'id_tipo_modulo' => 7, // generic_data_inc / ICMP Latency
                        'descripcion' => 'Get host network latency in miliseconds, using ICMP.',
                        'unit' => 'ms',
                    ],
                ];

                $modSql = 'INSERT INTO tagente_modulo (
                    id_agente, nombre, descripcion, id_tipo_modulo, id_module_group,
                    module_interval, ip_target, id_modulo, history_data, unit, flag
                ) VALUES (
                    :id_agente, :nombre, :descripcion, :id_tipo_modulo, 2,
                    :module_interval, :ip_target, 2, 1, :unit, 1
                )';
                $modStmt = $this->pdo->prepare($modSql);

                $modStateSql = 'INSERT INTO tagente_estado (id_agente, id_agente_modulo, timestamp, estado) VALUES (:id_agente, :id_agente_modulo, NOW(), 0)';
                $modStateStmt = $this->pdo->prepare($modStateSql);

                foreach ($defaultModules as $mod) {
                    $modStmt->execute([
                        'id_agente' => $agentId,
                        'nombre' => $mod['nombre'],
                        'descripcion' => $mod['descripcion'],
                        'id_tipo_modulo' => $mod['id_tipo_modulo'],
                        'module_interval' => $interval,
                        'ip_target' => $ipAddress,
                        'unit' => $mod['unit'],
                    ]);

                    $modId = (int) $this->pdo->lastInsertId();
                    $modStateStmt->execute([
                        'id_agente' => $agentId,
                        'id_agente_modulo' => $modId,
                    ]);
                }
            } catch (\Throwable) {
                // Ignore if tagente_modulo has constraint differences in custom setups
            }
        }

        // Save registered custom fields (custom_field[id_field] = value)
        if (isset($data['custom_field']) && is_array($data['custom_field'])) {
            try {
                $customDataSql = 'INSERT INTO tagent_custom_data (id_agent, id_field, description) VALUES (:id_agent, :id_field, :description) ON DUPLICATE KEY UPDATE description = :description';
                $customStmt = $this->pdo->prepare($customDataSql);
                foreach ($data['custom_field'] as $fieldId => $val) {
                    $v = trim((string) $val);
                    if ($v !== '') {
                        $customStmt->execute([
                            'id_agent' => $agentId,
                            'id_field' => (int) $fieldId,
                            'description' => $v,
                        ]);
                    }
                }
            } catch (\Throwable) {
                // Ignore if tagent_custom_data insert fails
            }
        }

        // Save dynamic user-defined custom fields (custom_key[] & custom_val[])
        if (isset($data['custom_key']) && is_array($data['custom_key']) && isset($data['custom_val']) && is_array($data['custom_val'])) {
            try {
                $findFieldStmt = $this->pdo->prepare('SELECT id_field FROM tagent_custom_fields WHERE name = :name');
                $createFieldStmt = $this->pdo->prepare('INSERT INTO tagent_custom_fields (name, display_on_front) VALUES (:name, 1)');
                $saveDataStmt = $this->pdo->prepare('INSERT INTO tagent_custom_data (id_agent, id_field, description) VALUES (:id_agent, :id_field, :description) ON DUPLICATE KEY UPDATE description = :description');

                foreach ($data['custom_key'] as $idx => $key) {
                    $fieldName = trim((string) $key);
                    $fieldVal = trim((string) ($data['custom_val'][$idx] ?? ''));
                    if ($fieldName === '' || $fieldVal === '') {
                        continue;
                    }

                    // Find or create field ID in tagent_custom_fields
                    $findFieldStmt->execute(['name' => $fieldName]);
                    $fieldId = $findFieldStmt->fetchColumn();

                    if (!$fieldId) {
                        $createFieldStmt->execute(['name' => $fieldName]);
                        $fieldId = (int) $this->pdo->lastInsertId();
                    } else {
                        $fieldId = (int) $fieldId;
                    }

                    // Save custom field value for this agent
                    $saveDataStmt->execute([
                        'id_agent' => $agentId,
                        'id_field' => $fieldId,
                        'description' => $fieldVal,
                    ]);
                }
            } catch (\Throwable) {
                // Ignore if custom field handling fails
            }
        }
    }
}
