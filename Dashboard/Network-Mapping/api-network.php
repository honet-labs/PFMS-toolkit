<?php
/**
 * api-network.php
 * High-performance backend API for Custom Network Mapping Dashboard
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

// Helper to load current layout layout X/Y positions and manual links
function get_mapping_layout($file) {
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (is_array($data)) {
            return [
                'nodes' => $data['nodes'] ?? [],
                'manual_links' => $data['manual_links'] ?? []
            ];
        }
    }
    return ['nodes' => [], 'manual_links' => []];
}

if (!$db_status) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed: ' . $db_error]);
    exit;
}

try {
    if ($api === 'nodes_links') {
        // 1. Fetch active agents
        $agentSql = "SELECT a.id_agente AS id, a.alias, a.direccion AS ip, a.id_parent, a.id_grupo, 
                            COALESCE((
                                SELECT MIN(e.estado) 
                                FROM tagente_modulo m 
                                JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                                WHERE m.id_agente = a.id_agente AND m.disabled = 0 AND m.nombre LIKE 'ifOperStatus_%'
                            ), 0) as worst_port_status
                     FROM tagente a 
                     WHERE a.disabled = 0 
                     ORDER BY a.alias ASC";
        
        $agentsStmt = $pdo->query($agentSql);
        $rawAgents = $agentsStmt->fetchAll();

        // 2. Fetch worst global health of each agent (Pandora FMS default status)
        // Usually, worst module state or global state. We can query tagente_estado or tagente agent status column if available.
        // Let's also check worst module state from tagente_modulo
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

        // 3. Load layout coordinates and manual links
        $layout = get_mapping_layout($layout_file);
        $savedNodes = $layout['nodes'];
        $manualLinks = $layout['manual_links'];

        // 4. Fetch all active port module values to dynamic coloring
        $portsSql = "SELECT m.id_agente_modulo, m.id_agente, m.nombre, e.estado, e.datos 
                     FROM tagente_modulo m 
                     JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo 
                     WHERE m.nombre LIKE 'ifOperStatus_%' AND m.disabled = 0";
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
            // Default health is from worst module state, fall back to worst port status or normal
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
                    'status' => 'normal', // default parent child link
                    'label' => 'Parent-Child'
                ];
            }
        }

        // Manual Links mapped to SNMP operational ports
        foreach ($manualLinks as $ml) {
            $srcPortId = (int)($ml['source_port'] ?? 0);
            $tgtPortId = (int)($ml['target_port'] ?? 0);

            // Fetch operational statuses from dynamic DB query
            $srcStatus = isset($portsDb[$srcPortId]) ? $portsDb[$srcPortId]['status'] : 4;
            $tgtStatus = isset($portsDb[$tgtPortId]) ? $portsDb[$tgtPortId]['status'] : 4;

            // Dynamic color determination: if either is critical (down), link is critical
            $linkStatus = 'normal'; // green
            if ($srcStatus === 1 || $tgtStatus === 1) {
                $linkStatus = 'critical'; // red
            } elseif ($srcStatus === 2 || $tgtStatus === 2) {
                $linkStatus = 'warning'; // yellow
            } elseif ($srcStatus === 4 || $tgtStatus === 4) {
                $linkStatus = 'unknown'; // grey/blue
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
            'nodes' => $nodes,
            'edges' => $edges
        ]);
        exit;
    }

    if ($api === 'save_layout') {
        ob_clean();
        // CSRF Token Check
        $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($csrf_token) || $client_token !== $csrf_token) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh panel.']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid layout data received.']);
            exit;
        }

        $save_data = [
            'nodes' => $input['nodes'] ?? [],
            'manual_links' => $input['manual_links'] ?? []
        ];

        $bytes = @file_put_contents($layout_file, json_encode($save_data, JSON_PRETTY_PRINT));
        echo json_encode(['ok' => $bytes !== false, 'error' => $bytes === false ? 'Save failed: Check permissions on Dashboard/Network-Mapping directory.' : '']);
        exit;
    }

    if ($api === 'agent_ports') {
        $id_agent = (int)($_GET['id_agent'] ?? 0);
        if ($id_agent <= 0) {
            echo json_encode([]);
            exit;
        }

        // Fetch all SNMP status ports for a specific agent
        $stmt = $pdo->prepare("SELECT m.id_agente_modulo AS id, m.nombre AS name, e.estado, e.datos 
                               FROM tagente_modulo m 
                               JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                               WHERE m.id_agente = ? AND m.nombre LIKE 'ifOperStatus_%' AND m.disabled = 0
                               ORDER BY m.nombre ASC");
        $stmt->execute([$id_agent]);
        $ports = $stmt->fetchAll();

        foreach ($ports as &$p) {
            $p['name'] = pretty_text($p['name']);
            // Standardize output name (clean 'ifOperStatus_' prefix if needed)
            $p['clean_name'] = str_replace('ifOperStatus_', '', $p['name']);
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

        // Fetch all modules for sidebar drawer
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

            // Detect performance markers
            if (stripos($nameLower, 'cpu') !== false || stripos($nameLower, 'processor') !== false) {
                $cpu = $mod['current_value'] . ($mod['unit'] ?: '%');
            } elseif (stripos($nameLower, 'ram') !== false || stripos($nameLower, 'memory') !== false) {
                $ram = $mod['current_value'] . ($mod['unit'] ?: '%');
            } elseif (stripos($nameLower, 'latency') !== false || stripos($nameLower, 'ping') !== false) {
                $latency = $mod['current_value'] . ($mod['unit'] ?: ' ms');
            } elseif (stripos($nameLower, 'packet loss') !== false) {
                $packetLoss = $mod['current_value'] . ($mod['unit'] ?: '%');
            }

            if (stripos($nameLower, 'ifoperstatus_') !== false) {
                $portStatuses[] = [
                    'id' => $mod['id'],
                    'port' => str_replace('ifOperStatus_', '', $mod['name']),
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
            'modules' => array_slice($modules, 0, 30) // cap to 30 modules for preview
        ]);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    exit;
}
