<?php
/**
 * PANDORA FMS - UNIVERSAL HEATMAP DASHBOARD (REBUILT v7.9.3)
 * UI Sync: Hide branding header for seamless portal integration & font matching
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
header_remove("X-Frame-Options"); 
header("X-Frame-Options: ALLOWALL");
header("Content-Security-Policy: frame-ancestors *"); 

$dynamic_breadcrumb = "PANDORA CONSOLE / CUSTOM / PANEL / DASHBOARD / RACK HEATMAP";
$PANDORA_BASE_URL = "/pandora_console";
$v = time(); 

// 1. CONFIG LOADING
$CONFIG_FILE = __DIR__ . '/grid-health-master.json';
$config_paths = ['/var/www/html/pandora_console/include/config.php'];
$config_loaded = false;
foreach ($config_paths as $path) { if (file_exists($path)) { require_once($path); $config_loaded = true; break; } }

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';

require_once(__DIR__ . '/../../tools/utils.php');

$pdo = null; $db_status = false;
if ($config_loaded) {
    try { $pdo = get_db_connection($config); $db_status = true; } catch (PDOException $e) {}
}

// Multi-Dashboard Logic
$dash_id = $_GET['dash_id'] ?? null;
$panel_id = $_GET['panel_id'] ?? null;
$master_config = [];
if (file_exists($CONFIG_FILE)) {
    $master_config = json_decode(file_get_contents($CONFIG_FILE), true) ?: [];
}

// Fallback: If only panel_id is provided, find its dashboard
if (!$dash_id && $panel_id) {
    foreach ($master_config as $d) {
        foreach ($d['panels'] ?? [] as $p) {
            if ($p['id'] === $panel_id) { $dash_id = $d['id']; break 2; }
        }
    }
}

$current_dashboard = null;
if ($dash_id) {
    foreach ($master_config as $d) {
        if ($d['id'] === $dash_id) { $current_dashboard = $d; break; }
    }
}

$current_config = $current_dashboard ?: [ 'id' => 'default', 'title' => 'Main Dashboard', 'refresh_sec' => 60, 'panels' => [] ];
$show_list = (!$dash_id && !$isPure && !$panel_id);
$agentAliasMap = [];
if ($db_status) {
    try {
        $resA = $pdo->query("SELECT id_agente, alias FROM tagente WHERE disabled = 0");
        while ($rA = $resA->fetch(PDO::FETCH_ASSOC)) { $agentAliasMap[strtolower(trim($rA['alias']))] = $rA['id_agente']; }
    } catch (Exception $e) {}
}

// 2. AJAX API
$api = $_GET['api'] ?? '';
if ($api === 'save_master') {
    ob_clean(); header('Content-Type: application/json');
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($client_token !== $csrf_token) { echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']); exit; }
    $input = file_get_contents('php://input');
    $bytes = @file_put_contents($CONFIG_FILE, $input);
    echo json_encode(['ok' => $bytes !== false]); exit;
}
if ($api === 'get_master') {
    ob_clean(); header('Content-Type: application/json');
    echo json_encode($master_config); exit;
}
if ($api === 'get_resources' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $groups = $pdo->query("SELECT id_grupo as id, nombre as name FROM tgrupo ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    $agents = $pdo->query("SELECT id_agente as id, alias, nombre, id_grupo FROM tagente WHERE disabled = 0 ORDER BY alias ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['groups' => $groups, 'agents' => $agents]); exit;
}
if ($api === 'get_agent_modules' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $aid = $_GET['agent_id'] ?? 0;
    $modules = $pdo->prepare("SELECT DISTINCT nombre FROM tagente_modulo WHERE disabled = 0 AND id_agente = ? ORDER BY nombre ASC");
    $modules->execute([$aid]);
    echo json_encode($modules->fetchAll(PDO::FETCH_COLUMN)); exit;
}

// 3. FETCH DATA
$allModuleStatus = []; 
if ($db_status) {
    try {
        foreach ($current_config['panels'] as $p) {
            $gm = trim($p['global_module'] ?? ''); $ovs = $p['overrides'] ?? [];
            $toFetch = [];
            foreach($ovs as $ov){ if($ov['agent_id']&&$ov['module_name']) $toFetch[]=['aid'=>$ov['agent_id'],'mname'=>$ov['module_name']]; }
            if($gm !== ''){
                foreach($p['rows'] as $r){ foreach($p['cols'] as $c){
                    $key = str_replace(['{row}','{col}'],[$r,$c],$p['mapping_pattern']);
                    if(!isset($ovs[$key])){ $aid=$agentAliasMap[strtolower(trim($key))]??null; if($aid) $toFetch[]=['aid'=>$aid,'mname'=>$gm]; }
                }}
            }
            foreach ($toFetch as $mtf) {
                $aid = (string)$mtf['aid']; $mname = trim((string)$mtf['mname']);
                $ck = $aid.'|'.strtolower(trim($mname)); if (isset($allModuleStatus[$ck])) continue;
                $v_status = 4; $last_upd = 'N/A';
                if ($mname === '[ALL MODULES]') {
                    $sqlM = "SELECT te.estado, te.utimestamp FROM tagente_modulo m JOIN tagente_estado te ON m.id_agente_modulo = te.id_agente_modulo WHERE m.disabled = 0 AND m.id_agente = ? ORDER BY CASE te.estado WHEN 1 THEN 0 WHEN 2 THEN 1 WHEN 3 THEN 2 WHEN 0 THEN 3 ELSE 4 END ASC";
                    $stmtM = $pdo->prepare($sqlM); $stmtM->execute([$aid]);
                    $rowsM = $stmtM->fetchAll(PDO::FETCH_ASSOC);
                    if ($rowsM) {
                        $v_status = $rowsM[0]['estado'];
                        $maxTs = 0; foreach($rowsM as $rm){ if($rm['utimestamp'] > $maxTs) $maxTs = $rm['utimestamp']; }
                        $last_upd = ($maxTs > 0) ? date("Y-m-d H:i:s", $maxTs) : 'N/A';
                    }
                } else {
                    $sqlM = "SELECT te.datos, COALESCE(te.estado, 4) as estado, te.utimestamp FROM tagente_modulo m JOIN tagente_estado te ON m.id_agente_modulo = te.id_agente_modulo WHERE m.disabled = 0 AND m.id_agente = ? AND (m.nombre = ? OR m.nombre LIKE ?)";
                    $stmtM = $pdo->prepare($sqlM); $stmtM->execute([$aid, $mname, '%' . str_replace(' ', '%', $mname) . '%']);
                    $rowM = $stmtM->fetch(PDO::FETCH_ASSOC);
                    if ($rowM) {
                        $rawVal = strtoupper(trim((string)$rowM['datos'])); $v_status = (int)$rowM['estado'];
                        if (preg_match('/^[01](\.0+)?$/', $rawVal)) { $numCheck = (float)$rawVal; $v_status = ($numCheck >= 1) ? 0 : 1; }
                        if (strpos($rawVal,'RUNNING')!==false||strpos($rawVal,'OK')!==false||strpos($rawVal,'UP')!==false) $v_status=0;
                        elseif (strpos($rawVal,'STOPPED')!==false||strpos($rawVal,'DOWN')!==false||strpos($rawVal,'CRIT')!==false) $v_status=1;
                        elseif (strpos($rawVal,'WARN')!==false) $v_status=2;
                        $last_upd = ($rowM['utimestamp'] > 0) ? date("Y-m-d H:i:s", $rowM['utimestamp']) : 'N/A';
                    }
                }
                $allModuleStatus[$ck] = ['status' => $v_status, 'last_update' => $last_upd];
            }
        }
    } catch (Exception $e) {}
}

function getStatusStyle($status) {
    if ($status === null || $status === -1) return ['color' => '#f1f5f9', 'label' => '---', 'class' => 'st-unk'];
    switch((int)$status) {
        case 0: return ['color' => 'linear-gradient(135deg, #2ecc71, #27ae60)', 'label' => 'UP', 'class' => 'st-up'];
        case 2: return ['color' => 'linear-gradient(135deg, #f1c40f, #f39c12)', 'label' => 'WARN', 'class' => 'st-warn'];
        case 1: return ['color' => 'linear-gradient(135deg, #e74c3c, #c0392b)', 'label' => 'DOWN', 'class' => 'st-down'];
        default: return ['color' => '#f1f5f9', 'label' => '---', 'class' => 'st-unk'];
    }
}

$isPure = (isset($_GET['pure']) && $_GET['pure'] == 1);
$isStandalone = (isset($_GET['standalone']) && $_GET['standalone'] == 1);
$target_panel_id = $_GET['panel_id'] ?? null;
$panels_to_render = $current_config['panels'];
if ($isPure && $target_panel_id) {
    $panels_to_render = array_filter($current_config['panels'], function($p) use ($target_panel_id) { return $p['id'] == $target_panel_id; });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Rack Heatmap Overview</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20,400,0,0">
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; background-color: #f4f6f8; margin: 0; padding: 0; color: #334155; font-size: 14px; -webkit-font-smoothing: antialiased; }
        
        .pandora-header-top { display: <?= $isStandalone ? 'flex' : 'none' ?>; background: #fff; border-bottom: 1px solid #e0e4e8; height: 60px; align-items: center; justify-content: space-between; padding: 0 25px; }
        .header-logo { height: 24px; width: auto; }
        .header-divider { width: 1px; height: 28px; background: #dce1e5; margin: 0 20px; }
        
        .pandora-header-bottom { padding: 15px 30px; display: <?= $isPure ? 'none' : 'flex' ?>; align-items: center; justify-content: space-between; background: #f4f6f8; }
        .breadcrumb-box { display: flex; flex-direction: column; }
        .page-breadcrumb { font-size: 11px !important; color: #64748b !important; margin-bottom: 4px; font-weight: normal !important; text-transform: uppercase; letter-spacing: 0.5px; }
        .page-title { font-size: 18px !important; color: #0b1a26 !important; margin: 0; font-weight: 600 !important; line-height: 1.1; display:flex; align-items:center; gap:8px;}
        
        .btn-apply { background: #004d40; color: #fff !important; border: none; padding: 8px 18px; border-radius: 4px; font-weight: normal !important; font-size: 13px; display: inline-flex; align-items: center; gap: 8px; cursor: pointer; transition: 0.2s; height: 36px; }
        .btn-apply:hover { background: #00332a; }
        
        .toolbar-pill { background: #fff; border: 1px solid #dce1e5; border-radius: 6px; padding: 4px 10px; display: flex; align-items: center; gap: 8px; height: 36px; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .refresh-select { border: none; background: transparent; font-size: 12px; font-weight: normal; color: #004d40; outline: none; cursor: pointer; }
        .timer-display { font-size: 11px; font-weight: normal; color: #e67e22; border-left: 1px solid #dce1e5; padding-left: 10px; min-width: 40px; text-align: right; }

        .dashboard-card { background: #fff; border-radius: 8px; border: 1px solid #e0e4e8; margin-bottom: 20px; overflow: hidden; width: 100%; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .dashboard-card-header { padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; background: #fafbfc; border-bottom: 1px solid #f0f3f5; }
        
        .icon-btn { border: none; background: none; color: #7f8c8d; padding: 0; cursor: pointer; border-radius: 4px; display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; transition: 0.2s; }
        .icon-btn:hover { background: #e0e4e8; color: #0b1a26; }
        .icon-btn .material-symbols-outlined { font-size: 18px !important; }

        .mini-stats-row { display: flex; gap: 12px; padding: 10px 20px; background: #fafbfc; border-bottom: 1px solid #f0f3f5; }
        .mini-stat { flex: 1; text-align: center; padding: 8px; background: #fff; border: 1px solid #e0e4e8; border-radius: 6px; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .mini-stat-val { font-size: 14px; font-weight: 700; color: #1e293b; }
        .mini-stat-label { font-size: 10px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; }

        .heatmap-table { border-collapse: separate; border-spacing: 6px; margin: 0 auto; width: 100%; }
        .cell { 
            width: auto; min-width: 60px; height: 36px; border-radius: 4px; cursor: pointer; 
            border: 1px solid rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.1); transition: transform 0.2s;
        }
        .cell:hover { transform: scale(1.05); z-index: 10; box-shadow: 0 6px 12px rgba(0,0,0,0.15); }
        .st-unk { background: #f8fafc; color: #cbd5e1 !important; text-shadow: none; }
        
        .axis-label { font-size: 10px; font-weight: 600; color: #94a3b8; text-transform: uppercase; padding: 5px; letter-spacing: 0.5px; }
        .col-header { font-size: 10px; font-weight: 600; color: #64748b; text-align: center; padding: 5px 0; }
        .row-header { font-size: 12px; font-weight: 700; color: #334155; padding-right: 20px; text-align: right; white-space: nowrap; }

        .modal-content { font-family: 'Inter', sans-serif !important; border-radius: 10px; border: none; box-shadow: 0 15px 40px rgba(0,0,0,0.2); }
        .modal-header { background: #fafbfc; border-bottom: 1px solid #e0e4e8; }
        .form-control { font-size: 13px !important; border-color: #dce1e5; padding: 8px 12px; border-radius: 4px; }
        .form-control:focus { border-color: #004d40; box-shadow: 0 0 0 2px rgba(0,77,64,0.1); }
        .form-label-muted { font-size: 10px; font-weight: 600; color: #7f8c8d; text-transform: uppercase; margin-bottom: 6px; display: block; letter-spacing: 0.5px; }
        
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: none; align-items: center; justify-content: center; z-index: 2000; backdrop-filter: blur(2px); padding: 20px; }
        .modal-box { background: #fff; width: 800px; border-radius: 12px; box-shadow: 0 20px 50px rgba(0,0,0,0.25); border: 1px solid #e0e4e8; display: flex; flex-direction: column; max-height: 90vh; overflow: hidden; }
        .modal-header-custom { padding: 20px 25px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; background: #fafbfc; }
        .modal-body-scroll { padding: 25px; overflow-y: auto; flex: 1; }
        .modal-footer-custom { padding: 15px 25px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end; gap: 10px; background: #fafbfc; }

        .tag-container { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px; border: 1px solid #dce1e5; border-radius: 6px; background: #fff; min-height: 42px; align-items: center; }
        .grid-tag { background: #e0f2f1; color: #004d40; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; display: flex; align-items: center; gap: 6px; border: 1px solid #b2dfdb; }
        .grid-tag span:last-child { color: #00695c; font-size: 14px; line-height: 1; margin-top: -1px; }
        .tag-input-box { border: none; outline: none; font-size: 12px; flex: 1; min-width: 60px; color: #334155; }

        .heatmap-container { display: flex; flex-direction: column; gap: 15px; width: 100%; }
        .rack-row-wrap { display: flex; gap: 15px; align-items: flex-start; background: #fff; padding: 10px; border-radius: 8px; border: 1px solid #f1f5f9; }
        .rack-row-label { width: 80px; font-size: 12px; font-weight: 800; color: #1e293b; text-align: right; padding-top: 10px; flex-shrink: 0; }
        .cells-grid { display: flex; flex-wrap: wrap; gap: 6px; flex: 1; }
        .cell-unit { display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .cell-label { font-size: 9px; font-weight: 600; color: #94a3b8; }
        
        .axis-title-x { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; text-align: center; margin-bottom: 15px; padding-left: 80px; letter-spacing: 1px; }
        .axis-title-y-wrapper { display: flex; align-items: stretch; gap: 15px; }
        .axis-title-y { writing-mode: vertical-rl; transform: rotate(180deg); font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; text-align: center; letter-spacing: 1px; border-right: 1px solid #e2e8f0; padding-left: 10px; }
        
        .cells-grid > * { flex: 0 0 calc(5% - 6px); min-width: 60px; }
        
        html, body { width: 100% !important; margin: 0; padding: 0; overflow-x: hidden; }
        .dashboard-grid { display: grid; gap: 20px; padding: 20px; width: 100% !important; max-width: 100% !important; box-sizing: border-box; }
        .layout-1 { grid-template-columns: 1fr !important; }
        .layout-2 { grid-template-columns: 1fr 1fr !important; }
        .layout-3 { grid-template-columns: 1fr 1fr 1fr !important; }
        .panel-wrapper { display: flex; flex-direction: column; background: #fff; border-radius: 10px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; overflow: hidden; min-width: 0; width: 100% !important; }
        
        .panel-header-custom { background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #334155; padding: 10px 15px; display: flex; justify-content: space-between; align-items: center; font-weight: 700; font-size: 13px; }
        .panel-body-custom { padding: 15px; flex: 1; display: flex; flex-direction: column; }
        
        .bulk-action { background:#f0f9ff !important; color:#0369a1 !important; font-weight:700 !important; border-bottom:1px solid #bae6fd !important; position: sticky; top: 0; z-index: 10; cursor: pointer; }
        .bulk-action:hover { background:#e0f2fe !important; }

        .search-list { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #dce1e5; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1000; display: none; overflow: hidden; }
        .search-item { padding: 8px 12px; font-size: 12px; cursor: pointer; transition: 0.15s; color: #334155; border-bottom: 1px solid #f8fafc; }
        .search-item:hover { background: #f1f5f9; color: #004d40; }
        .search-results-area { max-height: 200px; overflow-y: auto; }
        .search-pagination-area { padding: 8px; background: #fafbfc; border-top: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center; }
        .btn-page-nav { border: 1px solid #dce1e5; background: #fff; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; cursor: pointer; }
        .btn-page-nav:disabled { opacity: 0.5; cursor: default; }
        .pg-indicator { font-size: 10px; font-weight: 700; color: #64748b; }

        .toast-msg { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); background: #004d40; color: #fff; padding: 10px 25px; border-radius: 30px; z-index: 10000; display: none; align-items: center; gap: 10px; font-weight: 500; font-size: 13px; box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        
        .stat-pill { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 20px; padding: 4px 12px; display: flex; align-items: center; gap: 8px; font-size: 11px; font-weight: 700; color: #475569; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .st-dot { width: 8px; height: 8px; border-radius: 50%; }

        /* Dashboard List Enhancements */
        .dash-list-table { border-collapse: separate; border-spacing: 0; width: 100%; }
        .dash-list-table thead th { background: #fafbfc; color: #64748b; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; padding: 15px 20px; border-bottom: 1px solid #e0e4e8; }
        .dash-list-table tbody td { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .dash-list-table tbody tr:hover td { background: #f8fafc; }
        .dash-link { color: #1976d2; text-decoration: none; font-weight: 600; transition: 0.2s; }
        .dash-link:hover { color: #0d47a1; text-decoration: underline; }
        
        .btn-action { background: #fff; border: 1px solid #dce1e5; color: #64748b; padding: 6px 12px; border-radius: 4px; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; transition: 0.2s; cursor: pointer; text-decoration: none; }
        .btn-action:hover { background: #f1f5f9; color: #0b1a26; border-color: #b5c1c9; }
        .btn-action.btn-delete:hover { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .btn-action .material-symbols-outlined { font-size: 16px !important; }

        .breadcrumb-link { color: #64748b; text-decoration: none; transition: 0.2s; font-weight: 500; }
        .breadcrumb-link:hover { color: #004d40; }

        /* Status Detail Modal Styles */
        .status-pill { cursor: pointer; transition: transform 0.15s, box-shadow 0.15s; }
        .status-pill:hover { transform: translateY(-1px); box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-color: #cbd5e1; }
        .status-badge { padding: 3px 10px; border-radius: 4px; font-size: 10px; font-weight: 700; color: #fff; text-transform: uppercase; min-width: 60px; display: inline-block; text-align: center; }
        .bg-ok { background: #2ecc71; }
        .bg-warn { background: #f1c40f; }
        .bg-crit { background: #e74c3c; }
        .bg-unknown { background: #94a3b8; }
        
        .table-detail th { background: #f8fafc; color: #64748b; font-size: 10px; font-weight: 600; text-transform: uppercase; padding: 12px 15px; border-bottom: 2px solid #e2e8f0; }
        .table-detail td { padding: 12px 15px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    </style>
</head>
<body class="<?= ($isPure || $isStandalone === false) ? '' : 'standalone-mode' ?> <?= $isPure ? 'pure-mode' : '' ?>">
<div class="toast-msg" id="shareToast"><span class="material-symbols-outlined">link</span> Link Copied!</div>

<div class="pandora-header-top">
    <div class="d-flex align-items-center">
        <div style="display:flex; align-items:center;">
            <span style="color:#004d40; font-weight:900; font-size:16px; letter-spacing:-0.5px;">PANDORA</span>
            <span style="color:#10b981; font-weight:400; font-size:16px; margin-left:2px;">FMS</span>
        </div>
        <div class="header-divider"></div>
        <div>
            <span style="font-size:9px; font-weight:800; color:#0b1a26; display:block;">CUSTOM PANEL</span>
            <span style="font-size:7px; color:#94a3b8;">Universal Heatmap</span>
        </div>
    </div>
</div>

<div class="pandora-header-bottom">
    <div class="breadcrumb-box">
        <span class="page-breadcrumb">
            <a href="?" class="breadcrumb-link">DASHBOARDS</a> 
            <?= $dash_id ? ' <span style="margin:0 4px; opacity:0.5;">/</span> ' . htmlspecialchars($current_config['title']) : '' ?>
        </span>
        <h1 class="page-title"><?= $dash_id ? htmlspecialchars($current_config['title']) : 'Grid Health Overview' ?></h1>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <?php if (!$dash_id): ?>
            <button class="btn-apply" onclick="openDashMetaModal()">
                <span class="material-symbols-outlined" style="font-size:18px;">add</span> New Dashboard
            </button>
        <?php else: ?>
            <div class="toolbar-pill">
                <span class="material-symbols-outlined" style="color:#94a3b8; font-size:14px !important;">sync</span>
                <select class="refresh-select" onchange="updateGlobalRefresh(this.value)">
                    <option value="0" <?= $current_config['refresh_sec'] == 0 ? 'selected' : '' ?>>Off</option>
                    <option value="10" <?= $current_config['refresh_sec'] == 10 ? 'selected' : '' ?>>10s</option>
                    <option value="30" <?= $current_config['refresh_sec'] == 30 ? 'selected' : '' ?>>30s</option>
                    <option value="60" <?= $current_config['refresh_sec'] == 60 ? 'selected' : '' ?>>1m</option>
                    <option value="120" <?= $current_config['refresh_sec'] == 120 ? 'selected' : '' ?>>2m</option>
                    <option value="300" <?= $current_config['refresh_sec'] == 300 ? 'selected' : '' ?>>5m</option>
                    <option value="600" <?= $current_config['refresh_sec'] == 600 ? 'selected' : '' ?>>10m</option>
                </select>
                <div id="refreshIndicator" class="timer-display"></div>
            </div>
            <div class="toolbar-pill">
                <span class="material-symbols-outlined" style="color:#94a3b8; font-size:14px !important;">grid_view</span>
                <select class="refresh-select" onchange="updateLayout(this.value)">
                    <option value="1" <?= ($current_config['layout_cols'] ?? 1) == 1 ? 'selected' : '' ?>>1 Column</option>
                    <option value="2" <?= ($current_config['layout_cols'] ?? 1) == 2 ? 'selected' : '' ?>>2 Columns</option>
                    <option value="3" <?= ($current_config['layout_cols'] ?? 1) == 3 ? 'selected' : '' ?>>3 Columns</option>
                </select>
            </div>
            <button class="btn-apply" onclick="addNewPanel()">
                <span class="material-symbols-outlined" style="font-size:18px;">add</span> New Rack
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($show_list): ?>
<div id="view_list" class="p-4">
    <div class="dashboard-card">
        <div class="p-0">
            <table class="dash-list-table">
                <thead>
                    <tr>
                        <th>Dashboard Name</th>
                        <th>Rack Count</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($master_config)): ?>
                        <tr><td colspan="3" class="text-center py-5 text-muted">No dashboards found. Create one to get started.</td></tr>
                    <?php else: ?>
                        <?php foreach ($master_config as $idx => $d): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <span class="material-symbols-outlined" style="color:#94a3b8;">dashboard</span>
                                    <a href="?dash_id=<?= $d['id'] ?>" class="dash-link"><?= htmlspecialchars($d['title']) ?></a>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border fw-normal" style="font-size:11px;"><?= count($d['panels'] ?? []) ?> Racks</span>
                            </td>
                            <td style="text-align:right;">
                                <button class="btn-action" onclick="openDashMetaModal(<?= $idx ?>)">
                                    <span class="material-symbols-outlined">edit</span> Edit
                                </button>
                                <button class="btn-action" onclick="duplicateDashboard(<?= $idx ?>)">
                                    <span class="material-symbols-outlined">content_copy</span> Duplicate
                                </button>
                                <button class="btn-action btn-delete" onclick="deleteDashboard(<?= $idx ?>)">
                                    <span class="material-symbols-outlined">delete</span> Delete
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>

<div class="dashboard-grid <?= ($isPure && $target_panel_id) ? 'layout-1' : 'layout-'.($current_config['layout_cols'] ?? 1) ?>">
    <?php foreach ($panels_to_render as $idx => $panel): 
        $rows = $panel['rows'] ?? []; $cols = $panel['cols'] ?? [];
        $rowLabel = $panel['row_label'] ?? 'RACK'; $colLabel = $panel['col_label'] ?? 'SLOT';
        $pStats = ['ok'=>0, 'warn'=>0, 'crit'=>0, 'tot'=>0];
        $pDetails = [];
    ?>
    <div class="panel-wrapper" id="<?= $panel['id'] ?>">
        <div class="panel-header-custom">
            <div class="d-flex align-items-center gap-2">
                <span class="material-symbols-outlined" style="font-size:18px; color:#64748b;">grid_view</span>
                <span><?= htmlspecialchars($panel['title']) ?></span>
            </div>
            <div class="action-group d-flex gap-1">
                <button class="icon-btn" onclick="sharePanel('<?= $panel['id'] ?>')" title="Share"><span class="material-symbols-outlined">share</span></button>
                <button class="icon-btn" onclick="duplicatePanel(<?= $idx ?>)" title="Duplicate"><span class="material-symbols-outlined">content_copy</span></button>
                <button class="icon-btn" onclick="editPanel(<?= $idx ?>)" title="Edit"><span class="material-symbols-outlined">edit</span></button>
                <button class="icon-btn btn-delete text-danger" onclick="deletePanel(<?= $idx ?>)" title="Delete"><span class="material-symbols-outlined">delete_outline</span></button>
            </div>
        </div>
        <div class="panel-body-custom">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex gap-3">
                    <?php if ($panel['show_stats'] ?? true): ?>
                        <?php if ($panel['stats_ok'] ?? true): ?>
                        <div class="stat-pill" onclick="showStatusDetails('<?= $panel['id'] ?>', 0)"><div class="st-dot" style="background:#2ecc71;"></div> <span id="stat_ok_<?= $panel['id'] ?>">0</span> OK</div>
                        <?php endif; ?>
                        <?php if ($panel['stats_warn'] ?? true): ?>
                        <div class="stat-pill" onclick="showStatusDetails('<?= $panel['id'] ?>', 2)"><div class="st-dot" style="background:#f1c40f;"></div> <span id="stat_warn_<?= $panel['id'] ?>">0</span> WARN</div>
                        <?php endif; ?>
                        <?php if ($panel['stats_crit'] ?? true): ?>
                        <div class="stat-pill" onclick="showStatusDetails('<?= $panel['id'] ?>', 1)"><div class="st-dot" style="background:#e74c3c;"></div> <span id="stat_crit_<?= $panel['id'] ?>">0</span> CRIT</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($colLabel): ?>
                <div class="axis-title-x" style="padding-left: <?= ($rowLabel) ? '95px' : '80px' ?>;"><?= htmlspecialchars($colLabel) ?></div>
            <?php endif; ?>
            
            <div class="axis-title-y-wrapper">
                <?php if ($rowLabel): ?>
                    <div class="axis-title-y"><?= htmlspecialchars($rowLabel) ?></div>
                <?php endif; ?>
                
                <div class="heatmap-container">
                    <?php foreach($rows as $r): 
                        // Pre-calculate which cells in this row have assignments
                        $activeRowCells = [];
                        foreach($cols as $c) {
                            $key = str_replace(['{row}', '{col}'], [$r, $c], $panel['mapping_pattern']);
                            $ov = $panel['overrides'][$key] ?? null;
                            if (!$ov && trim($panel['global_module'] ?? '') !== '') {
                                $aid = $agentAliasMap[strtolower(trim($key))] ?? null;
                                if ($aid) { $ov = ['agent_id' => $aid, 'agent_alias' => $key, 'module_name' => $panel['global_module']]; }
                            }
                            if ($ov && $ov['agent_id']) {
                                $activeRowCells[$c] = $ov;
                            }
                        }
                        
                        // Skip the entire row if no cells have data
                        if (empty($activeRowCells)) continue;
                    ?>
                    <div class="rack-row-wrap">
                        <div class="rack-row-label" style="text-align: <?= $panel['row_align'] ?? 'right' ?>; padding-top: <?= ($panel['col_pos'] ?? 'top') === 'top' ? '12px' : '2px' ?>;"><?= htmlspecialchars($r) ?></div>
                        <div class="cells-grid">
                            <?php foreach($cols as $c): 
                                if (!isset($activeRowCells[$c])) continue; // Skip undefined cells
                                $ov = $activeRowCells[$c];
                                
                                $status = -1; $ovInfo = null;
                                $ovInfo = $allModuleStatus[(string)$ov['agent_id'].'|'.strtolower(trim((string)$ov['module_name']))] ?? null;
                                $status = $ovInfo ? $ovInfo['status'] : -1;
                                if ($status == 0) $pStats['ok']++; elseif ($status == 1) $pStats['crit']++; elseif ($status == 2) $pStats['warn']++;
                                $pStats['tot']++;
                                
                                $pDetails[] = [
                                    'agent_id' => $ov['agent_id'],
                                    'agent_alias' => $ov['agent_alias'],
                                    'module_name' => $ov['module_name'],
                                    'status' => $status,
                                    'ip_address' => $ovInfo['ip_address'] ?? 'N/A',
                                    'last_update' => $ovInfo ? date('d M H:i', strtotime($ovInfo['last_update'])) : 'N/A'
                                ];

                                $style = getStatusStyle($status);
                                $tooltip = "Agent: ".($ov['agent_alias'] ?? 'None')."\nModule: ".($ov['module_name'] ?? 'None')."\nStatus: ".$style['label']."\nLast Update: ".($ovInfo['last_update'] ?? 'N/A');
                            ?>
                            <div class="cell-unit" style="flex-direction: <?= ($panel['col_pos'] ?? 'top') === 'top' ? 'column' : 'column-reverse' ?>">
                                <div class="cell-label" style="text-align: <?= $panel['col_align'] ?? 'center' ?>; width: 100%;"><?= htmlspecialchars($c) ?></div>
                                <div class="cell <?= $style['class'] ?>" style="background:<?= $style['color'] ?>" 
                                     title="<?= htmlspecialchars($tooltip) ?>" 
                                     onclick="<?= ($ov && $ov['agent_id']) ? "window.open('/pandora_console/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente={$ov['agent_id']}', '_blank')" : "" ?>">
                                    <?= $style['label'] ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <script>
            (function(){
                window.panelData = window.panelData || {};
                const ok = document.getElementById('stat_ok_<?= $panel['id'] ?>');
                const wr = document.getElementById('stat_warn_<?= $panel['id'] ?>');
                const cr = document.getElementById('stat_crit_<?= $panel['id'] ?>');
                if(ok) ok.innerText = '<?= $pStats['ok'] ?>';
                if(wr) wr.innerText = '<?= $pStats['warn'] ?>';
                if(cr) cr.innerText = '<?= $pStats['crit'] ?>';
                panelData['<?= $panel['id'] ?>'] = <?= json_encode($pDetails) ?>;
            })();
        </script>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; // End show_list ?>

<!-- STATUS DETAIL MODAL -->
<div class="modal-overlay" id="statusDetailModal">
    <div class="modal-box" style="width: 900px; max-width: 95vw;">
        <div class="modal-header-custom">
            <div>
                <h5 style="font-weight: 700; margin:0; color:#0b1a26;" id="sd_title">STATUS DETAILS</h5>
                <p style="margin:2px 0 0; font-size:11px; color:#64748b;" id="sd_subtitle">List of modules for the selected status</p>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="position-relative">
                    <span class="material-symbols-outlined" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); font-size:18px; color:#94a3b8;">search</span>
                    <input type="text" id="sd_search" class="form-control ps-5" placeholder="Search agent or module..." style="height:36px; font-size:13px; width:250px;" onkeyup="filterStatusDetailTable()">
                </div>
                <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeStatusDetailModal()">close</span>
            </div>
        </div>
        <div class="modal-body-scroll p-0">
            <table class="table-detail w-100">
                <thead>
                    <tr>
                        <th>Agent Alias</th>
                        <th>IP Address</th>
                        <th>Module Name</th>
                        <th>Status</th>
                        <th>Last Update</th>
                    </tr>
                </thead>
                <tbody id="sd_body"></tbody>
            </table>
        </div>
        <div class="modal-footer-custom">
            <button type="button" class="btn btn-secondary px-4" onclick="closeStatusDetailModal()">Close</button>
        </div>
    </div>
</div>

<!-- MODAL DASHBOARD META -->
<div class="modal-overlay" id="dashMetaModal">
    <div class="modal-box" style="width:450px;">
        <div class="modal-header-custom">
            <h5 style="font-weight: 700; margin:0; color:#0b1a26;">Dashboard Configuration</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeDashMetaModal()">close</span>
        </div>
        <div class="modal-body-scroll">
            <input type="hidden" id="editDashIdx">
            <div class="form-group">
                <label class="form-label-muted">DASHBOARD TITLE</label>
                <input type="text" id="d_title" class="form-control" placeholder="e.g. Data Center Jakarta">
            </div>
        </div>
        <div class="modal-footer-custom">
            <button type="button" class="btn btn-secondary px-4" onclick="closeDashMetaModal()">Cancel</button>
            <button type="button" class="btn btn-apply px-5" onclick="saveDashboardMeta()">SAVE DASHBOARD</button>
        </div>
    </div>
</div>

<!-- MODAL CONFIG -->
<!-- MODAL CONFIG -->
<div class="modal-overlay" id="panelModal">
    <div class="modal-box">
        <div class="modal-header-custom">
            <h5 style="font-weight: 700; margin:0; color:#0b1a26;">Heatmap Configuration</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closePanelModal()">close</span>
        </div>
        <div class="modal-body-scroll">
            <input type="hidden" id="editIdx">
            <div class="row g-4">
                <div class="col-md-5">
                    <label class="form-label-muted" style="color:#004d40; border-bottom:2px solid #e0f2f1; padding-bottom:5px; margin-bottom:15px;">GENERAL SETTINGS</label>
                    <div class="form-group">
                        <label class="form-label-muted">RACK TITLE</label>
                        <input type="text" id="p_title" class="form-control" placeholder="e.g. Server Room A">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label-muted">Y-AXIS (ROW)</label><input type="text" id="p_row_label" class="form-control" placeholder="RACK"></div>
                        <div class="col-6"><label class="form-label-muted">X-AXIS (COL)</label><input type="text" id="p_col_label" class="form-control" placeholder="SLOT"></div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label-muted">ROW ALIGN (Y)</label>
                            <select id="p_row_align" class="form-control">
                                <option value="left">Left</option>
                                <option value="center">Center</option>
                                <option value="right">Right</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label-muted">COL ALIGN (X)</label>
                            <select id="p_col_align" class="form-control">
                                <option value="left">Left</option>
                                <option value="center">Center</option>
                                <option value="right">Right</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label-muted">COL LABEL POSITION</label>
                        <select id="p_col_pos" class="form-control">
                            <option value="top">Top (Above Cell)</option>
                            <option value="bottom">Bottom (Below Cell)</option>
                        </select>
                    </div>
                    <div class="form-check form-switch mb-1" style="padding-left: 2.5em;">
                        <input class="form-check-input" type="checkbox" id="p_stats" style="width: 40px; height: 20px; cursor: pointer;" onchange="$('#stats_granular').toggle(this.checked)">
                        <label class="form-check-label ms-2" style="font-weight:700; font-size:12px; color:#475569;">Show Health Stats Bar</label>
                    </div>
                    <div id="stats_granular" style="padding-left: 2.5em; display:none; margin-bottom:15px;">
                        <label style="display:flex; align-items:center; font-size:11px; cursor:pointer; color:#64748b; margin-bottom:4px;"><input type="checkbox" id="p_stats_ok" checked style="margin-right:8px;"> Show OK</label>
                        <label style="display:flex; align-items:center; font-size:11px; cursor:pointer; color:#64748b; margin-bottom:4px;"><input type="checkbox" id="p_stats_warn" checked style="margin-right:8px;"> Show WARN</label>
                        <label style="display:flex; align-items:center; font-size:11px; cursor:pointer; color:#64748b; margin-bottom:4px;"><input type="checkbox" id="p_stats_crit" checked style="margin-right:8px;"> Show CRIT</label>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label-muted">MAPPING PATTERN (FOR AGENT ALIAS)</label>
                        <input type="text" id="p_pattern" class="form-control" value="{row}-{col}">
                        <small class="text-muted" style="font-size:10px;">Variables: {row}, {col}</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label-muted" style="color:#0ea5e9;">GLOBAL DEFAULT MODULE</label>
                        <input type="text" id="p_global_module" class="form-control" placeholder="Apply to all cells without override">
                        <small class="text-muted" style="font-size:10px;">If set, any cell that matches an agent alias will use this module.</small>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="d-flex justify-content-between align-items-center" style="border-bottom:2px solid #e0f2f1; margin-bottom:15px; padding-bottom:5px;">
                        <label class="form-label-muted" style="color:#004d40; margin:0;">GRID DIMENSIONS (ROWS & COLS)</label>
                        <button type="button" class="btn btn-sm btn-link text-primary p-0 fw-bold" style="font-size:10px; text-decoration:none;" onclick="quickGenerateGrid()">+ QUICK GENERATE</button>
                    </div>
                    <div class="mb-3">
                        <label class="form-label-muted">ROWS (Press Enter after each)</label>
                        <div id="rowTags" class="tag-container"><input type="text" class="tag-input-box" placeholder="e.g. AC1" onkeydown="handleTagInput(event, 'rowTags')"></div>
                    </div>
                    <div class="text-center my-3">
                        <button type="button" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2 px-3" onclick="transposeGrid()" style="font-size:10px; border-radius:20px;">
                            <span class="material-symbols-outlined" style="font-size:16px;">sync_alt</span> TRANSPOSE (SWITCH ROWS & COLS)
                        </button>
                    </div>
                    <div>
                        <label class="form-label-muted">COLS (Press Enter after each)</label>
                        <div id="colTags" class="tag-container"><input type="text" class="tag-input-box" placeholder="e.g. 01" onkeydown="handleTagInput(event, 'colTags')"></div>
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div class="d-flex justify-content-between align-items-center" style="border-bottom:2px solid #e0f2f1; margin-bottom:15px; padding-bottom:5px;">
                <label class="form-label-muted" style="color:#004d40; margin:0;">CELL MAPPING OVERRIDES</label>
                <button type="button" class="btn btn-sm btn-link text-success p-0 fw-bold" style="font-size:10px; text-decoration:none;" onclick="openAutoMapTool()">⚡ GRID AUTO-MAPPER</button>
            </div>
            <div style="background:#fcfdfe; border:1px solid #e2e8f0; border-radius:8px; padding:15px;">
                <table class="table table-sm table-hover align-middle" style="font-size:12px;">
                    <thead><tr class="table-light"><th>Cell ID</th><th>Agent Assignment</th><th>Module Selector</th><th width="40"></th></tr></thead>
                    <tbody id="overrideList"></tbody>
                </table>
                <button type="button" class="btn btn-sm btn-outline-success fw-bold px-3" onclick="addOverrideRow()" style="font-size:11px;">+ Add New Assignment</button>
            </div>
        </div>
        <div class="modal-footer-custom">
            <button type="button" class="btn btn-secondary px-4" onclick="closePanelModal()">Cancel</button>
            <button type="button" class="btn btn-apply px-5" onclick="savePanel()" style="height:44px; font-size:14px; box-shadow:0 4px 10px rgba(0,77,64,0.2);">SAVE RACK CONFIGURATION</button>
        </div>
    </div>
</div>

<script>
    let masterConfig = <?= json_encode($master_config) ?>;
    let dashId = '<?= $dash_id ?>';
    let config = <?= json_encode($current_config) ?>;
    let panelData = {}; // To store detailed status info for each panel
    if (!config.panels) config.panels = [];
    let refreshSec = config.refresh_sec;
    let timer = refreshSec;
    let timerId = null;
    let allAgents = [];

    $(document).ready(async () => {
        const res = await fetch('?api=get_resources');
        const data = await res.json();
        allAgents = data.agents;
        updateTimerDisplay();
        if (refreshSec > 0) startTimer();
        $(document).on('mousedown', e => { if (!$(e.target).closest('.search-list').length) $('.search-list').hide(); });
    });

    function startTimer() { if (timerId) clearInterval(timerId); timerId = setInterval(() => { if (refreshSec <= 0) return; timer--; updateTimerDisplay(); if (timer <= 0) location.reload(); }, 1000); }
    function updateTimerDisplay() { const ind = $('#refreshIndicator'); if (refreshSec <= 0) ind.html('<span style="color:#94a3b8">OFF</span>'); else ind.html(timer + 's'); }
    async function updateGlobalRefresh(val) { 
        if (val === 'custom') {
            const customVal = prompt('Enter refresh interval in seconds:', config.refresh_sec || 60);
            if (customVal !== null) val = parseInt(customVal) || 0;
            else { return; }
        }
        config.refresh_sec = parseInt(val); refreshSec = config.refresh_sec; timer = refreshSec; updateTimerDisplay(); if (refreshSec > 0) startTimer(); else if(timerId) clearInterval(timerId); await saveConfig(false); 
    }

    function openDashMetaModal(idx = null) {
        if (idx !== null) {
            const d = masterConfig[idx];
            $('#editDashIdx').val(idx);
            $('#d_title').val(d.title);
        } else {
            $('#editDashIdx').val('');
            $('#d_title').val('');
        }
        $('#dashMetaModal').css('display', 'flex');
    }
    function closeDashMetaModal() { $('#dashMetaModal').hide(); }
    async function saveDashboardMeta() {
        const title = $('#d_title').val().trim();
        if (!title) return alert('Title is required');
        const idx = $('#editDashIdx').val();
        if (idx !== '') {
            masterConfig[idx].title = title;
        } else {
            masterConfig.push({ id: 'dash_' + Date.now(), title: title, refresh_sec: 60, layout_cols: 2, panels: [] });
        }
        await saveMaster();
        location.reload();
    }
    async function deleteDashboard(idx) {
        if (confirm('Delete this dashboard and all its racks?')) {
            masterConfig.splice(idx, 1);
            await saveMaster();
            location.reload();
        }
    }
    async function duplicateDashboard(idx) {
        const clone = JSON.parse(JSON.stringify(masterConfig[idx]));
        clone.id = 'dash_' + Date.now();
        clone.title += ' (Copy)';
        masterConfig.push(clone);
        await saveMaster();
        location.reload();
    }
    async function saveMaster() {
        const res = await fetch('?api=save_master', { 
            method: 'POST', 
            headers: { 'X-CSRF-TOKEN': '<?= $csrf_token ?>' }, 
            body: JSON.stringify(masterConfig) 
        });
        return await res.json();
    }

    let currentSdData = [];
    function showStatusDetails(pid, status) {
        const p = panelData[pid]; if(!p) return;
        const filtered = p.filter(d => d.status === status);
        currentSdData = filtered;
        
        const labels = { 0: 'OK', 1: 'CRITICAL', 2: 'WARNING' };
        $('#sd_title').text(`${labels[status]} MODULES (${filtered.length})`);
        $('#sd_search').val('');
        renderStatusDetailTable(filtered);
        $('#statusDetailModal').css('display', 'flex');
    }
    function closeStatusDetailModal() { $('#statusDetailModal').hide(); }
    function renderStatusDetailTable(data) {
        const body = $('#sd_body'); body.empty();
        if (data.length === 0) {
            body.append('<tr><td colspan="5" class="text-center py-4 text-muted">No modules found with this status.</td></tr>');
            return;
        }
        data.forEach(d => {
            const statusClass = d.status === 0 ? 'bg-ok' : (d.status === 1 ? 'bg-crit' : 'bg-warn');
            const statusText = d.status === 0 ? 'OK' : (d.status === 1 ? 'CRITICAL' : 'WARNING');
            body.append(`
                <tr>
                    <td><a href="/pandora_console/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${d.agent_id}" target="_blank" class="fw-bold text-primary text-decoration-none">${d.agent_alias}</a></td>
                    <td><span class="text-muted" style="font-family:monospace;">${d.ip_address || 'N/A'}</span></td>
                    <td>${d.module_name}</td>
                    <td><span class="status-badge ${statusClass}">${statusText}</span></td>
                    <td><small class="text-muted">${d.last_update || 'N/A'}</small></td>
                </tr>
            `);
        });
    }
    function filterStatusDetailTable() {
        const kw = $('#sd_search').val().toLowerCase();
        const filtered = currentSdData.filter(d => 
            d.agent_alias.toLowerCase().includes(kw) || 
            d.module_name.toLowerCase().includes(kw) ||
            (d.ip_address && d.ip_address.toLowerCase().includes(kw))
        );
        renderStatusDetailTable(filtered);
    }
    async function updateLayout(val) { config.layout_cols = parseInt(val); await saveConfig(false); location.reload(); }
    function handleTagInput(e, id) { 
        if(e.key==='Enter'){ 
            e.preventDefault(); 
            const v=e.target.value.trim(); 
            if(!v) return;

            // Range support: 01-20
            const rangeMatch = v.match(/^(\d+)-(\d+)$/);
            if (rangeMatch) {
                const start = parseInt(rangeMatch[1]);
                const end = parseInt(rangeMatch[2]);
                const pad = Math.max(rangeMatch[1].length, rangeMatch[2].length);
                if (start <= end && (end - start) < 500) {
                    for (let i = start; i <= end; i++) addTag(id, String(i).padStart(pad, '0'));
                    e.target.value=''; return;
                }
            }

            // Comma support: A,B,C
            if (v.includes(',')) {
                v.split(',').forEach(x => { const t = x.trim(); if(t) addTag(id, t); });
                e.target.value=''; return;
            }

            addTag(id, v); 
            e.target.value=''; 
        } 
    }
    function quickGenerateGrid() {
        const rCount = prompt("How many ROWS to generate?", "2");
        if (rCount === null) return;
        const cCount = prompt("How many COLUMNS to generate?", "20");
        if (cCount === null) return;
        
        const rPref = prompt("Row Label Prefix? (Optional)", "RACK");
        const cPref = prompt("Column Label Prefix? (Optional)", "");
        
        $('#rowTags .grid-tag, #colTags .grid-tag').remove();
        
        const rc = parseInt(rCount) || 0;
        const cc = parseInt(cCount) || 0;
        
        for (let i = 1; i <= rc; i++) addTag('rowTags', (rPref ? rPref : '') + (rc > 1 ? i : ''));
        for (let i = 1; i <= cc; i++) addTag('colTags', (cPref || '') + String(i).padStart(2, '0'));
    }
    function addTag(id, v) { $(`<div class="grid-tag"><span>${v}</span><span onclick="$(this).parent().remove()" style="cursor:pointer">×</span></div>`).insertBefore($(`#${id} input`)); }
    function getTags(id) { return $(`#${id} .grid-tag span:first-child`).map(function(){ return $(this).text(); }).get(); }
    function editPanel(idx) {
        const p = config.panels[idx]; 
        $('#editIdx').val(idx); 
        $('#p_title').val(p.title); 
        $('#p_stats').prop('checked', p.show_stats);
        $('#stats_granular').toggle(p.show_stats);
        $('#p_stats_ok').prop('checked', p.stats_ok !== false);
        $('#p_stats_warn').prop('checked', p.stats_warn !== false);
        $('#p_stats_crit').prop('checked', p.stats_crit !== false);
        $('#p_pattern').val(p.mapping_pattern);
        $('#p_row_label').val(p.row_label || 'RACK'); $('#p_col_label').val(p.col_label || 'SLOT');
        $('#p_row_align').val(p.row_align || 'right'); $('#p_col_align').val(p.col_align || 'center');
        $('#p_col_pos').val(p.col_pos || 'top'); $('#p_global_module').val(p.global_module || '');
        $('#rowTags .grid-tag, #colTags .grid-tag').remove(); p.rows.forEach(r=>addTag('rowTags',r)); p.cols.forEach(c=>addTag('colTags',c));
        $('#overrideList').empty(); Object.keys(p.overrides).forEach(k => addOverrideRow(k, p.overrides[k]));
        openPanelModal();
    }
    function openPanelModal() { $('#panelModal').css('display', 'flex'); if(timerId) clearInterval(timerId); }
    function closePanelModal() { $('#panelModal').hide(); if(refreshSec > 0) startTimer(); }
    function transposeGrid() {
        const rows = getTags('rowTags'); const cols = getTags('colTags');
        $('#rowTags .grid-tag, #colTags .grid-tag').remove();
        cols.forEach(c => addTag('rowTags', c)); rows.forEach(r => addTag('colTags', r));
    }
    function addOverrideRow(k='', d={}) {
        const rid = 'row_' + Math.random().toString(36).substr(2,5);
        $('#overrideList').append(`<tr id="${rid}">
            <td><input type="text" class="form-control form-control-sm ov-key" value="${k}"></td>
            <td class="position-relative"><input type="text" class="form-control form-control-sm ov-agent" value="${d.agent_alias||''}" data-id="${d.agent_id||''}" onkeyup="filterLocalList(this, '${rid}_alist', allAgents, '${rid}', 'agent')" onfocus="filterLocalList(this, '${rid}_alist', allAgents, '${rid}', 'agent')"><div id="${rid}_alist" class="search-list"></div></td>
            <td class="position-relative"><input type="text" class="form-control form-control-sm ov-mod" value="${d.module_name||''}" onkeyup="filterModules(this, '${rid}_mlist', '${rid}')" onfocus="filterModules(this, '${rid}_mlist', '${rid}')"><div id="${rid}_mlist" class="search-list"></div></td>
            <td><button class="btn btn-sm text-danger border-0 p-0" onclick="$('#${rid}').remove()">×</button></td>
        </tr>`);
    }
    function filterLocalList(inp, listId, data, rowId, type, page=1) {
        const kw = inp.value.toLowerCase(); const list = $(`#${listId}`);
        const allMatches = data.filter(item => item.alias.toLowerCase().includes(kw));
        const pageSize = 12; const totalPages = Math.ceil(allMatches.length / pageSize);
        const start = (page - 1) * pageSize; const matches = allMatches.slice(start, start + pageSize);
        let content = `<div class="search-results-area">`;
        if (matches.length === 0) content += '<div class="p-1 text-muted">No results</div>';
        else content += matches.map(m => `<div class="search-item" onmousedown="selectItem('${rowId}', '${m.alias.replace(/'/g,"\\'")}', '${m.id}', 'agent')">${m.alias}</div>`).join('');
        content += `</div>`;
        if (totalPages > 1) {
            content += `<div class="search-pagination-area" onmousedown="event.stopPropagation()">
                <button class="btn-page-nav" onmousedown="event.preventDefault(); event.stopPropagation(); filterLocalList(document.querySelector('#${rowId} .ov-agent'), '${listId}', allAgents, '${rowId}', 'agent', ${page-1})" ${page<=1?'disabled':''}>Prev</button>
                <span class="pg-indicator">Pg ${page}/${totalPages}</span>
                <button class="btn-page-nav" onmousedown="event.preventDefault(); event.stopPropagation(); filterLocalList(document.querySelector('#${rowId} .ov-agent'), '${listId}', allAgents, '${rowId}', 'agent', ${page+1})" ${page>=totalPages?'disabled':''}>Next</button>
            </div>`;
        }
        list.html(content).show();
    }
    async function filterModules(inp, listId, rowId, page=1) {
        const aid = $(`#${rowId} .ov-agent`).attr('data-id'); if(!aid) return;
        const kw = inp.value.toLowerCase(); const list = $(`#${listId}`);
        if (page === 1 && (!inp.dataset.loadedAid || inp.dataset.loadedAid !== aid)) {
            list.html('<div class="p-1">Loading...</div>').show();
            const res = await fetch('?api=get_agent_modules&agent_id='+aid);
            inp.dataset.modules = JSON.stringify(await res.json());
            inp.dataset.loadedAid = aid;
        }
        const mods = JSON.parse(inp.dataset.modules || '[]');
        const allMatches = mods.filter(m => m.toLowerCase().includes(kw));
        const pageSize = 12; const totalPages = Math.ceil(allMatches.length / pageSize);
        const start = (page - 1) * pageSize; const matches = allMatches.slice(start, start + pageSize);
        let content = `<div class="search-results-area">`;
        if (page === 1 && mods.length > 0) {
            content += `<div class="search-item" onmousedown="selectItem('${rowId}', '[ALL MODULES]', '', 'module')" style="background:#fff7ed; color:#9a3412; font-weight:700; border-bottom:1px solid #ffedd5;">⭐ ALL MODULES (Worst Status)</div>`;
            if (allMatches.length > 1) {
                content += `<div class="search-item bulk-action" onmousedown="bulkAddModules('${rowId}')">+ ADD ALL ${allMatches.length} INDIVIDUAL MODULES</div>`;
            }
        }
        if (matches.length === 0) content += '<div class="p-1 text-muted">No results</div>';
        else content += matches.map(m => `<div class="search-item" onmousedown="selectItem('${rowId}', '${m.replace(/'/g,"\\'")}', '', 'module')">${m}</div>`).join('');
        content += `</div>`;
        if (totalPages > 1) {
            content += `<div class="search-pagination-area" onmousedown="event.stopPropagation()">
                <button class="btn-page-nav" onmousedown="event.preventDefault(); event.stopPropagation(); filterModules(document.querySelector('#${rowId} .ov-mod'), '${listId}', '${rowId}', ${page-1})" ${page<=1?'disabled':''}>Prev</button>
                <span class="pg-indicator">Pg ${page}/${totalPages}</span>
                <button class="btn-page-nav" onmousedown="event.preventDefault(); event.stopPropagation(); filterModules(document.querySelector('#${rowId} .ov-mod'), '${listId}', '${rowId}', ${page+1})" ${page>=totalPages?'disabled':''}>Next</button>
            </div>`;
        }
        list.html(content).show();
    }
    function selectItem(rid, val, id, type) { if(type==='agent') $(`#${rid} .ov-agent`).val(val).attr('data-id', id); else $(`#${rid} .ov-mod`).val(val); $('.search-list').hide(); }
    function bulkAddModules(rowId) {
        const inp = document.querySelector(`#${rowId} .ov-mod`);
        const kw = inp.value.toLowerCase();
        const mods = JSON.parse(inp.dataset.modules || '[]').filter(m => m.toLowerCase().includes(kw));
        const aid = $(`#${rowId} .ov-agent`).attr('data-id');
        const alias = $(`#${rowId} .ov-agent`).val();
        const baseKey = $(`#${rowId} .ov-key`).val();
        if (mods.length === 0) return;
        if (!confirm(`Add all ${mods.length} modules as separate overrides?`)) return;
        mods.forEach((m, i) => {
            if (i === 0) {
                $(`#${rowId} .ov-mod`).val(m);
                if (!baseKey || baseKey === '') $(`#${rowId} .ov-key`).val(m);
            } else {
                addOverrideRow(baseKey ? baseKey + '_' + m : m, { agent_id: aid, agent_alias: alias, module_name: m });
            }
        });
        $('.search-list').hide();
    }
    async function savePanel() {
        const idx = $('#editIdx').val(); const p = config.panels[idx];
        ['rowTags','colTags'].forEach(id => { const inp = $(`#${id} input`); const v = inp.val().trim(); if(v){ addTag(id,v); inp.val(''); } });
        p.title = $('#p_title').val(); 
        p.show_stats = $('#p_stats').is(':checked');
        p.stats_ok = $('#p_stats_ok').is(':checked');
        p.stats_warn = $('#p_stats_warn').is(':checked');
        p.stats_crit = $('#p_stats_crit').is(':checked');
        p.mapping_pattern = $('#p_pattern').val();
        p.row_label = $('#p_row_label').val(); p.col_label = $('#p_col_label').val();
        p.row_align = $('#p_row_align').val(); p.col_align = $('#p_col_align').val();
        p.col_pos = $('#p_col_pos').val(); p.global_module = $('#p_global_module').val();
        p.rows = getTags('rowTags'); p.cols = getTags('colTags'); p.overrides = {};
        $('#overrideList tr').each(function() {
            const k = $(this).find('.ov-key').val(); const aid = $(this).find('.ov-agent').attr('data-id');
            const alias = $(this).find('.ov-agent').val(); const mod = $(this).find('.ov-mod').val();
            if(k && aid) p.overrides[k] = { agent_id: aid, agent_alias: alias, module_name: mod };
        });
        await saveConfig();
    }
    function addNewPanel() { config.panels.push({ id:'p_'+Date.now(), title:'New Rack', show_stats:true, rows:['AC14'], cols:['01','02'], mapping_pattern:'{row}-{col}', row_align:'right', col_align:'center', col_pos:'top', overrides:{} }); saveConfig(); }
    function duplicatePanel(idx) { const clone = JSON.parse(JSON.stringify(config.panels[idx])); clone.id = 'p_' + Date.now(); clone.title += ' (Copy)'; config.panels.push(clone); saveConfig(); }
    function deletePanel(idx) { if(confirm('Delete?')){ config.panels.splice(idx,1); saveConfig(); } }
    async function saveConfig(reload = true) { 
        // Update current config back into master
        if (dashId) {
            for (let i=0; i<masterConfig.length; i++) {
                if (masterConfig[i].id === dashId) {
                    masterConfig[i] = config;
                    break;
                }
            }
        }
        const result = await saveMaster();
        if (result.ok) {
            if (reload) location.reload(); 
        } else {
            alert('Save failed: ' + (result.error || 'Check permissions on rack-heatmaps-master.json'));
        }
    }
    function sharePanel(id) { const url = window.location.origin + window.location.pathname + '?pure=1&dash_id=' + dashId + '&panel_id=' + id; navigator.clipboard.writeText(url).then(() => { $('#shareToast').fadeIn().delay(2000).fadeOut(); }); }

    // --- AUTO MAP TOOL ---
    let autoMapModules = [];
    function openAutoMapTool() { $('#autoMapModal').css('display', 'flex'); $('#am_agent').val('').attr('data-id', ''); $('#am_mod_keyword').val(''); $('#am_preview').empty(); }
    function closeAutoMapTool() { $('#autoMapModal').hide(); }
    async function amFetchModules() {
        const aid = $('#am_agent').attr('data-id'); if(!aid) return alert('Select an agent first');
        const kw = $('#am_mod_keyword').val().toLowerCase();
        $('#am_preview').html('<div class="p-3 text-center">Loading modules...</div>');
        const res = await fetch('?api=get_agent_modules&agent_id='+aid);
        const allMods = await res.json();
        autoMapModules = allMods.filter(m => m.toLowerCase().includes(kw));
        renderAutoMapPreview();
    }
    function renderAutoMapPreview() {
        const rows = getTags('rowTags'); const cols = getTags('colTags');
        const pattern = $('#am_pattern').val() || $('#p_pattern').val();
        let gridCells = [];
        rows.forEach(r => { cols.forEach(c => { gridCells.push(pattern.replace('{row}', r).replace('{col}', c)); }); });
        
        let html = '<table class="table table-sm" style="font-size:11px;"><thead><tr><th>Cell ID (Target)</th><th>Module to Map</th></tr></thead><tbody>';
        const count = Math.min(gridCells.length, autoMapModules.length);
        for(let i=0; i<count; i++) {
            html += `<tr>
                <td><input type="text" class="form-control form-control-sm am-cell-id" value="${gridCells[i]}" style="font-size:10px; height:24px;"></td>
                <td style="vertical-align:middle;"><span class="text-success fw-bold">${autoMapModules[i]}</span></td>
            </tr>`;
        }
        if (autoMapModules.length > gridCells.length) html += `<tr><td colspan="2" class="text-warning text-center">... and ${autoMapModules.length - gridCells.length} more modules (exceeds grid size)</td></tr>`;
        html += '</tbody></table>';
        $('#am_preview').html(html);
        $('#am_apply_btn').prop('disabled', count === 0);
    }
    function applyAutoMap() {
        const aid = $('#am_agent').attr('data-id'); const aAlias = $('#am_agent').val();
        const ids = $('.am-cell-id').map(function(){ return $(this).val(); }).get();
        
        const count = Math.min(ids.length, autoMapModules.length);
        for(let i=0; i<count; i++) {
            addOverrideRow(ids[i], { agent_id: aid, agent_alias: aAlias, module_name: autoMapModules[i] });
        }
        closeAutoMapTool();
    }
</script>

<!-- AUTO MAP MODAL -->
<div class="modal-overlay" id="autoMapModal" style="z-index: 3000;">
    <div class="modal-box" style="width: 500px;">
        <div class="modal-header-custom">
            <h5 style="font-weight: 700; margin:0; color:#0b1a26;">⚡ Grid Auto-Mapper</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeAutoMapTool()">close</span>
        </div>
        <div class="modal-body-scroll">
            <p class="text-muted" style="font-size:11px;">This tool will automatically map multiple modules from one agent to your grid cells in sequence.</p>
            <div class="form-group position-relative">
                <label class="form-label-muted">1. SELECT SOURCE AGENT</label>
                <input type="text" id="am_agent" class="form-control" placeholder="Search Agent..." onkeyup="filterLocalList(this, 'am_alist', allAgents, 'am_agent_row', 'agent')" onfocus="filterLocalList(this, 'am_alist', allAgents, 'am_agent_row', 'agent')">
                <div id="am_alist" class="search-list"></div>
            </div>
            <div class="form-group">
                <label class="form-label-muted">2. FILTER MODULES (Optional)</label>
                <div class="d-flex gap-2">
                    <input type="text" id="am_mod_keyword" class="form-control" placeholder="e.g. ifOperStatus" onkeyup="if(event.key==='Enter') amFetchModules()">
                    <button type="button" class="btn btn-primary btn-sm px-3" onclick="amFetchModules()">Fetch</button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label-muted">3. TARGET ID PATTERN (Optional Override)</label>
                <input type="text" id="am_pattern" class="form-control" placeholder="e.g. {row}-{col} or MYPREFIX-{col}" onkeyup="renderAutoMapPreview()">
                <small class="text-muted" style="font-size:9px;">Defaults to global pattern. Use {row} and {col} variables.</small>
            </div>
            <div id="am_preview" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; min-height:100px; max-height:250px; overflow-y:auto; padding:10px;">
                <div class="text-center py-4 text-muted" style="font-size:11px;">Fetch modules to see preview...</div>
            </div>
        </div>
        <div class="modal-footer-custom">
            <button type="button" class="btn btn-secondary px-4" onclick="closeAutoMapTool()">Cancel</button>
            <button type="button" id="am_apply_btn" class="btn btn-success px-4" onclick="applyAutoMap()" disabled>APPLY MAPPING</button>
        </div>
    </div>
</div>
<script>
    // Overriding selectItem to handle am_agent
    const oldSelectItem = selectItem;
    selectItem = function(rid, val, id, type) {
        if (rid === 'am_agent_row') {
            $('#am_agent').val(val).attr('data-id', id);
            $('.search-list').hide();
            return;
        }
        oldSelectItem(rid, val, id, type);
    };
</script>
</body>
</html>
