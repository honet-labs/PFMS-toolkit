<?php
namespace NetworkMapping\Engine\Modules;

use PDO;
use NetworkMapping\Engine\Contracts\DiscoveryModuleInterface;

class LLDPDiscoveryModule implements DiscoveryModuleInterface {
    public function discover(PDO $pdo, int $agentId): array {
        $edges = [];
        
        $sql = "SELECT m.nombre AS local_port, e.datos AS remote_sysname
                FROM tagente_modulo m
                JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                WHERE m.id_agente = :agentId 
                  AND m.disabled = 0 
                  AND (m.nombre LIKE '%lldpRemSysName%' OR m.nombre LIKE '%cdpCacheDeviceId%')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':agentId' => $agentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $remoteName = trim($row['remote_sysname']);
            if (!empty($remoteName)) {
                $edges[] = [
                    'source_agent' => $agentId,
                    'local_interface' => $row['local_port'],
                    'remote_device_name' => $remoteName,
                    'protocol' => 'LLDP/CDP'
                ];
            }
        }

        return $edges;
    }
}
