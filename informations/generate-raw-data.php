<?php
/* generate-raw-data.php
 *
 * Module Status Summary & Raw Data Export
 * - Version: 4.8 (STABLE: Fixed SQL Column Name m.unit)
 * - Support: Bulk Pairs, Custom Date Range, TXT/CSV Export
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// Bypass resource limits for heavy queries
ini_set('memory_limit', '512M');
set_time_limit(300);

// =====================================================================
// 1. DYNAMIC BREADCRUMB
// =====================================================================
$raw_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); 
$dir_only = dirname($raw_path); 
$clean_path = trim($dir_only, '/'); 
$path_array = explode('/', $clean_path);
$formatted_array = array_map(function($p) { return ucwords(str_replace(['_', '-'], ' ', $p)); }, $path_array);
$dynamic_breadcrumb = implode(' / ', $formatted_array);

// =====================================================================
// 2. CONFIG LOADING
// =====================================================================
$PANDORA_BASE_URL = "/pandora_console";
$config_paths = ['/var/www/html/pandora_console/include/config.php', '../../include/config.php', '../include/config.php'];
$config_loaded = false;
foreach ($config_paths as $path) { 
    if (file_exists($path)) { 
        require_once($path); 
        $config_loaded = true; 
        break; 
    } 
}

$DB_HISTORY = [
    'host'    => '10.252.7.239',
    'name'    => 'pandora',
    'user'    => 'root',
    'pass'    => 'Pandor4!'
];

// =====================================================================
// 3. HELPERS & DB INIT
// =====================================================================
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// DECODER UNTUK MEMBERSIHKAN &#x20; DLL
function pretty_text($s) {
    if ($s === null) return '';
    $decoded = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
    return str_replace(['&#x20;', '&#x28;', '&#x29;'], [' ', '(', ')'], $decoded);
}

$pdo = null; $pdoHist = null; $db_status = false; $errors = [];
if ($config_loaded && isset($config)) {
    try {
        $dsn = "mysql:host=" . $config['dbhost'] . ";dbname=" . $config['dbname'] . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $config['dbuser'], $config['dbpass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $db_status = true;

        try {
            $dsnHist = "mysql:host=" . $DB_HISTORY['host'] . ";dbname=" . $DB_HISTORY['name'] . ";charset=utf8mb4";
            $pdoHist = new PDO($dsnHist, $DB_HISTORY['user'], $DB_HISTORY['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        } catch(Exception $e) {}

    } catch (PDOException $e) { $errors[] = "Failed to connect to Main DB: " . $e->getMessage(); }
} else {
    $errors[] = "Failed to load include/config.php from Pandora FMS.";
}

// =====================================================================
// 4. AJAX API ENDPOINTS FOR DROPDOWNS
// =====================================================================
$api = $_GET['api'] ?? '';

if ($api === 'get_agents' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $st = $pdo->query("SELECT alias FROM tagente WHERE disabled = 0 ORDER BY alias ASC");
    $result = array_map('pretty_text', $st->fetchAll(PDO::FETCH_COLUMN));
    echo json_encode($result); exit;
}
if ($api === 'get_modules' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $agent_filter = $_GET['agent_filter'] ?? '';
    
    if (!empty($agent_filter)) {
        $ag_kw = "%" . str_replace(' ', '%', trim($agent_filter)) . "%";
        $st = $pdo->prepare("SELECT DISTINCT m.nombre FROM tagente_modulo m JOIN tagente a ON m.id_agente = a.id_agente WHERE m.disabled = 0 AND a.disabled = 0 AND (a.alias LIKE ? OR a.nombre LIKE ?) ORDER BY m.nombre ASC");
        $st->execute([$ag_kw, $ag_kw]);
    } else {
        echo json_encode([]); exit;
    }
    
    $result = array_map('pretty_text', $st->fetchAll(PDO::FETCH_COLUMN));
    echo json_encode($result); exit;
}

// =====================================================================
// 5. INPUT PROCESSING
// =====================================================================
$agent       = $_REQUEST['agent'] ?? '';
$module      = $_REQUEST['module'] ?? '';
$tz          = $_REQUEST['tz'] ?? $DEFAULT_TZ;
$from_s      = $_REQUEST['from'] ?? '';
$to_s        = $_REQUEST['to'] ?? '';
$preset      = $_REQUEST['preset'] ?? 'custom'; 
$export      = $_REQUEST['export'] ?? '';
$data_filter = $_REQUEST['data_filter'] ?? '';
$num_op      = $_REQUEST['num_op'] ?? '';
$num_val     = $_REQUEST['num_val'] ?? '';
$pairs_raw   = $_REQUEST['pairs'] ?? '';

$valid_ops = ['','<','<=','=','>=','>'];
if (!in_array($num_op, $valid_ops, true)) $num_op = '';

/* TIME LOGIC */
$from_epoch = null; $to_epoch = null;
if ($preset === 'custom') {
    if ($from_s === '' || $to_s === '') {
        $from_s = date('Y-m-d 00:00:00');
        $to_s   = date('Y-m-d 23:59:59');
    }
    $from_epoch = strtotime($from_s);
    $to_epoch   = strtotime($to_s);
} else {
    $from_dt = new DateTime('now', new DateTimeZone($tz));
    $to_dt = clone $from_dt;
    if ($preset === '5m') $from_dt->modify('-5 minutes');
    elseif ($preset === '1h') $from_dt->modify('-1 hour');
    elseif ($preset === '1d') $from_dt->modify('-1 day');
    $from_epoch = $from_dt->getTimestamp();
    $to_epoch = $to_dt->getTimestamp();
    $from_s = $from_dt->format('Y-m-d H:i:s');
    $to_s = $to_dt->format('Y-m-d H:i:s');
}

