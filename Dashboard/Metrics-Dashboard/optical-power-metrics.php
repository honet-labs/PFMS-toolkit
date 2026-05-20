<?php
/* optical-power-metrics.php
 *
 * Dashboard Optical Power Monitoring (RX/TX Power)
 * - Version: 23.0 (STABLE: Configurable Search & Unknown Fix)
 * - Feature: Added Enable/Disable Search Toolbar option in widget settings.
 * - Bugfix: Fixed "Unknown" status not appearing by using LEFT JOIN for tagente_estado.
 * - UX: Agent names in drilldown modal are now active links to the Agent View.
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// 1. DYNAMIC BREADCRUMB
$dynamic_breadcrumb = "PANDORA CONSOLE / CUSTOM / PANEL / DASHBOARD";

// 2. CONFIG LOADING
$PANDORA_BASE_URL = "/pandora_console";
$CONFIG_FILE = __DIR__ . '/optical-power-save.json';
$config_paths = ['/var/www/html/pandora_console/include/config.php', '../../../include/config.php', '../../include/config.php'];
$config_loaded = false;
foreach ($config_paths as $path) { if (file_exists($path)) { require_once($path); $config_loaded = true; break; } }

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';

// 3. HELPERS & DB INIT
require_once(__DIR__ . '/../../tools/utils.php');

if (!function_exists('extract_number_from_string')) {
    function extract_number_from_string($s) {
        if ($s === null || $s === '') return 0;
        preg_match('/-?\d+(\.\d+)?/', (string)$s, $matches);
        return isset($matches[0]) ? (float)$matches[0] : 0;
    }
}

$pdo = null; $db_status = false; $db_error = '';
if ($config_loaded) {
    try {
        $pdo = get_db_connection($config);
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
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf_token) || $client_token !== $csrf_token) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token.']); exit;
    }
    $input = file_get_contents('php://input');
    $bytes = @file_put_contents($CONFIG_FILE, $input);
    echo json_encode(['ok' => $bytes !== false]); exit;
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

// API: FETCH DATA WITH DUAL SEARCH & PAGINATION & AUTO-SORT
if ($api === 'card_data' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $groupId = (int)($_GET['group_id'] ?? 0);
    $limit   = (int)($_GET['limit'] ?? 15);
    $page    = (int)($_GET['page'] ?? 1);
    $offset  = ($page - 1) * $limit;
    $fetchAll = (int)($_GET['fetch_all'] ?? 0);
    $manual_ids = preg_replace('/[^0-9,]/', '', $_GET['manual_ids'] ?? '');
    
    $search = trim($_GET['search'] ?? '');

    try {
        $where = "WHERE a.disabled = 0 AND am.disabled = 0 AND (am.nombre LIKE '%Rx%' OR am.nombre LIKE '%Tx%' OR am.nombre LIKE '%Optical%')";
        $params = [];
        if (!empty($manual_ids) && $groupId == 0) {
            $ids_array = array_filter(explode(',', $manual_ids));
            if (!empty($ids_array)) {
                $where .= " AND a.id_agente IN (" . implode(',', array_fill(0, count($ids_array), '?')) . ")";
                foreach ($ids_array as $id) { $params[] = (int)$id; } 
            }
        } elseif ($groupId > 0) { 
            $where .= " AND a.id_grupo = ?"; $params[] = $groupId; 
        }
        
        if ($search !== '') { 
            $where .= " AND (a.alias LIKE ? OR am.nombre LIKE ? OR am.descripcion LIKE ?)"; 
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        // Use LEFT JOIN for tagente_estado to ensure Unknown (null state) modules are captured
        $sqlData = "SELECT a.id_agente, a.alias AS agent_alias, g.nombre AS group_name, a.direccion AS ip_address,
                           am.id_agente_modulo, am.nombre AS module_name, am.descripcion, te.datos AS current_val, te.estado AS status, te.utimestamp AS last_contact
                    FROM tagente a
                    INNER JOIN tagente_modulo am ON a.id_agente = am.id_agente
                    LEFT JOIN tagente_estado te ON am.id_agente_modulo = te.id_agente_modulo
                    LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                    $where";
        
        $stmtData = $pdo->prepare($sqlData);
        $stmtData->execute($params);
        $rows = $stmtData->fetchAll();

        $pairs = [];
        foreach ($rows as $row) {
            $mName = trim($row['module_name']);
            $isRx = preg_match('/(rx|receive)/i', $mName);
            $isTx = preg_match('/(tx|transmit)/i', $mName);
            if (!$isRx && !$isTx) continue;

            $baseName = preg_replace('/(optical_|optic_|tx_|rx_|tx|rx|_power|power_|\s+)/i', '', $mName);
            $baseName = trim(preg_replace('/^[-_\s]+|[-_\s]+$/', '', $baseName));
            if ($baseName === '') $baseName = 'Port_' . $row['id_agente_modulo'];

            $key = $row['id_agente'] . '_' . md5(strtolower($baseName));
            if (!isset($pairs[$key])) {
                $pairs[$key] = [
                    'agent_id' => $row['id_agente'], 'agent_name' => pretty_text($row['agent_alias']),
                    'group' => pretty_text($row['group_name']), 'ip' => $row['ip_address'], 'interface' => pretty_text($baseName),
                    'rx_id' => 0, 'rx_val' => 'N/A', 'rx_st' => 3, 'tx_id' => 0, 'tx_val' => 'N/A', 'tx_st' => 3,
                    'last_update_ts' => 0, 'time_ago' => 'N/A', 'description' => ''
                ];
            }
            $val = $row['current_val'] !== null ? extract_number_from_string($row['current_val']) : 0;
            $val_fmt = $row['current_val'] !== null ? round($val, 2) . ' dBm' : 'N/A';
            $st = ($row['status'] === null) ? 3 : (int)$row['status'];
            
            if ($isRx) { 
                $pairs[$key]['rx_id'] = (int)$row['id_agente_modulo']; 
                $pairs[$key]['rx_val'] = $val_fmt; 
                $pairs[$key]['rx_st'] = $st; 
                $pairs[$key]['description'] = $row['descripcion'] ?: '';
            } else { 
                $pairs[$key]['tx_id'] = (int)$row['id_agente_modulo']; 
                $pairs[$key]['tx_val'] = $val_fmt; 
                $pairs[$key]['tx_st'] = $st; 
                if (empty($pairs[$key]['description'])) $pairs[$key]['description'] = $row['descripcion'] ?: '';
            }

            if ($row['last_contact'] !== null && (int)$row['last_contact'] > $pairs[$key]['last_update_ts']) {
                $pairs[$key]['last_update_ts'] = (int)$row['last_contact'];
                $pairs[$key]['time_ago'] = format_time_ago((int)$row['last_contact']);
            }
        }

        $stats = ['total'=>count($pairs), 'normal'=>0, 'critical'=>0, 'warning'=>0, 'unknown'=>0];
        $allPairs = array_values($pairs);

        // Calculate Stats
        foreach ($allPairs as $p) {
            $worst = max($p['rx_st'], $p['tx_st']);
            if ($worst === 0) $stats['normal']++;
            elseif ($worst === 1) $stats['critical']++;
            elseif ($worst === 2) $stats['warning']++;
            else $stats['unknown']++;
        }

        // SMART SORTING: Priority Critical > Warning > Normal > Unknown, then by Newest Update
        usort($allPairs, function($a, $b) {
            $pMap = [1 => 0, 2 => 1, 0 => 2, 3 => 3]; // Weight: Critical first
            $stA = $pMap[max($a['rx_st'], $a['tx_st'])] ?? 4;
            $stB = $pMap[max($b['rx_st'], $b['tx_st'])] ?? 4;
            if ($stA !== $stB) return $stA <=> $stB;
            return $b['last_update_ts'] <=> $a['last_update_ts']; // Newer first
        });

        $totalFound = count($allPairs);
        $paginatedData = ($fetchAll === 1) ? $allPairs : array_slice($allPairs, $offset, $limit);

        echo json_encode(['ok' => true, 'data' => $paginatedData, 'stats' => $stats, 'total_found' => $totalFound, 'updated' => date('H:i:s')]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

$isStandalone = (isset($_GET['standalone']) && $_GET['standalone'] == '1') || (isset($_GET['s']) && $_GET['s'] == '1');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Optical Power Monitoring</title>
    <link rel="icon" href="<?= h($PANDORA_BASE_URL) ?>/images/pandora.ico" type="image/x-icon">
    <link href="/pandora_console/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="/pandora_console/custom/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #334155; font-size: 14px; -webkit-font-smoothing: antialiased; background-color: #f4f6f8; margin: 0; padding: 0; }
        * { box-sizing: border-box; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-size: 18px !important; vertical-align: middle; line-height: 1; }

        <?php if ($isStandalone): ?>
        .pandora-header-top, .pandora-header-bottom, .top-controls { display: none !important; }
        .main-content { padding: 20px !important; }
        .grid-layout { grid-template-columns: 1fr !important; }
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
        .btn-secondary-custom:hover { background: #f4f6f8; color: #0b1a26 !important; }

        .main-content { padding: 0 30px 30px 30px; }
        .grid-layout { display: grid; grid-template-columns: 1fr; gap: 20px; }

        .dashboard-card { background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border: 1px solid #f0f3f5; overflow: hidden; margin-bottom: 20px; }
        .dashboard-card-header { padding: 15px 20px; border-bottom: 1px solid #e0e4e8; background-color: #f8f9fa; display: flex; justify-content: space-between; align-items: center; }
        .dashboard-card-title { font-size: 14px !important; font-weight: 500 !important; color: #1e293b !important; margin: 0; display: flex; align-items: center; gap: 8px; }

        .card-actions { display: flex; gap: 4px; align-items: center; }
        .icon-btn-card { background: transparent; border: none; padding: 6px; cursor: pointer; color: #7f8c8d; border-radius: 4px; display:inline-flex; align-items:center; transition: 0.2s; outline: none; }
        .icon-btn-card:hover { background: #e0e4e8; color: #0b1a26; }
        .icon-btn-card .material-symbols-outlined { font-size: 18px !important; }

        .mini-stats-row { display: flex; gap: 10px; width: 100%; flex-wrap: wrap; padding: 20px; border-bottom: 1px solid #e0e4e8;}
        .mini-stat { flex: 1; min-width: 90px; text-align: center; padding: 12px 5px; border-radius: 6px; background: #ffffff; border: 1px solid #e0e4e8; border-bottom: 4px solid #ccc; cursor: pointer; transition: 0.2s ease; }
        .mini-stat:hover { transform: translateY(-3px); box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        .mini-stat-val { font-size: 22px !important; font-weight: normal !important; line-height: 1; margin-bottom: 5px; }
        .mini-stat-label { font-size: 9px !important; text-transform: uppercase; color: #7f8c8d; font-weight: normal !important; white-space: nowrap; }

        .st-border-black { border-bottom-color: #0b1a26; } .text-black { color: #0b1a26 !important; }
        .st-border-green { border-bottom-color: #2ecc71; } .text-green { color: #2ecc71 !important; }
        .st-border-red { border-bottom-color: #e74c3c; } .text-red { color: #e74c3c !important; }
        .st-border-yellow { border-bottom-color: #f1c40f; } .text-yellow { color: #f1c40f !important; }
        .st-border-gray { border-bottom-color: #95a5a6; } .text-gray { color: #334155 !important; }

        .toolbar-row { padding: 12px 20px; background: #fafbfc; border-bottom: 1px solid #e0e4e8; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .search-container { position: relative; flex: 1; min-width: 250px; }
        .search-container .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #7f8c8d; font-size: 16px !important; }
        .search-box { width: 100%; height: 32px; padding: 0 10px 0 35px; border: 1px solid #dce1e5; border-radius: 4px; font-size: 12px; outline: none; }
        .search-box:focus { border-color: #004d40; box-shadow: 0 0 0 2px rgba(0,77,64,0.05); }

        table.table-pfms { border-collapse: collapse !important; width: 100% !important; margin: 0 !important; }
        table.table-pfms thead th { background-color: #ffffff !important; border-bottom: 2px solid #e0e4e8 !important; text-transform: uppercase; padding: 12px 20px !important; font-weight: normal !important; color: #7f8c8d !important; font-size: 10px !important; }
        table.table-pfms tbody td { font-weight: normal !important; border-bottom: 1px solid #f0f3f5; padding: 12px 20px !important; color: #0b1a26 !important; }
        
        .val-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px; min-width: 85px; justify-content: space-between; position: relative; }
        .bg-ok { background: #e0f2f1; color: #00796b; }
        .bg-crit { background: #fdecea; color: #d32f2f; }
        .bg-warn { background: #fff8e1; color: #f57c00; }
        .bg-unk { background: #f5f5f5; color: #616161; }

        .row-action-btn { background: transparent; border: 1px solid #dce1e5; padding: 3px; border-radius: 4px; color: #7f8c8d; cursor: pointer; display: inline-flex; transition: 0.2s; text-decoration: none !important; }
        .row-action-btn:hover { background: #0b1a26; color: #fff; border-color: #0b1a26; }
        .row-action-btn .material-symbols-outlined { font-size: 14px !important; }

        .hist-icon { font-size: 14px !important; cursor: pointer; color: inherit; opacity: 0.6; transition: 0.2s; text-decoration: none !important; border:none; background:transparent; padding:0; display:inline-flex; align-items:center;}
        .hist-icon:hover { opacity: 1; transform: scale(1.1); }

        .agent-drill-link { color: #0b1a26 !important; text-decoration: none !important; font-weight: 600 !important; }
        .agent-drill-link:hover { color: #004d40 !important; text-decoration: underline !important; }

        .pagination-container { display: flex; justify-content: space-between; align-items: center; padding: 15px 20px; background: #fafafa; border-top: 1px solid #e0e4e8; }
        .pagination-btn { background: #fff; border: 1px solid #dce1e5; padding: 4px 12px; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .pagination-btn:hover:not(:disabled) { background: #0b1a26; color: #fff; }
        .pagination-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-box { background: #fff; width: 550px; padding: 25px; border-radius: 8px; border: 1px solid #e0e4e8; max-height: 90vh; overflow-y: auto; }
        
        .iframe-modal-box { width: 950px; max-width: 95%; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2); display: flex; flex-direction: column;}
        .iframe-header { padding: 15px 20px; border-bottom: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; background-color: #f8f9fa; }
        .iframe-title { font-weight: 600 !important; font-size: 14px !important; color: #0b1a26; display:flex; align-items:center; gap:8px;}

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 11px; text-transform: uppercase; color: #7f8c8d; margin-bottom: 5px; }
        .form-control-fix { width: 100%; height: 36px; padding: 8px 12px; border: 1px solid #dce1e5; border-radius: 4px; outline: none; }

        .agent-list-scroll { max-height: 200px; overflow-y: auto; border: 1px solid #dce1e5; border-radius: 4px; background: #fff; }
        .agent-item { display: flex; align-items: center; padding: 8px 12px; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: 0.2s; }
        .agent-item:hover { background: #f8fafc; }
        .agent-item input[type="checkbox"] { margin: 0 12px 0 0; width: 15px; height: 15px; flex-shrink: 0; cursor: pointer; }
        .agent-item label { margin: 0 !important; font-size: 13px !important; color: #334155 !important; flex-grow: 1; cursor: pointer; display: block !important; line-height: 1.2 !important; font-weight: normal !important; text-transform: none !important; }

        .drilldown-modal { width: 1050px !important; max-width: 95vw !important; }
        .status-pill { padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; text-transform: uppercase; }

        #loadingOverlay { position: fixed; inset: 0; background: rgba(255,255,255,0.7); display: none; align-items: center; justify-content: center; z-index: 9999; flex-direction: column; gap: 15px; }
        .spinner { width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #004d40; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>

<div id="loadingOverlay"><div class="spinner"></div><div style="font-size:11px; color:#004d40; text-transform:uppercase; letter-spacing:1px;">Processing...</div></div>

<div class="pandora-header-top">
    <div class="header-left">
        <img src="<?= h($PANDORA_BASE_URL) ?>/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box"><span class="main-title">Pandora FMS</span><span style="font-size:11px; color:#7f8c8d;">Optical Intelligence Dashboard</span></div>
    </div>
    <div class="header-right"><button class="nav-icon-btn" onclick="window.location.href='<?= h($PANDORA_BASE_URL) ?>/index.php'"><span class="material-symbols-outlined">home</span></button></div>
</div>

<div class="pandora-header-bottom">
    <div class="breadcrumb-box"><span class="page-breadcrumb"><?= h($dynamic_breadcrumb) ?></span><h1 class="page-title">Optical Power Metrics</h1></div>
    <div class="top-controls">
        <button class="btn-secondary-custom" onclick="exportConfig()"><span class="material-symbols-outlined">download</span> Backup</button>
        <button class="btn-secondary-custom" onclick="document.getElementById('importFile').click()"><span class="material-symbols-outlined">upload</span> Load Config</button>
        <input type="file" id="importFile" style="display:none" onchange="importConfig(event)">
        <button class="btn-apply" onclick="openBuilder()"><span class="material-symbols-outlined">add</span> Add Widget</button>
    </div>
</div>

<div class="main-content pt-4"><div class="grid-layout" id="dashboardGrid"></div></div>

<div class="modal-overlay" id="builderModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px;">
            <h5 style="text-transform:uppercase; font-size:14px; font-weight:600; margin:0;" id="builderTitle">Build Optical Widget</h5>
            <span class="material-symbols-outlined" style="cursor:pointer; color:#7f8c8d;" onclick="closeBuilder()">close</span>
        </div>
        <div class="form-group"><label>Widget Title</label><input type="text" id="b_title" class="form-control-fix" placeholder="e.g. Core Optical Stats"></div>
        <div class="form-group"><label>Default Agent Search Keyword</label><input type="text" id="b_def_agent" class="form-control-fix" placeholder="e.g. Router-A"></div>
        <div class="form-group"><label>Default Module Search Keyword</label><input type="text" id="b_def_mod" class="form-control-fix" placeholder="e.g. Optical_Tx"></div>
        
        <div class="form-group">
            <label>Target Group</label>
            <select id="b_group" class="form-control-fix" onchange="toggleManualSelector()"></select>
        </div>

        <div id="manual_selector_box" class="form-group" style="display:none;">
            <label>Select Specific Agents</label>
            <div style="padding:10px; background:#f8f9fa; border:1px solid #dce1e5; border-bottom:none; display:flex; align-items:center; gap:8px;">
                <span class="material-symbols-outlined" style="font-size:18px;">search</span>
                <input type="text" id="inner_search" class="form-control-fix" style="border:none; height:25px;" placeholder="Filter agents list..." onkeyup="filterAgentsInList()">
            </div>
            <div class="agent-list-scroll" id="agent_checkbox_list"></div>
            <div id="sel_count" style="font-size:11px; color:#004d40; padding:5px;">0 Selected</div>
        </div>

        <div style="display:flex; gap:15px;">
            <div style="flex:1;"><label>Rows Per Page</label><select id="b_limit" class="form-control-fix"><option value="15">15 Rows</option><option value="30">30 Rows</option><option value="50">50 Rows</option></select></div>
            <div style="flex:1;"><label>Auto-Refresh</label><select id="b_refresh" class="form-control-fix"><option value="30">30s</option><option value="60" selected>1m</option><option value="300">5m</option></select></div>
        </div>

        <div class="form-group" style="margin-top:10px; padding-top:10px; border-top:1px dashed #e0e4e8;">
            <div style="display:flex; gap:30px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; color:#0b1a26; text-transform:none;">
                    <input type="checkbox" id="b_show_stats" checked style="width:16px; height:16px;"> Show Stats Row
                </label>
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; color:#0b1a26; text-transform:none;">
                    <input type="checkbox" id="b_show_search" checked style="width:16px; height:16px;"> Enable Search
                </label>
            </div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;"><button class="btn-secondary-custom" onclick="closeBuilder()">Cancel</button><button class="btn-apply" onclick="saveWidget()">Apply Widget</button></div>
    </div>
</div>

<div class="modal-overlay" id="exportDataModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px;">
            <h5 style="text-transform:uppercase; font-size:14px; font-weight:600; margin:0;">Export Widget Data</h5>
            <span class="material-symbols-outlined" style="cursor:pointer; color:#7f8c8d;" onclick="document.getElementById('exportDataModal').style.display='none'">close</span>
        </div>
        <p style="font-size:12px; color:#64748b;">Export current table data for <b id="export_title"></b></p>
        <div class="form-group">
            <label>Choose Format</label>
            <select id="export_format" class="form-control-fix">
                <option value="csv">CSV (Comma Separated)</option>
                <option value="txt">TXT (Visual Report)</option>
            </select>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
            <button class="btn-secondary-custom" onclick="document.getElementById('exportDataModal').style.display='none'">Cancel</button>
            <button class="btn-apply" onclick="processExportData()">Download Now</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="drilldownModal">
    <div class="modal-box drilldown-modal">
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:15px;">
            <h5 style="text-transform:uppercase; font-size:14px; font-weight:600; margin:0;" id="drill_title">Status Drilldown</h5>
            <span class="material-symbols-outlined" style="cursor:pointer; color:#7f8c8d;" onclick="document.getElementById('drilldownModal').style.display='none'">close</span>
        </div>
        <div style="margin-bottom:15px; display:flex; gap:10px; align-items:center;">
             <div class="search-container" style="max-width:350px;">
                <span class="material-symbols-outlined search-icon">search</span>
                <input type="text" class="search-box" id="drill_search" placeholder="Filter current list..." onkeyup="filterDrilldownTable()">
             </div>
             <div id="drill_count" style="font-size:12px; color:#64748b; font-weight:600;"></div>
        </div>
        <div style="max-height:60vh; overflow-y:auto; border:1px solid #f0f3f5; border-radius:8px;">
            <table class="table-pfms" id="drill_table">
                <thead><tr><th>Node Agent</th><th>IP Address</th><th>Group</th><th>Interface</th><th>Informations</th><th>Last Update</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <div class="pagination-container" id="drill_pagination" style="margin-top:15px; border-radius:0 0 8px 8px;"></div>
    </div>
</div>

<div class="modal-overlay" id="nativeChartModal" onclick="closeNativeChartModal()">
    <div class="iframe-modal-box" onclick="event.stopPropagation()">
        <div class="iframe-header">
            <div class="iframe-title" id="nativeChartTitle"><span class="material-symbols-outlined" style="color:#004d40;">monitoring</span> Optical Trend Graph</div>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeNativeChartModal()">close</span>
        </div>
        <iframe id="nativeChartFrame" src="" style="width: 100%; height: 500px; border: none; background: #fff;"></iframe>
    </div>
</div>

<script>
const PANDORA_URL = "<?= h($PANDORA_BASE_URL) ?>";
const CSRF_TOKEN = "<?= $csrf_token ?>";
const IS_STANDALONE = <?= $isStandalone ? 'true' : 'false' ?>;
let dashboardCards = [], cardStates = {}, fullAgentsList = [], selectedIds = [], editingCardId = null;
let cardDataCache = {};
let drillAllData = [], drillFilteredData = [], drillPage = 1, drillLimit = 20;

async function init() {
    try { const res = await fetch('?api=load_config'); const data = await res.json(); if(Array.isArray(data)) dashboardCards = data; } catch(e){}
    fetch('?api=groups').then(r=>r.json()).then(data => { const sel = document.getElementById('b_group'); if(sel) { sel.innerHTML = ''; data.forEach(g => sel.add(new Option(g.name, g.id))); } });
    fetch('?api=agents_list').then(r=>r.json()).then(data => { fullAgentsList = data; renderAgentDropdown(); });
    renderGrid();
    
    const urlParams = new URLSearchParams(window.location.search);
    const filterId = urlParams.get('d');
    
    dashboardCards.forEach(c => { 
        if(filterId && c.id !== filterId) return;
        cardStates[c.id] = { page: 1, search: c.def_agent || '', timer: parseInt(c.refresh_sec) }; 
        fetchCardData(c.id); 
    });
    setInterval(heartbeat, 1000);
}

function renderGrid() {
    const grid = document.getElementById('dashboardGrid'); if(!grid) return; grid.innerHTML = '';
    const urlParams = new URLSearchParams(window.location.search);
    const filterId = urlParams.get('d');

    dashboardCards.forEach(c => {
        if(filterId && c.id !== filterId) return;
        if(!cardStates[c.id]) cardStates[c.id] = { page: 1, search: c.def_agent || '', timer: parseInt(c.refresh_sec) };
        const showStats = c.show_stats !== false;
        const showSearch = c.show_search !== false;
        const div = document.createElement('div'); div.className = 'dashboard-card'; div.id = 'card_'+c.id;
        div.innerHTML = `
            <div class="dashboard-card-header">
                <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="color:#004d40;">sensors</span> ${c.title}</h5>
                <div class="card-actions">
                    <button class="icon-btn-card" onclick="fetchCardData('${c.id}')" title="Refresh"><span class="material-symbols-outlined">sync</span></button>
                    <button class="icon-btn-card" onclick="openExportModal('${c.id}')" title="Export CSV/TXT"><span class="material-symbols-outlined">download</span></button>
                    ${!IS_STANDALONE ? `
                    <button class="icon-btn-card" onclick="copyShareLink('${c.id}')" title="Share Panel URL"><span class="material-symbols-outlined">share</span></button>
                    <button class="icon-btn-card" onclick="duplicateCard('${c.id}')" title="Duplicate"><span class="material-symbols-outlined">content_copy</span></button>
                    <button class="icon-btn-card" onclick="openEdit('${c.id}')" title="Edit Widget"><span class="material-symbols-outlined">edit</span></button>
                    <button class="icon-btn-card" onclick="deleteCard('${c.id}')" title="Delete"><span class="material-symbols-outlined" style="color:#e74c3c;">delete</span></button>
                    ` : ''}
                </div>
            </div>
            ${showStats ? `
            <div class="mini-stats-row">
                <div class="mini-stat st-border-black" onclick="openStatsDrilldown('${c.id}', 'total')"><div class="mini-stat-val text-black" id="st_tot_${c.id}">0</div><div class="mini-stat-label">TOTAL</div></div>
                <div class="mini-stat st-border-green" onclick="openStatsDrilldown('${c.id}', 'normal')"><div class="mini-stat-val text-green" id="st_norm_${c.id}">0</div><div class="mini-stat-label">NORMAL</div></div>
                <div class="mini-stat st-border-red" onclick="openStatsDrilldown('${c.id}', 'critical')"><div class="mini-stat-val text-red" id="st_crit_${c.id}">0</div><div class="mini-stat-label">CRITICAL</div></div>
                <div class="mini-stat st-border-yellow" onclick="openStatsDrilldown('${c.id}', 'warning')"><div class="mini-stat-val text-yellow" id="st_warn_${c.id}">0</div><div class="mini-stat-label">WARNING</div></div>
                <div class="mini-stat st-border-gray" onclick="openStatsDrilldown('${c.id}', 'unknown')"><div class="mini-stat-val text-gray" id="st_unk_${c.id}">0</div><div class="mini-stat-label">UNKNOWN</div></div>
            </div>` : ''}
            ${showSearch ? `
            <div class="toolbar-row">
                <div class="search-container">
                    <span class="material-symbols-outlined search-icon">search</span>
                    <input type="text" class="search-box" id="s_query_${c.id}" value="${cardStates[c.id].search}" placeholder="Search everything (Agent, Interface, SFP Info...)" onkeyup="onSearch('${c.id}')">
                </div>
            </div>` : ''}
            <div style="overflow-x:auto;"><table class="table-pfms" id="tbl_${c.id}"><thead><tr><th>Agent / Node</th><th>Interface</th><th>Informations</th><th>RX Power</th><th>TX Power</th><th>Update</th></tr></thead><tbody></tbody></table></div>
            <div class="pagination-container" id="pg_${c.id}"></div>
        `;
        grid.appendChild(div);
    });
}

function fetchCardData(id) {
    const c = dashboardCards.find(x => x.id === id); if(!c) return; const s = cardStates[id];
    const overlay = document.getElementById('loadingOverlay'); if(overlay) overlay.style.display = 'flex';
    fetch(`?api=card_data&group_id=${c.group_id}&limit=${c.limit}&page=${s.page}&search=${encodeURIComponent(s.search)}&manual_ids=${c.manual_ids||''}`)
    .then(r => r.json())
    .then(res => {
        if(overlay) overlay.style.display = 'none';
        if(!res.ok) { console.error(res.error); return; }
        cardDataCache[id] = res.data;
        ['tot','norm','crit','warn','unk'].forEach(k => { const el = document.getElementById(`st_${k}_${id}`); if(el) el.innerText = res.stats[k==='tot'?'total':k==='norm'?'normal':k==='crit'?'critical':k==='warn'?'warning':'unknown']; });
        const tbody = document.querySelector(`#tbl_${id} tbody`); if(!tbody) return; tbody.innerHTML = '';
        if(!res.data.length) tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:30px; color:#7f8c8d;">No optical data found.</td></tr>';
        res.data.forEach(r => {
            const tr = document.createElement('tr');
            const getStCls = (st) => st===0?'bg-ok':st===1?'bg-crit':st===2?'bg-warn':'bg-unk';
            const buildVal = (val, st, aid, mid, mname) => {
                if(val === 'N/A') return `<span style="color:#ccc;">N/A</span>`;
                const title = `${r.agent_name} - ${mname}`;
                return `<span class="val-badge ${getStCls(st)}">${val} <button onclick="openNativeChart(${mid}, '${title.replace(/'/g, "\\'")}')" class="hist-icon" title="View History"><span class="material-symbols-outlined" style="font-size:12px;">monitoring</span></button></span>`;
            };
            const editAgentLink = `${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${r.agent_id}`;
            const formatInfo = (txt) => {
                if(!txt) return '-';
                let clean = txt.replace(/^[RT]X\s+Optical\s+Power\s+\(dBm\)\s*-\s*/i, '');
                return clean.replace(' [', '<br><span style="opacity:0.7">[') + (clean.includes('[') ? '</span>' : '');
            };
            tr.innerHTML = `<td><div style="font-weight:600;"><a href="${editAgentLink}" target="_blank" class="agent-drill-link">${r.agent_name}</a></div><div style="font-size:10px; color:#94a3b8;">${r.group} | ${r.ip||'-'}</div></td>
                            <td style="font-weight:600; color:#004d40;">${r.interface}</td>
                            <td style="font-size:10px; color:#64748b; max-width:250px; line-height:1.4; padding-top:8px !important; padding-bottom:8px !important;">${formatInfo(r.description)}</td>
                            <td>${buildVal(r.rx_val, r.rx_st, r.agent_id, r.rx_id, 'RX Power')}</td><td>${buildVal(r.tx_val, r.tx_st, r.agent_id, r.tx_id, 'TX Power')}</td>
                            <td style="font-size:11px; color:#64748b;">${r.time_ago}</td>`;
            tbody.appendChild(tr);
        });
        renderPagination(id, res.total_found, c.limit);
    })
    .catch(err => { if(overlay) overlay.style.display = 'none'; console.error("Fetch Error:", err); });
}

