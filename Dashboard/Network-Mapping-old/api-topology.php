<?php
use NetworkMapping\Engine\TopologyInferenceEngine;

require_once __DIR__ . '/../../includes/db-connection.php';
require_once __DIR__ . '/Engine/TopologyInferenceEngine.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['id_usuario'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$api = $_GET['api'] ?? '';
$mode = $_GET['mode'] ?? 'layer2';
$dashId = $_GET['dash_id'] ?? '';

if (!$db_status) {
    echo json_encode(['ok' => false, 'error' => 'DB Connection failed']);
    exit;
}

try {
    $engine = new TopologyInferenceEngine($pdo);

    if ($api === 'discover_now') {
        $engine->runFullDiscovery();
        echo json_encode(['ok' => true, 'message' => 'Discovery completed']);
        exit;
    }

    if ($api === 'get_topology') {
        // Load Dashboard Configuration
        $layout_file = __DIR__ . '/mapping_layout.json';
        $groupId = 0;
        $agentId = 0;
        $manualLinks = [];
        $savedNodes = [];

        if (file_exists($layout_file)) {
            $config = json_decode(file_get_contents($layout_file), true);
            if (isset($config['dashboards'])) {
                foreach ($config['dashboards'] as $d) {
                    if ($d['id'] === $dashId) {
                        $groupId = (int)($d['group_id'] ?? 0);
                        $agentId = (int)($d['agent_id'] ?? 0);
                        $manualLinks = $d['manual_links'] ?? [];
                        $savedNodes = $d['nodes'] ?? [];
                        break;
                    }
                }
            }
        }
        
        // Fetch nodes from tagente based on dashboard filters
        $isCustomCanvas = ($groupId === 0 && $agentId === 0);
        $params = [];
        $sql = "SELECT id_agente AS id, alias, direccion AS ip, id_parent FROM tagente WHERE disabled = 0";
        
        if ($groupId > 0) {
            $sql .= " AND id_grupo = :gid";
            $params[':gid'] = $groupId;
        }
        if ($agentId > 0) {
            // Include core node, its children, and its parent
            $sql .= " AND (id_agente = :aid OR id_parent = :aid OR id_agente = (SELECT COALESCE(id_parent, 0) FROM tagente WHERE id_agente = :aid))";
            $params[':aid'] = $agentId;
        }
        
        // Blank Canvas Mode: Only load manually added/saved nodes
        if ($isCustomCanvas) {
            if (!empty($savedNodes)) {
                $savedIds = array_keys($savedNodes);
                $inQuery = implode(',', array_map('intval', $savedIds));
                $sql .= " AND id_agente IN ($inQuery)";
            } else {
                $sql .= " AND 1 = 0"; // Return empty nodes
            }
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $nodesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $nodes = [];
        $agentsIndexed = [];
        foreach ($nodesRaw as $n) {
            $agentsIndexed[(int)$n['id']] = $n;
            
            // Apply saved coordinates if they exist
            $posX = isset($savedNodes[$n['id']]) ? (float)$savedNodes[$n['id']]['x'] : null;
            $posY = isset($savedNodes[$n['id']]) ? (float)$savedNodes[$n['id']]['y'] : null;
            
            $nodeData = [
                'id' => (string)$n['id'],
                'label' => pretty_text($n['alias']),
                'ip' => $n['ip'],
                'type' => 'switch'
            ];

            if ($posX !== null && $posY !== null) {
                $nodes[] = [
                    'data' => $nodeData,
                    'position' => ['x' => $posX, 'y' => $posY]
                ];
            } else {
                $nodes[] = ['data' => $nodeData];
            }
        }

        $edges = [];

        if ($mode === 'layer3') {
            // LAYER 3: Show Logical / Parent-Child Routing Graph
            foreach ($nodesRaw as $n) {
                if (!empty($n['id_parent']) && isset($agentsIndexed[(int)$n['id_parent']])) {
                    $edges[] = [
                        'data' => [
                            'id' => 'l3_' . $n['id_parent'] . '_' . $n['id'],
                            'source' => (string)$n['id_parent'],
                            'target' => (string)$n['id'],
                            'label' => 'Route (L3)'
                        ]
                    ];
                }
            }
        } 
        elseif ($mode === 'layer2') {
            // LAYER 2: Try to fetch from topology engine DB
            $layer2Edges = $engine->getLayer2Topology($groupId);
            
            if (!empty($layer2Edges)) {
                foreach ($layer2Edges as $e) {
                    $edges[] = [
                        'data' => [
                            'id' => 'l2_' . $e['id'],
                            'source' => (string)$e['source_agent_id'],
                            'target' => (string)$e['target_agent_id'],
                            'label' => pretty_text($e['source_port'] . ' ↔ ' . $e['target_port'])
                        ]
                    ];
                }
            } else {
                // FALLBACK: Since the DB cron might not have run, use Heuristic Port Matching
                $allPortsSql = "SELECT m.id_agente_modulo AS port_id, m.id_agente, m.nombre AS port_name 
                                FROM tagente_modulo m WHERE m.disabled = 0 AND m.nombre LIKE '%ifOperStatus%'";
                $allPorts = $pdo->query($allPortsSql)->fetchAll(PDO::FETCH_ASSOC);

                $agentsByAlias = [];
                foreach ($nodesRaw as $n) {
                    $norm = strtolower(trim($n['alias']));
                    if (strlen($norm) >= 3) $agentsByAlias[$norm] = $n['id'];
                }

                $fbEdgesMap = [];
                foreach ($allPorts as $port) {
                    $cleanPort = str_ireplace(['ifOperStatus_', '_ifOperStatus', 'ifOperStatus'], '', $port['port_name']);
                    $normPort = strtolower(trim($cleanPort));
                    
                    foreach ($agentsByAlias as $alias => $targetId) {
                        if ($port['id_agente'] == $targetId) continue;
                        
                        if (stripos($normPort, $alias) !== false) {
                            $key = min($port['id_agente'], $targetId) . '_' . max($port['id_agente'], $targetId);
                            $fbEdgesMap[$key] = [
                                'data' => [
                                    'id' => 'fb_' . $key,
                                    'source' => (string)$port['id_agente'],
                                    'target' => (string)$targetId,
                                    'label' => pretty_text($cleanPort)
                                ]
                            ];
                        }
                    }
                }
                $edges = array_values($fbEdgesMap);
            }
        }
        elseif ($mode === 'endpoint') {
            // ENDPOINTS: Placeholders for now. 
            // Only show nodes that have 'endpoint' type or simulate no links until MAC table is populated.
            // Keeping edges empty simulates disconnected endpoints map
        }

        // --- MERGE MANUAL LINKS ---
        // Restore ability for users to manually create connections in "Customizer Mode"
        foreach ($manualLinks as $ml) {
            $edges[] = [
                'data' => [
                    'id' => 'manual_' . $ml['id'],
                    'source' => (string)$ml['source'],
                    'target' => (string)$ml['target'],
                    'label' => pretty_text(($ml['source_port_name'] ?? '') . ' ↔ ' . ($ml['target_port_name'] ?? ''))
                ]
            ];
        }

        echo json_encode(['ok' => true, 'elements' => ['nodes' => $nodes, 'edges' => $edges]]);
        exit;
    }

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
