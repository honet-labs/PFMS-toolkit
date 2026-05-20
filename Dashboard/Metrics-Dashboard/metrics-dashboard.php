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
$PANDORA_BASE_URL = "/pandora_console";
$CONFIG_FILE = __DIR__ . '/metrics_config.json';

$config_paths = ['/var/www/html/pandora_console/include/config.php', '../../../include/config.php', '../../include/config.php', '../include/config.php'];
$config_loaded = false;
foreach ($config_paths as $path) { if (file_exists($path)) { require_once($path); $config_loaded = true; break; } }

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';

// 3. HELPERS & DB INIT
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pretty_text($s) {
    if ($s === null) return '';
    $decoded = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
    return str_replace('&#x20;', ' ', $decoded);
}
function timeAgo($datetime) {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00') return 'N/A';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return "Just now";
    if ($diff < 3600) return round($diff / 60) . " min";
    if ($diff < 86400) return round($diff / 3600) . " hours";
    return round($diff / 86400) . " days";
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

$pdo = null; $db_status = false; $db_error = '';
if ($config_loaded) {
    try {
        $dsn = "mysql:host=" . $config["dbhost"] . ";dbname=" . $config["dbname"] . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $config["dbuser"], $config["dbpass"], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_PERSISTENT => false
        ]);
        $db_status = true;
    } catch (PDOException $e) { $db_error = $e->getMessage(); }
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
    $stmt = $pdo->query("SELECT id_grupo AS id, nombre AS name FROM tgrupo ORDER BY name ASC");
    $dropdown = [['id' => '0', 'name' => '--- Manual Selection / All ---']];
    while($g = $stmt->fetch()) { $dropdown[] = ['id' => $g['id'], 'name' => pretty_text($g['name'])]; }
    echo json_encode($dropdown); exit;
}
if ($api === 'agents_list' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT id_agente AS id, alias FROM tagente WHERE disabled = 0 ORDER BY alias ASC");
    $list = $stmt->fetchAll();
    foreach($list as &$l) { $l['alias'] = pretty_text($l['alias']); }
    echo json_encode($list); exit;
}

