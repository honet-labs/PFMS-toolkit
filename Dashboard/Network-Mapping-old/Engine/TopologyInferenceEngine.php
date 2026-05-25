<?php
namespace NetworkMapping\Engine;

require_once __DIR__ . '/DatabaseSetup.php';
require_once __DIR__ . '/Contracts/DiscoveryModuleInterface.php';
require_once __DIR__ . '/Modules/LLDPDiscoveryModule.php';
require_once __DIR__ . '/Modules/FDBDiscoveryModule.php';

use PDO;
use NetworkMapping\Engine\Modules\LLDPDiscoveryModule;
use NetworkMapping\Engine\Modules\FDBDiscoveryModule;

class TopologyInferenceEngine {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        DatabaseSetup::initialize($this->pdo);
    }

    public function runFullDiscovery() {
        // Fetch all active agents
        $stmt = $this->pdo->query("SELECT id_agente, alias FROM tagente WHERE disabled = 0");
        $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lldpModule = new LLDPDiscoveryModule();
        $fdbModule = new FDBDiscoveryModule();

        // Clear stale edges (optional: could soft delete or update timestamps)
        $this->pdo->exec("TRUNCATE TABLE topology_edges");

        // Preload normalized agent aliases to map remote_sysname to agent_id
        $aliasMap = [];
        foreach ($agents as $a) {
            $aliasMap[strtolower(trim($a['alias']))] = $a['id_agente'];
        }

        foreach ($agents as $agent) {
            $agentId = (int)$agent['id_agente'];

            // 1. LLDP/CDP Discovery
            $lldpEdges = $lldpModule->discover($this->pdo, $agentId);
            foreach ($lldpEdges as $edge) {
                $remoteNorm = strtolower(trim($edge['remote_device_name']));
                
                // Find matching agent
                $targetId = null;
                foreach ($aliasMap as $alias => $id) {
                    if (strpos($remoteNorm, $alias) !== false || strpos($alias, $remoteNorm) !== false) {
                        $targetId = $id;
                        break;
                    }
                }

                if ($targetId && $targetId !== $agentId) {
                    $this->saveEdge($agentId, $targetId, $edge['local_interface'], '', 'Uplink/Downlink', $edge['protocol']);
                }
            }

            // 2. FDB Discovery (Placeholder logic for Endpoints)
            $macs = $fdbModule->discover($this->pdo, $agentId);
            // In a real scenario, we correlate FDB MACs with ARP tables to find Endpoints.
        }
    }

    private function saveEdge($sourceId, $targetId, $sourcePort, $targetPort, $type, $protocol) {
        $stmt = $this->pdo->prepare("
            INSERT INTO topology_edges (source_agent_id, target_agent_id, source_port, target_port, relationship_type, protocol)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
        ");
        try {
            $stmt->execute([$sourceId, $targetId, $sourcePort, $targetPort, $type, $protocol]);
        } catch (\Exception $e) {
            // Duplicate unique key ignored
        }
    }

    public function getLayer2Topology(int $groupId = 0, int $coreNodeId = 0) {
        // Fetch nodes and edges from topology_edges combined with tagente
        // This replaces the heuristic matching in api-network.php
        
        $sql = "SELECT DISTINCT e.id, e.source_agent_id, e.target_agent_id, e.source_port, e.target_port, e.protocol 
                FROM topology_edges e
                JOIN tagente a1 ON e.source_agent_id = a1.id_agente
                JOIN tagente a2 ON e.target_agent_id = a2.id_agente";
        
        if ($groupId > 0) {
            $sql .= " WHERE a1.id_grupo = :gid OR a2.id_grupo = :gid";
        }

        $stmt = $this->pdo->prepare($sql);
        if ($groupId > 0) {
            $stmt->execute([':gid' => $groupId]);
        } else {
            $stmt->execute();
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
