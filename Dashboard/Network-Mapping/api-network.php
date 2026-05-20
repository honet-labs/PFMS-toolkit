<?php
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
        $stmt = $pdo->query("SELECT id_grupo AS id, nombre AS name FROM tgrupo ORDER BY name ASC");
        $dropdown = [['id' => '0', 'name' => '-- All Groups --']];
        while ($g = $stmt->fetch()) {
            $dropdown[] = [
                'id' => (string)$g['id'],
                'name' => pretty_text($g['name'])
            ];
        }
        echo json_encode($dropdown);
        exit;
    }

    if ($api === 'agents') {
        $groupId = (int)($_GET['group_id'] ?? 0);
        $params = [];
        $sql = "SELECT id_agente AS id, alias FROM tagente WHERE disabled = 0";
        if ($groupId > 0) {
            $sql .= " AND id_grupo = ?";
            $params[] = $groupId;
        }
        $sql .= " ORDER BY alias ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $agents = [['id' => '0', 'alias' => '-- All Nodes --']];
        while ($row = $stmt->fetch()) {
            $agents[] = [
                'id' => (string)$row['id'],
                'alias' => pretty_text($row['alias'])
            ];
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

        $group_id = (int)($active_dash['group_id'] ?? 0);
        $agent_id = (int)($active_dash['agent_id'] ?? 0);

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
        
        if ($group_id > 0) {
            $agentSql .= " AND a.id_grupo = :gid";
            $params[':gid'] = $group_id;
        }
        if ($agent_id > 0) {
            // Star topology centered around core Node: Core Node + immediate Children + Parent core
            $agentSql .= " AND (a.id_agente = :aid OR a.id_parent = :aid OR a.id_agente = (SELECT COALESCE(id_parent, 0) FROM tagente WHERE id_agente = :aid))";
            $params[':aid'] = $agent_id;
        }
        $agentSql .= " ORDER BY a.alias ASC";
        
        $agentsStmt = $pdo->prepare($agentSql);
        $agentsStmt->execute($params);
        $rawAgents = $agentsStmt->fetchAll();

        // 2. Fetch worst global health of each agent (Pandora FMS default status)
        $agentHealthSql = "SELECT m.id_agente, MAX(e.estado) as worst_status 
                           FROM tagente_modulo m 
                           JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo 
                           WHERE m.disabled = 0 
                           GROUP BY m.id_agente";
        $healthStmt = $pdo->query($agentHealthSql);
        $healths = [];
        while ($h = $healthStmt->fetch()) {
            $healths[$h['id_agente']] = (int)$h['worst_status'];
        }

        // 3. Load layout coordinates and manual links specifically for this dashboard
        $savedNodes = $active_dash['nodes'] ?? [];
        $manualLinks = $active_dash['manual_links'] ?? [];

        // 4. Fetch active ports for SNMP status coloring
        $portsSql = "SELECT m.id_agente_modulo, m.id_agente, m.nombre, e.estado, e.datos 
                     FROM tagente_modulo m 
                     JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo 
                     WHERE m.nombre LIKE '%ifOperStatus%' AND m.disabled = 0";
        $portsStmt = $pdo->query($portsSql);
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
            $agentsIndexed[$id] = $agent;

            // Health Status Mapping (Green, Red, Yellow, Blue)
            $worstModule = $healths[$id] ?? 0;
            $healthLabel = 'normal'; // green
            if ($worstModule === 1) $healthLabel = 'critical'; // red
            elseif ($worstModule === 2) $healthLabel = 'warning'; // yellow
            elseif ($worstModule === 4) $healthLabel = 'not_init'; // blue

            $nodes[] = [
                'id' => $id,
                'label' => pretty_text($agent['alias']),
                'ip' => $agent['ip'] ?: '-',
                'status' => $healthLabel,
                'id_parent' => $agent['id_parent'] ? (int)$agent['id_parent'] : null,
                'x' => isset($savedNodes[$id]) ? (float)$savedNodes[$id]['x'] : null,
                'y' => isset($savedNodes[$id]) ? (float)$savedNodes[$id]['y'] : null,
                'is_manual' => isset($savedNodes[$id])
            ];
        }

        // Process Edges (Links)
        $edges = [];
        
        // Auto-generated parent-child relationships
        foreach ($nodes as $n) {
            if ($n['id_parent'] !== null && isset($agentsIndexed[$n['id_parent']])) {
                $edges[] = [
                    'id' => 'auto_' . $n['id_parent'] . '_' . $n['id'],
                    'from' => $n['id_parent'],
                    'to' => $n['id'],
                    'type' => 'auto',
                    'status' => 'normal',
                    'label' => 'Parent-Child'
                ];
            }
        }

        // Manual Links mapped to SNMP operational ports
        foreach ($manualLinks as $ml) {
            $srcPortId = (int)($ml['source_port'] ?? 0);
            $tgtPortId = (int)($ml['target_port'] ?? 0);

            $srcStatus = isset($portsDb[$srcPortId]) ? $portsDb[$srcPortId]['status'] : 4;
            $tgtStatus = isset($portsDb[$tgtPortId]) ? $portsDb[$tgtPortId]['status'] : 4;

            // Dynamic color: critical if either is down
            $linkStatus = 'normal';
            if ($srcStatus === 1 || $tgtStatus === 1) {
                $linkStatus = 'critical';
            } elseif ($srcStatus === 2 || $tgtStatus === 2) {
                $linkStatus = 'warning';
            } elseif ($srcStatus === 4 || $tgtStatus === 4) {
                $linkStatus = 'unknown';
            }

            $edges[] = [
                'id' => $ml['id'],
                'from' => (int)$ml['source'],
                'to' => (int)$ml['target'],
                'type' => 'manual',
                'status' => $linkStatus,
                'source_port_name' => $ml['source_port_name'] ?? '',
                'target_port_name' => $ml['target_port_name'] ?? '',
                'label' => ($ml['source_port_name'] ? basename($ml['source_port_name']) : '') . ' - ' . ($ml['target_port_name'] ? basename($ml['target_port_name']) : '')
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
        $id_agent = (int)($_GET['id_agent'] ?? 0);
        if ($id_agent <= 0) {
            echo json_encode([]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT m.id_agente_modulo AS id, m.nombre AS name, e.estado, e.datos 
                               FROM tagente_modulo m 
                               JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                               WHERE m.id_agente = ? AND m.nombre LIKE '%ifOperStatus%' AND m.disabled = 0
                               ORDER BY m.nombre ASC");
        $stmt->execute([$id_agent]);
        $ports = $stmt->fetchAll();

        foreach ($ports as &$p) {
            $p['name'] = pretty_text($p['name']);
            $p['clean_name'] = str_replace(['ifOperStatus_', '_ifOperStatus', 'ifOperStatus'], '', $p['name']);
        }
        echo json_encode($ports);
        exit;
    }

    if ($api === 'agent_details') {
        $id_agent = (int)($_GET['id_agent'] ?? 0);
        if ($id_agent <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid Agent ID']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT m.id_agente_modulo AS id, m.nombre AS name, e.datos AS current_value, 
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
        $packetLoss = 'N/A';
        $portStatuses = [];

        foreach ($modules as &$mod) {
            $mod['name'] = pretty_text($mod['name']);
            $val = (float)$mod['current_value'];
            $nameLower = strtolower($mod['name']);

            if (stripos($nameLower, 'cpu') !== false || stripos($nameLower, 'processor') !== false) {
                $cpu = $mod['current_value'] . ($mod['unit'] ?: '%');
            } elseif (stripos($nameLower, 'ram') !== false || stripos($nameLower, 'memory') !== false) {
                $ram = $mod['current_value'] . ($mod['unit'] ?: '%');
            } elseif (stripos($nameLower, 'latency') !== false || stripos($nameLower, 'ping') !== false) {
                $latency = $mod['current_value'] . ($mod['unit'] ?: ' ms');
            } elseif (stripos($nameLower, 'packet loss') !== false) {
                $packetLoss = $mod['current_value'] . ($mod['unit'] ?: '%');
            }

            if (stripos($nameLower, 'ifoperstatus') !== false) {
                $portStatuses[] = [
                    'id' => $mod['id'],
                    'port' => str_replace(['ifOperStatus_', '_ifOperStatus', 'ifOperStatus'], '', $mod['name']),
                    'status' => (int)$mod['estado'],
                    'value' => $mod['current_value']
                ];
            }
        }

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

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