// =====================================================================
// 6. OPTIMIZED DATA PROCESSING
// =====================================================================
$rows = [];
$total_fetched = 0;
$UI_LIMIT = 2000;
$EXPORT_LIMIT = 100000; 
$limit_hit = false;

if ($db_status && (!empty($agent) || !empty($pairs_raw)) && empty($errors)) {
    try {
        $moduleMap = [];
        $modIds = [];

        // UPDATE: Changed m.unidad to m.unit
        if (!empty($pairs_raw)) {
            $lines = explode("\n", str_replace("\r", "", $pairs_raw));
            foreach ($lines as $line) {
                $p = explode("|", $line);
                if (count($p) < 2) continue;
                $ag_kw  = "%" . trim($p[0]) . "%";
                $mod_kw = "%" . trim($p[1]) . "%";
                $st = $pdo->prepare("SELECT m.id_agente_modulo, a.alias as agent, m.nombre as module, a.direccion as ip, g.nombre as group_name, m.unit as module_unit FROM tagente_modulo m JOIN tagente a ON a.id_agente = m.id_agente LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo WHERE (a.alias LIKE ? OR a.nombre LIKE ?) AND m.nombre LIKE ? LIMIT 100");
                $st->execute([$ag_kw, $ag_kw, $mod_kw]);
                foreach ($st->fetchAll() as $res) { $modIds[] = (int)$res['id_agente_modulo']; $moduleMap[$res['id_agente_modulo']] = $res; }
            }
        } else {
            $ag_kw  = "%" . trim($agent) . "%";
            $mod_kw = "%" . trim($module) . "%";
            $st = $pdo->prepare("SELECT m.id_agente_modulo, a.alias as agent, m.nombre as module, a.direccion as ip, g.nombre as group_name, m.unit as module_unit FROM tagente_modulo m JOIN tagente a ON a.id_agente = m.id_agente LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo WHERE (a.alias LIKE ? OR a.nombre LIKE ?) AND m.nombre LIKE ? LIMIT 500");
            $st->execute([$ag_kw, $ag_kw, $mod_kw]);
            foreach ($st->fetchAll() as $res) { $modIds[] = (int)$res['id_agente_modulo']; $moduleMap[$res['id_agente_modulo']] = $res; }
        }

        $modIds = array_unique($modIds);
        $global_limit = empty($export) ? $UI_LIMIT : $EXPORT_LIMIT;

        if (!empty($modIds)) {
            $df_norm = strtolower($data_filter);
            
            $queries = [
                'num' => "SELECT FROM_UNIXTIME(utimestamp) as dt, datos, utimestamp FROM tagente_datos WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? ORDER BY utimestamp DESC LIMIT 2000",
                'str' => "SELECT FROM_UNIXTIME(utimestamp) as dt, datos, utimestamp FROM tagente_datos_string WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? ORDER BY utimestamp DESC LIMIT 2000"
            ];

            $stNum = $pdo->prepare($queries['num']); $stStr = $pdo->prepare($queries['str']);
            if ($pdoHist) { $stNumHist = $pdoHist->prepare($queries['num']); $stStrHist = $pdoHist->prepare($queries['str']); }

            foreach ($modIds as $mid) {
                if ($total_fetched >= $global_limit) { $limit_hit = true; break; }
                
                $mInfo = $moduleMap[$mid];
                $data_chunks = [];

                $stNum->execute([$mid, $from_epoch, $to_epoch]); while($d = $stNum->fetch()) $data_chunks[] = $d;
                $stStr->execute([$mid, $from_epoch, $to_epoch]); while($d = $stStr->fetch()) $data_chunks[] = $d;
                
                if ($pdoHist) {
                    $stNumHist->execute([$mid, $from_epoch, $to_epoch]); while($d = $stNumHist->fetch()) $data_chunks[] = $d;
                    $stStrHist->execute([$mid, $from_epoch, $to_epoch]); while($d = $stStrHist->fetch()) $data_chunks[] = $d;
                }

                foreach ($data_chunks as $d) {
                    if ($total_fetched >= $global_limit) { $limit_hit = true; break 2; }
                    
                    $val = (string)$d['datos'];
                    if (!empty($num_op) && is_numeric($val) && is_numeric($num_val)) {
                        $pass = false;
                        if ($num_op == '>') $pass = ($val > $num_val); elseif ($num_op == '<') $pass = ($val < $num_val); elseif ($num_op == '=') $pass = ($val == $num_val); elseif ($num_op == '>=') $pass = ($val >= $num_val); elseif ($num_op == '<=') $pass = ($val <= $num_val);
                        if (!$pass) continue;
                    }
                    if (!empty($df_norm) && stripos($val, $df_norm) === false) continue;

                    $statusStr = $val;
                    if ($val === '1') $statusStr = "UP/ON";
                    elseif ($val === '0') $statusStr = "CRITICAL/OFF";

                    $rows[] = [
                        'ts' => $d['dt'], 
                        'agent' => pretty_text($mInfo['agent']), 
                        'group' => pretty_text($mInfo['group_name'] ?: '-'),
                        'ip' => $mInfo['ip'] ?: '-', 
                        'module' => pretty_text($mInfo['module']), 
                        'unit' => pretty_text($mInfo['module_unit'] ?: ''),
                        'data' => pretty_text($statusStr), 
                        'uts' => $d['utimestamp']
                    ];
                    $total_fetched++;
                }
            }
            usort($rows, function($a, $b) { return $b['uts'] <=> $a['uts']; });
        }
    } catch (Exception $e) { $errors[] = "Query failed: " . $e->getMessage(); }
}

