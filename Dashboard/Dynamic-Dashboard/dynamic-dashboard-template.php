<?php
/* dynamic-dashboard-template.php
 *
 * Universal Grafana-Style Dynamic Dashboard
 * - Version: 4.9.3 (STABLE: Fully Responsive Table View, Word-Wrap Fixes)
 * - Features: 12-Column Smart Grid, Drag & Drop Flow, Auto-Clone Panels, Real-Time HUD.
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ob_start();

// Catch any PHP fatal error/syntax/parse/execution issue and output clean JSON
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'FATAL PHP ERROR: ' . $error['message'] . ' in ' . basename($error['file']) . ' on line ' . $error['line']
        ]);
        exit;
    }
});

// Catch any uncaught PHP exceptions and output clean JSON
set_exception_handler(function($e) {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'error' => 'UNCAUGHT EXCEPTION: ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ' on line ' . $e->getLine()
    ]);
    exit;
});

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

// 1. DYNAMIC CONFIG & DB LOADING
set_time_limit(120); 

// Use centralized db-connection.php - Load this BEFORE session_start to respect Pandora session settings
require_once __DIR__ . '/../../includes/db-connection.php';

// 2. DYNAMIC PATH RESOLUTION
$panelDirName = $PANEL_DIR_NAME ?? 'custom';
$directScriptUrl = !empty($_SERVER['SCRIPT_NAME'])
    ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME'])
    : (($TOOLKIT_BASE_PATH ?? ($PANDORA_BASE_URL . '/custom/pfms-toolkit')) . '/Dashboard/Dynamic-Dashboard/dynamic-dashboard-template.php');
$CONFIG_FILE = __DIR__ . '/dynamic-dashboards-master.json';

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';
$user_id = $_SESSION['id_usuario'] ?? '';
$is_admin = !empty($user_id) && isset($pdo) && ($pdo instanceof PDO) && is_pandora_administrator($pdo, $user_id);
session_write_close();

// 3. HELPERS (Using global ones from db-connection.php)
// function h() and pretty_text() are handled globally.

// 4. AJAX ENDPOINTS
$api = $_GET['api'] ?? '';
if (!empty($api)) {
    if (!ob_start("ob_gzhandler")) ob_start();
}

if ($api === 'load_config') {
    if (ob_get_level() > 0) ob_clean(); header('Content-Type: application/json');
    if(file_exists($CONFIG_FILE)) { 
        $content = file_get_contents($CONFIG_FILE);
        $dashboards = json_decode($content, true);
        if (!$is_admin && is_array($dashboards)) {
            $dashboards = array_values(array_filter($dashboards, function($d) use ($user_id, $pdo) {
                return check_dashboard_access($d['access_control'] ?? null, (string)$user_id, 'view', $pdo);
            }));
        }
        echo json_encode($dashboards ?: []);
    } 
    else { echo json_encode([]); } 
    exit;
}

if ($api === 'save_config') {
    if (ob_get_level() > 0) ob_clean(); header('Content-Type: application/json');

    if (!$is_admin) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Restricted Access (View Only): Only accounts with the Pandora Administrator profile can modify dashboard configurations.']);
        exit;
    }

    // CSRF Validation
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf_token) || $client_token !== $csrf_token) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh portal.']);
        exit;
    }

    $input = file_get_contents('php://input');
    $bytes = @file_put_contents($CONFIG_FILE, $input);
    if ($bytes === false) {
        $err = error_get_last();
        $errMsg = $err['message'] ?? 'Permission Denied / Unknown Error';
        echo json_encode(['ok' => false, 'error' => "Failed writing to file ($CONFIG_FILE). Reason: $errMsg", 'file' => basename($CONFIG_FILE)]);
    } else {
        echo json_encode(['ok' => true, 'file' => basename($CONFIG_FILE)]); 
    }
    exit;
}

if ($api === 'save_dashboard_acl') {
    if (ob_get_level() > 0) ob_clean(); 
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = trim((string)($input['dashboard_id'] ?? $input['id'] ?? ''));
    $acl = $input['access_control'] ?? null;
    $res = save_dashboard_acl_to_file($CONFIG_FILE, $id, $acl, $is_admin, $csrf_token);
    echo json_encode($res);
    exit;
}

if (!function_exists('get_hierarchical_groups')) {
    function get_hierarchical_groups(PDO $pdo, string $prefix_id = 'primary', string $node_label = ''): array {
        try {
            $stmt = $pdo->query("SELECT id_grupo AS id, nombre AS name, parent FROM tgrupo ORDER BY nombre ASC");
            $all = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            if (empty($all)) return [];

            $by_parent = [];
            foreach ($all as $g) {
                $p = (int)($g['parent'] ?? 0);
                $by_parent[$p][] = $g;
            }

            foreach ($by_parent as $p => &$children) {
                usort($children, function($a, $b) {
                    return strcasecmp($a['name'], $b['name']);
                });
            }
            unset($children);

            $result = [];
            $traverse = function(int $parent_id, int $depth, string $path_accum) use (&$traverse, &$result, &$by_parent, $prefix_id, $node_label) {
                if (!isset($by_parent[$parent_id])) return;

                foreach ($by_parent[$parent_id] as $g) {
                    $gid = (int)$g['id'];
                    $clean_name = pretty_text($g['name']);
                    $current_path = $path_accum ? ($path_accum . ' / ' . $clean_name) : $clean_name;

                    $indent = '';
                    if ($depth > 0) {
                        $indent = str_repeat("    ", $depth - 1) . "└─ ";
                    }

                    $displayName = $indent . $clean_name;
                    if (!empty($node_label)) {
                        $displayName = '[' . $node_label . '] ' . $displayName;
                    }

                    $result[] = [
                        'id' => $prefix_id . ':' . $gid,
                        'name' => $displayName,
                        'path' => $current_path,
                        'depth' => $depth,
                        'raw_id' => $gid
                    ];

                    $traverse($gid, $depth + 1, $current_path);
                }
            };

            $traverse(0, 0, '');

            $seen_ids = array_column($result, 'raw_id');
            foreach ($all as $g) {
                $gid = (int)$g['id'];
                if (!in_array($gid, $seen_ids, true)) {
                    $clean_name = pretty_text($g['name']);
                    $displayName = $clean_name;
                    if (!empty($node_label)) $displayName = '[' . $node_label . '] ' . $displayName;
                    $result[] = [
                        'id' => $prefix_id . ':' . $gid,
                        'name' => $displayName,
                        'path' => $clean_name,
                        'depth' => 0,
                        'raw_id' => $gid
                    ];
                }
            }

            return $result;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if ($api === 'groups') {
    if (ob_get_level() > 0) ob_clean(); header('Content-Type: application/json');
    if (!$db_status) { echo json_encode(['error' => 'DB Connection Error: ' . $db_error]); exit; }
    $dropdown = [['id' => '0', 'name' => '-- All Groups --']];
    
    // 1. Primary DB groups (Hierarchical Tree)
    $primary_groups = get_hierarchical_groups($pdo, 'primary', '');
    foreach ($primary_groups as $pg) {
        $dropdown[] = ['id' => $pg['id'], 'name' => $pg['name'], 'path' => $pg['path']];
    }
    
    // 2. Custom DB groups (Hierarchical Tree for remote nodes)
    global $custom_pdos, $custom_connections;
    if (!empty($custom_pdos)) {
        foreach ($custom_pdos as $cid => $cpdo) {
            $cname = '';
            foreach ($custom_connections as $cc) {
                if ($cc['id'] === $cid) { $cname = $cc['name']; break; }
            }
            if (empty($cname)) $cname = $cid;
            $custom_groups = get_hierarchical_groups($cpdo, $cid, $cname);
            foreach ($custom_groups as $cg) {
                $dropdown[] = ['id' => $cg['id'], 'name' => $cg['name'], 'path' => $cg['path']];
            }
        }
    }
    
    echo json_encode($dropdown); exit;
}

if ($api === 'module_list') {
    if (ob_get_level() > 0) ob_clean(); header('Content-Type: application/json');
    if (!$db_status) { echo json_encode(['error' => 'DB Connection Error: ' . $db_error]); exit; }
    
    $modules = [];
    
    // 1. Primary DB modules
    try {
        $stmt = $pdo->query("SELECT DISTINCT nombre FROM tagente_modulo WHERE disabled = 0 ORDER BY nombre ASC");
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $raw = $row['nombre'];
            $modules[$raw] = true;
        }
    } catch (Throwable $e) {}
    
    // 2. Custom DB modules
    global $custom_pdos;
    if (!empty($custom_pdos)) {
        foreach ($custom_pdos as $cid => $cpdo) {
            try {
                $stmt = $cpdo->query("SELECT DISTINCT nombre FROM tagente_modulo WHERE disabled = 0 ORDER BY nombre ASC");
                while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $raw = $row['nombre'];
                    $modules[$raw] = true;
                }
            } catch (Throwable $e) {}
        }
    }
    
    $list = [];
    ksort($modules);
    foreach ($modules as $raw => $_) {
        $list[] = ['raw' => $raw, 'pretty' => pretty_text($raw)];
    }
    
    echo json_encode($list); exit;
}

if ($api === 'template_nodes') {
    if (ob_get_level() > 0) ob_clean(); header('Content-Type: application/json');
    if (!$db_status) { echo json_encode(['error' => 'DB Connection Error: ' . $db_error]); exit; }
    $groupIdRaw = $_GET['group_id'] ?? '0';
    $groupParsed = parse_node_id($groupIdRaw);
    
    global $custom_pdos, $custom_connections;
    $target_nodes = [];
    if ($groupParsed['id'] > 0) {
        $target_nodes[$groupParsed['node']] = [
            'pdo' => ($groupParsed['node'] === 'primary') ? $pdo : ($custom_pdos[$groupParsed['node']] ?? null),
            'group_id' => $groupParsed['id']
        ];
    } else {
        $target_nodes['primary'] = ['pdo' => $pdo, 'group_id' => 0];
        if (!empty($custom_pdos)) {
            foreach ($custom_pdos as $cid => $cpdo) {
                $target_nodes[$cid] = ['pdo' => $cpdo, 'group_id' => 0];
            }
        }
    }
    
    $list = [];
    foreach ($target_nodes as $node => $info) {
        $active_pdo = $info['pdo'];
        if ($active_pdo === null) continue;
        
        $node_label = '';
        if ($node !== 'primary') {
            foreach ($custom_connections as $cc) {
                if ($cc['id'] === $node) { $node_label = '[' . $cc['name'] . '] '; break; }
            }
            if (empty($node_label)) $node_label = '[' . $node . '] ';
        }
        
        try {
            if ($info['group_id'] === 0) {
                $stmt = $active_pdo->query("SELECT id_agente AS id, alias FROM tagente WHERE disabled = 0 ORDER BY alias ASC");
                while ($a = $stmt->fetch()) {
                    $list[] = [
                        'id' => $node . ':' . $a['id'],
                        'alias' => $node_label . pretty_text($a['alias'])
                    ];
                }
            } else {
                $targetGroups = get_all_child_groups($active_pdo, $info['group_id']);
                $inStr = implode(',', array_fill(0, count($targetGroups), '?'));
                
                $hasSecGroup = false;
                try { $active_pdo->query("SELECT 1 FROM tagente_secondary_group LIMIT 1"); $hasSecGroup = true; } catch(Exception $e) {}

                if ($hasSecGroup) {
                    $sql = "SELECT DISTINCT a.id_agente AS id, a.alias 
                            FROM tagente a 
                            LEFT JOIN tagente_secondary_group sg ON a.id_agente = sg.id_agente
                            WHERE a.disabled = 0 
                            AND (a.id_grupo IN ($inStr) OR sg.id_grupo IN ($inStr)) 
                            ORDER BY a.alias ASC";
                    $stmt = $active_pdo->prepare($sql);
                    $stmt->execute(array_merge($targetGroups, $targetGroups));
                } else {
                    $stmt = $active_pdo->prepare("SELECT id_agente AS id, alias FROM tagente WHERE disabled = 0 AND id_grupo IN ($inStr) ORDER BY alias ASC");
                    $stmt->execute($targetGroups);
                }
                while ($a = $stmt->fetch()) {
                    $list[] = [
                        'id' => $node . ':' . $a['id'],
                        'alias' => $node_label . pretty_text($a['alias'])
                    ];
                }
            }
        } catch (Throwable $e) {}
    }
    
    echo json_encode($list); exit;
}

if ($api === 'detail_graph') {
    if (ob_get_level() > 0) ob_clean(); header('Content-Type: application/json');
    if (!$db_status) { echo json_encode(['ok' => false, 'error' => 'DB Connection Error: ' . $db_error]); exit; }
    $id_mod_raw = $_GET['id_mod'];
    $range = $_GET['range'] ?? '21600';

    if ($range === 'custom') {
        $start = (int)$_GET['start'];
        $end = (int)$_GET['end'];
    } else {
        $end = time();
        $start = $end - (int)$range;
    }

    try {
        $parsed = parse_node_id($id_mod_raw);
        global $custom_pdos;
        $active_pdo = ($parsed['node'] === 'primary') ? $pdo : ($custom_pdos[$parsed['node']] ?? null);
        $active_history_pdo = ($parsed['node'] === 'primary') ? $history_pdo : $active_pdo;

        if ($active_pdo === null) {
            echo json_encode(['ok' => false, 'error' => "Node '{$parsed['node']}' not found or connection failed"]);
            exit;
        }

        // Fetch module unit
        $stmtUnit = $active_pdo->prepare("SELECT COALESCE(unit, '') as unit FROM tagente_modulo WHERE id_agente_modulo = ?");
        $stmtUnit->execute([$parsed['id']]);
        $unitRow = $stmtUnit->fetch();
        $unit = $unitRow ? pretty_text($unitRow['unit']) : '';

        $raw_data = get_module_history_data($active_pdo, $active_history_pdo, $parsed['id'], $start, $end, 5000, 'ASC');
        $data = [];
        foreach ($raw_data as $row) {
            $data[] = [
                'waktu' => date('Y-m-d H:i', $row['ts']),
                'datos' => $row['datos']
            ];
        }
        
        // Include diagnostic info for debugging time range issues
        $debug = [
            'requested_start' => date('Y-m-d H:i:s', $start),
            'requested_end' => date('Y-m-d H:i:s', $end),
            'range_days' => round(($end - $start) / 86400, 1),
            'history_db_connected' => ($active_history_pdo !== null),
            'returned_points' => count($data),
        ];
        if (count($data) > 0) {
            $debug['actual_first_point'] = $data[0]['waktu'];
            $debug['actual_last_point'] = $data[count($data) - 1]['waktu'];
        }
        
        echo json_encode(['ok' => true, 'data' => $data, 'unit' => $unit, 'debug' => $debug]);
    } catch (Throwable $e) { 
        if (ob_get_level() > 0) ob_clean(); 
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()]); 
    }
    exit;
}

if ($api === 'bulk_panel_data') {
    if (ob_get_level() > 0) ob_clean(); header('Content-Type: application/json');
    if (!$db_status) { echo json_encode(['ok' => false, 'error' => 'DB Connection Error: ' . $db_error]); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    
    $agent_id_raw = $input['agent_id'] ?? '0';
    $start = (int)$input['start'] ?? (time() - 86400);
    $end = (int)$input['end'] ?? time();
    $panels = $input['panels'] ?? [];

    if ($agent_id_raw === '0' || empty($panels)) { echo json_encode(['ok' => false, 'error' => 'Missing param']); exit; }

    try {
        $parsed = parse_node_id($agent_id_raw);
        global $custom_pdos;
        $active_pdo = ($parsed['node'] === 'primary') ? $pdo : ($custom_pdos[$parsed['node']] ?? null);
        $active_history_pdo = ($parsed['node'] === 'primary') ? $history_pdo : $active_pdo;

        if ($active_pdo === null) {
            echo json_encode(['ok' => false, 'error' => "Node '{$parsed['node']}' not found or connection failed"]);
            exit;
        }

        // Helper to normalize strings
        if (!function_exists('normalize_mod_name')) {
            function normalize_mod_name($s) {
                $s = pretty_text($s);
                $s = preg_replace('/\s+/u', ' ', $s);
                $s = str_replace(chr(194).chr(160), ' ', $s);
                $s = preg_replace('/[^\x20-\x7E]/', '', $s);
                return strtolower(trim($s));
            }
        }

        // Fetch ALL modules for this agent
        $stAll = $active_pdo->prepare("SELECT m.id_agente_modulo, m.nombre, m.min, m.max, m.unit, e.datos as current_val, COALESCE(e.estado, 4) as estado, e.utimestamp as last_contact,
                                       a.alias as agent_name, a.direccion as ip_address, g.nombre as group_name, a.id_agente, a.nombre AS agent_db_name,
                                       m.min_warning, m.max_warning, m.min_critical, m.max_critical
                                FROM tagente_modulo m 
                                JOIN tagente a ON m.id_agente = a.id_agente
                                JOIN tgrupo g ON a.id_grupo = g.id_grupo
                                LEFT JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo 
                                WHERE m.id_agente = ? AND m.disabled = 0");
        $stAll->execute([$parsed['id']]);
        $allModules = $stAll->fetchAll();

        $results = [];

        foreach ($panels as $p) {
            $pId = $p['id'];
            $kw = $p['keyword'];
            $mType = $p['match_type'] ?? 'contains';
            $pType = $p['type'] ?? 'text'; 
            
            $keywords = array_map('trim', explode(',', $kw));
            $modulesFound = [];

            foreach ($keywords as $sub_kw) {
                $trimmed = trim($sub_kw);
                if ($trimmed === '') continue;
                $is_wildcard = ($trimmed === '%' || $trimmed === '*');
                $sub_kw_norm = normalize_mod_name($trimmed);
                
                foreach ($allModules as $mod) {
                    $mod_norm = normalize_mod_name($mod['nombre']);
                    if ($is_wildcard) {
                        $modulesFound[] = $mod;
                    } else if ($mType === 'exact') {
                        if ($mod_norm === $sub_kw_norm) {
                            $modulesFound[] = $mod;
                        }
                    } else {
                        // Contains/Regex logic
                        $clean_nombre = pretty_text($mod['nombre']);
                        $fuzzy_regex = '/' . str_replace(' ', '.*', preg_quote($trimmed, '/')) . '/i';
                        if (preg_match($fuzzy_regex, $clean_nombre) || $mod_norm === $sub_kw_norm) {
                            $modulesFound[] = $mod;
                        }
                    }
                }
            }
            // Ensure unique modules and sort deterministically
            $temp = [];
            foreach($modulesFound as $m) { $temp[$m['id_agente_modulo']] = $m; }
            $modulesFound = array_values($temp);
            usort($modulesFound, function($a, $b) {
                $cmp = strnatcasecmp($a['agent_name'] ?? '', $b['agent_name'] ?? '');
                if ($cmp !== 0) return $cmp;
                return strnatcasecmp($a['nombre'] ?? '', $b['nombre'] ?? '');
            });

            if (count($modulesFound) === 0) {
                $results[$pId] = ['found' => false, 'modules' => []];
                continue;
            }

            $moduleResults = [];
            foreach($modulesFound as $mod) {
                $mod_id = $mod['id_agente_modulo'];
                if (in_array($pType, ['line', 'area', 'bar', 'heatmap', 'history_table', 'single_value', 'text', 'gauge', 'sparkline_table'])) {
                    $raw_hist = get_module_history_data($active_pdo, $active_history_pdo, $mod_id, $start, $end, 2000, 'DESC');
                    $history = [];
                    foreach ($raw_hist as $row) {
                        $history[] = [
                            'ts' => (int)$row['ts'],
                            'lbl' => date('m-d H:i', $row['ts']),
                            'val' => $row['datos']
                        ];
                    }
                    $history = array_reverse($history);
                }

                $cur_val = $mod['current_val'];
                if ($cur_val !== null && is_numeric($cur_val)) {
                    $u_trim = trim($mod['unit'] ?? '');
                    $n_trim = strtolower(trim($mod['nombre'] ?? ''));
                    $is_pct = ($u_trim === '%' || strpos($u_trim, '%') !== false || strpos($n_trim, ' in %') !== false || strpos($n_trim, ' %') !== false);
                    if ($is_pct && (float)$cur_val > 100.0) {
                        $cur_val = ($mod['max'] > 0) ? min(100.0, (float)$mod['max']) : 100.0;
                    } elseif ((float)$mod['max'] > 0 && (float)$cur_val > (float)$mod['max']) {
                        $cur_val = (float)$mod['max'];
                    }
                }

                $moduleResults[] = [
                    'id' => $parsed['node'] . ':' . $mod_id,
                    'module_name' => pretty_text($mod['nombre']),
                    'current' => $cur_val !== null ? $cur_val : 'N/A',
                    'status' => (int)$mod['estado'],
                    'last_contact' => (int)$mod['last_contact'],
                    'unit' => pretty_text($mod['unit']),
                    'min' => $mod['min'],
                    'max' => $mod['max'],
                    'min_warning' => $mod['min_warning'] ?? null,
                    'max_warning' => $mod['max_warning'] ?? null,
                    'min_critical' => $mod['min_critical'] ?? null,
                    'max_critical' => $mod['max_critical'] ?? null,
                    'agent_name' => $mod['agent_name'],
                    'agent_db_name' => $mod['agent_db_name'],
                    'agent_id' => $parsed['node'] . ':' . $mod['id_agente'],
                    'ip_address' => $mod['ip_address'],
                    'group_name' => $mod['group_name'],
                    'history' => $history
                ];
            }
            $results[$pId] = [ 'found' => true, 'modules' => $moduleResults ];
        }
        echo json_encode(['ok' => true, 'data' => $results]);
    } catch (Throwable $e) { 
        if (ob_get_level() > 0) ob_clean(); 
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()]); 
    }
    exit;
}

$isStandalone = (isset($_GET['standalone']) && $_GET['standalone'] == '1') || (isset($_GET['s']) && $_GET['s'] == '1');
$isModalOnly = (isset($_GET['modal_only']) && $_GET['modal_only'] == '1') || (isset($_GET['view_modal']) && $_GET['view_modal'] == '1');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Universal Dynamic Dashboard</title>
    <link rel="icon" href="<?= h($PANDORA_BASE_URL) ?>/images/pandora.ico" type="image/x-icon">
    <link href="<?= h($vendor_url) ?>/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= h($vendor_url) ?>/fonts/fonts.css" />
    <link href="<?= h($vendor_url) ?>/bootstrap/bootstrap.min.css" rel="stylesheet">
    <script src="<?= h($vendor_url) ?>/echarts/echarts.min.js"></script>
    <script src="<?= h($vendor_url) ?>/html2canvas/html2canvas.min.js"></script>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #334155; font-size: 14px; -webkit-font-smoothing: antialiased; } * { box-sizing: border-box; }
        body { background-color: #f4f6f8; margin: 0; padding: 0; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-size: 18px !important; vertical-align: middle; line-height: 1; }

        /* V4.8 STANDALONE UI OVERRIDES */
        <?php if ($isModalOnly): ?>
        .pandora-header-top, .pandora-header-bottom, .toolbar-right, .drag-handle, #view_list, #view_detail, #listTopControls, .grafana-toolbar, .main-content { display: none !important; }
        html, body { height: 100% !important; width: 100% !important; background-color: #f4f6f8 !important; padding: 0 !important; margin: 0 !important; overflow: hidden !important; }
        .modal-overlay#statusDetailModal { position: fixed !important; inset: 0 !important; background: #f4f6f8 !important; display: flex !important; align-items: stretch !important; justify-content: center !important; z-index: 99999 !important; padding: 12px !important; box-sizing: border-box !important; }
        .modal-overlay#statusDetailModal .modal-box { width: 100% !important; max-width: 100% !important; height: 100% !important; max-height: 100% !important; border-radius: 8px !important; box-shadow: 0 1px 4px rgba(0,0,0,0.08) !important; border: 1px solid #cbd5e1 !important; background: #ffffff !important; }
        <?php elseif ($isStandalone): ?>
        .pandora-header-top, .pandora-header-bottom, .toolbar-right, .drag-handle, #view_list, #listTopControls { display: none !important; }
        .grafana-toolbar { border-top: 1px solid #dce1e5; margin-top:0 !important;}
        .main-content { padding: 20px 25px !important; width: 100% !important; max-width: 100% !important; margin: 0 !important; }
        button[onclick="closeDashboard()"], button[onclick="closeDashboard()"] + .toolbar-divider { display: none !important; }
        <?php endif; ?>

        .pandora-header-top { background-color: #ffffff; border-bottom: 1px solid #e0e4e8; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 25px; z-index: 10; }
        .header-logo { height: 24px; width: auto; }
        .header-divider { width: 1px; height: 28px; background-color: #dce1e5; margin: 0 20px; }
        .header-title-box { display: flex; flex-direction: column; line-height: 1.2; margin-right: 40px; }
        .header-title-box .main-title { font-size: 14px !important; font-weight: normal !important; color: #0b1a26 !important; }
        .nav-icon-btn { color: #4a5568 !important; text-decoration: none; display: flex; align-items: center; justify-content: center; height: 36px; width: 36px; border-radius: 50%; transition: 0.2s; border:none; background:transparent; cursor:pointer;}

        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; }
        .breadcrumb-box { display: flex; flex-direction: column; }
        .page-breadcrumb { font-size: 11px !important; color: #64748b !important; margin-bottom: 4px; font-weight: normal !important; text-transform: uppercase; letter-spacing: 0.5px; }
        .page-title { font-size: 18px !important; color: #0b1a26 !important; margin: 0; font-weight: 600 !important; line-height: 1.1; display:flex; align-items:center; gap:8px;}
        .breadcrumb-link { cursor: pointer; color: #1976d2 !important; text-decoration: none; transition:0.2s;}
        .breadcrumb-link:hover { text-decoration: underline; color:#0d47a1!important; }

        .agent-link { color: #1976d2 !important; font-weight: 600 !important; text-decoration: none; cursor: pointer; transition: 0.2s; }
        .agent-link:hover { text-decoration: underline !important; color: #0d47a1 !important; }
        .module-link { color: #0b1a26 !important; font-weight: 500 !important; text-decoration: none; cursor: pointer; transition: 0.2s; }
        .module-link:hover { text-decoration: underline !important; color: #1976d2 !important; }

        .list-table-wrap { background: #fff; border: 1px solid #e0e4e8; border-radius: 6px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.02);}
        table.list-table { border-collapse: collapse !important; width: 100% !important; margin: 0 !important; }
        table.list-table thead th { background-color: #fafbfc !important; border-bottom: 1px solid #e0e4e8 !important; text-transform: uppercase; padding: 15px 20px !important; font-weight: normal !important; color: #7f8c8d !important; font-size: 11px !important; }
        table.list-table tbody td { padding: 15px 20px !important; border-bottom: 1px solid #f0f3f5; color: #0b1a26 !important; vertical-align: middle; transition:0.2s;}
        table.list-table tbody tr:hover td { background-color: #f8f9fa !important; }
        
        .dash-name-link { font-weight: normal !important; font-size: 14px !important; color: #1976d2 !important; text-decoration: none; display: flex; align-items: center; gap:8px; cursor:pointer;}
        .dash-name-link:hover { text-decoration: underline; color: #0d47a1 !important; }
        .dash-badge { background: #e0f2f1; color: #004d40; padding: 2px 8px; border-radius: 10px; font-size: 10px !important; font-weight: normal; }

        div.top-controls { display: flex; flex-direction: row; flex-wrap: nowrap; gap: 10px; align-items: center; justify-content: flex-end; }
        .list-search-box { padding: 0 15px 0 35px !important; height: 36px !important; margin: 0 !important; box-sizing: border-box !important; width: 300px; border: 1px solid #dce1e5; border-radius: 4px; font-size: 13px !important; font-weight: normal !important; outline: none; background: #fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%237f8c8d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>') no-repeat 10px center; transition:0.2s; }
        .list-search-box:focus { border-color:#004d40; box-shadow: 0 0 0 2px rgba(0,77,64,0.1); }
        .btn-apply { height: 36px !important; margin: 0 !important; box-sizing: border-box !important; background: #004d40; color: #fff !important; border: none; padding: 0 18px; border-radius: 4px; font-weight: normal !important; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 5px; transition:0.2s; white-space:nowrap;}
        .btn-apply:hover { background: #00332a; }
        .btn-secondary-custom { height: 36px !important; margin: 0 !important; box-sizing: border-box !important; background: #fff; color: #4a5568 !important; border: 1px solid #dce1e5; padding: 0 18px; border-radius: 4px; font-weight: normal !important; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 5px; transition:0.2s; white-space:nowrap;}
        .btn-secondary-custom:hover { background: #f4f6f8; color: #0b1a26 !important; }
        .btn-unsaved { background-color: #e67e22 !important; }
        .btn-unsaved:hover { background-color: #d35400 !important; }

        .grafana-toolbar { position: sticky; top: 0; z-index: 1000; display: flex; align-items: center; justify-content: space-between; background: #ffffff; padding: 12px 30px; border-bottom: 1px solid #dce1e5; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); flex-wrap: wrap; gap: 15px;}
        .toolbar-left { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .toolbar-right { display: flex; align-items: center; gap: 10px; }
        .toolbar-item { display: flex; align-items: center; gap: 8px; }
        .toolbar-divider { width: 1px; height: 24px; background: #dce1e5; margin: 0 5px; }
        .toolbar-label { font-size: 11px !important; font-weight: normal !important; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px;}
        .toolbar-select { height: 32px; padding: 0 25px 0 10px; border: 1px solid #dce1e5; border-radius: 4px; font-size: 13px !important; font-weight: normal !important; color: #004d40; background: #f8f9fa; outline: none; transition: 0.2s; cursor: pointer; max-width: 250px;}
        .toolbar-select:hover { border-color: #b5c1c9; }
        .toolbar-select:focus { border-color: #004d40; box-shadow: 0 0 0 2px rgba(0,77,64,0.1); background: #ffffff; }
        .toolbar-input { height: 32px; padding: 0 8px; border: 1px solid #dce1e5; border-radius: 4px; font-size: 12px !important; font-weight: normal !important; color: #333; background: #fff; outline: none;}

        .refresh-hud { display: flex; align-items: center; gap: 10px; background: #ffffff; padding: 4px 10px; border-radius: 6px; border: 1px solid #dce1e5; box-shadow: 0 1px 2px rgba(0,0,0,0.02);}
        .refresh-hud-texts { display: flex; flex-direction: column; text-align: right; min-width: 100px; line-height: 1.3;}
        .text-last-update { font-size: 11px !important; color: #4a5568; font-weight: normal; letter-spacing: 0.2px;}
        .text-countdown { font-size: 11px !important; color: #e67e22; font-weight: normal; }
        .btn-refresh { background: #e8f5e9; color: #004d40; border: 1px solid #c8e6c9; height: 28px; width: 28px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s;}
        .btn-refresh:hover { background: #c8e6c9; color: #00332a; }
        .refresh-select { height: 28px; padding: 0 20px 0 8px; border: 1px solid #dce1e5; border-radius: 4px; font-size: 11px !important; font-weight: normal !important; color: #004d40; background: #fff; outline: none; cursor: pointer; transition: 0.2s;}
        .refresh-select:hover { border-color: #b5c1c9; }

        .dropdown-wrapper { position: relative; display: flex; align-items: center; }
        .custom-dropdown { position: absolute; top: 34px; left: 0; background: #fff; border: 1px solid #004d40; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 3000; overflow: hidden; display: flex; flex-direction: column; min-width: 250px; max-width: 400px; }
        .custom-dropdown-list { list-style: none; margin: 0; padding: 0; max-height: 250px; overflow-y: auto; }
        .custom-dropdown-list li { padding: 8px 12px; border-bottom: 1px solid #f0f3f5; cursor: pointer; font-size: 12px; color: #333; transition: background 0.1s; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;}
        .custom-dropdown-list li:hover { background: #e0f2f1; color: #004d40; font-weight: normal; }
        .custom-dropdown-list li.selected { background: #004d40; color: #fff; font-weight: normal; }
        .custom-dropdown-footer { padding: 8px 12px; background: #fafbfc; border-top: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; }
        .pg-btn { background: #fff; border: 1px solid #b5c1c9; padding: 2px 8px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: normal; color: #4a5568;}
        .pg-btn:hover:not(:disabled) { background: #e0e4e8; }
        .pg-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .btn-icon-only { background: transparent; color:#7f8c8d; border:none; height:32px; width:32px; border-radius:4px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.2s;}
        .btn-icon-only:hover { background: #e0e4e8; color: #0b1a26; }
        
        .main-content { padding: 20px 25px; max-width: 1800px; margin: 0 auto; overflow-x: hidden; }
        
        #panelsGrid { 
            display: grid; 
            grid-template-columns: repeat(12, 1fr); 
            grid-auto-rows: 5px; 
            row-gap: 15px; 
            column-gap: 15px; 
            align-items: start; 
            align-content: start;
            width: 100%;
        } 
        
        .panel-rule-wrapper { 
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: opacity 0.2s, transform 0.2s; 
            background: transparent;
            min-width: 100px;
        }
        .panel-rule-wrapper .panel-card { flex: 1; margin-bottom: 0; }
        
        .panel-rule-wrapper.dragging { opacity: 0.4; transform: scale(0.98); }
        .panel-rule-wrapper.drag-over { border: 2px dashed #004d40; border-radius: 8px; padding: 4px; }
        
        @media (max-width: 1400px) {
            #panelsGrid { grid-template-columns: repeat(12, 1fr); row-gap: 12px; column-gap: 12px; }
        }
        @media (max-width: 1200px) {
            #panelsGrid { grid-template-columns: repeat(8, 1fr); row-gap: 10px; column-gap: 10px; }
            .panel-rule-wrapper { grid-column: span var(--span-tablet, 4); }
        }
        @media (max-width: 768px) {
            #panelsGrid { grid-template-columns: repeat(4, 1fr); row-gap: 8px; column-gap: 8px; }
            .panel-rule-wrapper { grid-column: span var(--span-mobile, 4); }
        }
        @media (max-width: 480px) {
            #panelsGrid { grid-template-columns: 1fr; }
            .panel-rule-wrapper { grid-column: span 1; }
        }
        
        .drag-handle { cursor: grab; color: #b5c1c9; margin-right: 8px; font-size: 18px !important; transition:0.2s;}
        .drag-handle:hover { color: #004d40; }
        .drag-handle:active { cursor: grabbing; color: #004d40; }

        .panel-card { background-color: #ffffff; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,0.05); border: 1px solid #e0e4e8; display:flex; flex-direction:column; position: relative; height: auto; width: 100%; min-width: 0; overflow: hidden; margin: 0; }
        .panel-header { padding: 6px 6px 6px 10px; border-bottom: 1px solid #f0f3f5; display: flex; justify-content: space-between; align-items: flex-start; background: #fafbfc; min-height: 34px; gap: 4px; }
        .panel-title { font-size: 10px !important; font-weight: 600 !important; color: #4a5568 !important; margin: 0; text-transform: uppercase; letter-spacing: 0.5px; white-space: normal; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; line-height: 1.2; flex: 1; min-width: 0; }
        .panel-body { padding: 12px; flex-grow:1; display: flex; flex-direction: column; align-items: stretch; justify-content: flex-start; position: relative; min-width: 0; }
        
        .panel-controls { display: flex; gap: 1px; opacity: 0.2; transition: 0.2s; flex-shrink: 0; margin-top: -1px; margin-left: auto; }
        .panel-card:hover .panel-controls { opacity: 1; }
        .icon-btn { background: none; border: none; padding: 0; width: 22px; height: 22px; cursor: pointer; color: #b5c1c9; display: flex; align-items: center; justify-content: center; border-radius: 4px; }
        .icon-btn:hover { color: #0b1a26; background: rgba(0,0,0,0.03); }
        .icon-btn .material-symbols-outlined { font-size: 15px !important; }

        .val-big { font-size: 32px; font-weight: 700; color: #0b1a26; line-height: 1.1; white-space: normal; word-break: break-word; text-align: center; display: inline-block; width: auto; }
        .val-unit { font-size: 14px !important; font-weight: 500 !important; color: #64748b; margin-left: 3px;}
        .mod-subtitle { font-size: 11px !important; color: #64748b; font-weight: 500 !important; margin-top: 8px; text-align: center; line-height:1.3; width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 6px; flex-shrink: 0;}
        .chart-wrapper { width: 100%; position: relative; margin-top: 10px; display: flex; justify-content: center; align-items: center; min-width: 0;}
        .gauge-text { position: absolute; top: 75%; left: 50%; transform: translate(-50%, -50%); display: flex; flex-direction: column; align-items: center; width: 90%; }
        .gauge-val { font-size: 24px !important; font-weight: 700 !important; color: #0b1a26; line-height: 1; }
        .gauge-minmax { font-size: 10px !important; color: #95a5a6; margin-top: 4px; font-weight: normal;}
        .heatmap-wrap { width: 100%; display: flex; flex-wrap: wrap; gap: 3px; align-content: flex-start; height: auto; min-height: 60px; margin-top: 10px; background: #f8f9fa; border: 1px solid #e0e4e8; border-radius: 4px; padding: 6px; overflow-y: auto; }
        .heat-block { width: 16px; height: 16px; border-radius: 3px; transition: 0.2s; border: 1px solid rgba(0,0,0,0.08); }
        .heat-block:hover { transform: scale(1.4); filter: brightness(1.1); z-index: 10; box-shadow: 0 3px 8px rgba(0,0,0,0.25); }
        .legend-chip { display: inline-flex; align-items: center; gap: 5px; cursor: pointer; user-select: none; transition: opacity 0.2s ease, background 0.15s ease; border-radius: 4px; padding: 1px 4px; margin: 1px 0; }
        .legend-chip:hover { background: rgba(241, 245, 249, 0.7); }
        .legend-color-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease; }
        .legend-color-dot:hover { transform: scale(1.4); box-shadow: 0 0 0 2px #0284c7; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 2000; backdrop-filter: blur(2px); padding: 20px; }
        .modal-box { background: #fff; width: 550px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.15); border: 1px solid #e0e4e8; display: flex; flex-direction: column; max-height: 90vh; overflow: hidden; }
        .modal-header-custom { padding: 18px 25px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; background: #fafbfc; }
        .modal-body-scroll { padding: 25px; overflow-y: auto; flex: 1; }
        .modal-footer-custom { padding: 15px 25px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; background: #fafbfc; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 11px !important; text-transform: uppercase; font-weight: normal !important; color: #4a5568; margin-bottom: 6px; }
        .form-control-fix { width: 100%; height: 38px; padding: 8px 12px; border: 1px solid #dce1e5; border-radius: 4px; font-weight: normal !important; background-color: #fff; outline: none; margin-bottom: 10px;}
        .form-control-fix:focus { border-color:#004d40; box-shadow: 0 0 0 2px rgba(0,77,64,0.1); }
        .loading-overlay { position: absolute; inset: 0; background: rgba(255,255,255,0.8); display: flex; align-items: center; justify-content: center; z-index: 5; border-radius: 6px;}
        .spinner { width: 30px; height: 30px; border: 3px solid #f3f3f3; border-top: 3px solid #004d40; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes pf-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .radio-btn-group { display: flex; gap: 15px; margin-bottom: 10px; }
        .radio-btn-group label { display: flex; align-items: center; gap: 5px; font-size: 13px !important; text-transform: none; color: #0b1a26; cursor: pointer; font-weight: normal !important;}
        .radio-btn-group input { width: 16px; height: 16px; margin: 0; cursor: pointer;}
        .d-none { display: none !important; }

        /* CUSTOM TABLE IN DETAIL MODAL & PANELS */
        .table-pfms { 
            width: 100%; 
            max-width: 100%; 
            border-collapse: collapse; 
            font-size: 12px; 
            background: #fff; 
            table-layout: auto; 
        }
        .table-pfms th { 
            background: #f8fafc; 
            color: #475569; 
            font-weight: 600; 
            text-align: left; 
            padding: 8px 10px; 
            border-bottom: 1px solid #e2e8f0; 
            white-space: normal; 
            font-size: inherit;
        }
        .table-pfms td { 
            padding: 8px 10px; 
            border-bottom: 1px solid #f1f5f9; 
            vertical-align: middle; 
            overflow-wrap: break-word; 
            font-size: inherit;
            font-weight: inherit;
        }
        .table-pfms tr:hover td { background: #f8fafc; }
        .sparkline-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .sparkline-table th { padding: 9px 12px; font-weight: 600; font-size: 11px; text-transform: capitalize; color: #475569; border-bottom: 1px solid #e2e8f0; background: #f8fafc; cursor: pointer; user-select: none; white-space: nowrap; }
        .sparkline-table th:hover { color: #0284c7; background: #f1f5f9; }
        .sparkline-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .sparkline-table tr:hover td { background: #f8fafc; }

        .table-wrap-dyn { 
            width: 100%; 
            max-width: 100%; 
            overflow-x: auto; 
            margin-top: 5px; 
            border: 1px solid #e2e8f0; 
            border-radius: 6px; 
        }
        .table-wrap-dyn::-webkit-scrollbar { height: 6px; }
        .table-wrap-dyn::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

        .mini-stats-row { display: flex; gap: 12px; width: 100%; flex-wrap: wrap; margin-bottom: 5px;}
        .mini-stat { flex: 1; min-width: 100px; text-align: center; padding: 10px 5px; border-radius: 8px; background: #ffffff; border: 1px solid #e0e4e8; border-top: 4px solid #ccc; box-shadow: 0 2px 5px rgba(0,0,0,0.03); transition: 0.2s; cursor: pointer; position: relative; overflow: hidden; }
        .mini-stat:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.06); }
        .mini-stat:active { transform: translateY(0); }
        .mini-stat-val { font-size: 22px !important; font-weight: 600 !important; line-height: 1.1; margin-bottom: 2px; position: relative; z-index: 1;}
        .mini-stat-label { font-size: 9px !important; text-transform: uppercase; color: #64748b; font-weight: 600 !important; letter-spacing: 0.5px; position: relative; z-index: 1;}
        .st-black { border-top-color: #0b1a26; color: #0b1a26; }
        .st-green { border-top-color: #2ecc71; color: #2ecc71; background: rgba(46, 204, 113, 0.05); }
        .st-red { border-top-color: #e74c3c; color: #e74c3c; background: rgba(231, 76, 60, 0.05); }
        .st-yellow { border-top-color: #f1c40f; color: #f1c40f; background: rgba(241, 196, 15, 0.05); }
        .st-gray { border-top-color: #94a3b8; color: #475569; background: rgba(148, 163, 184, 0.05); }
        .st-blue { border-top-color: #3498db; color: #3498db; background: rgba(52, 152, 219, 0.05); }

        .table-dyn { width: 100%; border-collapse: collapse; font-size: 12px; table-layout: auto; }
        .table-dyn th { background: #fafbfc; border-bottom: 1px solid #e0e4e8; padding: 6px 10px; text-align: left; color: #7f8c8d; font-weight: normal; text-transform: uppercase; font-size: 9px; white-space: normal; }
        .table-dyn td { border-bottom: 1px solid #f0f3f5; padding: 8px 10px; color: #0b1a26; vertical-align: middle; overflow-wrap: break-word; }
        
        .status-pill-dyn { padding: 2px 8px; border-radius: 4px; font-size: 9px; text-transform: uppercase; color: #fff; font-weight: 500; display: inline-block; min-width: 50px; text-align: center; }
        .bg-green { background: linear-gradient(135deg, #2ecc71, #27ae60) !important; color: #fff !important; } 
        .bg-red { background: linear-gradient(135deg, #e74c3c, #c0392b) !important; color: #fff !important; } 
        .bg-yellow { background: linear-gradient(135deg, #f1c40f, #f39c12) !important; color: #fff !important; } 
        .bg-blue { background: linear-gradient(135deg, #3498db, #2980b9) !important; color: #fff !important; } 
        .bg-gray { background: linear-gradient(135deg, #95a5a6, #7f8c8d) !important; color: #fff !important; }

        .heatmap-grid-dyn { display: flex; flex-wrap: wrap; gap: 4px; width: 100%; margin-top: 5px; }
        .heat-box-dyn { width: 40px; height: 25px; border-radius: 3px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 8px; font-weight: 500; text-transform: uppercase; cursor: pointer; transition: 0.2s; border: none; }
        .heat-box-dyn:hover { opacity: 0.8; transform: scale(1.05); }

        .panel-card.is-hidden { opacity: 0.5; border: 2px dashed #94a3b8; }
        .panel-card.is-hidden::after { content: 'HIDDEN'; position: absolute; top: 10px; right: 50px; background: #64748b; color: #fff; font-size: 8px; padding: 2px 6px; border-radius: 4px; font-weight: 600; z-index: 10; pointer-events: none;}
        .btn-toggle-hidden { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; height: 32px; padding: 0 12px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: 0.2s;}
        .btn-toggle-hidden:hover { background: #e2e8f0; color: #334155; }
        .btn-toggle-hidden.active { background: #004d40; color: #fff; border-color: #004d40; }

        .selected-tags-container { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 8px; min-height: 0; }
        .module-tag { background: #e0f2f1; color: #004d40; border: 1px solid #b2dfdb; padding: 2px 8px; border-radius: 4px; font-size: 11px; display: flex; align-items: center; gap: 5px; font-weight: 500; }
        .module-tag .remove-tag { cursor: pointer; color: #00796b; font-weight: bold; font-size: 14px; line-height: 1; }
        .module-tag .remove-tag:hover { color: #e74c3c; }
        .btn-action { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 0; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; color: #64748b; cursor: pointer; transition: all 0.2s; text-decoration: none; margin-left: 5px; box-sizing: border-box; }
        .btn-action:hover { background: #f1f5f9; border-color: #cbd5e1; color: #0f172a; }
        .btn-action .material-symbols-outlined { font-size: 16px !important; }
        .btn-action.btn-delete { color: #ef4444; border-color: #fee2e2; }
        .btn-action.btn-delete:hover { background: #fef2f2; border-color: #fca5a5; color: #dc2626; }

        .btn-pfms { padding: 6px 14px; border-radius: 4px; font-size: 12px; font-weight: 500; cursor: pointer; transition: 0.2s; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 6px; }
        .btn-outline-pfms { background: #fff; border-color: #dce1e5; color: #4a5568; }
        .btn-outline-pfms:hover { border-color: #cbd5e1; background: #f8fafc; }

        /* NATIVE CHART IFRAME MODAL FIX */
        .iframe-modal-box {
            background: #ffffff;
            width: 850px;
            max-width: 95%;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }
        .iframe-header {
            padding: 15px 25px;
            background: #fafbfc;
            border-bottom: 1px solid #e0e4e8;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .iframe-title {
            font-size: 15px !important;
            font-weight: 600 !important;
            color: #0b1a26;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        @media print {
            @page {
                size: landscape;
                margin: 10mm;
            }
            body, html {
                background: #f4f6f8 !important;
                color: #000 !important;
                margin: 0 !important;
                padding: 0 !important;
                height: auto !important;
                overflow: visible !important;
                width: 100% !important;
            }
            .pandora-header-top,
            .pandora-header-bottom,
            .grafana-toolbar,
            .panel-controls,
            .drag-handle,
            .modal-overlay,
            #view_list,
            #listTopControls,
            .refresh-hud,
            .btn-icon-only,
            .toolbar-divider {
                display: none !important;
            }
            .main-content {
                padding: 10px !important;
                margin: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            #panelsGrid {
                grid-template-columns: repeat(12, 1fr) !important;
                row-gap: 15px !important;
                column-gap: 15px !important;
                grid-auto-rows: auto !important;
            }
            .panel-rule-wrapper {
                grid-row-end: auto !important;
            }
            .panel-card {
                box-shadow: none !important;
                border: 1px solid #e0e4e8 !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                height: auto !important;
            }
        }

        /* Visual Type Preview & Gallery Styles */
        .vtp-tab-btn {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #475569;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .vtp-tab-btn:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .vtp-tab-btn.active {
            background: #004d40;
            border-color: #004d40;
            color: #ffffff;
            box-shadow: 0 1px 3px rgba(0, 77, 64, 0.25);
        }
        .vtp-gallery-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
            cursor: pointer;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.15s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .vtp-gallery-card:hover {
            transform: translateY(-2px);
            border-color: #004d40;
            box-shadow: 0 6px 16px rgba(0,0,0,0.06) !important;
        }
        .vtp-gallery-card.is-active {
            border: 2px solid #004d40 !important;
            background: #f0fdf4 !important;
        }
    </style>
</head>
<body class="<?= $isStandalone ? 'is-standalone-view' : '' ?>">

<div class="pandora-header-top">
    <div class="header-left">
        <img src="<?= h($PANDORA_BASE_URL) ?>/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box"><span class="main-title">Pandora FMS</span><span class="sub-title">PFMS-Toolkit</span></div>
    </div>
    <div class="header-right"><a href="<?= h($PANDORA_BASE_URL) ?>/index.php" class="nav-icon-btn"><span class="material-symbols-outlined">home</span></a></div>
</div>

<div class="pandora-header-bottom">
    <div class="breadcrumb-box">
        <span class="page-breadcrumb" id="mainBreadcrumb"><?= h($dynamic_breadcrumb) ?></span>
        <h1 class="page-title" id="pageMainTitle">Dynamic Dashboard</h1>
    </div>
    
    <div class="top-controls" id="listTopControls">
        <input type="text" id="listSearch" class="list-search-box" placeholder="Search dashboards..." onkeyup="renderDashboardList()">
        <button class="btn-apply" onclick="openDashMetaModal()"><span class="material-symbols-outlined" style="font-size:18px!important;">add</span> Create Dashboard</button>
        <input type="file" id="importBackupFile" style="display:none" onchange="importDashboardConfig(event)">
    </div>
</div>

<div id="view_list" class="main-content">
    <div class="list-table-wrap">
        <table class="list-table" id="dashListTable">
            <thead>
                <tr>
                    <th style="width: 40%;">Dashboard Name</th>
                    <th>Target Group</th>
                    <th>Total Panels</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<div id="view_detail" class="d-none">
    <div class="grafana-toolbar">
        <div class="toolbar-left">
            <button class="btn-icon-only" onclick="closeDashboard()" title="Back to List"><span class="material-symbols-outlined">arrow_back</span></button>
            <div class="toolbar-divider"></div>
            
            <div class="toolbar-item">
                <span class="toolbar-label">Group</span>
                <select id="top_group" class="toolbar-select" onchange="onGroupChange()"><option value="0">Loading...</option></select>
            </div>
            
            <div class="toolbar-item" id="wrap_top_agent">
                <span class="toolbar-label">Node</span>
                <div class="dropdown-wrapper">
                    <input type="text" id="agent_search_input" class="toolbar-select" placeholder="-- Select Node --" onkeyup="renderAgentList()" onfocus="this.select(); showAgentDropdown()" autocomplete="off">
                    <input type="hidden" id="top_agent" value="0">
                    <div id="agent_dropdown" class="custom-dropdown" style="display:none;">
                        <ul id="agent_ul" class="custom-dropdown-list"></ul>
                    </div>
                </div>
            </div>
            
            <div class="toolbar-divider"></div>
            
            <div class="toolbar-item">
                <span class="toolbar-label"><span class="material-symbols-outlined" style="font-size:16px!important; color:#004d40;">schedule</span></span>
                <select id="top_time" class="toolbar-select" style="width:110px;" onchange="onTimeRangeChange()">
                    <option value="3600">Last 1h</option>
                    <option value="21600">Last 6h</option>
                    <option value="86400" selected>Last 24h</option>
                    <option value="604800">Last 7d</option>
                    <option value="custom">Custom...</option>
                </select>
            </div>
            
            <div id="customTimeBox" class="toolbar-item" style="display:none; background:#f0f3f5; padding:3px 8px; border-radius:4px; align-items:center; gap:6px;">
                <input type="datetime-local" id="top_start" class="toolbar-input">
                <span style="font-weight: normal; color:#7f8c8d;">-</span>
                <input type="datetime-local" id="top_end" class="toolbar-input">
                <button onclick="applyCustomTimeRange()" style="padding: 2px 10px; font-size:11px; height:24px; border:none; background:#1976d2; color:white; border-radius:3px; cursor:pointer; font-weight:600; display:inline-flex; align-items:center; justify-content:center;" title="Apply custom time range">Apply</button>
            </div>

            <div class="toolbar-divider"></div>

            <div class="refresh-hud">
                <div class="refresh-hud-texts">
                    <span id="last_update_text" class="text-last-update">Last Update: -</span>
                    <span id="countdown_text" class="text-countdown">Auto Refresh: Off</span>
                </div>
                <button class="btn-refresh" onclick="forceRefresh()" title="Force Refresh Data"><span class="material-symbols-outlined" style="font-size:18px!important;">sync</span></button>
                <select id="top_refresh" class="refresh-select" onchange="applyAutoRefresh()">
                    <option value="0">Off</option>
                    <option value="10">10s</option>
                    <option value="30">30s</option>
                    <option value="60">1m</option>
                    <option value="120">2m</option>
                    <option value="300">5m</option>
                </select>
            </div>
            
            <div class="toolbar-divider"></div>
            <div class="toolbar-item" id="export_controls">
                <span class="toolbar-label">Export</span>
                <button class="btn-secondary-custom" style="height:32px; padding:0 8px; margin-left:5px;" onclick="exportToPdf()" title="Export page to PDF / Print">
                    <span class="material-symbols-outlined" style="font-size:16px!important; color:#004d40;">picture_as_pdf</span>
                    <span style="font-size: 11px; font-weight: 500;">PDF</span>
                </button>
                <button class="btn-secondary-custom" style="height:32px; padding:0 8px; margin-left:5px;" onclick="exportToPng()" title="Export page to Image (PNG)">
                    <span class="material-symbols-outlined" style="font-size:16px!important; color:#004d40;">image</span>
                    <span style="font-size: 11px; font-weight: 500;">PNG</span>
                </button>
            </div>
        </div>

        <div class="toolbar-right">
            <button id="btnToggleHidden" class="btn-toggle-hidden" onclick="toggleHiddenVisibility()" title="Show/Hide panels marked as Hidden">
                <span class="material-symbols-outlined" style="font-size:18px!important;">visibility_off</span>
                <span>Hidden</span>
            </button>
            <button class="btn-secondary-custom" onclick="copyDashboardShareLink()"><span class="material-symbols-outlined" style="font-size:18px!important;">share</span> Share</button>
            <?php if ($is_admin): ?>
            <button class="btn-secondary-custom" onclick="openDashboardAclModal(currentDashId)" title="Access Permissions (Profiles & Users)" style="color:#0d9488; font-weight:600;"><span class="material-symbols-outlined" style="font-size:18px!important; color:#0d9488;">shield_person</span> Access</button>
            <?php endif; ?>
            <button class="btn-secondary-custom" onclick="duplicateDashboard()"><span class="material-symbols-outlined" style="font-size:18px!important;">content_copy</span> Duplicate</button>
            <button class="btn-secondary-custom" onclick="openDashMetaModal(true)"><span class="material-symbols-outlined" style="font-size:18px!important;">settings</span> Edit Info</button>
            <button class="btn-secondary-custom" onclick="openPanelBuilder()"><span class="material-symbols-outlined" style="font-size:18px!important;">add</span> Add Panel</button>
            <button class="btn-apply" id="btnSaveDashboard" onclick="saveCurrentDashboard()"><span class="material-symbols-outlined" style="font-size:18px!important;">save</span> Save Dashboard</button>
        </div>
    </div>
    
    <div class="main-content">
        <div id="panelsGrid"></div> 
    </div>
</div>

<div class="modal-overlay" id="dashMetaModal">
    <div class="modal-box">
        <div class="modal-header-custom">
            <h5 style="font-weight: 600!important; margin:0;" id="dashMetaTitle">Create Dashboard</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeDashMetaModal()">close</span>
        </div>
        <div class="modal-body-scroll">
            <div class="form-group">
                <label>Dashboard Title</label>
                <input type="text" id="m_dash_title" class="form-control-fix" placeholder="e.g. Linux Servers Dashboard">
            </div>
            <div class="form-group">
                <label>Default Startup Group</label>
                <select id="m_default_group" class="form-control-fix"></select>
                <small style="color:#7f8c8d; font-size:11px;">Group automatically selected when this dashboard is opened.</small>
            </div>
        </div>
        <div class="modal-footer-custom">
            <button class="btn-secondary-custom" onclick="closeDashMetaModal()">Cancel</button>
            <button class="btn-apply" onclick="saveDashboardMeta()">Apply Changes</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="panelModal">
    <div class="modal-box">
        <div class="modal-header-custom">
            <h5 style="font-weight: 600!important; margin:0;" id="panelModalTitle">Build Panel</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closePanelModal()">close</span>
        </div>
        
        <div class="modal-body-scroll">
            <div id="chart_engine_box" class="form-group" style="display:none;">
                <label>Chart Engine</label>
                <select id="p_chart_engine" class="form-control-fix">
                    <option value="custom">Custom (Modern & Fast)</option>
                    <option value="native">Native (Pandora FMS Original)</option>
                </select>
                <small style="color:#7f8c8d; font-size:10px;">* Native engine shows the full detailed graph from Pandora console.</small>
            </div>

            <div class="form-group">
                <label>Panel Title</label>
                <input type="text" id="p_title" class="form-control-fix" placeholder="e.g. Network Traffic (Out)">
            </div>
            
            <div class="form-group">
                <label>Target Module Selection</label>
                <div class="radio-btn-group">
                    <label><input type="radio" name="p_match_type" value="exact" checked onchange="togglePanelLayoutOptions()"> Exact Match (List)</label>
                    <label><input type="radio" name="p_match_type" value="contains" onchange="togglePanelLayoutOptions()"> Keyword Match (Contains)</label>
                </div>
                
                <div id="wrap_exact" style="display:block;">
                    <div id="exact_selected_tags" class="selected-tags-container"></div>
                    <div class="dropdown-wrapper">
                        <input type="text" id="exact_search_input" class="form-control-fix" placeholder="Click to search modules..." onkeyup="renderExactModuleList(1)" onfocus="this.select(); showExactDropdown()" autocomplete="off" style="margin-bottom:0;">
                        <input type="hidden" id="p_keyword_exact" value="">
                        
                        <div id="exact_dropdown" class="custom-dropdown" style="display:none;">
                            <ul id="exact_module_ul" class="custom-dropdown-list"></ul>
                            <div class="custom-dropdown-footer" id="exact_module_pagination"></div>
                        </div>
                    </div>
                </div>
                
                <div id="wrap_contains" style="display:none;">
                    <input type="text" id="p_keyword_contains" class="form-control-fix" placeholder="e.g. ifHCOutOctets" style="margin-bottom:0;">
                </div>
                <div style="margin-top:8px; display:flex; align-items:center; gap:10px; background:#e0f2f1; padding:8px 12px; border-radius:6px; border:1px solid #b2dfdb;">
                    <label style="display:flex; align-items:center; cursor:pointer; font-size:11px; font-weight:700; color:#004d40; margin:0;">
                        <input type="checkbox" id="p_multi_overlay" style="margin-right:8px; width:16px; height:16px;" onchange="toggleTypeFields(); renderSeriesColorsEditor();"> Overlay Multiple Modules in One Chart
                    </label>
                </div>
                <small style="color:#7f8c8d; font-size:10px; display:block; margin-top:4px;">* Tip: Use commas for different modules (e.g. <b>CPU Load, Memory Usage</b>)</small>
            </div>
            
            <div style="display:flex; gap:10px;">
                <div class="form-group" style="flex:1;">
                    <label>Visual Type</label>
                    <select id="p_type" class="form-control-fix" onchange="toggleTypeFields(); toggleChartEngine(); updateVisualTypePreview();">
                        <option value="text">Value Number / Text</option>
                        <option value="gauge">Gauge Chart</option>
                        <option value="single_value">Single Value Card (Sparkline)</option>
                        <option value="line" selected>Line Chart</option>
                        <option value="area">Area Chart</option>
                        <option value="bar">Bar Chart</option>
                        <option value="heatmap">History Heatmap Blocks</option>
                        <option value="history_table">History Table View</option>
                        <option value="sparkline_table">Sparkline Table (Trend Over Time)</option>
                        <option value="status_table">Table View (Current Status)</option>
                        <option value="status_heatmap">Heatmap View (Current Status)</option>
                        <option value="status_stats">Stats Cards (Current Status)</option>
                        <option value="pie">Pie Chart (Current Status)</option>
                        <option value="donut">Donut Chart (Current Status)</option>
                        <option value="table_viewer">View Snapshot Module</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Width (1-12)</label>
                    <select id="p_width" class="form-control-fix">
                        <option value="1">Span 1</option>
                        <option value="2">Span 2</option>
                        <option value="3">Span 3</option>
                        <option value="4">Span 4</option>
                        <option value="5">Span 5</option>
                        <option value="6">Span 6</option>
                        <option value="7">Span 7</option>
                        <option value="8">Span 8</option>
                        <option value="9">Span 9</option>
                        <option value="10">Span 10</option>
                        <option value="11">Span 11</option>
                        <option value="12" selected>Span 12</option>
                    </select>
                </div>
            </div>

            <!-- Interactive Visual Type Preview Card -->
            <div id="visual_type_preview_container" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px; margin-bottom:12px; transition:all 0.2s ease;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                    <div style="display:flex; align-items:center; gap:6px;">
                        <span class="material-symbols-outlined" style="font-size:16px; color:#004d40;">visibility</span>
                        <span style="font-size:11px; font-weight:700; color:#0f172a; text-transform:uppercase; letter-spacing:0.5px;">Visual Type Preview</span>
                        <span id="vtp_badge" style="font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; background:#e0f2fe; color:#0369a1;">Time-Series History</span>
                    </div>
                    <button type="button" onclick="openVisualGalleryModal()" style="background:#ffffff; border:1px solid #cbd5e1; border-radius:4px; padding:3px 8px; font-size:11px; font-weight:600; color:#334155; cursor:pointer; display:flex; align-items:center; gap:4px; box-shadow:0 1px 2px rgba(0,0,0,0.03);" title="Browse all visual type previews">
                        <span class="material-symbols-outlined" style="font-size:14px; color:#004d40;">dashboard_customize</span> All Types Gallery
                    </button>
                </div>
                <div id="vtp_mockup_area" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:6px; padding:10px; display:flex; align-items:center; justify-content:center; min-height:105px; box-shadow:inset 0 1px 2px rgba(0,0,0,0.02);">
                    <!-- Dynamic mockup SVG rendered here -->
                </div>
                <div id="vtp_description" style="margin-top:8px; font-size:11px; color:#64748b; line-height:1.45;">
                    <!-- Description & recommendation -->
                </div>
            </div>

            <div style="display:flex; gap:10px;">
                <div class="form-group" style="flex:1;">
                    <label>Panel Height (px)</label>
                    <input type="number" id="p_height" class="form-control-fix" placeholder="e.g. 200" value="200" min="100" max="1000">
                </div>
                <div class="form-group" style="flex:1;" id="wrap_box_size">
                    <label>Heatmap Box Size</label>
                    <select id="p_box_size" class="form-control-fix" onchange="toggleHeatmapCustomSizeDyn()">
                        <option value="rect_sm">Compact Bar (110px × 28px)</option>
                        <option value="rect_md">Standard Bar (160px × 34px)</option>
                        <option value="rect_lg">Large Bar (220px × 44px)</option>
                        <option value="rect_xl">Extra Wide Bar (280px × 54px)</option>
                        <option value="small">Small Square (36px × 24px)</option>
                        <option value="medium" selected>Medium Square (52px × 32px)</option>
                        <option value="large">Large Square (80px × 50px)</option>
                        <option value="xl">Extra Large Square (120px × 72px)</option>
                        <option value="custom">Custom Dimensions (px)</option>
                    </select>
                </div>
                <div class="form-group" style="flex:1;" id="wrap_row_limit">
                    <label>Row Limit (Table)</label>
                    <input type="number" id="p_row_limit" class="form-control-fix" value="200" min="1" max="1000">
                </div>
                <div class="form-group" style="flex:1;" id="wrap_chart_font">
                    <label>Chart Font Size</label>
                    <input type="number" id="p_chart_font_size" class="form-control-fix" value="10" min="6" max="32">
                </div>
            </div>

            <div id="wrap_heatmap_options" style="display:none; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; margin-top:5px; margin-bottom:10px;">
                <div style="font-weight:600; color:#0f172a; margin-bottom:8px; font-size:11px; display:flex; align-items:center; gap:6px;">
                    <span class="material-symbols-outlined" style="font-size:16px; color:#0284c7;">grid_view</span>
                    Heatmap Label & Custom Dimensions
                </div>
                <div style="display:flex; gap:10px; margin-bottom:8px;">
                    <div style="flex:1;">
                        <label style="font-size:11px; margin-bottom:4px; display:block;">Box Label / Text Display (Teks Kotak)</label>
                        <select id="p_heatmap_text_type" class="form-control-fix" onchange="toggleHeatmapCustomTextDyn()" style="margin-bottom:0;">
                            <option value="agent">Agent Name / Alias</option>
                            <option value="module">Module Name</option>
                            <option value="agent_module">Agent Name - Module Name</option>
                            <option value="ip">IP Address</option>
                            <option value="value" selected>Metric Value</option>
                            <option value="agent_value">Agent Name (Value)</option>
                            <option value="custom">Custom Text / Template</option>
                        </select>
                    </div>
                </div>
                <div id="wrap_heatmap_custom_size_dyn" style="display:none; gap:10px; margin-bottom:8px;">
                    <div style="flex:1;">
                        <label style="font-size:11px; margin-bottom:4px; display:block;">Custom Width (px / min-width)</label>
                        <input type="number" id="p_heatmap_custom_width" class="form-control-fix" placeholder="e.g. 160" min="20" max="600" style="margin-bottom:0;">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:11px; margin-bottom:4px; display:block;">Custom Height (px)</label>
                        <input type="number" id="p_heatmap_custom_height" class="form-control-fix" placeholder="e.g. 34" min="16" max="300" style="margin-bottom:0;">
                    </div>
                </div>
                <div id="wrap_heatmap_custom_text_dyn" style="display:none; margin-bottom:0;">
                    <label style="font-size:11px; margin-bottom:4px; display:block;">Custom Label Text / Template</label>
                    <input type="text" id="p_heatmap_custom_text" class="form-control-fix" placeholder="e.g. {agent} or {agent}: {value} or Host Alive" style="margin-bottom:2px;">
                    <small style="color:#64748b; font-size:10px; display:block;">* Tag variabel: <code>{agent}</code>, <code>{module}</code>, <code>{ip}</code>, <code>{value}</code>, <code>{unit}</code>, atau teks statis biasa.</small>
                </div>
            </div>

            <div class="form-group" id="wrap_sparkline_options" style="display:none; padding:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; margin-top:5px; margin-bottom:10px;">
                <div style="font-weight:600; color:#0f172a; margin-bottom:8px; font-size:11px; display:flex; align-items:center; gap:6px;">
                    <span class="material-symbols-outlined" style="font-size:15px; color:#10b981;">show_chart</span>
                    Sparkline Table Configuration
                </div>
                <label style="display:flex; align-items:center; cursor:pointer; font-size:11px; font-weight:600; color:#004d40; margin-bottom:0;">
                    <input type="checkbox" id="p_hide_search_bar" style="margin-right:8px; width:16px; height:16px;">
                    Hide Search Bar & Total Items (Sembunyikan Bilah Pencarian & Total Item)
                </label>
            </div>

            <div class="form-group" id="wrap_value_calc" style="margin-top:5px; margin-bottom:10px;">
                <label style="color:#004d40; font-size:11px; font-weight:600!important; display:block; margin-bottom:4px;">Value Calculation (Avg / Max / Min)</label>
                <select id="p_value_calc" class="form-control-fix" style="margin-bottom:0;">
                    <option value="current" selected>Current / Latest Value (Default)</option>
                    <option value="avg">Average (Avg / Rata-rata)</option>
                    <option value="max">Maximum (Max / Nilai Tertinggi)</option>
                    <option value="min">Minimum (Min / Nilai Terendah)</option>
                    <option value="summary">Current with Min / Avg / Max Badges</option>
                </select>
                <small style="color:#7f8c8d; font-size:10px; display:block; margin-top:3px;">* Calculates aggregate from history data over the active time range (1h, 24h, etc.).</small>
            </div>

            <div style="display:flex; gap:10px; margin-top:5px; padding:10px; background:#fff; border:1px solid #dce1e5; border-radius:6px; margin-bottom:10px;" id="wrap_font_options">
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label id="lbl_font_size" style="color:#004d40; font-size:10px; font-weight:600!important;">Value Font Size (px)</label>
                    <input type="number" id="p_font_size" class="form-control-fix" placeholder="32" value="32" min="8" max="120" style="margin-bottom:0;">
                </div>
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label id="lbl_font_weight" style="color:#004d40; font-size:10px; font-weight:600!important;">Value Font Weight</label>
                    <select id="p_font_weight" class="form-control-fix" style="margin-bottom:0;">
                        <option value="400">Normal</option>
                        <option value="600">Semi-Bold</option>
                        <option value="700" selected>Bold</option>
                        <option value="800">Extra-Bold</option>
                    </select>
                </div>
            </div>

            <div style="margin-top:5px; padding:10px; background:#fff; border:1px solid #dce1e5; border-radius:6px; margin-bottom:10px; display:flex; gap:15px; flex-wrap:wrap;">
                <label style="display:flex; align-items:center; cursor:pointer; font-size:11px; font-weight:600; color:#004d40; margin-bottom:0;">
                    <input type="checkbox" id="p_show_module" checked style="margin-right:8px; width:16px; height:16px;"> Show Module Name
                </label>
                <label style="display:flex; align-items:center; cursor:pointer; font-size:11px; font-weight:600; color:#004d40; margin-bottom:0;">
                    <input type="checkbox" id="p_use_raw" style="margin-right:8px; width:16px; height:16px;"> Use Raw Value
                </label>
                <label style="display:flex; align-items:center; cursor:pointer; font-size:11px; font-weight:600; color:#004d40; margin-bottom:0;" id="wrap_show_time">
                    <input type="checkbox" id="p_show_time" checked style="margin-right:8px; width:16px; height:16px;"> Show Chart Time
                </label>
                <label style="display:flex; align-items:center; cursor:pointer; font-size:11px; font-weight:600; color:#004d40; margin-bottom:0;" id="wrap_show_yaxis">
                    <input type="checkbox" id="p_show_yaxis" checked style="margin-right:8px; width:16px; height:16px;"> Show Y-Axis
                </label>
                <label style="display:flex; align-items:center; cursor:pointer; font-size:11px; font-weight:600; color:#e67e22; margin-bottom:0;" id="wrap_force_100">
                    <input type="checkbox" id="p_force_100" style="margin-right:8px; width:16px; height:16px;"> Force 0-100% Y-Axis
                </label>
            </div>

            <div style="display:flex; gap:10px; margin-top:5px; margin-bottom:10px;" id="wrap_stats_color">
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label style="color:#4a5568; font-size:10px; font-weight: 600!important;">Stats Card Background</label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="color" id="p_stat_bg_color" value="#ffffff" style="width:36px; height:34px; padding:2px; border:1px solid #cbd5e1; border-radius:4px; cursor:pointer; background:#fff;" onchange="document.getElementById('p_stat_bg_color_hex').value = this.value;">
                        <input type="text" id="p_stat_bg_color_hex" class="form-control-fix" value="" placeholder="#ffffff" style="flex:1; height:34px; margin-bottom:0;" oninput="if(/^#[0-9A-Fa-f]{6}$/.test(this.value)) document.getElementById('p_stat_bg_color').value = this.value;">
                    </div>
                </div>
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label style="color:#4a5568; font-size:10px; font-weight: 600!important;">Stats Card Font Color</label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="color" id="p_stat_font_color" value="#334155" style="width:36px; height:34px; padding:2px; border:1px solid #cbd5e1; border-radius:4px; cursor:pointer; background:#fff;" onchange="document.getElementById('p_stat_font_color_hex').value = this.value;">
                        <input type="text" id="p_stat_font_color_hex" class="form-control-fix" value="" placeholder="Default (Status Color)" style="flex:1; height:34px; margin-bottom:0;" oninput="if(/^#[0-9A-Fa-f]{6}$/.test(this.value)) document.getElementById('p_stat_font_color').value = this.value;">
                    </div>
                </div>
            </div>

            <!-- Chart Line & Multi-Series Color Customizer -->
            <div id="wrap_chart_colors" style="display:none; margin-top:5px; margin-bottom:10px; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                    <div style="font-weight:700; color:#0f172a; font-size:11px; display:flex; align-items:center; gap:6px;">
                        <span class="material-symbols-outlined" style="font-size:16px; color:#0284c7;">palette</span>
                        Chart & Series Colors
                    </div>
                    <button type="button" onclick="resetPanelSeriesColors()" style="background:#ffffff; border:1px solid #cbd5e1; border-radius:4px; padding:2px 8px; font-size:10px; font-weight:600; color:#475569; cursor:pointer;" title="Reset custom colors to default auto palette">
                        Reset to Auto
                    </button>
                </div>

                <!-- Palette Preset Selector -->
                <div style="display:flex; gap:10px; margin-bottom:8px; align-items:flex-end;">
                    <div style="flex:1;">
                        <label style="font-size:10px; font-weight:600; color:#64748b; margin-bottom:3px; display:block;">Color Palette (Auto Multi-Series)</label>
                        <select id="p_color_palette" class="form-control-fix" onchange="previewPaletteSelection()" style="margin-bottom:0; font-size:11px;">
                            <option value="vibrant">Modern Vibrant (High Contrast - Distinct Hues)</option>
                            <option value="distinct">Distinct Bold (Maximum Color Distance)</option>
                            <option value="neon">Neon Tech (Cyan, Lime, Hot Pink, Violet)</option>
                            <option value="ocean">Cool Ocean (Sky Blue, Teal, Cyan, Navy)</option>
                            <option value="warm">Warm Sunset (Orange, Crimson, Amber, Gold)</option>
                            <option value="pastel">Pastel Soft (Soft Indigo, Mint, Coral, Lavender)</option>
                        </select>
                    </div>
                    <div id="palette_swatch_preview" style="display:flex; gap:4px; align-items:center; padding-bottom:6px;">
                        <!-- Swatches preview rendered dynamically -->
                    </div>
                </div>

                <!-- Single Module Fixed Chart Color -->
                <div id="wrap_single_chart_color" style="display:none; margin-bottom:4px;">
                    <label style="font-size:10px; font-weight:600; color:#64748b; margin-bottom:4px; display:block;">Line / Bar Color (Fixed Color)</label>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <input type="color" id="p_chart_color" value="#0284c7" style="width:36px; height:34px; padding:2px; border:1px solid #cbd5e1; border-radius:4px; cursor:pointer; background:#fff;" onchange="document.getElementById('p_chart_color_hex').value = this.value;">
                        <input type="text" id="p_chart_color_hex" class="form-control-fix" placeholder="Auto (Status Color: Green / Yellow / Red)" style="flex:1; height:34px; margin-bottom:0; font-size:11px;" oninput="if(/^#[0-9A-Fa-f]{6}$/.test(this.value)) document.getElementById('p_chart_color').value = this.value;">
                        <button type="button" onclick="document.getElementById('p_chart_color_hex').value=''; document.getElementById('p_chart_color').value='#0284c7';" style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:4px; padding:6px 10px; font-size:10px; font-weight:600; color:#475569; cursor:pointer;" title="Follow default Pandora FMS module status color">Use Status Color</button>
                    </div>
                    <small style="color:#94a3b8; font-size:10px; display:block; margin-top:3px;">* Leave empty to follow Pandora FMS status color (OK = Green, Warning = Yellow, Critical = Red).</small>
                </div>

                <!-- Multi-Module Individual Series Colors List -->
                <div id="wrap_series_colors_list" style="display:none;">
                    <label style="font-size:10px; font-weight:600; color:#64748b; margin-bottom:4px; display:block;">Per-Series Color Overrides (Customize Individual Lines):</label>
                    <div id="series_colors_container" style="display:flex; flex-direction:column; gap:6px; max-height:160px; overflow-y:auto; padding:6px; background:#ffffff; border:1px solid #e2e8f0; border-radius:6px;">
                        <!-- Series rows rendered by renderSeriesColorsEditor() -->
                    </div>
                </div>
            </div>

            <div style="display:flex; gap:10px; margin-top:5px; padding:10px; background:#f8f9fa; border-radius:6px; border:1px solid #e0e4e8;">
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label style="color:#4a5568; font-size:10px; font-weight: 600!important;">Label for Value 1 (UP)</label>
                    <input type="text" id="p_lbl_1" class="form-control-fix" placeholder="e.g. UP" style="margin-bottom:0;">
                </div>
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label style="color:#4a5568; font-size:10px; font-weight: 600!important;">Label for Value 0 (DOWN)</label>
                    <input type="text" id="p_lbl_0" class="form-control-fix" placeholder="e.g. DOWN" style="margin-bottom:0;">
                </div>
            </div>

            <div class="form-group" id="wrap_columns_select" style="margin-top: 15px; margin-bottom: 15px;">
                <label style="font-weight: 600; margin-bottom: 8px; display: block; font-size: 11px; color: #7f8c8d; text-transform: uppercase;">Visible Columns (Table View Only)</label>
                <div style="display: flex; flex-wrap: wrap; gap: 15px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px; color: #4a5568;">
                        <input type="checkbox" class="col-visibility-chk" value="agent" checked> Node Agent
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px; color: #4a5568;">
                        <input type="checkbox" class="col-visibility-chk" value="group" checked> Group
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px; color: #4a5568;">
                        <input type="checkbox" class="col-visibility-chk" value="ip" checked> IP Address
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px; color: #4a5568;">
                        <input type="checkbox" class="col-visibility-chk" value="module" checked> Module
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px; color: #4a5568;">
                        <input type="checkbox" class="col-visibility-chk" value="status" checked> Status
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px; color: #4a5568;">
                        <input type="checkbox" class="col-visibility-chk" value="history" checked> Metrics History
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px; color: #4a5568;">
                        <input type="checkbox" class="col-visibility-chk" value="threshold" checked> Threshold
                    </label>
                </div>
            </div>

            <div style="margin-top:15px; padding:10px; border-top:1px solid #eee;">
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin:0 0 10px 0;">
                    <input type="checkbox" id="p_auto_convert_traffic" checked style="width:18px; height:18px; cursor:pointer;">
                    <span style="font-size:13px; font-weight:500; color:#4a5568;">Auto-Convert Traffic to bps / Kbps / Mbps / Gbps (On/Off)</span>
                </label>
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin:0;">
                    <input type="checkbox" id="p_hidden" style="width:18px; height:18px; cursor:pointer;">
                    <span style="font-size:13px; font-weight:500; color:#4a5568;">Hide Panel (Completely hide from Dashboard & Share URL)</span>
                </label>
                <small style="color:#bdc3c7; font-size:10px; display:block; margin-top:5px;">* Leaves blank to show raw numeric value (1/0).</small>
            </div>
        </div>

        <div class="modal-footer-custom">
            <button class="btn-secondary-custom" onclick="closePanelModal()">Cancel</button>
            <button class="btn-apply" onclick="applyPanel()">Apply Panel</button>
        </div>
    </div>
</div>

<!-- Visual Gallery Modal (Visual Type Catalog & Preview) -->
<div class="modal-overlay" id="visualGalleryModal" style="display:none; z-index:99999;">
    <div class="modal-box" style="width:95%; max-width:1150px; max-height:90vh; display:flex; flex-direction:column; padding:0; overflow:hidden;">
        <div style="padding:16px 20px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; background:#fafbfc;">
            <div>
                <h4 style="margin:0; font-size:16px; color:#0b1a26; font-weight:700; display:flex; align-items:center; gap:8px;">
                    <span class="material-symbols-outlined" style="color:#004d40;">dashboard_customize</span> Visual Type Catalog & Preview
                </h4>
                <p style="margin:4px 0 0; font-size:11px; color:#64748b;">Browse visual previews and choose the most suitable chart or table type for your monitoring needs.</p>
            </div>
            <span class="material-symbols-outlined" style="cursor:pointer; font-size:22px; color:#64748b;" onclick="closeVisualGalleryModal()">close</span>
        </div>
        
        <!-- Filter Tabs -->
        <div style="padding:8px 20px; background:#ffffff; border-bottom:1px solid #f1f5f9; display:flex; gap:8px; flex-wrap:wrap;">
            <button type="button" class="vtp-tab-btn active" onclick="setVisualGalleryFilter('all', this)">All (15)</button>
            <button type="button" class="vtp-tab-btn" onclick="setVisualGalleryFilter('timeseries', this)">Charts & Time-Series (4)</button>
            <button type="button" class="vtp-tab-btn" onclick="setVisualGalleryFilter('status', this)">Status & Real-Time Grids (5)</button>
            <button type="button" class="vtp-tab-btn" onclick="setVisualGalleryFilter('kpi', this)">Single Value & KPIs (3)</button>
            <button type="button" class="vtp-tab-btn" onclick="setVisualGalleryFilter('table', this)">Tables & Snapshots (3)</button>
        </div>

        <div class="modal-body-scroll" style="padding:16px 20px; background:#f8fafc;">
            <div id="vtp_gallery_grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:14px;"></div>
        </div>

        <div style="padding:12px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end; background:#fafbfc;">
            <button type="button" class="btn-secondary-custom" onclick="closeVisualGalleryModal()">Close</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="statusDetailModal">
    <div class="modal-box" style="width:90%; max-width:1100px; max-height:90vh; display:flex; flex-direction:column; padding:0; overflow:hidden;">
        <div style="padding:20px 25px; border-bottom:1px solid #f0f3f5; display:flex; justify-content:space-between; align-items:center; background:#fafbfc;">
            <div>
                <h4 id="statusDetailTitle" style="margin:0; font-size:16px; color:#0b1a26; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Status Details</h4>
                <p style="margin:5px 0 0; font-size:11px; color:#64748b;">* Displays module list based on the clicked status group.</p>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <div style="position:relative;">
                    <span class="material-symbols-outlined" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:16px!important;">search</span>
                    <input type="text" id="statusDetailSearch" class="form-control-fix" style="margin-bottom:0; padding-left:32px; width:250px; height:34px; font-size:12px;" placeholder="Search agent or module..." onkeyup="filterStatusDetailTable()">
                </div>
                <button class="icon-btn" onclick="closeStatusDetailModal()" style="padding:5px;"><span class="material-symbols-outlined" style="font-size:24px!important;">close</span></button>
            </div>
        </div>
        <div style="padding:0; flex-grow:1; overflow-y:auto; background:#fff;">
            <table class="table-dyn" id="statusDetailTable" style="margin:0;">
                <thead style="position:sticky; top:0; z-index:10; background:#fafbfc;">
                    <tr>
                        <th style="padding:12px 15px;">AGENT</th>
                        <th style="padding:12px 15px;">GROUP</th>
                        <th style="padding:12px 15px;">IP ADDRESS</th>
                        <th style="padding:12px 15px;">MODULE</th>
                        <th style="padding:12px 15px;">VALUE</th>
                        <th style="padding:12px 15px;">STATUS</th>
                        <th style="padding:12px 15px; text-align:center;">ACTION</th>
                    </tr>
                </thead>
                <tbody id="statusDetailBody">
                    </tbody>
            </table>
        </div>
        <div style="padding:15px 25px; border-top:1px solid #f0f3f5; text-align:right; background:#fafbfc;">
            <button class="btn-cancel" onclick="closeStatusDetailModal()">Close</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="nativeChartModal" onclick="closeNativeChartModal()">
    <div class="iframe-modal-box" onclick="event.stopPropagation()">
        <div class="iframe-header">
            <div class="iframe-title" id="nativeChartTitle">Metrics History</div>
            <button class="btn-secondary-custom" onclick="closeNativeChartModal()" style="padding: 4px 8px; border:none; background:#e0e4e8;">
                <span class="material-symbols-outlined" style="font-size:16px!important;">close</span>
            </button>
        </div>
        <iframe id="nativeChartFrame" src="" style="width: 100%; height: 500px; border: none; background: #fff;"></iframe>
    </div>
</div>

<div class="modal-overlay" id="nativeModuleDetailModal" style="display:none;">
    <div class="modal-box iframe-modal-box" style="width: 950px; max-width: 95%; height: 85vh; padding:0; display:flex; flex-direction:column; overflow:hidden;">
        <div class="iframe-header" style="padding: 15px 20px; border-bottom: 1px solid #e0e4e8; display:flex; justify-content:space-between; align-items:center; background-color:#f8f9fa; flex-shrink:0;">
            <h5 class="iframe-title" id="nativeModuleDetailTitle" style="font-weight:600!important; margin:0; font-size:14px; color:#0b1a26;">Module Detail</h5>
            <span class="material-symbols-outlined" style="cursor:pointer; color:#7f8c8d; font-size:20px;" onclick="closeNativeModuleDetailModal()">close</span>
        </div>
        <div class="modal-body-scroll" style="flex-grow:1; padding:20px; overflow-y:auto; background:#f8fafc; display:flex; flex-direction:column; gap:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; background:#ffffff; border-radius:8px; border:1px solid #e2e8f0; padding:12px 20px; flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span class="material-symbols-outlined" style="color:#004d40; font-size:18px;">schedule</span>
                    <span style="font-weight:600; color:#1e293b; font-size:13px;">Time Range:</span>
                    <select id="nativeModuleTimeRange" class="form-control-fix" style="width:160px; margin-bottom:0; height:32px; padding:4px 8px; font-size:13px;" onchange="handleNativeModuleRangeChange()">
                        <option value="3600">1 Hour</option>
                        <option value="21600">6 Hours</option>
                        <option value="86400" selected>24 Hours</option>
                        <option value="604800">7 Days</option>
                        <option value="2592000">30 Days</option>
                        <option value="custom">Custom Range...</option>
                    </select>
                </div>
                
                <div id="nativeModuleCustomRangeBox" style="display:none; align-items:center; gap:8px; flex-wrap:wrap;">
                    <input type="datetime-local" id="nativeModuleCustomStart" class="form-control-fix" style="width:190px; margin-bottom:0; height:32px; padding:4px 8px; font-size:12px;">
                    <span style="color:#64748b; font-size:12px;">to</span>
                    <input type="datetime-local" id="nativeModuleCustomEnd" class="form-control-fix" style="width:190px; margin-bottom:0; height:32px; padding:4px 8px; font-size:12px;">
                    <button class="btn-apply" style="padding:4px 15px; font-size:12px; height:32px; display:inline-flex; align-items:center; justify-content:center;" onclick="applyNativeModuleCustomRange()">Apply</button>
                </div>
            </div>

            <div id="nativeModuleChartContainer" style="background:#ffffff; border-radius:8px; border:1px solid #e2e8f0; padding:15px; min-height:280px; position:relative;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h6 style="margin:0; font-weight:600; color:#1e293b; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Historical Trend</h6>
                    <span style="font-size:10px; color:#94a3b8; font-style:italic;">Scroll to Zoom • Drag to Pan</span>
                </div>
                <div style="height:200px; width:100%; position:relative;">
                    <div id="nativeModuleDetailChart" style="width:100%; height:100%; min-height:200px;"></div>
                </div>
            </div>
            
            <div id="nativeModuleTableContainer" style="background:#ffffff; border-radius:8px; border:1px solid #e2e8f0; padding:15px; display:flex; flex-direction:column; flex-grow:1; min-height:300px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <h6 style="margin:0; font-weight:600; color:#1e293b; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Data Log</h6>
                    <div style="font-size:11px; color:#64748b;" id="nativeModuleDetailCount">0 rows</div>
                </div>
                <div style="overflow-y:auto; flex-grow:1; max-height:300px; border:1px solid #f0f3f5; border-radius:6px;">
                    <table class="table-pfms" id="nativeModuleDetailTable">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td colspan="2" style="text-align:center; padding:30px; color:#64748b;">Loading history...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= h($vendor_url) ?>/sortablejs/Sortable.min.js"></script>
<script>
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;
const CSRF_TOKEN = "<?= h($csrf_token) ?>";
const PANDORA_URL = "<?= h($PANDORA_BASE_URL) ?>";
const PRIMARY_UUID = '<?= get_node_uuid('primary') ?>';
const apiPage = 'Dashboard/Dynamic-Dashboard/dynamic-dashboard-template.php';
const apiBase = '../../custom-index.php';

function getApiUrl(apiName, params = {}) {
    const u = new URL(apiBase, window.location.href);
    u.searchParams.set('page', apiPage);
    u.searchParams.set('api', apiName);
    Object.keys(params).forEach(k => u.searchParams.set(k, params[k]));
    return u.toString();
}
let masterDashboards = [];
let currentDashId = null;
let currentAgentList = [];
let chartInstances = {};
let autoRefreshTimer = null;
let refreshIntervalConfig = 0;
let countdownValue = 0;
let globalModuleList = [];
let editingPanelId = null;
let lastFetchedData = {};
let currentDetailModuleList = null;
let showHiddenPanels = false;
let isFetchingModules = true;
let exactModulePage = 1;
const exactModuleLimit = 50;

let globalGroupsMap = {};

const baseBreadcrumb = '<?= h($dynamic_breadcrumb) ?>';
let hasUnsavedChanges = false;

let nativeModuleChartInstance = null;
let currentDetailModuleId = null;
let currentDetailModuleTitle = '';
let currentDetailViewType = '';

function isTrafficMetric(moduleName = '', rawUnit = '', cardTitle = '') {
    const u = (rawUnit || '').replace(/\s+/g, ' ').trim().toLowerCase();
    const m = (moduleName || '').toLowerCase();
    const t = (cardTitle || '').toLowerCase();

    // Any unit indicating bytes/second, bits/second, octets, etc.
    if (u.includes('byte') || u.includes('bit') || u.includes('bps') || u.includes('b/s') || u.includes('octet') || u === 'b') {
        return true;
    }
    // Any module name related to network traffic / interfaces
    if (m.includes('octet') || m.includes('traffic') || m.includes('download') || m.includes('upload') ||
        m.includes('bandwidth') || m.includes('ifin') || m.includes('ifout') || m.includes('ifhc') ||
        m.includes('ether') || m.includes('eth') || m.includes('port') || m.includes('lan') || m.includes('wan') ||
        m.includes('wlan') || m.includes('sfp') || m.includes('rx') || m.includes('tx')) {
        return true;
    }
    // Any card title related to traffic
    if (t.includes('traffic') || t.includes('bandwidth') || t.includes('download') || t.includes('upload') ||
        t.includes('throughput') || t.includes('in/out') || t.includes('port') || t.includes('interface')) {
        return true;
    }
    return false;
}

function isByteTrafficMetric(moduleName = '', rawUnit = '', cardTitle = '') {
    if (!isTrafficMetric(moduleName, rawUnit, cardTitle)) return false;
    const u = (rawUnit || '').replace(/\s+/g, ' ').trim().toLowerCase();
    // If unit explicitly says bits (bps, bit/s, kbps, mbps, gbps), it's already bit rate
    if (u.includes('bit') || u === 'bps' || u === 'kbps' || u === 'mbps' || u === 'gbps') {
        return false;
    }
    // All other traffic metrics (bytes/s, B/s, bytes, octets, ifInOctets, ifOutOctets, etc.) are in bytes and need 8x multiplier
    return true;
}

function formatBitsRate(bits) {
    if (bits === null || bits === undefined || bits === '' || isNaN(bits) || bits === '-') return '-';
    const b = parseFloat(bits);
    const abs = Math.abs(b);
    if (abs >= 1000000000) return (b / 1000000000).toFixed(2) + ' Gbps';
    if (abs >= 1000000) return (b / 1000000).toFixed(2) + ' Mbps';
    if (abs >= 1000) return (b / 1000).toFixed(2) + ' Kbps';
    return (b % 1 === 0 ? b : b.toFixed(2)) + ' bps';
}

function formatHumanMetric(val, rawUnit, autoConvertTraffic = true, moduleName = '', cardTitle = '') {
    if (val === null || val === undefined || val === '' || isNaN(val) || val === '-') {
        return '-';
    }
    let num = parseFloat(val);
    if (isNaN(num)) return val;

    const unit = (rawUnit || '').trim();
    const uLower = unit.toLowerCase();
    const isTraffic = isTrafficMetric(moduleName, unit, cardTitle);

    if (num === 0) {
        if (autoConvertTraffic && isTraffic) {
            return '0 bps';
        }
        return unit ? `0 ${unit}` : '0';
    }

    // 1. Network Traffic Rate -> Convert Byte rate to bit rate (* 8) and format as bps/Kbps/Mbps/Gbps
    if (autoConvertTraffic && isTraffic) {
        let bits = isByteTrafficMetric(moduleName, unit, cardTitle) ? (num * 8) : num;
        if (uLower === 'kbps') bits = num * 1000;
        if (uLower === 'mbps') bits = num * 1000000;
        if (uLower === 'gbps') bits = num * 1000000000;
        return formatBitsRate(bits);
    }

    // 2. Storage / Memory (bytes, B without /s)
    if (uLower === 'bytes' || uLower === 'b') {
        const abs = Math.abs(num);
        if (abs >= 1073741824) { // 1024^3
            return (num / 1073741824).toFixed(2) + ' GB';
        } else if (abs >= 1048576) { // 1024^2
            return (num / 1048576).toFixed(2) + ' MB';
        } else if (abs >= 1024) {
            return (num / 1024).toFixed(2) + ' KB';
        } else {
            return (num % 1 === 0 ? num : num.toFixed(2)) + ' B';
        }
    }

    // 3. Percentage
    if (uLower === '%') {
        let pct = num;
        const mLow = (moduleName || '').toLowerCase();
        const cLow = (cardTitle || '').toLowerCase();
        if (pct > 100 && (mLow.includes('load') || mLow.includes('used') || mLow.includes('cpu') || mLow.includes('mem') || mLow.includes('disk') || cLow.includes('utiliz'))) {
            pct = 100;
        }
        return (pct % 1 === 0 ? pct : pct.toFixed(2)) + '%';
    }

    // 4. Latency / Time
    if (uLower === 'ms' || uLower === 's') {
        return (num % 1 === 0 ? num : num.toFixed(2)) + ' ' + unit;
    }

    // 5. Optical / dBm
    if (uLower === 'dbm') {
        return (num % 1 === 0 ? num : num.toFixed(2)) + ' dBm';
    }

    // 6. General numbers (or when autoConvertTraffic is false)
    const formatted = (num % 1 === 0) ? num.toLocaleString() : num.toFixed(2);
    return unit ? `${formatted} ${unit}` : formatted;
}

// V4.8 IS_STANDALONE FLAG
const IS_STANDALONE = <?= $isStandalone ? 'true' : 'false' ?>;
const IS_MODAL_ONLY = <?= $isModalOnly ? 'true' : 'false' ?>;
const DIRECT_SCRIPT_URL = '<?= $directScriptUrl ?>';

// V4.8: SHARE LINK FUNCTIONS
function copyDashboardShareLink(dashId = null) {
    const idToShare = dashId || currentDashId;
    if (!idToShare) return;
    const card = masterDashboards.find(x => x.id === idToShare);
    const scriptPath = (DIRECT_SCRIPT_URL && DIRECT_SCRIPT_URL.indexOf('.php') !== -1)
        ? DIRECT_SCRIPT_URL
        : window.location.pathname;
    const u = new URL(window.location.origin + scriptPath);
    u.searchParams.set('s', '1');
    u.searchParams.set('d', idToShare);
    
    const curGroup = document.getElementById('top_group') ? document.getElementById('top_group').value : null;
    const curAgent = document.getElementById('top_agent') ? document.getElementById('top_agent').value : null;
    
    if (curGroup && curGroup != 0) u.searchParams.set('g', curGroup);
    else if (card && card.default_group) u.searchParams.set('g', card.default_group);
    
    if (curAgent && curAgent != 0) u.searchParams.set('a', curAgent);
    else if (card && card.default_agent) u.searchParams.set('a', card.default_agent);
    
    const dashTitle = card ? card.title : 'Dashboard';
    const linkStr = u.toString();
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(linkStr).then(() => {
            alert('Standalone Link for Dashboard "' + dashTitle + '" copied successfully:\n' + linkStr);
        });
    } else {
        const textArea = document.createElement("textarea"); textArea.value = linkStr; document.body.appendChild(textArea); textArea.select();
        try { document.execCommand('copy'); alert('Standalone Link for Dashboard "' + dashTitle + '" copied successfully:\n' + linkStr); } catch (err) {}
        document.body.removeChild(textArea);
    }
}

function copyPanelShareLink(panelId) {
    if (!currentDashId) return;
    const scriptPath = (DIRECT_SCRIPT_URL && DIRECT_SCRIPT_URL.indexOf('.php') !== -1)
        ? DIRECT_SCRIPT_URL
        : window.location.pathname;
    const u = new URL(window.location.origin + scriptPath);
    u.searchParams.set('s', '1');
    u.searchParams.set('d', currentDashId);
    u.searchParams.set('g', document.getElementById('top_group').value);
    u.searchParams.set('a', document.getElementById('top_agent').value);
    u.searchParams.set('p', panelId);

    const linkStr = u.toString();
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(linkStr).then(() => {
            alert('Standalone Link for this widget copied to clipboard:\n' + linkStr);
        });
    } else {
        const textArea = document.createElement("textarea"); textArea.value = linkStr; document.body.appendChild(textArea); textArea.select();
        try { document.execCommand('copy'); alert('Standalone Link for this widget copied to clipboard:\n' + linkStr); } catch (err) {}
        document.body.removeChild(textArea);
    }
}

function exportToPdf() {
    window.print();
}

function exportToPng() {
    const overlay = document.createElement('div');
    overlay.style.position = 'fixed';
    overlay.style.inset = '0';
    overlay.style.background = 'rgba(0,0,0,0.5)';
    overlay.style.zIndex = '99999';
    overlay.style.display = 'flex';
    overlay.style.alignItems = 'center';
    overlay.style.justifyContent = 'center';
    overlay.style.color = '#fff';
    overlay.style.fontFamily = 'sans-serif';
    overlay.style.fontSize = '18px';
    overlay.innerHTML = '<div>Generating PNG image, please wait...</div>';
    document.body.appendChild(overlay);

    const targetElement = document.getElementById('panelsGrid') || document.querySelector('.main-content');
    
    if (typeof html2canvas === 'undefined') {
        document.body.removeChild(overlay);
        alert("html2canvas library is not loaded yet.");
        return;
    }

    html2canvas(targetElement, {
        useCORS: true,
        scale: 2,
        backgroundColor: '#f4f6f8',
        logging: false
    }).then(canvas => {
        document.body.removeChild(overlay);
        const link = document.createElement('a');
        const dash = masterDashboards.find(x => x.id === currentDashId);
        const title = dash ? dash.title : 'Dashboard';
        link.download = `${title.replace(/\s+/g, '_')}_export.png`;
        link.href = canvas.toDataURL('image/png');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }).catch(err => {
        document.body.removeChild(overlay);
        console.error("Export Image Error:", err);
        alert("Failed to export image: " + err.message);
    });
}

function markUnsaved() {
    hasUnsavedChanges = true;
    const btn = document.getElementById('btnSaveDashboard');
    if(btn) {
        btn.classList.add('btn-unsaved');
        btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:18px!important;">save</span> Save Dashboard *';
    }
}

function markSaved() {
    hasUnsavedChanges = false;
    const btn = document.getElementById('btnSaveDashboard');
    if(btn) {
        btn.classList.remove('btn-unsaved');
        btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:18px!important;">save</span> Save Dashboard';
    }
}

function saveConfigToServer(callback, quiet = false) {
    fetch(getApiUrl('save_config'), { method:'POST', body:JSON.stringify(masterDashboards), headers: {'X-CSRF-TOKEN': '<?= $csrf_token ?>'} })
    .then(r => r.json())
    .then(res => {
        if(!res.ok) alert(`SAVE FAILED!\nReason: ${res.error?.message || res.error || 'Unknown Error'}\nTarget: ${res.file || 'File permission issue'}`);
        else if(callback) callback();
    })
    .catch(e => { if(!quiet) alert("Failed to connect to server."); });
}

function updateURLState(dashId = null, groupId = null, agentId = null) {
    const u = new URL(window.location.href);
    u.searchParams.delete('standalone');
    u.searchParams.delete('dash_id');
    u.searchParams.delete('group_id');
    u.searchParams.delete('agent_id');
    u.searchParams.delete('panel_id');
    u.searchParams.delete('v'); 

    if (dashId) {
        u.searchParams.set('d', dashId);
        if (groupId) u.searchParams.set('g', groupId);
        if (agentId) u.searchParams.set('a', agentId);
    } else {
        u.searchParams.delete('d');
        u.searchParams.delete('g');
        u.searchParams.delete('a');
        u.searchParams.delete('p');
    }
    window.history.replaceState({}, '', u.toString());
}

async function init() {
    try {
        const res = await fetch(getApiUrl('load_config'));
        const text = await res.text();
        try {
            const data = JSON.parse(text);
            if(Array.isArray(data)) masterDashboards = data;
        } catch(e) {
            console.error("Malformed JSON response for load_config:", text);
        }
    } catch(e) {
        console.error("Failed to fetch load_config:", e);
    }

    try {
        const r = await fetch(getApiUrl('groups'));
        const text = await r.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch(e) {
            console.error("Malformed JSON response for groups:", text);
            data = {error: "Invalid JSON response"};
        }

        if (data.error) { console.error("Groups Error:", data.error); return; }
        const selTop = document.getElementById('top_group');
        const selSet = document.getElementById('m_default_group');
        if(selTop) selTop.innerHTML = ''; 
        if(selSet) selSet.innerHTML = '';
        
        data.forEach(g => {
            if(selTop) selTop.add(new Option(g.name, g.id));
            if(selSet) selSet.add(new Option(g.name, g.id));
            globalGroupsMap[g.id] = g.name;
        });
        
        const u = new URLSearchParams(window.location.search);
        let urlDashId = u.get('d') || u.get('dash_id');
        const urlGroupId = u.get('g') || u.get('group_id');
        const urlAgentId = u.get('a') || u.get('agent_id');
        const openModalPanelId = u.get('open_modal') || u.get('panel_id') || u.get('p') || u.get('card_id');
        const openModalStatus = u.get('status_filter') !== null ? parseInt(u.get('status_filter')) : null;
        const openModalLabel = u.get('status_label') || 'UP';

        if (!urlDashId && openModalPanelId) {
            const parentDash = masterDashboards.find(d => (d.panels || []).some(panel => panel.id === openModalPanelId));
            if (parentDash) urlDashId = parentDash.id;
        }

        if (IS_MODAL_ONLY) {
            if (urlDashId) {
                openDashboard(urlDashId, urlGroupId, urlAgentId);
            }
            if (openModalPanelId && openModalStatus !== null) {
                setTimeout(() => {
                    showStatusDetails(openModalPanelId, openModalStatus, openModalLabel);
                }, 500);
            }
            return;
        }

        if (urlDashId && masterDashboards.some(d => d.id === urlDashId)) {
            openDashboard(urlDashId, urlGroupId, urlAgentId);
            if (openModalPanelId && openModalStatus !== null) {
                setTimeout(() => {
                    showStatusDetails(openModalPanelId, openModalStatus, openModalLabel);
                }, 800);
            }
        } else {
            renderDashboardList();
        }
    } catch(err) {
        console.error("Groups Fetch Error:", err);
    }

    try {
        const r = await fetch(getApiUrl('module_list'));
        const text = await r.text();
        try {
            globalModuleList = JSON.parse(text);
        } catch(e) {
            console.error("Malformed JSON response for module_list:", text);
        }
        isFetchingModules = false;
    } catch(e) {}
}

function renderDashboardList() {
    const tbody = document.querySelector('#dashListTable tbody');
    if(!tbody) return;
    const kw = document.getElementById('listSearch').value.toLowerCase();
    tbody.innerHTML = '';
    const filtered = masterDashboards.filter(d => d.title.toLowerCase().includes(kw));
    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding:30px; color:#7f8c8d;">No Dashboard Templates found.</td></tr>`;
        return;
    }
    filtered.forEach(d => {
        const groupName = globalGroupsMap[d.default_group] || 'All Groups';
        const panelCount = (d.panels || []).length;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><a class="dash-name-link" onclick="openDashboard('${d.id}')"><span class="material-symbols-outlined" style="font-size:18px!important;">dashboard</span> ${d.title}</a></td>
            <td><span style="color:#4a5568;">${groupName}</span></td>
            <td><span class="dash-badge">${panelCount} Panels</span></td>
            <td style="text-align:right;">
                <button class="btn-action" onclick="openDashboard('${d.id}')" title="Open Dashboard">
                    <span class="material-symbols-outlined">visibility</span>
                </button>
                ${IS_ADMIN ? `
                <button class="btn-action" onclick="openDashboardAclModal('${d.id}', '${d.title ? d.title.replace(/'/g, "\\'") : ''}')" title="Access Permissions (Profiles & Users)">
                    <span class="material-symbols-outlined">shield_person</span>
                </button>
                <button class="btn-action" onclick="editDashboardSettingsFromList('${d.id}')" title="Configure">
                    <span class="material-symbols-outlined">settings</span>
                </button>
                <button class="btn-action" onclick="exportDashboardConfig('${d.id}')" title="Backup Dashboard Config">
                    <span class="material-symbols-outlined">download</span>
                </button>
                <button class="btn-action" onclick="triggerImport('${d.id}')" title="Load Dashboard Config">
                    <span class="material-symbols-outlined">upload</span>
                </button>
                <button class="btn-action" onclick="duplicateDashboardFromList('${d.id}')" title="Duplicate">
                    <span class="material-symbols-outlined">content_copy</span>
                </button>
                <button class="btn-action btn-delete" onclick="deleteDashboard('${d.id}')" title="Delete">
                    <span class="material-symbols-outlined">delete</span>
                </button>
                ` : ''}
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function editDashboardSettingsFromList(id) {
    currentDashId = id;
    openDashMetaModal(true);
}

function openDashMetaModal(isEdit = false) {
    if (isEdit && currentDashId) {
        const d = masterDashboards.find(x => x.id === currentDashId);
        document.getElementById('dashMetaTitle').innerText = 'Edit Dashboard Settings';
        document.getElementById('m_dash_title').value = d.title;
        document.getElementById('m_default_group').value = d.default_group || '0';
    } else {
        document.getElementById('dashMetaTitle').innerText = 'Create New Dashboard';
        document.getElementById('m_dash_title').value = '';
        document.getElementById('m_default_group').value = '0';
    }
    document.getElementById('dashMetaModal').style.display = 'flex';
}

function closeDashMetaModal() { document.getElementById('dashMetaModal').style.display = 'none'; }

function saveDashboardMeta() {
    const title = document.getElementById('m_dash_title').value || 'New Dashboard';
    const grp = document.getElementById('m_default_group').value || '0';
    
    if (document.getElementById('dashMetaTitle').innerText.includes('Edit') && currentDashId) {
        masterDashboards = masterDashboards.map(d => {
            if(d.id === currentDashId) { d.title = title; d.default_group = grp; }
            return d;
        });
        closeDashMetaModal();
        openDashboard(currentDashId);
        markUnsaved();
    } else {
        const newDash = { id: 'dash_' + Date.now(), title: title, default_group: grp, refresh_rate: 60, panels: [] };
        masterDashboards.push(newDash);
        saveConfigToServer(() => { closeDashMetaModal(); renderDashboardList(); openDashboard(newDash.id); });
    }
}

function deleteDashboard(id) {
    if(confirm('Delete dashboard?')) {
        masterDashboards = masterDashboards.filter(d => d.id !== id);
        saveConfigToServer(() => { renderDashboardList(); });
    }
}

function saveCurrentDashboard() {
    if(!currentDashId) return;
    const currentGroup = document.getElementById('top_group').value || '0';
    const currentAgent = document.getElementById('top_agent').value;
    const currentAgentName = document.getElementById('agent_search_input').value; 
    masterDashboards = masterDashboards.map(d => {
        if(d.id === currentDashId) { d.default_group = currentGroup; d.default_agent = currentAgent; d.default_agent_name = currentAgentName; }
        return d;
    });
    saveConfigToServer(() => { markSaved(); alert("Dashboard Saved!"); });
}

let importTargetDashId = null;

function triggerImport(id) {
    importTargetDashId = id;
    document.getElementById('importBackupFile').click();
}

function exportDashboardConfig(id) {
    const targetId = id || currentDashId;
    if(!targetId) return;
    const d = masterDashboards.find(x => x.id === targetId);
    if(!d) return;
    const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(d.panels || [], null, 2));
    const dlAnchorElem = document.createElement('a');
    dlAnchorElem.setAttribute("href",     dataStr);
    dlAnchorElem.setAttribute("download", `dynamic_dashboard_${d.title.toLowerCase().replace(/\s+/g, '_')}_backup.json`);
    dlAnchorElem.click();
}

function importDashboardConfig(event) {
    const targetId = importTargetDashId || currentDashId;
    if(!targetId) return;
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const loaded = JSON.parse(e.target.result);
            if (Array.isArray(loaded)) {
                const isValid = loaded.every(p => p && typeof p === 'object' && 'id' in p && 'title' in p);
                if (!isValid) {
                    alert("Invalid file format. Please make sure the JSON file contains valid panel configuration.");
                    return;
                }
                masterDashboards = masterDashboards.map(d => {
                    if (d.id === targetId) {
                        d.panels = loaded;
                    }
                    return d;
                });
                saveConfigToServer(() => {
                    if (targetId === currentDashId) {
                        renderPanelsGrid();
                        forceRefresh();
                    } else {
                        renderDashboardList();
                    }
                    alert("Widgets loaded successfully!");
                });
            } else {
                alert("Invalid JSON file format. Must be an array of panels.");
            }
        } catch (err) {
            alert("Invalid JSON file: " + err.message);
        } finally {
            event.target.value = '';
            importTargetDashId = null;
        }
    };
    reader.readAsText(file);
}



function duplicateDashboard() {
    if(!currentDashId) return;
    const dash = masterDashboards.find(d => d.id === currentDashId);
    if(!dash) return;
    
    const newDash = JSON.parse(JSON.stringify(dash));
    newDash.id = 'd' + Date.now();
    newDash.title = newDash.title + ' (Copy)';
    
    masterDashboards.push(newDash);
    markUnsaved();
    saveConfigToServer(() => {
        alert('Dashboard duplicated successfully!');
        renderDashboardList();
        openDashboard(newDash.id);
        updateURLState(newDash.id);
    });
}

function duplicateDashboardFromList(id) {
    const dash = masterDashboards.find(d => d.id === id);
    if(!dash) return;
    
    const newDash = JSON.parse(JSON.stringify(dash));
    newDash.id = 'd' + Date.now();
    newDash.title = newDash.title + ' (Copy)';
    
    masterDashboards.push(newDash);
    saveConfigToServer(() => {
        alert('Dashboard duplicated successfully!');
        renderDashboardList();
    });
}

function openDashboard(id, initGroupId = null, initAgentId = null) {
    const d = masterDashboards.find(x => x.id === id);
    if(!d) return;
    currentDashId = id;
    if (document.getElementById('view_list')) document.getElementById('view_list').classList.add('d-none');
    if (document.getElementById('listTopControls')) document.getElementById('listTopControls').classList.add('d-none');
    document.getElementById('view_detail').classList.remove('d-none');
    
    const uParams = new URLSearchParams(window.location.search);
    const filterPanelId = uParams.get('p') || uParams.get('panel_id');
    if (IS_STANDALONE && filterPanelId) {
        const p = d.panels.find(x => x.id === filterPanelId);
        if(p) {
            if (p.hidden) {
                document.getElementById('pageMainTitle').innerText = "Panel Restricted";
                document.getElementById('panelsGrid').innerHTML = `
                    <div style="grid-column: span 12; text-align:center; padding:100px 20px; background:#fff; border-radius:8px; border:1px dashed #e0e4e8;">
                        <span class="material-symbols-outlined" style="font-size:64px; color:#e74c3c; margin-bottom:20px;">visibility_off</span>
                        <h2 style="color:#2c3e50; margin-bottom:10px;">This panel is currently hidden</h2>
                        <p style="color:#7f8c8d;">This widget has been disabled by an administrator and cannot be accessed publicly.</p>
                    </div>`;
                return;
            }
            document.getElementById('pageMainTitle').innerText = p.title;
            document.title = p.title + ' - Standalone View';
        } else {
            document.getElementById('pageMainTitle').innerText = d.title;
        }
    } else {
        document.getElementById('pageMainTitle').innerText = d.title;
    }
    
    const targetGroup = initGroupId !== null ? String(initGroupId) : String(d.default_group || '0');
    const targetAgent = initAgentId !== null ? initAgentId : (d.default_agent || null);

    if(document.getElementById('top_group')) document.getElementById('top_group').value = targetGroup;
    if(document.getElementById('top_refresh')) document.getElementById('top_refresh').value = d.refresh_rate || 60;
    
    if (targetAgent && d.default_agent_name && document.getElementById('agent_search_input')) {
        document.getElementById('agent_search_input').value = d.default_agent_name;
        document.getElementById('top_agent').value = targetAgent;
    }

    if (IS_STANDALONE) {
        document.querySelectorAll('.toolbar-right button').forEach(b => {
            const txt = b.innerText.toLowerCase();
            if (txt.includes('hidden') || txt.includes('settings') || txt.includes('add') || txt.includes('save')) {
                b.style.display = 'none';
            }
        });
    }
    renderPanelsGrid();
    onGroupChange(targetAgent);
    applyAutoRefresh();
    updateURLState(id, targetGroup, targetAgent);
}

function closeDashboard() {
    if(IS_STANDALONE) return;
    currentDashId = null;
    if(autoRefreshTimer) clearInterval(autoRefreshTimer);
    document.getElementById('view_detail').classList.add('d-none');
    document.getElementById('view_list').classList.remove('d-none');
    document.getElementById('listTopControls').classList.remove('d-none');
    updateURLState(null);
    renderDashboardList();
}

function updateCountdownUI() {
    const txt = document.getElementById('countdown_text');
    if (!txt) return;
    txt.innerText = refreshIntervalConfig <= 0 ? 'Auto Refresh: Off' : `Refresh in: ${countdownValue}s`;
}

function applyAutoRefresh() {
    if (autoRefreshTimer) clearInterval(autoRefreshTimer);
    refreshIntervalConfig = parseInt(document.getElementById('top_refresh').value);
    if (refreshIntervalConfig > 0) {
        countdownValue = refreshIntervalConfig;
        updateCountdownUI();
        autoRefreshTimer = setInterval(() => {
            countdownValue--;
            if (countdownValue <= 0) forceRefresh();
            else updateCountdownUI();
        }, 1000);
    } else { updateCountdownUI(); }
}

function forceRefresh() {
    refreshCurrentNodeData();
    if (refreshIntervalConfig > 0) { countdownValue = refreshIntervalConfig; updateCountdownUI(); }
    
    const now = new Date();
    const timeStr = now.getHours().toString().padStart(2, '0') + ':' + 
                    now.getMinutes().toString().padStart(2, '0') + ':' + 
                    now.getSeconds().toString().padStart(2, '0');
    const updateEl = document.getElementById('last_update_text');
    if(updateEl) updateEl.innerText = `Last Update: ${timeStr}`;
}

function onGroupChange(autoSelectAgentId = null) {
    const groupId = document.getElementById('top_group').value;
    const searchInput = document.getElementById('agent_search_input');
    fetch(getApiUrl('template_nodes', { group_id: groupId }))
    .then(r => r.text().then(text => {
        try { return JSON.parse(text); }
        catch(e) { console.error("Malformed JSON response for template_nodes:", text); return {error: "Invalid JSON response from server. Check console."}; }
    }))
    .then(nodes => {
        if (nodes.error) {
            console.error("Nodes Error:", nodes.error);
            currentAgentList = [];
            searchInput.placeholder = "Error loading nodes";
            return;
        }
        currentAgentList = nodes || [];
        if (autoSelectAgentId && currentAgentList.some(n => String(n.id) === String(autoSelectAgentId))) {
            const selNode = currentAgentList.find(n => String(n.id) === String(autoSelectAgentId));
            selectAgent(selNode.id, selNode.alias, true);
        } else {
            searchInput.value = '';
            document.getElementById('top_agent').value = '0';
            forceRefresh(); 
        }
        updateURLState(currentDashId, groupId, document.getElementById('top_agent').value);
    }).catch(err => {
        console.error("Nodes Fetch Error:", err);
        currentAgentList = [];
    });
}

function showAgentDropdown() { if(currentAgentList.length > 0) document.getElementById('agent_dropdown').style.display = 'flex'; renderAgentList(); }
function renderAgentList() {
    const ul = document.getElementById('agent_ul');
    const kw = document.getElementById('agent_search_input').value.toLowerCase();
    let filtered = currentAgentList.filter(n => n.alias.toLowerCase().includes(kw));
    ul.innerHTML = filtered.slice(0,100).map(n => `<li onclick="selectAgent('${n.id}', '${n.alias.replace(/'/g, "\\'")}')">${n.alias}</li>`).join('');
}
function selectAgent(id, alias, triggerRefresh = true) {
    document.getElementById('top_agent').value = id;
    document.getElementById('agent_search_input').value = alias;
    document.getElementById('agent_dropdown').style.display = 'none';
    if(triggerRefresh) forceRefresh();
    updateURLState(currentDashId, document.getElementById('top_group').value, id);
}

function onTimeRangeChange() {
    const val = document.getElementById('top_time').value;
    const customBox = document.getElementById('customTimeBox');
    if (val === 'custom') {
        customBox.style.display = 'flex';
        const now = new Date();
        const yesterday = new Date(now.getTime() - 24 * 60 * 60 * 1000);
        const pad = (n) => String(n).padStart(2, '0');
        const formatDT = (d) => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
        
        if (!document.getElementById('top_start').value) {
            document.getElementById('top_start').value = formatDT(yesterday);
        }
        if (!document.getElementById('top_end').value) {
            document.getElementById('top_end').value = formatDT(now);
        }
    } else {
        customBox.style.display = 'none';
        forceRefresh();
    }
}

function applyCustomTimeRange() {
    const s = document.getElementById('top_start').value;
    const e = document.getElementById('top_end').value;
    if (!s || !e) {
        alert('Please select both start and end times.');
        return;
    }
    const startTs = new Date(s).getTime();
    const endTs = new Date(e).getTime();
    if (startTs >= endTs) {
        alert('Start time must be before end time.');
        return;
    }
    forceRefresh();
}

function getTimeRange() {
    const val = document.getElementById('top_time').value;
    let end = Math.floor(Date.now() / 1000);
    if (val === 'custom') {
        const s = document.getElementById('top_start').value;
        const e = document.getElementById('top_end').value;
        return { start: Math.floor(new Date(s).getTime()/1000), end: Math.floor(new Date(e).getTime()/1000) };
    }
    return { start: end - parseInt(val), end: end };
}

function renderPanelsGrid() {
    const grid = document.getElementById('panelsGrid');
    grid.innerHTML = '';
    const currentDash = masterDashboards.find(d => d.id === currentDashId);
    if (!currentDash || !currentDash.panels) return;

    const urlParams = new URLSearchParams(window.location.search);
    const filterPanelId = urlParams.get('p') || urlParams.get('panel_id');

    currentDash.panels.forEach(p => {
        if (filterPanelId && p.id !== filterPanelId) return;
        if (p.hidden && !showHiddenPanels) return;

        const wrapper = document.createElement('div');
        wrapper.className = 'panel-rule-wrapper';
        wrapper.id = `wrapper_p_${p.id}`;
        wrapper.setAttribute('data-id', p.id);
        
        const wValue = parseInt(p.width) || 12;
        wrapper.style.setProperty('grid-column', `span ${wValue}`, 'important');
        
        const minH = parseInt(p.height) || 200;
        const hiddenClass = p.hidden ? 'is-hidden' : '';
        wrapper.innerHTML = `<div class="panel-card ${hiddenClass}" style="min-height:${minH}px;"><div class="loading-overlay" id="load_${p.id}"><div class="spinner"></div></div></div>`;
        grid.appendChild(wrapper);
    });

    if (!IS_STANDALONE && typeof Sortable !== 'undefined') {
        new Sortable(grid, {
            animation: 150,
            ghostClass: 'dragging-ghost',
            handle: '.drag-handle', 
            draggable: '.panel-rule-wrapper',
            onEnd: function (evt) {
                const currentDash = masterDashboards.find(d => d.id === currentDashId);
                if (!currentDash) return;
                
                const newOrder = [];
                grid.querySelectorAll('.panel-rule-wrapper').forEach(el => {
                    const pid = el.getAttribute('data-id');
                    const panel = currentDash.panels.find(x => x.id === pid);
                    if (panel) newOrder.push(panel);
                });
                
                currentDash.panels = newOrder;
                markUnsaved();
                forceRefresh();
            }
        });
    }
    setTimeout(resizeAllGridItems, 100);
}

function toggleHiddenVisibility() {
    showHiddenPanels = !showHiddenPanels;
    const btn = document.getElementById('btnToggleHidden');
    if (showHiddenPanels) {
        btn.classList.add('active');
        btn.querySelector('.material-symbols-outlined').innerText = 'visibility';
    } else {
        btn.classList.remove('active');
        btn.querySelector('.material-symbols-outlined').innerText = 'visibility_off';
    }
    renderPanelsGrid();
    forceRefresh();
}

function getPanelControlsHtml(p, moduleId = null) {
    if (IS_STANDALONE && p.id !== new URLSearchParams(window.location.search).get('panel_id')) return '';
    
    const isExcluded = moduleId && p.excluded && p.excluded.includes(moduleId);
    const isHidden = p.hidden || isExcluded;
    
    const hideIcon = isHidden ? 'visibility' : 'visibility_off';
    const hideTitle = isHidden ? 'Show' : 'Hide';
    const hideTarget = (moduleId !== null && moduleId !== undefined) ? `'${p.id}', '${moduleId}'` : `'${p.id}'`;
    return `
        <div class="panel-controls">
            <button class="icon-btn" onclick="quickTogglePanelHidden(${hideTarget})" title="${hideTitle}"><span class="material-symbols-outlined">${hideIcon}</span></button>
            <button class="icon-btn" onclick="duplicatePanel('${p.id}')" title="Duplicate"><span class="material-symbols-outlined">content_copy</span></button>
            <button class="icon-btn" onclick="openPanelEdit('${p.id}')" title="Settings"><span class="material-symbols-outlined">edit</span></button>
            <button class="icon-btn" onclick="deletePanel('${p.id}')" title="Delete"><span class="material-symbols-outlined" style="color:#e74c3c;">delete</span></button>
        </div>`;
}

function formatSmartValue(val, useRaw) {
    if (val === null || val === undefined || val === '') return 'N/A';
    if (useRaw) return val;
    let s = String(val).replace(',', '.');
    if (!isNaN(s) && s.trim() !== '') {
        let f = parseFloat(s);
        return (f % 1 === 0) ? f.toString() : Number(f.toFixed(2)).toString();
    }
    return val;
}

function evaluateModuleThresholdStatus(m, numericVal, moduleName = '', unit = '', contextTitle = '') {
    const parseNum = (v) => {
        if (v === null || v === undefined || v === '') return null;
        const n = parseFloat(String(v).replace(',', '.'));
        return isNaN(n) ? null : n;
    };

    const minCrit = parseNum(m.min_critical);
    const maxCrit = parseNum(m.max_critical);
    const minWarn = parseNum(m.min_warning);
    const maxWarn = parseNum(m.max_warning);

    const checkThresholdRange = (minVal, maxVal, val) => {
        const hasMin = (minVal !== null && minVal !== undefined);
        const hasMax = (maxVal !== null && maxVal !== undefined);
        if (!hasMin && !hasMax) return false;
        if (minVal === 0 && maxVal === 0) return false;

        if (hasMin && minVal > 0 && (!hasMax || maxVal === 0)) {
            return val >= minVal;
        }
        if (hasMax && maxVal > 0 && (!hasMin || minVal === 0)) {
            return val <= maxVal;
        }
        if (hasMin && hasMax) {
            if (minVal <= maxVal) {
                return val >= minVal && val <= maxVal;
            } else {
                return val <= maxVal || val >= minVal;
            }
        }
        return false;
    };

    // 1. Explicit Critical threshold
    if (checkThresholdRange(minCrit, maxCrit, numericVal)) {
        return { status: 1, label: 'Critical', color: '#ef4444' };
    }

    // 2. Explicit Warning threshold
    if (checkThresholdRange(minWarn, maxWarn, numericVal)) {
        return { status: 2, label: 'Warning', color: '#f59e0b' };
    }

    // 3. Native Pandora daemon state if already warning or critical
    const nativeState = parseInt(m.estado !== undefined ? m.estado : (m.status !== undefined ? m.status : -1));
    if (nativeState === 1) {
        return { status: 1, label: 'Critical', color: '#ef4444' };
    }
    if (nativeState === 2) {
        return { status: 2, label: 'Warning', color: '#f59e0b' };
    }

    // 4. Smart heuristic check for percentage / usage / load metrics when DB thresholds are unconfigured
    const combinedStr = `${moduleName || ''} ${unit || ''} ${contextTitle || ''}`.toLowerCase();
    const isPct = (unit && unit.includes('%')) || combinedStr.includes('%') || /cpu|usage|load|mem|disk|utilization|loss|drop/.test(combinedStr);
    const isFreeMetric = /free|avail|available/.test(combinedStr);

    if (isPct && !isNaN(numericVal)) {
        if (isFreeMetric) {
            if (numericVal <= 10) return { status: 1, label: 'Critical', color: '#ef4444' };
            if (numericVal <= 25) return { status: 2, label: 'Warning', color: '#f59e0b' };
        } else {
            if (numericVal >= 90) return { status: 1, label: 'Critical', color: '#ef4444' };
            if (numericVal >= 75) return { status: 2, label: 'Warning', color: '#f59e0b' };
        }
    }

    // 5. Default mapping
    if (nativeState === 0) return { status: 0, label: 'Normal (OK)', color: '#10b981' };
    if (nativeState === 4) return { status: 4, label: 'Not Initialized', color: '#38bdf8' };
    if (nativeState === 3) return { status: 3, label: 'Unknown', color: '#94a3b8' };

    return { status: 0, label: 'Normal (OK)', color: '#10b981' };
}

function generatePanelHtml(p, uniqueId, moduleData, isFirstInGroup, totalModulesInGroup) {
    const cMap = {0:'bg-green', 1:'bg-red', 2:'bg-yellow', 4:'bg-blue'};
    const bgClass = cMap[moduleData.status] || 'bg-gray';

    // Aggregation calculation (avg, max, min, summary)
    const valCalc = p.value_calc || 'current';
    let calcMin = null, calcMax = null, calcAvg = null;
    if (moduleData.history && Array.isArray(moduleData.history) && moduleData.history.length > 0) {
        const histNums = moduleData.history
            .map(h => (h && h.val !== undefined && h.val !== null) ? parseFloat(String(h.val).replace(',', '.')) : NaN)
            .filter(v => !isNaN(v));
        if (histNums.length > 0) {
            calcMin = Math.min(...histNums);
            calcMax = Math.max(...histNums);
            calcAvg = histNums.reduce((a, b) => a + b, 0) / histNums.length;
        }
    }

    let rawDisplay = moduleData.current;
    let badgeHtml = '';
    let summaryHtml = '';

    if (valCalc === 'avg') {
        if (calcAvg !== null) rawDisplay = (calcAvg % 1 === 0) ? calcAvg.toString() : Number(calcAvg.toFixed(2)).toString();
        badgeHtml = `<span class="badge-calc badge-calc-avg" style="display:inline-flex; align-items:center; font-size:9px; font-weight:700; color:#0d9488; background:#ccfbf1; border:1px solid rgba(13,148,136,0.3); padding:1px 5px; border-radius:4px; margin-left:4px; text-transform:uppercase; letter-spacing:0.5px; line-height:1.2; vertical-align:middle;" title="Average Value over active time range">AVG</span>`;
    } else if (valCalc === 'max') {
        if (calcMax !== null) rawDisplay = (calcMax % 1 === 0) ? calcMax.toString() : Number(calcMax.toFixed(2)).toString();
        badgeHtml = `<span class="badge-calc badge-calc-max" style="display:inline-flex; align-items:center; font-size:9px; font-weight:700; color:#b91c1c; background:#fee2e2; border:1px solid rgba(185,28,28,0.3); padding:1px 5px; border-radius:4px; margin-left:4px; text-transform:uppercase; letter-spacing:0.5px; line-height:1.2; vertical-align:middle;" title="Maximum Value over active time range">MAX</span>`;
    } else if (valCalc === 'min') {
        if (calcMin !== null) rawDisplay = (calcMin % 1 === 0) ? calcMin.toString() : Number(calcMin.toFixed(2)).toString();
        badgeHtml = `<span class="badge-calc badge-calc-min" style="display:inline-flex; align-items:center; font-size:9px; font-weight:700; color:#0369a1; background:#e0f2fe; border:1px solid rgba(3,105,161,0.3); padding:1px 5px; border-radius:4px; margin-left:4px; text-transform:uppercase; letter-spacing:0.5px; line-height:1.2; vertical-align:middle;" title="Minimum Value over active time range">MIN</span>`;
    } else if (valCalc === 'summary' && (calcMin !== null || calcAvg !== null || calcMax !== null)) {
        summaryHtml = `
        <div style="display:flex; gap:6px; margin-top:6px; flex-wrap:wrap; justify-content:center; align-items:center;">
            <span style="font-size:10px; font-weight:600; color:#0369a1; background:#e0f2fe; padding:2px 6px; border-radius:4px;" title="Minimum in active range">MIN: ${formatSmartValue(calcMin, p.use_raw)}</span>
            <span style="font-size:10px; font-weight:600; color:#0d9488; background:#ccfbf1; padding:2px 6px; border-radius:4px;" title="Average in active range">AVG: ${formatSmartValue(calcAvg, p.use_raw)}</span>
            <span style="font-size:10px; font-weight:600; color:#b91c1c; background:#fee2e2; padding:2px 6px; border-radius:4px;" title="Maximum in active range">MAX: ${formatSmartValue(calcMax, p.use_raw)}</span>
        </div>`;
    }

    let valText = formatSmartValue(rawDisplay, p.use_raw);
    if (p.lbl_1 && (rawDisplay == 1 || rawDisplay === '1')) valText = p.lbl_1;
    else if (p.lbl_0 && (rawDisplay == 0 || rawDisplay === '0')) valText = p.lbl_0;

    let contentHtml = '';
    const fs = p.font_size || 32;
    const fw = p.font_weight || 700;
    const showMod = p.show_module !== false; 
    const isMultiOverlay = moduleData.module_name === 'Multi-Module Overlay';
    const chartH = Math.max(220, (parseInt(p.height) || 260) - 20);
    const modNameHtml = (showMod && !isMultiOverlay) ? `<div class="mod-subtitle">${moduleData.module_name}</div>` : '';
    const statusHtml = isMultiOverlay ? '' : `<div style="display:flex; align-items:center;"><span class="status-dot ${bgClass}"></span><span style="font-size:${Math.round(fs*0.5)}px; font-weight:${fw};">${valText}</span><span style="font-size:10px; margin-left:3px;">${moduleData.unit}</span></div>`;

    if (p.type === 'text') {
        contentHtml = `<div style="display:flex; align-items:center; justify-content:center; flex-direction:column; height:100%; padding:10px;"><div style="display:flex; align-items:baseline; justify-content:center;"><span class="status-dot ${bgClass}"></span><span class="val-big" style="font-size:${fs}px; font-weight:${fw};">${valText}</span><span class="val-unit">${moduleData.unit}</span></div>${summaryHtml}${modNameHtml}</div>`;
    } 
    else if (p.type === 'single_value') {
        const color = {0:'#2ecc71', 1:'#e74c3c', 2:'#f1c40f', 4:'#3498db'}[moduleData.status] || '#95a5a6';
        contentHtml = `
        <div style="height: 100%; width: 100%; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden;">
            <div style="padding: 10px 10px 0 10px; z-index: 2;">
                <div style="font-size: ${fs}px; font-weight: ${fw}; color: ${color}; line-height: 1.1; display: flex; align-items: baseline; gap: 4px;">
                    <span>${valText}</span>
                    <span style="font-size: ${Math.round(fs * 0.45)}px; font-weight: normal; color: #64748b;">${moduleData.unit}</span>
                </div>
                ${summaryHtml}
            </div>
            <!-- Relative positioned ECharts Sparkline container -->
            <div id="chart_${uniqueId}" style="flex: 1; min-height: 60px; width: 100%; cursor: pointer;" onclick="openNativeModuleDetailModal('${moduleData.id}', '${(moduleData.agent_name + ' - ' + moduleData.module_name).replace(/'/g, "\\'")}')"></div>
            
            ${showMod ? `
            <div style="padding: 10px 10px 8px 10px; font-size: 11px; font-weight: 500; color: #64748b; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; z-index: 2;">
                ${moduleData.module_name}
            </div>
            ` : '<div style="height: 5px;"></div>'}
        </div>
        `;
    }
    else if (p.type === 'gauge') {
        contentHtml = `<div class="chart-wrapper"><div id="chart_${uniqueId}" style="width:100%; height:100%; min-height:100px;"></div><div class="gauge-text"><div><span class="gauge-val" style="font-size:${Math.round(fs*0.75)}px; font-weight:${fw};">${valText}</span><span class="val-unit">${moduleData.unit}</span></div>${modNameHtml}</div></div>`;
    }
    else if (p.type === 'table_viewer') {
        const tableFs = parseInt(p.font_size) || 11;
        const tableFw = p.font_weight || '400';
        contentHtml = `
            <div class="table-viewer-card-wrap" style="height:100%; display:flex; flex-direction:column; gap:10px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:4px;">
                    <div style="font-size:10px; color:#7f8c8d;">Last Updated: ${moduleData.last_contact ? new Date(moduleData.last_contact * 1000).toLocaleString() : '-'}</div>
                    <div class="search-box" style="width: 180px; position:relative;">
                        <input type="text" placeholder="Filter table..." class="form-control-fix" style="font-size:11px; padding: 4px 8px 4px 26px; height:24px; border-radius:4px; border: 1px solid #cbd5e1;" oninput="filterCardTableViewer('${uniqueId}', this.value)">
                        <span class="material-symbols-outlined" style="position:absolute; left:6px; top:50%; transform:translateY(-50%); font-size:14px; color:#94a3b8; line-height:1;">search</span>
                    </div>
                </div>
                <div class="table-scroll-wrapper" style="overflow-x:auto; overflow-y:auto; flex-grow:1; max-height:${chartH}px; border: 1px solid #e2e8f0; border-radius:6px; background:#fff;">
                    <table class="table-pfms" id="table_${uniqueId}" style="margin:0; font-size:${tableFs}px; font-weight:${tableFw}; width:100%;">
                        <thead id="thead_${uniqueId}"></thead>
                        <tbody id="tbody_${uniqueId}"></tbody>
                    </table>
                    <div id="raw_${uniqueId}" class="d-none" style="padding:10px; background:#1e293b; color:#e2e8f0; font-family:monospace; font-size:${tableFs}px; font-weight:${tableFw}; white-space:pre-wrap;"></div>
                </div>
            </div>
        `;
    }
    else if (p.type === 'heatmap') {
        const sizeMap = { 'small': '40px', 'medium': '80px', 'large': '140px', 'xl': '220px' };
        const h = sizeMap[p.box_size] || '80px';
        const history = moduleData.history || [];
        
        if (history.length === 0) {
            contentHtml = `<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:${h}; color:#bdc3c7; font-size:11px;"><span class="material-symbols-outlined" style="font-size:24px; margin-bottom:5px;">history</span>No History Data</div>`;
        } else {
            contentHtml = `<div class="heatmap-wrap" style="max-height:${h};">${history.map(h => {
                const val = parseFloat(h.val);
                let color = '#e2e8f0'; 
                if (val === 0) color = '#2ecc71'; 
                else if (val === 1) color = '#e74c3c'; 
                else if (val === 2) color = '#f1c40f'; 
                else if (val > 0) color = '#2ecc71'; 
                
                return `<div class="heat-block" style="background:${color};" title="${h.lbl}: ${h.val}"></div>`;
            }).join('')}</div>`;
        }
    }
    else {
        const history = moduleData.history || [];
        if (history.length === 0 && ['line','area','bar'].includes(p.type)) {
            contentHtml = `<div style="display:flex; justify-content:space-between; align-items:center; width:100%;">${modNameHtml}${statusHtml}</div><div class="chart-wrapper" style="color:#bdc3c7; font-size:11px; flex-direction:column;"><span class="material-symbols-outlined" style="font-size:24px; margin-bottom:5px;">history_toggle_off</span>No historical data</div>`;
        } else {
            let chartHtml = '';
            if (p.chart_engine === 'native') {
                const url = `${PANDORA_URL}/operation/agentes/stat_win.php?type=sparse&period=86400&id=${moduleData.id}&refresh=600&pure=1&draw_events=0&period_graph=0`;
                chartHtml = `
                <div class="chart-wrapper" style="height:230px; overflow:hidden; border-radius:4px; position:relative; background:#fff;">
                    <div id="loader_${uniqueId}" style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; z-index:1; background:#fff;">
                        <div class="pf-spinner" style="width:30px; height:30px; border:3px solid #f3f3f3; border-top:3px solid #2ecc71; border-radius:50%; animation: pf-spin 1s linear infinite;"></div>
                        <span style="font-size:10px; color:#95a5a6; margin-top:8px;">Synchronizing...</span>
                    </div>
                    
                    <iframe src="${url}" 
                        id="ifrm_${uniqueId}"
                        style="width:100%; height:280px; border:none; background:#fff; opacity:0; transition:opacity 0.3s; zoom: 0.85;" 
                        onload="try { 
                            const d = this.contentDocument || this.contentWindow.document;
                            const style = d.createElement('style');
                            style.textContent = '#summary-table-graph, .p_filter, .filter-menu-title-tabs, #filter-menu-stats-win-summary, .chart-control-buttons, .chart-watermark { display: none !important; }';
                            d.head.appendChild(style);
                            d.body.style.overflow = 'hidden';
                            d.body.style.padding = '0';
                            d.body.style.margin = '0';
                        } catch(e) {} 
                        this.style.opacity = '1';
                        const ldr = document.getElementById('loader_${uniqueId}');
                        if(ldr) ldr.style.display = 'none';">
                    </iframe>
                </div>`;
            } else {
                chartHtml = `<div class="chart-wrapper" style="min-height:${chartH}px; display:flex; flex-direction:column;"><div id="chart_${uniqueId}" style="width:100%; height:260px; min-height:200px;"></div><div id="chart_legend_${uniqueId}" class="chart-html-legend" style="display:flex; flex-wrap:wrap; align-items:center; gap:4px 10px; max-height:75px; overflow-y:auto; padding:4px 2px; margin-top:4px; border-top:1px solid #f1f5f9;"></div></div>`;
            }

            contentHtml = `<div style="display:flex; justify-content:space-between; align-items:center; width:100%;">${modNameHtml}${statusHtml}</div>${chartHtml}`;
        }
    }

    const controlsHtml = getPanelControlsHtml(p, moduleData.id);
    const isExcluded = p.excluded && p.excluded.map(String).includes(String(moduleData.id));
    const hiddenClass = (p.hidden || isExcluded) ? 'is-hidden' : '';

    let viewBtnHtml = '';
    if (['line', 'area', 'bar', 'history_table', 'single_value'].includes(p.type) && moduleData && moduleData.id) {
        viewBtnHtml = `
            <button class="panel-view-btn" onclick="openPanelDetailModal('${p.id}', '${moduleData.id}')" title="View Detail Chart" style="border:none; background:transparent; padding:0; cursor:pointer; color:#1976d2; margin-left:6px; display:inline-flex; align-items:center; vertical-align:middle;">
                <span class="material-symbols-outlined" style="font-size:16px!important;">monitoring</span>
            </button>
        `;
    }

    let displayTitle = p.title || '';
    if (valCalc === 'min' || valCalc === 'max' || valCalc === 'avg') {
        displayTitle = displayTitle.replace(new RegExp(`\\s*[\\(\\[]?${valCalc}[\\)\\]]?\\s*$`, 'i'), '').trim();
    }

    return `<div class="panel-card ${hiddenClass}" style="height: 100%; ${p.height ? 'min-height:' + p.height + 'px;' : ''} margin:0;"><div class="panel-header"><div style="display:flex; align-items:center; flex:1; min-width:0; overflow:hidden;"><h6 class="panel-title" style="display:inline-flex; align-items:center; flex-wrap:wrap; gap:4px; max-width:100%;"><span class="material-symbols-outlined drag-handle" style="font-size:16px; cursor:grab; margin-right:2px; color:#b5c1c9; vertical-align:middle;" title="Drag to reorder">drag_indicator</span><span>${displayTitle}</span>${badgeHtml}${viewBtnHtml}</h6></div>${controlsHtml}</div><div class="panel-body">${contentHtml}</div></div>`;
}

function generateSummaryPanelHtml(p, modules) {
    const controlsHtml = getPanelControlsHtml(p);
    let content = '';
    const stats = { total: modules.length, up: 0, crit: 0, warn: 0, unknown: 0, not_init: 0 };
    modules.forEach(m => {
        if (m.status === 0) stats.up++;
        else if (m.status === 1) stats.crit++;
        else if (m.status === 2) stats.warn++;
        else if (m.status === 4) stats.not_init++;
        else stats.unknown++;
    });

    if (p.type === 'status_stats') {
        const statBg = p.stat_bg_color ? `background: ${p.stat_bg_color} !important;` : '';
        const statValColor = p.stat_font_color ? `color: ${p.stat_font_color} !important;` : '';
        const statLblColor = p.stat_font_color ? `color: ${p.stat_font_color} !important; opacity: 0.75;` : '';
        const valFs = p.font_size ? `font-size: ${p.font_size}px !important;` : '';
        const valFw = p.font_weight ? `font-weight: ${p.font_weight} !important;` : '';
        const lblFs = p.font_size ? `font-size: ${Math.max(8, Math.round(p.font_size * 0.4))}px !important;` : '';
        const cardMinH = p.height ? `min-height: ${Math.max(50, parseInt(p.height) - 70)}px;` : '';
        const statCardStyle = `${statBg} ${cardMinH} ${p.height ? 'display:flex; flex-direction:column; justify-content:center; align-items:center;' : ''}`;

        content = `
            <div class="mini-stats-row">
                <div class="mini-stat st-black" style="${statCardStyle}" onclick="showStatusDetails('${p.id}', -1, 'TOTAL')">
                    <div class="mini-stat-val" style="${statValColor || 'color:#333;'} ${valFs} ${valFw}">${stats.total}</div>
                    <div class="mini-stat-label" style="${statLblColor} ${lblFs}">TOTAL</div>
                </div>
                <div class="mini-stat st-green" style="${statCardStyle}" onclick="showStatusDetails('${p.id}', 0, 'UP')">
                    <div class="mini-stat-val" style="${statValColor || 'color:#2ecc71;'} ${valFs} ${valFw}">${stats.up}</div>
                    <div class="mini-stat-label" style="${statLblColor} ${lblFs}">UP / OK</div>
                </div>
                <div class="mini-stat st-red" style="${statCardStyle}" onclick="showStatusDetails('${p.id}', 1, 'CRITICAL')">
                    <div class="mini-stat-val" style="${statValColor || 'color:#e74c3c;'} ${valFs} ${valFw}">${stats.crit}</div>
                    <div class="mini-stat-label" style="${statLblColor} ${lblFs}">CRITICAL</div>
                </div>
                <div class="mini-stat st-yellow" style="${statCardStyle}" onclick="showStatusDetails('${p.id}', 2, 'WARNING')">
                    <div class="mini-stat-val" style="${statValColor || 'color:#f1c40f;'} ${valFs} ${valFw}">${stats.warn}</div>
                    <div class="mini-stat-label" style="${statLblColor} ${lblFs}">WARNING</div>
                </div>
                <div class="mini-stat st-gray" style="${statCardStyle}" onclick="showStatusDetails('${p.id}', 3, 'UNKNOWN')">
                    <div class="mini-stat-val" style="${statValColor || 'color:#94a3b8;'} ${valFs} ${valFw}">${stats.unknown}</div>
                    <div class="mini-stat-label" style="${statLblColor} ${lblFs}">UNKNOWN</div>
                </div>
                <div class="mini-stat st-blue" style="${statCardStyle}" onclick="showStatusDetails('${p.id}', 4, 'NOT INIT')">
                    <div class="mini-stat-val" style="${statValColor || 'color:#3498db;'} ${valFs} ${valFw}">${stats.not_init}</div>
                    <div class="mini-stat-label" style="${statLblColor} ${lblFs}">NOT INIT</div>
                </div>
            </div>`;
    } else if (p.type === 'status_table') {
        const visibleCols = p.visible_columns || ['agent', 'group', 'ip', 'module', 'status', 'history', 'threshold'];
        const limit = parseInt(p.row_limit) || 200;
        
        // Calculate pagination slices
        const totalItems = modules.length;
        const totalPages = Math.ceil(totalItems / limit) || 1;
        window.tableCurrentPages = window.tableCurrentPages || {};
        const currentPage = window.tableCurrentPages[p.id] || 1;
        const actualPage = Math.min(currentPage, totalPages);
        window.tableCurrentPages[p.id] = actualPage; // clamp page
        
        const startIdx = (actualPage - 1) * limit;
        const endIdx = Math.min(startIdx + limit, totalItems);
        const displayModules = modules.slice(startIdx, endIdx);
        
        let headerRow = '';
        if (visibleCols.includes('agent')) headerRow += '<th>Node Agent</th>';
        if (visibleCols.includes('group')) headerRow += '<th>Group</th>';
        if (visibleCols.includes('ip')) headerRow += '<th>IP Address</th>';
        if (visibleCols.includes('module')) headerRow += '<th>Module</th>';
        if (visibleCols.includes('status')) headerRow += '<th style="text-align:center;">Status / Value</th>';
        if (visibleCols.includes('history')) headerRow += '<th style="text-align:center;">Metrics History</th>';
        if (visibleCols.includes('threshold')) headerRow += '<th>Threshold</th>';

        let paginationHtml = '';
        if (totalPages > 1) {
            paginationHtml = `
                <div class="pagination-container-dyn" style="display:flex; justify-content:space-between; align-items:center; padding:10px 15px; border-top:1px solid #f0f3f5; font-size:11px; color:#64748b; background:#fff;">
                    <div>Showing ${startIdx + 1} to ${endIdx} of ${totalItems} Entries</div>
                    <div style="display:flex; gap:10px;">
                        <button class="pagination-btn-dyn" style="padding:4px 8px; border:1px solid #dce1e5; border-radius:4px; background:#fff; cursor:pointer; font-size:11px; color:#475569;" ${actualPage === 1 ? 'disabled style="opacity:0.5; cursor:default;"' : `onclick="changeTablePage('${p.id}', ${actualPage - 1})"`}>Prev</button>
                        <span style="font-size:11px; font-weight: 600; align-self:center; color:#475569;">Page ${actualPage} / ${totalPages}</span>
                        <button class="pagination-btn-dyn" style="padding:4px 8px; border:1px solid #dce1e5; border-radius:4px; background:#fff; cursor:pointer; font-size:11px; color:#475569;" ${actualPage === totalPages ? 'disabled style="opacity:0.5; cursor:default;"' : `onclick="changeTablePage('${p.id}', ${actualPage + 1})"`}>Next</button>
                    </div>
                </div>
            `;
        }

        content = `
            <div class="table-wrap-dyn">
                <table class="table-pfms">
                    <thead><tr>${headerRow}</tr></thead>
                    <tbody>
                        ${displayModules.map(m => {
                            const bgClass = {0:'bg-green', 1:'bg-red', 2:'bg-yellow', 4:'bg-blue'}[m.status] || 'bg-gray';
                            const statusLbl = {0:'UP', 1:'CRITICAL', 2:'WARNING', 4:'NOT INIT'}[m.status] || 'UNKNOWN';
                            const cleanVal = formatSmartValue(m.current, p.use_raw);
                            
                            let rowHtml = '<tr>';
                            if (visibleCols.includes('agent')) {
                                const isPrimary = String(m.agent_id).startsWith(PRIMARY_UUID + ':');
                                let agentLinkHtml = '';
                                if (isPrimary) {
                                    const rawAgentId = String(m.agent_id).split(':')[1] || m.agent_id;
                                    agentLinkHtml = `<a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${rawAgentId}" target="_blank" style="color:#1976d2; font-weight:600; text-decoration:none;">${m.agent_name}</a>`;
                                } else {
                                    agentLinkHtml = `<span style="color:#334155; font-weight:600;">${m.agent_name}</span>`;
                                }
                                rowHtml += `<td>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <span class="status-dot ${bgClass}" style="margin:0; width:8px; height:8px;"></span>
                                        ${agentLinkHtml}
                                    </div>
                                </td>`;
                            }
                            if (visibleCols.includes('group')) {
                                rowHtml += `<td>${m.group_name}</td>`;
                            }
                            if (visibleCols.includes('ip')) {
                                rowHtml += `<td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px; font-family:monospace; color:#e74c3c;">${m.ip_address || '-'}</code></td>`;
                            }
                            if (visibleCols.includes('module')) {
                                let lastContactStr = m.last_contact ? new Date(m.last_contact * 1000).toLocaleString('id-ID', {hour12:false}) : 'Awaiting';
                                rowHtml += `<td>
                                    <div style="font-weight:500; color:#0b1a26; margin-bottom:2px; word-break: break-word;">${m.module_name}</div>
                                    <div style="font-size:10px; color:#7f8c8d;">Update: ${lastContactStr}</div>
                                </td>`;
                            }
                            if (visibleCols.includes('status')) {
                                const rawValStr = String(m.current || '');
                                const cleanValStr = String(cleanVal);
                                if (cleanValStr.length > 45 || cleanValStr.includes('|') || cleanValStr.includes('\n')) {
                                    rowHtml += `<td style="text-align:center;">
                                        <button class="status-pill-dyn ${bgClass}" style="color:#fff!important; border:none; padding: 4px 10px; font-weight:600; display:inline-block; border-radius:4px; font-size:11px; cursor:pointer; transition: opacity 0.2s;" 
                                            onclick="showLongValuePopup('${m.module_name.replace(/'/g, "\\'")}', '${m.agent_name.replace(/'/g, "\\'")}', \`${rawValStr.replace(/`/g, "\\`").replace(/\$/g, "\\$")}\`)"
                                            onmouseenter="this.style.opacity=0.8" onmouseleave="this.style.opacity=1">
                                            View Value
                                        </button>
                                    </td>`;
                                } else {
                                    rowHtml += `<td style="text-align:center;">
                                        <span class="status-pill-dyn ${bgClass}" style="color:#fff!important; border:none; padding: 4px 10px; font-weight:600; display:inline-block; border-radius:4px; font-size:11px; white-space:nowrap; max-width:100%; overflow:hidden; text-overflow:ellipsis;">
                                            ${cleanVal} ${m.unit}
                                        </span>
                                    </td>`;
                                }
                            }
                            if (visibleCols.includes('history')) {
                                rowHtml += `<td style="text-align:center;">
                                    <div style="display:flex; flex-wrap:wrap; gap:6px; align-items:center; justify-content:center; width:100%;">
                                        <button class="icon-btn" style="padding:0; margin:0; background:none; border:none; cursor:pointer;" onclick="openNativeChartModal('${m.id}', '${m.agent_name.replace(/'/g, "\\'")} - ${m.module_name.replace(/'/g, "\\'")}', '${m.agent_id}')" title="View Chart">
                                            <span class="material-symbols-outlined" style="font-size:16px!important; color:#1976d2;">monitoring</span>
                                        </button>
                                        <button class="icon-btn" style="padding:0; margin:0; background:none; border:none; cursor:pointer;" onclick="show_module_detail_dialog('${m.id}', '${m.agent_id}', 'data', 0, 86400, '${m.module_name.replace(/'/g, "\\'")}')" title="View Data Table">
                                            <span class="material-symbols-outlined" style="font-size:16px!important; color:#2e7d32;">table_chart</span>
                                        </button>
                                    </div>
                                </td>`;
                            }
                            if (visibleCols.includes('threshold')) {
                                let unitStr = m.unit ? ` ${m.unit}` : '';
                                rowHtml += `<td>
                                    <div style="font-size:11px; color:#64748b;">Min: <strong style="color:#475569;">${m.min}${unitStr}</strong></div>
                                    <div style="font-size:11px; color:#64748b;">Max: <strong style="color:#e74c3c;">${m.max}${unitStr}</strong></div>
                                </td>`;
                            }
                            rowHtml += '</tr>';
                            return rowHtml;
                        }).join('')}
                    </tbody>
                </table>
            </div>
            ${paginationHtml}`;
    } else if (p.type === 'status_heatmap') {
        const boxSize = p.box_size || 'medium';
        const textType = p.heatmap_text_type || 'value';
        const customTpl = p.heatmap_custom_text || '';
        const customFs = parseInt(p.font_size) || 0;
        const fw = p.font_weight || '600';

        let s = { w: '52px', h: '32px', f: '10px', isBar: false };
        if (boxSize === 'rect_sm') { s = { w: '110px', h: '28px', f: '10px', isBar: true }; }
        else if (boxSize === 'rect_md') { s = { w: '160px', h: '34px', f: '11px', isBar: true }; }
        else if (boxSize === 'rect_lg') { s = { w: '220px', h: '44px', f: '12px', isBar: true }; }
        else if (boxSize === 'rect_xl') { s = { w: '280px', h: '54px', f: '13px', isBar: true }; }
        else if (boxSize === 'small') { s = { w: '36px', h: '24px', f: '8px', isBar: false }; }
        else if (boxSize === 'medium') { s = { w: '52px', h: '32px', f: '10px', isBar: false }; }
        else if (boxSize === 'large') { s = { w: '80px', h: '50px', f: '12px', isBar: false }; }
        else if (boxSize === 'xl') { s = { w: '120px', h: '72px', f: '14px', isBar: false }; }
        else if (boxSize === 'custom') {
            const cW = p.heatmap_custom_width ? `${parseInt(p.heatmap_custom_width)}px` : '160px';
            const cH = p.heatmap_custom_height ? `${parseInt(p.heatmap_custom_height)}px` : '34px';
            s = { w: cW, h: cH, f: '11px', isBar: true };
        }

        const fontSizeStyle = customFs ? `${customFs}px` : s.f;

        content = `
            <div class="heatmap-grid-dyn" style="display:flex; flex-wrap:wrap; gap:6px; width:100%; margin-top:5px;">
                ${modules.map(m => {
                    const bgClass = {0:'bg-green', 1:'bg-red', 2:'bg-yellow', 4:'bg-blue'}[m.status] || 'bg-gray';
                    let cleanVal = formatSmartValue(m.current, p.use_raw);
                    let unitStr = m.unit ? ` ${m.unit}` : '';

                    let label = cleanVal;
                    if (textType === 'agent') {
                        label = m.agent_name || m.agent_db_name || m.ip_address || m.module_name;
                    } else if (textType === 'module') {
                        label = m.module_name;
                    } else if (textType === 'agent_module') {
                        label = `${m.agent_name || ''} - ${m.module_name}`;
                    } else if (textType === 'ip') {
                        label = m.ip_address || m.agent_name || m.module_name;
                    } else if (textType === 'value') {
                        label = (!s.isBar && String(cleanVal).length > 8) ? String(cleanVal).substring(0, 8) : `${cleanVal}${unitStr}`;
                    } else if (textType === 'agent_value') {
                        label = `${m.agent_name} (${cleanVal}${unitStr})`;
                    } else if (textType === 'custom') {
                        if (customTpl) {
                            label = customTpl
                                .replace(/\{agent\}/gi, m.agent_name || m.agent_db_name || '')
                                .replace(/\{module\}/gi, m.module_name || '')
                                .replace(/\{ip\}/gi, m.ip_address || '')
                                .replace(/\{value\}/gi, cleanVal !== null && cleanVal !== undefined ? cleanVal : '')
                                .replace(/\{unit\}/gi, m.unit || '');
                        } else {
                            label = m.agent_name || m.module_name;
                        }
                    }

                    const titleTip = `Agent: ${m.agent_name}\nIP: ${m.ip_address || '-'}\nModule: ${m.module_name}\nValue: ${m.current} ${m.unit}`;

                    return `<button class="heat-box-dyn ${bgClass}" style="min-width:${s.w}; max-width:${s.isBar ? '100%' : s.w}; height:${s.h}; font-size:${fontSizeStyle}; font-weight:${fw}!important; padding:0 ${s.isBar ? '10px' : '4px'}; border-radius:4px; display:inline-flex; align-items:center; justify-content:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; box-sizing:border-box;" title="${escapeHtml(titleTip)}" onclick="openNativeChart('${m.id}', '${(m.module_name || '').replace(/'/g, "\\'")}', '${m.agent_id}')"><span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; width:100%; text-align:center;">${escapeHtml(label)}</span></button>`;
                }).join('')}
            </div>`;
    } else if (p.type === 'pie' || p.type === 'donut') {
        const chartH = Math.max(120, (parseInt(p.height) || 200) - 60);
        content = `
            <div style="position:relative; width:100%; height:${chartH}px; display:flex; justify-content:center; align-items:center;">
                <div id="chart_${p.id}" style="width:100%; height:100%;"></div>
            </div>`;
    } else if (p.type === 'history_table') {
        const autoConvert = (p.auto_convert_traffic !== undefined)
            ? (p.auto_convert_traffic === true || p.auto_convert_traffic === 1 || p.auto_convert_traffic === '1' || p.auto_convert_traffic === 'true')
            : true;

        let combinedHistory = [];
        modules.forEach(m => {
            const history = m.history || [];
            const isTraffic = isTrafficMetric(m.module_name, m.unit, p.title);
            const isByte = isTraffic && isByteTrafficMetric(m.module_name, m.unit, p.title);
            const effectiveTraffic = (p.use_raw !== true) && autoConvert && isTraffic;

            history.forEach(h => {
                let valNum = parseFloat(h.val);
                let numericVal = isNaN(valNum) ? 0 : valNum;
                const isPctMod = (m.unit === '%' || (m.module_name && (m.module_name.includes('Load') || m.module_name.includes('Used'))) || p.title.toLowerCase().includes('utiliz'));
                if (isPctMod && numericVal > 100) {
                    numericVal = 100;
                }
                let bitVal = (effectiveTraffic && isByte) ? (numericVal * 8) : numericVal;
                let displayVal = '';

                if (effectiveTraffic) {
                    displayVal = formatBitsRate(bitVal);
                } else if (p.use_raw) {
                    displayVal = (numericVal % 1 === 0 ? numericVal : numericVal.toFixed(2)) + (m.unit ? ` ${m.unit}` : '');
                } else {
                    displayVal = formatHumanMetric(numericVal, m.unit, false, m.module_name, p.title);
                }

                combinedHistory.push({
                    ts: h.ts,
                    lbl: h.lbl,
                    agent_name: m.agent_name || '',
                    module_name: m.module_name || '',
                    val: h.val,
                    numericVal: numericVal,
                    bitVal: bitVal,
                    displayVal: displayVal,
                    isTraffic: effectiveTraffic,
                    unit: m.unit || ''
                });
            });
        });

        window.dynamicHistorySort = window.dynamicHistorySort || {};
        if (!window.dynamicHistorySort[p.id]) {
            const initSort = p.history_sort || 'time_desc';
            const parts = initSort.split('_');
            window.dynamicHistorySort[p.id] = {
                col: parts[0] || 'time',
                order: parts[1] || 'desc'
            };
        }
        const currentSort = window.dynamicHistorySort[p.id];

        combinedHistory.sort((a, b) => {
            let diff = 0;
            if (currentSort.col === 'val') {
                const vA = a.isTraffic ? a.bitVal : a.numericVal;
                const vB = b.isTraffic ? b.bitVal : b.numericVal;
                diff = vA - vB;
            } else if (currentSort.col === 'time') {
                diff = a.ts - b.ts;
            } else if (currentSort.col === 'agent') {
                diff = (a.agent_name || '').localeCompare(b.agent_name || '', undefined, { numeric: true, sensitivity: 'base' });
            } else if (currentSort.col === 'mod') {
                diff = (a.module_name || '').localeCompare(b.module_name || '', undefined, { numeric: true, sensitivity: 'base' });
            }
            return (currentSort.order === 'desc') ? -diff : diff;
        });
        
        const limit = parseInt(p.row_limit) || 15;
        const totalItems = combinedHistory.length;
        const totalPages = Math.ceil(totalItems / limit) || 1;
        
        window.tableCurrentPages = window.tableCurrentPages || {};
        const currentPage = window.tableCurrentPages[p.id] || 1;
        const actualPage = Math.min(Math.max(1, currentPage), totalPages);
        window.tableCurrentPages[p.id] = actualPage;
        
        const startIdx = (actualPage - 1) * limit;
        const endIdx = Math.min(startIdx + limit, totalItems);
        const paginatedHistory = combinedHistory.slice(startIdx, endIdx);

        let tableHeightStyle = '';
        if (p.height && parseInt(p.height) > 0) {
            tableHeightStyle = `max-height: ${Math.max(120, parseInt(p.height) - 90)}px; overflow-y: auto;`;
        } else {
            const estimatedHeight = Math.min(650, Math.max(200, (limit * 38) + 45));
            tableHeightStyle = `max-height: ${estimatedHeight}px; overflow-y: auto;`;
        }

        if (combinedHistory.length === 0) {
            content = `<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:160px; color:#bdc3c7; font-size:11px;"><span class="material-symbols-outlined" style="font-size:24px; margin-bottom:5px;">history</span>No History Data</div>`;
        } else {
            const getSortIcon = (col) => {
                if (currentSort.col === col) {
                    return currentSort.order === 'desc'
                        ? '<span style="font-size:11px; margin-left:4px; color:#0284c7; display:inline-block;">▼</span>'
                        : '<span style="font-size:11px; margin-left:4px; color:#0284c7; display:inline-block;">▲</span>';
                }
                return '<span style="font-size:11px; margin-left:4px; color:#cbd5e1; display:inline-block;">⇅</span>';
            };

            let paginationHtml = '';
            if (totalPages > 1) {
                paginationHtml = `
                    <div class="pagination-container-dyn" style="display:flex; justify-content:space-between; align-items:center; padding:10px 15px; border-top:1px solid #f0f3f5; font-size:11px; color:#64748b; background:#fff; margin-top:8px;">
                        <div>Showing ${startIdx + 1} to ${endIdx} of ${totalItems} Entries</div>
                        <div style="display:flex; gap:10px;">
                            <button class="pagination-btn-dyn" style="padding:4px 8px; border:1px solid #dce1e5; border-radius:4px; background:#fff; cursor:pointer; font-size:11px; color:#475569;" ${actualPage === 1 ? 'disabled style="opacity:0.5; cursor:default;"' : `onclick="changeTablePage('${p.id}', ${actualPage - 1})"`}>Prev</button>
                            <span style="font-size:11px; font-weight: 600; align-self:center; color:#475569;">Page ${actualPage} / ${totalPages}</span>
                            <button class="pagination-btn-dyn" style="padding:4px 8px; border:1px solid #dce1e5; border-radius:4px; background:#fff; cursor:pointer; font-size:11px; color:#475569;" ${actualPage === totalPages ? 'disabled style="opacity:0.5; cursor:default;"' : `onclick="changeTablePage('${p.id}', ${actualPage + 1})"`}>Next</button>
                        </div>
                    </div>
                `;
            }

            content = `
                <div class="table-scroll-wrapper" style="${tableHeightStyle} border: 1px solid #e2e8f0; border-radius:6px; background:#fff; width:100%;">
                    <table class="table-pfms" style="margin:0; font-size:11px; width:100%;">
                        <thead>
                            <tr style="position:sticky; top:0; background:#f8fafc; z-index:2; box-shadow: 0 1px 0 #e2e8f0;">
                                <th onclick="toggleDynamicHistorySort('${p.id}', 'time')" title="Click to sort by Timestamp" style="padding:8px 12px; text-align:left; font-weight:600; color:#475569; cursor:pointer; user-select:none; white-space:nowrap;">
                                    TIMESTAMP ${getSortIcon('time')}
                                </th>
                                <th onclick="toggleDynamicHistorySort('${p.id}', 'agent')" title="Click to sort by Agent Name" style="padding:8px 12px; text-align:left; font-weight:600; color:#475569; cursor:pointer; user-select:none; white-space:nowrap;">
                                    AGENT NAME ${getSortIcon('agent')}
                                </th>
                                <th onclick="toggleDynamicHistorySort('${p.id}', 'mod')" title="Click to sort by Module Name" style="padding:8px 12px; text-align:left; font-weight:600; color:#475569; cursor:pointer; user-select:none; white-space:nowrap;">
                                    MODULE NAME ${getSortIcon('mod')}
                                </th>
                                <th onclick="toggleDynamicHistorySort('${p.id}', 'val')" title="Click to sort by Highest / Lowest Value" style="padding:8px 12px; text-align:right; font-weight:600; color:#475569; cursor:pointer; user-select:none; white-space:nowrap;">
                                    VALUE ${getSortIcon('val')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            ${paginatedHistory.map(h => `
                                <tr>
                                    <td style="padding:8px 12px; color:#475569; border-bottom:1px solid #f1f5f9; white-space:nowrap;">${h.lbl}</td>
                                    <td style="padding:8px 12px; color:#475569; border-bottom:1px solid #f1f5f9; font-weight:500;">${h.agent_name}</td>
                                    <td style="padding:8px 12px; color:#475569; border-bottom:1px solid #f1f5f9; font-weight:500;">${h.module_name}</td>
                                    <td style="padding:8px 12px; text-align:right; font-weight:600; color:#0f172a; border-bottom:1px solid #f1f5f9; white-space:nowrap;">${h.displayVal}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                ${paginationHtml}
            `;
        }
    } else if (p.type === 'sparkline_table') {
        const autoConvert = (p.auto_convert_traffic !== undefined)
            ? (p.auto_convert_traffic === true || p.auto_convert_traffic === 1 || p.auto_convert_traffic === '1' || p.auto_convert_traffic === 'true')
            : true;

        let trendColTitle = 'Trend Over Time';
        if (p.title) {
            const cleanTitle = p.title.replace(/top\s+/i, '').replace(/leaderboard\s+/i, '').trim();
            if (/cpu/i.test(cleanTitle)) trendColTitle = 'CPU Over Time';
            else if (/mem/i.test(cleanTitle)) trendColTitle = 'Memory Over Time';
            else if (/session/i.test(cleanTitle)) trendColTitle = 'Sessions Over Time';
            else if (/traffic|bandwidth/i.test(cleanTitle)) trendColTitle = 'Traffic Over Time';
            else if (/latency|ping|rtt/i.test(cleanTitle)) trendColTitle = 'Latency Over Time';
            else trendColTitle = `${cleanTitle} Over Time`;
        }

        let items = modules.map(m => {
            const history = m.history || [];
            let valNum = parseFloat(String(m.current).replace(',', '.'));
            let numericVal = isNaN(valNum) ? 0 : valNum;

            const isTraffic = isTrafficMetric(m.module_name, m.unit, p.title);
            const isByte = isTraffic && isByteTrafficMetric(m.module_name, m.unit, p.title);
            const effectiveTraffic = (p.use_raw !== true) && autoConvert && isTraffic;
            const bitVal = (effectiveTraffic && isByte) ? (numericVal * 8) : numericVal;

            let displayVal = '';
            if (effectiveTraffic) {
                displayVal = formatBitsRate(bitVal);
            } else if (p.use_raw) {
                displayVal = (numericVal % 1 === 0 ? numericVal : numericVal.toFixed(2)) + (m.unit ? ` ${m.unit}` : '');
            } else {
                displayVal = formatHumanMetric(numericVal, m.unit, false, m.module_name, p.title);
            }

            const agentIp = (m.ip_address && m.ip_address !== 'N/A' && m.ip_address !== '127.0.0.1') ? m.ip_address : '';
            const agentName = m.agent_name || agentIp || 'Node';
            const moduleName = m.module_name || 'Module';

            const statusEval = evaluateModuleThresholdStatus(m, numericVal, m.module_name, m.unit, p.title);
            const statusColor = statusEval.color;
            const statusLabel = statusEval.label;

            return {
                id: m.id,
                agent_id: m.agent_id,
                agent_name: agentName,
                ip_address: agentIp,
                module_name: moduleName,
                rawVal: numericVal,
                sortVal: effectiveTraffic ? bitVal : numericVal,
                displayVal: displayVal,
                status: statusEval.status,
                statusColor: statusColor,
                statusLabel: statusLabel,
                history: history,
                unit: m.unit || ''
            };
        });

        const hideSearch = (p.hide_search_bar === true || p.hide_search_bar === 1 || p.hide_search_bar === '1' || p.hide_search_bar === 'true');
        window.sparklineTableSearch = window.sparklineTableSearch || {};
        if (hideSearch) {
            window.sparklineTableSearch[p.id] = '';
        }
        const searchKw = (window.sparklineTableSearch[p.id] || '').toLowerCase().trim();
        if (searchKw) {
            items = items.filter(it => 
                it.agent_name.toLowerCase().includes(searchKw) || 
                it.ip_address.toLowerCase().includes(searchKw) ||
                it.module_name.toLowerCase().includes(searchKw) ||
                it.displayVal.toLowerCase().includes(searchKw)
            );
        }

        window.sparklineTableSort = window.sparklineTableSort || {};
        if (!window.sparklineTableSort[p.id]) {
            window.sparklineTableSort[p.id] = { col: 'val', order: 'desc' };
        }
        const currentSort = window.sparklineTableSort[p.id];

        items.sort((a, b) => {
            let diff = 0;
            if (currentSort.col === 'agent' || currentSort.col === 'name') {
                const aName = a.ip_address || a.agent_name;
                const bName = b.ip_address || b.agent_name;
                diff = aName.localeCompare(bName, undefined, { numeric: true, sensitivity: 'base' });
            } else if (currentSort.col === 'module') {
                diff = a.module_name.localeCompare(b.module_name, undefined, { numeric: true, sensitivity: 'base' });
            } else if (currentSort.col === 'val') {
                diff = a.sortVal - b.sortVal;
            } else if (currentSort.col === 'trend') {
                const lastA = (a.history && a.history.length > 0) ? parseFloat(a.history[a.history.length - 1].val) : 0;
                const lastB = (b.history && b.history.length > 0) ? parseFloat(b.history[b.history.length - 1].val) : 0;
                diff = lastA - lastB;
            }
            return (currentSort.order === 'desc') ? -diff : diff;
        });

        const limit = parseInt(p.row_limit) || 10;
        const totalItems = items.length;
        const totalPages = Math.ceil(totalItems / limit) || 1;

        window.tableCurrentPages = window.tableCurrentPages || {};
        const currentPage = Math.min(Math.max(1, window.tableCurrentPages[p.id] || 1), totalPages);
        window.tableCurrentPages[p.id] = currentPage;

        const startIdx = (currentPage - 1) * limit;
        const paginatedItems = items.slice(startIdx, startIdx + limit);

        const tableFs = parseInt(p.font_size) || 12;
        const tableH = p.height ? `max-height: ${Math.max(160, parseInt(p.height) - 90)}px;` : 'max-height: 400px;';

        const getSortIndicator = (col) => {
            if (currentSort.col === col) {
                return currentSort.order === 'desc'
                    ? '<span style="font-size:10px; margin-left:3px; color:#0284c7;">▼</span>'
                    : '<span style="font-size:10px; margin-left:3px; color:#0284c7;">▲</span>';
            }
            return '<span style="font-size:10px; margin-left:3px; color:#cbd5e1;">⇅</span>';
        };

        let paginationHtml = '';
        if (totalPages > 1) {
            paginationHtml = `
            <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 4px 0 4px; font-size:11px; color:#64748b; background:transparent;">
                <div>Showing ${startIdx + 1} to ${Math.min(startIdx + limit, totalItems)} of ${totalItems}</div>
                <div style="display:flex; gap:6px;">
                    <button class="btn-pfms btn-outline-pfms" style="padding:2px 8px; font-size:10px;" ${currentPage <= 1 ? 'disabled' : ''} onclick="window.tableCurrentPages['${p.id}'] = ${currentPage - 1}; forceRefresh();">Prev</button>
                    <span style="padding:2px 6px;">Page ${currentPage} of ${totalPages}</span>
                    <button class="btn-pfms btn-outline-pfms" style="padding:2px 8px; font-size:10px;" ${currentPage >= totalPages ? 'disabled' : ''} onclick="window.tableCurrentPages['${p.id}'] = ${currentPage + 1}; forceRefresh();">Next</button>
                </div>
            </div>`;
        }

        if (items.length === 0) {
            content = `<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:160px; color:#bdc3c7; font-size:11px;"><span class="material-symbols-outlined" style="font-size:24px; margin-bottom:5px;">query_stats</span>No data matched</div>`;
        } else {
            content = `
            <div class="sparkline-table-wrap" style="display:flex; flex-direction:column; width:100%; height:auto;">
                ${!hideSearch ? `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; gap:8px;">
                    <div style="font-size:10px; color:#94a3b8; font-weight:500;">Top ${Math.min(limit, totalItems)} of ${totalItems} Items</div>
                    <div style="position:relative; width:180px;">
                        <input type="text" placeholder="Search node, IP..." class="form-control-fix" style="font-size:11px; padding:3px 8px 3px 24px; height:24px; border-radius:4px; margin-bottom:0;" value="${escapeHtml(window.sparklineTableSearch[p.id] || '')}" oninput="window.sparklineTableSearch['${p.id}'] = this.value; window.tableCurrentPages['${p.id}'] = 1; forceRefresh();">
                        <span class="material-symbols-outlined" style="position:absolute; left:6px; top:50%; transform:translateY(-50%); font-size:13px; color:#94a3b8; pointer-events:none;">search</span>
                    </div>
                </div>` : ''}
                <div style="overflow-x:auto; overflow-y:auto; ${tableH} border:1px solid #e2e8f0; border-radius:6px; background:#fff;">
                    <table class="sparkline-table" style="font-size:${tableFs}px; width:100%; border-collapse:collapse; margin:0;">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                                <th style="text-align:left; padding:8px 12px; cursor:pointer;" onclick="toggleSparklineSort('${p.id}', 'agent');">Agent ${getSortIndicator('agent')}</th>
                                <th style="text-align:left; padding:8px 12px; cursor:pointer;" onclick="toggleSparklineSort('${p.id}', 'module');">Module Name ${getSortIndicator('module')}</th>
                                <th style="text-align:left; padding:8px 12px; width:110px; cursor:pointer;" onclick="toggleSparklineSort('${p.id}', 'val');">Value ${getSortIndicator('val')}</th>
                                <th style="text-align:left; padding:8px 12px; width:170px; cursor:pointer;" onclick="toggleSparklineSort('${p.id}', 'trend');">${trendColTitle} ${getSortIndicator('trend')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${paginatedItems.map(it => `
                                <tr style="border-bottom:1px solid #f1f5f9; transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                                    <td style="padding:8px 12px; vertical-align:middle; white-space:nowrap;">
                                        <div style="font-weight:600; color:#0284c7; font-size:${tableFs}px; line-height:1.2;">
                                            ${escapeHtml(it.ip_address || it.agent_name)}
                                        </div>
                                        ${(it.ip_address && it.agent_name && it.agent_name !== it.ip_address) ? `<div style="font-size:${Math.max(9, tableFs - 2)}px; color:#64748b; margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:200px;" title="${escapeHtml(it.agent_name)}">${escapeHtml(it.agent_name)}</div>` : ''}
                                    </td>
                                    <td style="padding:8px 12px; vertical-align:middle;">
                                        <div style="font-weight:600; color:#0f172a; cursor:pointer; font-size:${tableFs}px; line-height:1.3; word-break:break-word;" onclick="openNativeModuleDetailModal('${it.id}', '${(it.agent_name + ' - ' + it.module_name).replace(/'/g, "\\'")}')" title="Click to view module details">
                                            ${escapeHtml(it.module_name)}
                                        </div>
                                    </td>
                                    <td style="padding:8px 12px; vertical-align:middle; white-space:nowrap;">
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:${it.statusColor}; box-shadow:0 0 0 2px ${it.statusColor}28; flex-shrink:0;" title="${escapeHtml(it.statusLabel || '')}"></span>
                                            <span style="font-weight:700; color:#0f172a; font-size:${tableFs}px;">${escapeHtml(it.displayVal)}</span>
                                        </div>
                                    </td>
                                    <td style="padding:6px 12px; vertical-align:middle;">
                                        ${generateSparklineSvg(it.history, { height: 26, color: '#10b981', fillColor: 'rgba(16, 185, 129, 0.15)', showArea: true })}
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                ${paginationHtml}
            </div>`;
        }
    }

    const isHidden = p.hidden === true;
    const hiddenClass = isHidden ? 'is-hidden' : '';
    const isTableWidget = ['sparkline_table', 'status_table', 'history_table', 'table_viewer'].includes(p.type);
    const cardMinH = isTableWidget ? '' : (p.height ? `min-height:${p.height}px;` : '');
    const cardHeight = isTableWidget ? 'height:auto;' : 'height:100%;';
    const bodyPadding = isTableWidget ? 'padding:10px;' : 'padding:10px;';
    const bodyFlex = isTableWidget ? 'flex-grow:0;' : '';

    return `
        <div class="panel-card ${hiddenClass}" style="${cardHeight} ${cardMinH} margin:0;">
            <div class="panel-header">
                <div><h6 class="panel-title"><span class="material-symbols-outlined drag-handle" style="font-size:14px; cursor:grab; color:#b5c1c9; vertical-align:middle; margin-right:4px;" title="Drag">drag_indicator</span> ${p.title}</h6></div>
                ${controlsHtml}
            </div>
            <div class="panel-body" style="align-items:stretch; justify-content:flex-start; ${bodyPadding} ${bodyFlex}">${content}</div>
        </div>`;
}

// =========================================================================
// CHART COLOR PALETTES & INTERACTIVE SERIES COLOR CUSTOMIZATION ENGINE
// =========================================================================
const COLOR_PALETTES = {
    'vibrant': [
        '#2563eb', '#16a34a', '#ea580c', '#8b5cf6', '#06b6d4',
        '#e11d48', '#f59e0b', '#10b981', '#6366f1', '#d946ef',
        '#14b8a6', '#f43f5e', '#84cc16', '#3b82f6', '#ec4899', '#0284c7'
    ],
    'distinct': [
        '#0284c7', '#16a34a', '#dc2626', '#d97706', '#7c3aed',
        '#0d9488', '#e11d48', '#2563eb', '#ca8a04', '#9333ea',
        '#059669', '#ea580c', '#64748b', '#db2777', '#0891b2',
        '#4f46e5', '#65a30d', '#c026d3', '#b45309', '#0369a1'
    ],
    'neon': [
        '#00e5ff', '#00e676', '#ff1744', '#ffea00', '#d500f9',
        '#ff9100', '#651fff', '#1de9b6', '#f50057', '#00b0ff'
    ],
    'ocean': [
        '#0284c7', '#0d9488', '#2563eb', '#0891b2', '#4f46e5',
        '#059669', '#3b82f6', '#14b8a6', '#1e40af', '#0e7490'
    ],
    'warm': [
        '#ea580c', '#e11d48', '#d97706', '#ca8a04', '#b91c1c',
        '#c2410c', '#b45309', '#f59e0b', '#f43f5e', '#854d0e'
    ],
    'pastel': [
        '#60a5fa', '#4ade80', '#f87171', '#fbbf24', '#a78bfa',
        '#2dd4bf', '#fb7185', '#38bdf8', '#818cf8', '#f472b6'
    ]
};

function getPaletteColors(paletteKey) {
    return COLOR_PALETTES[paletteKey] || COLOR_PALETTES['vibrant'];
}

function showToastNotice(msg, type = 'info') {
    let toast = document.getElementById('dyn_dash_toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'dyn_dash_toast';
        toast.style.cssText = 'position:fixed; bottom:24px; right:24px; background:#0f172a; color:#fff; padding:10px 16px; border-radius:8px; font-size:12px; font-weight:600; box-shadow:0 10px 25px rgba(0,0,0,0.25); z-index:999999; display:flex; align-items:center; gap:8px; transition:opacity 0.3s, transform 0.3s; opacity:0; transform:translateY(10px); pointer-events:none;';
        document.body.appendChild(toast);
    }
    const icon = type === 'success' ? 'palette' : 'info';
    toast.innerHTML = `<span class="material-symbols-outlined" style="font-size:16px; color:#38bdf8;">${icon}</span><span>${msg}</span>`;
    toast.style.opacity = '1';
    toast.style.transform = 'translateY(0)';
    if (window.__toastTimer) clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
    }, 3500);
}

function colorToHex(color) {
    if (!color) return '#0284c7';
    color = String(color).trim();
    if (color.startsWith('#')) {
        if (color.length === 7) return color;
        if (color.length === 4) return '#' + color[1]+color[1] + color[2]+color[2] + color[3]+color[3];
    }
    const match = color.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
    if (match) {
        const r = parseInt(match[1]).toString(16).padStart(2, '0');
        const g = parseInt(match[2]).toString(16).padStart(2, '0');
        const b = parseInt(match[3]).toString(16).padStart(2, '0');
        return `#${r}${g}${b}`;
    }
    return '#0284c7';
}

function openSeriesColorPicker(e, panelId, seriesName, chartUniqueId, dotEl) {
    if (e) {
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
        if (typeof e.preventDefault === 'function') e.preventDefault();
    }
    let picker = document.getElementById('global_legend_color_picker');
    if (!picker) {
        picker = document.createElement('input');
        picker.type = 'color';
        picker.id = 'global_legend_color_picker';
        picker.style.cssText = 'position:fixed; top:-9999px; left:-9999px; opacity:0; visibility:hidden; width:0; height:0; pointer-events:none; border:none; padding:0;';
        document.body.appendChild(picker);
    }
    const currColor = dotEl ? (dotEl.style.backgroundColor || dotEl.getAttribute('data-color')) : '#0284c7';
    picker.value = colorToHex(currColor);
    picker.onchange = function() {
        onSeriesColorChanged(panelId, seriesName, picker.value, chartUniqueId);
    };
    if (typeof picker.showPicker === 'function') {
        picker.showPicker();
    } else {
        picker.click();
    }
}

function onSeriesColorChanged(panelId, seriesName, newColor, chartUniqueId) {
    const dash = masterDashboards.find(d => d.id === currentDashId);
    if (!dash) return;
    const p = dash.panels.find(x => String(x.id) === String(panelId));
    if (!p) return;

    if (!p.series_colors) p.series_colors = {};
    p.series_colors[seriesName] = newColor;

    if (!p.multi_overlay) {
        p.chart_color = newColor;
    }

    const chart = chartInstances[chartUniqueId];
    if (chart) {
        const opt = chart.getOption();
        if (opt && opt.series) {
            opt.series.forEach(s => {
                if (s.name === seriesName || (!p.multi_overlay && opt.series.length === 1)) {
                    s.itemStyle = s.itemStyle || {};
                    s.itemStyle.color = newColor;
                    if (s.areaStyle) s.areaStyle.color = newColor;
                    if (s.lineStyle) s.lineStyle.color = newColor;
                }
            });
            chart.setOption({ series: opt.series });
        }
    }

    const legendEl = document.getElementById(`chart_legend_${chartUniqueId}`);
    if (legendEl) {
        const dots = legendEl.querySelectorAll(`.legend-chip[data-series="${seriesName.replace(/"/g, '\\"')}"] .legend-color-dot, .legend-color-dot`);
        dots.forEach(d => { 
            d.style.backgroundColor = newColor; 
            d.setAttribute('data-color', newColor);
        });
    }

    markUnsaved();
    showToastNotice(`Color for "${seriesName}" changed to ${newColor}. Click "Save Changes" on toolbar to save.`, 'success');
}

function previewPaletteSelection() {
    const el = document.getElementById('palette_swatch_preview');
    if (!el) return;
    const key = document.getElementById('p_color_palette') ? document.getElementById('p_color_palette').value : 'vibrant';
    const colors = getPaletteColors(key).slice(0, 6);
    el.innerHTML = colors.map(c => `<span style="display:inline-block; width:14px; height:14px; border-radius:50%; background-color:${c}; border:1.5px solid #ffffff; box-shadow:0 0 0 1px rgba(0,0,0,0.15);" title="${c}"></span>`).join('');
    renderSeriesColorsEditor();
}

function renderSeriesColorsEditor(p = null) {
    const container = document.getElementById('series_colors_container');
    if (!container) return;

    if (!p && editingPanelId) {
        const dash = masterDashboards.find(d => d.id === currentDashId);
        if (dash) p = dash.panels.find(x => x.id === editingPanelId);
    }

    const paletteKey = document.getElementById('p_color_palette') ? document.getElementById('p_color_palette').value : (p?.color_palette || 'vibrant');
    const activePalette = getPaletteColors(paletteKey);

    let seriesList = [];
    if (editingPanelId && typeof lastFetchedData !== 'undefined' && lastFetchedData && lastFetchedData[editingPanelId]?.modules) {
        seriesList = lastFetchedData[editingPanelId].modules.map(m => `${m.agent_name || ''} - ${m.module_name || ''}`);
    } else if (p && p.series_colors && Object.keys(p.series_colors).length > 0) {
        seriesList = Object.keys(p.series_colors);
    }

    seriesList = [...new Set(seriesList)].filter(Boolean);

    if (seriesList.length === 0) {
        container.innerHTML = `<div style="font-size:11px; color:#64748b; padding:8px; text-align:center; line-height:1.4;">Series colors will automatically use the <b>${paletteKey.toUpperCase()}</b> palette with distinct hues. You can also click the color dot on the chart legend to change colors instantly!</div>`;
        return;
    }

    const seriesColors = window.__editingPanelSeriesColors || (p?.series_colors || {});

    container.innerHTML = seriesList.map((seriesName, idx) => {
        const autoColor = activePalette[idx % activePalette.length];
        const isCustom = !!seriesColors[seriesName];
        const currentColor = seriesColors[seriesName] || autoColor;
        const safeName = seriesName.replace(/"/g, '&quot;');
        const safeJsName = seriesName.replace(/\\/g, '\\\\').replace(/'/g, "\\'");

        return `
            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:4px 8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:4px;">
                <div style="display:flex; align-items:center; gap:8px; flex:1; min-width:0;">
                    <input type="color" value="${currentColor.startsWith('#') && currentColor.length === 7 ? currentColor : autoColor}" 
                        style="width:28px; height:26px; padding:1px; border:1px solid #cbd5e1; border-radius:4px; cursor:pointer; background:#fff;" 
                        onchange="setSeriesEditorColor('${safeJsName}', this.value)">
                    <span style="font-size:11px; color:#334155; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${safeName}">${safeName}</span>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <span style="font-size:10px; font-family:monospace; color:#64748b;">${currentColor}</span>
                    ${isCustom ? `
                        <button type="button" onclick="resetSeriesEditorColor('${safeJsName}')" style="background:#fee2e2; border:1px solid #fca5a5; border-radius:3px; padding:2px 6px; font-size:9px; font-weight:700; color:#dc2626; cursor:pointer;" title="Reset to automatic palette color">
                            Reset
                        </button>
                    ` : `
                        <span style="font-size:9px; background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:3px; font-weight:600;">Auto</span>
                    `}
                </div>
            </div>
        `;
    }).join('');
}

function setSeriesEditorColor(seriesName, color) {
    if (!window.__editingPanelSeriesColors) window.__editingPanelSeriesColors = {};
    window.__editingPanelSeriesColors[seriesName] = color;
    renderSeriesColorsEditor();
}

function resetSeriesEditorColor(seriesName) {
    if (window.__editingPanelSeriesColors) {
        delete window.__editingPanelSeriesColors[seriesName];
    }
    renderSeriesColorsEditor();
}

function resetPanelSeriesColors() {
    window.__editingPanelSeriesColors = {};
    if (document.getElementById('p_chart_color_hex')) {
        document.getElementById('p_chart_color_hex').value = '';
    }
    renderSeriesColorsEditor();
}

function refreshCurrentNodeData() {
    if (!currentDashId) return;
    const currentDash = masterDashboards.find(d => d.id === currentDashId);
    let agentId = document.getElementById('top_agent').value;

    const urlParams = new URLSearchParams(window.location.search);
    const filterPanelId = urlParams.get('p') || urlParams.get('panel_id');
    const targetPanels = currentDash.panels.filter(p => {
        if (filterPanelId && p.id !== filterPanelId) return false;
        if (p.hidden && !showHiddenPanels) return false;
        return true;
    });
    
    targetPanels.forEach(p => {
        const loadOverlay = document.getElementById(`load_${p.id}`);
        if(loadOverlay) loadOverlay.style.display = 'flex';
    });

    if (!agentId || agentId == 0) {
        targetPanels.forEach(p => {
            const wrapper = document.getElementById(`wrapper_p_${p.id}`);
            if(wrapper) {
                const controlsHtml = getPanelControlsHtml(p);
                wrapper.innerHTML = `<div class="panel-card"><div class="panel-header"><div><h6 class="panel-title"><span class="material-symbols-outlined drag-handle" style="font-size:14px; cursor:grab; color:#b5c1c9; vertical-align:middle; margin-right:4px;" title="Drag">drag_indicator</span> ${p.title}</h6></div>${controlsHtml}</div><div class="panel-body" style="color:#7f8c8d; text-align:center; padding:20px;">Select a Node First</div></div>`;
            }
        });
        return;
    }

    const timeRng = getTimeRange();
    const payload = { agent_id: agentId, start: timeRng.start, end: timeRng.end, panels: targetPanels };

    fetch(getApiUrl('bulk_panel_data'), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r => r.text().then(text => {
        try {
            return JSON.parse(text);
        } catch(e) {
            console.error("Malformed JSON response from server:", text);
            throw new Error("Server returned invalid JSON. Check browser console (F12 -> Console) to see the raw error.");
        }
    })).then(res => {
        if (!res.ok) throw new Error(res.error || 'Unknown server error');
        
        const lastUpd = document.getElementById('last_update_text');
        if(lastUpd) lastUpd.innerText = 'Last Update: ' + new Date().toLocaleTimeString();
        
        const dataMap = res.data || {};
        lastFetchedData = dataMap; 
        Object.values(chartInstances).forEach(c => { if(c && typeof c.dispose === 'function') c.dispose(); });
        chartInstances = {};

        targetPanels.forEach(p => {
            const wrapper = document.getElementById(`wrapper_p_${p.id}`);
            if(!wrapper) return;
            
            const panelData = dataMap[p.id];
            if(!panelData || !panelData.found) { 
                const controlsHtml = getPanelControlsHtml(p);
                const hiddenClass = p.hidden ? 'is-hidden' : '';
                wrapper.innerHTML = `<div class="panel-card ${hiddenClass}">
                    <div class="panel-header"><div><h6 class="panel-title"><span class="material-symbols-outlined drag-handle" style="font-size:14px; cursor:grab; color:#b5c1c9; vertical-align:middle; margin-right:4px;" title="Drag">drag_indicator</span> ${p.title}</h6></div>${controlsHtml}</div>
                    <div class="panel-body" style="color:#7f8c8d; text-align:center; padding:30px; align-items:center; justify-content:center;">
                        <span class="material-symbols-outlined" style="font-size:48px; color:#bdc3c7; display:block; margin-bottom:10px;">search_off</span>
                        Data not found for: <b>${p.keyword}</b><br>
                        <small style="color:#bdc3c7;">(Match: ${p.match_type})</small>
                    </div>
                </div>`; 
                return; 
            }

            var activeModules = panelData.modules || [];
            if (!showHiddenPanels && p.excluded) {
                activeModules = activeModules.filter(m => !p.excluded.map(String).includes(String(m.id)));
            }
            if (p.multi_overlay && ['line', 'area', 'bar'].includes(p.type)) {
                activeModules.sort((a, b) => {
                    const nameA = `${a.agent_name || ''} - ${a.module_name || ''}`;
                    const nameB = `${b.agent_name || ''} - ${b.module_name || ''}`;
                    return nameA.localeCompare(nameB, undefined, { numeric: true, sensitivity: 'base' });
                });
            } else {
                activeModules.sort((a, b) => (b.last_contact || 0) - (a.last_contact || 0));
            }

            if (['status_table', 'status_heatmap', 'status_stats', 'pie', 'donut', 'history_table', 'sparkline_table'].includes(p.type)) {
                wrapper.innerHTML = generateSummaryPanelHtml(p, activeModules);
            } else {
                if (p.multi_overlay && ['line', 'area', 'bar'].includes(p.type)) {
                    const firstMod = activeModules[0] || {};
                    const multiModData = { ...firstMod, module_name: 'Multi-Module Overlay', current: 'N/A' };
                    wrapper.innerHTML = generatePanelHtml(p, `${p.id}_multi`, multiModData, true, 1);
                } else {
                    wrapper.innerHTML = activeModules.map((m, i) => generatePanelHtml(p, `${p.id}_${m.id}`, m, i===0, activeModules.length)).join('');
                }
            }

            const colors = [
                'rgba(0, 77, 64, 0.75)', 'rgba(25, 118, 210, 0.75)', 'rgba(211, 47, 47, 0.75)', 'rgba(245, 124, 0, 0.75)', 'rgba(123, 31, 162, 0.75)',
                'rgba(0, 150, 136, 0.75)', 'rgba(251, 192, 45, 0.75)', 'rgba(97, 97, 97, 0.75)', 'rgba(233, 30, 99, 0.75)', 'rgba(141, 110, 99, 0.75)'
            ];
            const borders = [
                '#0284c7', '#16a34a', '#dc2626', '#d97706', '#7c3aed',
                '#0d9488', '#e11d48', '#2563eb', '#ca8a04', '#9333ea',
                '#059669', '#ea580c', '#64748b', '#db2777', '#0891b2',
                '#4f46e5', '#65a30d', '#c026d3', '#b45309', '#0369a1',
                '#4338ca', '#be123c', '#15803d', '#b91c1c', '#6d28d9',
                '#0f766e', '#a16207', '#334155', '#86198f', '#1e40af',
                '#047857', '#c2410c', '#475569', '#a21caf', '#0e7490',
                '#854d0e'
            ];

            const chartFs = p.chart_font_size ? parseInt(p.chart_font_size) : 10;

            if (['pie', 'donut'].includes(p.type)) {
                const canvas = document.getElementById(`chart_${p.id}`);
                if (canvas) {
                    const stats = { total: activeModules.length, up: 0, crit: 0, warn: 0, unknown: 0, not_init: 0 };
                    activeModules.forEach(m => {
                        if (m.status === 0) stats.up++;
                        else if (m.status === 1) stats.crit++;
                        else if (m.status === 2) stats.warn++;
                        else if (m.status === 4) stats.not_init++;
                        else stats.unknown++;
                    });

                    const statusMap = [
                        { label: 'UP (Normal)', value: stats.up, color: '#2ecc71', border: '#27ae60' },
                        { label: 'Warning', value: stats.warn, color: '#f1c40f', border: '#f39c12' },
                        { label: 'Critical', value: stats.crit, color: '#e74c3c', border: '#c0392b' },
                        { label: 'Unknown', value: stats.unknown, color: '#95a5a6', border: '#7f8c8d' },
                        { label: 'Not Init', value: stats.not_init, color: '#3498db', border: '#2980b9' }
                    ];

                    const activeStatuses = statusMap.filter(s => s.value > 0);
                    const finalStatuses = activeStatuses.length > 0 ? activeStatuses : statusMap;

                    const labels = finalStatuses.map(s => `${s.label} (${s.value})`);
                    const values = finalStatuses.map(s => s.value);
                    const bgColors = finalStatuses.map(s => s.color);
                    const borderColors = finalStatuses.map(s => s.border);

                    try {
                        let dom = document.getElementById(`chart_${p.id}`);
                        if (!dom) return;
                        chartInstances[p.id] = echarts.init(dom);
                        let pieData = labels.map((lbl, i) => ({ value: values[i], name: lbl, itemStyle: { color: bgColors[i] } }));
                        chartInstances[p.id].setOption({
                            tooltip: { trigger: 'item', confine: true, appendToBody: true, backgroundColor: 'rgba(15, 23, 42, 0.95)', textStyle: { color: '#ffffff', fontSize: 12 }, padding: 10, borderRadius: 6, formatter: '{b} <br/>Count: {c} ({d}%)' },
                            legend: { type: 'scroll', orient: 'vertical', right: 5, top: 'middle', itemWidth: 10, itemHeight: 10, textStyle: { fontSize: Math.max(9, chartFs - 1) } },
                            series: [{
                                type: 'pie',
                                radius: p.type === 'pie' ? '75%' : ['50%', '75%'],
                                center: ['40%', '50%'],
                                data: pieData,
                                label: { show: true, formatter: '{b}\n{c} ({d}%)', fontSize: chartFs, color: '#334155' },
                                labelLine: { show: true, length: 10, length2: 5 },
                                itemStyle: { borderColor: '#fff', borderWidth: 1 }
                            }]
                        });
                        window.addEventListener('resize', () => chartInstances[p.id].resize());
                    } catch(e) { console.error("Pie/Donut ECharts Error:", e); }
                }
            } else if (p.multi_overlay && ['line', 'area', 'bar'].includes(p.type)) {
                const uniqueId = `${p.id}_multi`;
                const canvas = document.getElementById(`chart_${uniqueId}`);
                if (canvas) {
                    let allTimestampsSet = new Set();
                    activeModules.forEach(m => (m.history || []).forEach(h => {
                        if (h.ts !== undefined) {
                            const roundedTs = Math.round(Number(h.ts) / 60) * 60;
                            allTimestampsSet.add(roundedTs);
                        }
                    }));
                    const uniqueTimestamps = Array.from(allTimestampsSet).sort((a, b) => a - b);
                    
                    const labels = uniqueTimestamps.map(ts => {
                        for (let m of activeModules) {
                            const found = (m.history || []).find(h => {
                                const rounded = Math.round(Number(h.ts) / 60) * 60;
                                return rounded === ts;
                            });
                            if (found) return found.lbl;
                        }
                        return '';
                    });

                    const autoConvert = (p.auto_convert_traffic !== undefined)
                        ? (p.auto_convert_traffic === true || p.auto_convert_traffic === 1 || p.auto_convert_traffic === '1' || p.auto_convert_traffic === 'true')
                        : true;
                    const cardIsTraffic = (p.use_raw !== true) && (autoConvert !== false) && activeModules.some(m => isTrafficMetric(m.module_name, m.unit, p.title));
                    const commonUnit = activeModules.length > 0 ? (activeModules[0].unit || '').trim() : '';

                    const panelPalette = getPaletteColors(p.color_palette || 'vibrant');
                    const seriesData = activeModules.map((m, idx) => {
                        const seriesKey = `${m.agent_name} - ${m.module_name}`;
                        const customColor = (p.series_colors && (p.series_colors[seriesKey] || p.series_colors[m.id])) || null;
                        const color = customColor || panelPalette[idx % panelPalette.length];
                        const isByte = cardIsTraffic && isByteTrafficMetric(m.module_name, m.unit, p.title);
                        const historyMap = {};
                        (m.history || []).forEach(h => {
                            if (h.ts !== undefined) {
                                const roundedTs = Math.round(Number(h.ts) / 60) * 60;
                                historyMap[roundedTs] = h.val;
                            }
                        });
                        const isPct = (m.unit === '%' || (m.module_name && (m.module_name.includes('Load') || m.module_name.includes('Used'))) || p.title.toLowerCase().includes('utiliz'));
                        let lastVal = null;
                        const data = uniqueTimestamps.map(ts => {
                            if (historyMap[ts] !== undefined) lastVal = historyMap[ts];
                            if (lastVal !== null && !isNaN(lastVal)) {
                                let v = Number(lastVal);
                                if (isPct && v > 100) v = 100;
                                return isByte ? (v * 8) : v;
                            }
                            return null;
                        });

                        return {
                            name: seriesKey,
                            type: p.type === 'bar' ? 'bar' : 'line',
                            data: data,
                            itemStyle: { color: color },
                            areaStyle: p.type === 'area' ? { opacity: 0.15, color: color } : undefined,
                            smooth: true,
                            showSymbol: false,
                            connectNulls: true,
                            lineStyle: { width: p.type === 'bar' ? 0 : 2 }
                        };
                    });

                    try {
                        let dom = document.getElementById(`chart_${uniqueId}`);
                        if (!dom) return;

                        const legendEl = document.getElementById(`chart_legend_${uniqueId}`);
                        if (legendEl && seriesData.length > 0) {
                            legendEl.innerHTML = seriesData.map((s, idx) => {
                                const color = s.itemStyle ? s.itemStyle.color : panelPalette[idx % panelPalette.length];
                                const safeName = s.name.replace(/"/g, '&quot;');
                                const safeJsName = s.name.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                                return `<div class="legend-chip" data-series="${safeName}" style="display: inline-flex; align-items: center; gap: 6px; font-size: ${Math.max(9, chartFs - 1)}px; color: #475569; user-select: none; transition: opacity 0.2s;" onmouseenter="highlightDynamicEchartsSeries('${uniqueId}', '${safeJsName}')" onmouseleave="downplayDynamicEchartsSeries('${uniqueId}', '${safeJsName}')" title="${safeName} (click dot to change color, click text to toggle)">
                                    <span class="legend-color-dot" data-color="${color}" style="background-color: ${color};" onclick="openSeriesColorPicker(event, '${p.id}', '${safeJsName}', '${uniqueId}', this)" title="Click to change color"></span>
                                    <span style="white-space: nowrap; max-width: 260px; overflow: hidden; text-overflow: ellipsis; cursor: pointer;" onclick="toggleDynamicEchartsLegend('${uniqueId}', this.closest('.legend-chip'))">${s.name}</span>
                                </div>`;
                            }).join('');
                        }

                        chartInstances[uniqueId] = echarts.init(dom);
                        chartInstances[uniqueId].setOption({
                            tooltip: { 
                                trigger: 'axis', 
                                confine: true,
                                appendToBody: true,
                                backgroundColor: 'rgba(15, 23, 42, 0.95)', 
                                textStyle: { color: '#cbd5e1', fontSize: chartFs + 2 }, 
                                padding: 10, 
                                borderRadius: 6,
                                formatter: function(params) {
                                    if (!params || params.length === 0) return '';
                                    
                                    // Sort descending by value
                                    const sortedParams = [...params].sort((a, b) => {
                                        let valA = (a.value !== null && a.value !== undefined && a.value !== '' && !isNaN(a.value) && a.value !== '-') ? parseFloat(a.value) : -Infinity;
                                        let valB = (b.value !== null && b.value !== undefined && b.value !== '' && !isNaN(b.value) && b.value !== '-') ? parseFloat(b.value) : -Infinity;
                                        return valB - valA;
                                    });

                                    let html = `<div style="font-weight:600; color:#fff; margin-bottom:4px; font-size:11px; border-bottom:1px solid #334155; padding-bottom:3px;">${params[0].name || ''}</div>`;
                                    html += `<div style="max-height:320px; overflow-y:auto; padding-right:4px;">`;
                                    sortedParams.forEach(sp => {
                                        const mod = activeModules[sp.seriesIndex];
                                        const unitStr = (mod && mod.unit) ? mod.unit : '';
                                        const modIsTraffic = cardIsTraffic || isTrafficMetric(mod ? mod.module_name : sp.seriesName, unitStr, p.title);
                                        let displayVal;
                                        if (modIsTraffic) {
                                            const isByte = isByteTrafficMetric(mod ? mod.module_name : sp.seriesName, unitStr, p.title);
                                            const bits = (cardIsTraffic && isByte) ? sp.value : (isByte ? sp.value * 8 : sp.value);
                                            displayVal = formatBitsRate(bits);
                                        } else {
                                            displayVal = formatHumanMetric(sp.value, unitStr, true, mod ? mod.module_name : '', p.title);
                                        }
                                        html += `<div style="display:flex; justify-content:space-between; align-items:center; gap:14px; margin:3px 0;">
                                            <span style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:240px;">${sp.marker} ${sp.seriesName}</span>
                                            <span style="font-weight:600; color:#fff; white-space:nowrap; margin-left:auto;">${displayVal}</span>
                                        </div>`;
                                    });
                                    html += `</div>`;
                                    return html;
                                }
                            },
                            legend: { show: false, data: seriesData.map(s => s.name) },
                            grid: { left: 8, right: 15, top: 12, bottom: p.show_time ? 28 : 10, containLabel: true },
                            xAxis: { type: 'category', boundaryGap: p.type === 'bar', data: labels, show: !!p.show_time, axisLabel: { fontSize: Math.max(8, chartFs - 2), color: '#64748b' }, axisLine: { show: false }, axisTick: { show: false } },
                            yAxis: { 
                                type: 'value', 
                                show: (p.show_yaxis !== false),
                                max: (p.force_100 || (commonUnit === '%')) ? 100 : null, 
                                splitLine: { show: (p.show_yaxis !== false), lineStyle: { color: '#f0f3f5' } }, 
                                axisLine: { show: false },
                                axisTick: { show: false },
                                axisLabel: { 
                                    fontSize: Math.max(8, chartFs - 2), 
                                    color: '#64748b',
                                    formatter: function(value) {
                                        const u = (commonUnit || '').toLowerCase();
                                        const isTrafficUnit = cardIsTraffic || u.includes('byte') || u.includes('b/s') || u.includes('bit') || u.includes('bps') || u.includes('octet');
                                        if (isTrafficUnit) {
                                            if (value >= 1000000000) return (value / 1000000000).toFixed(1) + ' Gbps';
                                            if (value >= 1000000) return (value / 1000000).toFixed(1) + ' Mbps';
                                            if (value >= 1000) return (value / 1000).toFixed(1) + ' Kbps';
                                            return value.toFixed(0) + ' bps';
                                        }
                                        if (commonUnit === '%') return (Number.isInteger(value) ? value : parseFloat(value.toFixed(1))) + '%';
                                        if (commonUnit === 'ms') return (Number.isInteger(value) ? value : parseFloat(value.toFixed(1))) + ' ms';
                                        if (commonUnit === 'dBm') return parseFloat(value.toFixed(1)) + ' dBm';
                                        if (Math.abs(value) >= 1000000000) return (value / 1000000000).toFixed(1) + (commonUnit ? ' G' + commonUnit : 'G');
                                        if (Math.abs(value) >= 1000000) return (value / 1000000).toFixed(1) + (commonUnit ? ' M' + commonUnit : 'M');
                                        if (Math.abs(value) >= 1000) return (value / 1000).toFixed(1) + (commonUnit ? ' k' + commonUnit : 'k');
                                        return (Number.isInteger(value) ? value : parseFloat(value.toFixed(2))) + (commonUnit ? ' ' + commonUnit : '');
                                    }
                                } 
                            },
                            series: seriesData
                        });

                        setTimeout(() => {
                            if (chartInstances[uniqueId] && typeof chartInstances[uniqueId].resize === 'function') {
                                chartInstances[uniqueId].resize();
                            }
                        }, 50);

                        window.addEventListener('resize', () => chartInstances[uniqueId].resize());
                    } catch(e) { console.error("MultiChart ECharts Error:", e); }
                }
            } else {
                activeModules.forEach(m => {
                    const uniqueId = `${p.id}_${m.id}`;
                    if (p.type === 'table_viewer') {
                        const agentLabel = `${m.agent_name || ''}/${m.agent_db_name || ''}`;
                        renderSingleModuleTableViewer(uniqueId, m.current || '', agentLabel, p.row_limit);
                        return;
                    }
                    if (p.type === 'history_table') {
                        return;
                    }
                    const canvas = document.getElementById(`chart_${uniqueId}`);
                    if (!canvas) return;
                    const defaultColor = {0:'#2ecc71', 1:'#e74c3c', 2:'#f1c40f', 4:'#3498db'}[m.status] || '#95a5a6';
                    const seriesKey = `${m.agent_name} - ${m.module_name}`;
                    const customColor = (p.series_colors && (p.series_colors[seriesKey] || p.series_colors[m.id])) || p.chart_color || null;
                    const color = customColor || defaultColor;
                    const history = m.history || [];
                    if (['line','area','bar'].includes(p.type) && p.chart_engine === 'native') return;
                    const isHistoryChart = ['line','area','bar', 'single_value'].includes(p.type);
                    const isGaugeChart = p.type === 'gauge';
                    
                    if ((isHistoryChart && history.length === 0 && p.type !== 'single_value') || (isGaugeChart && (m.current === null || m.current === undefined || m.current === ''))) {
                        const parent = canvas.parentElement;
                        if(parent) parent.innerHTML = `<div style="color:#bdc3c7; font-size:11px; display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%;"><span class="material-symbols-outlined" style="font-size:24px; margin-bottom:5px;">query_stats</span>No Data</div>`;
                        return;
                    }

                    try {
                        let dom = document.getElementById(`chart_${uniqueId}`);
                        if (!dom) return;
                        chartInstances[uniqueId] = echarts.init(dom);
                        if (p.type === 'single_value') {
                            if (history && history.length > 0) {
                                chartInstances[uniqueId].setOption({
                                    grid: { left: 0, right: 0, top: 0, bottom: 0 },
                                    xAxis: { type: 'category', boundaryGap: false, data: history.map(h=>h.lbl), show: false },
                                    yAxis: { type: 'value', show: false },
                                    series: [{
                                        type: 'line',
                                        data: history.map(h=>h.val),
                                        itemStyle: { color: color },
                                        areaStyle: {
                                            color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                                                { offset: 0, color: color },
                                                { offset: 1, color: 'transparent' }
                                            ]),
                                            opacity: 0.25
                                        },
                                        smooth: true,
                                        showSymbol: false,
                                        connectNulls: true,
                                        lineStyle: { width: 1.5, color: color }
                                    }]
                                });
                            }
                        } else if (p.type === 'gauge') {
                            let curVal = parseFloat(m.current);
                            if (isNaN(curVal)) curVal = 0;
                            chartInstances[uniqueId].setOption({
                                series: [{
                                    type: 'pie', radius: ['70%', '100%'], center: ['50%', '70%'],
                                    startAngle: 180, endAngle: 0,
                                    data: [
                                        { value: curVal, itemStyle: { color: color } },
                                        { value: Math.max(0, 100 - curVal), itemStyle: { color: '#eee' } }
                                    ],
                                    label: { show: false }, silent: true
                                }]
                            });
                        } else if (['line','area','bar'].includes(p.type)) {
                            const isModTraffic = isTrafficMetric(m.module_name, m.unit, p.title);
                            const isByte = isModTraffic && isByteTrafficMetric(m.module_name, m.unit, p.title);
                            const singleChartData = (history || []).map(h => {
                                if (h.val !== null && h.val !== undefined && h.val !== '' && !isNaN(h.val)) {
                                    return isByte ? (Number(h.val) * 8) : h.val;
                                }
                                return null;
                            });

                            const unitStr = (m.unit || '').trim();
                            const combinedStr = `${m.module_name || ''} ${unitStr} ${p.title || ''}`.toLowerCase();
                            const isPct = unitStr === '%' || combinedStr.includes('%') || /cpu|usage|load|mem|disk|utilization|loss|drop/.test(combinedStr);

                            chartInstances[uniqueId].setOption({
                                tooltip: { 
                                    trigger: 'axis', 
                                    confine: true,
                                    appendToBody: true,
                                    backgroundColor: 'rgba(15, 23, 42, 0.95)', 
                                    textStyle: { color: '#cbd5e1', fontSize: chartFs + 2 }, 
                                    padding: 10, 
                                    borderRadius: 6,
                                    formatter: function(params) {
                                        let html = params[0].name ? params[0].name + '<br/>' : '';
                                        params.forEach(sp => {
                                            let val = sp.value;
                                            let displayVal = 'N/A';
                                            if (val !== null && val !== undefined && val !== '' && !isNaN(val)) {
                                                if (isModTraffic) {
                                                    displayVal = formatBitsRate(val);
                                                } else {
                                                    displayVal = formatHumanMetric(val, m.unit, true, m.module_name, p.title);
                                                }
                                            }
                                            html += `${sp.marker}${sp.seriesName}: <b>${displayVal}</b><br/>`;
                                        });
                                        return html;
                                    }
                                },
                                grid: { left: 8, right: 15, top: 12, bottom: p.show_time ? 28 : 10, containLabel: true },
                                xAxis: { 
                                    type: 'category', 
                                    boundaryGap: p.type === 'bar', 
                                    data: history.map(h=>h.lbl), 
                                    show: !!p.show_time, 
                                    axisLabel: { fontSize: Math.max(8, chartFs - 2), color: '#64748b' },
                                    axisLine: { show: false },
                                    axisTick: { show: false }
                                },
                                yAxis: { 
                                    type: 'value', 
                                    show: (p.show_yaxis !== false),
                                    max: (p.force_100 || (unitStr === '%' || (isPct && !isModTraffic))) ? 100 : null,
                                    splitLine: { 
                                        show: (p.show_yaxis !== false), 
                                        lineStyle: { color: '#f0f3f5' } 
                                    },
                                    axisLine: { show: false },
                                    axisTick: { show: false },
                                    axisLabel: {
                                        fontSize: Math.max(8, chartFs - 2),
                                        color: '#64748b',
                                        formatter: function(value) {
                                            const u = unitStr.toLowerCase();
                                            const isTrafficUnit = isModTraffic || u.includes('byte') || u.includes('b/s') || u.includes('bit') || u.includes('bps') || u.includes('octet');
                                            if (isTrafficUnit) {
                                                if (value >= 1000000000) return (value / 1000000000).toFixed(1) + ' Gbps';
                                                if (value >= 1000000) return (value / 1000000).toFixed(1) + ' Mbps';
                                                if (value >= 1000) return (value / 1000).toFixed(1) + ' Kbps';
                                                return value.toFixed(0) + ' bps';
                                            }
                                            if (unitStr === '%' || (isPct && !isModTraffic)) {
                                                return (Number.isInteger(value) ? value : parseFloat(value.toFixed(1))) + '%';
                                            }
                                            if (unitStr === 'ms') return (Number.isInteger(value) ? value : parseFloat(value.toFixed(1))) + ' ms';
                                            if (unitStr === 'dBm') return parseFloat(value.toFixed(1)) + ' dBm';
                                            if (Math.abs(value) >= 1000000000) return (value / 1000000000).toFixed(1) + (unitStr ? ' G' + unitStr : 'G');
                                            if (Math.abs(value) >= 1000000) return (value / 1000000).toFixed(1) + (unitStr ? ' M' + unitStr : 'M');
                                            if (Math.abs(value) >= 1000) return (value / 1000).toFixed(1) + (unitStr ? ' k' + unitStr : 'k');
                                            return (Number.isInteger(value) ? value : parseFloat(value.toFixed(2))) + (unitStr ? ' ' + unitStr : '');
                                        }
                                    }
                                },
                                series: [{
                                    name: `${m.agent_name} - ${m.module_name}`,
                                    type: p.type === 'bar' ? 'bar' : 'line',
                                    data: singleChartData,
                                    itemStyle: { color: color },
                                    areaStyle: p.type === 'area' ? { opacity: 0.15, color: color } : undefined,
                                    smooth: true, showSymbol: false, connectNulls: true, lineStyle: { width: p.type === 'bar' ? 0 : 2 }
                                }]
                            });

                            const legendEl = document.getElementById(`chart_legend_${uniqueId}`);
                            if (legendEl) {
                                const safeName = seriesKey.replace(/"/g, '&quot;');
                                const safeJsName = seriesKey.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                                legendEl.innerHTML = `<div class="legend-chip" data-series="${safeName}" style="display: inline-flex; align-items: center; gap: 6px; font-size: ${Math.max(9, chartFs - 1)}px; color: #475569; user-select: none;" title="${safeName} (click dot to change color)">
                                    <span class="legend-color-dot" data-color="${color}" style="background-color: ${color};" onclick="openSeriesColorPicker(event, '${p.id}', '${safeJsName}', '${uniqueId}', this)" title="Click to change color"></span>
                                    <span style="white-space: nowrap; max-width: 260px; overflow: hidden; text-overflow: ellipsis;">${seriesKey}</span>
                                </div>`;
                            }

                            setTimeout(() => {
                                if (chartInstances[uniqueId] && typeof chartInstances[uniqueId].resize === 'function') {
                                    chartInstances[uniqueId].resize();
                                }
                            }, 50);
                        }
                        window.addEventListener('resize', () => chartInstances[uniqueId].resize());
                    } catch(e) { console.error("Chart build error:", e); }
                });
            }
        });
        setTimeout(resizeAllGridItems, 250); 
    })
    .catch(err => {
        console.error("Refresh Error:", err);
        currentDash.panels.forEach(p => {
            const wrapper = document.getElementById(`wrapper_p_${p.id}`);
            if(wrapper) wrapper.innerHTML = `<div class="panel-card"><div class="panel-body" style="color:#e74c3c; font-size:11px;">Error: ${err.message}</div></div>`;
        });
    });
}

function toggleChartEngine() {
    const type = document.getElementById('p_type').value;
    const box = document.getElementById('chart_engine_box');
    if (['line','area','bar'].includes(type)) {
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}

function togglePanelLayoutOptions() {
    const mode = document.querySelector('input[name="p_match_type"]:checked').value;
    document.getElementById('wrap_exact').style.display = (mode === 'exact') ? 'block' : 'none';
    document.getElementById('wrap_contains').style.display = (mode === 'exact') ? 'none' : 'block';
}

function toggleTypeFields() {
    const type = document.getElementById('p_type').value;
    const isHeatmap = (type === 'heatmap' || type === 'status_heatmap');
    document.getElementById('wrap_box_size').style.opacity = isHeatmap ? '1' : '0.3';
    document.getElementById('p_box_size').disabled = !isHeatmap;
    
    const wrapHeatmapOptions = document.getElementById('wrap_heatmap_options');
    if (wrapHeatmapOptions) {
        wrapHeatmapOptions.style.display = (type === 'status_heatmap') ? 'block' : 'none';
        if (type === 'status_heatmap') {
            toggleHeatmapCustomSizeDyn();
            toggleHeatmapCustomTextDyn();
        }
    }

    const wrapSparklineOptions = document.getElementById('wrap_sparkline_options');
    if (wrapSparklineOptions) {
        wrapSparklineOptions.style.display = (type === 'sparkline_table') ? 'block' : 'none';
    }

    const isChart = ['line','area','bar'].includes(type);
    if (document.getElementById('wrap_show_time')) document.getElementById('wrap_show_time').style.display = isChart ? 'flex' : 'none';
    if (document.getElementById('wrap_show_yaxis')) document.getElementById('wrap_show_yaxis').style.display = isChart ? 'flex' : 'none';
    if (document.getElementById('wrap_force_100')) document.getElementById('wrap_force_100').style.display = isChart ? 'flex' : 'none';
    const wrapChartColors = document.getElementById('wrap_chart_colors');
    if (wrapChartColors) {
        wrapChartColors.style.display = isChart ? 'block' : 'none';
    }
    const isMulti = document.getElementById('p_multi_overlay') ? document.getElementById('p_multi_overlay').checked : false;
    if (document.getElementById('wrap_single_chart_color')) {
        document.getElementById('wrap_single_chart_color').style.display = (isChart && !isMulti) ? 'block' : 'none';
    }
    if (document.getElementById('wrap_series_colors_list')) {
        document.getElementById('wrap_series_colors_list').style.display = (isChart && isMulti) ? 'block' : 'none';
    }
    const wrapChartFont = document.getElementById('wrap_chart_font');
    if (wrapChartFont) {
        wrapChartFont.style.opacity = isChart ? '1' : '0.3';
        document.getElementById('p_chart_font_size').disabled = !isChart;
    }

    const isTable = (type === 'status_table' || type === 'history_table' || type === 'table_viewer' || type === 'sparkline_table');
    const wrapLimit = document.getElementById('wrap_row_limit');
    if (wrapLimit) {
        wrapLimit.style.opacity = isTable ? '1' : '0.3';
        document.getElementById('p_row_limit').disabled = !isTable;
    }

    const isValueType = (type === 'text' || type === 'single_value');
    const wrapValueCalc = document.getElementById('wrap_value_calc');
    if (wrapValueCalc) {
        wrapValueCalc.style.display = isValueType ? 'block' : 'none';
    }

    const lblFontSize = document.getElementById('lbl_font_size');
    const lblFontWeight = document.getElementById('lbl_font_weight');
    const wrapFontOptions = document.getElementById('wrap_font_options');
    if (lblFontSize && lblFontWeight && wrapFontOptions) {
        if (type === 'table_viewer' || type === 'sparkline_table') {
            wrapFontOptions.style.display = 'flex';
            lblFontSize.innerText = 'Table Font Size (px)';
            lblFontWeight.innerText = 'Table Font Weight';
            document.getElementById('p_font_size').placeholder = '12';
        } else if (type === 'status_heatmap' || type === 'heatmap') {
            wrapFontOptions.style.display = 'flex';
            lblFontSize.innerText = 'Heatmap Font Size (px)';
            lblFontWeight.innerText = 'Heatmap Font Weight';
            document.getElementById('p_font_size').placeholder = '11';
        } else if (type === 'text' || type === 'single_value' || type === 'gauge') {
            wrapFontOptions.style.display = 'flex';
            lblFontSize.innerText = 'Value Font Size (px)';
            lblFontWeight.innerText = 'Value Font Weight';
            document.getElementById('p_font_size').placeholder = '32';
        } else if (type === 'status_stats') {
            wrapFontOptions.style.display = 'flex';
            lblFontSize.innerText = 'Stat Value Font Size (px)';
            lblFontWeight.innerText = 'Stat Value Font Weight';
            document.getElementById('p_font_size').placeholder = '24';
        } else {
            wrapFontOptions.style.display = isChart ? 'none' : 'flex';
        }
    }
}

function toggleHeatmapCustomSizeDyn() {
    const boxSize = document.getElementById('p_box_size') ? document.getElementById('p_box_size').value : '';
    const wrapCustom = document.getElementById('wrap_heatmap_custom_size_dyn');
    if (wrapCustom) {
        wrapCustom.style.display = (boxSize === 'custom') ? 'flex' : 'none';
    }
}

function toggleHeatmapCustomTextDyn() {
    const textType = document.getElementById('p_heatmap_text_type') ? document.getElementById('p_heatmap_text_type').value : '';
    const wrapText = document.getElementById('wrap_heatmap_custom_text_dyn');
    if (wrapText) {
        wrapText.style.display = (textType === 'custom') ? 'block' : 'none';
    }
}

// =========================================================================
// VISUAL TYPE PREVIEW & INTERACTIVE GALLERY ENGINE
// =========================================================================
const VISUAL_TYPE_CATALOG = {
    'line': {
        title: 'Line Chart',
        group: 'timeseries',
        badge: 'Time-Series History',
        badgeColor: '#e0f2fe',
        badgeTextColor: '#0369a1',
        desc: 'Continuous line chart showing metric fluctuations over time. Ideal for bandwidth traffic, CPU load, memory usage, and analog sensors.',
        bestFor: 'Interface Bandwidth, CPU Load, Memory, Temperature',
        render: () => `<svg viewBox="0 0 280 85" style="width:100%; height:85px; display:block;"><line x1="28" y1="15" x2="270" y2="15" stroke="#f1f5f9" stroke-dasharray="3"/><line x1="28" y1="45" x2="270" y2="45" stroke="#f1f5f9" stroke-dasharray="3"/><line x1="28" y1="72" x2="270" y2="72" stroke="#e2e8f0"/><text x="4" y="18" font-size="7" fill="#94a3b8">100</text><text x="9" y="48" font-size="7" fill="#94a3b8">50</text><text x="14" y="74" font-size="7" fill="#94a3b8">0</text><path d="M 32 65 Q 65 25 100 48 T 165 18 T 225 42 T 265 15" fill="none" stroke="#2563eb" stroke-width="2.5"/><circle cx="32" cy="65" r="3" fill="#2563eb"/><circle cx="100" cy="48" r="3" fill="#2563eb"/><circle cx="165" cy="18" r="3" fill="#2563eb"/><circle cx="225" cy="42" r="3" fill="#2563eb"/><circle cx="265" cy="15" r="3" fill="#2563eb"/><text x="32" y="82" font-size="8" fill="#94a3b8">00:00</text><text x="145" y="82" font-size="8" fill="#94a3b8">12:00</text><text x="250" y="82" font-size="8" fill="#94a3b8">23:00</text></svg>`
    },
    'area': {
        title: 'Area Chart',
        group: 'timeseries',
        badge: 'Time-Series History',
        badgeColor: '#e0f2fe',
        badgeTextColor: '#0369a1',
        desc: 'Area chart with smooth shaded gradient under the curve to emphasize cumulative volume and network throughput.',
        bestFor: 'Network In/Out Traffic, Data Throughput, Total Connections',
        render: () => `<svg viewBox="0 0 280 85" style="width:100%; height:85px; display:block;"><defs><linearGradient id="areaGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#0284c7" stop-opacity="0.45"/><stop offset="100%" stop-color="#0284c7" stop-opacity="0.03"/></linearGradient></defs><line x1="28" y1="15" x2="270" y2="15" stroke="#f1f5f9" stroke-dasharray="3"/><line x1="28" y1="45" x2="270" y2="45" stroke="#f1f5f9" stroke-dasharray="3"/><line x1="28" y1="72" x2="270" y2="72" stroke="#e2e8f0"/><text x="3" y="18" font-size="7" fill="#94a3b8">100M</text><text x="8" y="48" font-size="7" fill="#94a3b8">50M</text><text x="14" y="74" font-size="7" fill="#94a3b8">0</text><path d="M 32 68 Q 65 18 105 42 T 175 16 T 235 36 T 265 14 L 265 72 L 32 72 Z" fill="url(#areaGrad)"/><path d="M 32 68 Q 65 18 105 42 T 175 16 T 235 36 T 265 14" fill="none" stroke="#0284c7" stroke-width="2.5"/><circle cx="175" cy="16" r="3" fill="#0284c7"/><text x="32" y="82" font-size="8" fill="#94a3b8">In: 45 Mbps</text><text x="205" y="82" font-size="8" fill="#0284c7" font-weight="bold">Out: 120 Mbps</text></svg>`
    },
    'bar': {
        title: 'Bar Chart',
        group: 'timeseries',
        badge: 'Time-Series History',
        badgeColor: '#e0f2fe',
        badgeTextColor: '#0369a1',
        desc: 'Periodic vertical bar chart ideal for discrete intervals and detecting spikes per hour or day.',
        bestFor: 'Request Count, Error Spikes, Hit Counts, Disk IOPS',
        render: () => `<svg viewBox="0 0 280 85" style="width:100%; height:85px; display:block;"><line x1="24" y1="72" x2="270" y2="72" stroke="#e2e8f0"/><line x1="24" y1="15" x2="270" y2="15" stroke="#f1f5f9" stroke-dasharray="3"/><line x1="24" y1="45" x2="270" y2="45" stroke="#f1f5f9" stroke-dasharray="3"/><text x="3" y="18" font-size="7" fill="#94a3b8">60</text><text x="3" y="48" font-size="7" fill="#94a3b8">30</text><text x="7" y="74" font-size="7" fill="#94a3b8">0</text><rect x="32" y="38" width="16" height="34" rx="2" fill="#0d9488"/><rect x="62" y="22" width="16" height="50" rx="2" fill="#0d9488"/><rect x="92" y="48" width="16" height="24" rx="2" fill="#0d9488"/><rect x="122" y="15" width="16" height="57" rx="2" fill="#0f766e"/><rect x="152" y="32" width="16" height="40" rx="2" fill="#0d9488"/><rect x="182" y="55" width="16" height="17" rx="2" fill="#0d9488"/><rect x="212" y="28" width="16" height="44" rx="2" fill="#0d9488"/><rect x="242" y="42" width="16" height="30" rx="2" fill="#0d9488"/><text x="32" y="82" font-size="7" fill="#94a3b8">02:00</text><text x="122" y="82" font-size="7" fill="#94a3b8">10:00</text><text x="212" y="82" font-size="7" fill="#94a3b8">18:00</text></svg>`
    },
    'gauge': {
        title: 'Gauge Chart',
        group: 'kpi',
        badge: 'Gauge & Indicator',
        badgeColor: '#fef3c7',
        badgeTextColor: '#b45309',
        desc: 'Circular 0–100% speedometer with analog needle and threshold arc indicators (OK = Green, Warning = Yellow, Critical = Red).',
        bestFor: 'CPU Utilization %, RAM Usage %, Disk Partition %, Temperature',
        render: () => `<svg viewBox="0 0 280 85" style="width:100%; height:85px; display:block;"><path d="M 85 70 A 55 55 0 0 1 195 70" fill="none" stroke="#e2e8f0" stroke-width="12" stroke-linecap="round"/><path d="M 85 70 A 55 55 0 0 1 140 15" fill="none" stroke="#10b981" stroke-width="12" stroke-linecap="round"/><path d="M 140 15 A 55 55 0 0 1 175 28" fill="none" stroke="#f59e0b" stroke-width="12"/><path d="M 175 28 A 55 55 0 0 1 195 70" fill="none" stroke="#ef4444" stroke-width="12" stroke-linecap="round"/><circle cx="140" cy="70" r="5" fill="#0f172a"/><line x1="140" y1="70" x2="168" y2="35" stroke="#0f172a" stroke-width="3" stroke-linecap="round"/><text x="140" y="64" text-anchor="middle" font-size="14" font-weight="bold" fill="#0f172a">76 %</text><text x="140" y="78" text-anchor="middle" font-size="8" fill="#64748b">CPU Usage (Warning)</text></svg>`
    },
    'single_value': {
        title: 'Single Value Card (Sparkline)',
        group: 'kpi',
        badge: 'KPI & Quick Trend',
        badgeColor: '#dcfce7',
        badgeTextColor: '#15803d',
        desc: 'Prominent numeric metric card with status pill badge and a mini sparkline chart to track 24-hour trends at a glance.',
        bestFor: 'Wallboard Key Metrics, Traffic Out, Ping Latency, Active Users',
        render: () => `<div style="display:flex; align-items:center; justify-content:space-between; width:100%; max-width:270px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px;"><div style="text-align:left;"><div style="font-size:9px; font-weight:700; color:#64748b; text-transform:uppercase;">WAN Outbound</div><div style="font-size:20px; font-weight:800; color:#0b1a26; line-height:1.2;">142.5 <span style="font-size:11px; font-weight:600; color:#64748b;">Mbps</span></div><div style="font-size:9px; color:#10b981; font-weight:600; margin-top:2px;">▲ +12% vs last hour</div></div><div style="width:70px; height:35px; text-align:right;"><svg viewBox="0 0 70 35" style="width:100%; height:25px;"><path d="M 2 28 Q 18 8 35 20 T 68 5" fill="none" stroke="#10b981" stroke-width="2.2"/><circle cx="68" cy="5" r="2.5" fill="#10b981"/></svg><div style="font-size:8px; font-weight:700; color:#047857; background:#d1fae5; border-radius:3px; padding:1px 4px; display:inline-block;">NORMAL</div></div></div>`
    },
    'text': {
        title: 'Value Number / Text',
        group: 'kpi',
        badge: 'Key Text / Value',
        badgeColor: '#f1f5f9',
        badgeTextColor: '#475569',
        desc: 'Displays status text (e.g., UP, ESTABLISHED, ACTIVE) or large numbers with a status dot indicator, font size, and flexible background color.',
        bestFor: 'BGP Peer Status, Service State (UP/DOWN), Cluster Active Role',
        render: () => `<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; width:100%; max-width:250px; background:#ffffff; border:1px solid #e2e8f0; border-radius:6px; padding:8px 12px; box-shadow:0 1px 3px rgba(0,0,0,0.02);"><div style="font-size:9px; font-weight:700; color:#64748b; letter-spacing:0.5px;">BGP PEER STATUS</div><div style="font-size:22px; font-weight:800; color:#10b981; margin:2px 0;">ESTABLISHED</div><div style="display:flex; align-items:center; gap:4px; font-size:10px; color:#64748b;"><span style="width:6px; height:6px; background:#10b981; border-radius:50%; display:inline-block;"></span> Uptime: 45 days 12 hrs</div></div>`
    },
    'heatmap': {
        title: 'History Heatmap Blocks',
        group: 'timeseries',
        badge: 'Timeline History',
        badgeColor: '#e0f2fe',
        badgeTextColor: '#0369a1',
        desc: 'Horizontal time-slice status blocks (similar to commit heatmaps) showcasing device stability and downtime history.',
        bestFor: '24-Hour Stability Audit, Flapping & Incident Detection',
        render: () => `<div style="width:100%; max-width:270px;"><div style="display:flex; justify-content:space-between; font-size:8px; color:#64748b; margin-bottom:3px;"><span>00:00 (Uptime History)</span><span>23:59</span></div><div style="display:grid; grid-template-columns:repeat(14, 1fr); gap:2px;"><div style="height:14px; background:#10b981; border-radius:2px;"></div><div style="height:14px; background:#10b981; border-radius:2px;"></div><div style="height:14px; background:#10b981; border-radius:2px;"></div><div style="height:14px; background:#10b981; border-radius:2px;"></div><div style="height:14px; background:#f59e0b; border-radius:2px;"></div><div style="height:14px; background:#10b981; border-radius:2px;"></div><div style="height:14px; background:#10b981; border-radius:2px;"></div><div style="height:14px; background:#10b981; border-radius:2px;"></div><div style="height:14px; background:#ef4444; border-radius:2px;"></div><div style="height:14px; background:#10b981; border-radius:2px;"></div><div style="height:14px; background:#10b981; border-radius:2px;"></div><div style="height:14px; background:#10b981; border-radius:2px;"></div><div style="height:14px; background:#10b981; border-radius:2px;"></div><div style="height:14px; background:#10b981; border-radius:2px;"></div></div><div style="font-size:8px; color:#10b981; font-weight:600; margin-top:4px; text-align:right;">99.4% Availability (24h)</div></div>`
    },
    'history_table': {
        title: 'History Table View',
        group: 'table',
        badge: 'Log History Table',
        badgeColor: '#f1f5f9',
        badgeTextColor: '#475569',
        desc: 'Structured tabular log of historical data by timestamp, useful for auditing, log lookups, or incident tracking.',
        bestFor: 'Event Audit Trail, Sensor History Log, Metric Recap',
        render: () => `<table style="width:100%; max-width:270px; font-size:8px; border-collapse:collapse; background:#fff; border:1px solid #e2e8f0; border-radius:4px; overflow:hidden;"><thead style="background:#f1f5f9; color:#475569; font-weight:700;"><tr style="border-bottom:1px solid #e2e8f0;"><th style="padding:2px 4px; text-align:left;">Timestamp</th><th style="padding:2px 4px; text-align:right;">Value</th><th style="padding:2px 4px; text-align:center;">State</th></tr></thead><tbody><tr style="border-bottom:1px solid #f8fafc;"><td style="padding:2px 4px; color:#64748b;">2026-10-09 14:35</td><td style="padding:2px 4px; text-align:right; font-weight:600;">45.2 Mbps</td><td style="padding:2px 4px; text-align:center;"><span style="background:#d1fae5; color:#047857; font-weight:700; padding:1px 3px; border-radius:2px;">OK</span></td></tr><tr style="border-bottom:1px solid #f8fafc;"><td style="padding:2px 4px; color:#64748b;">2026-10-09 14:30</td><td style="padding:2px 4px; text-align:right; font-weight:600;">42.8 Mbps</td><td style="padding:2px 4px; text-align:center;"><span style="background:#d1fae5; color:#047857; font-weight:700; padding:1px 3px; border-radius:2px;">OK</span></td></tr><tr><td style="padding:2px 4px; color:#64748b;">2026-10-09 14:25</td><td style="padding:2px 4px; text-align:right; font-weight:600;">85.1 Mbps</td><td style="padding:2px 4px; text-align:center;"><span style="background:#fef3c7; color:#b45309; font-weight:700; padding:1px 3px; border-radius:2px;">WARN</span></td></tr></tbody></table>`
    },
    'sparkline_table': {
        title: 'Sparkline Table (Trend Over Time)',
        group: 'table',
        badge: 'Table with Trend',
        badgeColor: '#ede9fe',
        badgeTextColor: '#6d28d9',
        desc: 'Multi-node intelligent table where each row includes a 24-hour mini sparkline chart to detect anomalies instantly.',
        bestFor: 'Multi-Node Monitoring, Compact Overview + Trend Graph',
        render: () => `<table style="width:100%; max-width:280px; font-size:8px; border-collapse:collapse; background:#fff; border:1px solid #e2e8f0; border-radius:4px; overflow:hidden;"><thead style="background:#f1f5f9; color:#475569; font-weight:700;"><tr style="border-bottom:1px solid #e2e8f0;"><th style="padding:2px 4px; text-align:left;">Node / Target</th><th style="padding:2px 4px; text-align:right;">Current</th><th style="padding:2px 4px; text-align:center;">Trend (24h)</th></tr></thead><tbody><tr style="border-bottom:1px solid #f8fafc;"><td style="padding:2px 4px; font-weight:600;">RT-CORE-01</td><td style="padding:2px 4px; text-align:right; font-weight:600;">145 Mbps</td><td style="padding:1px 4px; text-align:center;"><svg width="55" height="14"><path d="M 2 11 Q 15 2 28 7 T 53 3" fill="none" stroke="#2563eb" stroke-width="1.8"/></svg></td></tr><tr><td style="padding:2px 4px; font-weight:600;">SW-DIST-02</td><td style="padding:2px 4px; text-align:right; font-weight:600;">64 Mbps</td><td style="padding:1px 4px; text-align:center;"><svg width="55" height="14"><path d="M 2 4 Q 18 13 32 5 T 53 2" fill="none" stroke="#10b981" stroke-width="1.8"/></svg></td></tr></tbody></table>`
    },
    'status_table': {
        title: 'Table View (Current Status)',
        group: 'status',
        badge: 'Real-Time Data Table',
        badgeColor: '#ecfdf5',
        badgeTextColor: '#065f46',
        desc: 'Real-time status table of all matching nodes/modules with device name, IP address, current value, and status badges.',
        bestFor: 'Inventory Module Status List, Router/Switch Interface Summary',
        render: () => `<table style="width:100%; max-width:280px; font-size:8px; border-collapse:collapse; background:#fff; border:1px solid #e2e8f0; border-radius:4px; overflow:hidden;"><thead style="background:#f1f5f9; color:#475569; font-weight:700;"><tr style="border-bottom:1px solid #e2e8f0;"><th style="padding:2px 4px; text-align:left;">Device</th><th style="padding:2px 4px; text-align:left;">Module</th><th style="padding:2px 4px; text-align:center;">Status</th><th style="padding:2px 4px; text-align:right;">Val</th></tr></thead><tbody><tr style="border-bottom:1px solid #f8fafc;"><td style="padding:2px 4px; font-weight:600;">SRV-WEB-01</td><td style="padding:2px 4px; color:#64748b;">CPU Load</td><td style="padding:2px 4px; text-align:center;"><span style="background:#d1fae5; color:#047857; font-weight:700; padding:1px 3px; border-radius:2px;">OK</span></td><td style="padding:2px 4px; text-align:right; font-weight:600;">24 %</td></tr><tr style="border-bottom:1px solid #f8fafc;"><td style="padding:2px 4px; font-weight:600;">SRV-DB-01</td><td style="padding:2px 4px; color:#64748b;">Disk C:</td><td style="padding:2px 4px; text-align:center;"><span style="background:#fef3c7; color:#b45309; font-weight:700; padding:1px 3px; border-radius:2px;">WARN</span></td><td style="padding:2px 4px; text-align:right; font-weight:600;">88 %</td></tr><tr><td style="padding:2px 4px; font-weight:600;">SW-EDGE-01</td><td style="padding:2px 4px; color:#64748b;">Ping</td><td style="padding:2px 4px; text-align:center;"><span style="background:#fee2e2; color:#b91c1c; font-weight:700; padding:1px 3px; border-radius:2px;">CRIT</span></td><td style="padding:2px 4px; text-align:right; font-weight:600;">0 ms</td></tr></tbody></table>`
    },
    'status_heatmap': {
        title: 'Heatmap View (Current Status)',
        group: 'status',
        badge: 'Status Grid Matrix',
        badgeColor: '#ecfdf5',
        badgeTextColor: '#065f46',
        desc: 'Color-coded status grid matrix (Green = OK, Yellow = Warn, Red = Crit) to monitor dozens or hundreds of devices on one screen.',
        bestFor: 'NOC Wallboard, Server Farm Status Grid, Device Health Matrix',
        render: () => `<div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:4px; width:100%; max-width:270px;"><div style="background:#10b981; color:#fff; border-radius:3px; padding:4px; text-align:center; font-size:8px; font-weight:600;">SRV-01<br><span style="font-size:7px; opacity:0.85;">28%</span></div><div style="background:#10b981; color:#fff; border-radius:3px; padding:4px; text-align:center; font-size:8px; font-weight:600;">SRV-02<br><span style="font-size:7px; opacity:0.85;">34%</span></div><div style="background:#f59e0b; color:#fff; border-radius:3px; padding:4px; text-align:center; font-size:8px; font-weight:600;">SRV-03<br><span style="font-size:8px; opacity:0.85;">84%</span></div><div style="background:#10b981; color:#fff; border-radius:3px; padding:4px; text-align:center; font-size:8px; font-weight:600;">SW-CORE<br><span style="font-size:7px; opacity:0.85;">12%</span></div><div style="background:#ef4444; color:#fff; border-radius:3px; padding:4px; text-align:center; font-size:8px; font-weight:600;">ROUTER<br><span style="font-size:7px; opacity:0.85;">DOWN</span></div><div style="background:#10b981; color:#fff; border-radius:3px; padding:4px; text-align:center; font-size:8px; font-weight:600;">FIREWALL<br><span style="font-size:7px; opacity:0.85;">45%</span></div><div style="background:#10b981; color:#fff; border-radius:3px; padding:4px; text-align:center; font-size:8px; font-weight:600;">STORAGE<br><span style="font-size:7px; opacity:0.85;">52%</span></div><div style="background:#10b981; color:#fff; border-radius:3px; padding:4px; text-align:center; font-size:8px; font-weight:600;">DB-MASTER<br><span style="font-size:7px; opacity:0.85;">61%</span></div></div>`
    },
    'status_stats': {
        title: 'Stats Cards (Current Status)',
        group: 'status',
        badge: 'Status Summary',
        badgeColor: '#ecfdf5',
        badgeTextColor: '#065f46',
        desc: 'Real-time aggregate status counter cards (Total OK, Warning, Critical, Unknown) across all matching agents or modules.',
        bestFor: 'Dashboard Top Counters, Overall Health Summary',
        render: () => `<div style="display:flex; gap:6px; width:100%; max-width:280px; justify-content:center;"><div style="flex:1; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:5px; padding:5px 3px; text-align:center;"><div style="font-size:8px; font-weight:700; color:#065f46;">OK</div><div style="font-size:15px; font-weight:800; color:#047857;">42</div></div><div style="flex:1; background:#fffbeb; border:1px solid #fde68a; border-radius:5px; padding:5px 3px; text-align:center;"><div style="font-size:8px; font-weight:700; color:#92400e;">WARN</div><div style="font-size:15px; font-weight:800; color:#b45309;">3</div></div><div style="flex:1; background:#fef2f2; border:1px solid #fecaca; border-radius:5px; padding:5px 3px; text-align:center;"><div style="font-size:8px; font-weight:700; color:#991b1b;">CRIT</div><div style="font-size:15px; font-weight:800; color:#b91c1c;">1</div></div><div style="flex:1; background:#f8fafc; border:1px solid #e2e8f0; border-radius:5px; padding:5px 3px; text-align:center;"><div style="font-size:8px; font-weight:700; color:#475569;">UNKN</div><div style="font-size:15px; font-weight:800; color:#64748b;">0</div></div></div>`
    },
    'pie': {
        title: 'Pie Chart (Current Status)',
        group: 'status',
        badge: 'Proportion Distribution',
        badgeColor: '#ecfdf5',
        badgeTextColor: '#065f46',
        desc: 'Full circular pie chart showing status proportion ratios (percentage OK vs Warning vs Critical).',
        bestFor: 'System Health Ratio, Global Status Distribution',
        render: () => `<svg viewBox="0 0 280 85" style="width:100%; height:85px; display:block;"><g transform="translate(80, 42)"><circle r="36" fill="#10b981"/><path d="M 0 0 L 0 -36 A 36 36 0 0 1 34 11 Z" fill="#f59e0b"/><path d="M 0 0 L 34 11 A 36 36 0 0 1 20 30 Z" fill="#ef4444"/><circle r="2" fill="#fff"/></g><g transform="translate(145, 18)"><rect x="0" y="0" width="8" height="8" rx="2" fill="#10b981"/><text x="14" y="8" font-size="9" fill="#334155" font-weight="600">OK (78%)</text><rect x="0" y="16" width="8" height="8" rx="2" fill="#f59e0b"/><text x="14" y="24" font-size="9" fill="#334155" font-weight="600">Warning (16%)</text><rect x="0" y="32" width="8" height="8" rx="2" fill="#ef4444"/><text x="14" y="40" font-size="9" fill="#334155" font-weight="600">Critical (6%)</text></g></svg>`
    },
    'donut': {
        title: 'Donut Chart (Current Status)',
        group: 'status',
        badge: 'Ring Distribution',
        badgeColor: '#ecfdf5',
        badgeTextColor: '#065f46',
        desc: 'Modern donut chart with center metric total display, offering a clean and executive visual layout.',
        bestFor: 'Host / Module Distribution, Executive Dashboard Summary',
        render: () => `<svg viewBox="0 0 280 85" style="width:100%; height:85px; display:block;"><g transform="translate(80, 42)"><circle r="36" fill="none" stroke="#e2e8f0" stroke-width="14"/><circle r="36" fill="none" stroke="#10b981" stroke-width="14" stroke-dasharray="170 226" transform="rotate(-90)"/><circle r="36" fill="none" stroke="#f59e0b" stroke-width="14" stroke-dasharray="35 226" stroke-dashoffset="-170" transform="rotate(-90)"/><circle r="36" fill="none" stroke="#ef4444" stroke-width="14" stroke-dasharray="21 226" stroke-dashoffset="-205" transform="rotate(-90)"/><text x="0" y="3" text-anchor="middle" font-size="12" font-weight="bold" fill="#0f172a">48</text><text x="0" y="14" text-anchor="middle" font-size="6" fill="#64748b">TOTAL</text></g><g transform="translate(145, 18)"><rect x="0" y="0" width="8" height="8" rx="2" fill="#10b981"/><text x="14" y="8" font-size="9" fill="#334155" font-weight="600">OK (38)</text><rect x="0" y="16" width="8" height="8" rx="2" fill="#f59e0b"/><text x="14" y="24" font-size="9" fill="#334155" font-weight="600">Warning (7)</text><rect x="0" y="32" width="8" height="8" rx="2" fill="#ef4444"/><text x="14" y="40" font-size="9" fill="#334155" font-weight="600">Critical (3)</text></g></svg>`
    },
    'table_viewer': {
        title: 'View Snapshot Module',
        group: 'table',
        badge: 'Snapshot / SNMP Table',
        badgeColor: '#f1f5f9',
        badgeTextColor: '#475569',
        desc: 'Structured table viewer tailored for snapshot modules (such as SNMP interface tables, routing tables, or inventory modules).',
        bestFor: 'SNMP Port Table, Text / CSV Snapshot Modules',
        render: () => `<div style="width:100%; max-width:280px; background:#fff; border:1px solid #e2e8f0; border-radius:4px; overflow:hidden;"><div style="background:#f8fafc; padding:2px 5px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;"><span style="font-size:7px; font-weight:700; color:#475569;">SNMP INTERFACE TABLE</span><span style="font-size:7px; color:#64748b; background:#e2e8f0; padding:1px 3px; border-radius:2px;">Search 🔍</span></div><table style="width:100%; font-size:7px; border-collapse:collapse;"><tr style="background:#f1f5f9; color:#475569; font-weight:600;"><th style="padding:2px 4px; text-align:left;">ifName</th><th style="padding:2px 4px; text-align:left;">Admin</th><th style="padding:2px 4px; text-align:left;">Oper</th><th style="padding:2px 4px; text-align:right;">Speed</th></tr><tr style="border-bottom:1px solid #f8fafc;"><td style="padding:2px 4px;">Gi0/0/1</td><td style="padding:2px 4px; color:#10b981;">UP</td><td style="padding:2px 4px; color:#10b981;">UP</td><td style="padding:2px 4px; text-align:right;">1 Gbps</td></tr><tr><td style="padding:2px 4px;">Te0/1/0</td><td style="padding:2px 4px; color:#10b981;">UP</td><td style="padding:2px 4px; color:#ef4444;">DOWN</td><td style="padding:2px 4px; text-align:right;">10 Gbps</td></tr></table></div>`
    }
};

let currentGalleryFilter = 'all';

function updateVisualTypePreview() {
    const sel = document.getElementById('p_type');
    const type = sel ? sel.value : 'line';
    const info = VISUAL_TYPE_CATALOG[type] || VISUAL_TYPE_CATALOG['line'];
    
    const badgeEl = document.getElementById('vtp_badge');
    const mockupEl = document.getElementById('vtp_mockup_area');
    const descEl = document.getElementById('vtp_description');
    
    if (badgeEl) {
        badgeEl.innerText = info.badge || info.title;
        badgeEl.style.backgroundColor = info.badgeColor || '#e0f2fe';
        badgeEl.style.color = info.badgeTextColor || '#0369a1';
    }
    if (mockupEl) {
        mockupEl.innerHTML = info.render();
    }
    if (descEl) {
        descEl.innerHTML = `<strong>${escapeHtml(info.title)}:</strong> ${escapeHtml(info.desc)} <div style="margin-top:3px; font-size:10px; color:#004d40;"><strong>Recommended For:</strong> ${escapeHtml(info.bestFor)}</div>`;
    }
}

function openVisualGalleryModal() {
    currentGalleryFilter = 'all';
    renderVisualGalleryCards('all');
    document.querySelectorAll('.vtp-tab-btn').forEach(b => b.classList.remove('active'));
    const firstTab = document.querySelector('.vtp-tab-btn');
    if (firstTab) firstTab.classList.add('active');
    document.getElementById('visualGalleryModal').style.display = 'flex';
}

function closeVisualGalleryModal() {
    document.getElementById('visualGalleryModal').style.display = 'none';
}

function setVisualGalleryFilter(filter, btn) {
    currentGalleryFilter = filter;
    document.querySelectorAll('.vtp-tab-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    renderVisualGalleryCards(filter);
}

function selectVisualTypeFromGallery(type) {
    const sel = document.getElementById('p_type');
    if (sel) {
        sel.value = type;
        toggleTypeFields();
        toggleChartEngine();
        updateVisualTypePreview();
    }
    closeVisualGalleryModal();
}

function renderVisualGalleryCards(filter = 'all') {
    const grid = document.getElementById('vtp_gallery_grid');
    if (!grid) return;
    
    const currentSelected = document.getElementById('p_type') ? document.getElementById('p_type').value : '';
    
    let html = '';
    Object.keys(VISUAL_TYPE_CATALOG).forEach(key => {
        const item = VISUAL_TYPE_CATALOG[key];
        if (filter !== 'all' && item.group !== filter) return;
        
        const isCurrent = (key === currentSelected);
        const cardClass = isCurrent ? 'vtp-gallery-card is-active' : 'vtp-gallery-card';
        
        html += `
        <div class="${cardClass}" onclick="selectVisualTypeFromGallery('${key}')">
            <div>
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
                    <span style="font-weight:700; font-size:12px; color:#0f172a;">${escapeHtml(item.title)}</span>
                    <span style="font-size:9px; font-weight:600; padding:2px 8px; border-radius:10px; background:${item.badgeColor}; color:${item.badgeTextColor};">${escapeHtml(item.badge)}</span>
                </div>
                <div style="background:#f8fafc; border:1px solid #f1f5f9; border-radius:6px; padding:8px; display:flex; align-items:center; justify-content:center; min-height:85px; margin-bottom:8px;">
                    ${item.render()}
                </div>
                <p style="font-size:11px; color:#64748b; line-height:1.4; margin:0 0 6px 0;">${escapeHtml(item.desc)}</p>
            </div>
            <div style="display:flex; align-items:center; justify-content:space-between; border-top:1px solid #f1f5f9; padding-top:6px; margin-top:6px;">
                <span style="font-size:10px; color:#004d40; font-weight:600;">Best for: ${escapeHtml(item.bestFor.split(',')[0])}</span>
                <button type="button" class="btn btn-sm ${isCurrent ? 'btn-success' : 'btn-outline-primary'}" style="font-size:10px; padding:2px 8px;" onclick="event.stopPropagation(); selectVisualTypeFromGallery('${key}')">
                    ${isCurrent ? '✓ Selected' : 'Select'}
                </button>
            </div>
        </div>`;
    });
    grid.innerHTML = html;
}

function showExactDropdown() { document.getElementById('exact_dropdown').style.display = 'flex'; renderExactModuleList(1); }
function renderExactModuleList(page = 1) {
    const ul = document.getElementById('exact_module_ul');
    const kw = document.getElementById('exact_search_input').value.toLowerCase();
    const currentRaw = document.getElementById('p_keyword_exact').value;
    const rawArr = currentRaw ? currentRaw.split(',').map(s => s.trim()).filter(s => s !== '') : [];
    
    let filtered = globalModuleList.filter(m => m.pretty.toLowerCase().includes(kw) || m.raw.toLowerCase().includes(kw));
    ul.innerHTML = filtered.slice(0,50).map(m => {
        const isSelected = rawArr.includes(m.raw);
        const selClass = isSelected ? 'selected' : '';
        return `<li class="${selClass}" onclick="selectExactModule('${m.raw.replace(/'/g, "\\'")}', '${m.pretty.replace(/'/g, "\\'")}')">
            ${isSelected ? '<span class="material-symbols-outlined" style="font-size:14px; margin-right:5px; vertical-align:middle;">check_circle</span>' : ''}
            ${m.pretty}
        </li>`;
    }).join('');
}

function renderSelectedModules() {
    const container = document.getElementById('exact_selected_tags');
    const rawInput = document.getElementById('p_keyword_exact');
    const rawArr = rawInput.value ? rawInput.value.split(',').map(s => s.trim()).filter(s => s !== '') : [];
    
    container.innerHTML = '';
    rawArr.forEach(raw => {
        const found = globalModuleList.find(m => m.raw === raw);
        const prettyName = found ? found.pretty : raw;
        const tag = document.createElement('div');
        tag.className = 'module-tag';
        tag.innerHTML = `<span>${prettyName}</span><span class="remove-tag" onclick="removeModuleTag('${raw.replace(/'/g, "\\'")}')">&times;</span>`;
        container.appendChild(tag);
    });
}

function removeModuleTag(rawName) {
    let rawInput = document.getElementById('p_keyword_exact');
    let rawArr = rawInput.value.split(',').map(s => s.trim()).filter(s => s !== '');
    rawArr = rawArr.filter(r => r !== rawName);
    rawInput.value = rawArr.join(', ');
    renderSelectedModules();
    renderExactModuleList(1);
}

function selectExactModule(rawName, prettyName) {
    let rawInput = document.getElementById('p_keyword_exact');
    let currentRaw = rawInput.value;
    let rawArr = currentRaw ? currentRaw.split(',').map(s => s.trim()).filter(s => s !== '') : [];
    
    const idx = rawArr.indexOf(rawName);
    if (idx > -1) {
        rawArr.splice(idx, 1);
    } else {
        rawArr.push(rawName);
    }
    
    rawInput.value = rawArr.join(', ');
    renderSelectedModules();
    renderExactModuleList(1);
}

function openPanelBuilder() {
    editingPanelId = null;
    document.getElementById('panelModalTitle').innerText = 'Add New Panel Rule';
    document.getElementById('p_title').value = '';
    document.getElementById('p_keyword_exact').value = '';
    document.getElementById('exact_search_input').value = '';
    document.getElementById('exact_selected_tags').innerHTML = '';
    document.getElementById('p_keyword_contains').value = '';
    document.getElementById('p_type').value = 'line';
    document.getElementById('p_width').value = '12'; 
    document.getElementById('p_height').value = '200';
    document.getElementById('p_box_size').value = 'medium';
    if (document.getElementById('p_heatmap_text_type')) document.getElementById('p_heatmap_text_type').value = 'agent';
    if (document.getElementById('p_heatmap_custom_width')) document.getElementById('p_heatmap_custom_width').value = '';
    if (document.getElementById('p_heatmap_custom_height')) document.getElementById('p_heatmap_custom_height').value = '';
    if (document.getElementById('p_heatmap_custom_text')) document.getElementById('p_heatmap_custom_text').value = '';
    document.getElementById('p_row_limit').value = '200';
    document.getElementById('p_chart_font_size').value = '10';
    document.getElementById('p_lbl_1').value = '';
    document.getElementById('p_lbl_0').value = '';
    if (document.getElementById('p_value_calc')) document.getElementById('p_value_calc').value = 'current';
    document.getElementById('p_stat_bg_color').value = '#ffffff';
    document.getElementById('p_stat_bg_color_hex').value = '';
    document.getElementById('p_stat_font_color').value = '#334155';
    document.getElementById('p_stat_font_color_hex').value = '';
    document.getElementById('p_show_module').checked = true;
    document.getElementById('p_use_raw').checked = false;
    document.getElementById('p_auto_convert_traffic').checked = true;
    document.getElementById('p_show_time').checked = true;
    if (document.getElementById('p_show_yaxis')) document.getElementById('p_show_yaxis').checked = true;
    if (document.getElementById('p_hide_search_bar')) {
        document.getElementById('p_hide_search_bar').checked = false;
    }
    document.getElementById('p_hidden').checked = false;
    document.getElementById('p_multi_overlay').checked = false;
    if (document.getElementById('p_color_palette')) document.getElementById('p_color_palette').value = 'vibrant';
    if (document.getElementById('p_chart_color')) document.getElementById('p_chart_color').value = '#0284c7';
    if (document.getElementById('p_chart_color_hex')) document.getElementById('p_chart_color_hex').value = '';
    window.__editingPanelSeriesColors = {};
    document.querySelectorAll('.col-visibility-chk').forEach(chk => chk.checked = true);
    document.querySelector('input[name="p_match_type"][value="contains"]').checked = true;
    togglePanelLayoutOptions();
    toggleHeatmapCustomSizeDyn();
    toggleHeatmapCustomTextDyn();
    toggleTypeFields();
    toggleChartEngine();
    previewPaletteSelection();
    renderSeriesColorsEditor();
    updateVisualTypePreview();
    document.getElementById('panelModal').style.display = 'flex';
}

function openPanelEdit(id) {
    editingPanelId = id;
    const p = masterDashboards.find(d => d.id === currentDashId).panels.find(x => x.id === id);
    document.getElementById('p_title').value = p.title;
    document.getElementById('p_type').value = p.type;
    document.getElementById('p_width').value = p.width || 12;
    document.getElementById('p_height').value = p.height || 200;
    document.getElementById('p_box_size').value = p.box_size || 'medium';
    if (document.getElementById('p_heatmap_text_type')) document.getElementById('p_heatmap_text_type').value = p.heatmap_text_type || 'value';
    if (document.getElementById('p_heatmap_custom_width')) document.getElementById('p_heatmap_custom_width').value = p.heatmap_custom_width || '';
    if (document.getElementById('p_heatmap_custom_height')) document.getElementById('p_heatmap_custom_height').value = p.heatmap_custom_height || '';
    if (document.getElementById('p_heatmap_custom_text')) document.getElementById('p_heatmap_custom_text').value = p.heatmap_custom_text || '';
    document.getElementById('p_row_limit').value = p.row_limit || 200;
    document.getElementById('p_chart_font_size').value = p.chart_font_size || 10;
    document.getElementById('p_font_size').value = p.font_size || (p.type === 'table_viewer' ? 11 : 32);
    document.getElementById('p_font_weight').value = p.font_weight || (p.type === 'table_viewer' ? '400' : '700');
    if (document.getElementById('p_value_calc')) document.getElementById('p_value_calc').value = p.value_calc || 'current';
    document.getElementById('p_stat_bg_color').value = p.stat_bg_color || '#ffffff';
    document.getElementById('p_stat_bg_color_hex').value = p.stat_bg_color || '';
    document.getElementById('p_stat_font_color').value = p.stat_font_color || '#334155';
    document.getElementById('p_stat_font_color_hex').value = p.stat_font_color || '';
    document.getElementById('p_show_module').checked = p.show_module !== false;
    document.getElementById('p_use_raw').checked = p.use_raw || false;
    document.getElementById('p_auto_convert_traffic').checked = p.auto_convert_traffic !== false;
    document.getElementById('p_force_100').checked = p.force_100 || false;
    document.getElementById('p_show_time').checked = p.show_time !== false;
    if (document.getElementById('p_show_yaxis')) document.getElementById('p_show_yaxis').checked = p.show_yaxis !== false;
    if (document.getElementById('p_hide_search_bar')) {
        document.getElementById('p_hide_search_bar').checked = (p.hide_search_bar === true || p.hide_search_bar === 1 || p.hide_search_bar === '1' || p.hide_search_bar === 'true');
    }
    document.getElementById('p_chart_engine').value = p.chart_engine || 'custom';
    document.getElementById('p_lbl_1').value = p.lbl_1 || '';
    document.getElementById('p_lbl_0').value = p.lbl_0 || '';
    document.getElementById('p_hidden').checked = p.hidden || false;
    document.getElementById('p_multi_overlay').checked = p.multi_overlay || false;
    if (document.getElementById('p_color_palette')) document.getElementById('p_color_palette').value = p.color_palette || 'vibrant';
    if (document.getElementById('p_chart_color')) document.getElementById('p_chart_color').value = p.chart_color || '#0284c7';
    if (document.getElementById('p_chart_color_hex')) document.getElementById('p_chart_color_hex').value = p.chart_color || '';
    window.__editingPanelSeriesColors = JSON.parse(JSON.stringify(p.series_colors || {}));
    
    const activeCols = p.visible_columns || ['agent', 'group', 'ip', 'module', 'status', 'history', 'threshold'];
    document.querySelectorAll('.col-visibility-chk').forEach(el => {
        el.checked = activeCols.includes(el.value);
    });
    
    const mType = p.match_type || 'contains';
    document.querySelector(`input[name="p_match_type"][value="${mType}"]`).checked = true;

    if (mType === 'exact') {
        document.getElementById('p_keyword_exact').value = p.keyword || '';
        document.getElementById('exact_search_input').value = '';
        renderSelectedModules();
    } else {
        document.getElementById('p_keyword_contains').value = p.keyword || '';
    }

    togglePanelLayoutOptions();
    toggleHeatmapCustomSizeDyn();
    toggleHeatmapCustomTextDyn();
    toggleTypeFields();
    toggleChartEngine();
    previewPaletteSelection();
    renderSeriesColorsEditor(p);
    updateVisualTypePreview();
    document.getElementById('panelModal').style.display = 'flex';
}

function closePanelModal() { document.getElementById('panelModal').style.display = 'none'; }

function applyPanel() {
    const dash = masterDashboards.find(d => d.id === currentDashId);
    if (!dash) return;
    const matchType = document.querySelector('input[name="p_match_type"]:checked').value;
    const p = {
        id: editingPanelId || 'p' + Date.now(),
        title: document.getElementById('p_title').value || 'Panel',
        match_type: matchType,
        keyword: matchType === 'exact' ? document.getElementById('p_keyword_exact').value : document.getElementById('p_keyword_contains').value,
        type: document.getElementById('p_type').value,
        width: document.getElementById('p_width').value,
        height: document.getElementById('p_height').value || 200,
        box_size: document.getElementById('p_box_size').value || 'medium',
        heatmap_text_type: document.getElementById('p_heatmap_text_type') ? document.getElementById('p_heatmap_text_type').value : 'value',
        heatmap_custom_width: document.getElementById('p_heatmap_custom_width') ? document.getElementById('p_heatmap_custom_width').value : '',
        heatmap_custom_height: document.getElementById('p_heatmap_custom_height') ? document.getElementById('p_heatmap_custom_height').value : '',
        heatmap_custom_text: document.getElementById('p_heatmap_custom_text') ? document.getElementById('p_heatmap_custom_text').value : '',
        row_limit: parseInt(document.getElementById('p_row_limit').value) || 200,
        chart_font_size: parseInt(document.getElementById('p_chart_font_size').value) || 10,
        font_size: parseInt(document.getElementById('p_font_size').value) || 32,
        font_weight: document.getElementById('p_font_weight').value || 700,
        value_calc: document.getElementById('p_value_calc') ? document.getElementById('p_value_calc').value : 'current',
        stat_bg_color: document.getElementById('p_stat_bg_color_hex').value.trim(),
        stat_font_color: document.getElementById('p_stat_font_color_hex').value.trim(),
        show_module: document.getElementById('p_show_module').checked,
        use_raw: document.getElementById('p_use_raw').checked,
        auto_convert_traffic: document.getElementById('p_auto_convert_traffic').checked,
        force_100: document.getElementById('p_force_100').checked,
        show_time: document.getElementById('p_show_time').checked,
        show_yaxis: document.getElementById('p_show_yaxis') ? document.getElementById('p_show_yaxis').checked : true,
        chart_engine: document.getElementById('p_chart_engine').value || 'custom',
        lbl_1: document.getElementById('p_lbl_1').value,
        lbl_0: document.getElementById('p_lbl_0').value,
        hidden: document.getElementById('p_hidden').checked,
        hide_search_bar: document.getElementById('p_hide_search_bar') ? document.getElementById('p_hide_search_bar').checked : false,
        multi_overlay: document.getElementById('p_multi_overlay').checked,
        color_palette: document.getElementById('p_color_palette') ? document.getElementById('p_color_palette').value : 'vibrant',
        chart_color: document.getElementById('p_chart_color_hex') ? document.getElementById('p_chart_color_hex').value.trim() : '',
        series_colors: window.__editingPanelSeriesColors ? { ...window.__editingPanelSeriesColors } : (editingPanelId ? (dash.panels.find(x => x.id === editingPanelId)?.series_colors || {}) : {}),
        visible_columns: Array.from(document.querySelectorAll('.col-visibility-chk:checked')).map(el => el.value),
        excluded: editingPanelId ? (dash.panels.find(x => x.id === editingPanelId).excluded || []) : []
    };
    if (editingPanelId) dash.panels = dash.panels.map(x => x.id === editingPanelId ? p : x);
    else dash.panels.push(p);
    closePanelModal(); markUnsaved(); renderPanelsGrid(); forceRefresh(); 
}

function quickTogglePanelHidden(id, moduleId = null) {
    const dash = masterDashboards.find(d => d.id === currentDashId);
    if (!dash) return;
    const p = dash.panels.find(x => x.id === id);
    if (p) {
        if (p.hidden) {
            p.hidden = false;
        } else if (moduleId !== null && moduleId !== undefined) {
            if (!p.excluded) p.excluded = [];
            const sModId = String(moduleId);
            const idx = p.excluded.map(String).indexOf(sModId);
            if (idx > -1) p.excluded.splice(idx, 1);
            else p.excluded.push(sModId);
        } else {
            p.hidden = true;
        }
        markUnsaved();
        renderPanelsGrid();
        forceRefresh();
    }
}

function duplicatePanel(id) {
    const dash = masterDashboards.find(d => d.id === currentDashId);
    const p = dash.panels.find(x => x.id === id);
    if (!p) return;
    
    const newP = JSON.parse(JSON.stringify(p));
    newP.id = 'p' + Date.now();
    newP.title = newP.title + ' (Copy)';
    
    dash.panels.push(newP);
    markUnsaved();
    renderPanelsGrid();
    forceRefresh();
}

function deletePanel(id) {
    if(confirm('Are you sure you want to delete this panel?')) {
        const dash = masterDashboards.find(d => d.id === currentDashId);
        dash.panels = dash.panels.filter(x => x.id !== id);
        
        saveConfigToServer(() => {
            markSaved(); 
            renderPanelsGrid(); 
            forceRefresh();
        }, true); 
    }
}

window.tableCurrentPages = window.tableCurrentPages || {};
function changeTablePage(panelId, newPage) {
    window.tableCurrentPages[panelId] = newPage;
    const wrapper = document.getElementById(`wrapper_p_${panelId}`);
    if (wrapper && lastFetchedData && lastFetchedData[panelId]) {
        const p = masterDashboards.find(d => d.id === currentDashId).panels.find(pl => pl.id === panelId);
        if (p) {
            let activeModules = lastFetchedData[panelId].modules || [];
            if (!showHiddenPanels && p.excluded) {
                activeModules = activeModules.filter(m => !p.excluded.map(String).includes(String(m.id)));
            }
            activeModules.sort((a, b) => (b.last_contact || 0) - (a.last_contact || 0));
            wrapper.innerHTML = generateSummaryPanelHtml(p, activeModules);
            setTimeout(resizeAllGridItems, 50);
        }
    }
}

function toggleDynamicHistorySort(panelId, col) {
    window.dynamicHistorySort = window.dynamicHistorySort || {};
    const current = window.dynamicHistorySort[panelId] || { col: 'time', order: 'desc' };
    if (current.col === col) {
        current.order = (current.order === 'desc') ? 'asc' : 'desc';
    } else {
        current.col = col;
        current.order = (col === 'val' || col === 'time') ? 'desc' : 'asc';
    }
    window.dynamicHistorySort[panelId] = current;
    window.tableCurrentPages = window.tableCurrentPages || {};
    window.tableCurrentPages[panelId] = 1;
    
    const wrapper = document.getElementById(`wrapper_p_${panelId}`);
    if (wrapper && lastFetchedData && lastFetchedData[panelId]) {
        const p = masterDashboards.find(d => d.id === currentDashId).panels.find(pl => pl.id === panelId);
        if (p) {
            let activeModules = lastFetchedData[panelId].modules || [];
            if (!showHiddenPanels && p.excluded) {
                activeModules = activeModules.filter(m => !p.excluded.map(String).includes(String(m.id)));
            }
            wrapper.innerHTML = generateSummaryPanelHtml(p, activeModules);
            setTimeout(resizeAllGridItems, 50);
        }
    }
}

function showStatusDetails(panelId, statusFilter, statusLabel) {
    if (IS_STANDALONE && !IS_MODAL_ONLY) {
        try {
            const u = new URL(window.location.href);
            u.searchParams.set('s', '1');
            u.searchParams.set('modal_only', '1');
            u.searchParams.delete('hide_header');
            u.searchParams.delete('no_header');
            u.searchParams.delete('header');
            if (currentDashId) u.searchParams.set('d', currentDashId);
            u.searchParams.set('open_modal', panelId);
            u.searchParams.set('status_filter', statusFilter);
            u.searchParams.set('status_label', statusLabel);
            window.open(u.toString(), '_blank');
        } catch (e) {
            window.open(`?s=1&modal_only=1&open_modal=${panelId}&status_filter=${statusFilter}&status_label=${encodeURIComponent(statusLabel)}`, '_blank');
        }
        return;
    }

    const data = lastFetchedData[panelId];
    if (!data || !data.modules) return;

    let filtered = data.modules;
    if (statusFilter !== -1) {
        filtered = data.modules.filter(m => m.status === statusFilter);
    }

    const title = document.getElementById('statusDetailTitle');
    title.innerText = `${statusLabel} MODULES (${filtered.length} BARIS)`;

    const body = document.getElementById('statusDetailBody');
    body.innerHTML = filtered.map(m => {
        const bgClass = {0:'bg-green', 1:'bg-red', 2:'bg-yellow', 4:'bg-blue'}[m.status] || 'bg-gray';
        const statusLbl = {0:'UP', 1:'CRITICAL', 2:'WARNING', 4:'NOT INIT'}[m.status] || 'UNKNOWN';
        
        let displayVal = m.current || '';
        let valHtml = '';
        if (displayVal.length > 25) {
            const escapedVal = displayVal.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\n/g, "\\n").replace(/\r/g, "\\r").replace(/"/g, '&quot;');
            const escapedMod = m.module_name.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            const escapedAgent = m.agent_name.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            valHtml = `
                <div style="display:inline-flex; align-items:center; gap:6px;">
                    <span style="font-size:11px;" title="${escapedVal}">${displayVal.substring(0, 20)}...</span>
                    <button style="padding: 2px 6px; font-size: 10px; font-weight: 500; height: 18px; border-radius: 4px; background: #004d40; color: #fff; border: none; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; line-height: 1;" onclick="showLongValuePopup('${escapedMod}', '${escapedAgent}', '${escapedVal}')">View</button>
                </div>
            `;
        } else {
            valHtml = displayVal;
        }

        const rawAgentId = String(m.agent_id || '').includes(':') ? String(m.agent_id).split(':')[1] : m.agent_id;
        const rawModId = String(m.id || '').includes(':') ? String(m.id).split(':')[1] : m.id;

        let agentLinkHtml = '';
        if (rawAgentId && rawAgentId != '0' && rawAgentId !== 'undefined') {
            agentLinkHtml = `<a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${rawAgentId}" target="_blank" class="agent-link" style="color:#1976d2 !important; font-weight:600 !important; text-decoration:none; cursor:pointer; white-space:nowrap;">${m.agent_name}</a>`;
        } else {
            agentLinkHtml = `<span style="color:#334155; font-weight:600; white-space:nowrap;">${m.agent_name}</span>`;
        }

        let moduleLinkHtml = '';
        if (rawModId && rawModId != '0' && rawModId !== 'undefined') {
            moduleLinkHtml = `<a href="${PANDORA_URL}/index.php?sec=view&sec2=operation%2Fagentes%2Fstatus_monitor&id_module=${rawModId}" target="_blank" class="module-link" style="color:#0b1a26 !important; font-weight:500 !important; text-decoration:none; cursor:pointer;">${m.module_name}</a>`;
        } else {
            moduleLinkHtml = `<span style="font-weight:500;">${m.module_name}</span>`;
        }

        return `
            <tr>
                <td>${agentLinkHtml}</td>
                <td style="white-space:nowrap;">${m.group_name}</td>
                <td style="color:#e74c3c; white-space:nowrap;">${m.ip_address}</td>
                <td>${moduleLinkHtml}</td>
                <td style="font-weight:600; white-space:nowrap;">${valHtml}</td>
                <td style="white-space:nowrap;"><span class="status-pill-dyn ${bgClass}">${statusLbl}</span></td>
                <td style="text-align:center; white-space:nowrap;">
                    <div style="display:inline-flex; gap:8px; align-items:center; justify-content:center; width:100%;">
                        <button class="icon-btn" style="padding:0; margin:0; background:none; border:none; cursor:pointer;" onclick="openNativeChartModal('${m.id}', '${m.agent_name.replace(/'/g, "\\'")} - ${m.module_name.replace(/'/g, "\\'")}', '${m.agent_id}')" title="View Chart">
                            <span class="material-symbols-outlined" style="font-size:16px!important; color:#1976d2;">monitoring</span>
                        </button>
                        <button class="icon-btn" style="padding:0; margin:0; background:none; border:none; cursor:pointer;" onclick="show_module_detail_dialog('${m.id}', '${m.agent_id}', 'data', 0, 86400, '${m.module_name.replace(/'/g, "\\'")}')" title="View Data Table">
                            <span class="material-symbols-outlined" style="font-size:16px!important; color:#2e7d32;">table_chart</span>
                        </button>
                    </div>
                </td>
            </tr>`;
    }).join('');

    document.getElementById('statusDetailSearch').value = '';
    document.getElementById('statusDetailModal').style.display = 'flex';
}

function filterStatusDetailTable() {
    const kw = document.getElementById('statusDetailSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#statusDetailBody tr');
    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(kw) ? '' : 'none';
    });
}

function closeStatusDetailModal() {
    if (IS_MODAL_ONLY) {
        window.close();
        return;
    }
    document.getElementById('statusDetailModal').style.display = 'none';
}

function openNativeChartModal(modId, title, idAgent = 0) {
    if(!modId || modId === 0) return;
    const isPrimary = String(modId).startsWith(PRIMARY_UUID + ':');
    if (!isPrimary) {
        show_module_detail_dialog(modId, idAgent, 'graph', 0, 86400, title);
        return;
    }
    const rawId = String(modId).split(':')[1] || modId;
    document.getElementById('nativeChartTitle').innerHTML = `<span class="material-symbols-outlined" style="font-size:18px!important; color:#004d40; vertical-align:middle; margin-right:5px;">monitoring</span> ${title}`;
    const url = `${PANDORA_URL}/operation/agentes/stat_win.php?type=sparse&period=86400&id=${rawId}&refresh=600&period_graph=0&draw_events=0`;
    document.getElementById('nativeChartFrame').src = url;
    document.getElementById('nativeChartModal').style.display = 'flex';
}

function openNativeChart(modId, title, idAgent = 0) {
    openNativeChartModal(modId, title, idAgent);
}

function closeNativeChartModal() {
    document.getElementById('nativeChartModal').style.display = 'none';
    document.getElementById('nativeChartFrame').src = '';
}

function openPanelDetailModal(panelId, currentModuleId) {
    if (!lastFetchedData || !lastFetchedData[panelId]) return;
    const panelData = lastFetchedData[panelId];
    let activeModules = panelData.modules || [];
    
    const currentDash = masterDashboards.find(d => d.id === currentDashId);
    if (currentDash) {
        const p = currentDash.panels.find(x => x.id === panelId);
        if (p && !showHiddenPanels && p.excluded) {
            activeModules = activeModules.filter(m => !p.excluded.map(String).includes(String(m.id)));
        }
    }
    
    activeModules.sort((a, b) => (b.last_contact || 0) - (a.last_contact || 0));

    const moduleList = activeModules.map(m => ({ id: m.id, name: m.agent_name + ' - ' + m.module_name }));
    
    let selectedMod = activeModules.find(m => m.id == currentModuleId);
    if (!selectedMod && activeModules.length > 0) {
        selectedMod = activeModules[0];
    }
    if (!selectedMod) return;

    openNativeModuleDetailModal(selectedMod.id, selectedMod.agent_name + ' - ' + selectedMod.module_name, 86400, null, null, moduleList);
}

function changeDetailModule(newId) {
    if (!currentDetailModuleList) return;
    const selected = currentDetailModuleList.find(x => x.id == newId);
    if (!selected) return;
    
    const currentRange = document.getElementById('nativeModuleTimeRange').value;
    const customStart = document.getElementById('nativeModuleCustomStart').value;
    const customEnd = document.getElementById('nativeModuleCustomEnd').value;
    const customStartTs = customStart ? Math.floor(new Date(customStart).getTime() / 1000) : null;
    const customEndTs = customEnd ? Math.floor(new Date(customEnd).getTime() / 1000) : null;

    openNativeModuleDetailModal(newId, selected.name, currentRange, customStartTs, customEndTs, currentDetailModuleList, currentDetailViewType);
}

function show_module_detail_dialog(module_id, id_agent, filter, interval, offset, title) {
    if (window.parent && window.parent !== window && typeof window.parent.show_module_detail_dialog === 'function') {
        window.parent.show_module_detail_dialog(module_id, id_agent, filter, interval, offset, title);
        return;
    }
    openNativeModuleDetailModal(module_id, title || 'Module Detail', offset || 86400, null, null, null, filter);
}

function handleNativeModuleRangeChange() {
    const val = document.getElementById('nativeModuleTimeRange').value;
    const customBox = document.getElementById('nativeModuleCustomRangeBox');
    if (val === 'custom') {
        customBox.style.display = 'flex';
        const now = new Date();
        const yesterday = new Date(now.getTime() - 24 * 60 * 60 * 1000);
        const pad = (n) => String(n).padStart(2, '0');
        const formatDT = (d) => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
        document.getElementById('nativeModuleCustomStart').value = formatDT(yesterday);
        document.getElementById('nativeModuleCustomEnd').value = formatDT(now);
    } else {
        customBox.style.display = 'none';
        openNativeModuleDetailModal(currentDetailModuleId, currentDetailModuleTitle, val, null, null, currentDetailModuleList, currentDetailViewType);
    }
}

function applyNativeModuleCustomRange() {
    const startVal = document.getElementById('nativeModuleCustomStart').value;
    const endVal = document.getElementById('nativeModuleCustomEnd').value;
    if (!startVal || !endVal) return alert('Please select start and end dates.');
    
    const startTs = Math.floor(new Date(startVal).getTime() / 1000);
    const endTs = Math.floor(new Date(endVal).getTime() / 1000);
    
    if (startTs >= endTs) return alert('Start date must be before end date.');
    
    openNativeModuleDetailModal(currentDetailModuleId, currentDetailModuleTitle, 'custom', startTs, endTs, currentDetailModuleList, currentDetailViewType);
}

async function openNativeModuleDetailModal(moduleId, title, rangeSeconds = 86400, customStart = null, customEnd = null, moduleList = null, viewType = '') {
    currentDetailModuleId = moduleId;
    currentDetailModuleTitle = title;
    currentDetailModuleList = moduleList;
    currentDetailViewType = viewType;
    
    const chartContainer = document.getElementById('nativeModuleChartContainer');
    if (chartContainer) {
        chartContainer.style.display = (viewType === 'data') ? 'none' : 'block';
    }
    
    const headerTitleEl = document.getElementById('nativeModuleDetailTitle');
    if (headerTitleEl) {
        if (moduleList && moduleList.length > 1) {
            let selectHtml = `<select id="detailModuleSelector" class="form-control-fix" style="width: 320px; margin-left: 15px; margin-bottom:0; height:32px; padding:4px 8px; font-size:13px; font-weight:normal;" onchange="changeDetailModule(this.value)">`;
            moduleList.forEach(m => {
                selectHtml += `<option value="${m.id}" ${m.id == moduleId ? 'selected' : ''}>${m.name}</option>`;
            });
            selectHtml += `</select>`;
            headerTitleEl.innerHTML = `<span style="font-weight:600;">Module:</span> ${selectHtml}`;
        } else {
            headerTitleEl.innerText = 'Module Detail: ' + title;
        }
    }
    
    document.getElementById('nativeModuleDetailModal').style.display = 'flex';
    
    const selectEl = document.getElementById('nativeModuleTimeRange');
    if (selectEl) selectEl.value = rangeSeconds;
    
    const customBox = document.getElementById('nativeModuleCustomRangeBox');
    if (customBox) {
        if (rangeSeconds === 'custom') {
            customBox.style.display = 'flex';
        } else {
            customBox.style.display = 'none';
        }
    }
    
    const tableBody = document.querySelector('#nativeModuleDetailTable tbody');
    tableBody.innerHTML = '<tr><td colspan="2" style="text-align:center; padding:30px; color:#64748b;">Loading history...</td></tr>';
    document.getElementById('nativeModuleDetailCount').innerText = 'Loading...';
    
    if (nativeModuleChartInstance) {
        if (typeof nativeModuleChartInstance.dispose === 'function') nativeModuleChartInstance.dispose();
        nativeModuleChartInstance = null;
    }
    
    try {
        const params = { id_mod: moduleId, range: rangeSeconds };
        if (rangeSeconds === 'custom') {
            params.start = customStart;
            params.end = customEnd;
        }
        const url = getApiUrl('detail_graph', params);
        
        const resText = await fetch(url).then(r => r.text());
        let res;
        try {
            res = JSON.parse(resText);
        } catch(e) {
            console.error("Malformed JSON response from detail_graph:", resText);
            throw new Error("Server returned invalid JSON. Check browser console (F12 -> Console) to see the raw error.");
        }
        if (!res.ok) {
            tableBody.innerHTML = `<tr><td colspan="2" style="text-align:center; padding:30px; color:#e74c3c;">Error: ${res.error || 'Failed to load data'}</td></tr>`;
            return;
        }
        
        const data = res.data || [];
        if (res.debug) console.log('detail_graph debug:', res.debug);
        document.getElementById('nativeModuleDetailCount').innerText = `${data.length} rows`;
        
        if (data.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="2" style="text-align:center; padding:30px; color:#64748b;">No history data found in the selected range.</td></tr>';
            return;
        }
        
        const unit = res.unit ? ' ' + res.unit : '';
        
        let html = '';
        const isDetailPct = (unit.trim() === '%' || unit.includes('%') || title.toLowerCase().includes('load') || title.toLowerCase().includes('used'));
        data.forEach(row => {
            let formattedDate = row.waktu;
            if (row.waktu && row.waktu.includes('-')) {
                const parts = row.waktu.split(' ');
                const ymd = parts[0].split('-');
                formattedDate = `${ymd[2]}/${ymd[1]}/${ymd[0]} ${parts[1]}`;
            }
            let valNum = parseFloat(row.datos);
            if (isDetailPct && valNum > 100) valNum = 100;
            const displayVal = (valNum % 1 === 0) ? valNum : valNum.toFixed(2);
            html += `<tr>
                <td style="font-weight: normal; color: #475569;">${formattedDate}</td>
                <td style="font-weight: 600; color: #0f172a;">${displayVal}${unit}</td>
            </tr>`;
        });
        tableBody.innerHTML = html;
        
        if (viewType !== 'data') {
            const labels = data.map(row => {
                if (row.waktu && row.waktu.includes('-')) {
                    const parts = row.waktu.split(' ');
                    const ymd = parts[0].split('-');
                    return `${ymd[2]}/${ymd[1]} ${parts[1]}`;
                }
                return row.waktu;
            });
            const dataset = data.map(row => {
                let v = parseFloat(row.datos);
                if (isDetailPct && v > 100) v = 100;
                return v;
            });
            
            let dom = document.getElementById('nativeModuleDetailChart');
            if (dom) {
                nativeModuleChartInstance = echarts.init(dom);
                nativeModuleChartInstance.setOption({
                    tooltip: { 
                        trigger: 'axis', 
                        confine: true,
                        appendToBody: true,
                        backgroundColor: 'rgba(15, 23, 42, 0.95)', 
                        textStyle: { color: '#cbd5e1', fontSize: 12 }, 
                        padding: 10, 
                        borderRadius: 6,
                        formatter: function (params) {
                            let html = params[0].name ? params[0].name + '<br/>' : '';
                            params.forEach(p => {
                                let val = p.value;
                                if (val !== null && val !== undefined && !isNaN(val)) {
                                    val = parseFloat(val);
                                    val = (val % 1 === 0) ? val : val.toFixed(2);
                                }
                                html += `${p.marker}${p.seriesName}: <b>${val}${unit}</b><br/>`;
                            });
                            return html;
                        }
                    },
                    grid: { left: 5, right: 15, top: 15, bottom: 25, containLabel: true },
                    xAxis: { type: 'category', boundaryGap: false, data: labels, axisLabel: { fontSize: 9, color: '#64748b' }, axisLine: { show: false }, axisTick: { show: false } },
                    yAxis: { 
                        type: 'value', 
                        max: isDetailPct ? 100 : null,
                        splitLine: { lineStyle: { color: '#f1f5f9' } }, 
                        axisLabel: { fontSize: 10, color: '#64748b' } 
                    },
                    dataZoom: [{
                        type: 'inside',
                        start: 0,
                        end: 100
                    }],
                    series: [{
                        name: title,
                        type: 'line',
                        data: dataset,
                        itemStyle: { color: '#004d40' },
                        areaStyle: { opacity: 0.2, color: '#004d40' },
                        smooth: true,
                        showSymbol: false,
                        connectNulls: true,
                        lineStyle: { width: 2 }
                    }]
                });
                if (nativeModuleChartInstance) {
                    nativeModuleChartInstance.resize();
                    setTimeout(() => {
                        if (nativeModuleChartInstance) nativeModuleChartInstance.resize();
                    }, 50);
                }
                window.addEventListener('resize', () => {
                    if (nativeModuleChartInstance) nativeModuleChartInstance.resize();
                });
            }
        }
        
    } catch (e) {
        tableBody.innerHTML = `<tr><td colspan="2" style="text-align:center; padding:30px; color:#e74c3c;">Exception: ${e.message}</td></tr>`;
    }
}

function closeNativeModuleDetailModal() {
    document.getElementById('nativeModuleDetailModal').style.display = 'none';
    if (nativeModuleChartInstance) {
        if (typeof nativeModuleChartInstance.dispose === 'function') nativeModuleChartInstance.dispose();
        nativeModuleChartInstance = null;
    }
}

document.addEventListener('click', function(e) {
    const exactDropdown = document.getElementById('exact_dropdown');
    const exactInput = document.getElementById('exact_search_input');
    if (exactDropdown && !exactDropdown.contains(e.target) && e.target !== exactInput) {
        exactDropdown.style.display = 'none';
    }
    
    const agentDropdown = document.getElementById('agent_dropdown');
    const agentInput = document.getElementById('agent_search_input');
    if (agentDropdown && !agentDropdown.contains(e.target) && e.target !== agentInput) {
        agentDropdown.style.display = 'none';
    }
});

init();

function resizeGridItem(item) {
    const grid = document.getElementById("panelsGrid");
    if (!grid) return;
    const rowHeight = parseInt(window.getComputedStyle(grid).getPropertyValue('grid-auto-rows')) || 5;
    const rowGap = parseInt(window.getComputedStyle(grid).getPropertyValue('row-gap')) || 15;
    
    const cards = item.querySelectorAll('.panel-card');
    if (!cards || cards.length === 0) return;

    // Reset styles temporarily to measure true unconstrained content height
    item.style.gridRowEnd = 'auto';
    cards.forEach(c => { c.style.height = 'auto'; c.style.flex = 'none'; });

    const contentHeight = item.scrollHeight;
    const rowSpan = Math.ceil((contentHeight + rowGap) / (rowHeight + rowGap));
    item.style.gridRowEnd = "span " + Math.max(1, rowSpan);
    cards.forEach(c => { c.style.height = '100%'; c.style.flex = '1'; });
}

function resizeAllGridItems() {
    const allItems = document.getElementsByClassName("panel-rule-wrapper");
    for (let x = 0; x < allItems.length; x++) {
        resizeGridItem(allItems[x]);
    }
}

let resizeDebounce = null;
window.addEventListener("resize", () => {
    if (resizeDebounce) clearTimeout(resizeDebounce);
    resizeDebounce = setTimeout(resizeAllGridItems, 100);
});

const gridObserver = new ResizeObserver(entries => {
    if (resizeDebounce) clearTimeout(resizeDebounce);
    resizeDebounce = setTimeout(() => {
        for (let entry of entries) {
            resizeGridItem(entry.target);
        }
    }, 60);
});

function attachResizeObserver(pId) {
    setTimeout(() => {
        const el = document.getElementById(`wrapper_p_${pId}`);
        if (el) {
            gridObserver.observe(el);
            resizeGridItem(el);
        }
    }, 100);
}

function showLongValuePopup(moduleName, agentName, fullValue) {
    const existing = document.getElementById('longValuePopupModal');
    if (existing) existing.remove();

    const modal = document.createElement('div');
    modal.id = 'longValuePopupModal';
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(15, 23, 42, 0.5);
        -webkit-backdrop-filter: blur(4px);
        backdrop-filter: blur(4px);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 99999;
    `;

    const box = document.createElement('div');
    box.style.cssText = `
        background: #fff;
        width: 600px;
        max-width: 90%;
        border-radius: 12px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        border: 1px solid #e2e8f0;
        display: flex;
        flex-direction: column;
        max-height: 80vh;
        animation: modalFadeIn 0.2s ease-out;
    `;

    if (!document.getElementById('modal-animation-style')) {
        const style = document.createElement('style');
        style.id = 'modal-animation-style';
        style.innerText = `
            @keyframes modalFadeIn {
                from { opacity: 0; transform: scale(0.95); }
                to { opacity: 1; transform: scale(1); }
            }
        `;
        document.head.appendChild(style);
    }

    const header = document.createElement('div');
    header.style.cssText = `
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid #e2e8f0;
    `;
    header.innerHTML = `
        <div>
            <h5 style="margin: 0; font-size: 14px; font-weight: 600; color: #0f172a;">${moduleName || 'Module Value'}</h5>
            <span style="font-size: 11px; color: #64748b; font-weight: normal;">Agent: ${agentName || '-'}</span>
        </div>
        <span class="material-symbols-outlined" style="cursor: pointer; color: #64748b; font-size: 20px;" onclick="document.getElementById('longValuePopupModal').remove()">close</span>
    `;

    const body = document.createElement('div');
    body.style.cssText = `
        padding: 20px;
        overflow-y: auto;
        flex-grow: 1;
        background: #f8fafc;
        max-height: 50vh;
    `;
    
    const pre = document.createElement('pre');
    pre.style.cssText = `
        margin: 0;
        padding: 12px;
        background: #0f172a;
        color: #e2e8f0;
        border-radius: 8px;
        font-family: monospace;
        font-size: 11px;
        white-space: pre-wrap;
        word-break: break-all;
    `;
    pre.innerText = fullValue;
    body.appendChild(pre);

    const footer = document.createElement('div');
    footer.style.cssText = `
        padding: 12px 20px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: flex-end;
        background: #fff;
        border-bottom-left-radius: 12px;
        border-bottom-right-radius: 12px;
    `;
    
    const btn = document.createElement('button');
    btn.style.cssText = `
        padding: 8px 16px;
        border-radius: 6px;
        background: #004d40;
        color: #fff;
        border: none;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: background 0.15s;
    `;
    btn.innerText = 'Close';
    btn.onmouseenter = () => btn.style.background = '#00332a';
    btn.onmouseleave = () => btn.style.background = '#004d40';
    btn.onclick = () => modal.remove();

    const copyBtn = document.createElement('button');
    copyBtn.style.cssText = `
        padding: 8px 16px;
        border-radius: 6px;
        background: #fff;
        color: #004d40;
        border: 1px solid #004d40;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
        transition: background 0.15s, color 0.15s;
        margin-right: 10px;
    `;
    copyBtn.innerText = 'Copy';
    copyBtn.onmouseenter = () => {
        copyBtn.style.background = '#e0f2f1';
    };
    copyBtn.onmouseleave = () => {
        copyBtn.style.background = '#fff';
    };
    copyBtn.onclick = () => {
        const doSuccess = () => {
            copyBtn.innerText = 'Copied!';
            copyBtn.style.background = '#e8f5e9';
            copyBtn.style.color = '#2e7d32';
            copyBtn.style.borderColor = '#2e7d32';
            setTimeout(() => {
                copyBtn.innerText = 'Copy';
                copyBtn.style.background = '#fff';
                copyBtn.style.color = '#004d40';
                copyBtn.style.borderColor = '#004d40';
            }, 2000);
        };
        
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(fullValue).then(doSuccess).catch(fallbackCopy);
        } else {
            fallbackCopy();
        }
        
        function fallbackCopy() {
            try {
                const textArea = document.createElement("textarea");
                textArea.value = fullValue;
                textArea.style.top = "0";
                textArea.style.left = "0";
                textArea.style.position = "fixed";
                textArea.style.opacity = "0";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                const successful = document.execCommand('copy');
                document.body.removeChild(textArea);
                if (successful) {
                    doSuccess();
                } else {
                    alert('Browser does not support clipboard copy');
                }
            } catch (err) {
                alert('Failed to copy text: ' + err);
            }
        }
    };

    footer.appendChild(copyBtn);
    footer.appendChild(btn);

    box.appendChild(header);
    box.appendChild(body);
    box.appendChild(footer);
    modal.appendChild(box);

    modal.onclick = (e) => {
        if (e.target === modal) modal.remove();
    };

    document.body.appendChild(modal);
}

window.tableViewerData = window.tableViewerData || {};

function renderSingleModuleTableViewer(uniqueId, rawText, agentLabel, rowLimit = 200) {
    const lines = rawText.split(/\r?\n/).filter(l => l.trim() !== '');
    const thead = document.getElementById(`thead_${uniqueId}`);
    const tbody = document.getElementById(`tbody_${uniqueId}`);
    const rawEl = document.getElementById(`raw_${uniqueId}`);
    if (!thead || !tbody) return;

    window.tableViewerData[uniqueId + '_agent'] = agentLabel;

    let separatorIdx = -1;
    for (let i = 0; i < lines.length; i++) {
        const trimmed = lines[i].trim();
        if (trimmed.match(/^[|:\-\+\s]{5,}$/) && trimmed.includes('-')) {
            separatorIdx = i;
            break;
        }
    }

    if (separatorIdx === -1 || separatorIdx === 0) {
        if (lines.length > 0 && lines[0].includes('|')) {
            const firstLine = lines[0];
            let firstCells = firstLine.split('|').map(c => c.trim());
            if (firstCells.length > 0 && firstCells[0] === '' && firstLine.startsWith('|')) firstCells.shift();
            if (firstCells.length > 0 && firstCells[firstCells.length - 1] === '' && firstLine.endsWith('|')) firstCells.pop();

            const numCols = firstCells.length || 1;
            let headers = [];
            for (let i = 1; i <= numCols; i++) {
                headers.push(`Col ${i}`);
            }

            let parsedRows = [];
            let currentCells = [];

            lines.forEach(line => {
                const pipeCount = (line.match(/\|/g) || []).length;
                if (pipeCount >= 3) {
                    if (currentCells.length > 0) parsedRows.push(currentCells);
                    let cells = line.split('|').map(c => c.trim());
                    if (cells.length > 0 && cells[0] === '' && line.startsWith('|')) cells.shift();
                    if (cells.length > 0 && cells[cells.length - 1] === '' && line.endsWith('|')) cells.pop();
                    currentCells = cells;
                } else {
                    if (currentCells.length > 0) {
                        const lastIdx = currentCells.length - 1;
                        currentCells[lastIdx] = currentCells[lastIdx] + '\n' + line.trim();
                    } else {
                        currentCells = line.split('|').map(c => c.trim());
                    }
                }
            });
            if (currentCells.length > 0) parsedRows.push(currentCells);

            let dataRows = [];
            parsedRows.forEach(cells => {
                while (cells.length < numCols) cells.push('');
                if (cells.length > numCols) cells = cells.slice(0, numCols);
                dataRows.push(cells);
            });
            const limit = parseInt(rowLimit) || 200;
            if (dataRows.length > limit) dataRows = dataRows.slice(0, limit);

            window.tableViewerData[uniqueId] = dataRows;
            thead.innerHTML = '<tr>' + headers.map(h => `<th>${escapeHtml(h)}</th>`).join('') + '</tr>';
            renderTableViewerRows(uniqueId, dataRows);
            rawEl.classList.add('d-none');
            const wrapper = rawEl.closest('.table-viewer-card-wrap');
            if (wrapper) {
                const sb = wrapper.querySelector('.search-box');
                if (sb) sb.style.display = 'block';
            }
            return;
        }

        thead.innerHTML = '';
        tbody.innerHTML = '';
        rawEl.innerText = rawText;
        rawEl.classList.remove('d-none');
        window.tableViewerData[uniqueId] = [];
        const wrapper = rawEl.closest('.table-viewer-card-wrap');
        if (wrapper) {
            const sb = wrapper.querySelector('.search-box');
            if (sb) sb.style.display = 'none';
        }
        return;
    }

    rawEl.classList.add('d-none');

    const headerLines = lines.slice(0, separatorIdx);
    let headers = [];
    headerLines.forEach(line => {
        let cols = line.split('|').map(h => h.trim()).filter(h => h !== '');
        headers = headers.concat(cols);
    });

    const numCols = headers.length || 1;
    
    const dataLines = lines.slice(separatorIdx + 1);
    let parsedRows = [];
    let currentCells = [];

    dataLines.forEach(line => {
        const pipeCount = (line.match(/\|/g) || []).length;
        if (pipeCount >= 3) {
            if (currentCells.length > 0) parsedRows.push(currentCells);
            let cells = line.split('|').map(c => c.trim());
            if (cells.length > 0 && cells[0] === '' && line.startsWith('|')) cells.shift();
            if (cells.length > 0 && cells[cells.length - 1] === '' && line.endsWith('|')) cells.pop();
            currentCells = cells;
        } else {
            if (currentCells.length > 0) {
                const lastIdx = currentCells.length - 1;
                currentCells[lastIdx] = currentCells[lastIdx] + '\n' + line.trim();
            } else {
                currentCells = line.split('|').map(c => c.trim());
            }
        }
    });
    if (currentCells.length > 0) parsedRows.push(currentCells);

    let dataRows = [];
    parsedRows.forEach(cells => {
        while (cells.length < numCols) cells.push('');
        if (cells.length > numCols) cells = cells.slice(0, numCols);
        dataRows.push(cells);
    });
    const limit = parseInt(rowLimit) || 200;
    if (dataRows.length > limit) dataRows = dataRows.slice(0, limit);

    window.tableViewerData[uniqueId] = dataRows;
    thead.innerHTML = '<tr>' + headers.map(h => `<th>${escapeHtml(h)}</th>`).join('') + '</tr>';
    renderTableViewerRows(uniqueId, dataRows);
    const wrapper = rawEl.closest('.table-viewer-card-wrap');
    if (wrapper) {
        const sb = wrapper.querySelector('.search-box');
        if (sb) sb.style.display = 'block';
    }
}

function renderTableViewerRows(uniqueId, rows) {
    const tbody = document.getElementById(`tbody_${uniqueId}`);
    if (!tbody) return;
    const agentLabel = window.tableViewerData[uniqueId + '_agent'] || 'Table Viewer';
    if (rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="100%" style="text-align: center; color: #94a3b8; padding: 15px;">No rows found.</td></tr>`;
    } else {
        tbody.innerHTML = rows.map(r => '<tr>' + r.map(c => {
            const cellStr = String(c || '');
            if (cellStr.length > 45 || cellStr.includes('\n')) {
                return `<td style="vertical-align: middle;"><button class="btn-pfms btn-outline-pfms" style="padding:2px 6px; font-size:10px; font-weight:600; cursor:pointer;" onclick="showLongValuePopup('Detail Query', '${agentLabel}', \`${cellStr.replace(/`/g, "\\`").replace(/\$/g, "\\$")}\`)">View</button></td>`;
            }
            return `<td>${escapeHtml(c)}</td>`;
        }).join('') + '</tr>').join('');
    }
}

function filterCardTableViewer(uniqueId, keyword) {
    keyword = keyword.toLowerCase();
    const rows = window.tableViewerData[uniqueId] || [];
    if (!keyword) {
        renderTableViewerRows(uniqueId, rows);
        return;
    }
    const filtered = rows.filter(row => row.some(cell => cell.toLowerCase().includes(keyword)));
    renderTableViewerRows(uniqueId, filtered);
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function toggleDynamicEchartsLegend(uniqueId, el) {
    const chart = chartInstances[uniqueId];
    if (!chart) return;
    const seriesName = el.getAttribute('data-series');
    chart.dispatchAction({
        type: 'legendToggleSelect',
        name: seriesName
    });
    el.classList.toggle('legend-inactive');
    if (el.classList.contains('legend-inactive')) {
        el.style.opacity = '0.35';
        el.style.textDecoration = 'line-through';
    } else {
        el.style.opacity = '1';
        el.style.textDecoration = 'none';
    }
}

function highlightDynamicEchartsSeries(uniqueId, seriesName) {
    const chart = chartInstances[uniqueId];
    if (!chart) return;
    chart.dispatchAction({ type: 'highlight', seriesName: seriesName });
}

function downplayDynamicEchartsSeries(uniqueId, seriesName) {
    const chart = chartInstances[uniqueId];
    if (!chart) return;
    chart.dispatchAction({ type: 'downplay', seriesName: seriesName });
}

function toggleSparklineSort(panelId, col) {
    window.sparklineTableSort = window.sparklineTableSort || {};
    const curr = window.sparklineTableSort[panelId] || { col: 'val', order: 'desc' };
    if (curr.col === col) {
        curr.order = (curr.order === 'desc') ? 'asc' : 'desc';
    } else {
        curr.col = col;
        curr.order = (col === 'val' || col === 'trend') ? 'desc' : 'asc';
    }
    window.sparklineTableSort[panelId] = curr;
    if (typeof forceRefresh === 'function') forceRefresh();
}

function generateSparklineSvg(history, options = {}) {
    if (!history || !Array.isArray(history) || history.length < 2) {
        return '<div style="color:#94a3b8; font-size:10px; font-style:italic; padding:4px 0;">No trend data</div>';
    }
    
    const pointsData = [];
    history.forEach(h => {
        if (!h) return;
        const raw = (h.val !== undefined && h.val !== null) ? h.val : (h.datos !== undefined ? h.datos : null);
        if (raw === null || raw === undefined) return;
        const num = parseFloat(String(raw).replace(',', '.'));
        if (!isNaN(num)) {
            pointsData.push({
                val: num,
                lbl: h.lbl || h.time || '',
                ts: h.ts || h.utimestamp || 0
            });
        }
    });

    if (pointsData.length < 2) {
        return '<div style="color:#94a3b8; font-size:10px; font-style:italic; padding:4px 0;">Insufficient data</div>';
    }

    const width = options.width || 180;
    const height = options.height || 26;
    const strokeColor = options.color || '#10b981';
    const fillColor = options.fillColor || 'rgba(16, 185, 129, 0.15)';
    const showArea = options.showArea !== false;
    
    const vals = pointsData.map(p => p.val);
    const min = Math.min(...vals);
    const max = Math.max(...vals);
    const range = (max - min) === 0 ? 1 : (max - min);
    
    const paddingX = 3;
    const paddingY = 3;
    const effH = height - (paddingY * 2);
    const effW = width - (paddingX * 2);
    const step = effW / (pointsData.length - 1);
    
    const coords = pointsData.map((p, i) => {
        const x = paddingX + (i * step);
        const y = paddingY + effH - (((p.val - min) / range) * effH);
        return { x: Number(x.toFixed(1)), y: Number(y.toFixed(1)) };
    });
    
    const pathD = coords.map((c, i) => (i === 0 ? `M ${c.x} ${c.y}` : `L ${c.x} ${c.y}`)).join(' ');
    const lastC = coords[coords.length - 1];
    const areaD = `${pathD} L ${lastC.x} ${height - paddingY} L ${coords[0].x} ${height - paddingY} Z`;
    
    const latestVal = vals[vals.length - 1];
    const title = `Min: ${min.toFixed(2)} | Max: ${max.toFixed(2)} | Current: ${latestVal.toFixed(2)} (${vals.length} points)`;

    return `
    <div style="width:100%; min-width:120px; max-width:240px; height:${height}px; display:flex; align-items:center;" title="${title}">
        <svg width="100%" height="${height}" viewBox="0 0 ${width} ${height}" preserveAspectRatio="none" style="display:block; overflow:visible;">
            ${showArea ? `<path d="${areaD}" fill="${fillColor}" />` : ''}
            <path d="${pathD}" fill="none" stroke="${strokeColor}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
            <circle cx="${lastC.x}" cy="${lastC.y}" r="2" fill="${strokeColor}" />
        </svg>
    </div>`;
}

</script>
<?php require_once __DIR__ . '/../../includes/dashboard-acl-modal.php'; ?>
</body>
</html>