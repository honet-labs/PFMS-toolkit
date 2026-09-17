<?php
require_once __DIR__ . '/../../includes/db-connection.php';
require_once __DIR__ . '/Engine/Contracts/DiscoveryModuleInterface.php';
require_once __DIR__ . '/Engine/TopologyInferenceEngine.php';
require_once __DIR__ . '/Engine/Modules/LLDPDiscoveryModule.php';
require_once __DIR__ . '/Engine/Modules/CDPDiscoveryModule.php';
require_once __DIR__ . '/Engine/Modules/FDBDiscoveryModule.php';
require_once __DIR__ . '/Engine/DeviceClassifier.php';

use NetworkMapping\Engine\TopologyInferenceEngine;
use NetworkMapping\Engine\Modules\LLDPDiscoveryModule;
use NetworkMapping\Engine\Modules\CDPDiscoveryModule;
use NetworkMapping\Engine\Modules\FDBDiscoveryModule;
use NetworkMapping\Engine\DeviceClassifier;

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
    $mode = $_GET['mode'] ?? 'all'; // all, compute, network, layer2, layer3
    
    $groupId = 0;
    $agentId = 0;
    $mapType = 'auto';
    $manualLinks = [];
    $savedNodes = [];
    $customRoles = [];
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
                    $customRoles = $d['custom_roles'] ?? [];
                    break;
                }
            }
        }
    }

    // 1. Check for Demo Mode (SDDC / vSphere Reference Architecture from Screenshot)
    if ($dashId === 'dash_demo_sddc' || strpos($dashId, 'demo') !== false || isset($_GET['demo'])) {
        $demo = DeviceClassifier::getReferenceDemoTopology();
        $elements = [];

        foreach ($demo['nodes'] as $dn) {
            $pos = $savedNodes[$dn['id']] ?? ['x' => $dn['x'], 'y' => $dn['y']];
            $roleKey = $customRoles[$dn['id']] ?? $dn['role'];
            $roleTitle = DeviceClassifier::getRoleTitle($roleKey);

            $elements[] = [
                'group' => 'nodes',
                'data' => [
                    'id' => $dn['id'],
                    'label' => $dn['label'],
                    'display_label' => $dn['label'] . "\n" . $roleTitle,
                    'ip' => $dn['ip'],
                    'status' => $dn['status'],
                    'alert_count' => $dn['alert_count'],
                    'role' => $roleKey,
                    'role_title' => $roleTitle,
                    'icon' => $roleKey
                ],
                'position' => [
                    'x' => (float)$pos['x'],
                    'y' => (float)$pos['y']
                ]
            ];
        }

        foreach ($demo['edges'] as $idx => $de) {
            $elements[] = [
                'group' => 'edges',
                'data' => [
                    'id' => 'demo_edge_' . $idx,
                    'source' => $de['source'],
                    'target' => $de['target'],
                    'label' => $de['label']
                ]
            ];
        }

        echo json_encode([
            'ok' => true,
            'is_demo' => true,
            'elements' => $elements
        ]);
        exit;
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
    $sql = "SELECT a.id_agente AS id, a.alias, a.direccion AS ip, a.id_parent, a.id_grupo, 
                   a.so, a.comentarios, a.nombre, g.nombre AS group_name
            FROM tagente a 
            LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
            WHERE a.disabled = 0";
    
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
            $sql .= " AND a.id_agente IN ($inQuery)";
        } else {
            $sql .= " AND 1 = 0";
        }
    } else {
        if ($groupId > 0) {
            $sql .= " AND a.id_grupo = :gid";
            $params[':gid'] = $groupId;
        }
        
        if ($agentId > 0) {
            $sql .= " AND (a.id_agente = :aid1 OR a.id_parent = :aid2 OR a.id_agente = (SELECT COALESCE(id_parent, 0) FROM tagente WHERE id_agente = :aid3))";
            $params[':aid1'] = $agentId;
            $params[':aid2'] = $agentId;
            $params[':aid3'] = $agentId;
        }
    }

    $sql .= " ORDER BY a.alias ASC";
    $stmt = $active_pdo->prepare($sql);
    $stmt->execute($params);
    $nodesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fallback: If no agents exist in database at all, load the reference demo topology
    if (empty($nodesRaw) && $groupId === 0 && $agentId === 0 && !$isBlankCanvas) {
        $demo = DeviceClassifier::getReferenceDemoTopology();
        $elements = [];
        foreach ($demo['nodes'] as $dn) {
            $elements[] = [
                'group' => 'nodes',
                'data' => [
                    'id' => $dn['id'],
                    'label' => $dn['label'],
                    'display_label' => $dn['label'] . "\n" . $dn['role_title'],
                    'ip' => $dn['ip'],
                    'status' => $dn['status'],
                    'alert_count' => $dn['alert_count'],
                    'role' => $dn['role'],
                    'role_title' => $dn['role_title'],
                    'icon' => $dn['role']
                ],
                'position' => ['x' => (float)$dn['x'], 'y' => (float)$dn['y']]
            ];
        }
        foreach ($demo['edges'] as $idx => $de) {
            $elements[] = [
                'group' => 'edges',
                'data' => [
                    'id' => 'demo_edge_' . $idx,
                    'source' => $de['source'],
                    'target' => $de['target'],
                    'label' => $de['label']
                ]
            ];
        }
        echo json_encode([
            'ok' => true,
            'is_demo' => true,
            'notice' => 'No agents found in database. Showing reference SDDC topology demo.',
            'elements' => $elements
        ]);
        exit;
    }

    // 2. Fetch worst global health and count of active alerts for each agent
    $agentHealthSql = "SELECT m.id_agente, 
                              MAX(e.estado) as worst_status,
                              SUM(CASE WHEN e.estado IN (1, 2) THEN 1 ELSE 0 END) as alert_count
                       FROM tagente_modulo m 
                       JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo 
                       WHERE m.disabled = 0 
                       GROUP BY m.id_agente";
    $healthStmt = $active_pdo->query($agentHealthSql);
    $healths = [];
    if ($healthStmt) {
        while ($h = $healthStmt->fetch()) {
            $healths[$h['id_agente']] = [
                'worst_status' => (int)$h['worst_status'],
                'alert_count' => (int)$h['alert_count']
            ];
        }
    }

    $agentIds = [];
    $agentsIndexed = [];
    foreach ($nodesRaw as $n) {
        $id = (int)$n['id'];
        $agentIds[] = $id;
        $agentsIndexed[$id] = $n;
    }

    // Initialize Network Discovery Engine
    $engine = new TopologyInferenceEngine($active_pdo);
    $engine->registerModule(new LLDPDiscoveryModule());
    $engine->registerModule(new CDPDiscoveryModule());
    $engine->registerModule(new FDBDiscoveryModule());
    
    $discoveredEdges = count($agentIds) > 0 ? $engine->inferTopology($agentIds) : [];
    $edges = [];
    
    // Generate physical L2 edges from LLDP/CDP/FDB
    foreach ($discoveredEdges as $de) {
        $edges[] = [
            'group' => 'edges',
            'data' => [
                'id' => 'auto_' . $node . '_' . $de['id'],
                'source' => get_node_uuid($node) . ':' . $de['source'],
                'target' => get_node_uuid($node) . ':' . $de['target'],
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
                if (($e['data']['source'] == (get_node_uuid($src_node) . ':' . $src_id) && $e['data']['target'] == (get_node_uuid($tgt_node) . ':' . $tgt_id)) ||
                    ($e['data']['source'] == (get_node_uuid($tgt_node) . ':' . $tgt_id) && $e['data']['target'] == (get_node_uuid($src_node) . ':' . $src_id))) {
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

    // Auto-generate parent-child edges from Pandora FMS id_parent hierarchy
    foreach ($nodesRaw as $n) {
        $id = (int)$n['id'];
        $parentId = (int)$n['id_parent'];
        if ($parentId > 0 && isset($agentsIndexed[$parentId])) {
            $pSource = get_node_uuid($node) . ':' . $parentId;
            $pTarget = get_node_uuid($node) . ':' . $id;

            $hasLink = false;
            foreach ($edges as $e) {
                if (($e['data']['source'] === $pSource && $e['data']['target'] === $pTarget) ||
                    ($e['data']['source'] === $pTarget && $e['data']['target'] === $pSource)) {
                    $hasLink = true;
                    break;
                }
            }
            if (!$hasLink) {
                $edges[] = [
                    'group' => 'edges',
                    'data' => [
                        'id' => "parent_{$pSource}_{$pTarget}",
                        'source' => $pSource,
                        'target' => $pTarget,
                        'label' => 'Parent Link'
                    ]
                ];
            }
        }
    }

    // Build Final Cytoscape Nodes
    $finalNodes = [];
    foreach ($nodesRaw as $n) {
        $id = (int)$n['id'];
        $prefixed_id = get_node_uuid($node) . ':' . $id;
        
        $devClass = DeviceClassifier::classify($n, $customRoles);
        
        // Mode filtering
        if ($mode === 'compute' && !in_array($devClass['role'], ['datacenter', 'cluster', 'hypervisor', 'vm', 'storage', 'vcenter', 'server'])) {
            continue;
        }
        if ($mode === 'network' && !in_array($devClass['role'], ['switch', 'router', 'firewall'])) {
            continue;
        }

        $worstModule = isset($healths[$id]) ? $healths[$id]['worst_status'] : 0;
        $alertCount = isset($healths[$id]) ? $healths[$id]['alert_count'] : 0;

        $healthLabel = 'normal';
        if ($worstModule === 1) $healthLabel = 'critical';
        elseif ($worstModule === 2) $healthLabel = 'warning';
        elseif ($worstModule === 4) $healthLabel = 'not_init';
        elseif ($worstModule === 3) $healthLabel = 'unknown';

        $posX = isset($savedNodes[$prefixed_id]) ? (float)$savedNodes[$prefixed_id]['x'] : null;
        $posY = isset($savedNodes[$prefixed_id]) ? (float)$savedNodes[$prefixed_id]['y'] : null;

        $nodeDef = [
            'group' => 'nodes',
            'data' => [
                'id' => $prefixed_id,
                'label' => $node_label . pretty_text($n['alias']),
                'display_label' => $node_label . pretty_text($n['alias']) . "\n" . $devClass['title'],
                'ip' => $n['ip'] ?: '-',
                'os' => $n['so'] ?: '',
                'group_name' => $n['group_name'] ?? '',
                'status' => $healthLabel,
                'alert_count' => $alertCount,
                'role' => $devClass['role'],
                'role_title' => $devClass['title'],
                'icon' => $devClass['icon'],
                'parent' => $n['id_parent'] ? (get_node_uuid($node) . ':' . (int)$n['id_parent']) : null
            ]
        ];

        if ($posX !== null && $posY !== null) {
            $nodeDef['position'] = ['x' => $posX, 'y' => $posY];
        }

        $finalNodes[] = $nodeDef;
    }

    // Filter edges to only include nodes present in finalNodes
    $existingNodeMap = [];
    foreach ($finalNodes as $fn) {
        $existingNodeMap[$fn['data']['id']] = true;
    }

    $finalEdges = [];
    foreach ($edges as $e) {
        if (isset($existingNodeMap[$e['data']['source']]) && isset($existingNodeMap[$e['data']['target']])) {
            $finalEdges[] = $e;
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
