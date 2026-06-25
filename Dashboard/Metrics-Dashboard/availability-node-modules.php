<?php
/* node-availability.php
 *
 * Dashboard Multi-Widget Availability (Host Alive / Ping Status)
 * - Version: 10.4 (STABLE: Removed 100 Agent Limit on Manual Selection)
 * - Support: Custom Label Parser, Export Pipe (|), Accurate TimeAgo, Pagination
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// 1. DYNAMIC BREADCRUMB
$dynamic_breadcrumb = "PANDORA CONSOLE / CUSTOM / PANEL / DASHBOARD";

// 2. CONFIG LOADING & DB INITIALIZATION
require_once __DIR__ . '/../../includes/db-connection.php';
$CONFIG_FILE = __DIR__ . '/availability-node-save.json';

if (session_status() === PHP_SESSION_NONE) session_start();
// Generate CSRF Token if not exists
if (empty($_SESSION['pfms_csrf_token'])) {
    $_SESSION['pfms_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';

// 3. HELPERS & DB INIT
require_once(__DIR__ . '/../../tools/utils.php');

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
if ($api === 'agents_list' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $list = [];
    
    // 1. Primary DB agents
    try {
        $stmt = $pdo->query("SELECT id_agente AS id, alias FROM tagente WHERE disabled = 0 ORDER BY alias ASC");
        while($a = $stmt->fetch()) {
            $list[] = ['id' => 'primary:' . $a['id'], 'alias' => pretty_text($a['alias'])];
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
                $stmt = $cpdo->query("SELECT id_agente AS id, alias FROM tagente WHERE disabled = 0 ORDER BY alias ASC");
                while($a = $stmt->fetch()) {
                    $list[] = ['id' => $cid . ':' . $a['id'], 'alias' => '[' . $cname . '] ' . pretty_text($a['alias'])];
                }
            } catch (Throwable $e) {}
        }
    }
    
    echo json_encode($list); exit;
}

if ($api === 'card_data' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $groupIdRaw = $_GET['group_id'] ?? '0';
    $keyword = $_GET['keyword'] ?? 'Host Alive';
    $limit = (int)($_GET['limit'] ?? 15);
    $manual_ids = $_GET['manual_ids'] ?? '';

    $lbl_ok = strtoupper($_GET['lbl_ok'] ?? '');
    $lbl_warn = strtoupper($_GET['lbl_warn'] ?? '');
    $lbl_crit = strtoupper($_GET['lbl_crit'] ?? '');

    $groupParsed = parse_node_id($groupIdRaw);
    $manualIdsParsed = parse_node_ids($manual_ids);

    global $custom_pdos, $custom_connections;
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
        $all_rows = [];

        foreach ($target_nodes as $node => $info) {
            $active_pdo = $info['pdo'];
            if ($active_pdo === null) continue;

            $node_params = ['%' . str_replace(' ', '%', $keyword) . '%'];
            $whereClause = "";
            if (!empty($info['agent_ids'])) {
                $whereClause = "AND a.id_agente IN (" . implode(',', array_fill(0, count($info['agent_ids']), '?')) . ")";
                foreach ($info['agent_ids'] as $id) { $node_params[] = (int)$id; } 
            } elseif ($info['group_id'] > 0) {
                $targetGroups = get_all_child_groups($active_pdo, $info['group_id']);
                $whereClause = "AND a.id_grupo IN (" . implode(',', array_fill(0, count($targetGroups), '?')) . ")";
                foreach ($targetGroups as $tg) { $node_params[] = $tg; }
            }

            // Fetch detailed statuses for this node
            $sql = "SELECT a.id_agente, a.alias AS agent_alias, g.nombre AS group_name, a.direccion AS ip_address, 
                           m.id_agente_modulo, m.nombre AS module_name, te.datos AS current_val, 
                           COALESCE(te.estado, 4) as estado, te.utimestamp AS last_contact
                    FROM tagente a 
                    INNER JOIN tagente_modulo m ON a.id_agente = m.id_agente 
                    INNER JOIN tagente_estado te ON m.id_agente_modulo = te.id_agente_modulo 
                    LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo 
                    WHERE m.nombre LIKE ? AND a.disabled = 0 AND m.disabled = 0 $whereClause";

            try {
                $stmt = $active_pdo->prepare($sql);
                $stmt->execute($node_params);
                $rows = $stmt->fetchAll();
                
                $node_label = '';
                if ($node !== 'primary') {
                    foreach ($custom_connections as $cc) {
                        if ($cc['id'] === $node) { $node_label = '[' . $cc['name'] . '] '; break; }
                    }
                    if (empty($node_label)) $node_label = '[' . $node . '] ';
                }

                foreach ($rows as $row) {
                    $row['node'] = $node;
                    $row['node_label'] = $node_label;
                    $all_rows[] = $row;
                }
            } catch (Throwable $e) {
                error_log("Error fetching card data for node '{$node}': " . $e->getMessage());
            }
        }

        // Apply status logic and construct formatted array
        $formatted_data = [];
        foreach ($all_rows as $row) {
            $rawVal = strtoupper((string)$row['current_val']);
            $v_estado = (int)$row['estado'];

            // Apply custom status logic
            if (preg_match('/^[01](\.0+)?$/', trim($rawVal))) {
                $numCheck = (float)$rawVal;
                $v_estado = ($numCheck >= 1) ? 0 : 1;
            }

            if ($lbl_ok !== '' && strpos($rawVal, $lbl_ok) !== false) $v_estado = 0;
            elseif ($lbl_crit !== '' && strpos($rawVal, $lbl_crit) !== false) $v_estado = 1;
            elseif ($lbl_warn !== '' && strpos($rawVal, $lbl_warn) !== false) $v_estado = 2;
            elseif ($lbl_ok === '' && $lbl_crit === '' && $lbl_warn === '' && stripos($keyword, 'Host Alive') === false) {
                if (strpos($rawVal, 'RUNNING') || strpos($rawVal, 'OK') || strpos($rawVal, 'UP')) $v_estado = 0;
                elseif (strpos($rawVal, 'STOPPED') || strpos($rawVal, 'ABENDED') || strpos($rawVal, 'DOWN') || strpos($rawVal, 'CRIT')) $v_estado = 1;
                elseif (strpos($rawVal, 'WARN')) $v_estado = 2;
            }

            if (stripos($keyword, 'Host Alive') !== false && $lbl_ok === '' && $lbl_crit === '') {
                $v_estado = (((float)$row['current_val'] >= 1) ? 0 : 1);
            }

            // Increment stats
            $stats['total']++;
            if ($v_estado === 0) $stats['normal']++;
            elseif ($v_estado === 1) $stats['critical']++;
            elseif ($v_estado === 2) $stats['warning']++;
            elseif ($v_estado === 4) $stats['not_init']++;
            else $stats['unknown']++;

            // For display formatting:
            $dispVal = $rawVal;
            if (preg_match('/^[01](\.0+)?$/', trim($dispVal))) {
                $numCheck = (float)$dispVal;
                if ($numCheck == 1 && $lbl_ok !== '') $dispVal = $lbl_ok;
                elseif ($numCheck == 0 && $lbl_crit !== '') $dispVal = $lbl_crit;
                elseif ($numCheck == 1) $dispVal = "UP";
                elseif ($numCheck == 0) $dispVal = "DOWN";
            }
            if (stripos($keyword, 'Host Alive') !== false && $lbl_ok === '' && $lbl_crit === '') {
                $dispVal = ($v_estado === 0) ? "UP" : "DOWN";
            }

            $ts = (int)$row['last_contact'];
            $time_ago = format_time_ago($ts);

            $formatted_data[] = [
                'agent_id'    => $row['node'] . ':' . $row['id_agente'],
                'agent_name'  => $row['node_label'] . pretty_text($row['agent_alias']),
                'group_name'  => pretty_text($row['group_name']),
                'ip_address'  => $row['ip_address'],
                'module_id'   => $row['node'] . ':' . $row['id_agente_modulo'],
                'module_name' => pretty_text($row['module_name']),
                'value'       => $dispVal,
                'estado'      => $v_estado,
                'utimestamp'  => $ts,
                'time_ago'    => $time_ago
            ];
        }

        // Apply Search Filtering on backend to ensure pagination is correct
        $search = trim($_GET['search'] ?? '');
        if ($search !== '') {
            $searchLower = strtolower($search);
            $formatted_data = array_filter($formatted_data, function($item) use ($searchLower) {
                return (strpos(strtolower($item['agent_name']), $searchLower) !== false) ||
                       (strpos(strtolower($item['module_name']), $searchLower) !== false) ||
                       (strpos(strtolower($item['ip_address']), $searchLower) !== false) ||
                       (strpos(strtolower($item['group_name']), $searchLower) !== false);
            });
        }

        // Sort by timestamp DESC
        usort($formatted_data, function($a, $b) {
            return $b['utimestamp'] <=> $a['utimestamp'];
        });

        $totalFound = count($formatted_data);

        // Pagination slice
        $page = (int)($_GET['page'] ?? 1);
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $limit;
        $paginated_data = ($limit > 0) ? array_slice($formatted_data, $offset, $limit) : $formatted_data;

        echo json_encode([
            'ok' => true, 
            'stats' => $stats, 
            'data' => array_values($paginated_data), 
            'total_found' => $totalFound, 
            'updated' => date('H:i:s')
        ]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

if ($api === 'status_details' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $groupIdRaw = $_GET['group_id'] ?? '0';
    $keyword = $_GET['keyword'] ?? 'Host Alive';
    $manual_ids = $_GET['manual_ids'] ?? '';
    $statusFilter = $_GET['status_filter'] ?? 'all';

    $lbl_ok = strtoupper($_GET['lbl_ok'] ?? '');
    $lbl_warn = strtoupper($_GET['lbl_warn'] ?? '');
    $lbl_crit = strtoupper($_GET['lbl_crit'] ?? '');

    $groupParsed = parse_node_id($groupIdRaw);
    $manualIdsParsed = parse_node_ids($manual_ids);

    global $custom_pdos, $custom_connections;
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
        $all_rows = [];
        foreach ($target_nodes as $node => $info) {
            $active_pdo = $info['pdo'];
            if ($active_pdo === null) continue;

            $node_params = ['%' . str_replace(' ', '%', $keyword) . '%'];
            $whereClause = "";
            if (!empty($info['agent_ids'])) {
                $whereClause = "AND a.id_agente IN (" . implode(',', array_fill(0, count($info['agent_ids']), '?')) . ")";
                foreach ($info['agent_ids'] as $id) { $node_params[] = (int)$id; } 
            } elseif ($info['group_id'] > 0) {
                $targetGroups = get_all_child_groups($active_pdo, $info['group_id']);
                $whereClause = "AND a.id_grupo IN (" . implode(',', array_fill(0, count($targetGroups), '?')) . ")";
                foreach ($targetGroups as $tg) { $node_params[] = $tg; }
            }

            $sql = "SELECT a.id_agente, a.alias AS agent_alias, g.nombre AS group_name, a.direccion AS ip_address, 
                           m.id_agente_modulo, m.nombre AS module_name, te.datos AS current_val, 
                           COALESCE(te.estado, 4) as estado, te.utimestamp AS last_contact
                    FROM tagente a 
                    INNER JOIN tagente_modulo m ON a.id_agente = m.id_agente 
                    INNER JOIN tagente_estado te ON m.id_agente_modulo = te.id_agente_modulo 
                    LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo 
                    WHERE m.nombre LIKE ? AND a.disabled = 0 AND m.disabled = 0 $whereClause";

            try {
                $stmt = $active_pdo->prepare($sql);
                $stmt->execute($node_params);
                $rows = $stmt->fetchAll();
                
                $node_label = '';
                if ($node !== 'primary') {
                    foreach ($custom_connections as $cc) {
                        if ($cc['id'] === $node) { $node_label = '[' . $cc['name'] . '] '; break; }
                    }
                    if (empty($node_label)) $node_label = '[' . $node . '] ';
                }

                foreach ($rows as $row) {
                    $row['node'] = $node;
                    $row['node_label'] = $node_label;
                    $all_rows[] = $row;
                }
            } catch (Throwable $e) {
                error_log("Error fetching status details for node '{$node}': " . $e->getMessage());
            }
        }

        $data = [];
        foreach ($all_rows as $row) {
            $rawVal = strtoupper((string)$row['current_val']);
            $v_estado = (int)$row['estado'];
            if (preg_match('/^[01](\.0+)?$/', trim($rawVal))) {
                $numCheck = (float)$rawVal;
                $v_estado = ($numCheck >= 1) ? 0 : 1;
            }
            if ($lbl_ok !== '' && strpos($rawVal, $lbl_ok) !== false) $v_estado = 0;
            elseif ($lbl_crit !== '' && strpos($rawVal, $lbl_crit) !== false) $v_estado = 1;
            elseif ($lbl_warn !== '' && strpos($rawVal, $lbl_warn) !== false) $v_estado = 2;
            elseif ($lbl_ok === '' && $lbl_crit === '' && $lbl_warn === '' && stripos($keyword, 'Host Alive') === false) {
                if (strpos($rawVal, 'RUNNING') || strpos($rawVal, 'OK') || strpos($rawVal, 'UP')) $v_estado = 0;
                elseif (strpos($rawVal, 'STOPPED') || strpos($rawVal, 'ABENDED') || strpos($rawVal, 'DOWN') || strpos($rawVal, 'CRIT')) $v_estado = 1;
                elseif (strpos($rawVal, 'WARN')) $v_estado = 2;
            }
            if (stripos($keyword, 'Host Alive') !== false && $lbl_ok === '' && $lbl_crit === '') {
                $v_estado = (((float)$row['current_val'] >= 1) ? 0 : 1);
                $rawVal = ($v_estado === 0) ? "UP" : "DOWN";
            }

            if ($statusFilter !== 'all') {
                if ($statusFilter === 'normal' && $v_estado !== 0) continue;
                if ($statusFilter === 'critical' && $v_estado !== 1) continue;
                if ($statusFilter === 'warning' && $v_estado !== 2) continue;
                if ($statusFilter === 'not_init' && $v_estado !== 4) continue;
                if ($statusFilter === 'unknown' && in_array($v_estado, [0,1,2,4])) continue;
            }

            // For display formatting:
            $dispVal = $rawVal;
            if (preg_match('/^[01](\.0+)?$/', trim($dispVal))) {
                $numCheck = (float)$dispVal;
                if ($numCheck == 1 && $lbl_ok !== '') $dispVal = $lbl_ok;
                elseif ($numCheck == 0 && $lbl_crit !== '') $dispVal = $lbl_crit;
                elseif ($numCheck == 1) $dispVal = "UP";
                elseif ($numCheck == 0) $dispVal = "DOWN";
            }
            if (stripos($keyword, 'Host Alive') !== false && $lbl_ok === '' && $lbl_crit === '') {
                $dispVal = ($v_estado === 0) ? "UP" : "DOWN";
            }

            $data[] = [
                'agent_id'    => $row['node'] . ':' . $row['id_agente'],
                'agent_name'  => $row['node_label'] . pretty_text($row['agent_alias']),
                'group_name'  => pretty_text($row['group_name']),
                'ip_address'  => $row['ip_address'],
                'module_name' => pretty_text($row['module_name']),
                'value'       => $dispVal,
                'estado'      => $v_estado,
                'utimestamp'  => (int)$row['last_contact'],
                'time_ago'    => format_time_ago((int)$row['last_contact'])
            ];
        }

        // Sort by timestamp DESC
        usort($data, function($a, $b) {
            return $b['utimestamp'] <=> $a['utimestamp'];
        });

        echo json_encode(['ok' => true, 'data' => array_values($data)]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

if ($api === 'export_data' && $db_status) {
    ob_clean();
    $startTs   = (int)$_GET['start'];
    $endTs     = (int)$_GET['end'];
    $format    = $_GET['format'] ?? 'csv';
    $keyword   = $_GET['keyword'] ?: 'Host Alive';
    
    $lbl_ok = strtoupper($_GET['lbl_ok'] ?? '');
    $lbl_warn = strtoupper($_GET['lbl_warn'] ?? '');
    $lbl_crit = strtoupper($_GET['lbl_crit'] ?? '');
    $isHostAlive = (stripos($keyword, 'Host Alive') !== false);

    try {
        $ids_by_node = parse_node_ids($_GET['agent_ids'] ?? '');
        $finalData = [];

        global $custom_pdos, $custom_connections;
        foreach ($ids_by_node as $node => $aids) {
            $active_pdo = ($node === 'primary') ? $pdo : ($custom_pdos[$node] ?? null);
            if ($active_pdo === null) continue;

            $node_label = '';
            if ($node !== 'primary') {
                foreach ($custom_connections as $cc) {
                    if ($cc['id'] === $node) { $node_label = '[' . $cc['name'] . '] '; break; }
                }
                if (empty($node_label)) $node_label = '[' . $node . '] ';
            }

            $agIds_placeholders = implode(',', array_fill(0, count($aids), '?'));
            $sqlMod = "SELECT m.id_agente_modulo, a.alias, a.direccion, g.nombre as gname, e.datos as cur_val, e.utimestamp as cur_ts, m.nombre as mname
                       FROM tagente_modulo m
                       INNER JOIN tagente a ON m.id_agente = a.id_agente
                       INNER JOIN tgrupo g ON a.id_grupo = g.id_grupo
                       INNER JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                       WHERE m.id_agente IN ($agIds_placeholders) AND m.nombre LIKE ?";
            
            $stMod = $active_pdo->prepare($sqlMod);
            $paramsMod = array_merge($aids, ["%$keyword%"]);
            $stMod->execute($paramsMod);
            $modules = $stMod->fetchAll();

            foreach ($modules as $mod) {
                // Fetch history from tagente_datos, tagente_datos_string, tagente_datos_inc
                $query = "SELECT utimestamp as ts, datos FROM tagente_datos WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? 
                          UNION ALL SELECT utimestamp as ts, datos FROM tagente_datos_string WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? 
                          UNION ALL SELECT utimestamp as ts, datos FROM tagente_datos_inc WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? 
                          ORDER BY ts ASC";
                $stData = $active_pdo->prepare($query);
                $stData->execute([$mod['id_agente_modulo'], $startTs, $endTs, $mod['id_agente_modulo'], $startTs, $endTs, $mod['id_agente_modulo'], $startTs, $endTs]);
                $rows = $stData->fetchAll();

                if (empty($rows)) {
                    $statusStr = strtoupper((string)$mod['cur_val']);
                    if (preg_match('/^[01](\.0+)?$/', trim($statusStr))) {
                        $numCheck = (float)$statusStr;
                        if ($numCheck == 1 && $lbl_ok !== '') $statusStr = $lbl_ok;
                        elseif ($numCheck == 0 && $lbl_crit !== '') $statusStr = $lbl_crit;
                        elseif ($numCheck == 1) $statusStr = "UP";
                        elseif ($numCheck == 0) $statusStr = "DOWN";
                    }
                    if ($isHostAlive && $lbl_ok === '' && $lbl_crit === '') $statusStr = (((float)$mod['cur_val'] >= 1) ? "UP" : "CRITICAL");
                    
                    $finalData[] = [
                        'ts' => date('Y-m-d H:i:s', $mod['cur_ts']),
                        'agent' => $node_label . pretty_text($mod['alias']),
                        'group' => pretty_text($mod['gname']),
                        'ip' => $mod['direccion'] ?: '-',
                        'module' => pretty_text($mod['mname']),
                        'status' => $statusStr
                    ];
                } else {
                    foreach ($rows as $r) {
                        $statusStr = strtoupper((string)$r['datos']);
                        if (preg_match('/^[01](\.0+)?$/', trim($statusStr))) {
                            $numCheck = (float)$statusStr;
                            if ($numCheck == 1 && $lbl_ok !== '') $statusStr = $lbl_ok;
                            elseif ($numCheck == 0 && $lbl_crit !== '') $statusStr = $lbl_crit;
                            elseif ($numCheck == 1) $statusStr = "UP";
                            elseif ($numCheck == 0) $statusStr = "DOWN";
                        }
                        if ($isHostAlive && $lbl_ok === '' && $lbl_crit === '') $statusStr = (((float)$r['datos'] >= 1) ? "UP" : "CRITICAL");
                        
                        $finalData[] = [
                            'ts' => date('Y-m-d H:i:s', $r['ts']),
                            'agent' => $node_label . pretty_text($mod['alias']),
                            'group' => pretty_text($mod['gname']),
                            'ip' => $mod['direccion'] ?: '-',
                            'module' => pretty_text($mod['mname']),
                            'status' => $statusStr
                        ];
                    }
                }
            }
        }

        if ($format === 'csv') {
            header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="Node_Status_Export_'.date('Ymd_His').'.csv"');
            echo "Timestamp|Node Agent|Group|IP Address|Module|Status\n";
            foreach($finalData as $d) echo "{$d['ts']}|{$d['agent']}|{$d['group']}|{$d['ip']}|{$d['module']}|{$d['status']}\n";
        } else {
            header('Content-Type: text/plain'); header('Content-Disposition: attachment; filename="Node_Status_Report_'.date('Ymd_His').'.txt"');
            echo "NODE AVAILABILITY MONITORING REPORT (Delimiter: |)\nGenerated: " . date('Y-m-d H:i:s') . "\n";
            echo str_repeat("-", 130) . "\n" . sprintf("%-20s | %-20s | %-20s | %-15s | %-25s | %-10s\n", "Timestamp", "Node Agent", "Group", "IP Address", "Module", "Status") . str_repeat("-", 130) . "\n";
            foreach($finalData as $d) echo sprintf("%-20s | %-20s | %-20s | %-15s | %-25s | %-10s\n", $d['ts'], substr($d['agent'],0,18), substr($d['group'],0,18), substr($d['ip'],0,14), substr($d['module'],0,23), $d['status']);
        }
    } catch (Exception $e) { echo "Export Error: " . $e->getMessage(); }
    exit;
}
$isStandalone = (isset($_GET['standalone']) && $_GET['standalone'] == '1') || (isset($_GET['s']) && $_GET['s'] == '1');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Node Availability Overview</title>
    <link rel="icon" href="<?= h($PANDORA_BASE_URL) ?>/images/pandora.ico" type="image/x-icon">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" />
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
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
        
        .grid-layout { columns: 2; column-gap: 20px; }
        @media (max-width: 1200px) { .grid-layout { columns: 1; } }

        .dashboard-card { background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: inline-block; width:100%; margin-bottom:20px; break-inside: avoid; vertical-align: top; overflow: hidden; border: 1px solid #f0f3f5; cursor: default; transition: transform 0.2s, box-shadow 0.2s; }
        .dashboard-card.dragging { opacity: 0.5; transform: scale(0.98); box-shadow: 0 10px 20px rgba(0,0,0,0.1); cursor: grabbing; }
        .dashboard-card.drag-over { border: 2px dashed #004d40; border-radius: 8px; }

        .dashboard-card-header { padding: 15px 20px; border-bottom: 1px solid #e0e4e8; background-color: #f8f9fa; display: flex; justify-content: space-between; align-items: center; cursor: grab; }
        .dashboard-card-header:active { cursor: grabbing; }
        .dashboard-card-title { font-size: 14px !important; font-weight: 500 !important; color: #1e293b !important; margin: 0; letter-spacing: 0.3px; display: flex; align-items: center; gap: 8px; pointer-events: none; }
        .dashboard-card-body { display: flex; flex-direction: column; flex-grow:1; overflow: hidden; }

        .mini-stats-row { display: flex; gap: 10px; width: 100%; flex-wrap: wrap; padding: 20px; border-bottom: 1px solid #e0e4e8;}
        .mini-stat {
            flex: 1; min-width: 90px; text-align: center; padding: 12px 5px; border-radius: 6px; 
            background: #ffffff; border: 1px solid #e0e4e8; border-bottom: 4px solid #ccc; 
            cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .mini-stat:hover { transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        .mini-stat-val { font-size: 22px !important; font-weight: normal !important; line-height: 1; margin-bottom: 5px; }
        .mini-stat-label { font-size: 9px !important; text-transform: uppercase; color: #7f8c8d; font-weight: normal !important; white-space: nowrap; }

        .st-border-black { border-bottom-color: #0b1a26; } .text-black { color: #0b1a26 !important; }
        .st-border-green { border-bottom-color: #2ecc71; } .text-green { color: #2ecc71 !important; }
        .st-border-red { border-bottom-color: #e74c3c; } .text-red { color: #e74c3c !important; }
        .st-border-yellow { border-bottom-color: #f1c40f; } .text-yellow { color: #f1c40f !important; }
        .st-border-gray { border-bottom-color: #95a5a6; } .text-gray { color: #334155 !important; }
        .st-border-blue { border-bottom-color: #3498db; } .text-blue { color: #3498db !important; }

        .table-wrap { overflow-x: auto; flex-grow: 1;}
        table.table-pfms { border-collapse: collapse !important; width: 100% !important; margin: 0 !important; }
        table.table-pfms thead th { background-color: #ffffff !important; border-bottom: 2px solid #e0e4e8 !important; text-transform: uppercase; padding: 12px 20px !important; font-weight: normal !important; color: #7f8c8d !important; font-size: 10px !important; position: sticky; top: 0; z-index: 1;}
        table.table-pfms tbody td { font-weight: normal !important; border-bottom: 1px solid #f0f3f5; padding: 12px 20px !important; color: #0b1a26 !important; white-space: normal; word-break: break-word; min-width: 100px; max-width: 350px; }

        .node-wrap { display: inline-flex; align-items: center; gap: 8px; line-height: 1.2; vertical-align: middle; white-space: normal; }
        .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; position: relative; top: -1px; }

        .bg-green { background: linear-gradient(135deg, #2ecc71, #27ae60) !important; color: #fff !important; }
        .bg-red { background: linear-gradient(135deg, #e74c3c, #c0392b) !important; color: #fff !important; }
        .bg-yellow { background: linear-gradient(135deg, #f1c40f, #f39c12) !important; color: #fff !important; }
        .bg-gray { background: linear-gradient(135deg, #95a5a6, #7f8c8d) !important; color: #fff !important; }
        .bg-blue { background: linear-gradient(135deg, #3498db, #2980b9) !important; color: #fff !important; }

        /* LOADING OVERLAY */
        #loadingOverlay { position: fixed; inset: 0; background: rgba(255,255,255,0.7); display: none; align-items: center; justify-content: center; z-index: 9999; flex-direction: column; gap: 15px; }
        .spinner { width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #004d40; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loading-text { font-weight: normal !important; color: #004d40; letter-spacing: 1px; text-transform: uppercase; font-size: 11px !important; }

        .agent-link { color: #1976d2 !important; text-decoration: none; font-weight: normal !important; font-size: 14px !important; word-break: break-word; }
        .ip-text { color: #d63384 !important; font-size: 11px !important; font-weight: normal; background:#fff0f6; padding:2px 6px; border-radius:4px;}
        .status-pill { padding: 4px 10px; border-radius: 4px; font-weight: normal !important; font-size: 10px !important; text-transform: uppercase; display: inline-block; min-width: 70px; text-align: center; white-space: normal; word-break: break-word; max-width: 150px; }

        .heatmap-wrap { display: flex; flex-wrap: wrap; gap: 8px; padding: 15px 20px; }
        .heat-box { min-width: 48px; height: 32px; border-radius: 4px; display: flex; align-items: center; justify-content: center; font-weight: normal !important; font-size: 9px !important; cursor: pointer; text-decoration: none !important; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-transform: uppercase; color: #ffffff !important; transition: 0.2s opacity; padding: 0 12px;}
        .heat-box:hover { opacity: 0.8; }

        .pagination-container { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background: #fafafa; border-top: 1px solid #e0e4e8; border-radius: 0 0 8px 8px; }
        .pagination-btn { background: #fff; border: 1px solid #dce1e5; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: normal; color: #4a5568; transition: 0.2s;}
        .pagination-btn:hover:not(:disabled) { background: #0b1a26; color: #fff; border-color: #0b1a26;}
        .pagination-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .search-input-header { width: 0; padding: 0; border: none; outline: none; background: transparent; transition: all 0.3s; font-size: 12px; font-weight: normal; color: #333; }
        .search-input-header.active { width: 150px; padding: 4px 10px; border-bottom: 2px solid #004d40; margin-right: 10px; background: #fff; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-box { background: #fff; width: 550px; padding: 25px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e0e4e8; max-height: 90vh; overflow-y: auto; }
        .detail-modal-box { width: 1000px !important; max-width: 95% !important; padding: 0; overflow: hidden; display: flex; flex-direction: column;}

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
    </style>
</head>
<body>

<div id="loadingOverlay">
    <div class="spinner"></div>
    <div class="loading-text">Synchronizing Data...</div>
</div>

<div class="pandora-header-top">
    <div class="header-left">
        <img src="<?= h($PANDORA_BASE_URL) ?>/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box"><span class="main-title">Pandora FMS</span><span class="sub-title">PFMS-Toolkit</span></div>
    </div>
    <div class="header-right"><a href="<?= h($PANDORA_BASE_URL) ?>/index.php" class="nav-icon-btn"><span class="material-symbols-outlined">home</span></a></div>
</div>

<div class="pandora-header-bottom">
    <div class="breadcrumb-box"><span class="page-breadcrumb"><?= h($dynamic_breadcrumb) ?></span><h1 class="page-title">Availability Node & Modules</h1></div>
    <div class="top-controls">
        <button class="btn-secondary-custom" onclick="exportDashboardConfig()"><span class="material-symbols-outlined">download</span> Backup</button>
        <button class="btn-secondary-custom" onclick="document.getElementById('importFile').click()"><span class="material-symbols-outlined">upload</span> Load Config</button>
        <input type="file" id="importFile" style="display:none" onchange="importDashboardConfig(event)">
        <button class="btn-apply" onclick="openBuilder()"><span class="material-symbols-outlined">add</span> Add Widget</button>
    </div>
</div>

<div class="main-content pt-4"><div class="grid-layout" id="dashboardGrid"></div></div>

<div class="modal-overlay" id="detailModal" style="z-index: 2050;">
    <div class="modal-box detail-modal-box">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:1px solid #e0e4e8; padding:20px 25px; background: #f8f9fa;">
            <div style="flex: 1;">
                <h5 style="font-weight: normal!important; text-transform:uppercase; margin:0; color:#0b1a26;" id="detailModalTitle">Node Details</h5>
                <div style="font-size:11px!important; color:#7f8c8d; margin-top:5px; font-weight: normal;">* Displays the list of nodes based on the status group you clicked.</div>
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
            <h5 style="font-weight: normal!important; text-transform:uppercase;">Bulk Export Availability</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeExport()">close</span>
        </div>
        <div class="form-group">
            <div style="display:flex; justify-content:space-between; align-items:center;"><label>SELECT AGENTS</label><button type="button" class="btn btn-sm text-primary p-0" style="font-size:10px!important; font-weight: normal; background:none; border:none; cursor:pointer;" onclick="toggleExportAll()">[ Select/Clear All ]</button></div>
            <div class="bulk-scroll" id="export_agent_list"></div>
        </div>
        <div class="form-group"><label>TIME RANGE</label><select id="e_range" class="form-control-fix" onchange="toggleCustomDate()"><option value="3600">Last 1 Hour</option><option value="86400" selected>Last 24 Hours</option><option value="604800">Last 7 Days</option><option value="custom">-- Custom Date Range --</option></select></div>
        <div id="custom_date_box" style="display:none; gap:10px; margin-bottom:15px;"><div style="flex:1;"><label style="font-size:10px;font-weight: normal;">Start Date</label><input type="datetime-local" id="e_start" class="form-control-fix"></div><div style="flex:1;"><label style="font-size:10px;font-weight: normal;">End Date</label><input type="datetime-local" id="e_end" class="form-control-fix"></div></div>
        <div class="form-group"><label>FORMAT EXPORT</label><select id="e_format" class="form-control-fix"><option value="csv">CSV (Pipe Delimited)</option><option value="txt">TXT (Visual Report)</option></select></div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;"><button class="btn-secondary-custom" onclick="closeExport()">Cancel</button><button class="btn-apply" onclick="processExport()">Download Report</button></div>
    </div>
</div>

<div class="modal-overlay" id="builderModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px;">
            <h5 style="font-weight: normal!important; text-transform:uppercase;" id="builderTitle">Build Widget</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;color:#7f8c8d;" onclick="closeBuilder()">close</span>
        </div>
        <div class="form-group"><label>Widget Title</label><input type="text" id="b_title" class="form-control-fix" placeholder="e.g. Core Ping Status"></div>

        <div class="row g-2 form-group">
            <div class="col-4"><label>Label OK (0/1)</label><input type="text" id="b_lbl_ok" class="form-control-fix" placeholder="UP"></div>
            <div class="col-4"><label>Label Warn (2)</label><input type="text" id="b_lbl_warn" class="form-control-fix" placeholder="WARNING"></div>
            <div class="col-4"><label>Label Crit (1/0)</label><input type="text" id="b_lbl_crit" class="form-control-fix" placeholder="CRITICAL"></div>
        </div>

        <div class="form-group">
            <label>Style View</label>
            <select id="b_view_type" class="form-control-fix">
                <option value="table">Table View (Detailed)</option>
                <option value="heatmap">Heatmap View (Grid Summary)</option>
                <option value="cards">Cards Status View (Stats Only)</option>
            </select>
        </div>

        <div class="form-group"><label>Filter By Group</label><select id="b_group" class="form-control-fix" onchange="toggleManualSelector()"></select></div>
        <div id="manual_selector_box" class="form-group" style="display:none;">
            <label>Select Agents</label>
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
        <div class="form-group"><label>Table Keyword (Module Name)</label><input type="text" id="b_keyword" class="form-control-fix" value="Host Alive"></div>
        <div style="display:flex; gap:15px;"><div style="flex:1;"><label>Rows Per Page (Limit)</label>
            <select id="b_limit" class="form-control-fix">
                <option value="15">15 Rows</option>
                <option value="25">25 Rows</option>
                <option value="50">50 Rows</option>
                <option value="100" selected>100 Rows (Max Recommended)</option>
            </select></div><div style="flex:1;"><label>Auto-Refresh</label><select id="b_refresh" class="form-control-fix"><option value="30">30s</option><option value="60" selected>1m</option><option value="300">5m</option></select></div></div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;"><button class="btn-secondary-custom" onclick="closeBuilder()">Cancel</button><button class="btn-apply" id="btnSaveWidget" onclick="saveWidget()">Save Widget</button></div>
    </div>
</div>

<script>
const PANDORA_URL = "<?= h($PANDORA_BASE_URL) ?>";
const IS_STANDALONE = <?= $isStandalone ? 'true' : 'false' ?>;
let dashboardCards = [], cardTimers = {}, globalTimerRef = null;
let fullAgentsList = [], selectedIds = [];

let cardDataStore = {};
let cardPages = {}; 
let cardSearch = {};

let modalBaseData = [];
let modalFilteredData = [];
let modalCurrentPage = 1;
const MODAL_PAGE_SIZE = 25; 

let dragSrcId = null;

const getStatusObj = (estado) => {
    switch(parseInt(estado)) {
        case 0: return { label: 'NORMAL', color: 'bg-green' };
        case 1: return { label: 'CRITICAL', color: 'bg-red' };
        case 2: return { label: 'WARNING', color: 'bg-yellow' };
        case 4: return { label: 'NOT INIT', color: 'bg-blue' };
        default: return { label: 'UNKNOWN', color: 'bg-gray' };
    }
};

async function init() {
    let loadedCards = [];
    try {
        const res = await fetch('?api=load_config');
        const data = await res.json();
        if(Array.isArray(data)) loadedCards = data;
    } catch(e){}

    if (IS_STANDALONE) {
        const p = new URLSearchParams(window.location.search);
        const cardId = p.get('d') || p.get('card_id');
        const targetCard = loadedCards.find(c => c.id === cardId);
        if (targetCard) {
            dashboardCards = [targetCard];
            document.title = targetCard.title + ' - Standalone View';
            const pageTitle = document.querySelector('.page-title');
            if(pageTitle) pageTitle.innerText = targetCard.title;
        } else {
            dashboardCards = [{ id:'std', title:p.get('title')||'Availability', group_id:p.get('group_id')||'0', keyword:p.get('keyword')||'Host Alive', limit:p.get('limit')||15, refresh_sec:p.get('refresh')||60, view_type:p.get('view_type')||'table', manual_ids:p.get('manual_ids')||'', lbl_ok:p.get('lbl_ok')||'', lbl_warn:p.get('lbl_warn')||'', lbl_crit:p.get('lbl_crit')||'' }];
            if(p.get('title')) {
                document.title = p.get('title') + ' - Standalone View';
                const pageTitle = document.querySelector('.page-title');
                if(pageTitle) pageTitle.innerText = p.get('title');
            }
        }
    } else {
        dashboardCards = loadedCards;
        loadGroups();
        fetch('?api=agents_list').then(r=>r.json()).then(data => { fullAgentsList = data; renderAgentDropdown(); });
    }
    renderGrid();
    dashboardCards.forEach(c => { cardTimers[c.id] = parseInt(c.refresh_sec); fetchCardData(c); });
    setInterval(runTimerLogic, 1000);
}

function runTimerLogic() {
    if(document.getElementById('detailModal') && document.getElementById('detailModal').style.display === 'flex') return;
    dashboardCards.forEach(c => {
        if(cardTimers[c.id] === undefined) cardTimers[c.id] = parseInt(c.refresh_sec);
        cardTimers[c.id]--;
        let m = document.getElementById(`meta_timer_${c.id}`); if(m) m.innerText = `(Refresh in ${cardTimers[c.id]}s)`;
        if(cardTimers[c.id] <= 0) { fetchCardData(c); cardTimers[c.id] = parseInt(c.refresh_sec); }
    });
}

function loadGroups() { fetch('?api=groups').then(r=>r.json()).then(data => { const sel = document.getElementById('b_group'); data.forEach(g => sel.add(new Option(g.name, g.id))); }); }
function toggleManualSelector() { document.getElementById('manual_selector_box').style.display = (document.getElementById('b_group').value === '0') ? 'block' : 'none'; }
function renderAgentDropdown() {
    const list = document.getElementById('agent_checkbox_list');
    list.innerHTML = fullAgentsList.map(a => `<div class="agent-item" data-name="${a.alias.toLowerCase()}"><input type="checkbox" id="chk_${a.id}" value="${a.id}" onchange="handleAgentCheck(this)"><label for="chk_${a.id}">${a.alias}</label></div>`).join('');
}
function filterAgentsInList() {
    const kw = document.getElementById('inner_search').value.toLowerCase();
    document.querySelectorAll('.agent-item').forEach(item => { item.style.display = item.dataset.name.includes(kw) ? 'flex' : 'none'; });
}
function handleAgentCheck(chk) {
    const val = chk.value;
    if (chk.checked) { if (!selectedIds.includes(val)) selectedIds.push(val); } else { selectedIds = selectedIds.filter(id => id !== val); }
    document.getElementById('sel_count').innerText = `${selectedIds.length} Selected`;
}
function toggleBuilderAgentAll() {
    const chks = document.querySelectorAll('#agent_checkbox_list input[type="checkbox"]');
    let visibleChks = [];
    chks.forEach(c => { if (c.closest('.agent-item').style.display !== 'none') visibleChks.push(c); });

    if (visibleChks.length === 0) return;
    const allChecked = visibleChks.every(c => c.checked);

    visibleChks.forEach(c => {
        c.checked = !allChecked;
        const val = c.value;
        if (c.checked) { if (!selectedIds.includes(val)) selectedIds.push(val); } else { selectedIds = selectedIds.filter(id => id !== val); }
    });

    document.getElementById('sel_count').innerText = `${selectedIds.length} Selected`;
}

function toggleSearchInput(cardId) { 
    const input = document.getElementById(`search_inp_${cardId}`); 
    input.classList.toggle('active'); 
    if(input.classList.contains('active')) input.focus(); 
}
function filterTableRows(cardId) {
    cardSearch[cardId] = document.getElementById(`search_inp_${cardId}`).value.toLowerCase().trim();
    cardPages[cardId] = 1; 
    const card = dashboardCards.find(c => c.id === cardId);
    fetchCardData(card);
}

function renderGrid() {
    const grid = document.getElementById('dashboardGrid'); grid.innerHTML = '';
    dashboardCards.forEach(c => {
        const div = document.createElement('div'); div.className = 'dashboard-card'; div.id = 'box_'+c.id;
        
        if (!IS_STANDALONE) {
            div.draggable = true;
            div.ondragstart = (e) => handleDragStart(e, c.id);
            div.ondragover = (e) => handleDragOver(e);
            div.ondragleave = (e) => handleDragLeave(e);
            div.ondrop = (e) => handleDrop(e, c.id);
            div.ondragend = (e) => handleDragEnd(e);
        }

        let searchBtn = c.view_type === 'cards' ? '' : `<input type="text" id="search_inp_${c.id}" class="search-input-header" placeholder="Cari module..." onkeyup="filterTableRows('${c.id}')"><button class="icon-btn-card" onclick="toggleSearchInput('${c.id}')" title="Search Table"><span class="material-symbols-outlined">search</span></button>`;
        
        let acts = `<div class="card-actions">
            ${searchBtn}
            <button class="icon-btn-card" onclick="openExport('${c.id}')" title="Export CSV/TXT"><span class="material-symbols-outlined">download</span></button>
            ${!IS_STANDALONE ? `
            <button class="icon-btn-card" onclick='copyStandaloneUrl(${JSON.stringify(c)})' title="Share Widget"><span class="material-symbols-outlined">share</span></button>
            <button class="icon-btn-card" onclick="duplicatePanel('${c.id}')" title="Duplicate"><span class="material-symbols-outlined">content_copy</span></button>
            <button class="icon-btn-card" onclick="openEdit('${c.id}')" title="Edit"><span class="material-symbols-outlined">edit</span></button>
            <button class="icon-btn-card" onclick="deleteCard('${c.id}')" title="Delete"><span class="material-symbols-outlined" style="color:#e74c3c;">delete</span></button>
            ` : `
            <button class="icon-btn-card" onclick="openEdit('${c.id}')" title="Edit"><span class="material-symbols-outlined">edit</span></button>
            `}
        </div>`;
        
        let lblOk = c.lbl_ok || 'UP';
        let lblWarn = c.lbl_warn || 'WARNING';
        let lblCrit = c.lbl_crit || 'CRITICAL';

        div.innerHTML = `
            <div class="dashboard-card-header">
                <div><h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="color:#004d40;">fact_check</span> ${c.title}</h5><div style="font-size:10px; color:#7f8c8d; font-weight: normal;"><span id="meta_up_${c.id}">Awaiting...</span> <span id="meta_timer_${c.id}"></span></div></div>
                ${acts}
            </div>
            <div class="dashboard-card-body">
                <div class="mini-stats-row">
                    <div class="mini-stat st-border-black" onclick="showDetailModal('${c.id}', 'all')"><div class="mini-stat-val text-black" id="st_tot_${c.id}">0</div><div class="mini-stat-label">TOTAL</div></div>
                    <div class="mini-stat st-border-green" onclick="showDetailModal('${c.id}', 'normal')"><div class="mini-stat-val text-green" id="st_norm_${c.id}">0</div><div class="mini-stat-label">${lblOk.toUpperCase()}</div></div>
                    <div class="mini-stat st-border-red" onclick="showDetailModal('${c.id}', 'critical')"><div class="mini-stat-val text-red" id="st_crit_${c.id}">0</div><div class="mini-stat-label">${lblCrit.toUpperCase()}</div></div>
                    <div class="mini-stat st-border-yellow" onclick="showDetailModal('${c.id}', 'warning')"><div class="mini-stat-val text-yellow" id="st_warn_${c.id}">0</div><div class="mini-stat-label">${lblWarn.toUpperCase()}</div></div>
                    <div class="mini-stat st-border-gray" onclick="showDetailModal('${c.id}', 'unknown')"><div class="mini-stat-val text-gray" id="st_unk_${c.id}">0</div><div class="mini-stat-label">UNKNOWN</div></div>
                    <div class="mini-stat st-border-blue" onclick="showDetailModal('${c.id}', 'not_init')"><div class="mini-stat-val text-blue" id="st_not_init_${c.id}">0</div><div class="mini-stat-label">NOT INIT</div></div>
                </div>
                <div id="content_view_${c.id}"></div>
            </div>`;
        grid.appendChild(div);
    });
}

function fetchCardData(card) {
    document.getElementById('loadingOverlay').style.display = 'flex';
    const page = cardPages[card.id] || 1;
    const search = cardSearch[card.id] || '';
    const url = `?api=card_data&group_id=${card.group_id}&keyword=${encodeURIComponent(card.keyword)}&limit=${card.limit}&manual_ids=${card.manual_ids || ''}&lbl_ok=${encodeURIComponent(card.lbl_ok||'')}&lbl_warn=${encodeURIComponent(card.lbl_warn||'')}&lbl_crit=${encodeURIComponent(card.lbl_crit||'')}&page=${page}&search=${encodeURIComponent(search)}`;

    fetch(url).then(r=>r.json()).then(res => {
        document.getElementById('loadingOverlay').style.display = 'none';
        if(!res.ok) return;
        document.getElementById(`meta_up_${card.id}`).innerText = `Last updated: ${res.updated}`;
        
        cardDataStore[card.id] = res.data;
        cardPages[card.id] = page;
        card.totalFound = res.total_found;

        if (!cardSearch[card.id]) cardSearch[card.id] = '';

        if(document.getElementById(`st_tot_${card.id}`)) {
            document.getElementById(`st_tot_${card.id}`).innerText = res.stats.total;
            document.getElementById(`st_norm_${card.id}`).innerText = res.stats.normal;
            document.getElementById(`st_crit_${card.id}`).innerText = res.stats.critical;
            document.getElementById(`st_warn_${card.id}`).innerText = res.stats.warning;
            document.getElementById(`st_unk_${card.id}`).innerText = res.stats.unknown;
            document.getElementById(`st_not_init_${card.id}`).innerText = res.stats.not_init;
        }

        const container = document.getElementById(`content_view_${card.id}`);
        if (card.view_type === 'cards') {
            container.style.display = 'none';
        } else {
            container.style.display = 'block';
            renderTablePage(card.id);
        }
    });
}

function renderTablePage(cardId) {
    let data = cardDataStore[cardId] || [];
    const card = dashboardCards.find(c => c.id === cardId);
    if(!card) return;

    const kw = cardSearch[cardId];
    if (kw) {
        data = data.filter(r => 
            r.agent_name.toLowerCase().includes(kw) || 
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

    const limit = parseInt(card.limit);
    const pageSize = (limit === 0) ? 20 : limit; 
    const totalFound = card.totalFound || data.length;
    const totalPages = Math.ceil(totalFound / pageSize) || 1;
    
    let currentPage = cardPages[cardId] || 1;
    const startIdx = (currentPage - 1) * pageSize;
    const endIdx = startIdx + data.length;

    let h = '';
    
    if (card.view_type === 'heatmap') {
        h += '<div class="heatmap-wrap" style="border: 1px solid #f0f3f5; border-radius: 6px;">';
        data.forEach(r => {
            const sObj = getStatusObj(r.estado);
            let bgColor = sObj.color;
            let heatLbl = r.value.length > 8 ? r.value.substring(0, 8) : r.value;
            const isPrimary = String(r.agent_id).startsWith('primary:');
            let heatBoxHtml = '';
            if (isPrimary) {
                const rawAgentId = String(r.agent_id).split(':')[1] || r.agent_id;
                heatBoxHtml = `<a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${rawAgentId}" target="_blank" class="heat-box ${bgColor}" title="Agent: ${r.agent_name}\nModule: ${r.module_name}\nStatus: ${r.value}\nUpdate: ${r.time_ago}">${heatLbl}</a>`;
            } else {
                heatBoxHtml = `<span class="heat-box ${bgColor}" title="Agent: ${r.agent_name} (Custom Node)\nModule: ${r.module_name}\nStatus: ${r.value}\nUpdate: ${r.time_ago}">${heatLbl}</span>`;
            }
            h += heatBoxHtml;
        });
        h += '</div>';
    } 
    else {
        h += '<div class="table-wrap"><table class="table-pfms"><thead><tr><th>Node Agent</th><th>IP Address</th><th>Group</th><th>Module Name</th><th>Status</th><th>Last Update</th></tr></thead><tbody>';
        data.forEach(r => {
            const sObj = getStatusObj(r.estado);
            let bgColor = sObj.color;
            const isPrimary = String(r.agent_id).startsWith('primary:');
            let agentLinkHtml = '';
            if (isPrimary) {
                const rawAgentId = String(r.agent_id).split(':')[1] || r.agent_id;
                agentLinkHtml = `<a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${rawAgentId}" target="_blank" class="agent-link">${r.agent_name}</a>`;
            } else {
                agentLinkHtml = `<span class="agent-link-text" style="font-weight:600; color:#334155;">${r.agent_name}</span>`;
            }
            h += `<tr>
                    <td><div class="node-wrap"><div class="dot ${bgColor}"></div>${agentLinkHtml}</div></td>
                    <td><code class="ip-text">${r.ip_address||'-'}</code></td>
                    <td style="color:#7f8c8d">${r.group_name}</td>
                    <td style="color:#0b1a26; font-weight: normal;">${r.module_name}</td>
                    <td><span class="status-pill ${bgColor}">${r.value || sObj.label}</span></td>
                    <td style="color:#7f8c8d; font-size:11px!important; font-weight: normal;">${r.time_ago}</td>
                  </tr>`;
        });
        h += '</tbody></table></div>';
    }

    if (totalPages > 1) {
        h += `
            <div class="pagination-container">
                <div style="font-size:11px; font-weight: normal; color:#7f8c8d;">Showing ${startIdx + 1} to ${endIdx} of ${totalFound} Entries</div>
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
    const card = dashboardCards.find(c => c.id === cardId);
    fetchCardData(card);
}

async function showDetailModal(cardId, statusFilter) {
    const card = dashboardCards.find(c => c.id === cardId);
    if (!card) return;

    document.getElementById('loadingOverlay').style.display = 'flex';
    const url = `?api=status_details&group_id=${card.group_id}&keyword=${encodeURIComponent(card.keyword)}&manual_ids=${card.manual_ids || ''}&lbl_ok=${encodeURIComponent(card.lbl_ok||'')}&lbl_warn=${encodeURIComponent(card.lbl_warn||'')}&lbl_crit=${encodeURIComponent(card.lbl_crit||'')}&status_filter=${statusFilter}`;
    
    try {
        const res = await fetch(url).then(r => r.json());
        document.getElementById('loadingOverlay').style.display = 'none';
        if (!res.ok) return alert("Error fetching details: " + res.error);

        document.getElementById('detailModalSearch').value = '';
        modalBaseData = res.data;
        
        let title = "Node Details";
        const labels = { 'all': 'All', 'normal': (card.lbl_ok || 'NORMAL'), 'critical': (card.lbl_crit || 'CRITICAL'), 'warning': (card.lbl_warn || 'WARNING'), 'not_init': 'NOT INIT', 'unknown': 'UNKNOWN' };
        title = (labels[statusFilter] || statusFilter.toUpperCase()) + " Nodes";

        modalFilteredData = [...modalBaseData];
        modalCurrentPage = 1;
        document.getElementById('detailModalTitle').innerText = `${title} (${modalBaseData.length} rows)`;
        document.getElementById('detailModal').style.display = 'flex';
        
        renderDetailModalPage();
    } catch (e) {
        document.getElementById('loadingOverlay').style.display = 'none';
        alert("Fetch Error: " + e.message);
    }
}

function filterDetailModal() {
    const kw = document.getElementById('detailModalSearch').value.toLowerCase().trim();
    if (!kw) {
        modalFilteredData = [...modalBaseData];
    } else {
        modalFilteredData = modalBaseData.filter(r => 
            r.agent_name.toLowerCase().includes(kw) || 
            r.module_name.toLowerCase().includes(kw) || 
            (r.ip_address && r.ip_address.toLowerCase().includes(kw)) || 
            (r.group_name && r.group_name.toLowerCase().includes(kw))
        );
    }
    modalCurrentPage = 1;
    renderDetailModalPage();
}

function renderDetailModalPage() {
    const total = modalFilteredData.length;
    const totalPages = Math.ceil(total / MODAL_PAGE_SIZE) || 1;
    if(modalCurrentPage > totalPages) modalCurrentPage = totalPages;
    
    const startIdx = (modalCurrentPage - 1) * MODAL_PAGE_SIZE;
    const endIdx = Math.min(startIdx + MODAL_PAGE_SIZE, total);
    const pageData = modalFilteredData.slice(startIdx, endIdx);

    let h = '<div style="padding:0; max-height:60vh; overflow-y:auto;"><table class="table-pfms"><thead><tr><th>Node Agent</th><th>IP Address</th><th>Group</th><th>Module Name</th><th>Status</th><th>Last Update</th></tr></thead><tbody>';

    if(pageData.length === 0) {
         h += '<tr><td colspan="6" style="text-align:center; padding: 25px; color:#7f8c8d; font-weight: normal;">No modules found.</td></tr>';
    } else {
        pageData.forEach(r => {
            const sObj = getStatusObj(r.estado);
            let bgColor = sObj.color;
            const isPrimary = String(r.agent_id).startsWith('primary:');
            let agentLinkHtml = '';
            if (isPrimary) {
                const rawAgentId = String(r.agent_id).split(':')[1] || r.agent_id;
                agentLinkHtml = `<a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${rawAgentId}" target="_blank" class="agent-link">${r.agent_name}</a>`;
            } else {
                agentLinkHtml = `<span class="agent-link-text" style="font-weight:600; color:#334155;">${r.agent_name}</span>`;
            }
            h += `<tr>
                    <td><div class="node-wrap"><div class="dot ${bgColor}"></div>${agentLinkHtml}</div></td>
                    <td><code class="ip-text">${r.ip_address||'-'}</code></td>
                    <td style="color:#7f8c8d">${r.group_name}</td>
                    <td style="color:#0b1a26; font-weight: normal;">${r.module_name}</td>
                    <td><span class="status-pill ${bgColor}">${r.value || sObj.label}</span></td>
                    <td style="color:#7f8c8d; font-size:11px!important; font-weight: normal;">${r.time_ago}</td>
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

// EXPORT LOGIC
let curExpCardId = null;
function openExport(cardId) { 
    curExpCardId = cardId; 
    const data = cardDataStore[cardId] || [];
    const list = document.getElementById('export_agent_list');
    if (list) {
        const uniqueAgents = [];
        const seen = new Set();
        data.forEach(d => {
            if (!seen.has(d.agent_id)) {
                seen.add(d.agent_id);
                uniqueAgents.push({ id: d.agent_id, name: d.agent_name });
            }
        });
        uniqueAgents.sort((a,b) => a.name.localeCompare(b.name));
        list.innerHTML = uniqueAgents.map(a => `
            <div class="bulk-item">
                <input type="checkbox" class="exp-chk" value="${a.id}" id="exp_${a.id}" checked>
                <label for="exp_${a.id}" style="font-size:12px; margin:0; cursor:pointer;">${a.name}</label>
            </div>`).join('');
    }
    document.getElementById('exportModal').style.display='flex'; 
}

function toggleExportAll() {
    const chks = document.querySelectorAll('.exp-chk');
    const allChecked = Array.from(chks).every(c => c.checked);
    chks.forEach(c => c.checked = !allChecked);
}
function toggleCustomDate() { document.getElementById('custom_date_box').style.display = (document.getElementById('e_range').value === 'custom') ? 'flex' : 'none'; }
function processExport() {
    const range = document.getElementById('e_range').value;
    let start, end;
    if(range === 'custom') {
        const sVal = document.getElementById('e_start').value; const eVal = document.getElementById('e_end').value;
        if(!sVal || !eVal) return alert("Select dates.");
        start = Math.floor(new Date(sVal).getTime() / 1000); end = Math.floor(new Date(eVal).getTime() / 1000);
    } else { end = Math.floor(Date.now() / 1000); start = end - parseInt(range); }
    
    const checkedChks = document.querySelectorAll('.exp-chk:checked');
    if (checkedChks.length === 0) return alert("Select at least one agent.");
    const agIds = Array.from(checkedChks).map(c => c.value).join(',');
    
    const c = dashboardCards.find(card => card.id == curExpCardId);
    const url = `?api=export_data&agent_ids=${agIds}&keyword=${encodeURIComponent(c.keyword)}&start=${start}&end=${end}&lbl_ok=${encodeURIComponent(c.lbl_ok||'')}&lbl_warn=${encodeURIComponent(c.lbl_warn||'')}&lbl_crit=${encodeURIComponent(c.lbl_crit||'')}&format=${document.getElementById('e_format').value}`;
    window.open(url, '_blank');
    document.getElementById('exportModal').style.display = 'none';
}
function closeExport() { document.getElementById('exportModal').style.display = 'none'; }

function openBuilder() { editingCardId=null; document.getElementById('b_title').value=''; document.getElementById('b_keyword').value='Host Alive'; document.getElementById('b_lbl_ok').value=''; document.getElementById('b_lbl_warn').value=''; document.getElementById('b_lbl_crit').value=''; document.getElementById('b_group').value='0'; document.querySelectorAll('#agent_checkbox_list input').forEach(c => c.checked = false); document.getElementById('sel_count').innerText = "0 Selected"; toggleManualSelector(); document.getElementById('builderModal').style.display='flex'; }
function openEdit(id) { editingCardId=id; const c = dashboardCards.find(x=>x.id===id); document.getElementById('builderTitle').innerText='Edit Widget'; ['title','view_type','group','keyword','limit','refresh'].forEach(k => document.getElementById('b_'+k).value = c[k==='group'?'group_id':(k==='refresh'?'refresh_sec':k)]); document.getElementById('b_lbl_ok').value = c.lbl_ok || ''; document.getElementById('b_lbl_warn').value = c.lbl_warn || ''; document.getElementById('b_lbl_crit').value = c.lbl_crit || ''; selectedIds = c.manual_ids ? String(c.manual_ids).split(',') : []; document.querySelectorAll('#agent_checkbox_list input').forEach(chk => { chk.checked = selectedIds.includes(chk.value); }); document.getElementById('sel_count').innerText = selectedIds.length + " Selected"; toggleManualSelector(); document.getElementById('builderModal').style.display='flex'; }
function closeBuilder() { document.getElementById('builderModal').style.display='none'; }

function saveWidget() {
    const card = { id: editingCardId || 'c'+Date.now(), title: document.getElementById('b_title').value||'Availability', view_type: document.getElementById('b_view_type').value, group_id: document.getElementById('b_group').value, keyword: document.getElementById('b_keyword').value, limit: document.getElementById('b_limit').value, refresh_sec: document.getElementById('b_refresh').value, manual_ids: selectedIds.join(','), lbl_ok: document.getElementById('b_lbl_ok').value, lbl_warn: document.getElementById('b_lbl_warn').value, lbl_crit: document.getElementById('b_lbl_crit').value };
    let tempCards = editingCardId ? dashboardCards.map(x=>x.id===editingCardId?card:x) : [...dashboardCards, card];
    const btn = document.getElementById("btnSaveWidget"); btn.innerHTML = 'Saving...'; btn.disabled = true;

    fetch('?api=save_config',{method:'POST', body:JSON.stringify(tempCards), headers: {'X-CSRF-TOKEN': '<?= $csrf_token ?>'}}).then(r=>r.json()).then(res=>{
        if(res.ok) { dashboardCards = tempCards; renderGrid(); fetchCardData(card); closeBuilder(); } 
        else { 
            let errMsg = res.error && res.error.message ? res.error.message : res.error;
            alert(`FAILED TO SAVE WIDGET!\n\nSystem rejected save with reason:\n${errMsg || 'Unknown Error'}\n\nFile Target: ${res.file || 'File permission issue'}`); 
        }
    }).finally(()=>{ btn.innerHTML = 'Save Widget'; btn.disabled = false; });
}
function deleteCard(id) { if(confirm('Delete?')){ let tempCards = dashboardCards.filter(x=>x.id!==id); fetch('?api=save_config',{method:'POST', body:JSON.stringify(tempCards), headers: {'X-CSRF-TOKEN': '<?= $csrf_token ?>'}}).then(r=>r.json()).then(res=>{ if(res.ok){ dashboardCards=tempCards; renderGrid(); }else{ alert("Failed to delete! Check file permissions."); } }); } }

function copyStandaloneUrl(card) {
    const u = new URL(window.location.origin + window.location.pathname);
    u.searchParams.set('s', '1');
    u.searchParams.set('d', card.id);
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
    const card = dashboardCards.find(x => x.id === id);
    if (!card) return;
    const newCard = JSON.parse(JSON.stringify(card));
    newCard.id = 'c' + Date.now();
    newCard.title = newCard.title + " (Copy)";
    const tempCards = [...dashboardCards, newCard];
    const btn = document.querySelector(`#box_${id} .icon-btn-card[title="Duplicate"]`);
    if(btn) btn.style.opacity = '0.5';

    fetch('?api=save_config', {
        method: 'POST',
        body: JSON.stringify(tempCards),
        headers: { 'X-CSRF-TOKEN': '<?= $csrf_token ?>' }
    }).then(r => r.json()).then(res => {
        if (res.ok) {
            dashboardCards = tempCards;
            renderGrid();
            dashboardCards.forEach(c => fetchCardData(c));
        } else {
            alert("Failed to duplicate widget.");
        }
    }).finally(() => { if(btn) btn.style.opacity = '1'; });
}

function exportDashboardConfig() { const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(dashboardCards, null, 2)); const dlAnchorElem = document.createElement('a'); dlAnchorElem.setAttribute("href", dataStr); dlAnchorElem.setAttribute("download", "availability_node_config_backup.json"); dlAnchorElem.click(); }
function importDashboardConfig(event) { const file = event.target.files[0]; if (!file) return; const reader = new FileReader(); reader.onload = function(e) { try { const loaded = JSON.parse(e.target.result); if (Array.isArray(loaded)) { fetch('?api=save_config', { method: 'POST', body: JSON.stringify(loaded), headers: {'X-CSRF-TOKEN': '<?= $csrf_token ?>'} }).then(r => r.json()).then(res => { if (res.ok) { dashboardCards = loaded; renderGrid(); dashboardCards.forEach(c => fetchCardData(c)); alert("Config loaded!"); } else { alert(`Failed to save import. Reason: ${res.error?.message || res.error || 'Unknown Error'}`); } }); } } catch (err) { alert("Invalid JSON file."); } }; reader.readAsText(file); }

// =====================================================================
// DRAG AND DROP HANDLERS
// =====================================================================

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
    fetch('?api=save_config', {
        method: 'POST',
        body: JSON.stringify(dashboardCards),
        headers: { 'X-CSRF-TOKEN': '<?= $csrf_token ?>' }
    }).then(r => r.json()).then(res => {
        if (!res.ok) console.error("Failed to save widget order.");
    });
}

init();
</script>
</body>
</html>