async function openStatsDrilldown(id, type) {
    const c = dashboardCards.find(x=>x.id===id); if(!c) return;
    const s = cardStates[id];
    const overlay = document.getElementById('loadingOverlay');
    if(overlay) overlay.style.display = 'flex';

    try {
        const res = await fetch(`?api=card_data&group_id=${c.group_id}&limit=0&fetch_all=1&search=${encodeURIComponent(s.search)}&manual_ids=${c.manual_ids||''}`);
        const json = await res.json();
        if(overlay) overlay.style.display = 'none';
        if(!json.ok) return;

        const data = json.data;
        document.getElementById('drill_title').innerText = `${type.toUpperCase()} OPTICAL NODES (${c.title})`;
        document.getElementById('drill_search').value = '';
        
        drillAllData = data;
        if(type === 'normal') drillAllData = data.filter(r => Math.max(r.rx_st, r.tx_st) === 0);
        else if(type === 'critical') drillAllData = data.filter(r => Math.max(r.rx_st, r.tx_st) === 1);
        else if(type === 'warning') drillAllData = data.filter(r => Math.max(r.rx_st, r.tx_st) === 2);
        else if(type === 'unknown') drillAllData = data.filter(r => Math.max(r.rx_st, r.tx_st) >= 3 || Math.max(r.rx_st, r.tx_st) === null);

        drillFilteredData = [...drillAllData];
        drillPage = 1;
        renderDrillTable();
        document.getElementById('drilldownModal').style.display = 'flex';
    } catch(e) { 
        if(overlay) overlay.style.display = 'none';
        console.error("Drilldown fetch error:", e);
    }
}

