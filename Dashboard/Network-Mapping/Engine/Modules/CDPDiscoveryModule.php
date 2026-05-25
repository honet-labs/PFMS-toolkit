<?php
namespace NetworkMapping\Engine\Modules;

use NetworkMapping\Engine\Contracts\DiscoveryModuleInterface;
use PDO;

class CDPDiscoveryModule implements DiscoveryModuleInterface {
    
    public function getPriority(): int {
        return 15; // Slightly lower priority than LLDP
    }

    private function cleanStr(string $s): string {
        return preg_replace('/[^a-zA-Z0-9]/', '', strtolower($s));
    }

    public function discover(PDO $pdo, array $targetAgents = []): array {
        $edges = [];
        // Extract CDP neighbors from SNMP modules (e.g., cdpCacheDeviceId)
        $sql = "
            SELECT m.id_agente, m.nombre AS local_port, e.datos AS remote_sysname
            FROM tagente_modulo m
            JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
            WHERE m.disabled = 0 
              AND m.nombre LIKE '%cdpCacheDeviceId%'
        ";
        
        $stmt = $pdo->query($sql);
        $cdpData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch agents to map alias/IP back to ID
        $agentSql = "SELECT id_agente, alias, direccion AS ip FROM tagente WHERE disabled = 0";
        if (!empty($targetAgents)) {
            $inQuery = implode(',', array_map('intval', $targetAgents));
            $agentSql .= " AND id_agente IN ($inQuery)";
        }
        $agentData = $pdo->query($agentSql)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($cdpData as $row) {
            $remote = strtolower(trim($row['remote_sysname']));
            if (empty($remote)) continue;

            $sourceId = (int)$row['id_agente'];
            $cleanRemote = $this->cleanStr($remote);

            // Match against each active target agent
            foreach ($agentData as $a) {
                $targetId = (int)$a['id_agente'];
                if ($sourceId === $targetId) continue;

                // Crucial: Clean alias using pretty_text to decode HTML entities (like &#x20;)
                $alias = \pretty_text($a['alias']);
                $cleanAlias = $this->cleanStr($alias);
                $ip = trim($a['ip']);

                // High-performance matching heuristics:
                // 1. Cleaned alphanumeric exact or partial string matching
                // 2. IP address matching (if CDP registers the management IP)
                $isMatched = false;
                if (!empty($cleanAlias) && (str_contains($cleanRemote, $cleanAlias) || str_contains($cleanAlias, $cleanRemote))) {
                    $isMatched = true;
                } elseif (!empty($ip) && (str_contains($remote, $ip) || str_contains($ip, $remote))) {
                    $isMatched = true;
                }

                if ($isMatched) {
                    $cleanPort = preg_replace('/cdpCacheDeviceId_/i', '', $row['local_port']);
                    $edges[] = [
                        'source' => $sourceId,
                        'target' => $targetId,
                        'type' => 'CDP',
                        'label' => $cleanPort
                    ];
                }
            }
        }
        return $edges;
    }
}
