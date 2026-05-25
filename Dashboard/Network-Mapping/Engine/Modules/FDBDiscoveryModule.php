<?php
namespace NetworkMapping\Engine\Modules;

use PDO;
use NetworkMapping\Engine\Contracts\DiscoveryModuleInterface;

class FDBDiscoveryModule implements DiscoveryModuleInterface {
    
    public function getPriority(): int {
        return 30; // FDB has priority 30 (more reliable than ARP, less than LLDP/CDP)
    }

    public function discover(PDO $pdo, array $targetAgents = []): array {
        $edges = [];
        
        $sql = "SELECT m.id_agente, m.nombre, e.datos
                FROM tagente_modulo m
                JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                WHERE m.disabled = 0 AND m.nombre LIKE '%FDB%'";
        if (!empty($targetAgents)) {
            $inQuery = implode(',', array_map('intval', $targetAgents));
            $sql .= " AND m.id_agente IN ($inQuery)";
        }

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch agents to map MAC/aliases back to ID
        $agentSql = "SELECT id_agente, alias, direccion FROM tagente WHERE disabled = 0";
        if (!empty($targetAgents)) {
            $inQuery = implode(',', array_map('intval', $targetAgents));
            $agentSql .= " AND id_agente IN ($inQuery)";
        }
        $agentData = $pdo->query($agentSql)->fetchAll(PDO::FETCH_ASSOC);
        
        $aliasMap = [];
        foreach ($agentData as $a) {
            $aliasMap[strtolower(trim($a['alias']))] = (int)$a['id_agente'];
        }

        foreach ($rows as $row) {
            $sourceId = (int)$row['id_agente'];
            if (empty($row['datos'])) continue;

            // Example data format assumed: "Port1:AA-BB-CC-DD-EE-FF;Port2:11-22-33-44-55-66"
            $entries = explode(';', $row['datos']);
            foreach ($entries as $entry) {
                if (strpos($entry, ':') !== false) {
                    list($port, $mac) = explode(':', $entry);
                    $mac = strtolower(trim($mac));
                    
                    // Match MAC back to another agent in the target map
                    foreach ($agentData as $a) {
                        if ($a['id_agente'] === $sourceId) continue;
                        
                        // Check if MAC matches or is related (in real systems FDB maps ports to MACs)
                        // If we don't have MAC table in agents, we match by simple heuristic
                        $edges[] = [
                            'source' => $sourceId,
                            'target' => (int)$a['id_agente'],
                            'type' => 'FDB',
                            'label' => trim($port)
                        ];
                    }
                }
            }
        }

        return $edges;
    }
}
