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
    $node = 'primary';

    $layout_file = __DIR__ . '/mapping_layout.json';
    if (file_exists($layout_file)) {
        $config = json_decode(file_get_contents($layout_file), true);
        if (isset($config['dashboards'])) {
            foreach ($config['dashboards'] as $d) {
                if ($d['id'] === $dashId) {
                    $parsed_group = parse_node_id($d['group_id'] ?? '0');
                    $parsed_agent = parse_node_id($d['agent_id'] ?? '0');
                    $node = $parsed_agent['node'] ?: ($parsed_group['node'] ?: 'primary');
                    
                    $groupId = (int)$parsed_group['id'];
                    $agentId = (int)$parsed_agent['id'];
                    $mapType = $d['map_type'] ?? 'auto';
                    $savedNodesRaw = $d['nodes'] ?? [];
                    $savedNodes = [];
                    foreach ($savedNodesRaw as $sid => $pos) {
                        $parsed_sid = parse_node_id($sid);
                        $sid_real = $parsed_sid['id'];
                        $sid_node = $parsed_sid['node'] ?: 'primary';
                        $savedNodes[get_node_uuid($sid_node) . ':' . $sid_real] = [
                            'id' => get_node_uuid($sid_node) . ':' . $sid_real,
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

    global $custom_pdos, $custom_connections;
    $target_nodes = ['primary' => $pdo];
    if (!empty($custom_pdos)) {
        foreach ($custom_pdos as $cid => $cpdo) {
            $target_nodes[$cid] = $cpdo;
        }
    }
    $active_pdo = $target_nodes[$node] ?? $pdo;

    function get_node_label($node) {
        global $custom_connections;
        if ($node === 'primary') return '';
        foreach ($custom_connections as $cc) {
            if ($cc['id'] === $node) { return '[' . $cc['name'] . '] '; }
        }
        return '[' . $node . '] ';
    }
    $node_label = get_node_label($node);

    $isBlankCanvas = ($mapType === 'blank');
    $params = [];
    $sql = "SELECT id_agente AS id, alias, direccion AS ip, id_parent FROM tagente WHERE disabled = 0";
    
    if ($isBlankCanvas) {
        $savedIdsForThisNode = [];
        foreach ($savedNodes as $prefixed_sid => $pos) {
            $p_sid = parse_node_id($prefixed_sid);
            if ($p_sid['node'] === $node) {
                $savedIdsForThisNode[] = (int)$p_sid['id'];
            }
        }
        if (!empty($savedIdsForThisNode)) {
            $inQuery = implode(',', $savedIdsForThisNode);
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

    $stmt = $active_pdo->prepare($sql);
    $stmt->execute($params);
    $nodesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $nodes = [];
    $agentIds = [];
    $agentsIndexed = [];
    
    foreach ($nodesRaw as $n) {
        $id = (int)$n['id'];
        $prefixed_id = get_node_uuid($node) . ':' . $id;
        $agentIds[] = $id;
        $agentsIndexed[$id] = $n;
    }

    // Initialize Discovery Modules
    $engine = new TopologyInferenceEngine($active_pdo);
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
                'id' => 'auto_' . $node . '_' . $de['id'],
                'source' => $node . ':' . $de['source'],
                'target' => $node . ':' . $de['target'],
                'label' => $de['label']
            ]
        ];
    }

    // Merge manual user-defined links
    foreach ($manualLinks as $ml) {
        $parsed_src = parse_node_id($ml['source']);
        $src_node = $parsed_src['node'] ?: $node;
        $src_id = (int)$parsed_src['id'];

        $parsed_tgt = parse_node_id($ml['target']);
        $tgt_node = $parsed_tgt['node'] ?: $node;
        $tgt_id = (int)$parsed_tgt['id'];

        if (($src_node === $node && isset($agentsIndexed[$src_id])) || ($tgt_node === $node && isset($agentsIndexed[$tgt_id]))) {
            $edgeId = $ml['id'] ?? "manual_{$src_node}_{$src_id}_{$tgt_node}_{$tgt_id}";
            
            $isDuplicate = false;
            foreach ($edges as $e) {
                if (($e['data']['source'] == ($src_node . ':' . $src_id) && $e['data']['target'] == ($tgt_node . ':' . $tgt_id)) ||
                    ($e['data']['source'] == ($tgt_node . ':' . $tgt_id) && $e['data']['target'] == ($src_node . ':' . $src_id))) {
                    $isDuplicate = true;
                    break;
                }
            }
            
            if (!$isDuplicate) {
                $edges[] = [
                    'group' => 'edges',
                    'data' => [
                        'id' => $edgeId,
                        'source' => get_node_uuid($src_node) . ':' . $src_id,
                        'target' => get_node_uuid($tgt_node) . ':' . $tgt_id,
                        'label' => ($ml['source_port_name'] ?? '') . ' - ' . ($ml['target_port_name'] ?? '')
                    ]
                ];
            }
        }
    }

    // Apply logical filters and enhancements based on active Mode:
    $finalNodes = [];
    $finalEdges = $edges;

    if ($mode === 'layer2') {
        $connectedNodeIds = [];
        foreach ($edges as $e) {
            $connectedNodeIds[$e['data']['source']] = true;
            $connectedNodeIds[$e['data']['target']] = true;
        }

        foreach ($nodesRaw as $n) {
            $id = (int)$n['id'];
            $prefixed_id = get_node_uuid($node) . ':' . $id;
            
            if (isset($connectedNodeIds[$prefixed_id]) || $id === $agentId) {
                $posX = isset($savedNodes[$prefixed_id]) ? (float)$savedNodes[$prefixed_id]['x'] : null;
                $posY = isset($savedNodes[$prefixed_id]) ? (float)$savedNodes[$prefixed_id]['y'] : null;
                
                $nodeData = [
                    'id' => $prefixed_id,
                    'label' => $node_label . pretty_text($n['alias']),
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
        $finalEdges = [];
        
        foreach ($nodesRaw as $n) {
            $id = (int)$n['id'];
            $prefixed_id = get_node_uuid($node) . ':' . $id;
            $posX = isset($savedNodes[$prefixed_id]) ? (float)$savedNodes[$prefixed_id]['x'] : null;
            $posY = isset($savedNodes[$prefixed_id]) ? (float)$savedNodes[$prefixed_id]['y'] : null;
            
            $parentId = (int)$n['id_parent'];
            if ($parentId > 0 && isset($agentsIndexed[$parentId])) {
                $finalEdges[] = [
                    'group' => 'edges',
                    'data' => [
                        'id' => "l3_parent_" . get_node_uuid($node) . "_{$parentId}_{$id}",
                        'source' => get_node_uuid($node) . ':' . $parentId,
                        'target' => $prefixed_id,
                        'label' => 'Gateway Link'
                    ]
                ];
            }

            $nodeData = [
                'id' => $prefixed_id,
                'label' => $node_label . pretty_text($n['alias']),
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
    else {
        foreach ($nodesRaw as $n) {
            $id = (int)$n['id'];
            $prefixed_id = get_node_uuid($node) . ':' . $id;
            $posX = isset($savedNodes[$prefixed_id]) ? (float)$savedNodes[$prefixed_id]['x'] : null;
            $posY = isset($savedNodes[$prefixed_id]) ? (float)$savedNodes[$prefixed_id]['y'] : null;
            
            $parentId = (int)$n['id_parent'];
            if ($parentId > 0 && isset($agentsIndexed[$parentId])) {
                $hasL2Link = false;
                foreach ($edges as $e) {
                    if (($e['data']['source'] == $prefixed_id && $e['data']['target'] == (get_node_uuid($node) . ':' . $parentId)) ||
                        ($e['data']['source'] == (get_node_uuid($node) . ':' . $parentId) && $e['data']['target'] == $prefixed_id)) {
                        $hasL2Link = true;
                        break;
                    }
                }
                
                if (!$hasL2Link) {
                    $finalEdges[] = [
                        'group' => 'edges',
                        'data' => [
                            'id' => "endpoint_link_" . get_node_uuid($node) . "_{$parentId}_{$id}",
                            'source' => get_node_uuid($node) . ':' . $parentId,
                            'target' => $prefixed_id,
                            'label' => 'Parent Link'
                        ]
                    ];
                }
            }

            $nodeData = [
                'id' => $prefixed_id,
                'label' => $node_label . pretty_text($n['alias']),
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