function renderDrillTable() {
    const tbody = document.querySelector('#drill_table tbody'); tbody.innerHTML = '';
    const start = (drillPage - 1) * drillLimit;
    const paginated = drillFilteredData.slice(start, start + drillLimit);
    
    paginated.forEach(r => {
        const worst = Math.max(r.rx_st, r.tx_st);
        const dotColor = worst===0?'#2ecc71':worst===1?'#e74c3c':worst===2?'#f1c40f':'#95a5a6';
        const editAgentLink = `${PANDORA_URL}/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${r.agent_id}`;
        const tr = document.createElement('tr');
        const formatInfo = (txt) => {
            if(!txt) return '-';
            let clean = txt.replace(/^[RT]X\s+Optical\s+Power\s+\(dBm\)\s*-\s*/i, '');
            return clean.replace(' [', '<br><span style="opacity:0.7">[') + (clean.includes('[') ? '</span>' : '');
        };
        tr.innerHTML = `<td><span style="color:${dotColor}; font-size:20px; vertical-align:middle; line-height:1;">•</span> <a href="${editAgentLink}" target="_blank" class="agent-drill-link">${r.agent_name}</a></td>
                        <td><span style="color:#d32f2f;">${r.ip||'0.0.0.0'}</span></td>
                        <td style="font-size:12px; color:#64748b;">${r.group}</td>
                        <td style="font-weight:600;">${r.interface}</td>
                        <td style="font-size:10px; color:#64748b; max-width:200px; line-height:1.4;">${formatInfo(r.description)}</td>
                        <td style="font-size:11px; color:#64748b;">${r.time_ago}</td>`;
        tbody.appendChild(tr);
    });
    
    document.getElementById('drill_count').innerText = `${drillFilteredData.length} ROWS MATCHED`;
    renderDrillPagination();
}

