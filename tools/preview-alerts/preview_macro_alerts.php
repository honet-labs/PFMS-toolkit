<?php
/* preview_macro_alerts.php
 *
 * Tools Preview Macro Alert PandoraFMS
 * - UI/UX Match with Custom Dashboards
 * - Dynamic Breadcrumb
 * - Koneksi DB terintegrasi dengan config.php Pandora FMS
 * - Deteksi koneksi DB UI & Cascading Dropdown
 */
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
header('Content-Type: text/html; charset=utf-8');

// =====================================================================
// 1. DYNAMIC BREADCRUMB LOGIC
// =====================================================================
$raw_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$relative_path = str_replace('/pandora_console/custom/panel/', '', $raw_path);
$dir_only = dirname($relative_path);
if ($dir_only === '.') $dir_only = '';
$path_array = array_filter(explode('/', $dir_only));
$formatted_array = array_map(function($p) { 
    return ucwords(str_replace(['_', '-'], ' ', $p)); 
}, $path_array);
$dynamic_breadcrumb = !empty($formatted_array) ? implode(' / ', $formatted_array) : 'Tools';

// =====================================================================
// 2. FAST CONFIG LOADING (PANDORA FMS)
// =====================================================================
$config_paths = ['/var/www/html/pandora_console/include/config.php', '../../include/config.php', '../include/config.php'];
$config_loaded = false;
foreach ($config_paths as $path) { 
    if (file_exists($path)) { 
        require_once($path); 
        $config_loaded = true; 
        break; 
    } 
}

// =====================================================================
// 3. BACKEND LOGIC & HELPERS
// =====================================================================
$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function pretty_text($s) {
    if ($s === null) return '';
    $decoded = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
    return str_replace('&#x20;', ' ', $decoded);
}

function get_mock_graph($label) {
    $url_label = urlencode($label);
    return '<div style="margin: 15px 0; height: 200px; background-color: #e2e8f0; color: #475569; display: flex; align-items: center; justify-content: center; font-weight: normal; font-family: sans-serif; border-radius: 6px; border: 1px solid #cbd5e1;">' . $url_label . ' (Mock Graph)</div>';
}

$pdo = null;
$db_status = false;
$db_error_msg = '';

if ($config_loaded) {
    try {
        // Menggunakan kredensial dari config.php Pandora FMS
        $dsn = "mysql:host=" . $config["dbhost"] . ";dbname=" . $config["dbname"] . ";charset=utf8mb4";
        $opt = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, 
        ];
        $pdo = new PDO($dsn, $config["dbuser"], $config["dbpass"], $opt);
        $db_status = true;
    } catch (PDOException $e) {
        $db_error_msg = "Database Connection Error: " . $e->getMessage();
    }
} else {
    $db_error_msg = "Error: File config.php Pandora FMS tidak ditemukan.";
}

