<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/**
 * api-network.php
 * High-performance backend API for Custom Network Mapping Dashboard
 * Supports dynamic multi-dashboard configurations, coordinates, and manual links
 */

require_once __DIR__ . '/../../includes/db-connection.php';

header('Content-Type: application/json');

// Security & Authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['id_usuario'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized session. Please log in to Pandora FMS.']);
    exit;
}

$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';
$layout_file = __DIR__ . '/mapping_layout.json';

$api = $_GET['api'] ?? '';

// Multi-dashboard layout helper that upgrades legacy single-layout structures on the fly
function load_multi_dashboard_config($file) {
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            if (isset($data['dashboards'])) {
                return $data;
            }
            // Upgrade legacy single layout schema safely without losing custom positions
            $legacy_nodes = $data['nodes'] ?? [];
            $legacy_links = $data['manual_links'] ?? [];
            return [
                'dashboards' => [
                    [
                        'id' => 'dash_default',
                        'name' => 'Global Topology Map',
                        'group_id' => '0',
                        'group_name' => 'All Groups',
                        'agent_id' => '0',
                        'agent_name' => 'All Nodes',
                        'nodes' => $legacy_nodes,
                        'manual_links' => $legacy_links
                    ]
                ]
            ];
        }
    }
    // Return default empty dashboards template
    return [
        'dashboards' => [
            [
                'id' => 'dash_default',
                'name' => 'Global Topology Map',
                'group_id' => '0',
                'group_name' => 'All Groups',
                'agent_id' => '0',
                'agent_name' => 'All Nodes',
                'nodes' => [],
                'manual_links' => []
            ]
        ]
    ];
}