function renderDrillPagination() {
    const bar = document.getElementById('drill_pagination');
    const total = drillFilteredData.length;
    const pages = Math.ceil(total / drillLimit);
    if(pages <= 1) { bar.innerHTML = ''; return; }
    
    bar.innerHTML = `<div><button class="pagination-btn" ${drillPage===1?'disabled':''} onclick="moveDrillPage(${drillPage-1})">Prev</button>
                     <span style="margin:0 10px; font-size:12px;">Page ${drillPage} of ${pages}</span>
                     <button class="pagination-btn" ${drillPage===pages?'disabled':''} onclick="moveDrillPage(${drillPage+1})">Next</button></div>`;
}

function moveDrillPage(p) { drillPage = p; renderDrillTable(); }

function filterDrilldownTable() {
    const kw = document.getElementById('drill_search').value.toLowerCase();
    drillFilteredData = drillAllData.filter(r => (r.agent_name + r.interface + r.group + (r.description||'')).toLowerCase().includes(kw));
    drillPage = 1;
    renderDrillTable();
}

function copyShareLink(id) {
    const url = new URL(window.location.href);
    url.searchParams.set('s', '1');
    url.searchParams.set('d', id);
    const shareUrl = url.toString();
    if(navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(shareUrl).then(() => alert('Share Link Copied!'));
    } else {
        const input = document.createElement('textarea'); input.value = shareUrl; document.body.appendChild(input); input.select();
        document.execCommand('copy'); document.body.removeChild(input); alert('Share Link Copied!');
    }
}

