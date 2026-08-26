<?php
declare(strict_types=1);

/**
 * Route Parser Dashboard (Network Path Visualization)
 * PFMS-Toolkit - Enterprise Edition
 * 
 * Visualizes network routes traced by the Pandora FMS route_parser plugin
 * based on RouteStep and RouteTarget modules.
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// --- 1. CORE & DATABASE CONNECTION ---
$db_connection_file = __DIR__ . '/../../includes/db-connection.php';
if (file_exists($db_connection_file)) {
    require_once $db_connection_file;
} else {
    $possible_paths = [
        dirname(__DIR__, 2) . '/includes/db-connection.php',
        $_SERVER['DOCUMENT_ROOT'] . '/pandora_console/includes/db-connection.php'
    ];
    foreach ($possible_paths as $p) {
        if (file_exists($p)) {
            require_once $p;
            break;
        }
    }
}

// --- 2. AUTHENTICATION (PANDORA FMS SESSION) ---
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$user_id = $_SESSION['id_usuario'] ?? 0;
// If embedded within portal or standalone console session
$is_authenticated = !empty($user_id);
if (!$is_authenticated && !isset($_GET['debug']) && !isset($_GET['demo'])) {
    // Check if running directly inside console
    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $pandora_base = preg_match('#^(/.*?)/(custom|customize)/panel#', $script_dir, $m) ? rtrim($m[1], '/') : '/pandora_console';
    header("Location: " . $pandora_base . "/index.php");
    exit;
}

// Helpers
if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

function clean_hop_label(string $module_name): string {
    $prefixes = ['RouteStepTarget_', 'RouteStep_', 'RouteTarget_'];
    foreach ($prefixes as $p) {
        if (strpos($module_name, $p) === 0) {
            return substr($module_name, strlen($p));
        }
    }
    return $module_name;
}

function get_status_key(?int $estado): string {
    return match ((int)$estado) {
        0 => 'ok',
        1 => 'crit',
        2 => 'warn',
        default => 'na'
    };
}

// --- 3. INPUT PARAMETERS ---
$selected_node = $_GET['node'] ?? 'primary';
$selected_agent_id = isset($_GET['agent_id']) ? (int)$_GET['agent_id'] : 0;
$time_range = $_GET['range'] ?? '1d';
$auto_refresh = $_GET['refresh'] ?? '5m';
$is_demo = isset($_GET['demo']) || isset($_GET['debug']);

// Calculate time window timestamps
$now = time();
$range_seconds = match ($time_range) {
    '1h' => 3600,
    '6h' => 21600,
    '1d' => 86400,
    '7d' => 604800,
    '30d' => 2592000,
    default => 86400
};
$range_start = $now - $range_seconds;
$range_label = match ($time_range) {
    '1h' => 'Last 1 hour',
    '6h' => 'Last 6 hours',
    '1d' => 'Last 1 day',
    '7d' => 'Last 7 days',
    '30d' => 'Last 30 days',
    default => 'Last 1 day'
};

// Threshold defaults
$warn_threshold = 10.0;
$crit_threshold = 50.0;

// --- 4. DATA DISCOVERY & PARSING ---
$active_pdo = ($selected_node === 'primary' || !isset($custom_pdos[$selected_node])) ? $pdo : ($custom_pdos[$selected_node] ?? $pdo);

// Find all agents with RouteStep/RouteTarget modules
$available_agents = [];
if ($active_pdo) {
    try {
        $sql = "SELECT DISTINCT a.id_agente, a.nombre, a.alias, a.direccion, COUNT(tm.id_agente_modulo) as route_modules_count
                FROM tagente a
                JOIN tagente_modulo tm ON tm.id_agente = a.id_agente
                WHERE a.disabled = 0 
                  AND (tm.nombre LIKE 'RouteStep%' OR tm.nombre LIKE 'RouteTarget%')
                GROUP BY a.id_agente, a.nombre, a.alias, a.direccion
                ORDER BY a.alias ASC";
        $stmt = $active_pdo->query($sql);
        if ($stmt) {
            $available_agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log("Route Parser agent discovery failed: " . $e->getMessage());
    }
}

// If no specific agent selected, choose the first available one
if ($selected_agent_id === 0 && !empty($available_agents)) {
    $selected_agent_id = (int)$available_agents[0]['id_agente'];
}

$agent_info = null;
$modules_raw = [];
$stats_by_module = [];

if ($selected_agent_id > 0 && $active_pdo) {
    try {
        // Fetch agent info
        $stAgent = $active_pdo->prepare("SELECT id_agente, nombre, alias, direccion FROM tagente WHERE id_agente = ?");
        $stAgent->execute([$selected_agent_id]);
        $agent_info = $stAgent->fetch(PDO::FETCH_ASSOC);

        // Fetch RouteStep / RouteTarget modules
        $stMod = $active_pdo->prepare("
            SELECT tm.id_agente_modulo, tm.nombre, tm.parent_module_id, tm.descripcion,
                   te.datos as last_val, te.estado, te.utimestamp as last_ts
            FROM tagente_modulo tm
            LEFT JOIN tagente_estado te ON te.id_agente_modulo = tm.id_agente_modulo
            WHERE tm.id_agente = ? 
              AND tm.disabled = 0
              AND (tm.nombre LIKE 'RouteStep%' OR tm.nombre LIKE 'RouteTarget%')
            ORDER BY tm.id_agente_modulo ASC
        ");
        $stMod->execute([$selected_agent_id]);
        $modules_raw = $stMod->fetchAll(PDO::FETCH_ASSOC);

        // Fetch min/max stats within time range for each module
        if (!empty($modules_raw)) {
            $mod_ids = array_column($modules_raw, 'id_agente_modulo');
            $placeholders = implode(',', array_fill(0, count($mod_ids), '?'));
            $params = array_merge($mod_ids, [$range_start, $now]);
            
            $stStats = $active_pdo->prepare("
                SELECT id_agente_modulo, MIN(datos) as min_val, MAX(datos) as max_val, AVG(datos) as avg_val
                FROM tagente_datos
                WHERE id_agente_modulo IN ($placeholders)
                  AND utimestamp BETWEEN ? AND ?
                GROUP BY id_agente_modulo
            ");
            $stStats->execute($params);
            while ($row = $stStats->fetch(PDO::FETCH_ASSOC)) {
                $stats_by_module[(int)$row['id_agente_modulo']] = [
                    'min' => (float)$row['min_val'],
                    'max' => (float)$row['max_val'],
                    'avg' => (float)$row['avg_val']
                ];
            }
        }
    } catch (Throwable $e) {
        error_log("Route Parser module query failed: " . $e->getMessage());
    }
}

// Fallback to Rich Demo Data if no active modules in database or demo flag set
$use_demo_data = $is_demo || empty($modules_raw);
$source_ip = $agent_info['direccion'] ?? '172.17.8.96';
$agent_display_name = $agent_info['alias'] ?? ($agent_info['nombre'] ?? 'Core-Gateway-01');
$topology_hash = hash('sha256', ($selected_agent_id ?: 'demo') . '_route_parser_' . $source_ip);

$graph_nodes = [];
$graph_edges = [];
$targets_count = 0;

if ($use_demo_data) {
    $source_ip = '172.17.8.96';
    $selected_agent_id = $selected_agent_id ?: 1;
    $topology_hash = '844903017dd2d731527ade67a75139b83edab5efb03a55e63f097648bdb7095e';
    
    // Construct Demo Topology matching the screenshot
    $demo_nodes = [
        'hop_src' => [
            'id' => 101,
            'name' => 'RouteStep_172.17.8.96',
            'ip' => '172.17.8.96',
            'type' => 'src',
            'role' => 'HOP',
            'status' => 'ok',
            'last_ms' => 0.0,
            'min_ms' => 0.0,
            'max_ms' => 0.056,
            'parent' => null
        ],
        'hop_gw' => [
            'id' => 102,
            'name' => 'RouteStep_172.17.8.1',
            'ip' => '172.17.8.1',
            'type' => 'hop',
            'role' => 'HOP',
            'status' => 'ok',
            'last_ms' => 0.92,
            'min_ms' => 0.45,
            'max_ms' => 1.85,
            'parent' => 'hop_src'
        ],
        'hop_branch1' => [
            'id' => 103,
            'name' => 'RouteStep_10.10.5.1',
            'ip' => '10.10.5.1',
            'type' => 'hop',
            'role' => 'HOP',
            'status' => 'ok',
            'last_ms' => 0.316,
            'min_ms' => 0.21,
            'max_ms' => 0.95,
            'parent' => 'hop_gw'
        ],
        'target_1' => [
            'id' => 104,
            'name' => 'RouteStepTarget_10.10.5.81',
            'ip' => '10.10.5.81',
            'type' => 'target',
            'role' => 'TARGET',
            'status' => 'warn',
            'last_ms' => 10.737,
            'min_ms' => 5.2,
            'max_ms' => 18.4,
            'parent' => 'hop_branch1'
        ],
        'hop_branch2' => [
            'id' => 105,
            'name' => 'RouteStep_10.10.6.1',
            'ip' => '10.10.6.1',
            'type' => 'hop',
            'role' => 'HOP',
            'status' => 'ok',
            'last_ms' => 0.285,
            'min_ms' => 0.18,
            'max_ms' => 0.82,
            'parent' => 'hop_gw'
        ],
        'target_2' => [
            'id' => 106,
            'name' => 'RouteStepTarget_10.10.6.220',
            'ip' => '10.10.6.220',
            'type' => 'target',
            'role' => 'TARGET',
            'status' => 'ok',
            'last_ms' => 2.162,
            'min_ms' => 1.15,
            'max_ms' => 4.30,
            'parent' => 'hop_branch2'
        ]
    ];

    $graph_nodes = $demo_nodes;
    $graph_edges = [
        ['from' => 'hop_src', 'to' => 'hop_gw', 'label' => '0.92 ms', 'ms' => 0.92, 'status' => 'ok'],
        ['from' => 'hop_gw', 'to' => 'hop_branch1', 'label' => '0.316 ms', 'ms' => 0.316, 'status' => 'ok'],
        ['from' => 'hop_branch1', 'to' => 'target_1', 'label' => '10.737 ms', 'ms' => 10.737, 'status' => 'warn'],
        ['from' => 'hop_gw', 'to' => 'hop_branch2', 'label' => '0.285 ms', 'ms' => 0.285, 'status' => 'ok'],
        ['from' => 'hop_branch2', 'to' => 'target_2', 'label' => '2.162 ms', 'ms' => 2.162, 'status' => 'ok']
    ];
    $targets_count = 2;
} else {
    // Build Graph from real Pandora FMS modules
    $id_to_key = [];
    $raw_by_id = [];
    foreach ($modules_raw as $m) {
        $mid = (int)$m['id_agente_modulo'];
        $key = 'mod_' . $mid;
        $id_to_key[$mid] = $key;
        $raw_by_id[$mid] = $m;
    }

    foreach ($modules_raw as $m) {
        $mid = (int)$m['id_agente_modulo'];
        $key = $id_to_key[$mid];
        $ip = clean_hop_label($m['nombre']);
        $is_target = (strpos($m['nombre'], 'Target') !== false || strpos($m['nombre'], 'RouteTarget') !== false);
        $last_val = (float)($m['last_val'] ?? 0.0);
        $p_id = (int)$m['parent_module_id'];
        
        $stats = $stats_by_module[$mid] ?? null;
        $min_ms = $stats ? $stats['min'] : $last_val;
        $max_ms = $stats ? $stats['max'] : $last_val;
        
        $status = get_status_key($m['estado']);
        if ($status === 'na' || $status === 'ok') {
            if ($last_val >= $crit_threshold) $status = 'crit';
            elseif ($last_val >= $warn_threshold) $status = 'warn';
            else $status = 'ok';
        }

        if ($is_target) $targets_count++;

        $graph_nodes[$key] = [
            'id' => $mid,
            'name' => $m['nombre'],
            'ip' => $ip,
            'type' => $is_target ? 'target' : 'hop',
            'role' => $is_target ? 'TARGET' : 'HOP',
            'status' => $status,
            'last_ms' => $last_val,
            'min_ms' => $min_ms,
            'max_ms' => $max_ms,
            'parent' => ($p_id > 0 && isset($id_to_key[$p_id])) ? $id_to_key[$p_id] : null
        ];
    }

    // Connect edges
    foreach ($graph_nodes as $key => $n) {
        if (!empty($n['parent']) && isset($graph_nodes[$n['parent']])) {
            $graph_edges[] = [
                'from' => $n['parent'],
                'to' => $key,
                'label' => round($n['last_ms'], 3) . ' ms',
                'ms' => $n['last_ms'],
                'status' => $n['status']
            ];
        }
    }

    // If there's an isolated root hop or source node without parent, assign source
    $roots = [];
    foreach ($graph_nodes as $k => $n) {
        if (empty($n['parent'])) $roots[] = $k;
    }
    if (!empty($roots)) {
        $source_key = $roots[0];
        $graph_nodes[$source_key]['type'] = 'src';
    }
}

// Layout Calculation (Tree Depth & Y Spacing)
function calculate_tree_layout(array $nodes, array $edges): array {
    if (empty($nodes)) return ['positions' => [], 'svg_w' => 900, 'svg_h' => 600];

    $children = [];
    $parents = [];
    foreach ($edges as $e) {
        $p = $e['from'];
        $c = $e['to'];
        $children[$p][] = $c;
        $parents[$c][] = $p;
    }

    $roots = [];
    foreach ($nodes as $k => $n) {
        if (empty($parents[$k])) $roots[] = $k;
    }
    if (empty($roots)) $roots = [array_key_first($nodes)];

    // BFS Depth
    $depth = [];
    $queue = [];
    foreach ($roots as $r) {
        $depth[$r] = 0;
        $queue[] = $r;
    }
    $max_depth = 0;
    while (!empty($queue)) {
        $u = array_shift($queue);
        $d = $depth[$u];
        $max_depth = max($max_depth, $d);
        foreach ($children[$u] ?? [] as $v) {
            if (!isset($depth[$v]) || $depth[$v] > $d + 1) {
                $depth[$v] = $d + 1;
                $queue[] = $v;
            }
        }
    }
    foreach ($nodes as $k => $_) {
        if (!isset($depth[$k])) $depth[$k] = 0;
    }

    // DFS Y assignment
    $y_pos = [];
    $visited = [];
    $curr_y = 150.0;
    $y_gap = 130.0;

    $assign_y = function($u) use (&$assign_y, &$children, &$y_pos, &$visited, &$curr_y, $y_gap) {
        if (isset($visited[$u])) return;
        $visited[$u] = true;
        $chs = $children[$u] ?? [];
        if (empty($chs)) {
            $y_pos[$u] = $curr_y;
            $curr_y += $y_gap;
            return;
        }
        foreach ($chs as $v) {
            $assign_y($v);
        }
        $sum = 0.0;
        $cnt = 0;
        foreach ($chs as $v) {
            if (isset($y_pos[$v])) {
                $sum += $y_pos[$v];
                $cnt++;
            }
        }
        $y_pos[$u] = ($cnt > 0) ? ($sum / $cnt) : $curr_y;
        if ($cnt === 0) $curr_y += $y_gap;
    };

    foreach ($roots as $r) {
        $assign_y($r);
        $curr_y += $y_gap;
    }
    foreach (array_keys($nodes) as $k) {
        if (!isset($y_pos[$k])) $assign_y($k);
    }

    // Coordinates with normalization
    $x_start = 140.0;
    $x_spacing = 220.0;
    $positions = [];
    $min_y = 9999.0;
    $max_y = -9999.0;

    foreach ($nodes as $k => $_) {
        $d = $depth[$k] ?? 0;
        $x = $x_start + ($d * $x_spacing);
        $y = $y_pos[$k] ?? 200.0;
        $min_y = min($min_y, $y);
        $max_y = max($max_y, $y);
        $positions[$k] = ['x' => $x, 'y' => $y];
    }

    $target_h = max(450.0, ($max_y - $min_y) + 160.0);
    $center_shift_y = 260.0 - (($min_y + $max_y) / 2);

    foreach ($positions as $k => $p) {
        $positions[$k]['y'] = $p['y'] + $center_shift_y;
    }

    $svg_w = (int)max(900, $x_start + (($max_depth + 1) * $x_spacing) + 150);
    $svg_h = (int)max(560, $target_h);

    return ['positions' => $positions, 'svg_w' => $svg_w, 'svg_h' => $svg_h];
}

$layout = calculate_tree_layout($graph_nodes, $graph_edges);
$node_positions = $layout['positions'];
$total_nodes_count = count($graph_nodes);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Network Path Monitoring | PFMS-Toolkit</title>
    
    <!-- Vendor Fonts & Styles -->
    <link rel="stylesheet" href="../../vendor/fonts/fonts.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    
    <style>
        :root {
            --primary-navy: #0b1a26;
            --accent-green: #22c55e;
            --accent-orange: #f59e0b;
            --accent-red: #ef4444;
            --accent-gray: #94a3b8;
            --brand-green: #004d40;
            --bg-canvas: #ffffff;
            --bg-page: #f8fafc;
            --border-color: #e2e8f0;
            --text-dark: #1e293b;
            --text-muted: #64748b;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-dark);
            -webkit-font-smoothing: antialiased;
            overflow: hidden;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* --- TOP HEADER & CONTROLS --- */
        .rp-header {
            background: #ffffff;
            border-bottom: 1px solid var(--border-color);
            padding: 12px 24px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            z-index: 10;
        }

        .rp-title-area {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .rp-main-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--primary-navy);
            display: flex;
            align-items: center;
            gap: 6px;
            margin: 0;
        }

        .rp-hash-badge {
            font-family: 'Courier New', monospace;
            font-size: 12px;
            font-weight: 600;
            color: #334155;
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 4px;
            border: 1px solid #e2e8f0;
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .rp-controls-area {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rp-badge {
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .rp-badge.primary {
            background: #e6f4ea;
            color: #137333;
            border-color: #ceead6;
        }

        .rp-badge.circle-counter {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            line-height: 1.1;
        }
        .rp-badge.circle-counter span {
            font-size: 9px;
            font-weight: 500;
            color: var(--text-muted);
        }

        .rp-select {
            height: 32px;
            font-size: 12px;
            font-weight: 500;
            padding: 0 10px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-dark);
            outline: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .rp-select:hover, .rp-select:focus {
            border-color: var(--brand-green);
        }

        /* --- SUB HEADER INFO --- */
        .rp-subheader {
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
            padding: 8px 24px;
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .rp-sub-pills {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rp-pill {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 600;
            color: #334155;
            font-size: 11px;
        }

        /* --- MAIN WORKSPACE --- */
        .rp-workspace {
            display: flex;
            flex: 1;
            overflow: hidden;
            position: relative;
        }

        /* --- CANVAS AREA --- */
        .rp-canvas-wrapper {
            flex: 1;
            background: var(--bg-canvas);
            position: relative;
            overflow: hidden;
            cursor: grab;
            user-select: none;
        }
        .rp-canvas-wrapper:active { cursor: grabbing; }

        /* Canvas Floating Controls */
        .rp-canvas-tools {
            position: absolute;
            top: 20px;
            left: 20px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            z-index: 5;
        }

        .rp-tool-btn {
            width: 34px;
            height: 34px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s;
        }
        .rp-tool-btn:hover {
            background: #f1f5f9;
            color: var(--primary-navy);
            transform: scale(1.05);
        }
        .rp-tool-btn span { font-size: 18px; }

        /* SVG Graph Elements */
        @keyframes flowAnim {
            from { stroke-dashoffset: 24; }
            to { stroke-dashoffset: 0; }
        }

        .flow-path {
            stroke-dasharray: 6 6;
            animation: flowAnim 2.5s linear infinite;
            transition: stroke 0.3s;
        }

        .flow-path:hover {
            stroke-width: 4.5px !important;
            cursor: pointer;
        }

        .edge-label-box {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 10px;
            font-weight: 700;
            color: var(--primary-navy);
            text-align: center;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .edge-label-box:hover {
            border-color: var(--brand-green);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            transform: scale(1.05);
        }

        .graph-node {
            cursor: grab;
            transition: transform 0.05s ease-out;
        }
        .graph-node:hover circle.node-base {
            filter: drop-shadow(0 4px 10px rgba(0,0,0,0.15));
        }
        .graph-node.selected circle.node-base {
            stroke: #0284c7 !important;
            stroke-width: 4px !important;
        }

        /* --- RIGHT INSPECTOR SIDEBAR --- */
        .rp-sidebar {
            width: 320px;
            background: #ffffff;
            border-left: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            z-index: 5;
            box-shadow: -2px 0 10px rgba(0,0,0,0.02);
        }

        .rp-sidebar-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .rp-sidebar-title {
            font-size: 15px;
            font-weight: 800;
            color: var(--primary-navy);
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .rp-sidebar-sub {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 4px;
            word-break: break-all;
        }

        .rp-sidebar-body {
            padding: 20px 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            flex: 1;
            overflow-y: auto;
        }

        .rp-prop-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px dashed #f1f5f9;
            font-size: 12px;
        }

        .rp-prop-label {
            color: var(--text-muted);
            font-weight: 500;
        }

        .rp-prop-val {
            font-weight: 600;
            color: var(--text-dark);
            text-align: right;
            max-width: 170px;
            word-break: break-word;
        }

        .rp-status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 700;
            font-size: 12px;
        }
        .status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            display: inline-block;
        }
        .status-dot.ok { background-color: var(--accent-green); }
        .status-dot.warn { background-color: var(--accent-orange); }
        .status-dot.crit { background-color: var(--accent-red); }
        .status-dot.na { background-color: var(--accent-gray); }

        .rp-info-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px 14px;
            font-size: 11px;
            color: #64748b;
            line-height: 1.5;
            margin-top: 10px;
        }
    </style>
</head>
<body>

    <!-- 1. TOP HEADER TOOLBAR -->
    <div class="rp-header">
        <div class="rp-title-area">
            <h1 class="rp-main-title">
                <span class="material-symbols-outlined" style="color:var(--brand-green); font-size:22px;">route</span>
                Network Path
            </h1>
            <span style="color:#cbd5e1;">·</span>
            <div class="rp-hash-badge" title="<?= h($topology_hash) ?>"><?= h($topology_hash) ?></div>
        </div>

        <div class="rp-controls-area">
            <!-- Agent Selection Form -->
            <form method="GET" id="filterForm" style="display:flex; align-items:center; gap:8px; margin:0;">
                <input type="hidden" name="page" value="<?= h($_GET['page'] ?? '') ?>">
                <?php if ($is_demo): ?><input type="hidden" name="demo" value="1"><?php endif; ?>

                <?php if (!empty($available_agents)): ?>
                <div style="display:flex; align-items:center; gap:5px;">
                    <label style="font-size:11px; font-weight:600; color:var(--text-muted);">Agent:</label>
                    <select name="agent_id" class="rp-select" onchange="document.getElementById('filterForm').submit();">
                        <?php foreach ($available_agents as $ag): ?>
                            <option value="<?= (int)$ag['id_agente'] ?>" <?= ($selected_agent_id === (int)$ag['id_agente']) ? 'selected' : '' ?>>
                                <?= h($ag['alias'] ?: $ag['nombre']) ?> (ID: <?= (int)$ag['id_agente'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <div class="rp-badge">Agent ID: <?= (int)$selected_agent_id ?></div>
                <?php endif; ?>

                <!-- Range Selector -->
                <div style="display:flex; align-items:center; gap:5px;">
                    <label style="font-size:11px; font-weight:600; color:var(--text-muted);">Range:</label>
                    <select name="range" class="rp-select" onchange="document.getElementById('filterForm').submit();">
                        <option value="1h" <?= $time_range === '1h' ? 'selected' : '' ?>>Last 1 hour</option>
                        <option value="6h" <?= $time_range === '6h' ? 'selected' : '' ?>>Last 6 hours</option>
                        <option value="1d" <?= $time_range === '1d' ? 'selected' : '' ?>>Last 1 day</option>
                        <option value="7d" <?= $time_range === '7d' ? 'selected' : '' ?>>Last 7 days</option>
                        <option value="30d" <?= $time_range === '30d' ? 'selected' : '' ?>>Last 30 days</option>
                    </select>
                </div>

                <div class="rp-badge">Targets: <?= (int)$targets_count ?></div>
            </form>

            <div class="rp-badge circle-counter" title="Total Nodes in Path">
                <?= $total_nodes_count ?>
                <span>nodes</span>
            </div>
        </div>
    </div>

    <!-- 2. SUB-HEADER METRICS & TOPOLOGY PILLS -->
    <div class="rp-subheader">
        <div class="rp-sub-pills">
            <span class="rp-pill">Topology: route_parser</span>
            <span class="rp-pill">Auto refresh: <?= h($auto_refresh) ?></span>
        </div>
        <div>
            Threshold: <b style="color:#d97706;">warn <?= $warn_threshold ?>ms</b> · <b style="color:#dc2626;">crit <?= $crit_threshold ?>ms</b> · Min/Max Window: <b><?= h($range_label) ?></b> · Source IP: <b style="color:var(--primary-navy);"><?= h($source_ip) ?></b>
        </div>
    </div>

    <!-- 3. MAIN WORKSPACE -->
    <div class="rp-workspace">
        
        <!-- Canvas Area -->
        <div class="rp-canvas-wrapper" id="canvasWrapper">
            
            <!-- Zoom & View Tools -->
            <div class="rp-canvas-tools">
                <button class="rp-tool-btn" id="btnZoomIn" title="Zoom In"><span class="material-symbols-outlined">add</span></button>
                <button class="rp-tool-btn" id="btnZoomOut" title="Zoom Out"><span class="material-symbols-outlined">remove</span></button>
                <button class="rp-tool-btn" id="btnResetView" title="Reset View"><span class="material-symbols-outlined">restart_alt</span></button>
            </div>

            <!-- SVG Graph -->
            <svg id="mainSvg" style="width:100%; height:100%;">
                <g id="viewportGroup">
                    
                    <!-- Edges / Path connections -->
                    <g id="edgesLayer">
                        <?php foreach ($graph_edges as $edge): 
                            $p_from = $node_positions[$edge['from']] ?? null;
                            $p_to = $node_positions[$edge['to']] ?? null;
                            if (!$p_from || !$p_to) continue;

                            $mx = ($p_from['x'] + $p_to['x']) / 2;
                            $my = ($p_from['y'] + $p_to['y']) / 2;
                            
                            $edge_color = match ($edge['status']) {
                                'warn' => '#f59e0b',
                                'crit' => '#ef4444',
                                default => '#22c55e'
                            };
                        ?>
                            <!-- Curved Animated Path -->
                            <path id="path-<?= h($edge['from']) ?>-<?= h($edge['to']) ?>"
                                  class="flow-path"
                                  data-from="<?= h($edge['from']) ?>"
                                  data-to="<?= h($edge['to']) ?>"
                                  data-label="<?= h($edge['label']) ?>"
                                  data-ms="<?= h((string)$edge['ms']) ?>"
                                  data-status="<?= h($edge['status']) ?>"
                                  d="M <?= $p_from['x'] ?> <?= $p_from['y'] ?> C <?= $mx ?> <?= $p_from['y'] ?>, <?= $mx ?> <?= $p_to['y'] ?>, <?= $p_to['x'] ?> <?= $p_to['y'] ?>"
                                  stroke="<?= $edge_color ?>"
                                  stroke-width="3.5"
                                  fill="none"
                                  stroke-linecap="round" />

                            <!-- Edge Latency Badge -->
                            <foreignObject id="label-<?= h($edge['from']) ?>-<?= h($edge['to']) ?>"
                                           class="edge-label-container"
                                           data-from="<?= h($edge['from']) ?>"
                                           data-to="<?= h($edge['to']) ?>"
                                           x="<?= $mx - 38 ?>"
                                           y="<?= $my - 13 ?>"
                                           width="76"
                                           height="26">
                                <div class="edge-label-box" onclick="selectEdge('<?= h($edge['from']) ?>', '<?= h($edge['to']) ?>')">
                                    <?= h($edge['label']) ?>
                                </div>
                            </foreignObject>
                        <?php endforeach; ?>
                    </g>

                    <!-- Nodes Layer -->
                    <g id="nodesLayer">
                        <?php foreach ($graph_nodes as $key => $node): 
                            $pos = $node_positions[$key] ?? ['x' => 150, 'y' => 200];
                            
                            $node_color = match ($node['status']) {
                                'warn' => '#f59e0b',
                                'crit' => '#ef4444',
                                default => '#22c55e'
                            };

                            $node_type = $node['type']; // 'src', 'hop', 'target'
                        ?>
                            <g id="node-<?= h($key) ?>" 
                               class="graph-node" 
                               data-key="<?= h($key) ?>"
                               data-name="<?= h($node['name']) ?>"
                               data-ip="<?= h($node['ip']) ?>"
                               data-role="<?= h($node['role']) ?>"
                               data-status="<?= h($node['status']) ?>"
                               data-last="<?= h((string)$node['last_ms']) ?>"
                               data-min="<?= h((string)$node['min_ms']) ?>"
                               data-max="<?= h((string)$node['max_ms']) ?>"
                               transform="translate(<?= $pos['x'] ?>, <?= $pos['y'] ?>)">
                                
                                <!-- Outer Halo / Base Circle -->
                                <circle class="node-base" r="22" fill="<?= $node_color ?>" stroke="#ffffff" stroke-width="3.5" />

                                <!-- Inner Icon -->
                                <?php if ($node_type === 'src'): ?>
                                    <!-- Globe / Source Agent Icon -->
                                    <g transform="translate(-10, -10) scale(0.83)" stroke="#ffffff" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="9"/>
                                        <path d="M3.6 12h16.8M12 3.6c2.5 3 2.5 13.8 0 16.8M12 3.6c-2.5 3-2.5 13.8 0 16.8"/>
                                    </g>
                                <?php elseif ($node_type === 'target'): ?>
                                    <!-- Target / Bullseye Icon -->
                                    <g transform="translate(-10, -10) scale(0.83)" stroke="#ffffff" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="8"/>
                                        <circle cx="12" cy="12" r="3"/>
                                        <path d="M12 4V2M20 12h2M12 20v2M4 12H2"/>
                                    </g>
                                <?php else: ?>
                                    <!-- Router / Network Hop Icon -->
                                    <g transform="translate(-10, -10) scale(0.83)" stroke="#ffffff" stroke-width="2.2" fill="none" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="4" y="6" width="16" height="12" rx="3"/>
                                        <circle cx="8.5" cy="12" r="1" fill="#ffffff"/>
                                        <circle cx="12" cy="12" r="1" fill="#ffffff"/>
                                        <circle cx="15.5" cy="12" r="1" fill="#ffffff"/>
                                    </g>
                                <?php endif; ?>

                                <!-- Node Labels (Role & IP) -->
                                <text y="38" text-anchor="middle" style="font-size:10px; font-weight:800; fill:#334155; letter-spacing:0.5px; pointer-events:none;">
                                    <?= h($node['role']) ?>
                                </text>
                                <text y="52" text-anchor="middle" style="font-size:11px; font-weight:600; fill:#64748b; pointer-events:none;">
                                    <?= h($node['ip']) ?>
                                </text>
                            </g>
                        <?php endforeach; ?>
                    </g>

                </g>
            </svg>
        </div>

        <!-- 4. RIGHT DETAILS / INSPECTOR PANEL -->
        <div class="rp-sidebar" id="sidebarPanel">
            <div class="rp-sidebar-header">
                <h3 class="rp-sidebar-title" id="panelRoleTitle">HOP</h3>
                <div class="rp-sidebar-sub" id="panelIpSub"><?= h($source_ip) ?></div>
            </div>

            <div class="rp-sidebar-body">
                <div class="rp-prop-row">
                    <span class="rp-prop-label">Selection</span>
                    <span class="rp-prop-val" id="valSelection">Node</span>
                </div>

                <div class="rp-prop-row">
                    <span class="rp-prop-label">Status</span>
                    <span class="rp-prop-val">
                        <span class="rp-status-indicator">
                            <span class="status-dot ok" id="valStatusDot"></span>
                            <span id="valStatusText">OK</span>
                        </span>
                    </span>
                </div>

                <div class="rp-prop-row">
                    <span class="rp-prop-label">Module / Edge</span>
                    <span class="rp-prop-val" id="valModule">RouteStep_<?= h($source_ip) ?></span>
                </div>

                <div class="rp-prop-row">
                    <span class="rp-prop-label">Latency (last)</span>
                    <span class="rp-prop-val" id="valLastMs">0 ms</span>
                </div>

                <div class="rp-prop-row">
                    <span class="rp-prop-label">Latency (min)</span>
                    <span class="rp-prop-val" id="valMinMs">0 ms</span>
                </div>

                <div class="rp-prop-row">
                    <span class="rp-prop-label">Latency (max)</span>
                    <span class="rp-prop-val" id="valMaxMs">0.056 ms</span>
                </div>

                <div class="rp-prop-row">
                    <span class="rp-prop-label">Threshold</span>
                    <span class="rp-prop-val"><?= $warn_threshold ?>ms / <?= $crit_threshold ?>ms</span>
                </div>

                <div class="rp-info-card" id="valInfoText">
                    <span class="material-symbols-outlined" style="font-size:14px; vertical-align:middle; margin-right:4px;">info</span>
                    Klik edge untuk melihat detail koneksi. Double-click node untuk membuka modul di Pandora FMS.
                </div>
            </div>
        </div>

    </div>

    <!-- 5. JAVASCRIPT LOGIC (PAN, ZOOM, DRAG, SELECTION) -->
    <script>
        (function() {
            'use strict';

            const nodePositions = <?= json_encode($node_positions) ?>;
            const graphNodes = <?= json_encode($graph_nodes) ?>;
            const graphEdges = <?= json_encode($graph_edges) ?>;

            // DOM Elements
            const canvasWrapper = document.getElementById('canvasWrapper');
            const mainSvg = document.getElementById('mainSvg');
            const viewportGroup = document.getElementById('viewportGroup');

            // Sidebar Inspector Elements
            const panelRoleTitle = document.getElementById('panelRoleTitle');
            const panelIpSub = document.getElementById('panelIpSub');
            const valSelection = document.getElementById('valSelection');
            const valStatusDot = document.getElementById('valStatusDot');
            const valStatusText = document.getElementById('valStatusText');
            const valModule = document.getElementById('valModule');
            const valLastMs = document.getElementById('valLastMs');
            const valMinMs = document.getElementById('valMinMs');
            const valMaxMs = document.getElementById('valMaxMs');
            const valInfoText = document.getElementById('valInfoText');

            // Viewport Transform State
            let scale = 1.0;
            let pointX = 0, pointY = 0;
            let startPan = { x: 0, y: 0 };
            let isPanning = false;

            // Node Dragging State
            let activeDragNode = null;
            let dragOffset = { x: 0, y: 0 };

            function updateViewport() {
                viewportGroup.setAttribute('transform', `translate(${pointX}, ${pointY}) scale(${scale})`);
            }

            function clamp(val, min, max) {
                return Math.min(Math.max(val, min), max);
            }

            // --- ZOOM & PAN ---
            document.getElementById('btnZoomIn').addEventListener('click', () => {
                scale = clamp(scale + 0.15, 0.4, 3.0);
                updateViewport();
            });

            document.getElementById('btnZoomOut').addEventListener('click', () => {
                scale = clamp(scale - 0.15, 0.4, 3.0);
                updateViewport();
            });

            document.getElementById('btnResetView').addEventListener('click', () => {
                scale = 1.0;
                pointX = 0;
                pointY = 0;
                updateViewport();
            });

            canvasWrapper.addEventListener('wheel', (e) => {
                e.preventDefault();
                const delta = e.deltaY > 0 ? -0.08 : 0.08;
                const newScale = clamp(scale + delta, 0.4, 3.0);
                
                // Zoom towards mouse pointer
                const rect = canvasWrapper.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;

                pointX -= (mouseX - pointX) * (newScale / scale - 1);
                pointY -= (mouseY - pointY) * (newScale / scale - 1);
                scale = newScale;

                updateViewport();
            }, { passive: false });

            canvasWrapper.addEventListener('mousedown', (e) => {
                const nodeTarget = e.target.closest('.graph-node');
                if (nodeTarget) {
                    activeDragNode = nodeTarget;
                    const key = activeDragNode.dataset.key;
                    const pos = nodePositions[key];
                    
                    const rect = canvasWrapper.getBoundingClientRect();
                    const mouseX = (e.clientX - rect.left - pointX) / scale;
                    const mouseY = (e.clientY - rect.top - pointY) / scale;

                    dragOffset.x = mouseX - pos.x;
                    dragOffset.y = mouseY - pos.y;

                    selectNode(key);
                    return;
                }

                // If clicked on background, pan canvas
                isPanning = true;
                startPan = { x: e.clientX - pointX, y: e.clientY - pointY };
            });

            window.addEventListener('mousemove', (e) => {
                if (activeDragNode) {
                    const key = activeDragNode.dataset.key;
                    const rect = canvasWrapper.getBoundingClientRect();
                    const mouseX = (e.clientX - rect.left - pointX) / scale;
                    const mouseY = (e.clientY - rect.top - pointY) / scale;

                    const newX = mouseX - dragOffset.x;
                    const newY = mouseY - dragOffset.y;

                    nodePositions[key] = { x: newX, y: newY };
                    activeDragNode.setAttribute('transform', `translate(${newX}, ${newY})`);

                    updateConnectedEdges(key);
                    return;
                }

                if (isPanning) {
                    pointX = e.clientX - startPan.x;
                    pointY = e.clientY - startPan.y;
                    updateViewport();
                }
            });

            window.addEventListener('mouseup', () => {
                isPanning = false;
                activeDragNode = null;
            });

            // Update edge curves and label coordinates when dragging nodes
            function updateConnectedEdges(nodeKey) {
                const paths = document.querySelectorAll(`.flow-path[data-from="${nodeKey}"], .flow-path[data-to="${nodeKey}"]`);
                paths.forEach(p => {
                    const fromKey = p.dataset.from;
                    const toKey = p.dataset.to;
                    const pFrom = nodePositions[fromKey];
                    const pTo = nodePositions[toKey];
                    if (!pFrom || !pTo) return;

                    const mx = (pFrom.x + pTo.x) / 2;
                    const my = (pFrom.y + pTo.y) / 2;

                    p.setAttribute('d', `M ${pFrom.x} ${pFrom.y} C ${mx} ${pFrom.y}, ${mx} ${pTo.y}, ${pTo.x} ${pTo.y}`);

                    const labelEl = document.getElementById(`label-${fromKey}-${toKey}`);
                    if (labelEl) {
                        labelEl.setAttribute('x', mx - 38);
                        labelEl.setAttribute('y', my - 13);
                    }
                });
            }

            // --- NODE & EDGE SELECTION ---
            function clearSelection() {
                document.querySelectorAll('.graph-node.selected').forEach(n => n.classList.remove('selected'));
            }

            window.selectNode = function(key) {
                const n = graphNodes[key];
                if (!n) return;

                clearSelection();
                const nodeEl = document.getElementById(`node-${key}`);
                if (nodeEl) nodeEl.classList.add('selected');

                panelRoleTitle.textContent = n.role || 'HOP';
                panelIpSub.textContent = n.ip || '';
                valSelection.textContent = 'Node';
                
                const st = (n.status || 'ok').toLowerCase();
                valStatusDot.className = 'status-dot ' + st;
                valStatusText.textContent = st.toUpperCase();
                
                valModule.textContent = n.name || '-';
                valLastMs.textContent = parseFloat(n.last_ms || 0).toFixed(3) + ' ms';
                valMinMs.textContent = parseFloat(n.min_ms || 0).toFixed(3) + ' ms';
                valMaxMs.textContent = parseFloat(n.max_ms || 0).toFixed(3) + ' ms';

                valInfoText.innerHTML = '<span class="material-symbols-outlined" style="font-size:14px; vertical-align:middle; margin-right:4px;">info</span> Double-click node untuk membuka halaman modul di Pandora FMS.';
            };

            window.selectEdge = function(fromKey, toKey) {
                const edge = graphEdges.find(e => e.from === fromKey && e.to === toKey);
                const fromNode = graphNodes[fromKey];
                const toNode = graphNodes[toKey];
                if (!edge || !fromNode || !toNode) return;

                clearSelection();

                panelRoleTitle.textContent = 'EDGE / CONNECTION';
                panelIpSub.textContent = `${fromNode.ip} → ${toNode.ip}`;
                valSelection.textContent = 'Edge';

                const st = (edge.status || 'ok').toLowerCase();
                valStatusDot.className = 'status-dot ' + st;
                valStatusText.textContent = st.toUpperCase();

                valModule.textContent = `${fromNode.ip} → ${toNode.ip}`;
                valLastMs.textContent = parseFloat(edge.ms || 0).toFixed(3) + ' ms';
                valMinMs.textContent = parseFloat(toNode.min_ms || 0).toFixed(3) + ' ms';
                valMaxMs.textContent = parseFloat(toNode.max_ms || 0).toFixed(3) + ' ms';

                valInfoText.innerHTML = '<span class="material-symbols-outlined" style="font-size:14px; vertical-align:middle; margin-right:4px;">info</span> Latensi edge mengikuti respon hop tujuan (' + toNode.ip + ').';
            };

            // Auto select first node on initial load
            const firstKey = Object.keys(graphNodes)[0];
            if (firstKey) selectNode(firstKey);

            // Auto Refresh Timer
            const refreshMap = { '30s': 30, '1m': 60, '5m': 300 };
            const refreshSec = refreshMap['<?= h($auto_refresh) ?>'] || 0;
            if (refreshSec > 0) {
                setTimeout(() => {
                    location.reload();
                }, refreshSec * 1000);
            }

        })();
    </script>
</body>
</html>