function get_port_traffic_label($pdo, $agent_id, $port_name) {
    $suffix = str_replace(['ifOperStatus_', '_ifOperStatus', 'ifOperStatus'], '', $port_name);
    if (empty($suffix)) return '';
    
    $stmt = $pdo->prepare("
        SELECT m.nombre, e.datos 
        FROM tagente_modulo m 
        JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo 
        WHERE m.id_agente = ? 
          AND m.disabled = 0 
          AND (
              m.nombre LIKE ? 
              OR m.nombre LIKE ?
          )
    ");
    
    $likeIn = "%InOctets%{$suffix}%";
    $likeOut = "%OutOctets%{$suffix}%";
    
    $stmt->execute([$agent_id, $likeIn, $likeOut]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $rx_bps = 0.0;
    $tx_bps = 0.0;
    $has_data = false;
    
    foreach ($rows as $row) {
        $name = $row['nombre'];
        $val = (float)$row['datos'];
        $is_mbps = (stripos($name, 'Mbps') !== false);
        
        if (stripos($name, 'in') !== false) {
            $rx_bps = $is_mbps ? ($val * 1000000.0) : ($val * 8.0);
            $has_data = true;
        } elseif (stripos($name, 'out') !== false) {
            $tx_bps = $is_mbps ? ($val * 1000000.0) : ($val * 8.0);
            $has_data = true;
        }
    }
    
    if (!$has_data) return '';
    
    $format_bps = function($bps) {
        if ($bps <= 0) return '0 bps';
        if ($bps < 1000.0) return number_format($bps, 0) . ' bps';
        if ($bps < 1000000.0) return number_format($bps / 1000.0, 1) . ' Kbps';
        if ($bps < 1000000000.0) return number_format($bps / 1000000.0, 1) . ' Mbps';
        return number_format($bps / 1000000000.0, 1) . ' Gbps';
    };
    
    return "▲ " . $format_bps($tx_bps) . " | ▼ " . $format_bps($rx_bps);
}

if (!$db_status) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed: ' . $db_error]);
    exit;
}

try {
    if ($api === 'load_config') {
        $config = load_multi_dashboard_config($layout_file);
        $dashes = [];
        foreach ($config['dashboards'] as $d) {
            $dashes[] = [
                'id' => $d['id'],
                'name' => $d['name'],
                'group_id' => $d['group_id'] ?? '0',
                'group_name' => $d['group_name'] ?? 'All Groups',
                'agent_id' => $d['agent_id'] ?? '0',
                'agent_name' => $d['agent_name'] ?? 'All Nodes'
            ];
        }
        echo json_encode($dashes);
        exit;
    }

    if ($api === 'save_config') {
        ob_clean();
        $client_token = $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($csrf_token) || $client_token !== $csrf_token) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token.']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid dashboards data received.']);
            exit;
        }

        $existing = load_multi_dashboard_config($layout_file);
        $new_dashboards = [];

        foreach ($input as $in) {
            $id = $in['id'];
            $found = null;
            foreach ($existing['dashboards'] as $ex) {
                if ($ex['id'] === $id) {
                    $found = $ex;
                    break;
                }
            }

            $new_dashboards[] = [
                'id' => $id,
                'name' => $in['name'],
                'group_id' => $in['group_id'] ?? '0',
                'group_name' => $in['group_name'] ?? 'All Groups',
                'agent_id' => $in['agent_id'] ?? '0',
                'agent_name' => $in['agent_name'] ?? 'All Nodes',
                'nodes' => $found ? ($found['nodes'] ?? []) : [],
                'manual_links' => $found ? ($found['manual_links'] ?? []) : []
            ];
        }

        $bytes = file_put_contents($layout_file, json_encode(['dashboards' => $new_dashboards], JSON_PRETTY_PRINT));
        echo json_encode(['ok' => $bytes !== false]);
        exit;
    }

    if ($api === 'groups') {
        global $custom_pdos, $custom_connections;
        $target_nodes = ['primary' => $pdo];
        if (!empty($custom_pdos)) {
            foreach ($custom_pdos as $cid => $cpdo) {
                $target_nodes[$cid] = $cpdo;
            }
        }
        
        function get_node_label($node) {
            global $custom_connections;
            if ($node === 'primary') return '';
            foreach ($custom_connections as $cc) {
                if ($cc['id'] === $node) { return '[' . $cc['name'] . '] '; }
            }
            return '[' . $node . '] ';
        }

        $dropdown = [['id' => '0', 'name' => '-- All Groups --']];
        foreach ($target_nodes as $node => $active_pdo) {
            if (!$active_pdo) continue;
            $node_label = get_node_label($node);
            $stmt = $active_pdo->query("SELECT id_grupo AS id, nombre AS name FROM tgrupo ORDER BY name ASC");
            if ($stmt) {
                while ($g = $stmt->fetch()) {
                    $dropdown[] = [
                        'id' => get_node_uuid($node) . ':' . $g['id'],
                        'name' => $node_label . pretty_text($g['name'])
                    ];
                }
            }
        }
        echo json_encode($dropdown);
        exit;
    }

    if ($api === 'agents') {
        global $custom_pdos, $custom_connections;
        $target_nodes = ['primary' => $pdo];
        if (!empty($custom_pdos)) {
            foreach ($custom_pdos as $cid => $cpdo) {
                $target_nodes[$cid] = $cpdo;
            }
        }
        
        function get_node_label($node) {
            global $custom_connections;
            if ($node === 'primary') return '';
            foreach ($custom_connections as $cc) {
                if ($cc['id'] === $node) { return '[' . $cc['name'] . '] '; }
            }
            return '[' . $node . '] ';
        }

        $req_group = $_GET['group_id'] ?? '0';
        $parsed_group = parse_node_id($req_group);
        $filter_node = $parsed_group['node'] ?: 'primary';
        $real_group_id = $parsed_group['id'];
        
        $agents = [['id' => '0', 'alias' => '-- All Nodes --']];
        
        if ($req_group !== '0') {
            $active_pdo = $target_nodes[$filter_node] ?? null;
            if ($active_pdo) {
                $node_label = get_node_label($filter_node);
                $stmt = $active_pdo->prepare("SELECT id_agente AS id, alias FROM tagente WHERE disabled = 0 AND id_grupo = ? ORDER BY alias ASC");
                $stmt->execute([$real_group_id]);
                while ($row = $stmt->fetch()) {
                    $agents[] = [
                        'id' => get_node_uuid($filter_node) . ':' . $row['id'],
                        'alias' => $node_label . pretty_text($row['alias'])
                    ];
                }
            }
        } else {
            foreach ($target_nodes as $node => $active_pdo) {
                if (!$active_pdo) continue;
                $node_label = get_node_label($node);
                $stmt = $active_pdo->query("SELECT id_agente AS id, alias FROM tagente WHERE disabled = 0 ORDER BY alias ASC");
                if ($stmt) {
                    while ($row = $stmt->fetch()) {
                        $agents[] = [
                            'id' => get_node_uuid($node) . ':' . $row['id'],
                            'alias' => $node_label . pretty_text($row['alias'])
                        ];
                    }
                }
            }
        }
        echo json_encode($agents);
        exit;
    }

    if ($api === 'nodes_links') {
        $dash_id = $_GET['dash_id'] ?? '';
        $config = load_multi_dashboard_config($layout_file);
        $active_dash = null;

        foreach ($config['dashboards'] as $d) {
            if ($d['id'] === $dash_id) {
                $active_dash = $d;
                break;
            }
        }

        if (!$active_dash && !empty($config['dashboards'])) {
            $active_dash = $config['dashboards'][0];
        }

        if (!$active_dash) {
            echo json_encode(['ok' => false, 'error' => 'No active dashboard found. Please create one.']);
            exit;
        }

        $parsed_group = parse_node_id($active_dash['group_id'] ?? '0');
        $parsed_agent = parse_node_id($active_dash['agent_id'] ?? '0');
        $node = $parsed_agent['node'] ?: ($parsed_group['node'] ?: 'primary');
        
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

        $group_id = (int)$parsed_group['id'];
        $agent_id = (int)$parsed_agent['id'];
        
        $mapType = $active_dash['map_type'] ?? 'auto';
        $isBlankCanvas = ($mapType === 'blank');
        $savedNodesRaw = $active_dash['nodes'] ?? [];
        $savedNodes = [];
        foreach ($savedNodesRaw as $sid => $pos) {
            $parsed_sid = parse_node_id($sid);
            $sid_real = $parsed_sid['id'];
            $sid_node = $parsed_sid['node'] ?: 'primary';
            $savedNodes[get_node_uuid($sid_node) . ':' . $sid_real] = [
                'x' => $pos['x'] ?? 0,
                'y' => $pos['y'] ?? 0
            ];
        }
        $manualLinks = $active_dash['manual_links'] ?? [];

        // 1. Fetch active agents with optional dynamic dashboard filtering
        $params = [];
        $agentSql = "SELECT a.id_agente AS id, a.alias, a.direccion AS ip, a.id_parent, a.id_grupo, 
                            COALESCE((
                                SELECT MIN(e.estado) 
                                FROM tagente_modulo m 
                                JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                                WHERE m.id_agente = a.id_agente AND m.disabled = 0 AND m.nombre LIKE '%ifOperStatus%'
                            ), 0) as worst_port_status
                     FROM tagente a 
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
                $agentSql .= " AND a.id_agente IN ($inQuery)";
            } else {
                $agentSql .= " AND 1 = 0";
            }
        } else {
            if ($group_id > 0) {
                $agentSql .= " AND a.id_grupo = :gid";
                $params[':gid'] = $group_id;
            }
            if ($agent_id > 0) {
                // Star topology centered around core Node: Core Node + immediate Children + Parent core
                $agentSql .= " AND (a.id_agente = :aid OR a.id_parent = :aid OR a.id_agente = (SELECT COALESCE(id_parent, 0) FROM tagente WHERE id_agente = :aid))";
                $params[':aid'] = $agent_id;
            }
        }
        $agentSql .= " ORDER BY a.alias ASC";
        
        $agentsStmt = $active_pdo->prepare($agentSql);
        $agentsStmt->execute($params);
        $rawAgents = $agentsStmt->fetchAll();

        // 2. Fetch worst global health of each agent
        $agentHealthSql = "SELECT m.id_agente, MAX(e.estado) as worst_status 
                           FROM tagente_modulo m 
                           JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo 
                           WHERE m.disabled = 0 
                           GROUP BY m.id_agente";
        $healthStmt = $active_pdo->query($agentHealthSql);
        $healths = [];
        while ($h = $healthStmt->fetch()) {
            $healths[$h['id_agente']] = (int)$h['worst_status'];
        }

        // 4. Fetch active ports for SNMP status coloring
        $portsSql = "SELECT m.id_agente_modulo, m.id_agente, m.nombre, e.estado, e.datos 
                     FROM tagente_modulo m 
                     JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo 
                     WHERE (m.nombre LIKE '%ifOperStatus%' OR m.nombre LIKE '%ifAdminStatus%') AND m.disabled = 0";
        $portsStmt = $active_pdo->query($portsSql);
        $portsDb = [];
        while ($p = $portsStmt->fetch()) {
            $portsDb[$p['id_agente_modulo']] = [
                'status' => (int)$p['estado'],
                'value' => (string)$p['datos']
            ];
        }

        // Process Nodes
        $nodes = [];
        $agentsIndexed = [];
        foreach ($rawAgents as $agent) {
            $id = (int)$agent['id'];
            $prefixed_id = get_node_uuid($node) . ':' . $id;
            $agentsIndexed[$id] = $agent;

            // Health Status Mapping
            $worstModule = $healths[$id] ?? 0;
            $healthLabel = 'normal'; // green
            if ($worstModule === 1) $healthLabel = 'critical'; // red
            elseif ($worstModule === 2) $healthLabel = 'warning'; // yellow
            elseif ($worstModule === 4) $healthLabel = 'not_init'; // blue

            $nodes[] = [
                'id' => $prefixed_id,
                'label' => $node_label . pretty_text($agent['alias']),
                'ip' => $agent['ip'] ?: '-',
                'status' => $healthLabel,
                'id_parent' => $agent['id_parent'] ? (get_node_uuid($node) . ':' . (int)$agent['id_parent']) : null,
                'x' => isset($savedNodes[$prefixed_id]) ? (float)$savedNodes[$prefixed_id]['x'] : null,
                'y' => isset($savedNodes[$prefixed_id]) ? (float)$savedNodes[$prefixed_id]['y'] : null,
                'is_manual' => isset($savedNodes[$prefixed_id])
            ];
        }

        // Process Edges
        $edges = [];
        
        // Auto-generated parent-child relationships
        foreach ($nodes as $n) {
            if ($n['id_parent'] !== null) {
                $edges[] = [
                    'id' => 'auto_' . str_replace(':', '_', $n['id_parent']) . '_' . str_replace(':', '_', $n['id']),
                    'from' => $n['id_parent'],
                    'to' => $n['id'],
                    'type' => 'auto',
                    'status' => 'normal',
                    'label' => 'Parent-Child'
                ];
            }
        }

        // --- ADVANCED NETWORK TOPOLOGY AUTO-DISCOVERY ENGINE ---
        $lldpSql = "SELECT m.id_agente, m.nombre AS module_name, e.datos AS remote_sysname
                    FROM tagente_modulo m
                    JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                    WHERE m.disabled = 0 
                      AND (m.nombre LIKE '%lldpRemSysName%' 
                           OR m.nombre LIKE '%cdpCacheDeviceId%' 
                           OR m.nombre LIKE '%lldpRemPortId%')";
        $lldpStmt = $active_pdo->query($lldpSql);
        $lldpData = $lldpStmt->fetchAll();

        $allPortsSql = "SELECT m.id_agente_modulo AS port_id, m.id_agente, m.nombre AS port_name, e.estado AS port_status
                        FROM tagente_modulo m
                        JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                        WHERE m.disabled = 0 AND m.nombre LIKE '%ifOperStatus%'";
        $allPortsStmt = $active_pdo->query($allPortsSql);
        $allPorts = $allPortsStmt->fetchAll();

        // Index agents by normalized alias
        $agentsByNormalizedAlias = [];
        foreach ($nodes as $n) {
            $parsed_nid = parse_node_id($n['id']);
            $real_nid = $parsed_nid['id'];
            $label_without_prefix = str_replace($node_label, '', $n['label']);
            $norm = strtolower(trim($label_without_prefix));
            if (strlen($norm) >= 3) {
                $agentsByNormalizedAlias[$norm] = $real_nid;
            }
        }

        $discoveredLinks = [];

        // Apply Strategy A: LLDP/CDP matches
        foreach ($lldpData as $lldp) {
            $remoteName = trim($lldp['remote_sysname']);
            if (empty($remoteName)) continue;
            
            $normRemote = strtolower($remoteName);
            foreach ($agentsByNormalizedAlias as $alias => $targetAgentId) {
                if ($lldp['id_agente'] == $targetAgentId) continue;

                if (stripos($normRemote, $alias) !== false || stripos($alias, $normRemote) !== false) {
                    $key = min($lldp['id_agente'], $targetAgentId) . '_' . max($lldp['id_agente'], $targetAgentId);
                    if (!isset($discoveredLinks[$key])) {
                        $trafficLabel = get_port_traffic_label($active_pdo, $lldp['id_agente'], $lldp['module_name']);
                        $discoveredLinks[$key] = [
                            'id' => 'auto_lldp_' . get_node_uuid($node) . '_' . $key,
                            'from' => get_node_uuid($node) . ':' . $lldp['id_agente'],
                            'to' => get_node_uuid($node) . ':' . $targetAgentId,
                            'type' => 'auto',
                            'status' => 'normal',
                            'label' => 'LLDP: ' . pretty_text($remoteName) . ($trafficLabel ? "\n(" . $trafficLabel . ")" : "")
                        ];
                    }
                }
            }
        }

        // Apply Strategy B: Heuristic Interface Port Name matching
        foreach ($allPorts as $port) {
            $portName = $port['port_name'];
            $cleanPort = str_ireplace(['ifOperStatus_', '_ifOperStatus', 'ifOperStatus'], '', $portName);
            $cleanPort = pretty_text($cleanPort);
            $normPort = strtolower($cleanPort);
            
            foreach ($agentsByNormalizedAlias as $alias => $targetAgentId) {
                if ($port['id_agente'] == $targetAgentId) continue;

                if (stripos($normPort, $alias) !== false) {
                    $pStatus = (int)$port['port_status'];
                    $linkStatus = 'normal';
                    if ($pStatus === 1) $linkStatus = 'critical';
                    elseif ($pStatus === 2) $linkStatus = 'warning';
                    
                    $key = min($port['id_agente'], $targetAgentId) . '_' . max($port['id_agente'], $targetAgentId);
                    if (!isset($discoveredLinks[$key])) {
                        $trafficLabel = get_port_traffic_label($active_pdo, $port['id_agente'], $port['port_name']);
                        $discoveredLinks[$key] = [
                            'id' => 'auto_port_' . get_node_uuid($node) . '_' . $key,
                            'from' => get_node_uuid($node) . ':' . $port['id_agente'],
                            'to' => get_node_uuid($node) . ':' . $targetAgentId,
                            'type' => 'auto',
                            'status' => $linkStatus,
                            'label' => $cleanPort . ($trafficLabel ? "\n(" . $trafficLabel . ")" : "")
                        ];
                    }
                }
            }
        }

        foreach ($discoveredLinks as $dl) {
            $edges[] = $dl;
        }

        // Manual Links mapped to SNMP operational ports
        foreach ($manualLinks as $ml) {
            $parsed_src = parse_node_id($ml['source']);
            $src_node = $parsed_src['node'] ?: $node;
            $src_id = (int)$parsed_src['id'];

            $parsed_tgt = parse_node_id($ml['target']);
            $tgt_node = $parsed_tgt['node'] ?: $node;
            $tgt_id = (int)$parsed_tgt['id'];

            $srcStatus = 4;
            $tgtStatus = 4;
            if ($src_node === $node) {
                $srcPortId = (int)($ml['source_port'] ?? 0);
                $srcStatus = isset($portsDb[$srcPortId]) ? $portsDb[$srcPortId]['status'] : 4;
            }
            if ($tgt_node === $node) {
                $tgtPortId = (int)($ml['target_port'] ?? 0);
                $tgtStatus = isset($portsDb[$tgtPortId]) ? $portsDb[$tgtPortId]['status'] : 4;
            }

            $linkStatus = 'normal';
            if ($srcStatus === 2 || $tgtStatus === 2) {
                $linkStatus = 'critical';
            } elseif ($srcStatus === 1 || $tgtStatus === 1) {
                $linkStatus = 'warning';
            } elseif ($srcStatus === 4 || $tgtStatus === 4) {
                $linkStatus = 'unknown';
            }

            $trafficLabel = '';
            if ($src_node === $node) {
                $trafficLabel = get_port_traffic_label($active_pdo, $src_id, $ml['source_port_name']);
            }
            
            $edges[] = [
                'id' => $ml['id'],
                'from' => get_node_uuid($src_node) . ':' . $src_id,
                'to' => get_node_uuid($tgt_node) . ':' . $tgt_id,
                'type' => 'manual',
                'status' => $linkStatus,
                'source_port_name' => $ml['source_port_name'] ?? '',
                'target_port_name' => $ml['target_port_name'] ?? '',
                'label' => ($ml['source_port_name'] ? basename($ml['source_port_name']) : '') . ' - ' . ($ml['target_port_name'] ? basename($ml['target_port_name']) : '') . ($trafficLabel ? "\n(" . $trafficLabel . ")" : "")
            ];
        }

        echo json_encode([
            'ok' => true,
            'dash_name' => $active_dash['name'],
            'nodes' => $nodes,
            'edges' => $edges
        ]);
        exit;
    }

    if ($api === 'save_layout') {
        ob_clean();
        $client_token = $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_SERVER['HTTP_HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($csrf_token) || $client_token !== $csrf_token) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh panel.']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid layout data received.']);
            exit;
        }

        $dash_id = $_GET['dash_id'] ?? '';
        $config = load_multi_dashboard_config($layout_file);
        $found_idx = -1;

        foreach ($config['dashboards'] as $idx => $d) {
            if ($d['id'] === $dash_id) {
                $found_idx = $idx;
                break;
            }
        }

        if ($found_idx !== -1) {
            $config['dashboards'][$found_idx]['nodes'] = $input['nodes'] ?? [];
            $config['dashboards'][$found_idx]['manual_links'] = $input['manual_links'] ?? [];
            $bytes = file_put_contents($layout_file, json_encode($config, JSON_PRETTY_PRINT));
            echo json_encode(['ok' => $bytes !== false, 'error' => $bytes === false ? 'Save failed: Check permissions on Dashboard/Network-Mapping directory.' : '']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Dashboard not found.']);
        }
        exit;
    }

    if ($api === 'agent_ports') {
        $req_agent = $_GET['id_agent'] ?? '';
        $parsed_agent = parse_node_id($req_agent);
        $node = $parsed_agent['node'] ?: 'primary';
        $id_agent = (int)$parsed_agent['id'];
        
        if ($id_agent <= 0) {
            echo json_encode([]);
            exit;
        }

        global $custom_pdos;
        $target_nodes = ['primary' => $pdo];
        if (!empty($custom_pdos)) {
            foreach ($custom_pdos as $cid => $cpdo) {
                $target_nodes[$cid] = $cpdo;
            }
        }
        $active_pdo = $target_nodes[$node] ?? $pdo;

        $stmt = $active_pdo->prepare("SELECT m.id_agente_modulo AS id, m.nombre AS name, e.estado, e.datos 
                               FROM tagente_modulo m 
                               JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                               WHERE m.id_agente = ? AND (m.nombre LIKE '%ifOperStatus%' OR m.nombre LIKE '%ifAdminStatus%') AND m.disabled = 0
                               ORDER BY m.nombre ASC");
        $stmt->execute([$id_agent]);
        $ports = $stmt->fetchAll();

        foreach ($ports as &$p) {
            $p['id'] = get_node_uuid($node) . ':' . $p['id'];
            $p['name'] = pretty_text($p['name']);
            $p['clean_name'] = str_replace(['ifOperStatus_', '_ifOperStatus', 'ifOperStatus', 'ifAdminStatus_', '_ifAdminStatus', 'ifAdminStatus'], '', $p['name']);
        }
        echo json_encode($ports);
        exit;
    }

    if ($api === 'agent_details') {
        $req_agent = $_GET['id_agent'] ?? '';
        $parsed_agent = parse_node_id($req_agent);
        $node = $parsed_agent['node'] ?: 'primary';
        $id_agent = (int)$parsed_agent['id'];
        
        if ($id_agent <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid Agent ID']);
            exit;
        }

        global $custom_pdos;
        $target_nodes = ['primary' => $pdo];
        if (!empty($custom_pdos)) {
            foreach ($custom_pdos as $cid => $cpdo) {
                $target_nodes[$cid] = $cpdo;
            }
        }
        $active_pdo = $target_nodes[$node] ?? $pdo;

        $stmt = $active_pdo->prepare("SELECT m.id_agente_modulo AS id, m.nombre AS name, e.datos AS current_value, 
                                       e.timestamp, e.estado, COALESCE(m.unit, '') as unit
                               FROM tagente_modulo m 
                               JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                               WHERE m.id_agente = ? AND m.disabled = 0
                               ORDER BY m.nombre ASC");
        $stmt->execute([$id_agent]);
        $modules = $stmt->fetchAll();

        $cpu = 'N/A';
        $ram = 'N/A';
        $latency = 'N/A';
        $hostAliveVal = 'N/A';
        $latencyVal = 'N/A';
        $packetLoss = 'N/A';
        $portStatuses = [];

        foreach ($modules as &$mod) {
            $mod['name'] = pretty_text($mod['name'] ?? '');
            $val = (float)($mod['current_value'] ?? 0.0);
            $nameLower = strtolower((string)($mod['name'] ?? ''));

            if (stripos($nameLower, 'cpu') !== false || stripos($nameLower, 'processor') !== false) {
                $cpu_val = is_numeric($mod['current_value'] ?? '') ? round((float)$mod['current_value'], 2) : ($mod['current_value'] ?? '');
                $cpu = $cpu_val . (($mod['unit'] ?? '') ?: '%');
            } elseif (stripos($nameLower, 'ram') !== false || stripos($nameLower, 'memory') !== false) {
                $ram_val = is_numeric($mod['current_value'] ?? '') ? round((float)$mod['current_value'], 2) : ($mod['current_value'] ?? '');
                $ram = $ram_val . (($mod['unit'] ?? '') ?: '%');
            } elseif (stripos($nameLower, 'host alive') !== false || stripos($nameLower, 'hostalive') !== false || stripos($nameLower, 'alive') !== false) {
                $status_val = trim((string)($mod['current_value'] ?? ''));
                $status_num = is_numeric($status_val) ? (int)(float)$status_val : -1;
                if ($status_num === 1 || strtolower($status_val) === 'up' || $status_val === '1') {
                    $hostAliveVal = 'UP';
                } elseif ($status_num === 0 || strtolower($status_val) === 'down' || $status_val === '0') {
                    $hostAliveVal = 'DOWN';
                } else {
                    $hostAliveVal = strtoupper((string)($status_val ?? ''));
                }
            } elseif (stripos($nameLower, 'latency') !== false || stripos($nameLower, 'ping') !== false) {
                $lat_val = is_numeric($mod['current_value'] ?? '') ? round((float)$mod['current_value'], 2) : ($mod['current_value'] ?? '');
                $latencyVal = $lat_val . (($mod['unit'] ?? '') ?: ' ms');
            } elseif (stripos($nameLower, 'packet loss') !== false) {
                $loss_val = is_numeric($mod['current_value'] ?? '') ? round((float)$mod['current_value'], 2) : ($mod['current_value'] ?? '');
                $packetLoss = $loss_val . (($mod['unit'] ?? '') ?: '%');
            }

            if (stripos($nameLower, 'ifoperstatus') !== false) {
                $clean_val = is_numeric($mod['current_value'] ?? '') ? (int)(float)$mod['current_value'] : ($mod['current_value'] ?? '');
                $portStatuses[] = [
                    'id' => get_node_uuid($node) . ':' . ($mod['id'] ?? 0),
                    'port' => str_replace(['ifOperStatus_', '_ifOperStatus', 'ifOperStatus'], '', $mod['name'] ?? ''),
                    'status' => (int)($mod['estado'] ?? 0),
                    'value' => $clean_val
                ];
            }
        }

        $latency = ($hostAliveVal !== 'N/A') ? $hostAliveVal : $latencyVal;

        echo json_encode([
            'ok' => true,
            'metrics' => [
                'cpu' => $cpu,
                'ram' => $ram,
                'latency' => $latency,
                'loss' => $packetLoss
            ],
            'ports' => $portStatuses,
            'modules' => array_slice($modules, 0, 30)
        ]);
        exit;
    }

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