let curExportId = null;
function openExportModal(id) {
    curExportId = id; const c = dashboardCards.find(x=>x.id===id);
    document.getElementById('export_title').innerText = c.title;
    document.getElementById('exportDataModal').style.display = 'flex';
}

function processExportData() {
    const data = cardDataCache[curExportId]; if(!data) return;
    const format = document.getElementById('export_format').value;
    const title = dashboardCards.find(x=>x.id===curExportId).title;
    let content = "";
    if(format === 'csv') {
        content = "Agent Name,Group,IP Address,Interface,Informations,RX Power,TX Power,Last Update\n";
        data.forEach(r => { content += `"${r.agent_name}","${r.group}","${r.ip}","${r.interface}","${(r.description||'').replace(/"/g,'""')}","${r.rx_val}","${r.tx_val}","${r.time_ago}"\n`; });
    } else {
        content = `OPTICAL POWER METRICS REPORT: ${title}\nGenerated: ${new Date().toLocaleString()}\n` + "=".repeat(130) + "\n";
        content += sprintf("%-30s | %-20s | %-30s | %-12s | %-12s | %-15s\n", "Agent (Interface)", "Group", "Informations", "RX Power", "TX Power", "Last Update");
        content += "-".repeat(130) + "\n";
        data.forEach(r => { content += sprintf("%-30s | %-20s | %-30s | %-12s | %-12s | %-15s\n", `${r.agent_name} (${r.interface})`, r.group.substring(0,18), (r.description||'-').substring(0,28), r.rx_val, r.tx_val, r.time_ago); });
    }
    const blob = new Blob([content], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a'); a.href = url; a.download = `${title.replace(/\s+/g,'_')}_Export.${format}`; a.click();
    document.getElementById('exportDataModal').style.display = 'none';
}

function sprintf(format, ...args) {
    let i = 0; return format.replace(/%-(\d+)s/g, (match, width) => {
        let s = String(args[i++]); while(s.length < width) s += " "; return s;
    });
}

function openNativeChart(modId, title) {
    if(!modId || modId === 0) return;
    document.getElementById('nativeChartTitle').innerHTML = `<span class="material-symbols-outlined" style="color:#004d40;">monitoring</span> ${title}`;
    const url = `${PANDORA_URL}/operation/agentes/stat_win.php?type=sparse&period=86400&id=${modId}&refresh=600&period_graph=0&draw_events=0`;
    document.getElementById('nativeChartFrame').src = url;
    document.getElementById('nativeChartModal').style.display = 'flex';
}
function closeNativeChartModal() {
    document.getElementById('nativeChartModal').style.display = 'none';
    document.getElementById('nativeChartFrame').src = ''; 
}

function renderPagination(id, total, limit) {
    const bar = document.getElementById('pg_'+id); if(!bar) return; const s = cardStates[id]; const pages = Math.ceil(total / limit);
    if(pages <= 1) { bar.innerHTML = `<span style="font-size:11px; color:#7f8c8d;">Total: ${total} agents matched</span>`; return; }
    bar.innerHTML = `<div><button class="pagination-btn" ${s.page===1?'disabled':''} onclick="movePage('${id}',${s.page-1})">Prev</button>
                     <span style="margin:0 10px; font-size:12px;">Page ${s.page} of ${pages}</span>
                     <button class="pagination-btn" ${s.page===pages?'disabled':''} onclick="movePage('${id}',${s.page+1})">Next</button></div>
                     <span style="font-size:11px; color:#7f8c8d;">Total: ${total} agents matched</span>`;
}
function movePage(id, p) { cardStates[id].page = p; fetchCardData(id); }

function renderAgentDropdown() {
    const list = document.getElementById('agent_checkbox_list'); if(!list) return;
    list.innerHTML = fullAgentsList.map(a => `<div class="agent-item" data-name="${a.alias.toLowerCase()}"><input type="checkbox" id="chk_${a.id}" value="${a.id}" onchange="handleCheck(this)"><label for="chk_${a.id}">${a.alias}</label></div>`).join('');
}
function filterAgentsInList() {
    const kw = document.getElementById('inner_search').value.toLowerCase();
    document.querySelectorAll('.agent-item').forEach(item => { item.style.display = item.dataset.name.includes(kw) ? 'flex' : 'none'; });
}
function handleCheck(chk) {
    const val = chk.value; if(chk.checked) { if(!selectedIds.includes(val)) selectedIds.push(val); } else { selectedIds = selectedIds.filter(id => id !== val); }
    document.getElementById('sel_count').innerText = `${selectedIds.length} Selected`;
}
function toggleManualSelector() { const box = document.getElementById('manual_selector_box'); if(box) box.style.display = (document.getElementById('b_group').value === '0') ? 'block' : 'none'; }
let tSearch = null;
function onSearch(id) { if(tSearch) clearTimeout(tSearch); tSearch = setTimeout(() => { cardStates[id].search = document.getElementById('s_query_'+id).value; cardStates[id].page = 1; fetchCardData(id); }, 500); }
function heartbeat() { 
    const urlParams = new URLSearchParams(window.location.search);
    const filterId = urlParams.get('d');
    dashboardCards.forEach(c => { 
        if(filterId && c.id !== filterId) return;
        if(cardStates[c.id]) { 
            cardStates[c.id].timer--; 
            if(cardStates[c.id].timer <= 0) { fetchCardData(c.id); cardStates[c.id].timer = parseInt(c.refresh_sec); } 
        } 
    }); 
}
function openBuilder() { 
    editingCardId = null; selectedIds = [];
    document.getElementById('b_title').value = ''; 
    document.getElementById('b_def_agent').value = '';
    document.getElementById('b_def_mod').value = '';
    document.getElementById('b_show_stats').checked = true;
    document.getElementById('b_show_search').checked = true;
    document.querySelectorAll('#agent_checkbox_list input').forEach(i => i.checked = false);
    document.getElementById('builderModal').style.display = 'flex'; 
    toggleManualSelector();
}
function openEdit(id) { 
    editingCardId = id; const c = dashboardCards.find(x=>x.id===id); 
    document.getElementById('b_title').value = c.title; 
    document.getElementById('b_group').value = c.group_id; 
    document.getElementById('b_def_agent').value = c.def_agent || '';
    document.getElementById('b_def_mod').value = c.def_mod || '';
    document.getElementById('b_limit').value = c.limit; 
    document.getElementById('b_refresh').value = c.refresh_sec; 
    document.getElementById('b_show_stats').checked = (c.show_stats !== false);
    document.getElementById('b_show_search').checked = (c.show_search !== false);
    selectedIds = (c.manual_ids || '').split(',').filter(x => x);
    document.querySelectorAll('#agent_checkbox_list input').forEach(i => i.checked = selectedIds.includes(i.value));
    document.getElementById('sel_count').innerText = `${selectedIds.length} Selected`;
    document.getElementById('builderModal').style.display = 'flex'; 
    toggleManualSelector();
}
function closeBuilder() { document.getElementById('builderModal').style.display = 'none'; }
function saveWidget() {
    const c = { 
        id: editingCardId || 'c'+Date.now(), 
        title: document.getElementById('b_title').value || 'Optical Metrics', 
        group_id: document.getElementById('b_group').value, 
        def_agent: document.getElementById('b_def_agent').value,
        def_mod: document.getElementById('b_def_mod').value,
        manual_ids: selectedIds.join(','),
        limit: document.getElementById('b_limit').value, 
        refresh_sec: document.getElementById('b_refresh').value,
        show_stats: document.getElementById('b_show_stats').checked,
        show_search: document.getElementById('b_show_search').checked
    };
    if(editingCardId) dashboardCards = dashboardCards.map(x=>x.id===editingCardId?c:x); else dashboardCards.push(c);
    fetch('?api=save_config', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF_TOKEN }, body: JSON.stringify(dashboardCards) }).then(() => { 
        cardStates[c.id] = { page: 1, search: c.def_agent, timer: parseInt(c.refresh_sec) };
        renderGrid(); fetchCardData(c.id); closeBuilder(); 
    });
}
function duplicateCard(id) {
    const c = JSON.parse(JSON.stringify(dashboardCards.find(x=>x.id===id)));
    c.id = 'c'+Date.now(); c.title += ' (Copy)';
    dashboardCards.push(c);
    fetch('?api=save_config', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF_TOKEN }, body: JSON.stringify(dashboardCards) }).then(() => {
        cardStates[c.id] = { page: 1, search: c.def_agent, timer: parseInt(c.refresh_sec) };
        renderGrid(); fetchCardData(c.id);
    });
}
function deleteCard(id) { if(confirm('Delete widget?')) { dashboardCards = dashboardCards.filter(x=>x.id!==id); fetch('?api=save_config', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF_TOKEN }, body: JSON.stringify(dashboardCards) }).then(() => renderGrid()); } }
function exportConfig() { const blob = new Blob([JSON.stringify(dashboardCards)], {type: 'application/json'}); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = 'optical-power-config.json'; a.click(); }
function importConfig(e) { const file = e.target.files[0]; if(!file) return; const r = new FileReader(); r.onload = (ev) => { try { const d = JSON.parse(ev.target.result); if(Array.isArray(d)) { dashboardCards = d; fetch('?api=save_config', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF_TOKEN }, body: JSON.stringify(dashboardCards) }).then(() => renderGrid()); } } catch(err){alert('Invalid config');} }; r.readAsText(file); }
function h(s) { return s.replace(/[&<>"']/g, function(m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]; }); }

init();
</script>
</body>
</html>
