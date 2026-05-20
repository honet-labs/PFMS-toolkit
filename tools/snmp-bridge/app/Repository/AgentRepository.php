<?php

declare(strict_types=1);

namespace SnmpBridge\Repository;

use PDO;

final class AgentRepository
{
    public function __construct(private readonly PDO $pdo)
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
}
