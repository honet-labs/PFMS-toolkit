<?php

declare(strict_types=1);

namespace SnmpBridge\Repository;

use PDO;

final class PandoraRepository
{
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

    public function findModuleByCustomId(int $agentId, string $customId): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT id_agente_modulo FROM tagente_modulo WHERE id_agente = :agent_id AND custom_id = :custom_id LIMIT 1'
        );
        $statement->execute([
            'agent_id' => $agentId,
            'custom_id' => $customId,
        ]);

        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param array<string, mixed> $module
     */
    public function insertModule(array $module): int
    {
        $sql = <<<'SQL'
            INSERT INTO tagente_modulo (
                id_agente,
                id_tipo_modulo,
                descripcion,
                extended_info,
                nombre,
                unit,
                module_interval,
                snmp_community,
                snmp_oid,
                ip_target,
                id_module_group,
                id_modulo,
                disabled,
                max_timeout,
                max_retries,
                custom_id,
                history_data,
                wizard_level,
                quiet,
                extra_data
            ) VALUES (
                :id_agente,
                :id_tipo_modulo,
                :descripcion,
                :extended_info,
                :nombre,
                :unit,
                :module_interval,
                :snmp_community,
                :snmp_oid,
                :ip_target,
                :id_module_group,
                :id_modulo,
                :disabled,
                :max_timeout,
                :max_retries,
                :custom_id,
                :history_data,
                :wizard_level,
                :quiet,
                :extra_data
            )
            SQL;

        $statement = $this->pdo->prepare($sql);
        $statement->execute($module);

        return (int) $this->pdo->lastInsertId();
    }
}
