<?php
/* metrics-dashboard.php
 *
 * Universal Metrics Widget Dashboard
 * - Version: 10.6 (STABLE: Lazy-Load Sparklines to Fix Timeout on 4000+ Modules)
 * - Auto-detect Module Unit, Split Columns, Custom Range Chart, Bulk Export
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// 1. DYNAMIC BREADCRUMB
$dynamic_breadcrumb = "PANDORA CONSOLE / CUSTOM / PANEL / DASHBOARD";

// 2. CONFIG LOADING
$script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
if (preg_match('#^(/.*?)/(custom|customize)/panel#', $script_dir, $matches)) {
    $PANDORA_BASE_URL = rtrim($matches[1], '/');
    $vendor_url = $PANDORA_BASE_URL . '/' . $matches[2] . '/panel/vendor';
} else if (preg_match('#^/(custom|customize)/panel#', $script_dir, $matches)) {
    $PANDORA_BASE_URL = '';
    $vendor_url = '/' . $matches[1] . '/panel/vendor';
} else {
    $PANDORA_BASE_URL = "/pandora_console";
    $vendor_url = "/pandora_console/custom/panel/vendor";
}
$panelDirName = 'custom';
if (preg_match('#^(/.*?)/(custom|customize)/panel#', $script_dir, $matches)) {
    $panelDirName = $matches[2];
} else if (preg_match('#^/(custom|customize)/panel#', $script_dir, $matches)) {
    $panelDirName = $matches[1];
}
$directScriptUrl = $PANDORA_BASE_URL . '/' . $panelDirName . '/panel/Dashboard/Metrics-Dashboard/metrics-dashboard.php';
$CONFIG_FILE = __DIR__ . '/metrics_config.json';

require_once __DIR__ . '/../../includes/db-connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';

// 3. HELPERS & DB INIT
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('pretty_text')) {
    function pretty_text($s) {
        if ($s === null) return '';
        $decoded = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
        return str_replace('&#x20;', ' ', $decoded);
    }
}
if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        if (empty($datetime) || $datetime == '0000-00-00 00:00:00') return 'N/A';
        $diff = time() - strtotime($datetime);
        if ($diff < 60) return "Just now";
        if ($diff < 3600) return round($diff / 60) . " min";
        if ($diff < 86400) return round($diff / 3600) . " hours";
        return round($diff / 86400) . " days";
    }
}

function map_pandora_status($estado) {
    switch ((int)$estado) {
        case 0: return ['label' => 'NORMAL', 'color' => 'bg-green', 'val' => 0];
        case 1: return ['label' => 'CRITICAL', 'color' => 'bg-red', 'val' => 1];
        case 2: return ['label' => 'WARNING', 'color' => 'bg-yellow', 'val' => 2];
        case 4: return ['label' => 'NOT INIT', 'color' => 'bg-blue', 'val' => 4];
        default: return ['label' => 'UNKNOWN', 'color' => 'bg-gray', 'val' => 3];
    }
}

// 4. AJAX ENDPOINTS
$api = $_GET['api'] ?? '';

if ($api === 'load_config') {
    ob_clean(); header('Content-Type: application/json');
    echo file_exists($CONFIG_FILE) ? file_get_contents($CONFIG_FILE) : json_encode([]); exit;
}
if ($api === 'save_config') {
    ob_clean(); header('Content-Type: application/json');

    // CSRF Validation
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf_token) || $client_token !== $csrf_token) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh portal.']);
        exit;
    }

    $input = file_get_contents('php://input');
    $bytes = @file_put_contents($CONFIG_FILE, $input);
    echo json_encode([
        'ok' => $bytes !== false, 
        'file' => basename($CONFIG_FILE),
        'error' => $bytes === false ? error_get_last() : null
    ]); 
    exit;
}
if ($api === 'groups' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $dropdown = [['id' => '0', 'name' => '--- Manual Selection / All ---']];
    
    // 1. Primary DB groups
    try {
        $stmt = $pdo->query("SELECT id_grupo AS id, nombre AS name FROM tgrupo ORDER BY name ASC");
        while($g = $stmt->fetch()) { 
            $dropdown[] = ['id' => 'primary:' . $g['id'], 'name' => '[Primary] ' . pretty_text($g['name'])]; 
        }
    } catch (Throwable $e) {}
    
    // 2. Custom DB groups
    global $custom_pdos, $custom_connections;
    if (!empty($custom_pdos)) {
        foreach ($custom_pdos as $cid => $cpdo) {
            $cname = '';
            foreach ($custom_connections as $cc) {
                if ($cc['id'] === $cid) { $cname = $cc['name']; break; }
            }
            if (empty($cname)) $cname = $cid;
            try {
                $stmt = $cpdo->query("SELECT id_grupo AS id, nombre AS name FROM tgrupo ORDER BY name ASC");
                while($g = $stmt->fetch()) { 
                    $dropdown[] = ['id' => $cid . ':' . $g['id'], 'name' => '[' . $cname . '] ' . pretty_text($g['name'])]; 
                }
            } catch (Throwable $e) {}
        }
    }
    
    echo json_encode($dropdown); exit;
}
if ($api === 'search_agents' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    $selected_ids_str = trim($_GET['selected_ids'] ?? '');
    $selected_ids = $selected_ids_str !== '' ? explode(',', $selected_ids_str) : [];
    
    $agents = [];
    $limit = 100;
    
    $primary_sel_ids = [];
    $custom_sel_ids = [];
    foreach ($selected_ids as $sid) {
        $parts = explode(':', $sid, 2);
        if (count($parts) === 2) {
            $cid = $parts[0];
            $rid = $parts[1];
            if ($cid === 'primary') {
                $primary_sel_ids[] = (int)$rid;
            } else {
                $custom_sel_ids[$cid][] = (int)$rid;
            }
        }
    }
    
    // 1. Fetch Primary DB agents
    try {
        $where_clauses = ["disabled = 0"];
        $params = [];
        
        $term_conds = [];
        if ($q !== '') {
            $term_conds[] = "alias LIKE ?";
            $params[] = "%$q%";
        }
        if (!empty($primary_sel_ids)) {
            $placeholders = implode(',', array_fill(0, count($primary_sel_ids), '?'));
            $term_conds[] = "id_agente IN ($placeholders)";
            foreach ($primary_sel_ids as $pid) $params[] = $pid;
        }
        
        if (!empty($term_conds)) {
            $where_clauses[] = "(" . implode(" OR ", $term_conds) . ")";
        }
        
        $where = "WHERE " . implode(" AND ", $where_clauses);
        
        $sql = "SELECT id_agente AS id, alias FROM tagente $where ORDER BY alias ASC LIMIT $limit";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while($a = $stmt->fetch()) {
            $agents[] = ['id' => 'primary:' . $a['id'], 'alias' => '[Primary] ' . pretty_text($a['alias'])];
        }
    } catch (Throwable $e) {}
    
    // 2. Custom DB agents
    global $custom_pdos, $custom_connections;
    if (!empty($custom_pdos)) {
        foreach ($custom_pdos as $cid => $cpdo) {
            $cname = '';
            foreach ($custom_connections as $cc) {
                if ($cc['id'] === $cid) { $cname = $cc['name']; break; }
            }
            if (empty($cname)) $cname = $cid;
            try {
                $where_clauses = ["disabled = 0"];
                $params = [];
                
                $term_conds = [];
                if ($q !== '') {
                    $term_conds[] = "alias LIKE ?";
                    $params[] = "%$q%";
                }
                $c_sel = $custom_sel_ids[$cid] ?? [];
                if (!empty($c_sel)) {
                    $placeholders = implode(',', array_fill(0, count($c_sel), '?'));
                    $term_conds[] = "id_agente IN ($placeholders)";
                    foreach ($c_sel as $csid) $params[] = $csid;
                }
                
                if (!empty($term_conds)) {
                    $where_clauses[] = "(" . implode(" OR ", $term_conds) . ")";
                }
                
                $where = "WHERE " . implode(" AND ", $where_clauses);
                
                $sql = "SELECT id_agente AS id, alias FROM tagente $where ORDER BY alias ASC LIMIT $limit";
                $stmt = $cpdo->prepare($sql);
                $stmt->execute($params);
                while($a = $stmt->fetch()) {
                    $agents[] = ['id' => $cid . ':' . $a['id'], 'alias' => '[' . $cname . '] ' . pretty_text($a['alias'])];
                }
            } catch (Throwable $e) {}
        }
    }
    
    $unique_agents = [];
    $seen = [];
    foreach ($agents as $ag) {
        if (!isset($seen[$ag['id']])) {
            $seen[$ag['id']] = true;
            $unique_agents[] = $ag;
        }
    }
    
    echo json_encode($unique_agents); exit;
}
if ($api === 'module_list' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $groupIdRaw = $_GET['group_id'] ?? '0';
    $manual_ids = $_GET['manual_ids'] ?? '';
    
    $groupParsed = parse_node_id($groupIdRaw);
    $manualIdsParsed = parse_node_ids($manual_ids);

    $get_modules_from_db = function($db_pdo, $gId, $agentIds) {
        $params = [];
        $whereClause = "";
        if (!empty($agentIds)) {
            $placeholders = implode(',', array_fill(0, count($agentIds), '?'));
            $whereClause = "AND id_agente IN ($placeholders)";
            foreach ($agentIds as $id) { $params[] = $id; }
        } elseif ($gId > 0) {
            $stmtAllGroups = $db_pdo->query("SELECT id_grupo, parent FROM tgrupo");
            $allGroups = $stmtAllGroups->fetchAll();
            $getChildGroupsLocal = function($parentId, $allGroups) use (&$getChildGroupsLocal) {
                $children = [$parentId];
                foreach ($allGroups as $g) {
                    if ($g['parent'] == $parentId) {
                        $children = array_merge($children, $getChildGroupsLocal($g['id_grupo'], $allGroups));
                    }
                }
                return array_unique($children);
            };
            $targetGroups = $getChildGroupsLocal($gId, $allGroups);
            $placeholders = implode(',', array_fill(0, count($targetGroups), '?'));
            $whereClause = "AND id_agente IN (SELECT id_agente FROM tagente WHERE id_grupo IN ($placeholders) AND disabled = 0)";
            foreach ($targetGroups as $tg) { $params[] = $tg; }
        }
        
        $sql = "SELECT DISTINCT nombre FROM tagente_modulo WHERE disabled = 0 $whereClause ORDER BY nombre ASC";
        $stmt = $db_pdo->prepare($sql);
        $stmt->execute($params);
        $list = [];
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $list[] = $row['nombre'];
        }
        return $list;
    };

    global $custom_pdos;
    $target_dbs = [];
    if ($groupParsed['id'] > 0) {
        $target_dbs[$groupParsed['node']] = [
            'pdo' => ($groupParsed['node'] === 'primary') ? $pdo : ($custom_pdos[$groupParsed['node']] ?? null),
            'group_id' => $groupParsed['id'],
            'agent_ids' => []
        ];
    } elseif (!empty($manualIdsParsed)) {
        foreach ($manualIdsParsed as $node => $aids) {
            $target_dbs[$node] = [
                'pdo' => ($node === 'primary') ? $pdo : ($custom_pdos[$node] ?? null),
                'group_id' => 0,
                'agent_ids' => $aids
            ];
        }
    } else {
        $target_dbs['primary'] = ['pdo' => $pdo, 'group_id' => 0, 'agent_ids' => []];
        if (!empty($custom_pdos)) {
            foreach ($custom_pdos as $cid => $cpdo) {
                $target_dbs[$cid] = ['pdo' => $cpdo, 'group_id' => 0, 'agent_ids' => []];
            }
        }
    }

    $all_module_names = [];
    foreach ($target_dbs as $node => $info) {
        if ($info['pdo'] === null) continue;
        try {
            $db_modules = $get_modules_from_db($info['pdo'], $info['group_id'], $info['agent_ids']);
            $all_module_names = array_merge($all_module_names, $db_modules);
        } catch (Throwable $e) {}
    }
    
    $all_module_names = array_unique($all_module_names);
    sort($all_module_names);
    
    $list = [];
    foreach ($all_module_names as $raw) {
        $list[] = ['raw' => $raw, 'pretty' => pretty_text($raw)];
    }
    echo json_encode($list); exit;
}

if ($api === 'detail_graph' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $id_mod = $_GET['id_mod'];
    $range = $_GET['range'] ?? '21600';

    if ($range === 'custom') {
        $start = (int)$_GET['start'];
        $end = (int)$_GET['end'];
    } else {
        $end = time();
        $start = $end - (int)$range;
    }

    $parsed = parse_node_id($id_mod);
    $node = $parsed['node'];
    $real_id_mod = $parsed['id'];

    global $custom_pdos;
    $active_pdo = ($node === 'primary') ? $pdo : ($custom_pdos[$node] ?? null);
    $active_history_pdo = ($node === 'primary') ? $history_pdo : $active_pdo;

    try {
        $unit = '';
        if ($active_pdo !== null) {
            $stmtUnit = $active_pdo->prepare("SELECT COALESCE(unit, '') as unit FROM tagente_modulo WHERE id_agente_modulo = ?");
            $stmtUnit->execute([$real_id_mod]);
            $unitRow = $stmtUnit->fetch();
            $unit = $unitRow ? pretty_text($unitRow['unit']) : '';
        }

        $raw_data = get_module_history_data($active_pdo, $active_history_pdo, $id_mod, $start, $end, 5000, 'ASC');
        $data = [];
        foreach ($raw_data as $row) {
            $data[] = [
                'waktu' => date('Y-m-d H:i', $row['ts']),
                'datos' => $row['datos']
            ];
        }
        echo json_encode(['ok' => true, 'data' => $data, 'unit' => $unit]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

if ($api === 'sparklines' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $ids_raw = $_GET['ids'] ?? '';
    $ids_by_node = parse_node_ids($ids_raw);
    
    $miniGraphsData = [];
    global $custom_pdos;
    
    foreach ($ids_by_node as $node => $aids) {
        $active_pdo = ($node === 'primary') ? $pdo : ($custom_pdos[$node] ?? null);
        if ($active_pdo === null) continue;
        
        $placeholders = implode(',', array_fill(0, count($aids), '?'));
        $graphQuery = "SELECT id_agente_modulo, datos FROM (
                        SELECT id_agente_modulo, datos, 
                               ROW_NUMBER() OVER(PARTITION BY id_agente_modulo ORDER BY utimestamp DESC) as rn 
                        FROM tagente_datos WHERE id_agente_modulo IN ($placeholders)
                       ) sub WHERE rn <= 15 ORDER BY id_agente_modulo, rn DESC";
                       
        try {
            $stmtGraph = $active_pdo->prepare($graphQuery);
            $stmtGraph->execute($aids);
            $bulkGraphs = $stmtGraph->fetchAll();
            foreach ($bulkGraphs as $bg) {
                $prefixed_key = $node . ':' . $bg['id_agente_modulo'];
                $miniGraphsData[$prefixed_key][] = (float)$bg['datos'];
            }
        } catch (Exception $e) {
            // Fallback for older MySQL without ROW_NUMBER()
            foreach ($aids as $aid) {
                try {
                    $st = $active_pdo->prepare("SELECT datos FROM tagente_datos WHERE id_agente_modulo = ? ORDER BY utimestamp DESC LIMIT 15");
                    $st->execute([$aid]);
                    $vals = array_reverse(array_column($st->fetchAll(PDO::FETCH_ASSOC), 'datos'));
                    if (!empty($vals)) {
                        $prefixed_key = $node . ':' . $aid;
                        $miniGraphsData[$prefixed_key] = array_map('floatval', $vals);
                    }
                } catch (Throwable $t) {}
            }
        }
    }
    echo json_encode(['ok' => true, 'data' => $miniGraphsData]);
    exit;
}

if ($api === 'card_data' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $groupIdRaw = $_GET['group_id'] ?? '0';
    $keyword = $_GET['keyword'] ?? '%';
    $manual_ids = $_GET['manual_ids'] ?? '';
    $matchType = $_GET['match_type'] ?? 'contains';

    $groupParsed = parse_node_id($groupIdRaw);
    $manualIdsParsed = parse_node_ids($manual_ids);

    global $custom_pdos;
    $target_nodes = [];
    if ($groupParsed['id'] > 0) {
        $target_nodes[$groupParsed['node']] = [
            'pdo' => ($groupParsed['node'] === 'primary') ? $pdo : ($custom_pdos[$groupParsed['node']] ?? null),
            'group_id' => $groupParsed['id'],
            'agent_ids' => []
        ];
    } elseif (!empty($manualIdsParsed)) {
        foreach ($manualIdsParsed as $node => $aids) {
            $target_nodes[$node] = [
                'pdo' => ($node === 'primary') ? $pdo : ($custom_pdos[$node] ?? null),
                'group_id' => 0,
                'agent_ids' => $aids
            ];
        }
    } else {
        $target_nodes['primary'] = ['pdo' => $pdo, 'group_id' => 0, 'agent_ids' => []];
        if (!empty($custom_pdos)) {
            foreach ($custom_pdos as $cid => $cpdo) {
                $target_nodes[$cid] = ['pdo' => $cpdo, 'group_id' => 0, 'agent_ids' => []];
            }
        }
    }

    try {
        $stats = ['total'=>0, 'normal'=>0, 'critical'=>0, 'warning'=>0, 'unknown'=>0, 'not_init'=>0];
        $tableData = [];

        foreach ($target_nodes as $node => $info) {
            $active_pdo = $info['pdo'];
            if ($active_pdo === null) continue;

            $node_params = [];
            if ($matchType === 'exact') {
                $modulesArray = array_filter(array_map('trim', explode(',', $keyword)));
                if (empty($modulesArray)) {
                    $matchClause = "m.nombre = ''";
                } else {
                    $placeholders = implode(',', array_fill(0, count($modulesArray), '?'));
                    $matchClause = "m.nombre IN ($placeholders)";
                    foreach ($modulesArray as $modName) {
                        $node_params[] = $modName;
                    }
                }
            } else {
                $matchClause = "m.nombre LIKE ?";
                $node_params[] = '%' . str_replace(' ', '%', $keyword) . '%';
            }

            $whereClause = "";
            if (!empty($info['agent_ids'])) {
                $whereClause = "AND a.id_agente IN (" . implode(',', array_fill(0, count($info['agent_ids']), '?')) . ")";
                foreach ($info['agent_ids'] as $id) { $node_params[] = $id; }
            } elseif ($info['group_id'] > 0) {
                $stmtAllGroups = $active_pdo->query("SELECT id_grupo, parent FROM tgrupo");
                $allGroups = $stmtAllGroups->fetchAll();
                if (!function_exists('getChildGroupsLocal2')) {
                    function getChildGroupsLocal2($parentId, $allGroups) { 
                        $children = [$parentId]; 
                        foreach ($allGroups as $g) { 
                            if ($g['parent'] == $parentId) { 
                                $children = array_merge($children, getChildGroupsLocal2($g['id_grupo'], $allGroups)); 
                            } 
                        } 
                        return array_unique($children); 
                    }
                }
                $targetGroups = getChildGroupsLocal2($info['group_id'], $allGroups);
                $whereClause = "AND a.id_grupo IN (" . implode(',', array_fill(0, count($targetGroups), '?')) . ")";
                foreach ($targetGroups as $tg) { $node_params[] = $tg; }
            } else {
                try {
                    $recent_stmt = $active_pdo->query("SELECT id_agente FROM tagente WHERE disabled = 0 ORDER BY id_agente DESC LIMIT 50");
                    $recent_ids = $recent_stmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($recent_ids)) {
                        $whereClause = "AND a.id_agente IN (" . implode(',', $recent_ids) . ")";
                    } else {
                        $whereClause = "AND 1=0";
                    }
                } catch (Throwable $e) {
                    $whereClause = "AND 1=0";
                }
            }

            $sqlCommon = "FROM tagente_modulo m
                          INNER JOIN tagente a ON m.id_agente = a.id_agente
                          LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                          LEFT JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                          WHERE $matchClause AND a.disabled = 0 AND m.disabled = 0 $whereClause";

            // Stats count
            try {
                $sqlStats = "SELECT COALESCE(e.estado, 4) as status, COUNT(*) as count 
                             $sqlCommon 
                             GROUP BY status";
                $stStats = $active_pdo->prepare($sqlStats);
                $stStats->execute($node_params);
                $statsRows = $stStats->fetchAll();
                foreach ($statsRows as $sr) {
                    $c = (int)$sr['count'];
                    $stats['total'] += $c;
                    $v_estado = (int)$sr['status'];
                    if ($v_estado === 0) $stats['normal'] += $c;
                    elseif ($v_estado === 1) $stats['critical'] += $c;
                    elseif ($v_estado === 2) $stats['warning'] += $c;
                    elseif ($v_estado === 4) $stats['not_init'] += $c;
                    else $stats['unknown'] += $c;
                }
            } catch (Throwable $e) {}

            // Table rows
            try {
                $stTable = $active_pdo->prepare("SELECT a.id_agente, a.alias AS agent_alias, a.nombre AS agent_name, g.nombre AS group_name, a.direccion AS ip_address, m.id_agente_modulo, m.nombre AS module_name, e.timestamp, e.utimestamp AS last_contact, e.datos as current_value, m.min as low_limit, m.max as high_limit, COALESCE(m.unit, '') as unit, COALESCE(e.estado, 4) as estado $sqlCommon");
                $stTable->execute($node_params);
                $rows = $stTable->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $row['id_agente'] = $node . ':' . $row['id_agente'];
                    $row['id_agente_modulo'] = $node . ':' . $row['id_agente_modulo'];
                    
                    $row['agent_alias'] = pretty_text($row['agent_alias']);
                    $row['group_name'] = pretty_text($row['group_name']);
                    $row['module_name'] = pretty_text($row['module_name']);
                    $row['time_ago'] = timeAgo($row['timestamp']);
                    if (is_numeric($row['current_value'])) {
                        $row['current_value'] = (float)$row['current_value'];
                    }
                    $row['unit'] = pretty_text($row['unit']);
                    $tableData[] = $row;
                }
            } catch (Throwable $e) {}
        }

        // Sort tableData DESC by utimestamp
        usort($tableData, function($a, $b) {
            $tsA = (int)$a['last_contact'];
            $tsB = (int)$b['last_contact'];
            if ($tsA === $tsB) return 0;
            return ($tsA > $tsB) ? -1 : 1;
        });

        $historyData = [];
        if (!empty($tableData) && isset($_GET['history']) && $_GET['history'] === '1') {
            $historyLimit = 10;
            if (isset($_GET['chart_limit']) && (int)$_GET['chart_limit'] > 0) {
                $historyLimit = (int)$_GET['chart_limit'];
            }
            if (isset($_GET['view_type']) && $_GET['view_type'] === 'single_value') {
                $historyLimit = 1;
            }
            $slicedTableData = array_slice($tableData, 0, $historyLimit);
            $modIds = array_column($slicedTableData, 'id_agente_modulo');
            if (!empty($modIds)) {
                $range = isset($_GET['time_range']) ? $_GET['time_range'] : '86400';
                
                if ($range === 'custom' && isset($_GET['start_time']) && isset($_GET['end_time'])) {
                    $startTime = (int)$_GET['start_time'];
                    $endTime = (int)$_GET['end_time'];
                } else {
                    $endTime = time();
                    $startTime = $endTime - (int)$range;
                }
                
                foreach ($modIds as $modId) {
                    $modHist = get_module_history_data($pdo, $history_pdo, $modId, $startTime, $endTime, 2000, 'ASC');
                    foreach ($modHist as $h) {
                        $historyData[] = [
                            'id_mod' => $modId,
                            'utimestamp' => (int)$h['ts'],
                            'time' => date('d/m/Y H:i:s', $h['ts']),
                            'val' => (float)$h['datos']
                        ];
                    }
                }
            }
        }
        echo json_encode([
            'ok' => true, 
            'stats' => $stats, 
            'table' => $tableData, 
            'history' => $historyData, 
            'updated' => date('H:i:s')
        ]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

if ($api === 'status_details' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $groupIdRaw = $_GET['group_id'] ?? '0';
    $keyword = $_GET['keyword'] ?? '%';
    $manual_ids = $_GET['manual_ids'] ?? '';
    $statusFilter = $_GET['status_filter'] ?? 'all';
    $matchType = $_GET['match_type'] ?? 'contains';

    $groupParsed = parse_node_id($groupIdRaw);
    $manualIdsParsed = parse_node_ids($manual_ids);

    global $custom_pdos;
    $target_nodes = [];
    if ($groupParsed['id'] > 0) {
        $target_nodes[$groupParsed['node']] = [
            'pdo' => ($groupParsed['node'] === 'primary') ? $pdo : ($custom_pdos[$groupParsed['node']] ?? null),
            'group_id' => $groupParsed['id'],
            'agent_ids' => []
        ];
    } elseif (!empty($manualIdsParsed)) {
        foreach ($manualIdsParsed as $node => $aids) {
            $target_nodes[$node] = [
                'pdo' => ($node === 'primary') ? $pdo : ($custom_pdos[$node] ?? null),
                'group_id' => 0,
                'agent_ids' => $aids
            ];
        }
    } else {
        $target_nodes['primary'] = ['pdo' => $pdo, 'group_id' => 0, 'agent_ids' => []];
        if (!empty($custom_pdos)) {
            foreach ($custom_pdos as $cid => $cpdo) {
                $target_nodes[$cid] = ['pdo' => $cpdo, 'group_id' => 0, 'agent_ids' => []];
            }
        }
    }

    try {
        $data = [];
        foreach ($target_nodes as $node => $info) {
            $active_pdo = $info['pdo'];
            if ($active_pdo === null) continue;

            $node_params = [];
            if ($matchType === 'exact') {
                $modulesArray = array_filter(array_map('trim', explode(',', $keyword)));
                if (empty($modulesArray)) {
                    $matchClause = "m.nombre = ''";
                } else {
                    $placeholders = implode(',', array_fill(0, count($modulesArray), '?'));
                    $matchClause = "m.nombre IN ($placeholders)";
                    foreach ($modulesArray as $modName) {
                        $node_params[] = $modName;
                    }
                }
            } else {
                $matchClause = "m.nombre LIKE ?";
                $node_params[] = '%' . str_replace(' ', '%', $keyword) . '%';
            }

            $whereClause = "";
            if (!empty($info['agent_ids'])) {
                $whereClause = "AND a.id_agente IN (" . implode(',', array_fill(0, count($info['agent_ids']), '?')) . ")";
                foreach ($info['agent_ids'] as $id) { $node_params[] = $id; }
            } elseif ($info['group_id'] > 0) {
                $stmtAllGroups = $active_pdo->query("SELECT id_grupo, parent FROM tgrupo");
                $allGroups = $stmtAllGroups->fetchAll();
                if (!function_exists('getChildGroupsLocal3')) {
                    function getChildGroupsLocal3($parentId, $allGroups) { 
                        $children = [$parentId]; 
                        foreach ($allGroups as $g) { 
                            if ($g['parent'] == $parentId) { 
                                $children = array_merge($children, getChildGroupsLocal3($g['id_grupo'], $allGroups)); 
                            } 
                        } 
                        return array_unique($children); 
                    }
                }
                $targetGroups = getChildGroupsLocal3($info['group_id'], $allGroups);
                $whereClause = "AND a.id_grupo IN (" . implode(',', array_fill(0, count($targetGroups), '?')) . ")";
                foreach ($targetGroups as $tg) { $node_params[] = $tg; }
            }

            $sql = "SELECT a.id_agente, a.alias AS agent_alias, g.nombre AS group_name, a.direccion AS ip_address, 
                           m.id_agente_modulo, m.nombre AS module_name, e.timestamp, e.datos as current_value, 
                           m.min as low_limit, m.max as high_limit, COALESCE(m.unit, '') as unit, 
                           COALESCE(e.estado, 4) as estado
                    FROM tagente_modulo m
                    INNER JOIN tagente a ON m.id_agente = a.id_agente
                    LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                    LEFT JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                    WHERE $matchClause AND a.disabled = 0 AND m.disabled = 0 $whereClause";

            $st = $active_pdo->prepare($sql);
            $st->execute($node_params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $v_estado = (int)$row['estado'];
                if ($statusFilter !== 'all') {
                    if ($statusFilter === 'normal' && $v_estado !== 0) continue;
                    if ($statusFilter === 'critical' && $v_estado !== 1) continue;
                    if ($statusFilter === 'warning' && $v_estado !== 2) continue;
                    if ($statusFilter === 'not_init' && $v_estado !== 4) continue;
                    if ($statusFilter === 'unknown' && in_array($v_estado, [0,1,2,4])) continue;
                }

                $data[] = [
                    'id_agente'    => $node . ':' . $row['id_agente'],
                    'id_agente_modulo' => $node . ':' . $row['id_agente_modulo'],
                    'agent_alias'  => pretty_text($row['agent_alias']),
                    'group_name'   => pretty_text($row['group_name']),
                    'ip_address'   => $row['ip_address'],
                    'module_name'  => pretty_text($row['module_name']),
                    'current_value'=> (float)$row['current_value'],
                    'unit'         => pretty_text($row['unit']),
                    'estado'       => $v_estado,
                    'time_ago'     => timeAgo($row['timestamp']),
                    'timestamp'    => (int)$row['timestamp']
                ];
            }
        }

        // Sort by timestamp DESC
        usort($data, function($a, $b) {
            $tsA = (int)$a['timestamp'];
            $tsB = (int)$b['timestamp'];
            if ($tsA === $tsB) return 0;
            return ($tsA > $tsB) ? -1 : 1;
        });

        echo json_encode(['ok' => true, 'data' => $data]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

if ($api === 'export_data' && $db_status) {
    ob_clean();
    $keyword   = $_GET['keyword'] ?: '%';
    $matchType = $_GET['match_type'] ?? 'contains';
    $ids_by_node = parse_node_ids($_GET['agent_ids'] ?? '');

    try {
        $finalData = [];
        global $custom_pdos;
        foreach ($ids_by_node as $node => $aids) {
            $active_pdo = ($node === 'primary') ? $pdo : ($custom_pdos[$node] ?? null);
            if ($active_pdo === null) continue;

            $params = [];
            if ($matchType === 'exact') {
                $modulesArray = array_filter(array_map('trim', explode(',', $keyword)));
                if (empty($modulesArray)) {
                    $matchClause = "m.nombre = ''";
                } else {
                    $placeholdersMod = implode(',', array_fill(0, count($modulesArray), '?'));
                    $matchClause = "m.nombre IN ($placeholdersMod)";
                    foreach ($modulesArray as $modName) {
                        $params[] = $modName;
                    }
                }
            } else {
                $matchClause = "m.nombre LIKE ?";
                $params[] = '%' . str_replace(' ', '%', $keyword) . '%';
            }

            $placeholders = implode(',', array_fill(0, count($aids), '?'));
            foreach ($aids as $id) { $params[] = (int)$id; }

            $query = "SELECT a.alias AS agent_alias, g.nombre AS group_name, a.direccion AS ip_address, m.nombre AS module_name, e.timestamp, e.datos, m.min, m.max, COALESCE(m.unit, '') as unit, COALESCE(e.estado, 4) as estado
                      FROM tagente_modulo m
                      INNER JOIN tagente a ON m.id_agente = a.id_agente
                      LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                      LEFT JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                      WHERE $matchClause AND a.id_agente IN ($placeholders) AND a.disabled = 0 AND m.disabled = 0
                      ORDER BY a.alias ASC, m.nombre ASC";

            $stExp = $active_pdo->prepare($query);
            $stExp->execute($params);
            $rows = $stExp->fetchAll(PDO::FETCH_ASSOC);

            foreach($rows as $r) {
                $statusObj = map_pandora_status($r['estado']);
                $unit_str = $r['unit'] ? " " . $r['unit'] : "";
                $finalData[] = [
                    'ts' => $r['timestamp'] ? date('Y-m-d H:i:s', strtotime($r['timestamp'])) : 'N/A',
                    'agent' => pretty_text($r['agent_alias']),
                    'group' => pretty_text($r['group_name']),
                    'ip' => $r['ip_address'] ?: '-',
                    'module' => pretty_text($r['module_name']),
                    'current' => (float)$r['datos'] . $unit_str,
                    'status' => $statusObj['label']
                ];
            }
        }

        $format = $_GET['format'] ?? 'csv';
        if ($format === 'csv') {
            header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="Metrics_Status_Export_'.date('Ymd_His').'.csv"');
            echo "Last Update|Node Agent|Group|IP Address|Sensor Module|Current Value|Status\n";
            foreach($finalData as $d) echo "{$d['ts']}|{$d['agent']}|{$d['group']}|{$d['ip']}|{$d['module']}|{$d['current']}|{$d['status']}\n";
        } else {
            header('Content-Type: text/plain'); header('Content-Disposition: attachment; filename="Metrics_Status_Report_'.date('Ymd_His').'.txt"');
            echo "UNIVERSAL METRICS MONITORING STATUS REPORT (Delimiter: |)\nGenerated: " . date('Y-m-d H:i:s') . "\n";
            echo str_repeat("-", 145) . "\n" . sprintf("%-20s | %-20s | %-15s | %-15s | %-25s | %-10s | %-10s\n", "Last Update", "Node Agent", "Group", "IP Address", "Sensor Module", "Value", "Status") . str_repeat("-", 145) . "\n";
            foreach($finalData as $d) echo sprintf("%-20s | %-20s | %-15s | %-15s | %-25s | %-10s | %-10s\n", $d['ts'], substr($d['agent'],0,18), substr($d['group'],0,13), substr($d['ip'],0,14), substr($d['module'],0,23), $d['current'], $d['status']);
        }
    } catch (Exception $e) { echo "Export Error: " . $e->getMessage(); }
    exit;
}

// Diagnostic endpoint: show status of all DB nodes
if ($api === 'db_nodes_status') {
    ob_clean(); header('Content-Type: application/json');
    global $custom_pdos, $custom_connections, $custom_db_statuses;
    
    $nodes = [];
    
    // Primary node
    $nodes[] = [
        'id' => 'primary',
        'name' => 'Primary (Pandora FMS)',
        'status' => $db_status ? 'connected' : 'failed',
        'error' => $db_error ?: null,
        'agents_count' => 0
    ];
    if ($db_status) {
        try {
            $st = $pdo->query("SELECT COUNT(*) FROM tagente WHERE disabled = 0");
            $nodes[0]['agents_count'] = (int)$st->fetchColumn();
        } catch (Throwable $e) {}
    }
    
    // History node
    $history_tokens = [];
    if ($db_status) {
        try {
            $st = $pdo->query("SELECT token, value FROM tconfig WHERE token LIKE '%history%'");
            if ($st) {
                $history_tokens = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            }
        } catch (Throwable $e) {}
    }
    $nodes[] = [
        'id' => 'history',
        'name' => 'Default Historical DB',
        'status' => $history_db_status ? 'connected' : ($history_db_host ? 'failed' : 'not_configured'),
        'type' => 'history_only',
        'tconfig_history_tokens' => $history_tokens
    ];
    
    // Custom nodes
    if (!empty($custom_connections)) {
        foreach ($custom_connections as $conn) {
            $cid = $conn['id'] ?? '';
            $cname = $conn['name'] ?? $cid;
            $is_connected = isset($custom_pdos[$cid]);
            $node_info = [
                'id' => $cid,
                'name' => $cname,
                'host' => $conn['host'] ?? '',
                'dbname' => $conn['dbname'] ?? '',
                'status' => $is_connected ? 'connected' : 'failed',
                'agents_count' => 0,
                'has_tagente' => false
            ];
            
            if ($is_connected) {
                // Check if DB has tagente table (= full Pandora DB) and count agents
                try {
                    $st = $custom_pdos[$cid]->query("SELECT COUNT(*) FROM tagente WHERE disabled = 0");
                    $count = (int)$st->fetchColumn();
                    $node_info['agents_count'] = $count;
                    $node_info['has_tagente'] = true;
                } catch (Throwable $e) {
                    $node_info['has_tagente'] = false;
                    $node_info['table_error'] = $e->getMessage();
                }
            }
            
            $nodes[] = $node_info;
        }
    }
    
    echo json_encode(['ok' => true, 'nodes' => $nodes]);
    exit;
}

$isStandalone = (isset($_GET['standalone']) && $_GET['standalone'] == '1') || (isset($_GET['s']) && $_GET['s'] == '1');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metrics Monitoring Dashboard</title>
    <link rel="icon" href="<?= $PANDORA_BASE_URL ?>/images/pandora.ico" type="image/x-icon">
    <link href="<?= h($vendor_url) ?>/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= h($vendor_url) ?>/fonts/fonts.css" />
    <link href="<?= h($vendor_url) ?>/bootstrap/bootstrap.min.css" rel="stylesheet">
    <script src="<?= h($vendor_url) ?>/echarts/echarts.min.js"></script>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #334155; font-size: 14px; -webkit-font-smoothing: antialiased; } * { box-sizing: border-box; }
        body { background-color: #f4f6f8; margin: 0; padding: 0; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-size: 18px !important; vertical-align: middle; line-height: 1; }

        <?php if ($isStandalone): ?>
        .pandora-header-top, .pandora-header-bottom, .top-controls { display: none !important; visibility: hidden !important; }
        body { background-color: #ffffff !important; padding: 0 !important; }
        .main-content { padding: 20px 25px !important; width: 100% !important; max-width: 100% !important; margin: 0 !important; }
        .dashboard-card { box-shadow: none !important; border: 1px solid #eee !important; border-radius: 4px !important; width: 100% !important; }
        .grid-layout { grid-template-columns: 1fr !important; gap: 0 !important; columns: 1 !important; display: block !important; }
        <?php endif; ?>

        .pandora-header-top { background-color: #ffffff; border-bottom: 1px solid #e0e4e8; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 25px; z-index: 10; }
        .header-logo { height: 24px; width: auto; }
        .header-divider { width: 1px; height: 28px; background-color: #dce1e5; margin: 0 20px; }
        .header-title-box { display: flex; flex-direction: column; line-height: 1.2; margin-right: 40px; }
        .header-title-box .main-title { font-size: 14px !important; font-weight: normal !important; color: #0b1a26 !important; }
        .nav-icon-btn { color: #4a5568 !important; text-decoration: none; display: flex; align-items: center; justify-content: center; height: 36px; width: 36px; border-radius: 50%; transition: 0.2s; border:none; background:transparent; cursor:pointer;}

        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; }
        .page-breadcrumb { font-size: 11px !important; color: #64748b !important; margin-bottom: 4px; font-weight: normal !important; text-transform: uppercase; letter-spacing: 0.5px; }
        .page-title { font-size: 18px !important; color: #0b1a26 !important; margin: 0; font-weight: 600 !important; line-height: 1.2; }

        .top-controls { display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: center !important; }
        .btn-apply { background: #004d40; color: #fff !important; border: none; padding: 8px 25px; border-radius: 4px; font-weight: normal !important; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap; transition:0.2s;}
        .btn-apply:hover { background: #00332a; }
        .btn-secondary-custom { background: #fff; color: #4a5568 !important; border: 1px solid #dce1e5; padding: 8px 20px; border-radius: 4px; font-weight: normal !important; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;}
        .icon-btn-card { background: transparent; border: none; padding: 4px; cursor: pointer; color: #7f8c8d; border-radius: 4px; display:inline-flex; align-items:center; }
        .icon-btn-card:hover { background: #e0e4e8; color: #0b1a26; }

        .main-content { padding: 0 30px 30px 30px; }
        
        .grid-layout { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 700px), 1fr)); gap: 20px; align-items: start; }
        .grid-layout.single-item { grid-template-columns: 1fr; }
        @media (max-width: 1200px) { .grid-layout { grid-template-columns: 1fr; } }

        .dashboard-card { background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: inline-block; width:100%; margin-bottom:20px; break-inside: avoid; vertical-align: top; overflow: hidden; border: 1px solid #f0f3f5; cursor: default; transition: transform 0.2s, box-shadow 0.2s; }
        .dashboard-card.dragging { opacity: 0.5; transform: scale(0.98); box-shadow: 0 10px 20px rgba(0,0,0,0.1); cursor: grabbing; }
        .dashboard-card.drag-over { border: 2px dashed #004d40; border-radius: 8px; }

        .dashboard-card-header { padding: 15px 20px; border-bottom: 1px solid #e0e4e8; background-color: #f8f9fa; display: flex; justify-content: space-between; align-items: center; cursor: grab; }
        .dashboard-card-header:active { cursor: grabbing; }
        .dashboard-card-title { font-size: 14px !important; font-weight: 500 !important; color: #1e293b !important; margin: 0; letter-spacing: 0.3px; display: flex; align-items: center; gap: 8px; pointer-events: none; }
        .dashboard-card-body { padding: 20px; display: flex; flex-direction: column; gap: 20px; flex-grow:1;}

        .mini-stats-row { display: flex; gap: 10px; width: 100%; flex-wrap: wrap;}
        .mini-stat { flex: 1; min-width: 90px; text-align: center; padding: 12px 5px; border-radius: 6px; background: #ffffff; border: 1px solid #e0e4e8; border-bottom: 4px solid #ccc; cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .mini-stat:hover { transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        .mini-stat-val { font-size: 22px !important; font-weight: normal !important; line-height: 1; margin-bottom: 5px; }
        .mini-stat-label { font-size: 9px !important; text-transform: uppercase; color: #7f8c8d; font-weight: normal !important; white-space: nowrap; }

        .st-border-black { border-bottom-color: #0b1a26; } .text-black { color: #0b1a26 !important; }
        .st-border-green { border-bottom-color: #2ecc71; } .text-green { color: #2ecc71 !important; }
        .st-border-red { border-bottom-color: #e74c3c; } .text-red { color: #e74c3c !important; }
        .st-border-yellow { border-bottom-color: #f1c40f; } .text-yellow { color: #f1c40f !important; }
        .st-border-gray { border-bottom-color: #95a5a6; } .text-gray { color: #334155 !important; }
        .st-border-blue { border-bottom-color: #3498db; } .text-blue { color: #3498db !important; }

        .table-wrap { overflow-x: auto; flex-grow: 1; border: 1px solid #f0f3f5; border-radius: 6px; }
        table.table-pfms { border-collapse: collapse !important; width: 100% !important; margin: 0 !important;}
        table.table-pfms thead th { background-color: #ffffff !important; border-bottom: 2px solid #e0e4e8 !important; text-transform: uppercase; padding: 10px 15px !important; font-weight: normal !important; color: #7f8c8d !important; font-size: 10px !important; position: sticky; top: 0; z-index: 1;}
        table.table-pfms tbody td { font-weight: normal !important; border-bottom: 1px solid #f0f3f5; padding: 12px 15px !important; color: #0b1a26 !important; white-space: normal; word-break: break-word; min-width: 100px; max-width: 300px; vertical-align: middle;}

        .node-wrap { display: inline-flex; align-items: center; gap: 8px; line-height: 1; vertical-align: middle; }
        .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; position: relative; top: -1px; }

        .bg-green { background: linear-gradient(135deg, #2ecc71, #27ae60) !important; color: #fff !important; }
        .bg-red { background: linear-gradient(135deg, #e74c3c, #c0392b) !important; color: #fff !important; }
        .bg-yellow { background: linear-gradient(135deg, #f1c40f, #f39c12) !important; color: #fff !important; }
        .bg-gray { background: linear-gradient(135deg, #95a5a6, #7f8c8d) !important; color: #fff !important; }
        .bg-blue { background: linear-gradient(135deg, #3498db, #2980b9) !important; color: #fff !important; }

        .agent-link { color: #1976d2 !important; text-decoration: none; font-weight: normal !important; font-size: 14px !important; }
        .ip-text { color: #d63384 !important; font-size: 11px !important; font-weight: normal; background:#fff0f6; padding:2px 6px; border-radius:4px;}
        .status-pill { padding: 6px 12px; border-radius: 4px; font-weight: normal !important; font-size: 11px !important; display: inline-block; text-align: center; border:none; }

        .heatmap-wrap { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 8px; padding: 5px; }
        .heat-box { width: 100%; height: 32px; border-radius: 4px; display: block; line-height: 32px; text-align: center; font-weight: normal !important; font-size: 11px !important; cursor: pointer; text-decoration: none !important; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 0 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #ffffff !important; transition: 0.2s opacity; box-sizing: border-box; }
        .heat-box:hover { opacity: 0.8; }
        canvas.mini-chart { width: 120px !important; height: 30px !important; cursor: pointer; }
        .limit-text { font-size: 10px !important; color: #7f8c8d; line-height:1.2;}

        .pagination-container { display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; background: #fafafa; border-top: 1px solid #e0e4e8; border-radius: 0 0 6px 6px; }
        .pagination-btn { background: #fff; border: 1px solid #dce1e5; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: normal; color: #4a5568; transition: 0.2s;}
        .pagination-btn:hover:not(:disabled) { background: #004d40; color: #fff; border-color: #004d40;}
        .pagination-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .btn-pfms { padding: 6px 14px; border-radius: 4px; font-size: 12px; font-weight: 500; cursor: pointer; transition: 0.2s; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 6px; }
        .btn-outline-pfms { background: #fff; border-color: #dce1e5; color: #4a5568; }
        .btn-outline-pfms:hover { border-color: #cbd5e1; background: #f8fafc; }

        .search-input-header { width: 0; padding: 0; border: none; outline: none; background: transparent; transition: all 0.3s; font-size: 12px; font-weight: normal; color: #333; }
        .search-input-header.active { width: 150px; padding: 4px 10px; border-bottom: 2px solid #004d40; margin-right: 10px; background: #fff; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-box { background: #fff; width: 550px; padding: 25px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e0e4e8; max-height: 90vh; overflow-y: auto; }
        .detail-modal-box { width: 1000px !important; max-width: 95% !important; padding: 0; overflow: hidden; display: flex; flex-direction: column;}
        .iframe-modal-box { width: 950px; max-width: 95%; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); display: flex; flex-direction: column;}
        .iframe-header { padding: 15px 20px; border-bottom: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; background-color: #f8f9fa; }
        .iframe-title { font-weight: normal !important; font-size: 14px !important; color: #0b1a26;}

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 11px !important; text-transform: uppercase; font-weight: normal !important; color: #7f8c8d; margin-bottom: 5px; }
        .form-control-fix { width: 100%; height: 36px; padding: 8px 12px; border: 1px solid #dce1e5; border-radius: 4px; font-weight: normal !important; background-color: #fff; outline: none; }

        .bulk-scroll { border: 1px solid #dce1e5; border-radius: 4px; max-height: 150px; overflow-y: auto; padding: 10px; background: #fafafa; }
        .bulk-item { display: flex; align-items: center; gap: 10px; padding: 4px 0; border-bottom: 1px solid #eee; cursor: pointer; }

        .agent-list-scroll { max-height: 180px; overflow-y: scroll; padding: 5px 0; }
        .agent-item { display: flex; align-items: center; padding: 6px 15px; cursor: pointer; border-bottom: 1px solid #f5f5f5; }
        .agent-item:hover { background: #f8f9fa; }
        .agent-item input[type="checkbox"] { width: 16px; height: 16px; margin-right: 10px; flex-shrink: 0; }
        .agent-item label { display: inline-block !important; flex-grow: 1; margin: 0 !important; font-size: 13px !important; text-transform: none !important; color: #333 !important; font-weight: normal !important; cursor: pointer; }

        .modal-search-container { position: relative; max-width: 250px; width: 100%; }
        .modal-search-container .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #7f8c8d !important; font-size: 16px !important; pointer-events: none; }
        .modal-search-container input { width: 100%; height: 30px; padding: 5px 15px 5px 32px; border-radius: 4px; border: 1px solid #dce1e5; background-color: #ffffff; font-size: 12px !important; color: #333 !important; outline:none;}
        .modal-search-container input:focus { border-color: #b5c1c9; box-shadow: 0 0 0 2px rgba(181, 193, 201, 0.2); }

        .chart-controls { padding: 15px 25px; background: #fff; border-bottom: 1px solid #eee; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;}
        .chart-container-large { padding: 20px; height: 400px; width: 100%; position: relative; background-color: #ffffff;}

        /* Dashboard landing page and selection styles */
        .d-none { display: none !important; }
        .list-table-wrap { background: #fff; border: 1px solid #e0e4e8; border-radius: 6px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.02);}
        table.list-table { border-collapse: collapse !important; width: 100% !important; margin: 0 !important; }
        table.list-table thead th { background-color: #fafbfc !important; border-bottom: 1px solid #e0e4e8 !important; text-transform: uppercase; padding: 15px 20px !important; font-weight: normal !important; color: #7f8c8d !important; font-size: 11px !important; }
        table.list-table tbody td { padding: 15px 20px !important; border-bottom: 1px solid #f0f3f5; color: #0b1a26 !important; vertical-align: middle; transition:0.2s;}
        table.list-table tbody tr:hover td { background-color: #f8f9fa !important; }
        
        .dash-name-link { font-weight: normal !important; font-size: 14px !important; color: #1976d2 !important; text-decoration: none; display: flex; align-items: center; gap:8px; cursor:pointer;}
        .dash-name-link:hover { text-decoration: underline; color: #0d47a1 !important; }
        .dash-badge { background: #e0f2f1; color: #004d40; padding: 2px 8px; border-radius: 10px; font-size: 10px !important; font-weight: normal; }

        .list-search-box { padding: 0 15px 0 35px !important; height: 36px !important; margin: 0 !important; box-sizing: border-box !important; width: 300px; border: 1px solid #dce1e5; border-radius: 4px; font-size: 13px !important; font-weight: normal !important; outline: none; background: #fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%237f8c8d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>') no-repeat 10px center; transition:0.2s; }
        .list-search-box:focus { border-color:#004d40; box-shadow: 0 0 0 2px rgba(0,77,64,0.1); }
        
        .btn-action { background: transparent; color: #7f8c8d; border: none; height: 32px; width: 32px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: 0.2s; }
        .btn-action:hover { background: #e0e4e8; color: #0b1a26; }
        .btn-action.btn-delete:hover { background: #fee2e2; color: #ef4444; }
        
        /* MULTI-SELECT TAGS CSS */
        .selected-tags-container { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 8px; min-height: 0; }
        .module-tag { background: #e0f2f1; color: #004d40; border: 1px solid #b2dfdb; padding: 2px 8px; border-radius: 4px; font-size: 11px; display: flex; align-items: center; gap: 5px; font-weight: 500; }
        .module-tag .remove-tag { cursor: pointer; color: #00796b; font-weight: bold; font-size: 14px; line-height: 1; }
        .module-tag .remove-tag:hover { color: #e74c3c; }

        .dropdown-wrapper { position: relative; display: block; width: 100%; }
        .custom-dropdown { position: absolute; top: 38px; left: 0; background: #fff; border: 1px solid #004d40; border-radius: 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 3000; overflow: hidden; display: flex; flex-direction: column; width: 100%; box-sizing: border-box; }
        .custom-dropdown-list { list-style: none; margin: 0; padding: 0; max-height: 200px; overflow-y: auto; }
        .custom-dropdown-list li { padding: 8px 12px; border-bottom: 1px solid #f0f3f5; cursor: pointer; font-size: 12px; color: #333; transition: background 0.1s; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: flex; align-items: center;}
        .custom-dropdown-list li:hover { background: #e0f2f1; color: #004d40; font-weight: normal; }
        .custom-dropdown-list li.selected { background: #004d40; color: #fff; font-weight: normal; }
        .radio-btn-group { display: flex; gap: 15px; margin-bottom: 10px; }
        .radio-btn-group label { display: flex; align-items: center; gap: 5px; font-size: 13px !important; text-transform: none !important; color: #0b1a26 !important; cursor: pointer; font-weight: normal !important; margin-bottom: 0 !important;}
        .radio-btn-group input { width: 16px; height: 16px; margin: 0; cursor: pointer;}
        
        .modal-header-custom { padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .modal-footer-custom { padding: 15px 20px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; }

        /* DB NODES STATUS BANNER */
        .db-nodes-banner { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 8px 30px; display: none; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 11px; }
        .db-nodes-banner.visible { display: flex; }
        .db-node-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 12px; font-weight: 500; border: 1px solid transparent; }
        .db-node-chip.connected { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .db-node-chip.failed { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .db-node-chip.not_configured { background: #e2e8f0; color: #64748b; border-color: #cbd5e1; }
        .db-node-chip .node-dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
        .db-node-chip.connected .node-dot { background: #10b981; }
        .db-node-chip.failed .node-dot { background: #ef4444; }
        .db-node-chip.not_configured .node-dot { background: #94a3b8; }
        .db-nodes-toggle { background: none; border: none; color: #64748b; cursor: pointer; font-size: 11px; padding: 2px 6px; display: inline-flex; align-items: center; gap: 3px; transition: 0.2s; border-radius: 4px; }
        .db-nodes-toggle:hover { background: #e2e8f0; color: #334155; }
    </style>
</head>
<body class="<?= $isStandalone ? 'is-standalone-view' : '' ?>">

<div class="pandora-header-top">
    <div class="header-left">
        <img src="<?= $PANDORA_BASE_URL ?>/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box"><span class="main-title">Pandora FMS</span><span class="sub-title">PFMS-Toolkit</span></div>
    </div>
    <div class="header-right"><a href="<?= $PANDORA_BASE_URL ?>/index.php" class="nav-icon-btn"><span class="material-symbols-outlined">home</span></a></div>
</div>

<div class="pandora-header-bottom">
    <div class="breadcrumb-box">
        <span class="page-breadcrumb" id="mainBreadcrumb"><?= h($dynamic_breadcrumb) ?></span>
        <h1 class="page-title" id="pageMainTitle">Metrics Dashboard</h1>
    </div>
    
    <div class="top-controls" id="listTopControls">
        <input type="text" id="listSearch" class="list-search-box" placeholder="Search dashboards..." onkeyup="renderDashboardList()">
        <button class="btn-apply" onclick="openDashMetaModal()"><span class="material-symbols-outlined" style="font-size:18px!important;">add</span> Create Dashboard</button>
        <input type="file" id="importBackupFile" style="display:none" onchange="importDashboardConfig(event)">
    </div>
    
    <div class="top-controls d-none" id="detailTopControls">
        <button class="btn-secondary-custom" onclick="closeDashboard()" title="Back to List"><span class="material-symbols-outlined">arrow_back</span> Back</button>
        <button class="btn-apply" onclick="openBuilder()"><span class="material-symbols-outlined">add</span> Add Widget</button>
    </div>
</div>

<!-- DB Nodes Status Banner -->
<div class="db-nodes-banner" id="dbNodesBanner">
    <span style="color: #475569; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
        <span class="material-symbols-outlined" style="font-size:14px!important; color:#004d40;">dns</span> DB Nodes:
    </span>
    <div id="dbNodesChips" style="display: inline-flex; gap: 8px; flex-wrap: wrap; align-items: center;"></div>
    <button class="db-nodes-toggle" id="dbNodesToggle" onclick="toggleDbNodesBanner()" title="Toggle DB nodes info">
        <span class="material-symbols-outlined" style="font-size:14px!important;">expand_less</span>
    </button>
</div>

<div id="view_list" class="main-content pt-4">
    <div class="list-table-wrap">
        <table class="list-table" id="dashListTable">
            <thead>
                <tr>
                    <th style="width: 50%;">Dashboard Name</th>
                    <th>Total Widgets</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<div id="view_detail" class="d-none">
    <div class="main-content pt-4">
        <div class="grid-layout" id="dashboardGrid"></div>
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

<div class="modal-overlay" id="detailModal" style="z-index: 2050;">
    <div class="modal-box detail-modal-box">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:1px solid #e0e4e8; padding:20px 25px; background: #f8f9fa;">
            <div style="flex: 1;">
                <h5 style="font-weight: normal!important; text-transform:uppercase; margin:0; color:#0b1a26;" id="detailModalTitle">Module Details</h5>
                <div style="font-size:11px!important; color:#7f8c8d; margin-top:5px; font-weight: normal;">* Displays the list of modules based on the status group you clicked.</div>
            </div>
            <div style="display:flex; align-items:center; gap: 15px;">
                <div class="modal-search-container">
                    <span class="material-symbols-outlined search-icon">search</span>
                    <input type="text" id="detailModalSearch" placeholder="Search agent or module..." onkeyup="filterDetailModal()">
                </div>
                <span class="material-symbols-outlined" style="cursor:pointer; color:#7f8c8d;" onclick="closeDetailModal()">close</span>
            </div>
        </div>
        <div id="detailModalContent" style="padding: 0; background: #fff;"></div>
    </div>
</div>

<div class="modal-overlay" id="exportModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px;">
            <h5 style="font-weight: normal!important; text-transform:uppercase;">Export Status Data</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeExport()">close</span>
        </div>
        <div class="form-group">
            <div style="display:flex; justify-content:space-between; align-items:center;"><label>SELECT AGENTS</label><button type="button" class="btn btn-sm text-primary p-0" style="font-size:10px!important; font-weight: normal; background:none; border:none; cursor:pointer;" onclick="toggleExportAll()">[ Select/Clear All ]</button></div>
            <div class="bulk-scroll" id="export_agent_list"></div>
        </div>
        <div class="form-group"><label>FORMAT</label><select id="e_format" class="form-control-fix"><option value="csv">CSV (Pipe Delimited)</option><option value="txt">TXT (Report)</option></select></div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;"><button class="btn-secondary-custom" onclick="closeExport()">Cancel</button><button class="btn-apply" onclick="processExport()">Download Report</button></div>
    </div>
</div>

<div class="modal-overlay" id="builderModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px;">
            <h5 style="font-weight: normal!important; text-transform:uppercase;" id="builderTitle">Build Widget</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;color:#7f8c8d;" onclick="closeBuilder()">close</span>
        </div>
        <div class="form-group"><label>Widget Title</label><input type="text" id="b_title" class="form-control-fix" placeholder="e.g. CPU & Memory Load"></div>

        <div class="form-group">
            <label>Style View</label>
            <select id="b_view_type" class="form-control-fix" onchange="toggleViewTypeOptions()">
                <option value="table">Table View (Detailed)</option>
                <option value="history_table">History Table View</option>
                <option value="single_value">Single Value Card (Sparkline)</option>
                <option value="heatmap">Heatmap View (Grid Summary)</option>
                <option value="cards">Cards Status View (Stats Only)</option>
                <option value="pie">Pie Chart (Metrics Value)</option>
                <option value="donut">Donut Chart (Metrics Value)</option>
                <option value="line">Line Chart (Value Comparison)</option>
                <option value="area">Area Chart (Value Comparison)</option>
                <option value="bar">Bar Chart (Value Comparison)</option>
                <option value="table_viewer">View Snapshot Module</option>
            </select>
        </div>

        <div class="form-group" id="wrap_chart_options" style="display:none;">
            <label>Pie/Donut Slice Limit</label>
            <select id="b_chart_limit" class="form-control-fix" style="margin-bottom:12px;">
                <option value="0">Show All Slices</option>
                <option value="5">Top 5 + Others</option>
                <option value="10">Top 10 + Others</option>
            </select>
            <label>Show Value Count in Legend</label>
            <select id="b_show_legend_count" class="form-control-fix">
                <option value="1">Show Count (e.g. UP (12))</option>
                <option value="0">Hide Count (Label Only)</option>
            </select>
        </div>

        <div class="form-group" id="wrap_show_stats">
            <label>Show Status Cards (UP, Critical, etc.)</label>
            <select id="b_show_stats" class="form-control-fix">
                <option value="1">Show</option>
                <option value="0">Hide</option>
            </select>
        </div>

        <div class="form-group" id="wrap_single_value_options" style="display:none;">
            <label>Show Module & Agent Name</label>
            <select id="b_show_module_name" class="form-control-fix">
                <option value="1">Show</option>
                <option value="0">Hide</option>
            </select>
        </div>

        <div class="form-group"><label>Filter By Group</label><select id="b_group" class="form-control-fix" onchange="toggleManualSelector(); refreshBuilderModuleList();"></select></div>
        <div id="manual_selector_box" class="form-group" style="display:none;">
            <label>Select Agents (Unlimited)</label>
            <div style="border:1px solid #dce1e5; border-radius:6px; background:#fff;">
                <div style="padding:10px; background:#f8f9fa; border-bottom:1px solid #eee; display:flex; align-items:center; gap:8px; justify-content:space-between;">
                    <div style="display:flex; align-items:center; gap:8px; flex:1;">
                        <span class="material-symbols-outlined" style="font-size:18px;">search</span>
                        <input type="text" id="inner_search" class="form-control-fix" placeholder="Filter..." style="border:none; margin-bottom:0; height:25px; padding:0 4px;" onkeyup="filterAgentsInList()">
                    </div>
                    <button type="button" style="background:none; border:none; color:#1976d2; font-size:10px!important; font-weight: normal; cursor:pointer; padding:0;" onclick="toggleBuilderAgentAll()">[ Select/Clear All ]</button>
                </div>
                <div class="agent-list-scroll" id="agent_checkbox_list"></div>
                <div style="font-size:11px; font-weight: normal; color:#004d40; padding:10px; background:#e0f2f1; border-top:1px solid #eee;" id="sel_count">0 Selected</div>
            </div>
        </div>
        <div class="form-group">
            <label>Module Selection Mode</label>
            <div class="radio-btn-group">
                <label>
                    <input type="radio" name="b_match_type" value="contains" checked onchange="toggleBuilderMatchMode()"> Keyword Match (Contains)
                </label>
                <label>
                    <input type="radio" name="b_match_type" value="exact" onchange="toggleBuilderMatchMode()"> Exact Selection (List)
                </label>
            </div>
        </div>

        <div class="form-group" id="wrap_contains">
            <label>Table Keyword (Module Name)</label>
            <input type="text" id="b_keyword" class="form-control-fix" value="%" placeholder="Use % for wildcard">
        </div>

        <div class="form-group" id="wrap_exact" style="display:none;">
            <label>Select Module Names (Direct Selection)</label>
            <div id="exact_selected_tags" class="selected-tags-container"></div>
            <div class="dropdown-wrapper">
                <input type="text" id="exact_search_input" class="form-control-fix" placeholder="Search and select module names..." onkeyup="renderExactModuleList()" onfocus="showExactDropdown()" autocomplete="off" style="margin-bottom:0;">
                <input type="hidden" id="p_keyword_exact" value="">
                <div id="exact_dropdown" class="custom-dropdown" style="display:none;">
                    <ul id="exact_module_ul" class="custom-dropdown-list"></ul>
                </div>
            </div>
        </div>
        <div style="display:flex; gap:15px; margin-bottom: 15px;">
            <div style="flex:1;">
                <label>Historical Icon Size (px)</label>
                <input type="number" id="b_icon_size" class="form-control-fix" value="18" min="10" max="40">
            </div>
            <div style="flex:1;">
                <label>Table Font Size (px)</label>
                <input type="number" id="b_font_size" class="form-control-fix" value="14" min="8" max="24">
            </div>
            <div style="flex:1;">
                <label>Chart Font Size (px)</label>
                <input type="number" id="b_chart_font_size" class="form-control-fix" value="11" min="8" max="24">
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 15px;">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                <input type="checkbox" id="b_use_raw" style="width:16px; height:16px; margin:0;"> Use Raw Value (No Formatting)
            </label>
        </div>

        <div class="form-group" id="wrap_columns_select" style="margin-bottom: 15px;">
            <label style="font-weight: 600; margin-bottom: 8px; display: block;">Visible Columns (Table View Only)</label>
            <div style="display: flex; flex-wrap: wrap; gap: 15px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0;">
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px;">
                    <input type="checkbox" class="col-visibility-chk" value="agent" checked> Agent
                </label>
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px;">
                    <input type="checkbox" class="col-visibility-chk" value="group" checked> Group
                </label>
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px;">
                    <input type="checkbox" class="col-visibility-chk" value="ip" checked> IP Address
                </label>
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px;">
                    <input type="checkbox" class="col-visibility-chk" value="module" checked> Sensor Module
                </label>
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px;">
                    <input type="checkbox" class="col-visibility-chk" value="status" checked> Status
                </label>
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px;">
                    <input type="checkbox" class="col-visibility-chk" value="history" checked> Metrics History
                </label>
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-weight: normal; font-size: 13px;">
                    <input type="checkbox" class="col-visibility-chk" value="threshold" checked> Threshold
                </label>
            </div>
        </div>

        <div style="display:flex; gap:15px;"><div style="flex:1;"><label>Rows Per Page (Limit)</label>
            <input type="number" id="b_limit" class="form-control-fix" value="15" min="1"></div><div style="flex:1;"><label>Auto-Refresh</label><select id="b_refresh" class="form-control-fix"><option value="30">30s</option><option value="60" selected>1m</option><option value="300">5m</option></select></div></div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;"><button class="btn-secondary-custom" onclick="closeBuilder()">Cancel</button><button class="btn-apply" id="btnSaveWidget" onclick="saveWidget()">Save Widget</button></div>
    </div>
</div>

<div class="modal-overlay" id="dashMetaModal">
    <div class="modal-box">
        <div class="modal-header-custom">
            <h5 style="font-weight: 600!important; margin:0;" id="dashMetaTitle">Create Dashboard</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeDashMetaModal()">close</span>
        </div>
        <div class="modal-body-scroll" style="padding:20px 0;">
            <div class="form-group">
                <label>Dashboard Title</label>
                <input type="text" id="m_dash_title" class="form-control-fix" placeholder="e.g. Linux Servers Metrics Dashboard">
            </div>
        </div>
        <div class="modal-footer-custom">
            <button class="btn-secondary-custom" onclick="closeDashMetaModal()">Cancel</button>
            <button class="btn-apply" onclick="saveDashboardMeta()">Apply Changes</button>
        </div>
    </div>
</div>

<!-- NATIVE MODULE DETAIL FALLBACK MODAL -->
<div class="modal-overlay" id="nativeModuleDetailModal" style="display:none;">
    <div class="modal-box iframe-modal-box" style="width: 950px; max-width: 95%; height: 85vh; padding:0; display:flex; flex-direction:column; overflow:hidden;">
        <div class="iframe-header" style="padding: 15px 20px; border-bottom: 1px solid #e0e4e8; display:flex; justify-content:space-between; align-items:center; background-color:#f8f9fa; flex-shrink:0;">
            <h5 class="iframe-title" id="nativeModuleDetailTitle" style="font-weight:600!important; margin:0; font-size:14px; color:#0b1a26;">Module Detail</h5>
            <span class="material-symbols-outlined" style="cursor:pointer; color:#7f8c8d; font-size:20px;" onclick="closeNativeModuleDetailModal()">close</span>
        </div>
        <div class="modal-body-scroll" style="flex-grow:1; padding:20px; overflow-y:auto; background:#f8fafc; display:flex; flex-direction:column; gap:20px;">
            <!-- Range Selector Control -->
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

            <!-- Chart Container -->
            <div id="nativeModuleChartContainer" style="background:#ffffff; border-radius:8px; border:1px solid #e2e8f0; padding:15px; min-height:260px; position:relative;">
                <h6 style="margin:0 0 10px 0; font-weight:600; color:#1e293b; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Historical Trend</h6>
                <div style="height:200px; width:100%; position:relative;">
                    <div id="nativeModuleDetailChart" style="width:100%; height:100%; min-height:200px;"></div>
                </div>
            </div>
            
            <!-- Table Container -->
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

<script>
const PANDORA_URL = "<?= h($PANDORA_BASE_URL) ?>";
const IS_STANDALONE = <?= $isStandalone ? 'true' : 'false' ?>;
const DIRECT_SCRIPT_URL = '<?= $directScriptUrl ?>';
const PRIMARY_UUID = '<?= get_node_uuid('primary') ?>';

let nativeModuleChartInstance = null;
let currentDetailModuleId = null;
let currentDetailModuleTitle = '';
let currentDetailViewType = '';

function show_module_detail_dialog(module_id, id_agent, filter, interval, offset, title) {
    if (window.parent && window.parent !== window && typeof window.parent.show_module_detail_dialog === 'function') {
        window.parent.show_module_detail_dialog(module_id, id_agent, filter, interval, offset, title);
        return;
    }
    // Open our lightweight custom history modal (which displays both ECharts and Data Log table)
    openNativeModuleDetailModal(module_id, title || 'Module Detail', offset || 86400, null, null, filter);
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
        openNativeModuleDetailModal(currentDetailModuleId, currentDetailModuleTitle, val, null, null, currentDetailViewType);
    }
}

function applyNativeModuleCustomRange() {
    const startVal = document.getElementById('nativeModuleCustomStart').value;
    const endVal = document.getElementById('nativeModuleCustomEnd').value;
    if (!startVal || !endVal) return alert('Please select start and end dates.');
    
    const startTs = Math.floor(new Date(startVal).getTime() / 1000);
    const endTs = Math.floor(new Date(endVal).getTime() / 1000);
    
    if (startTs >= endTs) return alert('Start date must be before end date.');
    
    openNativeModuleDetailModal(currentDetailModuleId, currentDetailModuleTitle, 'custom', startTs, endTs, currentDetailViewType);
}

async function openNativeModuleDetailModal(moduleId, title, rangeSeconds = 86400, customStart = null, customEnd = null, viewType = '') {
    currentDetailModuleId = moduleId;
    currentDetailModuleTitle = title;
    currentDetailViewType = viewType;
    
    const chartContainer = document.getElementById('nativeModuleChartContainer');
    if (chartContainer) {
        chartContainer.style.display = (viewType === 'data') ? 'none' : 'block';
    }
    
    document.getElementById('nativeModuleDetailTitle').innerText = 'Module: ' + title;
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
        let url = `?api=detail_graph&id_mod=${moduleId}&range=${rangeSeconds}`;
        if (rangeSeconds === 'custom') {
            url += `&start=${customStart}&end=${customEnd}`;
        }
        
        const res = await fetch(url).then(r => r.json());
        if (!res.ok) {
            tableBody.innerHTML = `<tr><td colspan="2" style="text-align:center; padding:30px; color:#e74c3c;">Error: ${res.error || 'Failed to load data'}</td></tr>`;
            return;
        }
        
        const data = res.data || [];
        document.getElementById('nativeModuleDetailCount').innerText = `${data.length} rows`;
        
        if (data.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="2" style="text-align:center; padding:30px; color:#64748b;">No history data found in the selected range.</td></tr>';
            return;
        }
        
        const unit = res.unit ? ' ' + res.unit : '';
        
        // Render Table Rows
        let html = '';
        data.forEach(row => {
            let formattedDate = row.waktu;
            if (row.waktu && row.waktu.includes('-')) {
                const parts = row.waktu.split(' ');
                const ymd = parts[0].split('-');
                formattedDate = `${ymd[2]}/${ymd[1]}/${ymd[0]} ${parts[1]}`;
            }
            const valNum = parseFloat(row.datos);
            const displayVal = (valNum % 1 === 0) ? valNum : valNum.toFixed(2);
            html += `<tr>
                <td style="font-weight: normal; color: #475569;">${formattedDate}</td>
                <td style="font-weight: 600; color: #0f172a;">${displayVal}${unit}</td>
            </tr>`;
        });
        tableBody.innerHTML = html;
        
        if (viewType !== 'data') {
            // Render Chart.js line graph
            const labels = data.map(row => {
                if (row.waktu && row.waktu.includes('-')) {
                    const parts = row.waktu.split(' ');
                    const ymd = parts[0].split('-');
                    return `${ymd[2]}/${ymd[1]} ${parts[1]}`;
                }
                return row.waktu;
            });
            const dataset = data.map(row => parseFloat(row.datos));
            
            const chartDom = document.getElementById('nativeModuleDetailChart');
            if (chartDom) {
                nativeModuleChartInstance = echarts.init(chartDom);
                nativeModuleChartInstance.setOption({
                    tooltip: { 
                        trigger: 'axis', 
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
                    yAxis: { type: 'value', splitLine: { lineStyle: { color: '#f1f5f9' } }, axisLabel: { fontSize: 10, color: '#64748b' } },
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

const iconChart  = `<span class="material-symbols-outlined" style="font-size:16px!important; color:#1976d2;">monitoring</span>`;
const iconEdit = `<span class="material-symbols-outlined">edit</span>`;
const iconDelete = `<span class="material-symbols-outlined" style="color:#e74c3c;">delete</span>`;
let masterDashboards = [];
let currentDashId = null;
let dashboardCards = [], cardTimers = {}, globalTimerRef = null;
let fullAgentsList = [], selectedIds = [];
let globalModuleList = [];

// JS Storage variables
let cardDataStore = {};
let cardPages = {}; 
let cardSearch = {};
const activeCharts = {};

// Modal Drill-down variables
let modalBaseData = [];
let modalFilteredData = [];
let modalCurrentPage = 1;
const MODAL_PAGE_SIZE = 25; 

let searchDebounceTimer = null;
const customCanvasBackgroundColor = {
    id: 'customCanvasBackgroundColor',
    beforeDraw: (chart, args, options) => {
        const {ctx} = chart;
        ctx.save();
        ctx.globalCompositeOperation = 'destination-over';
        ctx.fillStyle = options.color || '#ffffff';
        ctx.fillRect(0, 0, chart.width, chart.height);
        ctx.restore();
    }
};

const getStatusObj = (estado) => {
    switch(parseInt(estado)) {
        case 0: return { label: 'UP', color: 'bg-green' };
        case 1: return { label: 'CRITICAL', color: 'bg-red' };
        case 2: return { label: 'WARNING', color: 'bg-yellow' };
        case 4: return { label: 'NOT INIT', color: 'bg-blue' };
        default: return { label: 'UNKNOWN', color: 'bg-gray' };
    }
};

const formatValue = (val, unit, useRaw) => {
    if (useRaw || isNaN(parseFloat(val))) return val;
    const v = parseFloat(val);
    const u = (unit || '').toUpperCase().trim();
    
    // Format Bytes
    if (u === 'B' || u === 'BYTES') {
        if (v >= 1125899906842624) return (v / 1125899906842624).toFixed(2) + ' PB';
        if (v >= 1099511627776) return (v / 1099511627776).toFixed(2) + ' TB';
        if (v >= 1073741824) return (v / 1073741824).toFixed(2) + ' GB';
        if (v >= 1048576) return (v / 1048576).toFixed(2) + ' MB';
        if (v >= 1024) return (v / 1024).toFixed(2) + ' KB';
        return v + ' B';
    }
    
    // General large numbers
    if (v >= 1000000) return (v / 1000000).toFixed(2) + ' M';
    if (v >= 1000) return (v / 1000).toFixed(2) + ' K';
    
    // Round to 2 decimal places if it's a float
    return (v % 1 === 0) ? v : v.toFixed(2);
};

const workerScript = `let t = null; self.onmessage = function(e) { if (e.data === 'start') { if (t) clearInterval(t); t = setInterval(() => self.postMessage('tick'), 1000); } else if (e.data === 'stop') { clearInterval(t); } };`;
const blob = new Blob([workerScript], { type: 'application/javascript' });
const worker = new Worker(URL.createObjectURL(blob));

function saveConfigToServer(callback, quiet = false) {
    return fetch('?api=save_config', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '<?= $csrf_token ?>'
        },
        body: JSON.stringify(masterDashboards)
    })
    .then(r => r.json())
    .then(res => {
        if (!res.ok) {
            if (!quiet) alert("Gagal menyimpan ke server: " + (res.error?.message || res.error || 'Permission Denied'));
            return false;
        } else {
            if (callback) callback();
            return true;
        }
    })
    .catch((e) => {
        if (!quiet) alert("Gagal berkomunikasi dengan server.");
        return false;
    });
}

function updateURLState(dashId = null) {
    const u = new URL(window.location.href);
    u.searchParams.delete('standalone');
    u.searchParams.delete('dash_id');
    u.searchParams.delete('v');
    if (dashId) {
        u.searchParams.set('d', dashId);
    } else {
        u.searchParams.delete('d');
    }
    window.history.replaceState({}, '', u.toString());

    if (window.parent && window.parent !== window) {
        try {
            const pu = new URL(window.parent.location.href);
            if (dashId) {
                pu.searchParams.set('d', dashId);
            } else {
                pu.searchParams.delete('d');
            }
            window.parent.history.replaceState({}, '', pu.toString());
        } catch (e) {
            console.warn("Failed to update parent URL state:", e);
        }
    }
}

async function init() {
    let loadedData = [];
    try {
        const res = await fetch('?api=load_config&_t=' + Date.now());
        const data = await res.json();
        if (Array.isArray(data)) loadedData = data;
    } catch (e) {}

    // Check if flat array (widgets without a dashboard wrap)
    if (Array.isArray(loadedData)) {
        if (loadedData.length > 0 && !loadedData[0].hasOwnProperty('panels')) {
            masterDashboards = [{
                id: 'dash_default',
                title: 'Default Metrics Dashboard',
                panels: loadedData
            }];
            saveConfigToServer(null, true);
        } else {
            masterDashboards = loadedData;
        }
    }

    if (IS_STANDALONE) {
        const p = new URLSearchParams(window.location.search);
        const targetDashId = p.get('d') || p.get('dash_id') || p.get('dashId');
        const targetCardId = p.get('card_id') || p.get('cardId');
        
        let targetCard = null;
        let foundDashId = null;
        
        // 1. If targetCardId is provided, look for that specific panel globally across all dashboards
        if (targetCardId) {
            for (let d of masterDashboards) {
                const c = (d.panels || []).find(x => String(x.id).trim() === String(targetCardId).trim());
                if (c) {
                    targetCard = c;
                    foundDashId = d.id;
                    break;
                }
            }
        }
        
        // 2. If targetCard was found, isolate and load only this card!
        if (targetCard) {
            currentDashId = foundDashId;
            dashboardCards = [targetCard];
            document.title = targetCard.title + ' - Standalone View';
            const pageTitle = document.getElementById('pageMainTitle');
            if(pageTitle) pageTitle.innerText = targetCard.title;
        } else {
            // 3. If targetCardId was not found/provided, check targetDashId
            let targetDash = masterDashboards.find(x => String(x.id).trim() === String(targetDashId).trim());
            if (targetDash) {
                currentDashId = targetDash.id;
                if (targetCardId) {
                    // A specific card was requested but could not be resolved! Do not leak all dashboard panels!
                    dashboardCards = [{
                        id: 'error_card',
                        title: 'Widget Not Found',
                        view_type: 'table',
                        group_id: '0',
                        keyword: 'WIDGET_NOT_FOUND_PLACEHOLDER',
                        limit: 15,
                        refresh_sec: 60
                    }];
                    document.title = 'Widget Not Found';
                    const pageTitle = document.getElementById('pageMainTitle');
                    if(pageTitle) pageTitle.innerText = 'Widget Not Found';
                } else {
                    // No card was specified, load entire dashboard
                    dashboardCards = targetDash.panels || [];
                }
            } else {
                // 4. Custom standalone parameters
                dashboardCards = [{
                    id: 'std', title: p.get('title')||'Metrics Status', group_id: p.get('group_id')||'0',
                    keyword: p.get('keyword')||'%', limit: p.get('limit')||15, refresh_sec: p.get('refresh')||60,
                    view_type: p.get('view_type')||'table', manual_ids: p.get('manual_ids')||''
                }];
                currentDashId = 'std';
                if(p.get('title')) {
                    document.title = p.get('title') + ' - Standalone View';
                    const pageTitle = document.getElementById('pageMainTitle');
                    if(pageTitle) pageTitle.innerText = p.get('title');
                }
            }
        }

        // Ensure DOM visibility states and rendering are initialized in Standalone mode
        const viewList = document.getElementById('view_list');
        const listTopCtrls = document.getElementById('listTopControls');
        const viewDetail = document.getElementById('view_detail');
        const detailTopCtrls = document.getElementById('detailTopControls');
        
        if (viewList) viewList.classList.add('d-none');
        if (listTopCtrls) listTopCtrls.classList.add('d-none');
        if (viewDetail) viewDetail.classList.remove('d-none');
        if (detailTopCtrls) detailTopCtrls.classList.add('d-none');
        
        renderGrid();
        dashboardCards.forEach(c => fetchCardData(c));
    } else {
        loadGroups();
        fullAgentsList = [];
        fetch('?api=module_list').then(r=>r.json()).then(data => { globalModuleList = data; });
        
        const p = new URLSearchParams(window.location.search);
        const urlDashId = p.get('d') || p.get('dash_id');
        if (urlDashId && masterDashboards.some(x => x.id === urlDashId)) {
            openDashboard(urlDashId);
        } else {
            renderDashboardList();
        }
    }

    worker.postMessage('start');
    worker.onmessage = (e) => { if(e.data === 'tick') runTimerLogic(); };
    document.addEventListener("visibilitychange", () => { if (!document.hidden && currentDashId) { dashboardCards.forEach(c => fetchCardData(c)); } });
    
    // Load DB nodes status (non-blocking)
    loadDbNodesStatus();
}

async function loadDbNodesStatus() {
    try {
        const res = await fetch('?api=db_nodes_status&_t=' + Date.now());
        const data = await res.json();
        if (!data.ok || !data.nodes) return;
        
        const banner = document.getElementById('dbNodesBanner');
        const chips = document.getElementById('dbNodesChips');
        if (!banner || !chips) return;
        
        let hasCustomNodes = false;
        let hasIssues = false;
        let html = '';
        
        data.nodes.forEach(node => {
            if (node.id === 'history' && node.status === 'not_configured') return; // Skip unconfigured history
            if (node.id !== 'primary' && node.id !== 'history') hasCustomNodes = true;
            if (node.status === 'failed') hasIssues = true;
            
            let label = node.name;
            let extra = '';
            
            if (node.status === 'connected' && node.agents_count !== undefined && node.agents_count > 0) {
                extra = ` (${node.agents_count} agents)`;
            } else if (node.status === 'connected' && node.has_tagente === false) {
                extra = ' (No tagente table!)';
                hasIssues = true;
            } else if (node.status === 'failed') {
                extra = ' ✗';
            }
            
            html += `<span class="db-node-chip ${node.status}" title="${node.status === 'failed' ? 'Connection failed - check credentials in Settings' : (node.has_tagente === false ? 'Database does not contain Pandora FMS agent tables (tagente). This must be a full Pandora DB, not just a history DB.' : 'Connected')}">
                <span class="node-dot"></span>${label}${extra}
            </span>`;
        });
        
        // Only show banner if there are custom nodes or issues
        if (hasCustomNodes || hasIssues) {
            chips.innerHTML = html;
            banner.classList.add('visible');
        }
    } catch (e) {
        console.warn('Failed to load DB nodes status:', e);
    }
}

function toggleDbNodesBanner() {
    const banner = document.getElementById('dbNodesBanner');
    const toggle = document.getElementById('dbNodesToggle');
    if (!banner) return;
    
    if (banner.classList.contains('visible')) {
        banner.classList.remove('visible');
        if (toggle) toggle.querySelector('.material-symbols-outlined').innerText = 'expand_more';
    } else {
        banner.classList.add('visible');
        if (toggle) toggle.querySelector('.material-symbols-outlined').innerText = 'expand_less';
    }
}

function renderDashboardList() {
    updateURLState(null);
    currentDashId = null;
    dashboardCards = [];
    
    // Stop all active timers
    for (let timerId in cardTimers) { delete cardTimers[timerId]; }

    document.getElementById('view_list').classList.remove('d-none');
    document.getElementById('listTopControls').classList.remove('d-none');
    document.getElementById('view_detail').classList.add('d-none');
    document.getElementById('detailTopControls').classList.add('d-none');
    
    const pageTitle = document.getElementById('pageMainTitle');
    if(pageTitle) pageTitle.innerText = "Metrics Dashboard";

    const mainBreadcrumb = document.getElementById('mainBreadcrumb');
    if (mainBreadcrumb) mainBreadcrumb.innerText = "PANDORA CONSOLE / CUSTOM / PANEL / DASHBOARD";

    const tbody = document.querySelector('#dashListTable tbody');
    if(!tbody) return;
    
    const kw = document.getElementById('listSearch').value.toLowerCase().trim();
    tbody.innerHTML = '';
    
    const filtered = masterDashboards.filter(d => d.title.toLowerCase().includes(kw));
    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="3" style="text-align:center; padding:30px; color:#7f8c8d;">No Metrics Dashboards found. Click "Create Dashboard" to add one.</td></tr>`;
        return;
    }
    
    filtered.forEach(d => {
        const tr = document.createElement('tr');
        const widgetCount = (d.panels || []).length;
        tr.innerHTML = `
            <td>
                <a class="dash-name-link" onclick="openDashboard('${d.id}')">
                    <span class="material-symbols-outlined">dashboard</span> ${d.title}
                </a>
            </td>
            <td><span class="dash-badge">${widgetCount} Widgets</span></td>
            <td style="text-align:right;">
                <button class="btn-action" onclick="openDashboard('${d.id}')" title="Open Dashboard">
                    <span class="material-symbols-outlined">visibility</span>
                </button>
                <button class="btn-action" onclick="editDashboardSettingsFromList('${d.id}')" title="Rename Settings">
                    <span class="material-symbols-outlined">settings</span>
                </button>
                <button class="btn-action" onclick="exportDashboardConfig('${d.id}')" title="Backup Dashboard Config">
                    <span class="material-symbols-outlined">download</span>
                </button>
                <button class="btn-action" onclick="triggerImport('${d.id}')" title="Load Dashboard Config">
                    <span class="material-symbols-outlined">upload</span>
                </button>
                <button class="btn-action" onclick="duplicateDashboardFromList('${d.id}')" title="Duplicate Dashboard">
                    <span class="material-symbols-outlined">content_copy</span>
                </button>
                <button class="btn-action btn-delete" onclick="deleteDashboard('${d.id}')" title="Delete Dashboard">
                    <span class="material-symbols-outlined">delete</span>
                </button>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

function openDashboard(id) {
    const d = masterDashboards.find(x => x.id === id);
    if (!d) return;
    currentDashId = id;
    dashboardCards = d.panels || [];
    
    updateURLState(id);
    
    document.getElementById('view_list').classList.add('d-none');
    document.getElementById('listTopControls').classList.add('d-none');
    document.getElementById('view_detail').classList.remove('d-none');
    document.getElementById('detailTopControls').classList.remove('d-none');
    
    const pageTitle = document.getElementById('pageMainTitle');
    if(pageTitle) pageTitle.innerText = d.title;

    const mainBreadcrumb = document.getElementById('mainBreadcrumb');
    if (mainBreadcrumb) mainBreadcrumb.innerText = "PANDORA CONSOLE / CUSTOM / PANEL / DASHBOARD / " + d.title.toUpperCase();
    
    renderGrid();
    
    // Initialize timers and fetch data
    dashboardCards.forEach(c => {
        cardTimers[c.id] = parseInt(c.refresh_sec);
        fetchCardData(c);
    });
}

function closeDashboard() {
    renderDashboardList();
}

let editingDashId = null;
function openDashMetaModal(dashId = null) {
    editingDashId = dashId;
    if (dashId) {
        const d = masterDashboards.find(x => x.id === dashId);
        document.getElementById('dashMetaTitle').innerText = 'Rename Dashboard';
        document.getElementById('m_dash_title').value = d.title;
    } else {
        document.getElementById('dashMetaTitle').innerText = 'Create New Dashboard';
        document.getElementById('m_dash_title').value = '';
    }
    document.getElementById('dashMetaModal').style.display = 'flex';
}
function closeDashMetaModal() {
    document.getElementById('dashMetaModal').style.display = 'none';
}
function editDashboardSettingsFromList(id) {
    openDashMetaModal(id);
}

function saveDashboardMeta() {
    const title = document.getElementById('m_dash_title').value.trim() || 'New Metrics Dashboard';
    
    if (editingDashId) {
        // Edit Mode
        masterDashboards = masterDashboards.map(d => {
            if (d.id === editingDashId) { d.title = title; }
            return d;
        });
        saveConfigToServer(() => {
            closeDashMetaModal();
            renderDashboardList();
        });
    } else {
        // Create Mode
        const newDash = {
            id: 'dash_' + Date.now(),
            title: title,
            panels: []
        };
        masterDashboards.push(newDash);
        saveConfigToServer(() => {
            closeDashMetaModal();
            renderDashboardList();
            openDashboard(newDash.id);
        });
    }
}

function deleteDashboard(id) {
    const d = masterDashboards.find(x => x.id === id);
    if (!d) return;
    if (confirm(`Apakah Anda yakin ingin menghapus dashboard "${d.title}"? Semua widget di dalamnya akan terhapus permanent.`)) {
        masterDashboards = masterDashboards.filter(x => x.id !== id);
        saveConfigToServer(() => {
            renderDashboardList();
        });
    }
}

function duplicateDashboardFromList(id) {
    const d = masterDashboards.find(x => x.id === id);
    if (!d) return;
    const newDash = JSON.parse(JSON.stringify(d));
    newDash.id = 'dash_' + Date.now();
    newDash.title = d.title + ' (Copy)';
    masterDashboards.push(newDash);
    saveConfigToServer(() => {
        alert('Dashboard berhasil diduplikasi!');
        renderDashboardList();
    });
}

function runTimerLogic() {
    if(!currentDashId) return;
    if(document.getElementById('chartModal') && document.getElementById('chartModal').style.display === 'flex') return;
    if(document.getElementById('historyModal') && document.getElementById('historyModal').style.display === 'flex') return;
    if(document.getElementById('detailModal') && document.getElementById('detailModal').style.display === 'flex') return;

    dashboardCards.forEach(c => {
        if (cardTimers[c.id] === undefined) cardTimers[c.id] = parseInt(c.refresh_sec);
        cardTimers[c.id]--;
        let m = document.getElementById(`meta_timer_${c.id}`); if(m) m.innerText = `(Refresh in ${cardTimers[c.id]}s)`;
        if(cardTimers[c.id] <= 0) { fetchCardData(c); cardTimers[c.id] = parseInt(c.refresh_sec); }
    });
}

function loadGroups() { fetch('?api=groups').then(r=>r.json()).then(data => { const sel = document.getElementById('b_group'); data.forEach(g => sel.add(new Option(g.name, g.id))); }); }
function toggleManualSelector() { document.getElementById('manual_selector_box').style.display = (document.getElementById('b_group').value === '0') ? 'block' : 'none'; }
async function loadAgentsForBuilder(query) {
    const list = document.getElementById('agent_checkbox_list');
    list.innerHTML = '<div style="padding:15px; text-align:center; color:#7f8c8d;">Searching agents...</div>';
    try {
        const res = await fetch('?api=search_agents&q=' + encodeURIComponent(query) + '&selected_ids=' + encodeURIComponent(selectedIds.join(',')));
        fullAgentsList = await res.json();
        renderAgentDropdown();
    } catch(e) {
        list.innerHTML = '<div style="padding:15px; text-align:center; color:#e74c3c;">Failed to load agents.</div>';
    }
}
function renderAgentDropdown() {
    const list = document.getElementById('agent_checkbox_list');
    list.innerHTML = fullAgentsList.map(a => {
        const isChecked = selectedIds.includes(String(a.id));
        return `<div class="agent-item" data-name="${a.alias.toLowerCase()}">
            <input type="checkbox" id="chk_${a.id}" value="${a.id}" onchange="handleAgentCheck(this)" ${isChecked ? 'checked' : ''}>
            <label for="chk_${a.id}">${a.alias}</label>
        </div>`;
    }).join('');
}
let filterTimeout = null;
function filterAgentsInList() {
    if (filterTimeout) clearTimeout(filterTimeout);
    filterTimeout = setTimeout(async () => {
        const kw = document.getElementById('inner_search').value;
        await loadAgentsForBuilder(kw);
    }, 300);
}
function handleAgentCheck(chk) {
    const val = String(chk.value);
    if (chk.checked) {
        if (!selectedIds.includes(val)) selectedIds.push(val);
    } else {
        selectedIds = selectedIds.filter(id => id !== val);
    }
    document.getElementById('sel_count').innerText = selectedIds.length + " Selected";
    refreshBuilderModuleList();
}
function toggleBuilderAgentAll() {
    const chks = document.querySelectorAll('#agent_checkbox_list input[type="checkbox"]');
    let visibleChks = Array.from(chks);
    if (visibleChks.length === 0) return;
    const allChecked = visibleChks.every(c => c.checked);
    visibleChks.forEach(c => {
        c.checked = !allChecked;
        handleAgentCheck(c);
    });
}

function toggleSearchInput(cardId) { 
    const input = document.getElementById(`search_inp_${cardId}`); 
    input.classList.toggle('active'); 
    if(input.classList.contains('active')) input.focus(); 
}
function filterTableRows(cardId) {
    cardSearch[cardId] = document.getElementById(`search_inp_${cardId}`).value.toLowerCase().trim();
    cardPages[cardId] = 1; 
    renderTablePage(cardId);
}

function renderGrid() {
    const grid = document.getElementById('dashboardGrid'); grid.innerHTML = '';
    if (dashboardCards.length === 1) grid.classList.add('single-item');
    else grid.classList.remove('single-item');

    dashboardCards.forEach(c => {
        const div = document.createElement('div'); div.className = 'dashboard-card'; div.id = 'box_' + c.id;
        
        if (!IS_STANDALONE) {
            div.draggable = true;
            div.ondragstart = (e) => handleDragStart(e, c.id);
            div.ondragover = (e) => handleDragOver(e);
            div.ondragleave = (e) => handleDragLeave(e);
            div.ondrop = (e) => handleDrop(e, c.id);
            div.ondragend = (e) => handleDragEnd(e);
        }

        let searchBtn = ['cards', 'pie', 'donut', 'line', 'area', 'bar'].includes(c.view_type) ? '' : `
            <input type="text" id="search_inp_${c.id}" class="search-input-header" placeholder="Filter..." onkeyup="filterTableRows('${c.id}')">
            <button class="icon-btn-card" onclick="toggleSearchInput('${c.id}')" title="Search"><span class="material-symbols-outlined">search</span></button>
        `;

        let timeRangeBtn = ['line', 'area', 'bar'].includes(c.view_type) ? `
            <button class="icon-btn-card" onclick="openTimeRangeMenu(event, '${c.id}')" title="Time Range"><span class="material-symbols-outlined">schedule</span></button>
        ` : '';

        let acts = `
            <div class="card-actions">
                ${searchBtn}
                ${timeRangeBtn}
                <button class="icon-btn-card" onclick="openExport('${c.id}')" title="Export Data"><span class="material-symbols-outlined">ios_share</span></button>
                ${!IS_STANDALONE ? `
                <button class="icon-btn-card" onclick='copyStandaloneUrl(${JSON.stringify(c)})' title="Share Widget"><span class="material-symbols-outlined">share</span></button>
                <button class="icon-btn-card" onclick="duplicatePanel('${c.id}')" title="Duplicate"><span class="material-symbols-outlined">content_copy</span></button>
                <button class="icon-btn-card" onclick="openEdit('${c.id}')" title="Edit">${iconEdit}</button>
                <button class="icon-btn-card" onclick="deleteCard('${c.id}')" title="Delete">${iconDelete}</button>
                ` : ''}
            </div>`;

        const showStatsStyle = (c.show_stats === 0 || ['line', 'area', 'bar', 'history_table', 'single_value', 'table_viewer'].includes(c.view_type)) ? 'display: none !important;' : '';
        div.innerHTML = `<div class="dashboard-card-header"><div><h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="color:#004d40;">analytics</span> ${c.title}</h5><div style="font-size:10px; color:#7f8c8d; font-weight: normal;"><span id="meta_up_${c.id}">Awaiting...</span> <span id="meta_timer_${c.id}"></span></div></div>${acts}</div>
        <div class="dashboard-card-body">
            <div class="mini-stats-row" style="${showStatsStyle}">
                <div class="mini-stat st-border-black" onclick="showDetailModal('${c.id}', 'all')"><div class="mini-stat-val text-black" id="st_tot_${c.id}">0</div><div class="mini-stat-label">TOTAL</div></div>
                <div class="mini-stat st-border-green" onclick="showDetailModal('${c.id}', 'normal')"><div class="mini-stat-val text-green" id="st_normal_${c.id}">0</div><div class="mini-stat-label">UP</div></div>
                <div class="mini-stat st-border-yellow" onclick="showDetailModal('${c.id}', 'warning')"><div class="mini-stat-val text-yellow" id="st_warning_${c.id}">0</div><div class="mini-stat-label">WARNING</div></div>
                <div class="mini-stat st-border-red" onclick="showDetailModal('${c.id}', 'critical')"><div class="mini-stat-val text-red" id="st_critical_${c.id}">0</div><div class="mini-stat-label">CRITICAL</div></div>
                <div class="mini-stat st-border-gray" onclick="showDetailModal('${c.id}', 'unknown')"><div class="mini-stat-val text-gray" id="st_unknown_${c.id}">0</div><div class="mini-stat-label">UNKNOWN</div></div>
                <div class="mini-stat st-border-blue" onclick="showDetailModal('${c.id}', 'not_init')"><div class="mini-stat-val text-blue" id="st_not_init_${c.id}">0</div><div class="mini-stat-label">NOT INIT</div></div>
            </div>
            <div id="content_view_${c.id}"></div>
        </div>`;
        grid.appendChild(div);
    });
}

function fetchCardData(card) {
    const matchType = card.match_type || 'contains';
    const isChart = ['line', 'area', 'bar', 'history_table', 'single_value'].includes(card.view_type);
    const range = card.time_range || 86400;
    let url = `?api=card_data&group_id=${card.group_id}&keyword=${encodeURIComponent(card.keyword)}&limit=${card.limit}&manual_ids=${card.manual_ids || ''}&match_type=${matchType}&chart_limit=${card.chart_limit || 0}&view_type=${card.view_type || ''}`;
    if (isChart) {
        if (range === 'custom' && card.custom_start_ts && card.custom_end_ts) {
            url += `&history=1&time_range=custom&start_time=${card.custom_start_ts}&end_time=${card.custom_end_ts}`;
        } else {
            url += `&history=1&time_range=${range}`;
        }
    }

    fetch(url).then(r=>r.json()).then(res => {
        if (!res.ok) {
            document.getElementById(`content_view_${card.id}`).innerHTML = `<div style="padding:20px; color:red; text-align:center;">Load Error: ${res.error}</div>`;
            return;
        }
        document.getElementById(`meta_up_${card.id}`).innerText = `Last update: ${res.updated}`;
        
        cardDataStore[card.id] = res.table;
        if (!cardPages[card.id]) cardPages[card.id] = 1;
        if (!cardSearch[card.id]) cardSearch[card.id] = '';

        const map = {'total':'tot', 'normal':'normal', 'warning':'warning', 'critical':'critical', 'unknown':'unknown', 'not_init':'not_init'};
        Object.keys(map).forEach(k => { if(document.getElementById(`st_${map[k]}_${card.id}`)) document.getElementById(`st_${map[k]}_${card.id}`).innerText = res.stats[k] || 0; });

        const container = document.getElementById(`content_view_${card.id}`);
        if (card.view_type === 'cards') {
            container.style.display = 'none';
        } else if (['pie', 'donut', 'line', 'area', 'bar', 'single_value'].includes(card.view_type)) {
            container.style.display = 'block';
            renderWidgetChart(card.id, card.view_type, res.table || [], parseInt(card.chart_limit) || 0, res.stats || {}, res.history || []);
        } else if (card.view_type === 'history_table') {
            container.style.display = 'block';
            renderHistoryTableWidget(card.id, res.table || [], res.history || []);
        } else {
            container.style.display = 'block';
            renderTablePage(card.id);
        }
    }).catch(err => {
        console.error("Widget fetch error:", err);
        document.getElementById(`content_view_${card.id}`).innerHTML = `<div style="padding:20px; color:#e74c3c; text-align:center; font-weight: normal;">Koneksi ke database lambat atau terputus. Silakan perkecil scope filter Agent.</div>`;
    });
}

function renderTablePage(cardId) {
    let data = cardDataStore[cardId] || [];
    const card = dashboardCards.find(c => c.id === cardId);
    if(!card) return;

    const kw = cardSearch[cardId];
    if (kw) {
        data = data.filter(r => 
            r.agent_alias.toLowerCase().includes(kw) || 
            r.module_name.toLowerCase().includes(kw) || 
            r.ip_address.toLowerCase().includes(kw) || 
            r.group_name.toLowerCase().includes(kw)
        );
    }

    const container = document.getElementById(`content_view_${cardId}`);
    
    if (data.length === 0) {
        container.innerHTML = '<div style="text-align:center; padding: 30px; color:#7f8c8d; font-weight: normal; border:1px solid #e0e4e8; border-radius:6px;">No data found.</div>';
        return;
    }

    const limit = parseInt(card.limit) || 0;
    const pageSize = (limit === 0) ? 20 : limit; 
    const totalPages = Math.ceil(data.length / pageSize) || 1;
    
    let currentPage = cardPages[cardId];
    if(currentPage > totalPages) currentPage = totalPages;
    cardPages[cardId] = currentPage;

    const startIdx = (currentPage - 1) * pageSize;
    const endIdx = Math.min(startIdx + pageSize, data.length);
    const pageData = data.slice(startIdx, endIdx);

    let h = '';
    
    if (card.view_type === 'table_viewer') {
        const firstMod = pageData[0] || {};
        const uniqueId = `metrics_viewer_${cardId}`;
        const chartH = 200;
        
        h += `
            <div class="table-viewer-card-wrap" style="height:100%; display:flex; flex-direction:column; gap:10px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:4px;">
                    <div style="font-size:10px; color:#7f8c8d;">Last Updated: ${firstMod.last_contact ? new Date(firstMod.last_contact * 1000).toLocaleString() : '-'}</div>
                    <div class="search-box" style="width: 180px; position:relative;">
                        <input type="text" placeholder="Filter table..." class="form-control-fix" style="font-size:11px; padding: 4px 8px 4px 26px; height:24px; border-radius:4px; border: 1px solid #cbd5e1;" oninput="filterCardTableViewer('${uniqueId}', this.value)">
                        <span class="material-symbols-outlined" style="position:absolute; left:6px; top:50%; transform:translateY(-50%); font-size:14px; color:#94a3b8; line-height:1;">search</span>
                    </div>
                </div>
                <div class="table-scroll-wrapper" style="overflow-x:auto; overflow-y:auto; flex-grow:1; max-height:${chartH}px; border: 1px solid #e2e8f0; border-radius:6px; background:#fff;">
                    <table class="table-pfms" id="table_${uniqueId}" style="margin:0; font-size:11px; width:100%;">
                        <thead id="thead_${uniqueId}"></thead>
                        <tbody id="tbody_${uniqueId}"></tbody>
                    </table>
                    <div id="raw_${uniqueId}" class="d-none" style="padding:10px; background:#1e293b; color:#e2e8f0; font-family:monospace; font-size:10px; white-space:pre-wrap;"></div>
                </div>
            </div>
        `;
        
        container.innerHTML = h;
        
        setTimeout(() => {
            const agentLabel = `${firstMod.agent_alias || ''}/${firstMod.agent_name || ''}`;
            renderSingleModuleTableViewer(uniqueId, firstMod.current_value || '', agentLabel);
        }, 10);
        return;
    }
    
    if (card.view_type === 'heatmap') {
        h += '<div class="heatmap-wrap" style="border: 1px solid #f0f3f5; border-radius: 6px;">';
        pageData.forEach(r => {
            const sObj = getStatusObj(r.estado);
            let unitStr = r.unit ? ` ${r.unit}` : '';
            const isPrimaryAgent = String(r.id_agente).startsWith(PRIMARY_UUID + ':');
            if (isPrimaryAgent) {
                const rawAgentId = String(r.id_agente).split(':')[1] || r.id_agente;
                h += `<a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${rawAgentId}" target="_blank" class="heat-box ${sObj.color}" title="Agent: ${r.agent_alias}\nModule: ${r.module_name}\nValue: ${r.current_value}${unitStr}">${r.module_name}</a>`;
            } else {
                h += `<span class="heat-box ${sObj.color}" title="Agent: ${r.agent_alias} (Custom Node)\nModule: ${r.module_name}\nValue: ${r.current_value}${unitStr}">${r.module_name}</span>`;
            }
        });
        h += '</div>';
    } 
    else {
        const tableFs = card.font_size || 14;
        const iconSz = card.icon_size || 18;
        const visibleCols = card.visible_columns || ['agent', 'group', 'ip', 'module', 'status', 'history', 'threshold'];
        
        let headerRow = '';
        if (visibleCols.includes('agent')) headerRow += '<th>Agent</th>';
        if (visibleCols.includes('group')) headerRow += '<th>Group</th>';
        if (visibleCols.includes('ip')) headerRow += '<th>IP Address</th>';
        if (visibleCols.includes('module')) headerRow += '<th>Sensor Module</th>';
        if (visibleCols.includes('status')) headerRow += '<th style="text-align:center;">Status</th>';
        if (visibleCols.includes('history')) headerRow += '<th style="text-align:center;">Metrics History</th>';
        if (visibleCols.includes('threshold')) headerRow += '<th>Threshold</th>';
        
        h += `<div class="table-wrap"><table class="table-pfms" style="font-size:${tableFs}px;"><thead><tr>${headerRow}</tr></thead><tbody>`;
        
        pageData.forEach(r => {
            const sObj = getStatusObj(r.estado);
            let unitStr = r.unit ? ` ${r.unit}` : '';

            let rowHtml = '<tr>';
            if (visibleCols.includes('agent')) {
                const isPrimaryAgent = String(r.id_agente).startsWith(PRIMARY_UUID + ':');
                let agentLinkHtml = '';
                if (isPrimaryAgent) {
                    const rawAgentId = String(r.id_agente).split(':')[1] || r.id_agente;
                    agentLinkHtml = `<a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${rawAgentId}" target="_blank" class="agent-link" style="font-size:${tableFs}px!important;">${r.agent_alias}</a>`;
                } else {
                    agentLinkHtml = `<span style="font-size:${tableFs}px!important; font-weight:500; color:#334155;">${r.agent_alias}</span>`;
                }
                rowHtml += `<td>
                    <div class="node-wrap"><div class="dot ${sObj.color}"></div>${agentLinkHtml}</div>
                </td>`;
            }
            if (visibleCols.includes('group')) {
                rowHtml += `<td style="color:#7f8c8d">${r.group_name}</td>`;
            }
            if (visibleCols.includes('ip')) {
                rowHtml += `<td><code class="ip-text">${r.ip_address||'-'}</code></td>`;
            }
            if (visibleCols.includes('module')) {
                rowHtml += `<td>
                    <div style="font-weight: normal; color:#0b1a26; margin-bottom:4px;">${r.module_name}</div>
                    <div style="font-size:10px!important; color:#7f8c8d;">Update: ${r.time_ago}</div>
                </td>`;
            }
            if (visibleCols.includes('status')) {
                const rawValStr = String(r.current_value || '');
                const cleanValStr = formatValue(r.current_value, r.unit, card.use_raw);
                if (cleanValStr.length > 45 || cleanValStr.includes('|') || cleanValStr.includes('\n')) {
                    rowHtml += `<td style="text-align:center;">
                        <button class="status-pill ${sObj.color}" style="color:#fff!important; border:none; padding: 6px 12px; font-size:${Math.round(tableFs*0.8)}px!important; cursor:pointer; font-weight:600; display:inline-block; border-radius:4px; transition: opacity 0.2s;" 
                            onclick="showLongValuePopup('${r.module_name.replace(/'/g, "\\'")}', '${r.agent_alias.replace(/'/g, "\\'")}', \`${rawValStr.replace(/`/g, "\\`").replace(/\$/g, "\\$")}\`)"
                            onmouseenter="this.style.opacity=0.8" onmouseleave="this.style.opacity=1">
                            View Value
                        </button>
                    </td>`;
                } else {
                    rowHtml += `<td style="text-align:center;">
                        <div class="status-pill ${sObj.color}" style="color:#fff!important; border:none; padding: 6px 12px; font-size:${Math.round(tableFs*0.8)}px!important;">
                            ${cleanValStr}${unitStr}
                        </div>
                    </td>`;
                }
            }
            if (visibleCols.includes('history')) {
                rowHtml += `<td style="text-align:center;">
                    <div style="display:inline-flex; gap:8px; align-items:center; justify-content:center; width:100%;">
                        <button class="icon-btn-card" style="padding:0; margin:0;" onclick="openNativeChart('${r.id_agente_modulo}', '${r.agent_alias.replace(/'/g, "\\'")} - ${r.module_name.replace(/'/g, "\\'")}', '${r.id_agente}')" title="View Chart">
                            <span class="material-symbols-outlined" style="font-size:${iconSz}px!important; color:#1976d2;">monitoring</span>
                        </button>
                        <button class="icon-btn-card" style="padding:0; margin:0;" onclick="show_module_detail_dialog('${r.id_agente_modulo}', '${r.id_agente}', 'data', 0, 86400, '${r.module_name.replace(/'/g, "\\'")}')" title="View Data Table">
                            <span class="material-symbols-outlined" style="font-size:${iconSz}px!important; color:#2e7d32;">table_chart</span>
                        </button>
                    </div>
                </td>`;
            }
            if (visibleCols.includes('threshold')) {
                rowHtml += `<td>
                    <div class="limit-text">Min: <strong style="color:#333;">${r.low_limit}${unitStr}</strong></div>
                    <div class="limit-text">Max: <strong style="color:#e74c3c;">${r.high_limit}${unitStr}</strong></div>
                </td>`;
            }
            rowHtml += '</tr>';
            h += rowHtml;
        });
        h += '</tbody></table></div>';
    }

    if (totalPages > 1) {
        h += `
            <div class="pagination-container">
                <div style="font-size:11px; font-weight: normal; color:#7f8c8d;">Showing ${startIdx + 1} to ${endIdx} of ${data.length} Entries</div>
                <div style="display:flex; gap:10px;">
                    <button class="pagination-btn" ${currentPage === 1 ? 'disabled' : ''} onclick="changePage('${cardId}', -1)">Prev</button>
                    <span style="font-size:12px; font-weight: normal; align-self:center;">Page ${currentPage} / ${totalPages}</span>
                    <button class="pagination-btn" ${currentPage === totalPages ? 'disabled' : ''} onclick="changePage('${cardId}', 1)">Next</button>
                </div>
            </div>
        `;
    }

    container.innerHTML = h;
}

function changePage(cardId, direction) {
    cardPages[cardId] += direction;
    renderTablePage(cardId);
}

function renderHistoryTableWidget(cardId, tableData, historyData) {
    const container = document.getElementById(`content_view_${cardId}`);
    if (!container) return;

    if (tableData.length === 0) {
        container.innerHTML = '<div style="text-align:center; padding:30px; color:#7f8c8d; font-weight: normal; border:1px solid #e0e4e8; border-radius:6px;">No data found.</div>';
        return;
    }

    // Cache the data for client-side pagination
    window.widgetHistoryStores = window.widgetHistoryStores || {};
    window.widgetHistoryStores[cardId] = { table: tableData, history: historyData };

    const moduleMap = {};
    tableData.forEach(m => {
        moduleMap[m.id_agente_modulo] = m;
    });

    let combinedHistory = [];
    historyData.forEach(h => {
        const m = moduleMap[h.id_mod];
        if (m) {
            combinedHistory.push({
                utimestamp: h.utimestamp,
                time: h.time,
                agent_alias: m.agent_alias,
                module_name: m.module_name,
                val: h.val,
                unit: m.unit
            });
        }
    });

    combinedHistory.sort((a, b) => b.utimestamp - a.utimestamp);

    const card = dashboardCards.find(c => c.id === cardId);
    if (!card) return;
    const chartH = Math.max(120, (parseInt(card.height) || 200) - 90);

    let tableHtml = '';
    if (combinedHistory.length === 0) {
        tableHtml = `<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:${chartH}px; color:#bdc3c7; font-size:11px; border:1px solid #e2e8f0; border-radius:6px;"><span class="material-symbols-outlined" style="font-size:24px; margin-bottom:5px;">history</span>No History Data</div>`;
        container.innerHTML = `<div style="display:flex; flex-direction:column; height:100%;">${tableHtml}</div>`;
        return;
    }

    const limit = parseInt(card.limit) || 20;
    const pageSize = (limit === 0) ? 20 : limit;
    const totalPages = Math.ceil(combinedHistory.length / pageSize) || 1;

    let currentPage = cardPages[cardId] || 1;
    if (currentPage > totalPages) currentPage = totalPages;
    cardPages[cardId] = currentPage;

    const startIdx = (currentPage - 1) * pageSize;
    const endIdx = Math.min(startIdx + pageSize, combinedHistory.length);
    const paginatedHistory = combinedHistory.slice(startIdx, endIdx);

    tableHtml = `
        <div class="table-scroll-wrapper" style="overflow-y:auto; max-height:${chartH}px; border: 1px solid #e2e8f0; border-radius:6px; background:#fff; width:100%;">
            <table class="table-pfms" style="margin:0; font-size:11px; width:100%;">
                <thead>
                    <tr style="position:sticky; top:0; background:#f8fafc; z-index:1; box-shadow: 0 1px 0 #e2e8f0;">
                        <th style="padding:8px 12px; text-align:left; font-weight:600; color:#475569;">Timestamp</th>
                        <th style="padding:8px 12px; text-align:left; font-weight:600; color:#475569;">Agent Name</th>
                        <th style="padding:8px 12px; text-align:left; font-weight:600; color:#475569;">Module Name</th>
                        <th style="padding:8px 12px; text-align:right; font-weight:600; color:#475569;">Value</th>
                    </tr>
                </thead>
                <tbody>
                    ${paginatedHistory.map(h => {
                        let valNum = parseFloat(h.val);
                        let displayVal = isNaN(valNum) ? h.val : ((valNum % 1 === 0) ? valNum : valNum.toFixed(2));
                        let unitStr = h.unit ? ` ${h.unit}` : '';
                        return `
                            <tr>
                                <td style="padding:8px 12px; color:#475569; border-bottom:1px solid #f1f5f9; white-space:nowrap;">${h.time}</td>
                                <td style="padding:8px 12px; color:#475569; border-bottom:1px solid #f1f5f9; font-weight:500;">${h.agent_alias}</td>
                                <td style="padding:8px 12px; color:#475569; border-bottom:1px solid #f1f5f9; font-weight:500;">${h.module_name}</td>
                                <td style="padding:8px 12px; text-align:right; font-weight:600; color:#1e293b; border-bottom:1px solid #f1f5f9; white-space:nowrap;">${displayVal}${unitStr}</td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        </div>
    `;

    let paginationHtml = '';
    if (totalPages > 1) {
        paginationHtml = `
            <div class="pagination-container" style="margin-top: 8px;">
                <div style="font-size:11px; font-weight: normal; color:#7f8c8d;">Showing ${startIdx + 1} to ${endIdx} of ${combinedHistory.length} Entries</div>
                <div style="display:flex; gap:10px;">
                    <button class="pagination-btn" ${currentPage === 1 ? 'disabled' : ''} onclick="changeHistoryWidgetPage('${cardId}', -1)">Prev</button>
                    <span style="font-size:12px; font-weight: normal; align-self:center;">Page ${currentPage} / ${totalPages}</span>
                    <button class="pagination-btn" ${currentPage === totalPages ? 'disabled' : ''} onclick="changeHistoryWidgetPage('${cardId}', 1)">Next</button>
                </div>
            </div>
        `;
    }

    container.innerHTML = `
        <div style="display:flex; flex-direction:column; height:100%;">
            ${tableHtml}
            ${paginationHtml}
        </div>
    `;
}

function changeHistoryWidgetPage(cardId, direction) {
    cardPages[cardId] = (cardPages[cardId] || 1) + direction;
    const store = window.widgetHistoryStores[cardId];
    if (store) {
        renderHistoryTableWidget(cardId, store.table, store.history);
    }
}

function toggleCustomChartRange() {
    const val = document.getElementById('chartRangeSelect').value;
    document.getElementById('chartCustomDateBox').style.display = (val === 'custom') ? 'flex' : 'none';
}



function openNativeChart(modId, title, idAgent = 0) {
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

function closeNativeChartModal() {
    document.getElementById('nativeChartModal').style.display = 'none';
    document.getElementById('nativeChartFrame').src = ''; 
}

function renderDetailModalTable(dataArray) {
    let h = '<div class="table-wrap"><table class="table-pfms"><thead><tr><th>Agent</th><th>Group</th><th>IP Address</th><th>Sensor Module</th><th>Value</th><th style="text-align:center;">Status</th><th style="text-align:center;">Actions</th></tr></thead><tbody>';

    if (dataArray.length === 0) {
        h += '<tr><td colspan="7" style="text-align:center; padding: 25px; color:#7f8c8d; font-weight: normal;">No matching data found.</td></tr>';
    } else {
        dataArray.forEach(r => {
            const sObj = getStatusObj(r.estado);
            let unitStr = r.unit ? ` ${r.unit}` : '';
            const isPrimaryAgent = String(r.id_agente).startsWith(PRIMARY_UUID + ':');
            let agentLinkHtml = '';
            if (isPrimaryAgent) {
                const rawAgentId = String(r.id_agente).split(':')[1] || r.id_agente;
                agentLinkHtml = `<a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${rawAgentId}" target="_blank" class="agent-link">${r.agent_alias}</a>`;
            } else {
                agentLinkHtml = `<span style="font-weight:500; color:#334155;">${r.agent_alias}</span>`;
            }

            h += `<tr>
                    <td><div class="node-wrap"><div class="dot ${sObj.color}"></div>${agentLinkHtml}</div></td>
                    <td style="color:#7f8c8d">${r.group_name}</td>
                    <td><code class="ip-text">${r.ip_address||'-'}</code></td>
                    <td style="color:#0b1a26; font-weight: normal;">${r.module_name}</td>
                    <td style="font-weight: normal;">${r.current_value}${unitStr}</td>
                    <td style="text-align:center;">
                        <div class="status-pill ${sObj.color}" style="color:#fff!important; border:none; padding:4px 8px;">
                            ${sObj.label}
                        </div>
                    </td>
                    <td style="text-align:center; white-space:nowrap;">
                        <div style="display:inline-flex; gap:8px; align-items:center; justify-content:center; width:100%;">
                            <button class="icon-btn-card" style="padding:0; margin:0; background:none; border:none; cursor:pointer;" onclick="openNativeChart('${r.id_agente_modulo}', '${r.agent_alias.replace(/'/g, "\\'")} - ${r.module_name.replace(/'/g, "\\'")}', '${r.id_agente}')" title="View Chart">
                                <span class="material-symbols-outlined" style="font-size:16px!important; color:#1976d2;">monitoring</span>
                            </button>
                            <button class="icon-btn-card" style="padding:0; margin:0; background:none; border:none; cursor:pointer;" onclick="show_module_detail_dialog('${r.id_agente_modulo}', '${r.id_agente}', 'data', 0, 86400, '${r.module_name.replace(/'/g, "\\'")}')" title="View Data Table">
                                <span class="material-symbols-outlined" style="font-size:16px!important; color:#2e7d32;">table_chart</span>
                            </button>
                        </div>
                    </td>
                  </tr>`;
        });
    }
    h += '</tbody></table></div>';
    document.getElementById('detailModalContent').innerHTML = h;
}

async function showDetailModal(cardId, statusFilter) {
    const card = dashboardCards.find(c => c.id === cardId);
    if (!card) return;

    const overlay = document.getElementById('loadingOverlay');
    if(overlay) overlay.style.display = 'flex';
    
    const matchType = card.match_type || 'contains';
    const url = `?api=status_details&group_id=${card.group_id}&keyword=${encodeURIComponent(card.keyword)}&manual_ids=${card.manual_ids || ''}&status_filter=${statusFilter}&match_type=${matchType}`;
    
    try {
        const res = await fetch(url).then(r => r.json());
        if(overlay) overlay.style.display = 'none';
        if (!res.ok) return alert("Error fetching details: " + res.error);

        document.getElementById('detailModalSearch').value = '';
        currentModalData = res.data;
        
        let title = "Module Details";
        const labels = { 'all': 'All', 'normal': 'UP', 'critical': 'CRITICAL', 'warning': 'WARNING', 'not_init': 'NOT INIT', 'unknown': 'UNKNOWN' };
        title = (labels[statusFilter] || statusFilter.toUpperCase()) + " Modules";

        modalFilteredData = [...currentModalData];
        modalCurrentPage = 1;
        document.getElementById('detailModalTitle').innerText = `${title} (${currentModalData.length} rows)`;
        document.getElementById('detailModal').style.display = 'flex';
        
        renderDetailModalPage();
    } catch (e) {
        if(overlay) overlay.style.display = 'none';
        alert("Fetch Error: " + e.message);
    }
}

function filterDetailModal() {
    clearTimeout(searchDebounceTimer);
    searchDebounceTimer = setTimeout(() => {
        const kw = document.getElementById('detailModalSearch').value.toLowerCase().trim();
        if (!kw) {
            modalFilteredData = [...currentModalData];
        } else {
            modalFilteredData = currentModalData.filter(r => 
                (r.agent_alias && r.agent_alias.toLowerCase().includes(kw)) || 
                (r.module_name && r.module_name.toLowerCase().includes(kw)) || 
                (r.ip_address && r.ip_address.toLowerCase().includes(kw)) || 
                (r.group_name && r.group_name.toLowerCase().includes(kw))
            );
        }
        modalCurrentPage = 1;
        renderDetailModalPage();
    }, 300);
}

function renderDetailModalPage() {
    const total = modalFilteredData.length;
    const MODAL_PAGE_SIZE = 25;
    const totalPages = Math.ceil(total / MODAL_PAGE_SIZE) || 1;
    if(modalCurrentPage > totalPages) modalCurrentPage = totalPages;
    
    const startIdx = (modalCurrentPage - 1) * MODAL_PAGE_SIZE;
    const endIdx = Math.min(startIdx + MODAL_PAGE_SIZE, total);
    const pageData = modalFilteredData.slice(startIdx, endIdx);

    let h = '<div style="padding:0; max-height:60vh; overflow-y:auto;"><table class="table-pfms"><thead><tr><th>Agent</th><th>Group</th><th>IP Address</th><th>Sensor Module</th><th>Value</th><th style="text-align:center;">Status</th><th style="text-align:center;">Actions</th></tr></thead><tbody>';

    if (pageData.length === 0) {
        h += '<tr><td colspan="7" style="text-align:center; padding: 25px; color:#7f8c8d; font-weight: normal;">No matching data found.</td></tr>';
    } else {
        pageData.forEach(r => {
            const sObj = getStatusObj(r.estado);
            let unitStr = r.unit ? ` ${r.unit}` : '';
            const isPrimaryAgent = String(r.id_agente).startsWith(PRIMARY_UUID + ':');
            let agentLinkHtml = '';
            if (isPrimaryAgent) {
                const rawAgentId = String(r.id_agente).split(':')[1] || r.id_agente;
                agentLinkHtml = `<a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${rawAgentId}" target="_blank" class="agent-link">${r.agent_alias}</a>`;
            } else {
                agentLinkHtml = `<span style="font-weight:500; color:#334155;">${r.agent_alias}</span>`;
            }

            h += `<tr>
                    <td><div class="node-wrap"><div class="dot ${sObj.color}"></div>${agentLinkHtml}</div></td>
                    <td style="color:#7f8c8d">${r.group_name}</td>
                    <td><code class="ip-text">${r.ip_address||'-'}</code></td>
                    <td style="color:#0b1a26; font-weight: normal;">${r.module_name}</td>
                    <td style="font-weight: normal;">${r.current_value}${unitStr}</td>
                    <td style="text-align:center;">
                        <div class="status-pill ${sObj.color}" style="color:#fff!important; border:none; padding:4px 8px;">
                            ${sObj.label}
                        </div>
                    </td>
                    <td style="text-align:center; white-space:nowrap;">
                        <div style="display:inline-flex; gap:8px; align-items:center; justify-content:center; width:100%;">
                            <button class="icon-btn-card" style="padding:0; margin:0; background:none; border:none; cursor:pointer;" onclick="openNativeChart('${r.id_agente_modulo}', '${r.agent_alias.replace(/'/g, "\\'")} - ${r.module_name.replace(/'/g, "\\'")}', '${r.id_agente}')" title="View Chart">
                                <span class="material-symbols-outlined" style="font-size:16px!important; color:#1976d2;">monitoring</span>
                            </button>
                            <button class="icon-btn-card" style="padding:0; margin:0; background:none; border:none; cursor:pointer;" onclick="show_module_detail_dialog('${r.id_agente_modulo}', '${r.id_agente}', 'data', 0, 86400, '${r.module_name.replace(/'/g, "\\'")}')" title="View Data Table">
                                <span class="material-symbols-outlined" style="font-size:16px!important; color:#2e7d32;">table_chart</span>
                            </button>
                        </div>
                    </td>
                  </tr>`;
        });
    }
    h += '</tbody></table></div>';

    if(totalPages > 1) {
        h += `
        <div class="pagination-container" style="background:#fff; border-radius:0;">
            <div style="font-size:11px; font-weight: normal; color:#7f8c8d;">Showing ${startIdx + 1} to ${endIdx} of ${total} Entries</div>
            <div style="display:flex; gap:10px;">
                <button class="pagination-btn" ${modalCurrentPage === 1 ? 'disabled' : ''} onclick="changeModalPage(-1)">Prev</button>
                <span style="font-size:12px; font-weight: normal; align-self:center;">Page ${modalCurrentPage} / ${totalPages}</span>
                <button class="pagination-btn" ${modalCurrentPage === totalPages ? 'disabled' : ''} onclick="changeModalPage(1)">Next</button>
            </div>
        </div>`;
    }

    document.getElementById('detailModalContent').innerHTML = h;
}

function changeModalPage(dir) {
    modalCurrentPage += dir;
    renderDetailModalPage();
}

function closeDetailModal() { document.getElementById('detailModal').style.display = 'none'; }

function openExport(cardId) {
    curExpCardId = cardId; const data = cardDataStore[cardId]; if(!data || !data.length) return alert("No data.");
    const container = document.getElementById('export_agent_list');
    const uniqueAgents = [...new Map(data.map(item => [item.id_agente, item])).values()];
    container.innerHTML = uniqueAgents.map(d => `<div class="bulk-item"><input type="checkbox" class="exp-chk" data-agid="${d.id_agente}" id="exp_${d.id_agente}" checked><label for="exp_${d.id_agente}" style="font-size:12px; margin:0; cursor:pointer;">${d.agent_alias}</label></div>`).join('');
    document.getElementById('exportModal').style.display = 'flex';
}
function toggleExportAll() { const chks = document.querySelectorAll('.exp-chk'); const allChecked = Array.from(chks).every(c => c.checked); chks.forEach(c => c.checked = !allChecked); }
function toggleBuilderMatchMode() {
    const mode = document.querySelector('input[name="b_match_type"]:checked').value;
    document.getElementById('wrap_exact').style.display = (mode === 'exact') ? 'block' : 'none';
    document.getElementById('wrap_contains').style.display = (mode === 'exact') ? 'none' : 'block';
}

async function refreshBuilderModuleList() {
    const groupId = document.getElementById('b_group').value || '0';
    const manualIds = selectedIds.join(',');
    
    try {
        const res = await fetch(`?api=module_list&group_id=${groupId}&manual_ids=${manualIds}`);
        const data = await res.json();
        if (Array.isArray(data)) {
            globalModuleList = data;
            if (document.getElementById('exact_dropdown').style.display === 'flex') {
                renderExactModuleList();
            }
        }
    } catch (e) {
        console.error("Failed to refresh module list:", e);
    }
}

function showExactDropdown() { 
    document.getElementById('exact_dropdown').style.display = 'flex'; 
    renderExactModuleList(); 
}

function renderExactModuleList() {
    const ul = document.getElementById('exact_module_ul');
    const kw = document.getElementById('exact_search_input').value.toLowerCase();
    const currentRaw = document.getElementById('p_keyword_exact').value;
    const rawArr = currentRaw ? currentRaw.split(',').map(s => s.trim()).filter(s => s !== '') : [];
    
    let filtered = globalModuleList.filter(m => m.pretty.toLowerCase().includes(kw) || m.raw.toLowerCase().includes(kw));
    ul.innerHTML = filtered.slice(0,50).map(m => {
        const isSelected = rawArr.includes(m.raw);
        const selClass = isSelected ? 'selected' : '';
        const safeRaw = encodeURIComponent(m.raw);
        const safePretty = encodeURIComponent(m.pretty);
        return `<li class="${selClass}" data-raw="${safeRaw}" data-pretty="${safePretty}" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; cursor: pointer;">
            <input type="checkbox" ${isSelected ? 'checked' : ''} style="margin: 0; pointer-events: none; width: 14px; height: 14px;">
            <span>${m.pretty}</span>
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
        
        const span = document.createElement('span');
        span.textContent = prettyName;
        
        const removeBtn = document.createElement('span');
        removeBtn.className = 'remove-tag';
        removeBtn.innerHTML = '&times;';
        removeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            removeModuleTag(raw);
        });
        
        tag.appendChild(span);
        tag.appendChild(removeBtn);
        container.appendChild(tag);
    });
}

function removeModuleTag(rawName) {
    let rawInput = document.getElementById('p_keyword_exact');
    let rawArr = rawInput.value.split(',').map(s => s.trim()).filter(s => s !== '');
    rawArr = rawArr.filter(r => r !== rawName);
    rawInput.value = rawArr.join(', ');
    renderSelectedModules();
    renderExactModuleList();
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
    renderExactModuleList();
}

// Add event delegation on the exact_module_ul element
const exactModuleUl = document.getElementById('exact_module_ul');
if (exactModuleUl) {
    exactModuleUl.addEventListener('click', function(e) {
        const li = e.target.closest('li');
        if (!li) return;
        const rawName = decodeURIComponent(li.dataset.raw);
        const prettyName = decodeURIComponent(li.dataset.pretty);
        selectExactModule(rawName, prettyName);
    });
}

// Add global listener to close exact dropdown
document.addEventListener('click', function(e) {
    const dropdown = document.getElementById('exact_dropdown');
    const input = document.getElementById('exact_search_input');
    if (dropdown && input && !dropdown.contains(e.target) && e.target !== input) {
        dropdown.style.display = 'none';
    }
});

function processExport() {
    const selected = Array.from(document.querySelectorAll('.exp-chk:checked')); if(!selected.length) return alert("Select agents.");
    const agIds = selected.map(s => s.dataset.agid).join(',');
    const c = dashboardCards.find(card=>card.id==curExpCardId);
    const kw = c.keyword;
    const matchType = c.match_type || 'contains';
    const url = `?api=export_data&agent_ids=${agIds}&keyword=${encodeURIComponent(kw)}&format=${document.getElementById('e_format').value}&match_type=${matchType}`;
    window.open(url, '_blank');
}
function closeExport() { document.getElementById('exportModal').style.display = 'none'; }

function toggleViewTypeOptions() {
    const vt = document.getElementById('b_view_type').value;
    const wrap = document.getElementById('wrap_chart_options');
    const wrapShowStats = document.getElementById('wrap_show_stats');
    const wrapSingleValue = document.getElementById('wrap_single_value_options');
    
    if (vt === 'pie' || vt === 'donut') {
        wrap.style.display = 'block';
    } else {
        wrap.style.display = 'none';
    }

    if (vt === 'single_value') {
        if (wrapSingleValue) wrapSingleValue.style.display = 'block';
    } else {
        if (wrapSingleValue) wrapSingleValue.style.display = 'none';
    }

    if (vt === 'line' || vt === 'area' || vt === 'bar' || vt === 'history_table' || vt === 'single_value') {
        if (wrapShowStats) wrapShowStats.style.display = 'none';
    } else {
        if (wrapShowStats) wrapShowStats.style.display = 'block';
    }
}

async function openBuilder() {
    editingCardId = null; selectedIds = []; document.getElementById('builderTitle').innerText='Build Widget';
    document.getElementById('b_title').value = '';
    document.getElementById('b_group').value='0';
    document.getElementById('b_icon_size').value='18';
    document.getElementById('b_font_size').value='14';
    document.getElementById('b_chart_font_size').value='11';
    document.getElementById('b_use_raw').checked = false;
    document.querySelectorAll('.col-visibility-chk').forEach(chk => chk.checked = true);
    document.getElementById('b_chart_limit').value = '0';
    document.getElementById('b_show_legend_count').value = '1';
    document.getElementById('b_show_stats').value = '1';
    if (document.getElementById('b_show_module_name')) document.getElementById('b_show_module_name').value = '1';
    toggleViewTypeOptions();
    document.getElementById('inner_search').value = '';
    document.getElementById('sel_count').innerText = "0 Selected";
    
    document.querySelector('input[name="b_match_type"][value="contains"]').checked = true;
    document.getElementById('b_keyword').value = '%';
    document.getElementById('p_keyword_exact').value = '';
    document.getElementById('exact_search_input').value = '';
    document.getElementById('exact_selected_tags').innerHTML = '';
    toggleBuilderMatchMode();

    toggleManualSelector();
    refreshBuilderModuleList();
    document.getElementById('builderModal').style.display='flex';
    await loadAgentsForBuilder('');
}
async function openEdit(id) {
    editingCardId = id; const c = dashboardCards.find(x => x.id === id); document.getElementById('builderTitle').innerText='Edit Widget';
    ['title','view_type','group','limit','refresh','icon_size','font_size','use_raw'].forEach(k => {
        const el = document.getElementById('b_'+k);
        if(el) {
            if (el.type === 'checkbox') el.checked = !!c[k];
            else el.value = c[k==='group'?'group_id':(k==='refresh'?'refresh_sec':k)] || (k==='icon_size'?'18':(k==='font_size'?'14':''));
        }
    });

    const activeCols = c.visible_columns || ['agent', 'group', 'ip', 'module', 'status', 'history', 'threshold'];
    document.querySelectorAll('.col-visibility-chk').forEach(el => {
        el.checked = activeCols.includes(el.value);
    });

    document.getElementById('b_chart_limit').value = c.chart_limit || '0';
    document.getElementById('b_show_legend_count').value = (c.show_legend_count !== undefined) ? String(c.show_legend_count) : '1';
    document.getElementById('b_show_stats').value = (c.show_stats !== undefined) ? String(c.show_stats) : '1';
    document.getElementById('b_chart_font_size').value = c.chart_font_size || '11';
    if (document.getElementById('b_show_module_name')) {
        document.getElementById('b_show_module_name').value = (c.show_module_name !== undefined) ? String(c.show_module_name) : '1';
    }
    toggleViewTypeOptions();

    const mType = c.match_type || 'contains';
    document.querySelector(`input[name="b_match_type"][value="${mType}"]`).checked = true;

    if (mType === 'exact') {
        document.getElementById('p_keyword_exact').value = c.keyword || '';
        document.getElementById('b_keyword').value = '';
        renderSelectedModules();
    } else {
        document.getElementById('b_keyword').value = c.keyword || '%';
        document.getElementById('p_keyword_exact').value = '';
        document.getElementById('exact_search_input').value = '';
        document.getElementById('exact_selected_tags').innerHTML = '';
    }

    toggleBuilderMatchMode();

    selectedIds = c.manual_ids ? String(c.manual_ids).split(',').filter(x => x.trim() !== '') : [];
    document.getElementById('inner_search').value = '';
    document.getElementById('sel_count').innerText = selectedIds.length + " Selected";
    toggleManualSelector();
    refreshBuilderModuleList();
    document.getElementById('builderModal').style.display='flex';
    await loadAgentsForBuilder('');
}
function closeBuilder() { document.getElementById('builderModal').style.display = 'none'; }

function saveWidget() {
    if (!currentDashId) return;
    const matchType = document.querySelector('input[name="b_match_type"]:checked').value;
    const keywordVal = (matchType === 'exact') ? document.getElementById('p_keyword_exact').value : document.getElementById('b_keyword').value;
    const card = {
        id: editingCardId || 'c'+Date.now(),
        title: document.getElementById('b_title').value||'Widget',
        view_type: document.getElementById('b_view_type').value,
        group_id: document.getElementById('b_group').value,
        match_type: matchType,
        keyword: keywordVal,
        limit: document.getElementById('b_limit').value,
        refresh_sec: document.getElementById('b_refresh').value,
        icon_size: document.getElementById('b_icon_size').value || 18,
        font_size: document.getElementById('b_font_size').value || 14,
        chart_font_size: parseInt(document.getElementById('b_chart_font_size').value) || 11,
        use_raw: document.getElementById('b_use_raw').checked,
        chart_limit: document.getElementById('b_chart_limit').value,
        show_legend_count: parseInt(document.getElementById('b_show_legend_count').value),
        show_stats: parseInt(document.getElementById('b_show_stats').value),
        show_module_name: document.getElementById('b_show_module_name') ? parseInt(document.getElementById('b_show_module_name').value) : 1,
        visible_columns: Array.from(document.querySelectorAll('.col-visibility-chk:checked')).map(el => el.value),
        manual_ids: selectedIds.join(',')
    };

    masterDashboards = masterDashboards.map(d => {
        if (d.id === currentDashId) {
            let tempCards = [];
            if (editingCardId) {
                tempCards = (d.panels || []).map(x => x.id === editingCardId ? card : x);
            } else {
                tempCards = [...(d.panels || []), card];
            }
            d.panels = tempCards;
            dashboardCards = tempCards;
        }
        return d;
    });

    const btn = document.getElementById("btnSaveWidget");
    btn.innerHTML = '<span class="material-symbols-outlined">sync</span> Saving...';
    btn.disabled = true;

    saveConfigToServer(() => {
        renderGrid();
        fetchCardData(card);
        closeBuilder();
    })
    .finally(() => {
        btn.innerHTML = 'Save Widget';
        btn.disabled = false;
    });
}

function deleteCard(id) {
    if (!currentDashId) return;
    if(confirm('Delete widget?')) {
        masterDashboards = masterDashboards.map(d => {
            if (d.id === currentDashId) {
                const tempCards = (d.panels || []).filter(x => x.id !== id);
                d.panels = tempCards;
                dashboardCards = tempCards;
            }
            return d;
        });
        saveConfigToServer(() => {
            renderGrid();
        });
    }
}

function copyStandaloneUrl(card) {
    if(!currentDashId) return;
    const u = new URL(window.location.origin + DIRECT_SCRIPT_URL);
    u.searchParams.set('s', '1');
    u.searchParams.set('d', currentDashId);
    u.searchParams.set('card_id', card.id);
    const urlString = u.toString();

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(urlString).then(() => alert("URL Share Widget Copied!"));
    } else {
        const textArea = document.createElement("textarea");
        textArea.value = urlString; textArea.style.position = "fixed"; textArea.style.left = "-999999px"; textArea.style.top = "-999999px";
        document.body.appendChild(textArea); textArea.focus(); textArea.select();
        try { document.execCommand('copy'); alert("URL Share Widget Copied!"); } catch (err) { prompt("Copy manual:", urlString); }
        textArea.remove();
    }
}

function duplicatePanel(id) {
    if(!currentDashId) return;
    const card = dashboardCards.find(x => x.id === id);
    if (!card) return;
    const newCard = JSON.parse(JSON.stringify(card));
    newCard.id = 'c' + Date.now();
    newCard.title = newCard.title + " (Copy)";
    
    masterDashboards = masterDashboards.map(d => {
        if (d.id === currentDashId) {
            const tempCards = [...(d.panels || []), newCard];
            d.panels = tempCards;
            dashboardCards = tempCards;
        }
        return d;
    });

    const btn = document.querySelector(`#box_${id} .icon-btn-card[title="Duplicate"]`);
    if(btn) btn.style.opacity = '0.5';

    saveConfigToServer(() => {
        renderGrid();
        dashboardCards.forEach(c => fetchCardData(c));
    }).finally(() => { if(btn) btn.style.opacity = '1'; });
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
    dlAnchorElem.setAttribute("download", `metrics_dashboard_${d.title.toLowerCase().replace(/\s+/g, '_')}_backup.json`);
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
                masterDashboards = masterDashboards.map(d => {
                    if (d.id === targetId) {
                        d.panels = loaded;
                        if (targetId === currentDashId) {
                            dashboardCards = loaded;
                        }
                    }
                    return d;
                });
                saveConfigToServer(() => {
                    if (targetId === currentDashId) {
                        renderGrid();
                        dashboardCards.forEach(c => fetchCardData(c));
                    } else {
                        renderDashboardList();
                    }
                    alert("Widgets loaded successfully!");
                });
            } else {
                alert("Format file JSON tidak valid. Harus berupa array widget.");
            }
        } catch (err) { alert("Invalid JSON file: " + err.message); }
        finally {
            event.target.value = '';
            importTargetDashId = null;
        }
    };
    reader.readAsText(file);
}

// =====================================================================
// DRAG AND DROP HANDLERS
// =====================================================================
let dragSrcId = null;

function handleDragStart(e, id) {
    if(IS_STANDALONE) return e.preventDefault();
    dragSrcId = id;
    e.target.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', id);
}

function handleDragOver(e) {
    e.preventDefault();
    e.currentTarget.classList.add('drag-over');
    e.dataTransfer.dropEffect = 'move';
    return false;
}

function handleDragLeave(e) {
    e.currentTarget.classList.remove('drag-over');
}

function handleDrop(e, targetId) {
    e.stopPropagation();
    e.currentTarget.classList.remove('drag-over');
    
    if (dragSrcId && dragSrcId !== targetId) {
        const srcIdx = dashboardCards.findIndex(c => c.id === dragSrcId);
        const tgtIdx = dashboardCards.findIndex(c => c.id === targetId);

        if(srcIdx > -1 && tgtIdx > -1) {
            const movedItem = dashboardCards.splice(srcIdx, 1)[0];
            dashboardCards.splice(tgtIdx, 0, movedItem);
            
            saveOrderToServer();
            renderGrid();
            dashboardCards.forEach(c => fetchCardData(c));
        }
    }
    return false;
}

function handleDragEnd(e) {
    e.target.classList.remove('dragging');
    document.querySelectorAll('.dashboard-card').forEach(c => c.classList.remove('drag-over'));
}

function saveOrderToServer() {
    if(!currentDashId) return;
    masterDashboards = masterDashboards.map(d => {
        if (d.id === currentDashId) {
            d.panels = dashboardCards;
        }
        return d;
    });
    saveConfigToServer(null, true);
}

function openCustomRangeModal(cardId) {
    const existing = document.getElementById('custom_range_modal');
    if (existing) existing.remove();

    const card = dashboardCards.find(c => c.id === cardId);
    if (!card) return;

    let startVal = '';
    let endVal = '';
    if (card.custom_start) {
        startVal = card.custom_start;
    } else {
        const d = new Date(Date.now() - 86400000);
        startVal = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    }

    if (card.custom_end) {
        endVal = card.custom_end;
    } else {
        const d = new Date();
        endVal = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    }

    const modal = document.createElement('div');
    modal.id = 'custom_range_modal';
    modal.style.position = 'fixed';
    modal.style.top = '0';
    modal.style.left = '0';
    modal.style.width = '100vw';
    modal.style.height = '100vh';
    modal.style.background = 'rgba(15, 23, 42, 0.4)';
    modal.style.backdropFilter = 'blur(4px)';
    modal.style.display = 'flex';
    modal.style.justifyContent = 'center';
    modal.style.alignItems = 'center';
    modal.style.zIndex = '10000';
    modal.style.fontFamily = "'Inter', system-ui, -apple-system, sans-serif";

    modal.innerHTML = `
        <div style="background:#ffffff; border-radius:12px; width:340px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15), 0 10px 10px -5px rgba(0, 0, 0, 0.1); padding: 24px; border: 1px solid #e2e8f0;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
                <h4 style="margin:0; font-size:16px; font-weight:600; color:#0f172a; display:flex; align-items:center; gap:8px;">
                    <span class="material-symbols-outlined" style="color:#004d40; font-size:22px; font-weight:bold;">calendar_month</span> Custom Time Range
                </h4>
                <button onclick="document.getElementById('custom_range_modal').remove()" style="background:none; border:none; cursor:pointer; padding:4px; display:flex; color:#64748b; transition: color 0.2s;" onmouseenter="this.style.color='#0f172a'" onmouseleave="this.style.color='#64748b'"><span class="material-symbols-outlined" style="font-size:20px;">close</span></button>
            </div>
            
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:6px;">Start Date & Time</label>
                <input type="datetime-local" id="cust_start_${cardId}" value="${startVal}" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-family:inherit; color:#1e293b; outline:none; transition: all 0.2s;" onfocus="this.style.borderColor='#004d40'; this.style.boxShadow='0 0 0 3px rgba(0, 77, 64, 0.15)'" onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none'">
            </div>
            
            <div style="margin-bottom:22px;">
                <label style="display:block; font-size:12px; font-weight:600; color:#475569; margin-bottom:6px;">End Date & Time</label>
                <input type="datetime-local" id="cust_end_${cardId}" value="${endVal}" style="width:100%; padding:9px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; font-family:inherit; color:#1e293b; outline:none; transition: all 0.2s;" onfocus="this.style.borderColor='#004d40'; this.style.boxShadow='0 0 0 3px rgba(0, 77, 64, 0.15)'" onblur="this.style.borderColor='#cbd5e1'; this.style.boxShadow='none'">
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button onclick="document.getElementById('custom_range_modal').remove()" style="padding:9px 16px; border:1px solid #e2e8f0; background:#ffffff; color:#475569; border-radius:6px; font-size:13px; font-weight:500; cursor:pointer; transition: background 0.15s;" onmouseenter="this.style.background='#f8fafc'" onmouseleave="this.style.background='#ffffff'">Cancel</button>
                <button id="apply_cust_range_${cardId}" style="padding:9px 16px; border:none; background:#004d40; color:#ffffff; border-radius:6px; font-size:13px; font-weight:500; cursor:pointer; transition: background 0.15s;" onmouseenter="this.style.background='#00332c'" onmouseleave="this.style.background='#004d40'">Apply Range</button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);

    document.getElementById(`apply_cust_range_${cardId}`).onclick = () => {
        const startInput = document.getElementById(`cust_start_${cardId}`).value;
        const endInput = document.getElementById(`cust_end_${cardId}`).value;

        if (!startInput || !endInput) {
            alert('Please select both start and end times.');
            return;
        }

        const startTs = Math.round(new Date(startInput).getTime() / 1000);
        const endTs = Math.round(new Date(endInput).getTime() / 1000);

        if (startTs >= endTs) {
            alert('Start time must be before End time.');
            return;
        }

        card.time_range = 'custom';
        card.custom_start = startInput;
        card.custom_end = endInput;
        card.custom_start_ts = startTs;
        card.custom_end_ts = endTs;

        saveOrderToServer();
        fetchCardData(card);
        modal.remove();
    };
}

function openTimeRangeMenu(event, cardId) {
    event.stopPropagation();
    const existing = document.getElementById('floating_range_menu');
    if (existing) existing.remove();

    const card = dashboardCards.find(c => c.id === cardId);
    if (!card) return;

    const currentRange = card.time_range || 86400; // default 24h

    const ranges = [
        { label: 'Last 1 Hour', value: 3600 },
        { label: 'Last 6 Hours', value: 21600 },
        { label: 'Last 24 Hours', value: 86400 },
        { label: 'Last 7 Days', value: 604800 },
        { label: 'Last 30 Days', value: 2592000 },
        { label: 'Custom Range...', value: 'custom' }
    ];

    const menu = document.createElement('div');
    menu.id = 'floating_range_menu';
    menu.style.position = 'absolute';
    menu.style.background = '#ffffff';
    menu.style.border = '1px solid #e2e8f0';
    menu.style.borderRadius = '8px';
    menu.style.boxShadow = '0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1)';
    menu.style.zIndex = '9999';
    menu.style.width = '170px';
    menu.style.overflow = 'hidden';
    menu.style.padding = '6px 0';
    menu.style.fontFamily = "'Inter', system-ui, -apple-system, sans-serif";

    ranges.forEach(r => {
        const item = document.createElement('div');
        item.style.padding = '10px 16px';
        item.style.fontSize = '13px';
        item.style.cursor = 'pointer';
        item.style.display = 'flex';
        item.style.justifyContent = 'space-between';
        item.style.alignItems = 'center';
        item.style.transition = 'all 0.15s ease';
        
        const isActive = (r.value === currentRange || (typeof r.value === 'number' && parseInt(r.value) === parseInt(currentRange)));
        item.style.color = isActive ? '#004d40' : '#475569';
        item.style.background = isActive ? '#f0fdf4' : 'transparent';
        item.style.fontWeight = isActive ? '600' : '500';

        item.innerText = r.label;
        if (isActive) {
            item.innerHTML += `<span class="material-symbols-outlined" style="font-size:16px!important; color:#10b981; font-weight: bold;">check</span>`;
        }

        item.onmouseenter = () => { if (!isActive) item.style.background = '#f1f5f9'; };
        item.onmouseleave = () => { if (!isActive) item.style.background = 'transparent'; };

        item.onclick = () => {
            if (r.value === 'custom') {
                openCustomRangeModal(cardId);
            } else {
                card.time_range = r.value;
                saveOrderToServer();
                fetchCardData(card);
            }
            menu.remove();
        };

        menu.appendChild(item);
    });

    const rect = event.currentTarget.getBoundingClientRect();
    menu.style.top = `${rect.bottom + window.scrollY + 6}px`;
    menu.style.left = `${rect.right - 170 + window.scrollX}px`;

    document.body.appendChild(menu);

    const closeListener = () => {
        menu.remove();
        document.removeEventListener('click', closeListener);
    };

    setTimeout(() => document.addEventListener('click', closeListener), 10);
}

function renderWidgetChart(cardId, viewType, data, chartLimit = 0, stats = {}, history = []) {
    const container = document.getElementById(`content_view_${cardId}`);
    if (!data || data.length === 0) {
        container.innerHTML = `<div style="text-align:center; padding: 40px; color:#7f8c8d; font-weight: normal;">No data available to render chart.</div>`;
        return;
    }

    const card = dashboardCards.find(c => c.id === cardId) || {};

    if (viewType === 'single_value') {
        const m = data[0] || {};
        const valText = m.current_value !== null && m.current_value !== undefined ? m.current_value : 'N/A';
        const color = {0:'#2ecc71', 1:'#e74c3c', 2:'#f1c40f', 4:'#3498db'}[m.estado] || '#95a5a6';
        const unit = m.unit || '';
        const showText = card.show_module_name !== 0;
        
        container.innerHTML = `
        <div style="height: 260px; width: 100%; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; background: #fff; border-radius: 6px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div style="padding: 15px 15px 0 15px;">
                <div style="font-size: 36px; font-weight: 700; color: ${color}; line-height: 1.1; display: flex; align-items: baseline; gap: 4px;">
                    <span>${valText}</span>
                    <span style="font-size: 16px; font-weight: normal; color: #64748b;">${unit}</span>
                </div>
            </div>
            <!-- Relative positioned ECharts Sparkline container -->
            <div id="chart_canvas_${cardId}" style="flex: 1; min-height: 80px; width: 100%; cursor: pointer;" onclick="openNativeModuleDetailModal('${m.id_agente_modulo}', '${(m.agent_alias + ' - ' + m.module_name).replace(/'/g, "\\'")}')"></div>
            
            ${showText ? `
            <div style="padding: 10px 15px 15px 15px; font-size: 11px; font-weight: 500; color: #64748b; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; z-index: 2;">
                ${m.agent_alias} - ${m.module_name}
            </div>
            ` : '<div style="height: 10px;"></div>'}
        </div>
        `;

        if (activeCharts[cardId]) {
            if (typeof activeCharts[cardId].dispose === "function") activeCharts[cardId].dispose();
            delete activeCharts[cardId];
        }

        const modHist = history.filter(h => String(h.id_mod) === String(m.id_agente_modulo));
        if (modHist && modHist.length > 0) {
            activeCharts[cardId] = echarts.init(document.getElementById(`chart_canvas_${cardId}`));
            activeCharts[cardId].setOption({
                grid: { left: 0, right: 0, top: 0, bottom: 0 },
                xAxis: { type: 'category', boundaryGap: false, data: modHist.map(h => h.time), show: false },
                yAxis: { type: 'value', show: false },
                series: [{
                    type: 'line',
                    data: modHist.map(h => h.val),
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
        return;
    }

    const chartFontSize = parseInt(card.chart_font_size) || 11;
    const dashboardFontFamily = "'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";

    if (typeof Chart !== 'undefined') {
        Chart.defaults.font.family = dashboardFontFamily;
        Chart.defaults.color = "#4a5568";
    }

    container.innerHTML = `<div class="chart-container" style="position: relative; width: 100%; height: 260px; padding: 5px;">
        <div id="chart_canvas_${cardId}" style="width:100%; height:100%; min-height:200px;"></div>
    </div>`;

    if (activeCharts[cardId]) {
        if (typeof activeCharts[cardId].dispose === "function") activeCharts[cardId].dispose();
        delete activeCharts[cardId];
    }

    const canvas = document.getElementById(`chart_canvas_${cardId}`);
    if (!canvas) return;

    const colors = [
        'rgba(0, 77, 64, 0.75)',    // Premium Teal
        'rgba(25, 118, 210, 0.75)',  // Deep Blue
        'rgba(211, 47, 47, 0.75)',   // Crimson Red
        'rgba(245, 124, 0, 0.75)',   // Vibrant Orange
        'rgba(123, 31, 162, 0.75)',  // Royal Purple
        'rgba(0, 150, 136, 0.75)',   // Sea Green
        'rgba(251, 192, 45, 0.75)',  // Golden Yellow
        'rgba(97, 97, 97, 0.75)',    // Slate Gray
        'rgba(233, 30, 99, 0.75)',    // Hot Pink
        'rgba(141, 110, 99, 0.75)'   // Earth Brown
    ];
    
    const borders = [
        '#004d40', '#1976d2', '#d32f2f', '#f57c00', '#7b1fa2',
        '#009688', '#fbc02d', '#616161', '#e91e63', '#8d6e63'
    ];

    const elegantTooltipConfig = {
        backgroundColor: 'rgba(15, 23, 42, 0.95)', // Deep Slate Dark Mode
        titleColor: '#ffffff',
        titleFont: { size: chartFontSize + 1, family: dashboardFontFamily, weight: '600' },
        bodyColor: '#cbd5e1',
        bodyFont: { size: chartFontSize, family: dashboardFontFamily },
        padding: 12,
        cornerRadius: 8,
        borderColor: 'rgba(255, 255, 255, 0.1)',
        borderWidth: 1,
        displayColors: true,
        boxWidth: 8,
        boxHeight: 8,
        boxPadding: 4,
        usePointStyle: true
    };

    if (viewType === 'pie' || viewType === 'donut') {
        const statusMap = [
            { label: 'UP (Normal)', value: parseInt(stats.normal) || 0, color: '#2ecc71', border: '#27ae60' },
            { label: 'Warning', value: parseInt(stats.warning) || 0, color: '#f1c40f', border: '#f39c12' },
            { label: 'Critical', value: parseInt(stats.critical) || 0, color: '#e74c3c', border: '#c0392b' },
            { label: 'Unknown', value: parseInt(stats.unknown) || 0, color: '#95a5a6', border: '#7f8c8d' },
            { label: 'Not Init', value: parseInt(stats.not_init) || 0, color: '#3498db', border: '#2980b9' }
        ];

        const activeStatuses = statusMap.filter(s => s.value > 0);
        const finalStatuses = activeStatuses.length > 0 ? activeStatuses : statusMap;

        let displayStatuses = [...finalStatuses];
        if (chartLimit > 0 && displayStatuses.length > chartLimit) {
            displayStatuses.sort((a, b) => b.value - a.value);
            const topList = displayStatuses.slice(0, chartLimit);
            const othersList = displayStatuses.slice(chartLimit);
            const sumOthers = othersList.reduce((acc, curr) => acc + curr.value, 0);
            if (sumOthers > 0) {
                topList.push({
                    label: 'Others',
                    value: sumOthers,
                    color: '#9b51e0',
                    border: '#8e44ad'
                });
            }
            displayStatuses = topList;
        }

        const showLegendCount = (card.show_legend_count !== 0);
        const labels = displayStatuses.map(s => showLegendCount ? `${s.label} (${s.value})` : s.label);
        const values = displayStatuses.map(s => s.value);
        const bgColors = displayStatuses.map(s => s.color);
        const borderColors = displayStatuses.map(s => s.border);

        const pieData = displayStatuses.map(s => ({
            name: showLegendCount ? `${s.label} (${s.value})` : s.label,
            value: s.value,
            itemStyle: { color: s.color }
        }));

        activeCharts[cardId] = echarts.init(document.getElementById(`chart_canvas_${cardId}`));
        activeCharts[cardId].setOption({
            tooltip: { trigger: 'item', backgroundColor: 'rgba(15, 23, 42, 0.95)', textStyle: { color: '#cbd5e1', fontSize: 12 }, padding: 10, borderRadius: 6, formatter: '{b}: {c} ({d}%)' },
            legend: { orient: 'vertical', right: 10, top: 'center', itemWidth: 10, itemHeight: 10, textStyle: { fontSize: Math.max(9, chartFontSize - 1), color: '#475569' } },
            series: [{
                name: 'Count',
                type: viewType === 'pie' ? 'pie' : 'pie',
                radius: viewType === 'pie' ? '70%' : ['40%', '70%'],
                center: ['40%', '50%'],
                data: pieData,
                label: { show: true, formatter: '{b}\n{c} ({d}%)', fontSize: Math.max(9, chartFontSize - 1), color: '#475569' },
                labelLine: { show: true, length: 15, length2: 10 }
            }]
        });
    } else {
        if (history && history.length > 0) {
            const uniqueTimestamps = [...new Set(history.map(h => h.utimestamp))].sort((a, b) => a - b);
            const labels = uniqueTimestamps.map(ts => {
                const found = history.find(h => h.utimestamp === ts);
                return found ? found.time : '';
            });

            const seriesData = data.map((m, idx) => {
                const color = borders[idx % borders.length];
                const modHist = history.filter(h => String(h.id_mod) === String(m.id_agente_modulo));
                let lastVal = null;
                const dataPoints = uniqueTimestamps.map(ts => {
                    const h = modHist.find(x => x.utimestamp === ts);
                    if (h) lastVal = h.val;
                    return lastVal;
                });

                return {
                    name: `${m.agent_alias} - ${m.module_name}`,
                    type: (viewType === 'line' || viewType === 'area') ? 'line' : 'bar',
                    data: dataPoints,
                    itemStyle: { color: color },
                    areaStyle: viewType === 'area' ? { opacity: 0.15, color: color } : undefined,
                    smooth: true,
                    showSymbol: false,
                    connectNulls: true,
                    lineStyle: { width: viewType === 'bar' ? 0 : 2 }
                };
            });

            activeCharts[cardId] = echarts.init(document.getElementById(`chart_canvas_${cardId}`));
            activeCharts[cardId].setOption({
                tooltip: { 
                    trigger: 'axis', 
                    backgroundColor: 'rgba(15, 23, 42, 0.95)', 
                    textStyle: { color: '#cbd5e1', fontSize: 12 }, 
                    padding: 10, 
                    borderRadius: 6,
                    formatter: function(params) {
                        let html = params[0].name ? params[0].name + '<br/>' : '';
                        params.forEach(p => {
                            const mod = data[p.seriesIndex];
                            const unitStr = (mod && mod.unit) ? ' ' + mod.unit : '';
                            let val = p.value;
                            if (val !== null && val !== undefined && !isNaN(val)) {
                                val = parseFloat(val);
                                val = (val % 1 === 0) ? val : val.toFixed(2);
                            }
                            html += `${p.marker}${p.seriesName}: <b>${val}${unitStr}</b><br/>`;
                        });
                        return html;
                    }
                },
                legend: { type: 'scroll', bottom: 0, padding: [10, 5, 5, 5], icon: 'circle', textStyle: { fontSize: Math.max(9, chartFontSize - 1), color: '#64748b' } },
                grid: { left: 5, right: 15, top: 15, bottom: 45, containLabel: true },
                xAxis: { type: 'category', boundaryGap: viewType === 'bar', data: labels, axisLabel: { fontSize: Math.max(8, chartFontSize - 2), color: '#64748b' }, axisLine: { show: false }, axisTick: { show: false } },
                yAxis: { type: 'value', splitLine: { lineStyle: { color: '#f0f3f5' } }, axisLabel: { fontSize: Math.max(8, chartFontSize - 2), color: '#64748b' } },
                series: seriesData
            });
        } else {
            const processedData = [...data];
            const uniqueAgents = [...new Set(processedData.map(r => r.agent_alias))];
            const uniqueModules = [...new Set(processedData.map(r => r.module_name))];

            const seriesData = uniqueModules.map((moduleName, idx) => {
                const color = borders[idx % borders.length];
                const modData = uniqueAgents.map(agentAlias => {
                    const found = processedData.find(r => r.agent_alias === agentAlias && r.module_name === moduleName);
                    return found ? (parseFloat(found.current_value) || 0) : null;
                });

                return {
                    name: moduleName,
                    type: (viewType === 'line' || viewType === 'area') ? 'line' : 'bar',
                    data: modData,
                    itemStyle: { color: color },
                    areaStyle: viewType === 'area' ? { opacity: 0.15, color: color } : undefined,
                    smooth: true,
                    showSymbol: false,
                    connectNulls: true,
                    lineStyle: { width: viewType === 'bar' ? 0 : 2 }
                };
            });

            activeCharts[cardId] = echarts.init(document.getElementById(`chart_canvas_${cardId}`));
            activeCharts[cardId].setOption({
                tooltip: { 
                    trigger: 'axis', 
                    backgroundColor: 'rgba(15, 23, 42, 0.95)', 
                    textStyle: { color: '#cbd5e1', fontSize: 12 }, 
                    padding: 10, 
                    borderRadius: 6,
                    formatter: function(params) {
                        let html = params[0].name ? params[0].name + '<br/>' : '';
                        params.forEach(p => {
                            const foundMod = processedData.find(r => r.module_name === p.seriesName);
                            const unitStr = (foundMod && foundMod.unit) ? ' ' + foundMod.unit : '';
                            let val = p.value;
                            if (val !== null && val !== undefined && !isNaN(val)) {
                                val = parseFloat(val);
                                val = (val % 1 === 0) ? val : val.toFixed(2);
                            }
                            html += `${p.marker}${p.seriesName}: <b>${val}${unitStr}</b><br/>`;
                        });
                        return html;
                    }
                },
                legend: { type: 'scroll', bottom: 0, padding: [10, 5, 5, 5], icon: 'circle', textStyle: { fontSize: Math.max(9, chartFontSize - 1), color: '#64748b' } },
                grid: { left: 5, right: 15, top: 15, bottom: 45, containLabel: true },
                xAxis: { type: 'category', boundaryGap: viewType === 'bar', data: uniqueAgents, axisLabel: { fontSize: Math.max(8, chartFontSize - 2), color: '#64748b' }, axisLine: { show: false }, axisTick: { show: false } },
                yAxis: { type: 'value', splitLine: { lineStyle: { color: '#f0f3f5' } }, axisLabel: { fontSize: Math.max(8, chartFontSize - 2), color: '#64748b' } },
                series: seriesData
            });
        }
    }
}

init();

window.addEventListener('load', init);
window.addEventListener('resize', () => {
    Object.values(activeCharts).forEach(chart => {
        if (chart && typeof chart.resize === 'function') chart.resize();
    });
    if (typeof nativeModuleChartInstance !== 'undefined' && nativeModuleChartInstance && typeof nativeModuleChartInstance.resize === 'function') {
        nativeModuleChartInstance.resize();
    }
});

document.addEventListener('click', e => { 
    if(e.target.id === 'createModal') closeCreateModal();
    if(e.target.id === 'settingsModal') closeSettingsModal();
    if(e.target.id === 'dashMetaModal') closeDashMetaModal();
    if(e.target.id === 'agentMetaModal') closeAgentMetaModal();
    if(e.target.id === 'nativeModuleDetailModal') closeNativeModuleDetailModal();
});

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

function renderSingleModuleTableViewer(uniqueId, rawText, agentLabel) {
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

</script>
</body>
</html>