if ($api === 'detail_graph' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $id_mod = (int)$_GET['id_mod'];
    $range = $_GET['range'] ?? '21600';

    if ($range === 'custom') {
        $start = (int)$_GET['start'];
        $end = (int)$_GET['end'];
    } else {
        $end = time();
        $start = $end - (int)$range;
    }

    try {
        $query = "SELECT FROM_UNIXTIME(utimestamp, '%Y-%m-%d %H:%i') as waktu, datos
                  FROM tagente_datos
                  WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ?
                  ORDER BY utimestamp ASC LIMIT 1500";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id_mod, $start, $end]);
        $data = $stmt->fetchAll();
        echo json_encode(['ok' => true, 'data' => $data]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// V10.6: NEW ENDPOINT specifically for getting 15-points Sparkline on requested IDs only
if ($api === 'sparklines' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $ids_raw = $_GET['ids'] ?? '';
    $ids_array = array_filter(explode(',', $ids_raw));
    
    if (empty($ids_array)) { echo json_encode(['ok' => true, 'data' => []]); exit; }
    
    $placeholders = implode(',', array_fill(0, count($ids_array), '?'));
    // Fallback support if ROW_NUMBER() is not supported by MySQL version
    $graphQuery = "SELECT id_agente_modulo, datos FROM (
                    SELECT id_agente_modulo, datos, 
                           ROW_NUMBER() OVER(PARTITION BY id_agente_modulo ORDER BY utimestamp DESC) as rn 
                    FROM tagente_datos WHERE id_agente_modulo IN ($placeholders)
                   ) sub WHERE rn <= 15 ORDER BY id_agente_modulo, rn DESC";
                   
    $miniGraphsData = [];
    try {
        $stmtGraph = $pdo->prepare($graphQuery);
        $stmtGraph->execute($ids_array);
        $bulkGraphs = $stmtGraph->fetchAll();
        foreach ($bulkGraphs as $bg) {
            $miniGraphsData[$bg['id_agente_modulo']][] = (float)$bg['datos'];
        }
        echo json_encode(['ok' => true, 'data' => $miniGraphsData]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

if ($api === 'card_data' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $groupId = (int)($_GET['group_id'] ?? 0);
    $keyword = $_GET['keyword'] ?? '%';
    $manual_ids = $_GET['manual_ids'] ?? '';

    try {
        $params = ['%' . str_replace(' ', '%', $keyword) . '%']; $whereClause = "";
        if (!empty($manual_ids) && $groupId == 0) {
            $ids_array = array_filter(explode(',', $manual_ids));
            if (!empty($ids_array)) {
                $whereClause = "AND a.id_agente IN (" . implode(',', array_fill(0, count($ids_array), '?')) . ")";
                foreach ($ids_array as $id) { $params[] = (int)$id; }
            }
        } elseif ($groupId > 0) {
            $stmtAllGroups = $pdo->query("SELECT id_grupo, parent FROM tgrupo"); $allGroups = $stmtAllGroups->fetchAll();
            function getChildGroups($parentId, $allGroups) { $children = [$parentId]; foreach ($allGroups as $g) { if ($g['parent'] == $parentId) { $children = array_merge($children, getChildGroups($g['id_grupo'], $allGroups)); } } return array_unique($children); }
            $targetGroups = getChildGroups($groupId, $allGroups);
            $whereClause = "AND a.id_grupo IN (" . implode(',', array_fill(0, count($targetGroups), '?')) . ")";
            foreach ($targetGroups as $tg) { $params[] = $tg; }
        }

        $sqlCommon = "FROM tagente_modulo m
                      INNER JOIN tagente a ON m.id_agente = a.id_agente
                      LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                      LEFT JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                      WHERE m.nombre LIKE ? AND a.disabled = 0 AND m.disabled = 0 $whereClause";

        // 1. Get Grand Total Stats (Decoupled from table limits/fetching)
        $sqlStats = "SELECT COALESCE(e.estado, 4) as status, COUNT(*) as count 
                     $sqlCommon 
                     GROUP BY status";
        $stStats = $pdo->prepare($sqlStats);
        $stStats->execute($params);
        $statsRows = $stStats->fetchAll();

        $stats = ['total'=>0, 'normal'=>0, 'critical'=>0, 'warning'=>0, 'unknown'=>0, 'not_init'=>0];
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

        // 2. Get Table Data (Still fetches all for now as JS handles pagination, but stats are now independently verified)
        $stTable = $pdo->prepare("SELECT a.id_agente, a.alias AS agent_alias, g.nombre AS group_name, a.direccion AS ip_address, m.id_agente_modulo, m.nombre AS module_name, e.timestamp, e.datos as current_value, m.min as low_limit, m.max as high_limit, COALESCE(m.unit, '') as unit, COALESCE(e.estado, 4) as estado $sqlCommon ORDER BY e.timestamp DESC");
        $stTable->execute($params);
        $tableData = $stTable->fetchAll();

        foreach ($tableData as &$row) {
            $row['agent_alias'] = pretty_text($row['agent_alias']);
            $row['group_name'] = pretty_text($row['group_name']);
            $row['module_name'] = pretty_text($row['module_name']);
            $row['time_ago'] = timeAgo($row['timestamp']);
            $row['current_value'] = (float)$row['current_value'];
            $row['unit'] = pretty_text($row['unit']);
        }
        echo json_encode(['ok' => true, 'stats' => $stats, 'table' => $tableData, 'updated' => date('H:i:s')]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

if ($api === 'status_details' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $groupId = (int)($_GET['group_id'] ?? 0);
    $keyword = $_GET['keyword'] ?? '%';
    $manual_ids = $_GET['manual_ids'] ?? '';
    $statusFilter = $_GET['status_filter'] ?? 'all';

    try {
        $params = ['%' . str_replace(' ', '%', $keyword) . '%']; $whereClause = "";
        if (!empty($manual_ids) && $groupId == 0) {
            $ids_array = array_filter(explode(',', $manual_ids));
            if (!empty($ids_array)) {
                $whereClause = "AND a.id_agente IN (" . implode(',', array_fill(0, count($ids_array), '?')) . ")";
                foreach ($ids_array as $id) { $params[] = (int)$id; }
            }
        } elseif ($groupId > 0) {
            $stmtAllGroups = $pdo->query("SELECT id_grupo, parent FROM tgrupo"); $allGroups = $stmtAllGroups->fetchAll();
            function getChildGroups($parentId, $allGroups) { $children = [$parentId]; foreach ($allGroups as $g) { if ($g['parent'] == $parentId) { $children = array_merge($children, getChildGroups($g['id_grupo'], $allGroups)); } } return array_unique($children); }
            $targetGroups = getChildGroups($groupId, $allGroups);
            $whereClause = "AND a.id_grupo IN (" . implode(',', array_fill(0, count($targetGroups), '?')) . ")";
            foreach ($targetGroups as $tg) { $params[] = $tg; }
        }

        $sql = "SELECT a.id_agente, a.alias AS agent_alias, g.nombre AS group_name, a.direccion AS ip_address, 
                       m.id_agente_modulo, m.nombre AS module_name, e.timestamp, e.datos as current_value, 
                       m.min as low_limit, m.max as high_limit, COALESCE(m.unit, '') as unit, 
                       COALESCE(e.estado, 4) as estado
                FROM tagente_modulo m
                INNER JOIN tagente a ON m.id_agente = a.id_agente
                LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                LEFT JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                WHERE m.nombre LIKE ? AND a.disabled = 0 AND m.disabled = 0 $whereClause 
                ORDER BY e.timestamp DESC";

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();

        $data = [];
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
                'id_agente'    => $row['id_agente'],
                'agent_alias'  => pretty_text($row['agent_alias']),
                'group_name'   => pretty_text($row['group_name']),
                'ip_address'   => $row['ip_address'],
                'module_name'  => pretty_text($row['module_name']),
                'current_value'=> (float)$row['current_value'],
                'unit'         => pretty_text($row['unit']),
                'estado'       => $v_estado,
                'time_ago'     => timeAgo($row['timestamp'])
            ];
        }
        echo json_encode(['ok' => true, 'data' => $data]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

if ($api === 'export_data' && $db_status) {
    ob_clean();
    $agentIds  = explode(',', $_GET['agent_ids']);
    $keyword   = $_GET['keyword'] ?: '%';

    try {
        $finalData = [];
        $params = ['%' . str_replace(' ', '%', $keyword) . '%'];
        $placeholders = implode(',', array_fill(0, count($agentIds), '?'));
        foreach ($agentIds as $id) { $params[] = (int)$id; }

        $query = "SELECT a.alias AS agent_alias, g.nombre AS group_name, a.direccion AS ip_address, m.nombre AS module_name, e.timestamp, e.datos, m.min, m.max, COALESCE(m.unit, '') as unit, COALESCE(e.estado, 4) as estado
                  FROM tagente_modulo m
                  INNER JOIN tagente a ON m.id_agente = a.id_agente
                  LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                  LEFT JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                  WHERE m.nombre LIKE ? AND a.id_agente IN ($placeholders) AND a.disabled = 0 AND m.disabled = 0
                  ORDER BY a.alias ASC, m.nombre ASC";

        $stExp = $pdo->prepare($query);
        $stExp->execute($params);
        $rows = $stExp->fetchAll();

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

$isStandalone = (isset($_GET['standalone']) && $_GET['standalone'] == '1') || (isset($_GET['s']) && $_GET['s'] == '1');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metrics Monitoring Dashboard</title>
    <link rel="icon" href="<?= $PANDORA_BASE_URL ?>/images/pandora.ico" type="image/x-icon">
    <link href="/pandora_console/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="/pandora_console/custom/panel/vendor/fonts/fonts.css" />
    <link href="/pandora_console/custom/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <script src="/pandora_console/custom/panel/vendor/chartjs/chart.js"></script>
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
    </style>
</head>
<body>

<div class="pandora-header-top">
    <div class="header-left">
        <img src="<?= $PANDORA_BASE_URL ?>/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box"><span class="main-title">Pandora FMS</span><span class="sub-title">Custom Extensions Portal</span></div>
    </div>
    <div class="header-right"><a href="<?= $PANDORA_BASE_URL ?>/index.php" class="nav-icon-btn"><span class="material-symbols-outlined">home</span></a></div>
</div>

<div class="pandora-header-bottom">
    <div class="breadcrumb-box"><span class="page-breadcrumb"><?= h($dynamic_breadcrumb) ?></span><h1 class="page-title">Metrics Dashboard</h1></div>
    <div class="top-controls">
        <button class="btn-secondary-custom" onclick="exportDashboardConfig()"><span class="material-symbols-outlined">download</span> Backup</button>
        <button class="btn-secondary-custom" onclick="document.getElementById('importFile').click()"><span class="material-symbols-outlined">upload</span> Load</button>
        <input type="file" id="importFile" style="display:none" onchange="importDashboardConfig(event)">
        <button class="btn-apply" onclick="openBuilder()"><span class="material-symbols-outlined">add</span> Add Widget</button>
    </div>
</div>

<div class="main-content pt-4"><div class="grid-layout" id="dashboardGrid"></div></div>



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
            <select id="b_view_type" class="form-control-fix">
                <option value="table">Table View (Detailed)</option>
                <option value="heatmap">Heatmap View (Grid Summary)</option>
                <option value="cards">Cards Status View (Stats Only)</option>
            </select>
        </div>

        <div class="form-group"><label>Filter By Group</label><select id="b_group" class="form-control-fix" onchange="toggleManualSelector()"></select></div>
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
        <div class="form-group"><label>Table Keyword (Module Name)</label><input type="text" id="b_keyword" class="form-control-fix" value="%" placeholder="Use % for wildcard"></div>
        <div style="display:flex; gap:15px; margin-bottom: 15px;">
            <div style="flex:1;">
                <label>Historical Icon Size (px)</label>
                <input type="number" id="b_icon_size" class="form-control-fix" value="18" min="10" max="40">
            </div>
            <div style="flex:1;">
                <label>Table Font Size (px)</label>
                <input type="number" id="b_font_size" class="form-control-fix" value="14" min="8" max="24">
            </div>
        </div>

        <div class="form-group" style="margin-bottom: 15px;">
            <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:normal;">
                <input type="checkbox" id="b_use_raw" style="width:16px; height:16px; margin:0;"> Use Raw Value (No Formatting)
            </label>
        </div>

        <div style="display:flex; gap:15px;"><div style="flex:1;"><label>Rows Per Page (Limit)</label>
            <select id="b_limit" class="form-control-fix">
                <option value="15">15 Rows</option>
                <option value="50">50 Rows</option>
                <option value="100">100 Rows</option>
                <option value="500">500 Rows</option>
                <option value="0">All (Pagination 20/page)</option>
            </select></div><div style="flex:1;"><label>Auto-Refresh</label><select id="b_refresh" class="form-control-fix"><option value="30">30s</option><option value="60" selected>1m</option><option value="300">5m</option></select></div></div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;"><button class="btn-secondary-custom" onclick="closeBuilder()">Cancel</button><button class="btn-apply" id="btnSaveWidget" onclick="saveWidget()">Save Widget</button></div>
    </div>
</div>

<script>
const PANDORA_URL = "<?= h($PANDORA_BASE_URL) ?>";
const IS_STANDALONE = <?= $isStandalone ? 'true' : 'false' ?>;
const iconChart  = `<span class="material-symbols-outlined" style="font-size:16px!important; color:#1976d2;">monitoring</span>`;
const iconEdit = `<span class="material-symbols-outlined">edit</span>`;
const iconDelete = `<span class="material-symbols-outlined" style="color:#e74c3c;">delete</span>`;
let dashboardCards = [], cardTimers = {}, globalTimerRef = null;
let fullAgentsList = [], selectedIds = [];

// JS Storage variables
let cardDataStore = {};
let cardPages = {}; 
let cardSearch = {};

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

async function init() {
    let loadedCards = [];
    try {
        const res = await fetch('?api=load_config');
        const data = await res.json();
        if (Array.isArray(data)) loadedCards = data;
    } catch (e) {}

    if (IS_STANDALONE) {
        const p = new URLSearchParams(window.location.search);
        const targetId = p.get('d') || p.get('card_id');
        let targetCard = loadedCards.find(c => c.id === targetId);

        if (targetCard) {
            dashboardCards = [targetCard];
            document.title = targetCard.title + ' - Standalone View';
            const pageTitle = document.querySelector('.page-title');
            if(pageTitle) pageTitle.innerText = targetCard.title;
        } else {
            dashboardCards = [{
                id: 'std', title: p.get('title')||'Metrics Status', group_id: p.get('group_id')||'0',
                keyword: p.get('keyword')||'%', limit: p.get('limit')||15, refresh_sec: p.get('refresh')||60,
                view_type: p.get('view_type')||'table', manual_ids: p.get('manual_ids')||''
            }];
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

    worker.postMessage('start');
    worker.onmessage = (e) => { if(e.data === 'tick') runTimerLogic(); };
    document.addEventListener("visibilitychange", () => { if (!document.hidden) { dashboardCards.forEach(c => fetchCardData(c)); } });
}

function runTimerLogic() {
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
function renderAgentDropdown() {
    const list = document.getElementById('agent_checkbox_list');
    list.innerHTML = fullAgentsList.map(a => `<div class="agent-item" data-name="${a.alias.toLowerCase()}"><input type="checkbox" id="chk_${a.id}" value="${a.id}" onchange="handleAgentCheck(this)"><label for="chk_${a.id}">${a.alias}</label></div>`).join('');
}
function filterAgentsInList() {
    const kw = document.getElementById('inner_search').value.toLowerCase();
    document.querySelectorAll('.agent-item').forEach(item => { item.style.display = item.dataset.name.includes(kw) ? 'flex' : 'none'; });
}
function handleAgentCheck(chk) {
    const val = parseInt(chk.value);
    if (chk.checked) { if (!selectedIds.includes(val)) selectedIds.push(val); } else { selectedIds = selectedIds.filter(id => id !== val); }
    document.getElementById('sel_count').innerText = selectedIds.length + " Selected";
}
function toggleBuilderAgentAll() {
    const chks = document.querySelectorAll('#agent_checkbox_list input[type="checkbox"]');
    let visibleChks = [];
    chks.forEach(c => { if (c.closest('.agent-item').style.display !== 'none') visibleChks.push(c); });

    if (visibleChks.length === 0) return;
    const allChecked = visibleChks.every(c => c.checked);

    visibleChks.forEach(c => {
        c.checked = !allChecked;
        const val = parseInt(c.value);
        if (c.checked) { if (!selectedIds.includes(val)) selectedIds.push(val); } else { selectedIds = selectedIds.filter(id => id !== val); }
    });

    document.getElementById('sel_count').innerText = selectedIds.length + " Selected";
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

        let searchBtn = c.view_type === 'cards' ? '' : `
            <input type="text" id="search_inp_${c.id}" class="search-input-header" placeholder="Filter..." onkeyup="filterTableRows('${c.id}')">
            <button class="icon-btn-card" onclick="toggleSearchInput('${c.id}')" title="Search"><span class="material-symbols-outlined">search</span></button>
        `;

        let acts = `
            <div class="card-actions">
                ${searchBtn}
                <button class="icon-btn-card" onclick="openExport('${c.id}')" title="Export Data"><span class="material-symbols-outlined">ios_share</span></button>
                ${!IS_STANDALONE ? `
                <button class="icon-btn-card" onclick='copyStandaloneUrl(${JSON.stringify(c)})' title="Share Widget"><span class="material-symbols-outlined">share</span></button>
                <button class="icon-btn-card" onclick="duplicatePanel('${c.id}')" title="Duplicate"><span class="material-symbols-outlined">content_copy</span></button>
                <button class="icon-btn-card" onclick="openEdit('${c.id}')" title="Edit">${iconEdit}</button>
                <button class="icon-btn-card" onclick="deleteCard('${c.id}')" title="Delete">${iconDelete}</button>
                ` : `
                <button class="icon-btn-card" onclick="openEdit('${c.id}')" title="Edit">${iconEdit}</button>
                `}
            </div>`;

        div.innerHTML = `<div class="dashboard-card-header"><div><h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="color:#004d40;">analytics</span> ${c.title}</h5><div style="font-size:10px; color:#7f8c8d; font-weight: normal;"><span id="meta_up_${c.id}">Awaiting...</span> <span id="meta_timer_${c.id}"></span></div></div>${acts}</div>
        <div class="dashboard-card-body">
            <div class="mini-stats-row">
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
    const url = `?api=card_data&group_id=${card.group_id}&keyword=${encodeURIComponent(card.keyword)}&limit=${card.limit}&manual_ids=${card.manual_ids || ''}`;

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
        } else {
            container.style.display = 'block';
            renderTablePage(card.id);
        }
    }).catch(err => {
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

    const limit = parseInt(card.limit);
    const pageSize = (limit === 0) ? 20 : limit; 
    const totalPages = Math.ceil(data.length / pageSize) || 1;
    
    let currentPage = cardPages[cardId];
    if(currentPage > totalPages) currentPage = totalPages;
    cardPages[cardId] = currentPage;

    const startIdx = (currentPage - 1) * pageSize;
    const endIdx = Math.min(startIdx + pageSize, data.length);
    const pageData = data.slice(startIdx, endIdx);

    let h = '';
    
    if (card.view_type === 'heatmap') {
        h += '<div class="heatmap-wrap" style="border: 1px solid #f0f3f5; border-radius: 6px;">';
        pageData.forEach(r => {
            const sObj = getStatusObj(r.estado);
            let unitStr = r.unit ? ` ${r.unit}` : '';
            h += `<a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${r.id_agente}" target="_blank" class="heat-box ${sObj.color}" title="Agent: ${r.agent_alias}\nModule: ${r.module_name}\nValue: ${r.current_value}${unitStr}">${r.module_name}</a>`;
        });
        h += '</div>';
    } 
    else {
        const tableFs = card.font_size || 14;
        const iconSz = card.icon_size || 18;
        h += `<div class="table-wrap"><table class="table-pfms" style="font-size:${tableFs}px;"><thead><tr><th>Agent</th><th>Group</th><th>IP Address</th><th>Sensor Module</th><th style="text-align:center;">Status</th><th>Metrics History</th><th>Threshold</th></tr></thead><tbody>`;
        pageData.forEach(r => {
            const sObj = getStatusObj(r.estado);
            let unitStr = r.unit ? ` ${r.unit}` : '';

            h += `<tr>
                    <td>
                        <div class="node-wrap"><div class="dot ${sObj.color}"></div><a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${r.id_agente}" target="_blank" class="agent-link" style="font-size:${tableFs}px!important;">${r.agent_alias}</a></div>
                    </td>
                    <td style="color:#7f8c8d">${r.group_name}</td>
                    <td><code class="ip-text">${r.ip_address||'-'}</code></td>
                    <td>
                        <div style="font-weight: normal; color:#0b1a26; margin-bottom:4px;">${r.module_name}</div>
                        <div style="font-size:10px!important; color:#7f8c8d;">Update: ${r.time_ago}</div>
                    </td>
                    <td style="text-align:center;">
                        <div class="status-pill ${sObj.color}" style="color:#fff!important; border:none; padding: 6px 12px; font-size:${Math.round(tableFs*0.8)}px!important;">
                            ${formatValue(r.current_value, r.unit, card.use_raw)}${unitStr}
                        </div>
                    </td>
                    <td style="text-align:center;">
                        <button class="icon-btn-card" style="padding:0; margin:0;" onclick="openNativeChart(${r.id_agente_modulo}, '${r.agent_alias.replace(/'/g, "\\'")} - ${r.module_name.replace(/'/g, "\\'")}')">
                            <span class="material-symbols-outlined" style="font-size:${iconSz}px!important; color:#1976d2;">monitoring</span>
                        </button>
                    </td>
                    <td>
                        <div class="limit-text">Min: <strong style="color:#333;">${r.low_limit}${unitStr}</strong></div>
                        <div class="limit-text">Max: <strong style="color:#e74c3c;">${r.high_limit}${unitStr}</strong></div>
                    </td>
                  </tr>`;
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

function toggleCustomChartRange() {
    const val = document.getElementById('chartRangeSelect').value;
    document.getElementById('chartCustomDateBox').style.display = (val === 'custom') ? 'flex' : 'none';
}



function openNativeChart(modId, title) {
    if(!modId || modId === 0) return;
    document.getElementById('nativeChartTitle').innerHTML = `<span class="material-symbols-outlined" style="font-size:18px!important; color:#004d40; vertical-align:middle; margin-right:5px;">monitoring</span> ${title}`;
    const url = `${PANDORA_URL}/operation/agentes/stat_win.php?type=sparse&period=86400&id=${modId}&refresh=600&period_graph=0&draw_events=0`;
    document.getElementById('nativeChartFrame').src = url;
    document.getElementById('nativeChartModal').style.display = 'flex';
}

function closeNativeChartModal() {
    document.getElementById('nativeChartModal').style.display = 'none';
    document.getElementById('nativeChartFrame').src = ''; 
}

function renderDetailModalTable(dataArray) {
    let h = '<div class="table-wrap"><table class="table-pfms"><thead><tr><th>Agent</th><th>Group</th><th>IP Address</th><th>Sensor Module</th><th>Value</th><th style="text-align:center;">Status</th></tr></thead><tbody>';

    if (dataArray.length === 0) {
        h += '<tr><td colspan="6" style="text-align:center; padding: 25px; color:#7f8c8d; font-weight: normal;">No matching data found.</td></tr>';
    } else {
        dataArray.forEach(r => {
            const sObj = getStatusObj(r.estado);
            let unitStr = r.unit ? ` ${r.unit}` : '';

            h += `<tr>
                    <td><div class="node-wrap"><div class="dot ${sObj.color}"></div><a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${r.id_agente}" target="_blank" class="agent-link">${r.agent_alias}</a></div></td>
                    <td style="color:#7f8c8d">${r.group_name}</td>
                    <td><code class="ip-text">${r.ip_address||'-'}</code></td>
                    <td style="color:#0b1a26; font-weight: normal;">${r.module_name}</td>
                    <td style="font-weight: normal;">${r.current_value}${unitStr}</td>
                    <td style="text-align:center;">
                        <div class="status-pill ${sObj.color}" style="color:#fff!important; border:none; padding:4px 8px;">
                            ${sObj.label}
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
    
    const url = `?api=status_details&group_id=${card.group_id}&keyword=${encodeURIComponent(card.keyword)}&manual_ids=${card.manual_ids || ''}&status_filter=${statusFilter}`;
    
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

    let h = '<div style="padding:0; max-height:60vh; overflow-y:auto;"><table class="table-pfms"><thead><tr><th>Agent</th><th>Group</th><th>IP Address</th><th>Sensor Module</th><th>Value</th><th style="text-align:center;">Status</th></tr></thead><tbody>';

    if (pageData.length === 0) {
        h += '<tr><td colspan="6" style="text-align:center; padding: 25px; color:#7f8c8d; font-weight: normal;">No matching data found.</td></tr>';
    } else {
        pageData.forEach(r => {
            const sObj = getStatusObj(r.estado);
            let unitStr = r.unit ? ` ${r.unit}` : '';
            h += `<tr>
                    <td><div class="node-wrap"><div class="dot ${sObj.color}"></div><a href="${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${r.id_agente}" target="_blank" class="agent-link">${r.agent_alias}</a></div></td>
                    <td style="color:#7f8c8d">${r.group_name}</td>
                    <td><code class="ip-text">${r.ip_address||'-'}</code></td>
                    <td style="color:#0b1a26; font-weight: normal;">${r.module_name}</td>
                    <td style="font-weight: normal;">${r.current_value}${unitStr}</td>
                    <td style="text-align:center;">
                        <div class="status-pill ${sObj.color}" style="color:#fff!important; border:none; padding:4px 8px;">
                            ${sObj.label}
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
function processExport() {
    const selected = Array.from(document.querySelectorAll('.exp-chk:checked')); if(!selected.length) return alert("Select agents.");
    const agIds = selected.map(s => s.dataset.agid).join(',');
    const kw = dashboardCards.find(c=>c.id==curExpCardId).keyword;
    const url = `?api=export_data&agent_ids=${agIds}&keyword=${encodeURIComponent(kw)}&format=${document.getElementById('e_format').value}`;
    window.open(url, '_blank');
}
function closeExport() { document.getElementById('exportModal').style.display = 'none'; }

function openBuilder() {
    editingCardId = null; selectedIds = []; document.getElementById('builderTitle').innerText='Build Widget';
    document.getElementById('b_title').value = '';
    document.getElementById('b_group').value='0';
    document.getElementById('b_icon_size').value='18';
    document.getElementById('b_font_size').value='14';
    document.getElementById('b_use_raw').checked = false;
    document.querySelectorAll('#agent_checkbox_list input').forEach(c => c.checked = false);
    document.getElementById('sel_count').innerText = "0 Selected";
    toggleManualSelector(); document.getElementById('builderModal').style.display='flex';
}
function openEdit(id) {
    editingCardId = id; const c = dashboardCards.find(x => x.id === id); document.getElementById('builderTitle').innerText='Edit Widget';
    ['title','view_type','group','keyword','limit','refresh','icon_size','font_size','use_raw'].forEach(k => {
        const el = document.getElementById('b_'+k);
        if(el) {
            if (el.type === 'checkbox') el.checked = !!c[k];
            else el.value = c[k==='group'?'group_id':(k==='refresh'?'refresh_sec':k)] || (k==='icon_size'?'18':(k==='font_size'?'14':''));
        }
    });

    selectedIds = c.manual_ids ? String(c.manual_ids).split(',').map(Number) : [];
    document.querySelectorAll('#agent_checkbox_list input').forEach(chk => { chk.checked = selectedIds.includes(parseInt(chk.value)); });
    document.getElementById('sel_count').innerText = selectedIds.length + " Selected"; toggleManualSelector(); document.getElementById('builderModal').style.display='flex';
}
function closeBuilder() { document.getElementById('builderModal').style.display = 'none'; }

function saveWidget() {
    const card = {
        id: editingCardId || 'c'+Date.now(),
        title: document.getElementById('b_title').value||'Widget',
        view_type: document.getElementById('b_view_type').value,
        group_id: document.getElementById('b_group').value,
        keyword: document.getElementById('b_keyword').value,
        limit: document.getElementById('b_limit').value,
        refresh_sec: document.getElementById('b_refresh').value,
        icon_size: document.getElementById('b_icon_size').value || 18,
        font_size: document.getElementById('b_font_size').value || 14,
        use_raw: document.getElementById('b_use_raw').checked,
        manual_ids: selectedIds.join(',')
    };

    let tempCards = [];
    if (editingCardId) { tempCards = dashboardCards.map(x => x.id === editingCardId ? card : x); } else { tempCards = [...dashboardCards, card]; }

    const btn = document.getElementById("btnSaveWidget");
    btn.innerHTML = '<span class="material-symbols-outlined">sync</span> Saving...';
    btn.disabled = true;

    fetch('?api=save_config', { method: 'POST', body: JSON.stringify(tempCards), headers: {'X-CSRF-TOKEN': '<?= $csrf_token ?>'} })
    .then(r => r.json())
    .then(res => {
        if(res.ok) {
            dashboardCards = tempCards;
            renderGrid(); fetchCardData(card); closeBuilder();
        } else {
            let errMsg = res.error && res.error.message ? res.error.message : res.error;
            alert(`SAVE FAILED!\n\nReason:\n${errMsg || 'Unknown Error'}\n\nTarget File: ${res.file || 'File permission issue'}`);
        }
    })
    .catch(err => { alert("Failed to connect to server to save configuration."); })
    .finally(() => { btn.innerHTML = 'Save Widget'; btn.disabled = false; });
}

function deleteCard(id) {
    if(confirm('Delete?')) {
        let tempCards = dashboardCards.filter(x => x.id !== id);
        fetch('?api=save_config', { method: 'POST', body: JSON.stringify(tempCards), headers: {'X-CSRF-TOKEN': '<?= $csrf_token ?>'} })
        .then(r => r.json())
        .then(res => {
            if(res.ok) { dashboardCards = tempCards; renderGrid(); }
            else { let errMsg = res.error && res.error.message ? res.error.message : res.error; alert(`Delete failed! Reason: ${errMsg || 'Check permissions'}`); }
        });
    }
}

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

    fetch('?api=save_config', { method: 'POST', body: JSON.stringify(tempCards), headers: {'X-CSRF-TOKEN': '<?= $csrf_token ?>'} })
    .then(r => r.json()).then(res => {
        if (res.ok) {
            dashboardCards = tempCards;
            renderGrid(); dashboardCards.forEach(c => fetchCardData(c));
        } else {
            alert("Failed to duplicate widget.");
        }
    }).finally(() => { if(btn) btn.style.opacity = '1'; });
}

function exportDashboardConfig() {
    const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(dashboardCards, null, 2));
    const dlAnchorElem = document.createElement('a');
    dlAnchorElem.setAttribute("href",     dataStr);
    dlAnchorElem.setAttribute("download", "metrics_config_backup.json");
    dlAnchorElem.click();
}

function importDashboardConfig(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const loaded = JSON.parse(e.target.result);
            if (Array.isArray(loaded)) {
                fetch('?api=save_config', { method: 'POST', body: JSON.stringify(loaded), headers: {'X-CSRF-TOKEN': '<?= $csrf_token ?>'} })
                .then(r => r.json())
                .then(res => {
                    if (res.ok) {
                        dashboardCards = loaded;
                        renderGrid(); dashboardCards.forEach(c => fetchCardData(c)); alert("Config loaded successfully!");
                    } else { alert(`Failed to save import. Reason: ${res.error?.message || res.error || 'Unknown Error'}`); }
                });
            }
        } catch (err) { alert("Invalid JSON file."); }
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


