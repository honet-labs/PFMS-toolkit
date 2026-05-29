<?php
/* dynamic-dashboard-template.php
 *
 * Universal Grafana-Style Dynamic Dashboard
 * - Version: 4.9 (STABLE: Masonry Fix & API Optimization)
 * - Features: 12-Column Smart Grid, Drag & Drop Flow, Auto-Clone Panels, Real-Time HUD.
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// 1. DYNAMIC BREADCRUMB
set_time_limit(120); 
$dynamic_breadcrumb = "PANDORA CONSOLE / CUSTOM / PANEL / DASHBOARD";

// 2. CONFIG LOADING
$PANDORA_BASE_URL = "/pandora_console";
$CONFIG_FILE = __DIR__ . '/dynamic-dashboards-master.json';

// Use centralized db-connection.php - Load this BEFORE session_start to respect Pandora session settings
require_once __DIR__ . '/../../includes/db-connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';

// 3. HELPERS (Using global ones from db-connection.php)
// function h() and pretty_text() are handled globally.

// 4. AJAX ENDPOINTS
$api = $_GET['api'] ?? '';

if ($api === 'load_config') {
    ob_clean(); header('Content-Type: application/json');
    if(file_exists($CONFIG_FILE)) { echo file_get_contents($CONFIG_FILE); } 
    else { echo json_encode([]); } 
    exit;
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
    if ($bytes === false) {
        $err = error_get_last();
        $errMsg = $err['message'] ?? 'Permission Denied / Unknown Error';
        echo json_encode(['ok' => false, 'error' => "Gagal menulis ke file ($CONFIG_FILE). Alasan: $errMsg", 'file' => basename($CONFIG_FILE)]);
    } else {
        echo json_encode(['ok' => true, 'file' => basename($CONFIG_FILE)]); 
    }
    exit;
}

if ($api === 'groups') {
    ob_clean(); header('Content-Type: application/json');
    if (!$db_status) { echo json_encode(['error' => 'DB Connection Error: ' . $db_error]); exit; }
    $stmt = $pdo->query("SELECT id_grupo AS id, nombre AS name FROM tgrupo ORDER BY name ASC");
    $dropdown = [['id' => '0', 'name' => '-- Semua Group (All Groups) --']];
    while($g = $stmt->fetch()) { $dropdown[] = ['id' => $g['id'], 'name' => pretty_text($g['name'])]; }
    echo json_encode($dropdown); exit;
}

if ($api === 'module_list') {
    ob_clean(); header('Content-Type: application/json');
    if (!$db_status) { echo json_encode(['error' => 'DB Connection Error: ' . $db_error]); exit; }
    $stmt = $pdo->query("SELECT DISTINCT nombre FROM tagente_modulo WHERE disabled = 0 ORDER BY nombre ASC");
    $list = [];
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $raw = $row['nombre'];
        $list[] = ['raw' => $raw, 'pretty' => pretty_text($raw)];
    }
    echo json_encode($list); exit;
}

if ($api === 'template_nodes') {
    ob_clean(); header('Content-Type: application/json');
    if (!$db_status) { echo json_encode(['error' => 'DB Connection Error: ' . $db_error]); exit; }
    $groupId = (int)($_GET['group_id'] ?? 0);
    try {
        if ($groupId === 0) {
            $stmt = $pdo->query("SELECT id_agente AS id, alias FROM tagente WHERE disabled = 0 ORDER BY alias ASC");
            $list = $stmt->fetchAll();
        } else {
            $stmtAllGroups = $pdo->query("SELECT id_grupo, parent FROM tgrupo"); 
            $allGroups = $stmtAllGroups->fetchAll();
            
            function getChildGroups($parentId, $allGroups) { 
                $children = [$parentId]; 
                foreach ($allGroups as $g) { 
                    if ($g['parent'] == $parentId && $g['id_grupo'] != $parentId) { 
                        $children = array_merge($children, getChildGroups($g['id_grupo'], $allGroups)); 
                    } 
                } 
                return array_unique($children); 
            }
            
            $targetGroups = getChildGroups($groupId, $allGroups);
            $inStr = implode(',', array_fill(0, count($targetGroups), '?'));
            
            $hasSecGroup = false;
            try { $pdo->query("SELECT 1 FROM tagente_secondary_group LIMIT 1"); $hasSecGroup = true; } catch(Exception $e) {}

            if ($hasSecGroup) {
                $sql = "SELECT DISTINCT a.id_agente AS id, a.alias 
                        FROM tagente a 
                        LEFT JOIN tagente_secondary_group sg ON a.id_agente = sg.id_agente
                        WHERE a.disabled = 0 
                        AND (a.id_grupo IN ($inStr) OR sg.id_grupo IN ($inStr)) 
                        ORDER BY a.alias ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge($targetGroups, $targetGroups));
            } else {
                $stmt = $pdo->prepare("SELECT id_agente AS id, alias FROM tagente WHERE disabled = 0 AND id_grupo IN ($inStr) ORDER BY alias ASC");
                $stmt->execute($targetGroups);
            }
            $list = $stmt->fetchAll();
        }
        foreach($list as &$l) { $l['alias'] = pretty_text($l['alias']); }
        echo json_encode($list); exit;
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); exit; }
}

if ($api === 'bulk_panel_data') {
    ob_clean(); header('Content-Type: application/json');
    if (!$db_status) { echo json_encode(['ok' => false, 'error' => 'DB Connection Error: ' . $db_error]); exit; }
    $input = json_decode(file_get_contents('php://input'), true);
    
    $agent_id = (int)($input['agent_id'] ?? 0);
    $start = (int)($input['start'] ?? (time() - 86400));
    $end = (int)($input['end'] ?? time());
    $panels = $input['panels'] ?? [];

    if ($agent_id === 0 || empty($panels)) { echo json_encode(['ok' => false, 'error' => 'Missing param']); exit; }

    try {
        // Helper to normalize strings (remove NBSP, extra spaces, lowercase, trim)
        function normalize_mod_name($s) {
            // Remove any non-printable characters and normalize all whitespace (including NBSP)
            $s = preg_replace('/[[:^print:]]/', '', (string)$s); 
            $s = preg_replace('/\s+/', ' ', $s);
            return strtolower(trim($s));
        }

        // Fetch ALL modules for this agent once to avoid multiple name-matching issues
        $stAll = $pdo->prepare("SELECT m.id_agente_modulo, m.nombre, m.min, m.max, m.unit, e.datos as current_val, COALESCE(e.estado, 4) as estado, e.utimestamp as last_contact,
                                       a.nombre as agent_name, a.direccion as ip_address, g.nombre as group_name, a.id_agente
                                FROM tagente_modulo m 
                                JOIN tagente a ON m.id_agente = a.id_agente
                                JOIN tgrupo g ON a.id_grupo = g.id_grupo
                                LEFT JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo 
                                WHERE m.id_agente = ? AND m.disabled = 0");
        $stAll->execute([$agent_id]);
        $allModules = $stAll->fetchAll();

        $stHist = $pdo->prepare("SELECT FROM_UNIXTIME(ts, '%m-%d %H:%i') as lbl, datos as val FROM (
                                    SELECT utimestamp as ts, datos FROM tagente_datos WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ?
                                    UNION ALL
                                    SELECT utimestamp as ts, datos FROM tagente_datos_string WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ?
                                    UNION ALL
                                    SELECT utimestamp as ts, datos FROM tagente_datos_inc WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ?
                                 ) AS combined ORDER BY ts DESC LIMIT 500");

        $results = [];

        foreach ($panels as $p) {
            $pId = $p['id'];
            $kw = $p['keyword'];
            $mType = $p['match_type'] ?? 'contains';
            $pType = $p['type'] ?? 'text'; 
            
            $keywords = array_map('trim', explode(',', $kw));
            $modulesFound = [];

            foreach ($keywords as $sub_kw) {
                if (empty(trim($sub_kw))) continue;
                $sub_kw_norm = normalize_mod_name($sub_kw);
                
                foreach ($allModules as $mod) {
                    $mod_norm = normalize_mod_name($mod['nombre']);
                    if ($mType === 'exact') {
                        if ($mod_norm === $sub_kw_norm) {
                            $modulesFound[] = $mod;
                        }
                    } else {
                        // Contains/Regex logic
                        $fuzzy_regex = '/' . str_replace(' ', '.*', preg_quote($sub_kw, '/')) . '/i';
                        if (preg_match($fuzzy_regex, $mod['nombre']) || $mod_norm === $sub_kw_norm) {
                            $modulesFound[] = $mod;
                        }
                    }
                }
            }
            // Ensure unique modules if keywords overlap
            $temp = [];
            foreach($modulesFound as $m) { $temp[$m['id_agente_modulo']] = $m; }
            $modulesFound = array_values($temp);

            if (count($modulesFound) === 0) {
                $results[$pId] = ['found' => false, 'modules' => []];
                continue;
            }

            $moduleResults = [];
            foreach($modulesFound as $mod) {
                $mod_id = $mod['id_agente_modulo'];
                $history = null;
                if (in_array($pType, ['line', 'area', 'bar', 'heatmap'])) {
                    $stHist->execute([$mod_id, $start, $end, $mod_id, $start, $end, $mod_id, $start, $end]);
                    $history = array_reverse($stHist->fetchAll(PDO::FETCH_ASSOC));
                }

                $moduleResults[] = [
                    'id' => $mod_id,
                    'module_name' => pretty_text($mod['nombre']),
                    'current' => $mod['current_val'] !== null ? $mod['current_val'] : 'N/A',
                    'status' => (int)$mod['estado'],
                    'last_contact' => (int)$mod['last_contact'],
                    'unit' => pretty_text($mod['unit']),
                    'min' => $mod['min'],
                    'max' => $mod['max'],
                    'agent_name' => $mod['agent_name'],
                    'agent_id' => $mod['id_agente'],
                    'ip_address' => $mod['ip_address'],
                    'group_name' => $mod['group_name'],
                    'history' => $history
                ];
            }
            $results[$pId] = [ 'found' => true, 'modules' => $moduleResults ];
        }
        echo json_encode(['ok' => true, 'data' => $results]);
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
    <title>Universal Dynamic Dashboard</title>
    <link rel="icon" href="<?= h($PANDORA_BASE_URL) ?>/images/pandora.ico" type="image/x-icon">
    <link href="/pandora_console/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="/pandora_console/custom/panel/vendor/fonts/fonts.css" />
    <link href="/pandora_console/custom/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <script src="/pandora_console/custom/panel/vendor/chartjs/chart.js"></script>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #334155; font-size: 14px; -webkit-font-smoothing: antialiased; } * { box-sizing: border-box; }
        body { background-color: #f4f6f8; margin: 0; padding: 0; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-size: 18px !important; vertical-align: middle; line-height: 1; }

        /* V4.8 STANDALONE UI OVERRIDES */
        <?php if ($isStandalone): ?>
        .pandora-header-top, .pandora-header-bottom, .toolbar-right, .drag-handle, #view_list, #listTopControls { display: none !important; }
        .grafana-toolbar { border-top: 1px solid #dce1e5; margin-top:0 !important;}
        .main-content { padding: 20px 25px !important; width: 100% !important; margin: 0 !important; }
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

        .grafana-toolbar { display: flex; align-items: center; justify-content: space-between; background: #ffffff; padding: 12px 30px; border-bottom: 1px solid #dce1e5; box-shadow: 0 2px 4px rgba(0,0,0,0.02); flex-wrap: wrap; gap: 15px;}
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
        
        .main-content { padding: 20px 25px; max-width: 100%; overflow-x: hidden; }
        
        #panelsGrid { 
            display: grid; 
            grid-template-columns: repeat(12, 1fr); 
            grid-auto-rows: auto;
            gap: 15px; 
            align-items: start; 
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
        
        /* Responsive Grid Adjustments */
        @media (max-width: 1400px) {
            #panelsGrid { grid-template-columns: repeat(12, 1fr); gap: 12px; }
        }
        
        @media (max-width: 1200px) {
            #panelsGrid { grid-template-columns: repeat(8, 1fr); gap: 10px; }
            .panel-rule-wrapper { 
                /* Scale spans for 8-column grid */
                grid-column: span var(--span-tablet, 4); 
            }
        }
        
        @media (max-width: 768px) {
            #panelsGrid { grid-template-columns: repeat(4, 1fr); gap: 8px; }
            .panel-rule-wrapper { 
                grid-column: span var(--span-mobile, 4); 
            }
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
        .chart-wrapper { width: 100%; height: 180px; position: relative; margin-top: 10px; display: flex; justify-content: center; align-items: center; min-width: 0;}
        .gauge-text { position: absolute; top: 75%; left: 50%; transform: translate(-50%, -50%); display: flex; flex-direction: column; align-items: center; width: 90%; }
        .gauge-val { font-size: 24px !important; font-weight: 700 !important; color: #0b1a26; line-height: 1; }
        .gauge-minmax { font-size: 10px !important; color: #95a5a6; margin-top: 4px; font-weight: normal;}
        .heatmap-wrap { width: 100%; display: flex; flex-wrap: wrap; gap: 3px; align-content: flex-start; height: auto; min-height: 60px; margin-top: 10px; background: #f8f9fa; border: 1px solid #e0e4e8; border-radius: 4px; padding: 6px; overflow-y: auto; }
        .heat-block { width: 16px; height: 16px; border-radius: 3px; transition: 0.2s; border: 1px solid rgba(0,0,0,0.08); }
        .heat-block:hover { transform: scale(1.4); filter: brightness(1.1); z-index: 10; box-shadow: 0 3px 8px rgba(0,0,0,0.25); }

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

        /* NEW VIEW TYPES CSS */
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

        .table-wrap-dyn { width: 100%; overflow-x: auto; margin-top: 5px; border: 1px solid #f0f3f5; border-radius: 4px; }
        .table-dyn { width: 100%; border-collapse: collapse; font-size: 12px; }
        .table-dyn th { background: #fafbfc; border-bottom: 1px solid #e0e4e8; padding: 8px 12px; text-align: left; color: #7f8c8d; font-weight: normal; text-transform: uppercase; font-size: 9px; }
        .table-dyn td { border-bottom: 1px solid #f0f3f5; padding: 8px 12px; color: #0b1a26; word-break: break-word; min-width: 80px; max-width: 250px; }
        
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

        /* MULTI-SELECT TAGS CSS */
        .selected-tags-container { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 8px; min-height: 0; }
        .module-tag { background: #e0f2f1; color: #004d40; border: 1px solid #b2dfdb; padding: 2px 8px; border-radius: 4px; font-size: 11px; display: flex; align-items: center; gap: 5px; font-weight: 500; }
        .module-tag .remove-tag { cursor: pointer; color: #00796b; font-weight: bold; font-size: 14px; line-height: 1; }
        .module-tag .remove-tag:hover { color: #e74c3c; }
        /* ACTION BUTTONS UI */
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            padding: 0;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            margin-left: 5px;
            box-sizing: border-box;
        }
        .btn-action:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #0f172a;
        }
        .btn-action .material-symbols-outlined { font-size: 16px !important; }
        .btn-action.btn-delete { color: #ef4444; border-color: #fee2e2; }
        .btn-action.btn-delete:hover { background: #fef2f2; border-color: #fca5a5; color: #dc2626; }

    </style>
</head>
<body>

<div class="pandora-header-top">
    <div class="header-left">
        <img src="<?= h($PANDORA_BASE_URL) ?>/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box"><span class="main-title">Pandora FMS</span><span class="sub-title">Custom Dashboard Portal</span></div>
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
                    <input type="text" id="agent_search_input" class="toolbar-select" placeholder="-- Pilih Node --" onkeyup="renderAgentList()" onfocus="this.select(); showAgentDropdown()" autocomplete="off">
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
            
            <div id="customTimeBox" class="toolbar-item" style="display:none; background:#f0f3f5; padding:3px 8px; border-radius:4px;">
                <input type="datetime-local" id="top_start" class="toolbar-input">
                <span style="font-weight: normal; color:#7f8c8d;">-</span>
                <input type="datetime-local" id="top_end" class="toolbar-input">
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
        </div>

        <div class="toolbar-right">
            <button id="btnToggleHidden" class="btn-toggle-hidden" onclick="toggleHiddenVisibility()" title="Show/Hide panels marked as Hidden">
                <span class="material-symbols-outlined" style="font-size:18px!important;">visibility_off</span>
                <span>Hidden</span>
            </button>
            <button class="btn-secondary-custom" onclick="copyDashboardShareLink()"><span class="material-symbols-outlined" style="font-size:18px!important;">share</span> Share</button>
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
                <small style="color:#7f8c8d; font-size:11px;">Grup yang akan otomatis terpilih saat dashboard ini dibuka.</small>
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
                        <input type="checkbox" id="p_multi_overlay" style="margin-right:8px; width:16px; height:16px;"> Overlay Multiple Modules in One Chart
                    </label>
                </div>
                <small style="color:#7f8c8d; font-size:10px; display:block; margin-top:4px;">* Tip: Use commas for different modules (e.g. <b>CPU Load, Memory Usage</b>)</small>
            </div>
            
            <div style="display:flex; gap:10px;">
                <div class="form-group" style="flex:1;">
                    <label>Visual Type</label>
                    <select id="p_type" class="form-control-fix" onchange="toggleTypeFields(); toggleChartEngine();">
                        <option value="text">Value Number / Text</option>
                        <option value="gauge">Gauge Chart</option>
                        <option value="line" selected>Line Chart</option>
                        <option value="area">Area Chart</option>
                        <option value="bar">Bar Chart</option>
                        <option value="heatmap">History Heatmap Blocks</option>
                        <option value="status_table">Table View (Current Status)</option>
                        <option value="status_heatmap">Heatmap View (Current Status)</option>
                        <option value="status_stats">Stats Cards (Current Status)</option>
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

            <div style="display:flex; gap:10px;">
                <div class="form-group" style="flex:1;">
                    <label>Panel Height (px)</label>
                    <input type="number" id="p_height" class="form-control-fix" placeholder="e.g. 200" value="200" min="100" max="1000">
                </div>
                <div class="form-group" style="flex:1;" id="wrap_box_size">
                    <label>Heatmap Box Size</label>
                    <select id="p_box_size" class="form-control-fix">
                        <option value="small">Small</option>
                        <option value="medium" selected>Medium</option>
                        <option value="large">Large</option>
                        <option value="xl">Extra Large</option>
                    </select>
                </div>
            </div>

            <div style="display:flex; gap:10px; margin-top:5px; padding:10px; background:#fff; border:1px solid #dce1e5; border-radius:6px; margin-bottom:10px;">
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label style="color:#004d40; font-size:10px; font-weight:600!important;">Value Font Size (px)</label>
                    <input type="number" id="p_font_size" class="form-control-fix" placeholder="32" value="32" min="8" max="120" style="margin-bottom:0;">
                </div>
                <div class="form-group" style="flex:1; margin-bottom:0;">
                    <label style="color:#004d40; font-size:10px; font-weight:600!important;">Value Font Weight</label>
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
                <label style="display:flex; align-items:center; cursor:pointer; font-size:11px; font-weight:600; color:#e67e22; margin-bottom:0;">
                    <input type="checkbox" id="p_force_100" style="margin-right:8px; width:16px; height:16px;"> Force 0-100% Y-Axis
                </label>
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

            <div style="margin-top:15px; padding:10px; border-top:1px solid #eee;">
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; margin:0;">
                    <input type="checkbox" id="p_hidden" style="width:18px; height:18px; cursor:pointer;">
                    <span style="font-size:13px; font-weight:500; color:#4a5568;">Hide Panel (Benar-benar sembunyikan dari Dashboard & Share URL)</span>
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

</div>

<div class="modal-overlay" id="statusDetailModal">
    <div class="modal-box" style="width:90%; max-width:1100px; max-height:90vh; display:flex; flex-direction:column; padding:0; overflow:hidden;">
        <div style="padding:20px 25px; border-bottom:1px solid #f0f3f5; display:flex; justify-content:space-between; align-items:center; background:#fafbfc;">
            <div>
                <h4 id="statusDetailTitle" style="margin:0; font-size:16px; color:#0b1a26; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Status Details</h4>
                <p style="margin:5px 0 0; font-size:11px; color:#64748b;">* Menampilkan daftar modul berdasarkan kelompok status yang Anda klik.</p>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <div style="position:relative;">
                    <span class="material-symbols-outlined" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:16px!important;">search</span>
                    <input type="text" id="statusDetailSearch" class="form-control-fix" style="margin-bottom:0; padding-left:32px; width:250px; height:34px; font-size:12px;" placeholder="Cari agent atau module..." onkeyup="filterStatusDetailTable()">
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
                        <th style="padding:12px 15px;">SENSOR MODULE</th>
                        <th style="padding:12px 15px;">VALUE</th>
                        <th style="padding:12px 15px;">STATUS</th>
                    </tr>
                </thead>
                <tbody id="statusDetailBody">
                    <!-- Data will be injected here -->
                </tbody>
            </table>
        </div>
        <div style="padding:15px 25px; border-top:1px solid #f0f3f5; text-align:right; background:#fafbfc;">
            <button class="btn-cancel" onclick="closeStatusDetailModal()">Close</button>
        </div>
    </div>
</div>

    <script src="/pandora_console/custom/panel/vendor/sortablejs/Sortable.min.js"></script>
<script>
const PANDORA_URL = "<?= h($PANDORA_BASE_URL) ?>";
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
let showHiddenPanels = false;
isFetchingModules = true;
let exactModulePage = 1;
const exactModuleLimit = 50;

let globalGroupsMap = {};

const baseBreadcrumb = '<?= h($dynamic_breadcrumb) ?>';
let hasUnsavedChanges = false;

// V4.8 IS_STANDALONE FLAG
const IS_STANDALONE = <?= $isStandalone ? 'true' : 'false' ?>;

// V4.8: SHARE LINK FUNCTIONS
function copyDashboardShareLink(dashId = null) {
    const idToShare = dashId || currentDashId;
    if (!idToShare) return;
    const card = masterDashboards.find(x => x.id === idToShare);
    const u = new URL(window.location.origin + window.location.pathname);
    u.searchParams.set('s', '1');
    u.searchParams.set('d', idToShare);
    
    const curGroup = document.getElementById('top_group') ? document.getElementById('top_group').value : null;
    const curAgent = document.getElementById('top_agent') ? document.getElementById('top_agent').value : null;
    
    if (curGroup && curGroup != 0) u.searchParams.set('g', curGroup);
    else if (card.default_group) u.searchParams.set('g', card.default_group);
    
    if (curAgent && curAgent != 0) u.searchParams.set('a', curAgent);
    else if (card.default_agent) u.searchParams.set('a', card.default_agent);
    
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(u.toString()).then(() => {
            alert('Link Standalone untuk Dashboard "' + card.title + '" berhasil disalin!');
        });
    } else {
        const textArea = document.createElement("textarea"); textArea.value = u.toString(); document.body.appendChild(textArea); textArea.select();
        try { document.execCommand('copy'); alert('Link Standalone untuk Dashboard "' + card.title + '" berhasil disalin!'); } catch (err) {}
        document.body.removeChild(textArea);
    }
}

function copyPanelShareLink(panelId) {
    if (!currentDashId) return;
    const u = new URL(window.location.origin + window.location.pathname);
    u.searchParams.set('s', '1');
    u.searchParams.set('d', currentDashId);
    u.searchParams.set('g', document.getElementById('top_group').value);
    u.searchParams.set('a', document.getElementById('top_agent').value);
    u.searchParams.set('p', panelId);

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(u.toString()).then(() => {
            alert('Link Standalone untuk Widget ini berhasil disalin ke clipboard!');
        });
    } else {
        const textArea = document.createElement("textarea"); textArea.value = u.toString(); document.body.appendChild(textArea); textArea.select();
        try { document.execCommand('copy'); alert('Link Standalone untuk Widget ini berhasil disalin ke clipboard!'); } catch (err) {}
        document.body.removeChild(textArea);
    }
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
    fetch('?api=save_config', { method:'POST', body:JSON.stringify(masterDashboards), headers: {'X-CSRF-TOKEN': '<?= $csrf_token ?>'} })
    .then(r => r.json())
    .then(res => {
        if(!res.ok) alert(`SAVE FAILED!\nReason: ${res.error?.message || res.error || 'Unknown Error'}\nTarget: ${res.file || 'File permission issue'}`);
        else if(callback) callback();
    })
    .catch(e => { if(!quiet) alert("Failed to connect to server."); });
}

function updateURLState(dashId = null, groupId = null, agentId = null) {
    const u = new URL(window.location.href);
    // Cleanup old long params if present
    u.searchParams.delete('standalone');
    u.searchParams.delete('dash_id');
    u.searchParams.delete('group_id');
    u.searchParams.delete('agent_id');
    u.searchParams.delete('panel_id');
    u.searchParams.delete('v'); // Clean version param

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
        const res = await fetch('?api=load_config');
        const data = await res.json();
        if(Array.isArray(data)) masterDashboards = data;
    } catch(e) {}

    fetch('?api=groups').then(r=>r.json()).then(data => { 
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
        const urlDashId = u.get('d') || u.get('dash_id');
        const urlGroupId = u.get('g') || u.get('group_id');
        const urlAgentId = u.get('a') || u.get('agent_id');

        if (urlDashId && masterDashboards.some(d => d.id === urlDashId)) {
            openDashboard(urlDashId, urlGroupId, urlAgentId);
        } else {
            renderDashboardList();
        }
    }).catch(err => console.error("Groups Fetch Error:", err));

    fetch('?api=module_list').then(r=>r.json()).then(data => {
        globalModuleList = data;
        isFetchingModules = false;
    });
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
                <button class="btn-action" onclick="editDashboardSettingsFromList('${d.id}')" title="Configure">
                    <span class="material-symbols-outlined">settings</span>
                </button>
                <button class="btn-action" onclick="duplicateDashboardFromList('${d.id}')" title="Duplicate">
                    <span class="material-symbols-outlined">content_copy</span>
                </button>
                <button class="btn-action btn-delete" onclick="deleteDashboard('${d.id}')" title="Delete">
                    <span class="material-symbols-outlined">delete</span>
                </button>
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
        document.getElementById('m_default_group').value = d.default_group || 0;
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
    const grp = parseInt(document.getElementById('m_default_group').value);
    
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
    const currentGroup = parseInt(document.getElementById('top_group').value);
    const currentAgent = document.getElementById('top_agent').value;
    const currentAgentName = document.getElementById('agent_search_input').value; 
    masterDashboards = masterDashboards.map(d => {
        if(d.id === currentDashId) { d.default_group = currentGroup; d.default_agent = currentAgent; d.default_agent_name = currentAgentName; }
        return d;
    });
    saveConfigToServer(() => { markSaved(); alert("Dashboard Saved!"); });
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
                        <h2 style="color:#2c3e50; margin-bottom:10px;">Panel ini sedang disembunyikan</h2>
                        <p style="color:#7f8c8d;">Widget ini telah dinonaktifkan oleh administrator dan tidak dapat diakses secara publik.</p>
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
    
    const targetGroup = initGroupId !== null ? initGroupId : (d.default_group || 0);
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
    
    // Update global last update timestamp
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
    fetch(`?api=template_nodes&group_id=${groupId}`).then(r=>r.json()).then(nodes => {
        if (nodes.error) {
            console.error("Nodes Error:", nodes.error);
            currentAgentList = [];
            searchInput.placeholder = "Error loading nodes";
            return;
        }
        currentAgentList = nodes || [];
        if (autoSelectAgentId && currentAgentList.some(n => n.id == autoSelectAgentId)) {
            const selNode = currentAgentList.find(n => n.id == autoSelectAgentId);
            selectAgent(selNode.id, selNode.alias, true);
        } else {
            searchInput.value = '';
            document.getElementById('top_agent').value = 0;
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
    document.getElementById('customTimeBox').style.display = (val === 'custom') ? 'flex' : 'none';
    if(val !== 'custom') forceRefresh();
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
        
        // Calculate responsive spans
        const wValue = parseInt(p.width) || 12;
        wrapper.style.setProperty('grid-column', `span ${wValue}`, 'important');
        
        const minH = p.height || 200;
        const hiddenClass = p.hidden ? 'is-hidden' : '';
        wrapper.innerHTML = `<div class="panel-card ${hiddenClass}" style="min-height:${minH}px;"><div class="loading-overlay" id="load_${p.id}"><div class="spinner"></div></div></div>`;
        grid.appendChild(wrapper);
    });

    // Initialize SortableJS for modern Drag & Drop Builder
    if (!IS_STANDALONE && typeof Sortable !== 'undefined') {
        new Sortable(grid, {
            animation: 150,
            ghostClass: 'dragging-ghost',
            handle: '.panel-header', // Use header as drag handle
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
                // Just force refresh data
                forceRefresh();
            }
        });
    }
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
        // If it's a whole number, return as integer. Otherwise, max 2 decimals.
        return (f % 1 === 0) ? f.toString() : Number(f.toFixed(2)).toString();
    }
    return val;
}

function generatePanelHtml(p, uniqueId, moduleData, isFirstInGroup, totalModulesInGroup) {
    const cMap = {0:'bg-green', 1:'bg-red', 2:'bg-yellow', 4:'bg-blue'};
    const bgClass = cMap[moduleData.status] || 'bg-gray';
    let valText = formatSmartValue(moduleData.current, p.use_raw);
    
    // Custom Label Logic for 1/0
    if (p.lbl_1 && (moduleData.current == 1 || moduleData.current === '1')) valText = p.lbl_1;
    else if (p.lbl_0 && (moduleData.current == 0 || moduleData.current === '0')) valText = p.lbl_0;

    let contentHtml = '';
    const fs = p.font_size || 32;
    const fw = p.font_weight || 700;
    const showMod = p.show_module !== false; // Default true
    const isMultiOverlay = moduleData.module_name === 'Multi-Module Overlay';
    const modNameHtml = showMod ? `<div class="mod-subtitle" style="${isMultiOverlay ? 'margin:0 auto;' : ''}">${moduleData.module_name}</div>` : '';
    const statusHtml = isMultiOverlay ? '' : `<div style="display:flex; align-items:center;"><span class="status-dot ${bgClass}"></span><span style="font-size:${Math.round(fs*0.5)}px; font-weight:${fw};">${valText}</span><span style="font-size:10px; margin-left:3px;">${moduleData.unit}</span></div>`;

    if (p.type === 'text') {
        contentHtml = `<div style="display:flex; align-items:center; justify-content:center; flex-direction:column; height:100%; padding:10px;"><div style="display:flex; align-items:baseline; justify-content:center;"><span class="status-dot ${bgClass}"></span><span class="val-big" style="font-size:${fs}px; font-weight:${fw};">${valText}</span><span class="val-unit">${moduleData.unit}</span></div>${modNameHtml}</div>`;
    } 
    else if (p.type === 'gauge') {
        contentHtml = `<div class="chart-wrapper"><canvas id="chart_${uniqueId}"></canvas><div class="gauge-text"><div><span class="gauge-val" style="font-size:${Math.round(fs*0.75)}px; font-weight:${fw};">${valText}</span><span class="val-unit">${moduleData.unit}</span></div>${modNameHtml}</div></div>`;
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
                let color = '#e2e8f0'; // Default light gray
                if (val === 0) color = '#2ecc71'; // OK
                else if (val === 1) color = '#e74c3c'; // CRITICAL
                else if (val === 2) color = '#f1c40f'; // WARNING
                else if (val > 0) color = '#2ecc71'; // Fallback
                
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
                    <!-- Custom Loader -->
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
                chartHtml = `<div class="chart-wrapper"><canvas id="chart_${uniqueId}"></canvas></div>`;
            }

            contentHtml = `<div style="display:flex; justify-content:space-between; align-items:center; width:100%;">${modNameHtml}${statusHtml}</div>${chartHtml}`;
        }
    }

    const controlsHtml = getPanelControlsHtml(p, moduleData.id);
    const isExcluded = p.excluded && p.excluded.map(String).includes(String(moduleData.id));
    const hiddenClass = (p.hidden || isExcluded) ? 'is-hidden' : '';

    return `<div class="panel-card ${hiddenClass}" style="height: 100%; margin:0;"><div class="panel-header"><div><h6 class="panel-title">${p.title}</h6></div>${controlsHtml}</div><div class="panel-body">${contentHtml}</div></div>`;
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
        content = `
            <div class="mini-stats-row">
                <div class="mini-stat st-black" onclick="showStatusDetails('${p.id}', -1, 'TOTAL')">
                    <div class="mini-stat-val" style="color:#333;">${stats.total}</div>
                    <div class="mini-stat-label">TOTAL</div>
                </div>
                <div class="mini-stat st-green" onclick="showStatusDetails('${p.id}', 0, 'UP')">
                    <div class="mini-stat-val" style="color:#2ecc71;">${stats.up}</div>
                    <div class="mini-stat-label">UP / OK</div>
                </div>
                <div class="mini-stat st-red" onclick="showStatusDetails('${p.id}', 1, 'CRITICAL')">
                    <div class="mini-stat-val" style="color:#e74c3c;">${stats.crit}</div>
                    <div class="mini-stat-label">CRITICAL</div>
                </div>
                <div class="mini-stat st-yellow" onclick="showStatusDetails('${p.id}', 2, 'WARNING')">
                    <div class="mini-stat-val" style="color:#f1c40f;">${stats.warn}</div>
                    <div class="mini-stat-label">WARNING</div>
                </div>
                <div class="mini-stat st-gray" onclick="showStatusDetails('${p.id}', 3, 'UNKNOWN')">
                    <div class="mini-stat-val" style="color:#94a3b8;">${stats.unknown}</div>
                    <div class="mini-stat-label">UNKNOWN</div>
                </div>
                <div class="mini-stat st-blue" onclick="showStatusDetails('${p.id}', 4, 'NOT INIT')">
                    <div class="mini-stat-val" style="color:#3498db;">${stats.not_init}</div>
                    <div class="mini-stat-label">NOT INIT</div>
                </div>
            </div>`;
    } else if (p.type === 'status_table') {
        content = `
            <div class="table-wrap-dyn">
                <table class="table-dyn">
                    <thead><tr><th>Module Name</th><th>Current Value</th><th>Status</th></tr></thead>
                    <tbody>
                        ${modules.map(m => {
                            const bgClass = {0:'bg-green', 1:'bg-red', 2:'bg-yellow', 4:'bg-blue'}[m.status] || 'bg-gray';
                            const statusLbl = {0:'UP', 1:'CRITICAL', 2:'WARNING', 4:'NOT INIT'}[m.status] || 'UNKNOWN';
                            const cleanVal = formatSmartValue(m.current, p.use_raw);
                            return `<tr><td>${m.module_name}</td><td>${cleanVal} ${m.unit}</td><td><span class="status-pill-dyn ${bgClass}">${statusLbl}</span></td></tr>`;
                        }).join('')}
                    </tbody>
                </table>
            </div>`;
    } else if (p.type === 'status_heatmap') {
        const sizeConf = {
            'small':  { w:'32px', h:'20px', f:'7px' },
            'medium': { w:'48px', h:'28px', f:'9px' },
            'large':  { w:'80px', h:'48px', f:'12px' },
            'xl':     { w:'120px', h:'72px', f:'16px' }
        };
        const s = sizeConf[p.box_size] || sizeConf['medium'];
        content = `
            <div class="heatmap-grid-dyn">
                ${modules.map(m => {
                    const bgClass = {0:'bg-green', 1:'bg-red', 2:'bg-yellow', 4:'bg-blue'}[m.status] || 'bg-gray';
                    let cleanVal = formatSmartValue(m.current, p.use_raw);
                    let shortVal = String(cleanVal).length > (p.box_size === 'small' ? 4 : 8) ? String(cleanVal).substring(0, (p.box_size === 'small' ? 4 : 8)) : cleanVal;
                    return `<button class="heat-box-dyn ${bgClass}" style="width:${s.w}; height:${s.h}; font-size:${s.f};" title="${m.module_name}: ${m.current} ${m.unit}" onclick="openNativeChart(${m.id}, '${m.module_name}')">${shortVal}</button>`;
                }).join('')}
            </div>`;
    }

    const isHidden = p.hidden === true;
    const hiddenClass = isHidden ? 'is-hidden' : '';

    return `
        <div class="panel-card ${hiddenClass}" style="height: 100%; margin:0;">
            <div class="panel-header">
                <div><h6 class="panel-title"><span class="material-symbols-outlined" style="font-size:14px; vertical-align:middle; margin-right:5px;">grid_view</span> ${p.title}</h6></div>
                ${controlsHtml}
            </div>
            <div class="panel-body" style="align-items:stretch; justify-content:flex-start; padding:10px;">${content}</div>
        </div>`;
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
    
    // Ensure spinners are shown while loading
    targetPanels.forEach(p => {
        const loadOverlay = document.getElementById(`load_${p.id}`);
        if(loadOverlay) loadOverlay.style.display = 'flex';
    });

    if (!agentId || agentId == 0) {
        targetPanels.forEach(p => {
            const wrapper = document.getElementById(`wrapper_p_${p.id}`);
            if(wrapper) {
                const controlsHtml = getPanelControlsHtml(p);
                wrapper.innerHTML = `<div class="panel-card"><div class="panel-header"><h5 class="panel-title">${p.title}</h5>${controlsHtml}</div><div class="panel-body" style="color:#7f8c8d; text-align:center; padding:20px;">Select a Node First</div></div>`;
            }
        });
        return;
    }

    const timeRng = getTimeRange();
    const payload = { agent_id: agentId, start: timeRng.start, end: timeRng.end, panels: targetPanels };

    fetch('?api=bulk_panel_data', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) })
    .then(r=>r.json()).then(res => {
        if (!res.ok) throw new Error(res.error || 'Unknown server error');
        
        const lastUpd = document.getElementById('last_update_text');
        if(lastUpd) lastUpd.innerText = 'Last Update: ' + new Date().toLocaleTimeString();
        
        const dataMap = res.data || {};
        lastFetchedData = dataMap; // Store for drill-down
        Object.values(chartInstances).forEach(c => { if(c) c.destroy(); });
        chartInstances = {};

        targetPanels.forEach(p => {
            const wrapper = document.getElementById(`wrapper_p_${p.id}`);
            if(!wrapper) return;
            
            const panelData = dataMap[p.id];
            if(!panelData || !panelData.found) { 
                const controlsHtml = getPanelControlsHtml(p);
                const hiddenClass = p.hidden ? 'is-hidden' : '';
                wrapper.innerHTML = `<div class="panel-card ${hiddenClass}">
                    <div class="panel-header"><h6 class="panel-title">${p.title}</h6>${controlsHtml}</div>
                    <div class="panel-body" style="color:#7f8c8d; text-align:center; padding:30px; align-items:center; justify-content:center;">
                        <span class="material-symbols-outlined" style="font-size:48px; color:#bdc3c7; display:block; margin-bottom:10px;">search_off</span>
                        Data not found for: <b>${p.keyword}</b><br>
                        <small style="color:#bdc3c7;">(Match: ${p.match_type})</small>
                    </div>
                </div>`; 
                return; 
            }

            // --- 1. Prepare Active Modules ---
            var activeModules = panelData.modules || [];
            if (!showHiddenPanels && p.excluded) {
                activeModules = activeModules.filter(m => !p.excluded.map(String).includes(String(m.id)));
            }
            activeModules.sort((a, b) => (b.last_contact || 0) - (a.last_contact || 0));

            // --- 2. Render HTML Content ---
            if (['status_table', 'status_heatmap', 'status_stats'].includes(p.type)) {
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

            // --- 3. Render Charts ---
            const multiColors = ['#2ecc71', '#3498db', '#e67e22', '#9b59b6', '#e74c3c', '#1abc9c', '#f1c40f', '#34495e'];

            if (p.multi_overlay && ['line', 'area', 'bar'].includes(p.type)) {
                // RENDER MULTI-OVERLAY CHART
                const uniqueId = `${p.id}_multi`;
                const canvas = document.getElementById(`chart_${uniqueId}`);
                if (canvas) {
                    // FIX: Collect unique labels from ALL active modules to ensure all lines appear
                    let allLabelsSet = new Set();
                    activeModules.forEach(m => (m.history || []).forEach(h => allLabelsSet.add(h.lbl)));
                    const labels = Array.from(allLabelsSet).sort(); 

                    const datasets = activeModules.map((m, idx) => {
                        const color = multiColors[idx % multiColors.length];
                        const historyMap = {};
                        (m.history || []).forEach(h => historyMap[h.lbl] = h.val);
                        const data = labels.map(lbl => historyMap[lbl] !== undefined ? historyMap[lbl] : null);

                        return {
                            label: m.module_name,
                            data: data,
                            borderColor: color,
                            backgroundColor: p.type === 'area' ? color + '33' : (p.type === 'bar' ? color : 'transparent'),
                            fill: p.type === 'area',
                            tension: 0.4,
                            pointRadius: 0,
                            borderWidth: p.type === 'bar' ? 0 : 2
                        };
                    });

                    try {
                        chartInstances[uniqueId] = new Chart(canvas.getContext('2d'), {
                            type: p.type === 'area' ? 'line' : p.type,
                            data: { labels: labels, datasets: datasets },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: { 
                                    legend: { 
                                        display: true, 
                                        position: 'bottom', 
                                        labels: { boxWidth: 10, font: { size: 9 }, color: '#64748b', usePointStyle: true } 
                                    },
                                    tooltip: {
                                        enabled: true,
                                        mode: 'index',
                                        intersect: false,
                                        backgroundColor: '#fff',
                                        titleColor: '#1e293b',
                                        bodyColor: '#475569',
                                        borderColor: '#e2e8f0',
                                        borderWidth: 1,
                                        padding: 10,
                                        cornerRadius: 6,
                                        displayColors: true,
                                        bodyFont: { size: 12 }
                                    }
                                },
                                scales: {
                                    x: { display: !!p.show_time, ticks: { font: { size: 8 }, autoSkip: true, maxTicksLimit: 6 }, grid: { display: false } },
                                    y: { 
                                        beginAtZero: true,
                                        max: p.force_100 ? 100 : undefined,
                                        ticks: { font: { size: 9 } }, 
                                        grid: { color: 'rgba(226, 232, 240, 0.6)', drawBorder: false } 
                                    }
                                }
                            }
                        });
                    } catch(e) { console.error("MultiChart Error:", e); }
                }
            } else {
                // RENDER INDIVIDUAL CHARTS
                activeModules.forEach(m => {
                    const uniqueId = `${p.id}_${m.id}`;
                    const canvas = document.getElementById(`chart_${uniqueId}`);
                    if (!canvas) return;
                    const color = {0:'#2ecc71', 1:'#e74c3c', 2:'#f1c40f', 4:'#3498db'}[m.status] || '#95a5a6';
                    const history = m.history || [];
                    if (['line','area','bar'].includes(p.type) && p.chart_engine === 'native') return;
                    const isHistoryChart = ['line','area','bar'].includes(p.type);
                    const isGaugeChart = p.type === 'gauge';
                    
                    if ((isHistoryChart && history.length === 0) || (isGaugeChart && (m.current === null || m.current === undefined || m.current === ''))) {
                        const parent = canvas.parentElement;
                        if(parent) parent.innerHTML = `<div style="color:#bdc3c7; font-size:11px; display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%;"><span class="material-symbols-outlined" style="font-size:24px; margin-bottom:5px;">query_stats</span>No Data</div>`;
                        return;
                    }

                    try {
                        if (p.type === 'gauge') {
                            let curVal = parseFloat(m.current);
                            if (isNaN(curVal)) curVal = 0;
                            const remainder = Math.max(0, 100 - curVal);
                            chartInstances[uniqueId] = new Chart(canvas.getContext('2d'), { 
                                type:'doughnut', 
                                data:{ datasets:[{data:[curVal, remainder], backgroundColor:[color, '#eee'], borderWidth:0}]}, 
                                options:{circumference:180, rotation:-90, cutout:'80%', plugins:{legend:{display:false}}, animation:{duration:1000}}
                            });
                        } else if (['line','area','bar'].includes(p.type)) {
                            chartInstances[uniqueId] = new Chart(canvas.getContext('2d'), { 
                                type: p.type==='area'?'line':p.type, 
                                data:{ 
                                    labels:history.map(h=>h.lbl), 
                                    datasets:[{
                                        label: m.module_name,
                                        data:history.map(h=>h.val), 
                                        borderColor:color, 
                                        fill:p.type==='area', 
                                        backgroundColor: (p.type==='bar') ? color : (p.type==='area' ? color + '44' : 'transparent'), 
                                        tension: 0.4,
                                        pointRadius: 0,
                                        pointHoverRadius: 4,
                                        borderWidth: (p.type==='bar' ? 0 : 2)
                                    }]
                                }, 
                                options:{
                                    responsive:true, 
                                    maintainAspectRatio:false, 
                                    plugins:{
                                        legend:{display:false},
                                        tooltip: {
                                            enabled: true,
                                            mode: 'index',
                                            intersect: false,
                                            backgroundColor: '#fff',
                                            titleColor: '#1e293b',
                                            bodyColor: '#475569',
                                            borderColor: '#e2e8f0',
                                            borderWidth: 1,
                                            padding: 10,
                                            cornerRadius: 6,
                                            displayColors: true,
                                            bodyFont: { size: 12 }
                                        }
                                    }, 
                                    scales: { 
                                        x: { 
                                            display: !!p.show_time,
                                            ticks: { font: { size: 8 }, autoSkip: true, maxTicksLimit: 6, maxRotation: 0 },
                                            grid: { display: false }
                                        }, 
                                        y: { 
                                            beginAtZero: true,
                                            max: p.force_100 ? 100 : undefined,
                                            ticks: { 
                                                font: { size: 9 },
                                                color: '#94a3b8',
                                                callback: function(value) {
                                                    return value + (m.unit ? ' ' + m.unit : '');
                                                }
                                            },
                                            grid: { color: 'rgba(226, 232, 240, 0.6)', drawBorder: false }
                                        }
                                    }
                                }
                            });
                        }
                    } catch(e) { console.error("Chart build error:", e); }
                });
            }
        });
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
    
    const isChart = ['line','area','bar'].includes(type);
    document.getElementById('wrap_show_time').style.display = isChart ? 'flex' : 'none';
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
    document.getElementById('p_lbl_1').value = '';
    document.getElementById('p_lbl_0').value = '';
    document.getElementById('p_show_module').checked = true;
    document.getElementById('p_use_raw').checked = false;
    document.getElementById('p_show_time').checked = true;
    document.getElementById('p_hidden').checked = false;
    document.getElementById('p_multi_overlay').checked = false;
    document.querySelector('input[name="p_match_type"][value="contains"]').checked = true;
    togglePanelLayoutOptions();
    toggleTypeFields();
    toggleChartEngine();
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
    document.getElementById('p_font_size').value = p.font_size || 32;
    document.getElementById('p_font_weight').value = p.font_weight || 700;
    document.getElementById('p_show_module').checked = p.show_module !== false;
    document.getElementById('p_use_raw').checked = p.use_raw || false;
    document.getElementById('p_force_100').checked = p.force_100 || false;
    document.getElementById('p_show_time').checked = p.show_time !== false;
    document.getElementById('p_chart_engine').value = p.chart_engine || 'custom';
    document.getElementById('p_lbl_1').value = p.lbl_1 || '';
    document.getElementById('p_lbl_0').value = p.lbl_0 || '';
    document.getElementById('p_hidden').checked = p.hidden || false;
    document.getElementById('p_multi_overlay').checked = p.multi_overlay || false;
    
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
    toggleTypeFields();
    toggleChartEngine();
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
        font_size: parseInt(document.getElementById('p_font_size').value) || 32,
        font_weight: document.getElementById('p_font_weight').value || 700,
        show_module: document.getElementById('p_show_module').checked,
        use_raw: document.getElementById('p_use_raw').checked,
        force_100: document.getElementById('p_force_100').checked,
        show_time: document.getElementById('p_show_time').checked,
        chart_engine: document.getElementById('p_chart_engine').value || 'custom',
        lbl_1: document.getElementById('p_lbl_1').value,
        lbl_0: document.getElementById('p_lbl_0').value,
        hidden: document.getElementById('p_hidden').checked,
        multi_overlay: document.getElementById('p_multi_overlay').checked,
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
            // If panel is hidden, unhide it first
            p.hidden = false;
        } else if (moduleId !== null && moduleId !== undefined) {
            // If panel is visible, but a moduleId is passed, toggle exclusion for that module
            if (!p.excluded) p.excluded = [];
            const sModId = String(moduleId);
            const idx = p.excluded.map(String).indexOf(sModId);
            if (idx > -1) p.excluded.splice(idx, 1);
            else p.excluded.push(sModId);
        } else {
            // Normal panel hide
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
    
    // Create a copy with a new ID
    const newP = JSON.parse(JSON.stringify(p));
    newP.id = 'p' + Date.now();
    newP.title = newP.title + ' (Copy)';
    
    dash.panels.push(newP);
    markUnsaved();
    renderPanelsGrid();
    forceRefresh();
}

function deletePanel(id) {
    if(confirm('Delete panel?')) {
        const dash = masterDashboards.find(d => d.id === currentDashId);
        dash.panels = dash.panels.filter(x => x.id !== id);
        markUnsaved(); renderPanelsGrid(); forceRefresh();
    }
}

function showStatusDetails(panelId, statusFilter, statusLabel) {
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
        return `
            <tr>
                <td style="color:#3498db; font-weight:600; cursor:pointer;" onclick="window.open('/pandora_console/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${m.agent_id}', '_blank')">${m.agent_name}</td>
                <td>${m.group_name}</td>
                <td style="color:#e74c3c;">${m.ip_address}</td>
                <td style="font-weight:500;">${m.module_name}</td>
                <td style="font-weight:600;">${m.current}</td>
                <td><span class="status-pill-dyn ${bgClass}">${statusLbl}</span></td>
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
    document.getElementById('statusDetailModal').style.display = 'none';
}

function openNativeChart(id, name) {
    window.open(`/pandora_console/index.php?sec=estado&sec2=operation/agentes/ver_agente&tab=graph&id_agente_modulo=${id}`, '_blank');
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

// Masonry Logic Removed to use standard Grid
function resizeGridItem(item) { }
function resizeAllGridItems() { }

window.addEventListener("resize", resizeAllGridItems);

const gridObserver = new ResizeObserver(entries => {
    for (let entry of entries) {
        // Debounce or direct call
        resizeGridItem(entry.target);
    }
});

function attachResizeObserver(pId) {
    setTimeout(() => {
        const el = document.getElementById(`wrapper_p_${pId}`);
        if (el) gridObserver.observe(el);
    }, 100);
}
</script>
</body>
</html>
