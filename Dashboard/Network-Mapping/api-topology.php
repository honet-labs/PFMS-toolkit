<?php
require_once __DIR__ . '/../../includes/db-connection.php';
require_once __DIR__ . '/Engine/Contracts/DiscoveryModuleInterface.php';
require_once __DIR__ . '/Engine/TopologyInferenceEngine.php';
require_once __DIR__ . '/Engine/Modules/LLDPDiscoveryModule.php';
require_once __DIR__ . '/Engine/Modules/CDPDiscoveryModule.php';
require_once __DIR__ . '/Engine/Modules/FDBDiscoveryModule.php';

use NetworkMapping\Engine\TopologyInferenceEngine;
use NetworkMapping\Engine\Modules\LLDPDiscoveryModule;
use NetworkMapping\Engine\Modules\CDPDiscoveryModule;
use NetworkMapping\Engine\Modules\FDBDiscoveryModule;

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['id_usuario'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized access']);
    exit;
}

$api = $_GET['api'] ?? '';

if ($api === 'get_topology') {
    $dashId = $_GET['dash_id'] ?? '';
    $mode = $_GET['mode'] ?? 'layer2'; // layer2, layer3, endpoint
    
    $groupId = 0;
    $agentId = 0;
    $mapType = 'auto'; // 'auto' or 'blank'
    $manualLinks = [];
    $savedNodes = [];

    $layout_file = __DIR__ . '/mapping_layout.json';
    if (file_exists($layout_file)) {
        $config = json_decode(file_get_contents($layout_file), true);
        if (isset($config['dashboards'])) {
            foreach ($config['dashboards'] as $d) {
                if ($d['id'] === $dashId) {
                    $groupId = (int)($d['group_id'] ?? 0);
                    $agentId = (int)($d['agent_id'] ?? 0);
                    $mapType = $d['map_type'] ?? 'auto';
                    $savedNodesRaw = $d['nodes'] ?? [];
                    $savedNodes = [];
                    foreach ($savedNodesRaw as $id => $pos) {
                        $savedNodes[(int)$id] = [
                            'id' => (int)$id,
                            'x' => $pos['x'] ?? 0,
                            'y' => $pos['y'] ?? 0
                        ];
                    }
                    $manualLinks = $d['manual_links'] ?? [];
                    break;
                }
            }
        }
    }

    $isBlankCanvas = ($mapType === 'blank');
    $params = [];
    $sql = "SELECT id_agente AS id, alias, direccion AS ip, id_parent FROM tagente WHERE disabled = 0";
    
    if ($isBlankCanvas) {
        if (!empty($savedNodes)) {
            $savedIds = array_keys($savedNodes);
            $inQuery = implode(',', array_map('intval', $savedIds));
            $sql .= " AND id_agente IN ($inQuery)";
        } else {
            $sql .= " AND 1 = 0";
        }
    } else {
        if ($groupId > 0) {
            $sql .= " AND id_grupo = :gid";
            $params[':gid'] = $groupId;
        }
        
        if ($agentId > 0) {
            $sql .= " AND (id_agente = :aid1 OR id_parent = :aid2 OR id_agente = (SELECT COALESCE(id_parent, 0) FROM tagente WHERE id_agente = :aid3))";
            $params[':aid1'] = $agentId;
            $params[':aid2'] = $agentId;
            $params[':aid3'] = $agentId;
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $nodesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nodes = [];
    $agentIds = [];
    $agentsIndexed = [];
    
    foreach ($nodesRaw as $n) {
        $id = (int)$n['id'];
        $n['alias'] = pretty_text($n['alias']);
        $agentIds[] = $id;
        $agentsIndexed[$id] = $n;
    }

    // Initialize Discovery Modules
    $engine = new TopologyInferenceEngine($pdo);
    $engine->registerModule(new LLDPDiscoveryModule());
    $engine->registerModule(new CDPDiscoveryModule());
    $engine->registerModule(new FDBDiscoveryModule());
    
    $discoveredEdges = count($agentIds) > 0 ? $engine->inferTopology($agentIds) : [];
    $edges = [];
    
    // Generate physical L2 edges
    foreach ($discoveredEdges as $de) {
        $edges[] = [
            'group' => 'edges',
            'data' => [
                'id' => $de['id'],
                'source' => $de['source'],
                'target' => $de['target'],
                'label' => $de['label']
            ]
        ];
    }

    // Merge manual user-defined links
    foreach ($manualLinks as $ml) {
        if (isset($agentsIndexed[(int)$ml['source']]) && isset($agentsIndexed[(int)$ml['target']])) {
            $edgeId = $ml['id'] ?? "manual_{$ml['source']}_{$ml['target']}";
            
            $isDuplicate = false;
            foreach ($edges as $e) {
                if (($e['data']['source'] == $ml['source'] && $e['data']['target'] == $ml['target']) ||
                    ($e['data']['source'] == $ml['target'] && $e['data']['target'] == $ml['source'])) {
                    $isDuplicate = true;
                    break;
                }
            }
            
            if (!$isDuplicate) {
                $edges[] = [
                    'group' => 'edges',
                    'data' => [
                        'id' => $edgeId,
                        'source' => (string)$ml['source'],
                        'target' => (string)$ml['target'],
                        'label' => $ml['source_port_name'] . ' - ' . $ml['target_port_name']
                    ]
                ];
            }
        }
    }

    // Apply logical filters and enhancements based on active Mode:
    $finalNodes = [];
    $finalEdges = $edges;

    if ($mode === 'layer2') {
        // LAYER 2: Focus on manageable infrastructure (switches, routers) and their active physical L2 links.
        // We filter out standalone endpoints (servers/printers that have no physical L2 links on the map).
        $connectedNodeIds = [];
        foreach ($edges as $e) {
            $connectedNodeIds[(int)$e['data']['source']] = true;
            $connectedNodeIds[(int)$e['data']['target']] = true;
        }

        foreach ($nodesRaw as $n) {
            $id = (int)$n['id'];
            
            // Render if part of L2 link OR explicitly designated as a core agent
            if (isset($connectedNodeIds[$id]) || $id === $agentId) {
                $posX = isset($savedNodes[$id]) ? (float)$savedNodes[$id]['x'] : null;
                $posY = isset($savedNodes[$id]) ? (float)$savedNodes[$id]['y'] : null;
                
                $nodeData = [
                    'id' => (string)$id,
                    'label' => $n['alias'],
                    'ip' => $n['ip'],
                    'type' => 'switch'
                ];
                
                $nodeDef = ['group' => 'nodes', 'data' => $nodeData];
                if ($posX !== null && $posY !== null) {
                    $nodeDef['position'] = ['x' => $posX, 'y' => $posY];
                }
                $finalNodes[] = $nodeDef;
            }
        }
    } 
    elseif ($mode === 'layer3') {
        // LAYER 3: Focus on network routing paths and parent-child gateway dependencies.
        // Draw routing lines based on the 'id_parent' column.
        $finalEdges = []; // L3 uses routing hierarchy, not physical switchports
        
        foreach ($nodesRaw as $n) {
            $id = (int)$n['id'];
            $posX = isset($savedNodes[$id]) ? (float)$savedNodes[$id]['x'] : null;
            $posY = isset($savedNodes[$id]) ? (float)$savedNodes[$id]['y'] : null;
            
            // Connect parent router/gateway to child
            $parentId = (int)$n['id_parent'];
            if ($parentId > 0 && isset($agentsIndexed[$parentId])) {
                $finalEdges[] = [
                    'group' => 'edges',
                    'data' => [
                        'id' => "l3_parent_{$parentId}_{$id}",
                        'source' => (string)$parentId,
                        'target' => (string)$id,
                        'label' => 'Gateway Link'
                    ]
                ];
            }

            $nodeData = [
                'id' => (string)$id,
                'label' => $n['alias'],
                'ip' => $n['ip'],
                'type' => ($parentId === 0) ? 'router' : 'switch' // Parentless nodes act as Core Routers
            ];
            
            $nodeDef = ['group' => 'nodes', 'data' => $nodeData];
            if ($posX !== null && $posY !== null) {
                $nodeDef['position'] = ['x' => $posX, 'y' => $posY];
            }
            $finalNodes[] = $nodeDef;
        }
    } 
    else {
        // ENDPOINTS: Show full absolute mapping (all switches, servers, printers, and subnets).
        // Includes both L2 physical links and parent-child gateway links to represent a complete topological blueprint.
        foreach ($nodesRaw as $n) {
            $id = (int)$n['id'];
            $posX = isset($savedNodes[$id]) ? (float)$savedNodes[$id]['x'] : null;
            $posY = isset($savedNodes[$id]) ? (float)$savedNodes[$id]['y'] : null;
            
            // Draw logical parent links for edge nodes that have no physical link
            $parentId = (int)$n['id_parent'];
            if ($parentId > 0 && isset($agentsIndexed[$parentId])) {
                $hasL2Link = false;
                foreach ($edges as $e) {
                    if (($e['data']['source'] == $id && $e['data']['target'] == $parentId) ||
                        ($e['data']['source'] == $parentId && $e['data']['target'] == $id)) {
                        $hasL2Link = true;
                        break;
                    }
                }
                
                if (!$hasL2Link) {
                    $finalEdges[] = [
                        'group' => 'edges',
                        'data' => [
                            'id' => "endpoint_link_{$parentId}_{$id}",
                            'source' => (string)$parentId,
                            'target' => (string)$id,
                            'label' => 'Parent Link'
                        ]
                    ];
                }
            }

            $nodeData = [
                'id' => (string)$id,
                'label' => $n['alias'],
                'ip' => $n['ip'],
                'type' => ($parentId === 0) ? 'router' : 'switch'
            ];
            
            $nodeDef = ['group' => 'nodes', 'data' => $nodeData];
            if ($posX !== null && $posY !== null) {
                $nodeDef['position'] = ['x' => $posX, 'y' => $posY];
            }
            $finalNodes[] = $nodeDef;
        }
    }

    echo json_encode([
        'ok' => true,
        'elements' => array_merge($finalNodes, $finalEdges)
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Endpoint not found']);
?>