// =====================================================================
// 7. EXPORT LOGIC
// =====================================================================
if (!empty($export) && !empty($rows)) {
    ob_clean();
    $fn = "RawData_Report_".date('Ymd_His');
    if ($export === 'csv') {
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="'.$fn.'.csv"');
        echo "Timestamp,Group,Node Agent,IP Address,Module,Data,Unit\n";
        foreach($rows as $r) echo "\"{$r['ts']}\",\"{$r['group']}\",\"{$r['agent']}\",\"{$r['ip']}\",\"{$r['module']}\",\"{$r['data']}\",\"{$r['unit']}\"\n";
    } else {
        header('Content-Type: text/plain'); header('Content-Disposition: attachment; filename="'.$fn.'.txt"');
        echo "RAW DATA REPORT - GENERATED: ".date('Y-m-d H:i:s')."\n".str_repeat("-", 140)."\n";
        echo sprintf("%-20s | %-15s | %-20s | %-15s | %-30s | %-10s | %-10s\n", "Timestamp", "Group", "Agent", "IP Address", "Module", "Value", "Unit");
        echo str_repeat("-", 140)."\n";
        foreach($rows as $r) echo sprintf("%-20s | %-15s | %-20s | %-15s | %-30s | %-10s | %-10s\n", $r['ts'], substr($r['group'],0,15), substr($r['agent'],0,18), substr($r['ip'],0,14), substr($r['module'],0,28), $r['data'], substr($r['unit'],0,10));
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Raw Data Generator</title>
    <link rel="icon" href="/pandora_console/images/pandora.ico" type="image/x-icon">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" />
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <style>
        * { font-family: 'Lato', sans-serif !important; color: #333; font-size: 14px !important; }
        body { background-color: #f4f6f8; margin: 0; padding: 0; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-weight: normal !important; font-size: 18px !important; line-height: 1 !important; display: inline-block; vertical-align: middle; color: inherit !important; }

        .pandora-header-top { background-color: #ffffff; border-bottom: 1px solid #e0e4e8; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 25px; z-index: 10; }
        .header-logo { height: 24px; width: auto; }
        .header-divider { width: 1px; height: 28px; background-color: #dce1e5; margin: 0 20px; }
        .header-title-box { display: flex; flex-direction: column; line-height: 1.2; margin-right: 40px; }
        .header-title-box .main-title { font-size: 14px !important; font-weight: normal !important; color: #0b1a26 !important; }
        .nav-icon-btn { color: #4a5568 !important; text-decoration: none; display: flex; align-items: center; justify-content: center; height: 36px; width: 36px; border-radius: 50%; transition: 0.2s; }
        
        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; }
        .breadcrumb-box { display: flex; flex-direction: column; }
        .page-breadcrumb { font-size: 11px !important; color: #64748b !important; margin-bottom: 4px; font-weight: normal !important; text-transform: uppercase; letter-spacing: 0.5px; }
        .page-title { font-size: 18px !important; color: #0b1a26 !important; margin: 0; font-weight: 600 !important; line-height: 1.2; }

        .btn-apply { background: #004d40; color: #fff !important; border: none; padding: 8px 25px; border-radius: 4px; font-weight: normal !important; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;}
        .btn-apply:hover { background: #00695c; }
        .btn-secondary-custom { background: #fff; color: #4a5568 !important; border: 1px solid #dce1e5; padding: 8px 20px; border-radius: 4px; font-weight: normal !important; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;}
        .btn-secondary-custom:disabled { opacity: 0.5; cursor: not-allowed; }

        .main-content { padding: 30px; }
        .dashboard-card { background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border: 1px solid #f0f3f5; margin-bottom: 25px; }
        .dashboard-card-body { padding: 25px; }

        .form-label { font-size: 11px !important; font-weight: normal; color: #7f8c8d; text-transform: uppercase; margin-bottom: 5px; display: block; }
        .form-control-fix { width: 100%; height: 38px; padding: 8px 12px; border: 1px solid #dce1e5; border-radius: 4px; font-weight: normal !important; background-color: #fff; outline: none; }
        textarea.form-control-fix { height: auto !important; min-height: 100px; }
        
        table.table-pfms { width: 100%; border-collapse: collapse; margin-top: 0; }
        table.table-pfms thead th { background-color: #ffffff; border-bottom: 2px solid #e0e4e8; text-transform: uppercase; padding: 12px 15px; font-weight: normal; color: #7f8c8d; font-size: 11px !important; text-align: left; }
        table.table-pfms tbody td { font-weight: normal; border-bottom: 1px solid #f0f3f5; padding: 12px 15px; color: #0b1a26; vertical-align: middle; }
        .status-badge { padding: 4px 10px; border-radius: 4px; color: #fff; font-size: 10px !important; text-transform: uppercase; font-weight: normal; }
        .bg-on { background-color: #2ecc71; } .bg-off { background-color: #e74c3c; }
        .ip-text { color: #d63384 !important; font-size: 12px !important; font-weight: normal; }
        .unit-text { font-size: 12px !important; color: #4a5568; font-weight: normal; background: #e0e4e8; padding: 2px 6px; border-radius: 4px; }

        /* CUSTOM AUTOCOMPLETE DROPDOWN */
        .autocomplete-container { position: relative; width: 100%; }
        .custom-dropdown { position: absolute; top: 100%; left: 0; width: 100%; max-height: 250px; overflow-y: auto; background-color: #ffffff; border: 1px solid #dce1e5; border-radius: 0 0 4px 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 9999; display: none; }
        .custom-dropdown-item { padding: 10px 12px; cursor: pointer; font-size: 13px !important; border-bottom: 1px solid #f0f3f5; color: #333; transition: background 0.1s; }
        .custom-dropdown-item:last-child { border-bottom: none; }
        .custom-dropdown-item:hover { background-color: #f8f9fa; color: #1976d2; font-weight: normal; }

        /* LOADING SPINNER & OVERLAY CSS */
        #loader-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(255, 255, 255, 0.85);
            z-index: 10000; display: flex; flex-direction: column; 
            align-items: center; justify-content: center; backdrop-filter: blur(2px);
        }
        .spinner {
            width: 50px; height: 50px; border: 5px solid #e0e4e8;
            border-top: 5px solid #004d40; border-radius: 50%;
            animation: spin 1s linear infinite; margin-bottom: 15px;
        }
        .rotating { animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loader-text { font-size: 16px !important; font-weight: normal; color: #0b1a26; }
        .loader-subtext { font-size: 12px !important; color: #7f8c8d; margin-top: 5px; }
    </style>
</head>
<body>

<div id="loader-overlay">
    <div class="spinner"></div>
    <div class="loader-text">Loading Page...</div>
    <div class="loader-subtext" id="loader-subtext-dynamic">Preparing interface components.</div>
</div>

<div class="pandora-header-top">
    <div class="header-left">
        <img src="/pandora_console/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box"><span class="main-title">Pandora FMS</span><span class="sub-title">PFMS-Toolkit</span></div>
    </div>
    <div class="header-right"><a href="/pandora_console/index.php" class="nav-icon-btn"><span class="material-symbols-outlined">home</span></a></div>
</div>

<div class="pandora-header-bottom">
    <div class="breadcrumb-box"><span class="page-breadcrumb"><?= h($dynamic_breadcrumb) ?></span><h1 class="page-title">Generate Raw Data</h1></div>
</div>

<div class="main-content">
    <div class="dashboard-card">
        <div class="dashboard-card-body">
            <form method="get" id="queryForm">
                <?php if(isset($_GET['page'])): ?>
                    <input type="hidden" name="page" value="<?= h($_GET['page']) ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Agent (Select or Type Pattern)</label>
                        <div class="autocomplete-container">
                            <input type="text" id="agent_input" name="agent" class="form-control-fix" value="<?= h($agent) ?>" autocomplete="off" placeholder="Search agent or type keyword...">
                            <span class="material-symbols-outlined rotating" id="agent_spinner" style="display:none; position:absolute; right:10px; top:10px; color:#004d40; pointer-events:none;">autorenew</span>
                            <div id="agent_dropdown" class="custom-dropdown"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Module Name (Loads based on Agent)</label>
                        <div class="autocomplete-container">
                            <input type="text" id="module_input" name="module" class="form-control-fix" value="<?= h($module) ?>" autocomplete="off" placeholder="Search module or type keyword...">
                            <span class="material-symbols-outlined rotating" id="module_spinner" style="display:none; position:absolute; right:10px; top:10px; color:#004d40; pointer-events:none;">autorenew</span>
                            <div id="module_dropdown" class="custom-dropdown"></div>
                        </div>
                    </div>

                    <div class="col-md-12">
                        <label class="form-label">Bulk Pairs List (Optional) - Format: <span style="color:#d63384">agent_keyword|module_keyword</span></label>
                        <textarea name="pairs" class="form-control-fix" placeholder="CORE|Host Alive&#10;Agent02|CPU"><?= h($pairs_raw) ?></textarea>
                    </div>
                    
                    <div class="col-md-3"><label class="form-label">Start Date</label><input type="datetime-local" name="from" class="form-control-fix" value="<?= h(date('Y-m-d\TH:i', $from_epoch)) ?>"></div>
                    <div class="col-md-3"><label class="form-label">End Date</label><input type="datetime-local" name="to" class="form-control-fix" value="<?= h(date('Y-m-d\TH:i', $to_epoch)) ?>"></div>
                    <div class="col-md-3"><label class="form-label">Timelapse Preset</label>
                        <select name="preset" class="form-control-fix">
                            <option value="custom" <?= $preset=='custom'?'selected':'' ?>>Custom</option><option value="5m" <?= $preset=='5m'?'selected':'' ?>>5 minutes</option><option value="1h" <?= $preset=='1h'?'selected':'' ?>>1 hour</option><option value="1d" <?= $preset=='1d'?'selected':'' ?>>1 day</option>
                        </select>
                    </div>
                    <div class="col-md-3"><label class="form-label">Timezone</label>
                        <select name="tz" class="form-control-fix">
                            <?php foreach (['Asia/Jakarta','Asia/Bangkok','UTC'] as $opt): ?>
                                <option value="<?= h($opt) ?>" <?= $opt==$tz?'selected':'' ?>><?= h($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label">Filter Data (Optional)</label><input type="text" name="data_filter" class="form-control-fix" value="<?= h($data_filter) ?>" placeholder="e.g. Off / DOWN / 144"></div>
                    <div class="col-md-6"><label class="form-label">Numeric Filter (Optional)</label>
                        <div class="d-flex gap-2">
                            <select name="num_op" class="form-control-fix" style="width:100px;"><option value="">(none)</option><option value="<" <?= $num_op=='<'?'selected':'' ?>>&lt;</option><option value="<=" <?= $num_op=='<='?'selected':'' ?>>&le;</option><option value="=" <?= $num_op=='='?'selected':'' ?>>=</option><option value=">=" <?= $num_op=='>='?'selected':'' ?>>&ge;</option><option value=">" <?= $num_op=='>'?'selected':'' ?>>&gt;</option></select>
                            <input type="text" name="num_val" class="form-control-fix" value="<?= h($num_val) ?>" placeholder="e.g. 144">
                        </div>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button class="btn-apply" type="submit" onclick="setSubmitAction('run')"><span class="material-symbols-outlined">play_arrow</span> Run Query</button>
                    <button class="btn-secondary-custom" type="button" onclick="handleExport('txt')" <?= $rows ? '' : 'disabled' ?>><span class="material-symbols-outlined">description</span> Download TXT</button>
                    <button class="btn-secondary-custom" type="button" onclick="handleExport('csv')" <?= $rows ? '' : 'disabled' ?>><span class="material-symbols-outlined">table_view</span> Download CSV</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger" style="font-size:12px; font-weight: normal; padding: 15px; border-radius: 6px; border: 1px solid #f5c6cb;">
        <span class="material-symbols-outlined" style="font-size:16px;">error</span> <?= h(implode(" | ", $errors)) ?>
      </div>
    <?php endif; ?>

    <?php if (!$errors && (!empty($agent) || !empty($pairs_raw)) && $rows): ?>
        <?php if ($limit_hit && empty($export)): ?>
            <div class="alert alert-info mb-3" style="background:#e1f5fe; color:#0c5460; font-size:12px; font-weight: normal; padding:12px; border-radius:6px; border: 1px solid #b8daff;">
                <span class="material-symbols-outlined" style="font-size:16px;">info</span>
                Showing maximum <?= $UI_LIMIT ?> rows of recent data. Please click the Download button to see the full historical data range.
            </div>
        <?php else: ?>
            <div class="alert alert-success mb-3" style="background:#d4edda; color:#155724; font-size:12px; font-weight: normal; padding:12px; border-radius:6px; border: 1px solid #c3e6cb;">
                <span class="material-symbols-outlined" style="font-size:16px;">check_circle</span>
                Success! Loaded <?= number_format(count($rows)) ?> rows of data from your query.
            </div>
        <?php endif; ?>
    
    <div class="dashboard-card">
        <div class="dashboard-card-body p-0" style="overflow-x:auto;">
            <table class="table-pfms">
                <thead><tr>
                    <th style="width:5%">#</th>
                    <th style="width:15%">Group</th>
                    <th style="width:15%">Agent Name/Alias</th>
                    <th style="width:15%">IP Address</th>
                    <th style="width:20%">Module</th>
                    <th style="width:15%">Date (<?= h($tz) ?>)</th>
                    <th style="width:10%">Data</th>
                    <th style="width:5%">Unit</th>
                </tr></thead>
                <tbody>
                    <?php $i=1; foreach($rows as $r): 
                        $cls = (strpos($r['data'], 'On') !== false || strpos($r['data'], 'UP') !== false) ? 'bg-on' : ((strpos($r['data'], 'Off') !== false || strpos($r['data'], 'CRIT') !== false || strpos($r['data'], 'ABENDED') !== false || strpos($r['data'], 'STOPPED') !== false) ? 'bg-off' : '');
                    ?>
                    <tr>
                        <td style="color:#7f8c8d;"><?= $i++ ?></td>
                        <td style="color:#7f8c8d;"><?= h($r['group']) ?></td>
                        <td style="color:#1976d2;"><?= h($r['agent']) ?></td>
                        <td><code class="ip-text"><?= h($r['ip']) ?></code></td>
                        <td style="color:#7f8c8d; font-weight: normal;"><?= h($r['module']) ?></td>
                        <td><?= h($r['ts']) ?></td>
                        <td><?= $cls ? "<span class='status-badge $cls'>{$r['data']}</span>" : h($r['data']) ?></td>
                        <td><?= !empty($r['unit']) ? "<span class='unit-text'>" . h($r['unit']) . "</span>" : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif(!$errors && (!empty($agent) || !empty($pairs_raw)) && !$rows): ?>
        <div class="alert" style="background:#fff3cd; color:#856404; font-size:12px; font-weight: normal; padding:15px; border-radius:6px; border: 1px solid #ffeeba;">
            <span class="material-symbols-outlined" style="font-size:16px;">warning</span>
            No historical data found for the search criteria in this time range.
        </div>
    <?php endif; ?>
</div>

<script>
// ==========================================
// 1. PAGE LOAD & FORM SUBMIT EVENTS
// ==========================================
let submitAction = 'run';
function setSubmitAction(action) { submitAction = action; }

document.getElementById('queryForm').addEventListener('submit', function() {
    if (submitAction === 'run') {
        const overlay = document.getElementById('loader-overlay');
        overlay.style.display = 'flex';
        overlay.querySelector('.loader-text').innerText = 'Processing Data Query...';
        overlay.querySelector('.loader-subtext').innerText = 'Searching historical data from database. Please wait.';
    }
});

// ==========================================
// 2. EXPORT BLOB FETCH LOGIC
// ==========================================
function handleExport(format) {
    const overlay = document.getElementById('loader-overlay');
    const loaderText = overlay.querySelector('.loader-text');
    const loaderSubtext = overlay.querySelector('.loader-subtext');

    overlay.style.display = 'flex';
    loaderText.innerText = 'Preparing Export File...';
    loaderSubtext.innerText = 'Please wait, the system is formatting your data to ' + format.toUpperCase() + '.';

    const form = document.getElementById('queryForm');
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    params.set('export', format);

    fetch('?' + params.toString())
    .then(response => {
        if (!response.ok) throw new Error('Network error');
        const disposition = response.headers.get('Content-Disposition');
        let filename = 'RawData_Report.' + format;
        if (disposition && disposition.indexOf('filename=') !== -1) {
            filename = disposition.split('filename=')[1].replace(/"/g, '');
        }
        return response.blob().then(blob => ({blob, filename}));
    })
    .then(({blob, filename}) => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);

        overlay.style.display = 'none';
    })
    .catch(error => {
        console.error(error);
        alert("An error occurred while downloading the file.");
        overlay.style.display = 'none';
    });
}

// ==========================================
// 3. CASCADING AUTOCOMPLETE & INIT FETCH
// ==========================================
document.addEventListener("DOMContentLoaded", function() {
    let agentsList = [];
    let modulesList = [];

    const urlParams = new URLSearchParams(window.location.search);
    const pageParam = urlParams.get('page');
    const apiPrefix = pageParam ? '?page=' + encodeURIComponent(pageParam) + '&api=' : '?api=';

    const overlay = document.getElementById('loader-overlay');
    const subtext = document.getElementById('loader-subtext-dynamic');
    const agentSpinner = document.getElementById('agent_spinner');
    const moduleSpinner = document.getElementById('module_spinner');
    
    overlay.style.display = 'flex';
    subtext.innerText = 'Fetching device list from database...';
    agentSpinner.style.display = 'block';

    fetch(apiPrefix + 'get_agents')
        .then(r=>r.json())
        .then(data => { 
            agentsList = data; 
            agentSpinner.style.display = 'none';
            overlay.style.display = 'none'; 
        })
        .catch(e => {
            console.error("Failed to load agents:", e);
            agentSpinner.style.display = 'none';
            overlay.style.display = 'none';
        });

    function fetchModulesForAgent(agentKw) {
        if(!agentKw || agentKw.trim() === '') return;
        
        moduleSpinner.style.display = 'block';
        fetch(apiPrefix + 'get_modules&agent_filter=' + encodeURIComponent(agentKw))
            .then(r=>r.json())
            .then(data => { 
                modulesList = data; 
                moduleSpinner.style.display = 'none';
            })
            .catch(() => {
                moduleSpinner.style.display = 'none';
            });
    }

    const agentInput = document.getElementById('agent_input');
    const moduleInput = document.getElementById('module_input');
    const agentDropdown = document.getElementById('agent_dropdown');
    const moduleDropdown = document.getElementById('module_dropdown');

    agentInput.addEventListener('blur', function() {
        setTimeout(() => fetchModulesForAgent(this.value), 200); 
    });

    function setupDropdown(input, dropdown, getListFn, isAgentField) {
        function renderList(val) {
            const dataList = getListFn();
            dropdown.innerHTML = '';
            if (!dataList || dataList.length === 0) return;

            const filtered = dataList.filter(item => item.toLowerCase().includes(val.toLowerCase()));
            const displayList = filtered.slice(0, 100);

            if (displayList.length === 0) {
                dropdown.style.display = 'none';
                return;
            }

            displayList.forEach(item => {
                const div = document.createElement('div');
                div.className = 'custom-dropdown-item';
                div.textContent = item;
                div.addEventListener('mousedown', function(e) {
                    e.preventDefault(); 
                    input.value = item;
                    dropdown.style.display = 'none';
                    if (isAgentField) fetchModulesForAgent(item);
                });
                dropdown.appendChild(div);
            });
            dropdown.style.display = 'block';
        }

        input.addEventListener('focus', function() { renderList(this.value); });
        input.addEventListener('input', function() { renderList(this.value); });
        input.addEventListener('blur', function() { dropdown.style.display = 'none'; });
    }

    setupDropdown(agentInput, agentDropdown, () => agentsList, true);
    setupDropdown(moduleInput, moduleDropdown, () => modulesList, false);
});
</script>
</body>
</html>