// AJAX ENDPOINTS
if (isset($_GET['ajax']) && $db_status) {
    header('Content-Type: application/json');
    try {
        if ($_GET['ajax'] === 'groups') {
            $stmt = $pdo->query("SELECT id_grupo AS id, nombre AS name FROM tgrupo ORDER BY name ASC");
            $results = $stmt->fetchAll();
            foreach ($results as &$r) { $r['name'] = pretty_text($r['name']); }
            echo json_encode($results);
        } elseif ($_GET['ajax'] === 'agents' && isset($_GET['group_id'])) {
            $stmt = $pdo->prepare("SELECT id_agente AS id, alias AS name FROM tagente WHERE id_grupo = :gid ORDER BY alias ASC");
            $stmt->execute([':gid' => $_GET['group_id']]);
            $results = $stmt->fetchAll();
            foreach ($results as &$r) { $r['name'] = pretty_text($r['name']); }
            echo json_encode($results);
        } elseif ($_GET['ajax'] === 'modules' && isset($_GET['agent_id'])) {
            $stmt = $pdo->prepare("SELECT id_agente_modulo AS id, nombre AS name FROM tagente_modulo WHERE id_agente = :aid AND disabled = 0 ORDER BY nombre ASC");
            $stmt->execute([':aid' => $_GET['agent_id']]);
            $results = $stmt->fetchAll();
            foreach ($results as &$r) { $r['name'] = pretty_text($r['name']); }
            echo json_encode($results);
        }
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// INPUT HANDLING
$default_template = "[ALERT] [PANDORA FMS - _alert_name_ - _agent_]\n\nHello team,\n\nThe agent _agent_ is currently in _modulestatus_ state on module _module_.\nPlease check it as soon as possible.\n\nMonitoring alert details:\nAgent: _agent_\nGroup Agent: _agentgroup_\nModule: _module_\nValue: _data_ _dataunit_\nStatus: _modulestatus_\nTime Detected: _timestamp_\n\nBest regards,\nPandora Assistant Monitoring";

$sel_group_id  = isset($_POST['group_id']) ? $_POST['group_id'] : '';
$sel_agent_id  = isset($_POST['agent_id']) ? $_POST['agent_id'] : '';
$sel_module_id = isset($_POST['module_id']) ? $_POST['module_id'] : '';
$template_text = isset($_POST['template_text']) ? $_POST['template_text'] : $default_template;

$errors = [];
$preview_text = '';
$db_data = null;

if (!$config_loaded) {
    $errors[] = $db_error_msg;
}

// MACRO REPLACEMENT LOGIC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db_status) {
    if (empty($sel_module_id) || empty($sel_agent_id)) {
        $errors[] = "Harap pilih Agent dan Module dari menu dropdown untuk melakukan generate preview.";
    } else {
        try {
            $sql = "SELECT 
                        ta.id_agente, ta.alias AS agent_alias, ta.nombre AS agent_name, ta.comentarios AS agent_desc, ta.direccion AS agent_address,
                        tg.nombre AS group_name, tos.name AS agent_os,
                        tam.id_agente_modulo, tam.nombre AS module_name, tam.descripcion AS module_desc, tam.unit AS module_unit,
                        tam.min_warning, tam.max_warning, tam.min_critical, tam.max_critical, tam.module_interval,
                        tae.datos AS current_data, tae.estado AS current_state, tae.utimestamp
                    FROM tagente_modulo tam
                    JOIN tagente ta ON tam.id_agente = ta.id_agente
                    LEFT JOIN tgrupo tg ON ta.id_grupo = tg.id_grupo
                    LEFT JOIN tconfig_os tos ON ta.id_os = tos.id_os
                    LEFT JOIN tagente_estado tae ON tam.id_agente_modulo = tae.id_agente_modulo
                    WHERE tam.id_agente_modulo = :mid";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([':mid' => $sel_module_id]);
            $db_data = $stmt->fetch();

            if (!$db_data) {
                $errors[] = "Data modul tidak ditemukan di database.";
            } else {
                $status_map = [0 => 'Normal', 1 => 'Warning', 2 => 'Critical', 3 => 'Unknown'];
                $mod_status = isset($status_map[$db_data['current_state']]) ? $status_map[$db_data['current_state']] : 'Not Init';

                $macros = [
                    '_agent_'                  => pretty_text($db_data['agent_alias']) ?: 'N/A',
                    '_agentalias_'             => pretty_text($db_data['agent_alias']) ?: 'N/A',
                    '_agentname_'              => pretty_text($db_data['agent_name']) ?: 'N/A',
                    '_agentdescription_'       => pretty_text($db_data['agent_desc']) ?: 'N/A',
                    '_agentstatus_'            => $mod_status, 
                    '_agentgroup_'             => pretty_text($db_data['group_name']) ?: 'N/A',
                    '_agentos_'                => pretty_text($db_data['agent_os']) ?: 'Unknown OS',
                    '_address_'                => pretty_text($db_data['agent_address']) ?: 'N/A',
                    '_module_'                 => pretty_text($db_data['module_name']) ?: 'N/A',
                    '_modulegroup_'            => 'General',
                    '_moduledescription_'      => pretty_text($db_data['module_desc']) ?: 'N/A',
                    '_modulestatus_'           => $mod_status,
                    '_moduletags_'             => 'N/A',
                    '_data_'                   => pretty_text($db_data['current_data']) !== '' ? pretty_text($db_data['current_data']) : 'N/A',
                    '_prevdata_'               => 'N/A (Preview)',
                    '_dataunit_'               => pretty_text($db_data['module_unit']) ?: '', 
                    '_warning_threshold_min_'  => $db_data['min_warning'] ?: '0',
                    '_warning_threshold_max_'  => $db_data['max_warning'] ?: '0',
                    '_critical_threshold_min_' => $db_data['min_critical'] ?: '0',
                    '_critical_threshold_max_' => $db_data['max_critical'] ?: '0',
                    '_timestamp_'              => $db_data['utimestamp'] ? date('Y/m/d H:i:s', $db_data['utimestamp']) : 'N/A',
                    '_timezone_'               => $DEFAULT_TZ,
                    '_interval_'               => $db_data['module_interval'] ?: 'N/A',
                    '_id_agent_'               => $db_data['id_agente'],
                    '_id_module_'              => $db_data['id_agente_modulo'],
                    '_id_alert_'               => rand(10000, 99999),
                    '_id_group_'               => $sel_group_id ?: 'N/A',
                    '_alert_name_'             => 'Template_Critical_Condition',
                    '_alert_description_'      => 'Module went into critical state',
                    '_alert_threshold_'        => '1',
                    '_alert_times_fired_'      => '3',
                    '_event_text_'             => 'Event trigger mock text',
                    '_event_id_'               => rand(1000, 5000),
                    '_event_source_'           => 'System',
                    '_target_ip_'              => pretty_text($db_data['agent_address']) ?: 'N/A',
                    '_target_port_'            => 'N/A',
                    '_plugin_parameters_'      => 'N/A',
                    '_email_tag_'              => 'admin@domain.local',
                    '_phone_tag_'              => '08123456789',
                    '_name_tag_'               => 'Tag1, Tag2',
                    '_groupcontact_'           => 'noc@domain.local',
                    '_modulegraph_1h_'         => get_mock_graph('Module Graph: 1 Hour'),
                    '_modulegraph_24h_'        => get_mock_graph('Module Graph: 24 Hours'),
                    '_modulegraph_7d_'         => get_mock_graph('Module Graph: 7 Days'),
                    '_rca_'                    => 'N/A (RCA available for Services only)',
                    '_secondarygroups_'        => 'N/A',
                    '_server_ip_'              => '127.0.0.1',
                    '_server_name_'            => 'PandoraServer',
                    '_time_down_human_'        => '1day 10h 35m 40s (Mock)',
                    '_time_down_seconds_'      => '124540 (Mock)',
                    '_statusimagetag_'         => '<span style="background:#fee2e2; border:1px solid #ef4444; color:#b91c1c; padding:2px 6px; font-size:10px; border-radius:3px; font-weight: normal;">[IMG: Critical]</span>'
                ];

                $preview_text = $template_text;
                foreach ($macros as $macro => $value) {
                    $preview_text = str_replace($macro, $value, $preview_text);
                }
            }
        } catch (Exception $e) { $errors[] = "DB Query Error: " . $e->getMessage(); }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PandoraFMS - Alert Macro Previewer</title>
    
    <link rel="icon" href="/pandora_console/images/pandora.ico" type="image/x-icon">
    
    
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" />
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">

    <style>
        /* Base Global Styling */
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #334155; font-size: 14px; -webkit-font-smoothing: antialiased; } * { box-sizing: border-box; }
        body { background-color: #f4f6f8; margin: 0; padding: 0; }

        /* MATERIAL SYMBOLS FIX */
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-weight: normal !important; font-style: normal !important; font-size: 18px !important; line-height: 1 !important; display: inline-block; vertical-align: middle; color: inherit !important; }

        /* NAVBAR 1: GLOBAL TOP HEADER */
        .pandora-header-top { background-color: #ffffff; border-bottom: 1px solid #e0e4e8; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 25px; position: relative; z-index: 10; }
        .header-left { display: flex; align-items: center; }
        .header-logo { height: 24px; width: auto; object-fit: contain; }
        .header-divider { width: 1px; height: 28px; background-color: #dce1e5; margin: 0 20px; }
        .header-title-box { display: flex; flex-direction: column; line-height: 1.2; margin-right: 40px; }
        .header-title-box .main-title { font-size: 14px !important; font-weight: normal !important; color: #0b1a26 !important; }
        .header-title-box .sub-title { font-size: 12px !important; font-weight: normal !important; color: #7f8c8d !important; }

        .custom-search-container { position: relative; width: 450px; }
        .custom-search-container .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #7f8c8d !important; font-size: 20px !important; pointer-events: none; }
        .custom-search-container input { width: 100%; height: 36px; padding: 8px 15px 8px 40px; border-radius: 18px; border: 1px solid transparent; background-color: #f4f6f8; font-size: 13px !important; color: #333 !important; transition: all 0.2s ease; }
        .custom-search-container input:focus { background-color: #ffffff; border-color: #b5c1c9; outline: none; box-shadow: 0 0 0 3px rgba(181, 193, 201, 0.2); }

        .header-right { display: flex; align-items: center; gap: 15px; }
        .nav-icon-btn { color: #4a5568 !important; text-decoration: none; display: flex; align-items: center; justify-content: center; height: 36px; width: 36px; border-radius: 50%; transition: 0.2s; }
        .nav-icon-btn:hover { background-color: #f4f6f8; color: #1a252f !important; }

        /* NAVBAR 2: MODULE SUB-HEADER */
        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: flex-start; justify-content: space-between; }
        .breadcrumb-box { display: flex; flex-direction: column; }
        .page-breadcrumb { font-size: 12px !important; font-weight: normal !important; color: #4a5568 !important; margin-bottom: 2px; }
        .page-title { font-size: 18px !important; font-weight: 600 !important; color: #0b1a26 !important; margin: 0; padding: 0; }

        /* DB STATUS BADGE */
        .db-status { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 4px; font-size: 11px !important; font-weight: normal !important; text-transform: uppercase; }
        .db-online { background-color: #e8f5e9; color: #2e7d32 !important; border: 1px solid #c8e6c9; }
        .db-offline { background-color: #ffebee; color: #c62828 !important; border: 1px solid #ffcdd2; }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; }
        .db-online .status-dot { background-color: #4caf50; box-shadow: 0 0 4px #4caf50; }
        .db-offline .status-dot { background-color: #f44336; box-shadow: 0 0 4px #f44336; }

        /* MAIN CONTENT & CARDS */
        .main-content { padding: 0 30px 30px 30px; }
        .dashboard-card { background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; flex-direction: column; height: 100%; border: 1px solid #f0f3f5;}
        .dashboard-card-header { padding: 15px 20px; border-bottom: 1px solid #e0e4e8; background-color: #f8f9fa; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .dashboard-card-title { font-size: 14px !important; font-weight: 500 !important; color: #0b1a26 !important; margin: 0; display: flex; align-items: center; gap: 8px; text-transform: uppercase;}
        .dashboard-card-body { padding: 20px; }

        /* FORMS */
        .form-label { font-size: 11px !important; text-transform: uppercase; font-weight: normal !important; color: #7f8c8d; margin-bottom: 5px; display: block; }
        .form-control, .form-select { border: 1px solid #dce1e5; padding: 8px 12px; background-color: #fff; font-weight: normal !important; color: #000 !important; border-radius: 4px; transition: 0.2s; }
        .form-control:focus, .form-select:focus { border-color: #004d40; box-shadow: 0 0 0 2px rgba(0,77,64,0.1); }
        .form-control:disabled, .form-select:disabled { background-color: #f4f6f8; color: #9ca3af !important; cursor: not-allowed; }

        /* BUTTONS */
        .btn-apply { background: #004d40; color: #fff !important; border: none; padding: 10px 25px; border-radius: 4px; font-weight: normal !important; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; }
        .btn-apply:hover:not(:disabled) { background: #00695c; color: #fff; }
        .btn-apply:disabled { background-color: #94a3b8; cursor: not-allowed; }

        /* PREVIEW AREA */
        .preview-box { background-color: #f8fafc; border: 1px solid #dce1e5; border-radius: 6px; padding: 20px; font-size: 14px !important; color: #334155; word-wrap: break-word; min-height: 400px; line-height: 1.6; }
        .preview-box p { margin-top: 0; margin-bottom: 10px; font-family: inherit !important; font-size: 14px !important;}
        .info-hint { background-color: #e3f2fd; border: 1px solid #bbdefb; padding: 12px; border-radius: 6px; margin-top: 15px; font-size: 12px !important; color: #0d47a1; }

        /* MACRO TABLE ACCORDION */
        .macro-section { margin-top: 25px; border: 1px solid #dce1e5; border-radius: 6px; }
        .macro-summary { padding: 12px 16px; font-weight: normal !important; background-color: #f8f9fa; cursor: pointer; list-style: none; display: flex; align-items: center; gap: 8px; border-radius: 6px; transition: 0.2s; }
        .macro-summary::-webkit-details-marker { display: none; }
        .macro-summary:hover { background-color: #eef2f5; }
        .macro-table-wrapper { max-height: 350px; overflow-y: auto; border-top: 1px solid #dce1e5; }
        .macro-table { width: 100%; border-collapse: collapse; font-size: 12px !important; }
        .macro-table th, .macro-table td { padding: 10px 15px; border-bottom: 1px solid #f0f3f5; text-align: left; }
        .macro-table th { background-color: #fcfcfc; color: #7f8c8d; font-weight: normal !important; width: 35%; }
        .macro-code { background-color: #eef2f5; padding: 3px 8px; border-radius: 4px; font-family: 'Courier New', Courier, monospace !important; color: #004d40; font-weight: normal !important; }
        .section-title { background-color: #f8f9fa; padding: 8px 15px; font-weight: normal !important; color: #0b1a26; font-size: 11px !important; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 2px solid #dce1e5; }
    </style>
</head>
<body>

<div class="pandora-header-top">
    <div class="header-left">
        <img src="/pandora_console/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Pandora Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box">
            <span class="main-title">Pandora FMS</span>
            <span class="sub-title">the Flexible Monitoring System</span>
        </div>
        <div class="custom-search-container">
            <span class="material-symbols-outlined search-icon">search</span>
            <input type="text" placeholder="Global search..." readonly onclick="alert('Tip: Search functionality is disabled in Preview mode.')">
        </div>
    </div>
    <div class="header-right">
        <a href="/pandora_console/index.php" class="nav-icon-btn" title="Back to Home">
            <span class="material-symbols-outlined">home</span>
        </a>
    </div>
</div>

<div class="pandora-header-bottom">
    <div class="breadcrumb-box">
        <span class="page-breadcrumb"><?= htmlspecialchars($dynamic_breadcrumb) ?></span>
        <h1 class="page-title">Alert Macro Previewer</h1>
    </div>
    <div>
        <?php if ($db_status): ?>
            <div class="db-status db-online" title="Terhubung ke Database">
                <div class="status-dot"></div> DB CONNECTED
            </div>
        <?php else: ?>
            <div class="db-status db-offline" title="<?php echo h($db_error_msg); ?>">
                <div class="status-dot"></div> DB OFFLINE / ERROR
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="main-content">
    <?php if ($errors): ?>
        <div class="alert alert-danger" style="border-radius: 6px; font-weight: normal !important; margin-top:20px;">
            <?php echo implode("<br>", $errors); ?>
        </div>
    <?php endif; ?>

    <form method="POST" style="margin-top: 20px;">
        <div class="row align-items-stretch">
            
            <div class="col-lg-5 mb-4 mb-lg-0 d-flex flex-column">
                <div class="dashboard-card">
                    <div class="dashboard-card-header">
                        <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="font-size:18px!important; color:#004d40;">settings_suggest</span> Configuration</h5>
                    </div>
                    <div class="dashboard-card-body">
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">1. Group</label>
                                <select name="group_id" id="sel_group" class="form-select" <?php echo !$db_status ? 'disabled' : ''; ?>>
                                    <option value="">-- Select Group --</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">2. Agent</label>
                                <select name="agent_id" id="sel_agent" class="form-select" disabled>
                                    <option value="">-- Select Agent --</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">3. Module</label>
                                <select name="module_id" id="sel_module" class="form-select" disabled required>
                                    <option value="">-- Select Module --</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Template Message (Supports HTML & Macros)</label>
                            <textarea name="template_text" class="form-control" rows="12" required <?php echo !$db_status ? 'disabled' : ''; ?>><?php echo h($template_text); ?></textarea>
                        </div>

                        <button class="btn-apply" type="submit" id="btn_submit" <?php echo (!$db_status || empty($sel_module_id)) ? 'disabled' : ''; ?>>
                            <span class="material-symbols-outlined" style="font-size:18px!important;">preview</span> GENERATE PREVIEW
                        </button>

                        <details class="macro-section">
                            <summary class="macro-summary">
                                <span class="material-symbols-outlined" style="color:#004d40;">menu_book</span> Pandora FMS Macro Dictionary
                            </summary>
                            <div class="macro-table-wrapper">
                                <table class="macro-table">
                                    <tr><td colspan="2" class="section-title">Agent & Group</td></tr>
                                    <tr><th><span class="macro-code">_agent_</span></th><td>Agent Alias.</td></tr>
                                    <tr><th><span class="macro-code">_agentname_</span></th><td>Agent System Name.</td></tr>
                                    <tr><th><span class="macro-code">_agentdescription_</span></th><td>Agent Description.</td></tr>
                                    <tr><th><span class="macro-code">_agentstatus_</span></th><td>Current Agent Status.</td></tr>
                                    <tr><th><span class="macro-code">_agentos_</span></th><td>Agent Operating System.</td></tr>
                                    <tr><th><span class="macro-code">_agentgroup_</span></th><td>Agent Primary Group.</td></tr>
                                    <tr><th><span class="macro-code">_address_</span></th><td>Agent IP Address.</td></tr>
                                    
                                    <tr><td colspan="2" class="section-title">Module & Data</td></tr>
                                    <tr><th><span class="macro-code">_module_</span></th><td>Module Name.</td></tr>
                                    <tr><th><span class="macro-code">_moduledescription_</span></th><td>Module Description.</td></tr>
                                    <tr><th><span class="macro-code">_modulestatus_</span></th><td>Module Status (Normal, Critical, etc).</td></tr>
                                    <tr><th><span class="macro-code">_data_</span></th><td>Last Module Value.</td></tr>
                                    <tr><th><span class="macro-code">_prevdata_</span></th><td>Value before alert was triggered.</td></tr>
                                    <tr><th><span class="macro-code">_dataunit_</span></th><td>Module Unit (%, Mbps, etc).</td></tr>
                                    
                                    <tr><td colspan="2" class="section-title">Thresholds</td></tr>
                                    <tr><th><span class="macro-code">_warning_threshold_min_</span></th><td>Warning Min Threshold.</td></tr>
                                    <tr><th><span class="macro-code">_warning_threshold_max_</span></th><td>Warning Max Threshold.</td></tr>
                                    <tr><th><span class="macro-code">_critical_threshold_min_</span></th><td>Critical Min Threshold.</td></tr>
                                    <tr><th><span class="macro-code">_critical_threshold_max_</span></th><td>Critical Max Threshold.</td></tr>
                                    
                                    <tr><td colspan="2" class="section-title">Time & Meta</td></tr>
                                    <tr><th><span class="macro-code">_timestamp_</span></th><td>Detection Time.</td></tr>
                                    <tr><th><span class="macro-code">_timezone_</span></th><td>Timezone configured.</td></tr>
                                    <tr><th><span class="macro-code">_interval_</span></th><td>Module Interval execution.</td></tr>
                                    
                                    <tr><td colspan="2" class="section-title">Alert Specific</td></tr>
                                    <tr><th><span class="macro-code">_alert_name_</span></th><td>Alert Template Name.</td></tr>
                                    <tr><th><span class="macro-code">_alert_description_</span></th><td>Alert Template Description.</td></tr>
                                    
                                    <tr><td colspan="2" class="section-title">HTML Visuals</td></tr>
                                    <tr><th><span class="macro-code">_modulegraph_1h_</span></th><td>Image Graph 1 Hour.</td></tr>
                                    <tr><th><span class="macro-code">_modulegraph_24h_</span></th><td>Image Graph 24 Hours.</td></tr>
                                    <tr><th><span class="macro-code">_statusimagetag_</span></th><td>HTML Tag Image for Module Status.</td></tr>
                                </table>
                            </div>
                        </details>

                    </div>
                </div>
            </div>

            <div class="col-lg-7 d-flex flex-column">
                <div class="dashboard-card">
                    <div class="dashboard-card-header">
                        <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="font-size:18px!important; color:#004d40;">visibility</span> Live Output Result</h5>
                        <?php if ($preview_text !== ''): ?>
                            <span style="background:#dcfce7; color:#166534; font-size:10px!important; padding:4px 10px; border-radius:4px; font-weight: normal!important; text-transform:uppercase;">Evaluated Success</span>
                        <?php endif; ?>
                    </div>
                    <div class="dashboard-card-body" style="background-color: #f8fafc;">
                        <div class="preview-box">
                            <?php if ($preview_text !== ''): ?>
                                <?php 
                                    $has_html = ($preview_text !== strip_tags($preview_text));
                                    if ($has_html) {
                                        echo $preview_text; 
                                    } else {
                                        echo nl2br(h($preview_text)); 
                                    }
                                ?>
                            <?php else: ?>
                                <div style="color:#9ca3af; text-align:center; margin-top:150px; font-weight: normal!important;">
                                    <span class="material-symbols-outlined" style="font-size:40px!important; display:block; margin-bottom:10px; opacity:0.5;">find_in_page</span>
                                    Preview output will appear here.<br>Select Group > Agent > Module, then click "Generate Preview".
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($db_data): ?>
                        <div class="info-hint">
                            <strong style="display:flex; align-items:center; gap:5px; margin-bottom:5px;"><span class="material-symbols-outlined" style="font-size:14px!important;">info</span> Target Database Metrics Info:</strong>
                            Group ID: <code><?php echo h($sel_group_id); ?></code> &nbsp;|&nbsp; 
                            Agent ID: <code><?php echo h($db_data['id_agente']); ?></code> &nbsp;|&nbsp; 
                            Module ID: <code><?php echo h($db_data['id_agente_modulo']); ?></code>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </form>
</div>

<script>
    const dbStatus = <?php echo $db_status ? 'true' : 'false'; ?>;
    const selectedGroupId = "<?php echo h($sel_group_id); ?>";
    const selectedAgentId = "<?php echo h($sel_agent_id); ?>";
    const selectedModuleId = "<?php echo h($sel_module_id); ?>";

    document.addEventListener("DOMContentLoaded", function() {
        if (!dbStatus) return;

        const selGroup = document.getElementById('sel_group');
        const selAgent = document.getElementById('sel_agent');
        const selModule = document.getElementById('sel_module');
        const btnSubmit = document.getElementById('btn_submit');

        fetch('?ajax=groups')
            .then(res => res.json())
            .then(data => {
                data.forEach(item => {
                    let opt = new Option(item.name, item.id);
                    if (item.id == selectedGroupId) opt.selected = true;
                    selGroup.add(opt);
                });
                if (selectedGroupId) loadAgents(selectedGroupId, selectedAgentId);
            });

        selGroup.addEventListener('change', function() {
            selAgent.innerHTML = '<option value="">-- Select Agent --</option>';
            selModule.innerHTML = '<option value="">-- Select Module --</option>';
            selAgent.disabled = true;
            selModule.disabled = true;
            btnSubmit.disabled = true;

            if (this.value) loadAgents(this.value, null);
        });

        selAgent.addEventListener('change', function() {
            selModule.innerHTML = '<option value="">-- Select Module --</option>';
            selModule.disabled = true;
            btnSubmit.disabled = true;

            if (this.value) loadModules(this.value, null);
        });

        selModule.addEventListener('change', function() {
            btnSubmit.disabled = !this.value;
        });

        function loadAgents(groupId, preselectId) {
            selAgent.innerHTML = '<option value="">Loading...</option>';
            fetch('?ajax=agents&group_id=' + groupId)
                .then(res => res.json())
                .then(data => {
                    selAgent.innerHTML = '<option value="">-- Select Agent --</option>';
                    data.forEach(item => {
                        let opt = new Option(item.name, item.id);
                        if (item.id == preselectId) opt.selected = true;
                        selAgent.add(opt);
                    });
                    selAgent.disabled = false;
                    if (preselectId) loadModules(preselectId, selectedModuleId);
                });
        }

        function loadModules(agentId, preselectId) {
            selModule.innerHTML = '<option value="">Loading...</option>';
            fetch('?ajax=modules&agent_id=' + agentId)
                .then(res => res.json())
                .then(data => {
                    selModule.innerHTML = '<option value="">-- Select Module --</option>';
                    data.forEach(item => {
                        let opt = new Option(item.name, item.id);
                        if (item.id == preselectId) opt.selected = true;
                        selModule.add(opt);
                    });
                    selModule.disabled = false;
                    if (preselectId) btnSubmit.disabled = false;
                });
        }
    });
</script>
</body>
</html>
