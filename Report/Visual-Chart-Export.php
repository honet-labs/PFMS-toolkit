<?php
// Dynamically locate includes/db-connection.php by searching parent directories upwards
$dir = __DIR__;
while ($dir !== '/' && $dir !== '.' && !file_exists($dir . '/includes/db-connection.php')) {
    $parent = dirname($dir);
    if ($parent === $dir) break;
    $dir = $parent;
}
require_once $dir . '/includes/db-connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['id_usuario'] ?? 0;

// Security Check: Ensure valid Pandora FMS session
if (empty($user_id)) {
    header("Location: /pandora_console/index.php");
    exit;
}

// Detect Pandora base and panel dir variables
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
$DASHBOARD_FILE = __DIR__ . '/visual-charts-reports.json';
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';

$api = $_GET['api'] ?? '';

// AJAX ENDPOINTS
if ($api === 'dashboards_list') {
    ob_clean(); header('Content-Type: application/json');
    if (file_exists($DASHBOARD_FILE)) {
        echo file_get_contents($DASHBOARD_FILE);
    } else {
        echo json_encode([]);
    }
    exit;
}

if ($api === 'save_dashboards' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['csrf_token'] ?? '';
    $input_raw = file_get_contents('php://input');
    $input = json_decode($input_raw, true);

    if (empty($csrf_token) || ($client_token !== $csrf_token && ($input['csrf_token'] ?? '') !== $csrf_token)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token']);
        exit;
    }

    $dashboards = $input['dashboards'] ?? [];
    $bytes = @file_put_contents($DASHBOARD_FILE, json_encode($dashboards, JSON_PRETTY_PRINT));
    if ($bytes === false) {
        echo json_encode(['ok' => false, 'error' => 'Failed to write config file']);
    } else {
        echo json_encode(['ok' => true]);
    }
    exit;
}
if ($api === 'agents_list' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $list = [];
    
    // 1. Primary DB agents
    try {
        $stmt = $pdo->query("SELECT id_agente AS id, alias FROM tagente WHERE disabled = 0 ORDER BY alias ASC");
        while($a = $stmt->fetch()) {
            $list[] = ['id' => 'primary:' . $a['id'], 'alias' => '[Primary] ' . pretty_text($a['alias'])];
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

if ($api === 'module_list' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $agentIdsRaw = $_GET['agent_ids'] ?? $_GET['agent_id'] ?? '';
    if (empty($agentIdsRaw)) {
        echo json_encode([]); exit;
    }

    $agentIds = array_unique(array_filter(explode(',', $agentIdsRaw)));
    $list = [];
    global $custom_pdos;

    foreach ($agentIds as $agentIdRaw) {
        $parsed = parse_node_id($agentIdRaw);
        $node = $parsed['node'];
        $agent_id = $parsed['id'];

        $active_pdo = ($node === 'primary') ? $pdo : ($custom_pdos[$node] ?? null);
        if ($active_pdo === null) continue;

        try {
            $stmt_agent = $active_pdo->prepare("SELECT alias FROM tagente WHERE id_agente = ?");
            $stmt_agent->execute([$agent_id]);
            $agent_row = $stmt_agent->fetch();
            $agent_alias = $agent_row ? pretty_text($agent_row['alias']) : 'Unknown Agent';

            $stmt = $active_pdo->prepare("SELECT id_agente_modulo AS id, nombre FROM tagente_modulo WHERE id_agente = ? AND disabled = 0 ORDER BY nombre ASC");
            $stmt->execute([$agent_id]);
            while($m = $stmt->fetch()) {
                $list[] = [
                    'id' => $node . ':' . $m['id'],
                    'name' => '[' . $agent_alias . '] ' . pretty_text($m['nombre'])
                ];
            }
        } catch (Throwable $e) {}
    }

    usort($list, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });

    echo json_encode($list); exit;
}

if ($api === 'chart_data' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    $moduleIdsRaw = $_GET['module_ids'] ?? '';
    if (empty($moduleIdsRaw)) {
        echo json_encode(['ok' => false, 'error' => 'No modules selected']); exit;
    }

    $moduleIds = explode(',', $moduleIdsRaw);
    $range = $_GET['time_range'] ?? '86400';
    if ($range === 'custom') {
        $startTime = isset($_GET['start_time']) ? (int)$_GET['start_time'] : (time() - 86400);
        $endTime = isset($_GET['end_time']) ? (int)$_GET['end_time'] : time();
    } else {
        $endTime = time();
        $startTime = $endTime - (int)$range;
    }

    $historyData = [];
    $modulesMeta = [];

    global $custom_pdos;

    foreach ($moduleIds as $modIdRaw) {
        $parsed = parse_node_id($modIdRaw);
        $node = $parsed['node'];
        $modId = $parsed['id'];

        $active_pdo = ($node === 'primary') ? $pdo : ($custom_pdos[$node] ?? null);
        if ($active_pdo === null) continue;

        // Fetch module meta
        try {
            $stmt = $active_pdo->prepare("
                SELECT m.id_agente_modulo, m.nombre AS module_name, a.alias AS agent_alias, COALESCE(m.unit, '') as unit
                FROM tagente_modulo m
                JOIN tagente a ON m.id_agente = a.id_agente
                WHERE m.id_agente_modulo = ?
            ");
            $stmt->execute([$modId]);
            $meta = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($meta) {
                $meta['id_agente_modulo'] = $node . ':' . $meta['id_agente_modulo'];
                $meta['module_name'] = pretty_text($meta['module_name']);
                $meta['agent_alias'] = pretty_text($meta['agent_alias']);
                $modulesMeta[$modIdRaw] = $meta;
            }
        } catch (Throwable $e) {}

        // Fetch history
        $modHist = get_module_history_data($pdo, $history_pdo, $modIdRaw, $startTime, $endTime, 2000, 'ASC');
        foreach ($modHist as $h) {
            $historyData[] = [
                'id_mod' => $modIdRaw,
                'utimestamp' => (int)$h['ts'],
                'time' => date('d/m/Y H:i:s', $h['ts']),
                'val' => (float)$h['datos']
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'meta' => array_values($modulesMeta),
        'history' => $historyData
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visual Chart Reports - Pandora FMS</title>
    <!-- Core fonts and style dependencies -->
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($panelDirName ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($panelDirName ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <!-- ECharts JS Library -->
    <script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($panelDirName ?? "custom") ?>/panel/vendor/echarts/echarts.min.js"></script>
    
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            margin: 0;
            padding: 0;
            font-size: 13px;
        }
        .main-container {
            padding: 25px 30px;
        }
        .card-custom {
            background: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #e2e8f0;
            background-color: #f8fafc;
            font-weight: 600;
            color: #334155;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .card-body-custom {
            padding: 24px;
        }
        .form-label-custom {
            font-weight: 500;
            color: #475569;
            margin-bottom: 6px;
            display: block;
        }
        .btn-pfms {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary-pfms {
            background: #004d40;
            color: #ffffff;
        }
        .btn-primary-pfms:hover:not(:disabled) {
            background: #00332a;
        }
        .btn-outline-pfms {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #475569;
        }
        .btn-outline-pfms:hover:not(:disabled) {
            background: #f1f5f9;
            border-color: #94a3b8;
        }
        .btn-pfms:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        /* Custom dropdown / checkbox list */
        .checkbox-list-container {
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            max-height: 180px;
            overflow-y: auto;
            background: #ffffff;
            margin-top: 6px;
            padding: 6px;
        }
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .checkbox-item:hover {
            background-color: #f1f5f9;
        }
        .checkbox-item input {
            cursor: pointer;
        }
        .checkbox-item label {
            margin: 0;
            cursor: pointer;
            user-select: none;
            flex-grow: 1;
        }
        
        .preview-box {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            min-height: 380px;
            background-color: #0d1117; 
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .chart-canvas-container {
            width: 100%;
            height: 380px;
        }
        
        /* Spin animation for spinner */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .spinner-icon {
            animation: spin 1s linear infinite;
            display: inline-block;
        }
        .material-symbols-outlined {
            font-family: 'Material Symbols Outlined';
            font-weight: normal;
            font-style: normal;
            font-size: 20px;
            line-height: 1;
            letter-spacing: normal;
            text-transform: none;
            display: inline-block;
            white-space: nowrap;
            word-wrap: normal;
            direction: ltr;
            -webkit-font-feature-settings: 'liga';
            -webkit-font-smoothing: antialiased;
            vertical-align: middle;
        }
        
        /* Stats Table Styles */
        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
            font-size: 11px;
        }
        .stats-table th {
            background: #f8fafc;
            padding: 6px 10px;
            text-align: left;
            font-weight: 600;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase;
            font-size: 9px;
        }
        .stats-table td {
            padding: 6px 10px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        .stats-table tbody tr:hover {
            background-color: #f8fafc;
        }

        /* Dashboard specific styles */
        .dashboard-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            height: 100%;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .dashboard-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border-color: #004d40;
        }
        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background-color: #004d40;
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }
        .dashboard-card:hover::before {
            transform: scaleY(1);
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }
        .modal-box {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        .modal-header-custom {
            padding: 16px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }
        .modal-footer-custom {
            padding: 16px 24px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
        }

        .btn-action-panel {
            background: transparent;
            border: none;
            color: #94a3b8;
            padding: 4px;
            border-radius: 4px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .btn-action-panel:hover {
            background: rgba(0,0,0,0.05);
            color: #0f172a;
        }
        .btn-action-panel.text-danger:hover {
            background: #fef2f2;
            color: #ef4444;
        }

        .header-section {
            padding: 15px 30px;
            background: #ffffff;
            border-bottom: 1px solid #e0e4e8;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .page-title {
            font-size: 16px;
            font-weight: 600;
            color: #0b1a26;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Print styles */
        @media print {
            body, html {
                background: #ffffff !important;
                color: #000000 !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .no-print, .header-section, .modal-overlay, #dashboard-list-view, .btn-pfms, .btn-action-panel {
                display: none !important;
            }
            #dashboard-detail-view {
                display: block !important;
            }
            .card-custom {
                box-shadow: none !important;
                border: 1px solid #cbd5e1 !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            .col-lg-6 {
                width: 50% !important;
                float: left !important;
            }
            .col-lg-12 {
                width: 100% !important;
            }
        }
    </style>
</head>
<body>

<div class="header-section no-print">
    <div class="d-flex align-items-center gap-3">
        <button id="header-back-btn" class="btn-pfms btn-outline-pfms d-none" onclick="showDashboardList()">
            <span class="material-symbols-outlined">arrow_back</span> Back
        </button>
        <div>
            <h1 class="page-title" id="page-nav-header">Visual Chart Reports</h1>
        </div>
    </div>
    <div id="header-actions">
        <!-- Action buttons rendered dynamically -->
    </div>
</div>

<div class="main-container">
    <!-- 1. DASHBOARD LIST VIEW -->
    <div id="dashboard-list-view">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="font-weight-bold m-0 text-dark" style="font-size:16px;">My Visual Reports</h4>
                <p class="text-muted small m-0">Create and manage your custom reporting views.</p>
            </div>
        </div>

        <div class="row g-4" id="dashboards-grid-container">
            <!-- Rendered dynamically -->
        </div>
    </div>

    <!-- 2. DASHBOARD DETAIL VIEW -->
    <div id="dashboard-detail-view" class="d-none">
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <div>
                <h2 class="h5 font-weight-bold text-dark m-0" id="detail-dashboard-title">Report Title</h2>
                <p class="text-muted small m-0" id="detail-dashboard-subtitle">Created on ...</p>
            </div>
        </div>

        <!-- Panels Grid Container -->
        <div class="row" id="panels-grid-container">
            <!-- Rendered dynamically -->
        </div>
    </div>
</div>

<!-- REPORT MODAL (Create/Rename) -->
<div class="modal-overlay" id="dashboard-modal">
    <div class="modal-box" style="width: 500px; max-width: 90vw;">
        <div class="modal-header-custom d-flex justify-content-between align-items-center">
            <h5 class="m-0 font-weight-bold text-dark" id="dashboard-modal-title">Create Report Visual</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeDashboardModal()">close</span>
        </div>
        <div class="modal-body p-4">
            <div class="mb-3">
                <label class="form-label-custom">Report Visual Name</label>
                <input type="text" id="dashboard-name-input" class="form-control" placeholder="e.g. Server Performance Monthly">
            </div>
        </div>
        <div class="modal-footer-custom d-flex justify-content-end gap-2">
            <button class="btn-pfms btn-outline-pfms" onclick="closeDashboardModal()">Cancel</button>
            <button class="btn-pfms btn-primary-pfms" onclick="saveDashboard()">Save</button>
        </div>
    </div>
</div>

<!-- PANEL MODAL (Add/Edit Widget) -->
<div class="modal-overlay" id="panel-modal">
    <div class="modal-box" style="width: 800px; max-width: 95vw;">
        <div class="modal-header-custom d-flex justify-content-between align-items-center">
            <h5 class="m-0 font-weight-bold text-dark" id="panel-modal-title">Configure Widget Panel</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closePanelModal()">close</span>
        </div>
        <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
            <div class="row g-3">
                <!-- Left Configuration Column -->
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label-custom">Panel Widget Title</label>
                        <input type="text" id="panel-title-input" class="form-control" placeholder="Metrics Trend Chart">
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Select Agents</label>
                        <input type="text" id="panel-agent-search" class="form-control form-control-sm mb-2" placeholder="Search agents..." oninput="filterPanelAgentList()">
                        <div class="checkbox-list-container" id="panel-agent-checkbox-list" style="max-height: 140px;">
                            <!-- Populated dynamically -->
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Select Modules</label>
                        <input type="text" id="panel-module-search" class="form-control form-control-sm mb-2" placeholder="Search modules..." oninput="filterPanelModuleList()" disabled>
                        <div class="checkbox-list-container" id="panel-module-checkbox-list" style="max-height: 140px;">
                            <div class="text-muted p-2" style="font-size: 12px;">Select an agent first...</div>
                        </div>
                    </div>
                </div>

                <!-- Right Configuration Column -->
                <div class="col-md-6">
                    <div class="mb-3">
                        <label class="form-label-custom">Chart Type</label>
                        <select id="panel-chart-type" class="form-select">
                            <option value="line">Line Chart</option>
                            <option value="area">Area Chart</option>
                            <option value="bar">Bar Chart</option>
                            <option value="pie">Pie Chart</option>
                            <option value="donut">Donut Chart</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Time Range</label>
                        <select id="panel-time-range" class="form-select" onchange="togglePanelCustomRange()">
                            <option value="3600">Last 1 Hour</option>
                            <option value="86400" selected>Last 24 Hours</option>
                            <option value="604800">Last 7 Days</option>
                            <option value="2592000">Last 30 Days</option>
                            <option value="custom">Custom Range...</option>
                        </select>
                    </div>

                    <!-- Custom Datetime fields -->
                    <div id="panel-custom-range-container" class="mb-3 d-none">
                        <div class="row g-2">
                            <div class="col-6">
                                <label style="font-size: 11px; color: #64748b; margin-bottom: 2px; display:block;">Start Time</label>
                                <input type="datetime-local" id="panel-custom-start" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label style="font-size: 11px; color: #64748b; margin-bottom: 2px; display:block;">End Time</label>
                                <input type="datetime-local" id="panel-custom-end" class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Background Theme</label>
                        <select id="panel-bg-theme" class="form-select">
                            <option value="grafana">Grafana Dark (#0d1117)</option>
                            <option value="midnight">Midnight (#1a1b26)</option>
                            <option value="slate">Slate (#1f1f1f)</option>
                            <option value="light">Light (#ffffff)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Color Palette</label>
                        <select id="panel-color-palette" class="form-select">
                            <option value="grafana">Grafana Classic</option>
                            <option value="cool">Cool Gradient</option>
                            <option value="warm">Warm Palette</option>
                            <option value="neon">Neon Brights</option>
                            <option value="monochrome">Sleek Slate</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Panel Size (Width)</label>
                        <select id="panel-width" class="form-select">
                            <option value="6" selected>Half Width (50%)</option>
                            <option value="12">Full Width (100%)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Data Interval / Aggregation</label>
                        <select id="panel-interval-mode" class="form-select">
                            <option value="actual" selected>Actual (Raw Data)</option>
                            <option value="avg">Average (Daily)</option>
                            <option value="min">Minimum (Daily)</option>
                            <option value="max">Maximum (Daily)</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer-custom d-flex justify-content-end gap-2">
            <button class="btn-pfms btn-outline-pfms" onclick="closePanelModal()">Cancel</button>
            <button class="btn-pfms btn-primary-pfms" onclick="savePanel()">Save Widget</button>
        </div>
    </div>
</div>

<script>
const themeStyles = {
  grafana: {
    bg: '#0d1117',
    text: '#c9d1d9',
    axis: '#8b949e',
    split: '#21262d'
  },
  midnight: {
    bg: '#1a1b26',
    text: '#a9b1d6',
    axis: '#565f89',
    split: '#24283b'
  },
  slate: {
    bg: '#1f1f1f',
    text: '#e0e0e0',
    axis: '#888888',
    split: '#2d2d2d'
  },
  light: {
    bg: '#ffffff',
    text: '#333333',
    axis: '#666666',
    split: '#eeeeee'
  }
};

const colorPalettes = {
  grafana: ['#7eb26d', '#eab839', '#6ed0e0', '#ef843c', '#e24d42', '#1f78c1', '#ba43a9', '#705da0', '#508642', '#cca300'],
  cool: ['#00f2fe', '#4facfe', '#00c6ff', '#0072ff', '#3a7bd5', '#3a6073', '#00dbde', '#fc00ff', '#00f2fe', '#2575fc'],
  warm: ['#f857a6', '#ff5858', '#ffb347', '#ffcc33', '#f12711', '#f5af19', '#e65c00', '#F9D423', '#ff4e50', '#f9d423'],
  neon: ['#00ffcc', '#ff007f', '#39ff14', '#bc13fe', '#00ffff', '#ffff00', '#ff00ff', '#0000ff', '#ff4500', '#8a2be2'],
  monochrome: ['#cbd5e1', '#94a3b8', '#64748b', '#475569', '#334155', '#1e293b', '#0f172a', '#e2e8f0', '#f1f5f9', '#f8fafc']
};

const csrfToken = "<?= htmlspecialchars($csrf_token) ?>";
let dashboards = [];
let activeDashboardId = null;
let activePanelId = null;
let agentsList = [];
let panelEchartsInstances = {};
let panelLoadedData = {}; // Cache panel chart JSON data

// 1. DOM initialization
document.addEventListener('DOMContentLoaded', () => {
    loadAgentsList().then(() => {
        loadDashboards();
    });

    // Set initial custom timepickers to default
    const now = new Date();
    const last24h = new Date(Date.now() - 24 * 60 * 60 * 1000);
    document.getElementById('panel-custom-start').value = toLocalISOString(last24h);
    document.getElementById('panel-custom-end').value = toLocalISOString(now);
});

function toLocalISOString(date) {
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

// 2. Load lists
function loadAgentsList() {
    return fetch('?api=agents_list')
        .then(res => res.json())
        .then(data => {
            agentsList = data;
        })
        .catch(err => console.error('Error fetching agents:', err));
}

function loadDashboards() {
    fetch('?api=dashboards_list')
        .then(res => res.json())
        .then(data => {
            dashboards = Array.isArray(data) ? data : [];
            renderDashboardsList();
            
            // Populate header actions based on state
            if (activeDashboardId) {
                const currentDash = dashboards.find(d => d.id === activeDashboardId);
                if (currentDash) {
                    document.getElementById('page-nav-header').textContent = currentDash.name;
                    document.getElementById('header-back-btn').classList.remove('d-none');
                }
            } else {
                document.getElementById('page-nav-header').textContent = 'Visual Chart Reports';
                document.getElementById('header-back-btn').classList.add('d-none');
                document.getElementById('header-actions').innerHTML = `
                    <button class="btn-pfms btn-primary-pfms" onclick="openDashboardModal(false)">
                        <span class="material-symbols-outlined">add</span> Create Report Visual
                    </button>
                `;
            }
        })
        .catch(err => {
            console.error('Error loading reports:', err);
        });
}

// 3. Render Dashboards List
function renderDashboardsList() {
    const container = document.getElementById('dashboards-grid-container');
    container.innerHTML = '';

    if (dashboards.length === 0) {
        container.innerHTML = `
            <div class="col-12 text-center py-5">
                <span class="material-symbols-outlined" style="font-size:56px; color:#cbd5e1; display:block; margin-bottom:15px;">analytics</span>
                <h5 class="text-secondary font-weight-bold">No reports created yet</h5>
                <p class="text-muted">Click "Create Report Visual" in the header to start designing reports.</p>
            </div>
        `;
        return;
    }

    dashboards.forEach(dash => {
        const panelsCount = dash.panels ? dash.panels.length : 0;
        const dateStr = dash.created_at ? new Date(dash.created_at * 1000).toLocaleDateString() : '-';

        const col = document.createElement('div');
        col.className = 'col-lg-4 col-md-6 col-sm-12';
        col.innerHTML = `
            <div class="dashboard-card" onclick="showDashboardDetail('${dash.id}')">
                <div>
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <span class="material-symbols-outlined text-success" style="font-size:32px; background: #e0f2f1; padding: 8px; border-radius: 8px;">insights</span>
                        <span class="badge bg-success" style="font-size:10px;">${panelsCount} Widget(s)</span>
                    </div>
                    <h5 class="font-weight-bold text-dark mb-2" style="font-size:15px;">${escapeHtml(dash.name)}</h5>
                    <p class="text-muted small m-0">Custom visual reporting setup.</p>
                </div>
                <div class="mt-4 pt-3 border-top d-flex justify-content-between align-items-center">
                    <span class="text-muted small" style="font-size:11px;">Created: ${dateStr}</span>
                    <span class="material-symbols-outlined text-primary" style="font-size: 18px;">arrow_forward</span>
                </div>
            </div>
        `;
        container.appendChild(col);
    });
}

function showDashboardList() {
    document.getElementById('dashboard-list-view').classList.remove('d-none');
    document.getElementById('dashboard-detail-view').classList.add('d-none');
    
    // Set Header for List View
    document.getElementById('page-nav-header').textContent = 'Visual Chart Reports';
    document.getElementById('header-back-btn').classList.add('d-none');
    
    document.getElementById('header-actions').innerHTML = `
        <button class="btn-pfms btn-primary-pfms" onclick="openDashboardModal(false)">
            <span class="material-symbols-outlined">add</span> Create Report Visual
        </button>
    `;

    activeDashboardId = null;
    loadDashboards();
}

// 4. Manage Dashboard Modals
function openDashboardModal(isEdit = false) {
    const modal = document.getElementById('dashboard-modal');
    const title = document.getElementById('dashboard-modal-title');
    const input = document.getElementById('dashboard-name-input');

    if (isEdit) {
        const currentDash = dashboards.find(d => d.id === activeDashboardId);
        title.textContent = 'Rename Report Visual';
        input.value = currentDash ? currentDash.name : '';
    } else {
        title.textContent = 'Create Report Visual';
        input.value = '';
    }

    modal.style.display = 'flex';
}

function closeDashboardModal() {
    document.getElementById('dashboard-modal').style.display = 'none';
}

function saveDashboard() {
    const input = document.getElementById('dashboard-name-input').value.trim();
    if (!input) return alert('Please input report visual name');

    const modalTitle = document.getElementById('dashboard-modal-title').textContent;

    if (modalTitle === 'Rename Report Visual') {
        const currentDash = dashboards.find(d => d.id === activeDashboardId);
        if (currentDash) {
            currentDash.name = input;
        }
    } else {
        const newDash = {
            id: 'dash_' + Date.now(),
            name: input,
            created_at: Math.floor(Date.now() / 1000),
            panels: []
        };
        dashboards.push(newDash);
        activeDashboardId = newDash.id;
    }

    saveDashboardsToServer().then(() => {
        closeDashboardModal();
        showDashboardDetail(activeDashboardId);
    });
}

function deleteActiveDashboard() {
    if (!confirm('Are you sure you want to delete this report visual? This cannot be undone.')) return;
    
    dashboards = dashboards.filter(d => d.id !== activeDashboardId);
    saveDashboardsToServer().then(() => {
        showDashboardList();
    });
}

function saveDashboardsToServer() {
    return fetch('?api=save_dashboards', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({ dashboards })
    })
    .then(res => res.json())
    .then(res => {
        if (!res.ok) {
            alert('Failed to save config: ' + res.error);
        }
    })
    .catch(err => {
        console.error('Error saving config:', err);
    });
}

// 5. Dashboard details & Panels
function showDashboardDetail(dashId) {
    activeDashboardId = dashId;
    const currentDash = dashboards.find(d => d.id === dashId);
    if (!currentDash) return;

    document.getElementById('dashboard-list-view').classList.add('d-none');
    document.getElementById('dashboard-detail-view').classList.remove('d-none');
    
    // Set Header for Detail View
    document.getElementById('page-nav-header').textContent = currentDash.name;
    document.getElementById('header-back-btn').classList.remove('d-none');
    
    document.getElementById('header-actions').innerHTML = `
        <div class="d-flex gap-2">
            <button class="btn-pfms btn-primary-pfms" onclick="openPanelModal(false)">
                <span class="material-symbols-outlined">add_chart</span> Add Panel
            </button>
            <button class="btn-pfms btn-outline-pfms" onclick="openDashboardModal(true)">
                <span class="material-symbols-outlined">edit</span> Rename Report
            </button>
            <button class="btn-pfms btn-outline-pfms" onclick="window.print()">
                <span class="material-symbols-outlined">print</span> Print / PDF
            </button>
            <button class="btn-pfms btn-outline-pfms text-danger border-danger" onclick="deleteActiveDashboard()">
                <span class="material-symbols-outlined">delete</span> Delete Report
            </button>
        </div>
    `;

    document.getElementById('detail-dashboard-title').textContent = currentDash.name;
    document.getElementById('detail-dashboard-subtitle').textContent = 'Created: ' + new Date(currentDash.created_at * 1000).toLocaleDateString();

    renderDashboardPanels();
}

function renderDashboardPanels() {
    const currentDash = dashboards.find(d => d.id === activeDashboardId);
    const container = document.getElementById('panels-grid-container');
    
    // Dispose previous echarts instances
    Object.keys(panelEchartsInstances).forEach(k => {
        try { panelEchartsInstances[k].dispose(); } catch(e){}
    });
    panelEchartsInstances = {};
    panelLoadedData = {};

    container.innerHTML = '';

    if (!currentDash.panels || currentDash.panels.length === 0) {
        container.innerHTML = `
            <div class="col-12 text-center py-5">
                <span class="material-symbols-outlined" style="font-size:48px; color:#cbd5e1; display:block; margin-bottom:12px;">insights</span>
                <h5 class="text-secondary font-weight-bold">No widgets on this report visual</h5>
                <p class="text-muted">Click "Add Panel" to add your first reporting chart widget.</p>
            </div>
        `;
        return;
    }

    currentDash.panels.forEach(panel => {
        const col = document.createElement('div');
        col.className = `col-lg-${panel.width || 6} col-md-12 mb-4`;
        
        const card = document.createElement('div');
        card.className = 'card-custom h-100';
        
        const header = document.createElement('div');
        header.className = 'card-header-custom no-print';
        header.innerHTML = `
            <span>${escapeHtml(panel.title || 'Metrics Trend Chart')}</span>
            <div class="d-flex align-items-center gap-1">
                <button class="btn-action-panel" title="Export PNG" onclick="triggerPanelExport('${panel.id}')">
                    <span class="material-symbols-outlined">download</span>
                </button>
                <button class="btn-action-panel" title="Edit Panel" onclick="openPanelModal('${panel.id}')">
                    <span class="material-symbols-outlined">edit</span>
                </button>
                <button class="btn-action-panel text-danger" title="Delete Panel" onclick="deletePanel('${panel.id}')">
                    <span class="material-symbols-outlined">delete</span>
                </button>
            </div>
        `;
        
        // Print-friendly header
        const printHeader = document.createElement('div');
        printHeader.className = 'card-header-custom d-none d-print-block';
        printHeader.innerHTML = `<span>${escapeHtml(panel.title || 'Metrics Trend Chart')}</span>`;
        
        const body = document.createElement('div');
        body.className = 'card-body-custom';
        
        const previewBox = document.createElement('div');
        previewBox.className = 'preview-box';
        previewBox.id = `preview-box-${panel.id}`;
        
        const canvasContainer = document.createElement('div');
        canvasContainer.className = 'chart-canvas-container';
        canvasContainer.id = `canvas-${panel.id}`;
        previewBox.appendChild(canvasContainer);
        
        body.appendChild(previewBox);

        // Stats summary toggle & table
        const statsToggleBtn = document.createElement('div');
        statsToggleBtn.className = 'mt-3 no-print';
        statsToggleBtn.innerHTML = `
            <a href="javascript:void(0)" class="text-decoration-none text-success small font-weight-bold d-flex align-items-center gap-1" onclick="toggleStatsTable('${panel.id}')">
                <span class="material-symbols-outlined" id="stats-toggle-icon-${panel.id}">keyboard_arrow_down</span> View Widget Summary
            </a>
        `;
        body.appendChild(statsToggleBtn);

        const statsContainer = document.createElement('div');
        statsContainer.className = 'd-none mt-2';
        statsContainer.id = `stats-container-${panel.id}`;
        statsContainer.innerHTML = `
            <div class="table-responsive">
                <table class="stats-table">
                    <thead>
                        <tr>
                            <th>Agent / Module</th>
                            <th>Min</th>
                            <th>Max</th>
                            <th>Avg</th>
                            <th>Last</th>
                        </tr>
                    </thead>
                    <tbody id="stats-tbody-${panel.id}">
                        <!-- Dynamically filled -->
                    </tbody>
                </table>
            </div>
        `;
        body.appendChild(statsContainer);

        card.appendChild(header);
        card.appendChild(printHeader);
        card.appendChild(body);
        col.appendChild(card);
        container.appendChild(col);

        // Render ECharts Chart asynchronously
        initPanelChart(panel);
    });
}

function toggleStatsTable(panelId) {
    const container = document.getElementById(`stats-container-${panelId}`);
    const icon = document.getElementById(`stats-toggle-icon-${panelId}`);
    if (container.classList.contains('d-none')) {
        container.classList.remove('d-none');
        icon.textContent = 'keyboard_arrow_up';
    } else {
        container.classList.add('d-none');
        icon.textContent = 'keyboard_arrow_down';
    }
}

// 6. Initialize & load individual Panel chart data
function initPanelChart(panel) {
    const canvas = document.getElementById(`canvas-${panel.id}`);
    const previewBox = document.getElementById(`preview-box-${panel.id}`);
    
    const theme = themeStyles[panel.bg_theme] || themeStyles.grafana;
    previewBox.style.backgroundColor = theme.bg;
    previewBox.style.borderColor = theme.split;

    const chartInstance = echarts.init(canvas);
    panelEchartsInstances[panel.id] = chartInstance;

    // Loading indicator
    chartInstance.showLoading({
        text: 'Fetching data...',
        color: '#004d40',
        textColor: theme.text,
        maskColor: theme.bg,
        zlevel: 0
    });

    const moduleIds = panel.modules.join(',');
    const range = panel.time_range;
    
    let url = `?api=chart_data&module_ids=${encodeURIComponent(moduleIds)}&time_range=${range}`;
    if (range === 'custom') {
        const startTs = Math.floor(new Date(panel.custom_start).getTime() / 1000);
        const endTs = Math.floor(new Date(panel.custom_end).getTime() / 1000);
        url += `&start_time=${startTs}&end_time=${endTs}`;
    }

    fetch(url)
        .then(res => res.json())
        .then(res => {
            chartInstance.hideLoading();
            if (res.ok) {
                panelLoadedData[panel.id] = res;
                const option = getEchartsOption(panel, res);
                chartInstance.setOption(option);
                populatePanelStatsTable(panel.id, res);
            } else {
                canvas.innerHTML = `<div class="no-data-placeholder text-danger text-center p-5">${escapeHtml(res.error)}</div>`;
            }
        })
        .catch(err => {
            console.error('Error fetching panel data:', err);
            chartInstance.hideLoading();
            canvas.innerHTML = `<div class="no-data-placeholder text-danger text-center p-5">Connection error loading metrics.</div>`;
        });
}

function populatePanelStatsTable(panelId, res) {
    const tbody = document.getElementById(`stats-tbody-${panelId}`);
    tbody.innerHTML = '';
    
    const history = res.history || [];
    const metaList = res.meta || [];
    
    metaList.forEach(m => {
        const modHist = history.filter(h => String(h.id_mod) === String(m.id_agente_modulo));
        
        let min = 'N/A';
        let max = 'N/A';
        let avg = 'N/A';
        let last = 'N/A';
        
        if (modHist.length > 0) {
            const vals = modHist.map(h => h.val);
            min = Math.min(...vals).toFixed(2);
            max = Math.max(...vals).toFixed(2);
            const sum = vals.reduce((a, b) => a + b, 0);
            avg = (sum / vals.length).toFixed(2);
            last = vals[vals.length - 1].toFixed(2);
        }
        
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><b>${escapeHtml(m.agent_alias)}</b><br><span class="text-muted" style="font-size: 10px;">${escapeHtml(m.module_name)}</span></td>
            <td>${min} ${escapeHtml(m.unit)}</td>
            <td>${max} ${escapeHtml(m.unit)}</td>
            <td>${avg} ${escapeHtml(m.unit)}</td>
            <td><span class="badge bg-light text-dark border">${last} ${escapeHtml(m.unit)}</span></td>
        `;
        tbody.appendChild(tr);
    });
}

// 7. Modals: Configure Panels (Add/Edit)
function openPanelModal(panelId = false) {
    activePanelId = panelId;
    const modal = document.getElementById('panel-modal');
    const title = document.getElementById('panel-modal-title');
    
    // Initial loading of agents
    populatePanelAgents();

    const moduleSearch = document.getElementById('panel-module-search');
    const moduleContainer = document.getElementById('panel-module-checkbox-list');
    moduleContainer.innerHTML = '<div class="text-muted p-2" style="font-size: 12px;">Select an agent first...</div>';
    moduleSearch.disabled = true;

    if (panelId) {
        title.textContent = 'Edit Widget Panel';
        const currentDash = dashboards.find(d => d.id === activeDashboardId);
        const panel = currentDash.panels.find(p => p.id === panelId);
        
        if (panel) {
            document.getElementById('panel-title-input').value = panel.title;
            document.getElementById('panel-chart-type').value = panel.chart_type;
            document.getElementById('panel-time-range').value = panel.time_range;
            document.getElementById('panel-bg-theme').value = panel.bg_theme;
            document.getElementById('panel-color-palette').value = panel.color_palette;
            document.getElementById('panel-width').value = panel.width || "6";
            document.getElementById('panel-interval-mode').value = panel.interval_mode || "actual";
            
            if (panel.time_range === 'custom') {
                document.getElementById('panel-custom-start').value = panel.custom_start;
                document.getElementById('panel-custom-end').value = panel.custom_end;
                document.getElementById('panel-custom-range-container').classList.remove('d-none');
            } else {
                document.getElementById('panel-custom-range-container').classList.add('d-none');
            }

            // Check agents
            panel.agents.forEach(agentId => {
                const cb = document.getElementById(`panel-chk-agent-${agentId.replace(/:/g, '-')}`);
                if (cb) cb.checked = true;
            });
            
            // Load and check modules
            onAgentSelectionChange().then(() => {
                panel.modules.forEach(modId => {
                    const cb = document.getElementById(`panel-chk-${modId.replace(/:/g, '-')}`);
                    if (cb) cb.checked = true;
                });
            });
        }
    } else {
        title.textContent = 'Add Widget Panel';
        document.getElementById('panel-title-input').value = '';
        document.getElementById('panel-chart-type').value = 'line';
        document.getElementById('panel-time-range').value = '86400';
        document.getElementById('panel-bg-theme').value = 'grafana';
        document.getElementById('panel-color-palette').value = 'grafana';
        document.getElementById('panel-width').value = '6';
        document.getElementById('panel-interval-mode').value = 'actual';
        document.getElementById('panel-custom-range-container').classList.add('d-none');
        
        document.getElementById('panel-agent-search').value = '';
        document.getElementById('panel-module-search').value = '';
    }

    modal.style.display = 'flex';
}

function closePanelModal() {
    document.getElementById('panel-modal').style.display = 'none';
}

function populatePanelAgents() {
    const container = document.getElementById('panel-agent-checkbox-list');
    container.innerHTML = '';
    
    if (agentsList.length === 0) {
        container.innerHTML = '<div class="text-muted p-2" style="font-size:11px;">No active agents found.</div>';
        return;
    }

    container.innerHTML = agentsList.map(agent => `
        <div class="checkbox-item panel-agent-item" data-name="${agent.alias.toLowerCase()}">
            <input type="checkbox" class="panel-agent-chk" value="${agent.id}" id="panel-chk-agent-${agent.id.replace(/:/g, '-')}" onchange="onAgentSelectionChange()">
            <label for="panel-chk-agent-${agent.id.replace(/:/g, '-')}">${agent.alias}</label>
        </div>
    `).join('');
}

function filterPanelAgentList() {
    const q = document.getElementById('panel-agent-search').value.toLowerCase().trim();
    const items = document.querySelectorAll('#panel-agent-checkbox-list .panel-agent-item');
    items.forEach(item => {
        const name = item.getAttribute('data-name');
        if (name.includes(q)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

function filterPanelModuleList() {
    const q = document.getElementById('panel-module-search').value.toLowerCase().trim();
    const items = document.querySelectorAll('#panel-module-checkbox-list .panel-module-item');
    items.forEach(item => {
        const name = item.getAttribute('data-name');
        if (name.includes(q)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

function onAgentSelectionChange() {
    const checked = Array.from(document.querySelectorAll('#panel-agent-checkbox-list .panel-agent-chk:checked')).map(cb => cb.value);
    const moduleCheckboxList = document.getElementById('panel-module-checkbox-list');
    const moduleSearch = document.getElementById('panel-module-search');

    if (checked.length === 0) {
        moduleCheckboxList.innerHTML = '<div class="text-muted p-2" style="font-size: 12px;">Select an agent first...</div>';
        moduleSearch.disabled = true;
        moduleSearch.value = '';
        return Promise.resolve();
    }

    moduleCheckboxList.innerHTML = '<div class="text-muted p-2" style="font-size: 12px;"><span class="material-symbols-outlined spinner-icon">sync</span> Loading modules...</div>';
    moduleSearch.disabled = false;
    moduleSearch.value = '';

    return fetch(`?api=module_list&agent_ids=${encodeURIComponent(checked.join(','))}`)
        .then(res => res.json())
        .then(data => {
            if (data.length === 0) {
                moduleCheckboxList.innerHTML = '<div class="text-muted p-2" style="font-size: 12px;">No active modules found.</div>';
                return;
            }

            moduleCheckboxList.innerHTML = data.map(m => `
                <div class="checkbox-item panel-module-item" data-name="${m.name.toLowerCase()}">
                    <input type="checkbox" class="panel-module-chk" value="${m.id}" id="panel-chk-${m.id.replace(/:/g, '-')}">
                    <label for="panel-chk-${m.id.replace(/:/g, '-')}">${m.name}</label>
                </div>
            `).join('');
        })
        .catch(err => {
            console.error('Error fetching modules:', err);
            moduleCheckboxList.innerHTML = '<div class="text-danger p-2" style="font-size: 12px;">Failed to load modules.</div>';
        });
}

function togglePanelCustomRange() {
    const range = document.getElementById('panel-time-range').value;
    const container = document.getElementById('panel-custom-range-container');
    if (range === 'custom') {
        container.classList.remove('d-none');
    } else {
        container.classList.add('d-none');
    }
}

function savePanel() {
    const title = document.getElementById('panel-title-input').value.trim() || 'Metrics Trend Chart';
    const chartType = document.getElementById('panel-chart-type').value;
    const timeRange = document.getElementById('panel-time-range').value;
    const bgTheme = document.getElementById('panel-bg-theme').value;
    const colorPalette = document.getElementById('panel-color-palette').value;
    const width = parseInt(document.getElementById('panel-width').value);
    const intervalMode = document.getElementById('panel-interval-mode').value;
    
    const agents = Array.from(document.querySelectorAll('#panel-agent-checkbox-list .panel-agent-chk:checked')).map(cb => cb.value);
    const modules = Array.from(document.querySelectorAll('#panel-module-checkbox-list .panel-module-chk:checked')).map(cb => cb.value);

    if (agents.length === 0) return alert('Please select at least one agent.');
    if (modules.length === 0) return alert('Please select at least one module.');

    let customStart = '';
    let customEnd = '';
    if (timeRange === 'custom') {
        customStart = document.getElementById('panel-custom-start').value;
        customEnd = document.getElementById('panel-custom-end').value;
        if (!customStart || !customEnd) return alert('Please select custom datetimes.');
    }

    const currentDash = dashboards.find(d => d.id === activeDashboardId);
    if (!currentDash) return;

    if (activePanelId) {
        // Edit panel
        const idx = currentDash.panels.findIndex(p => p.id === activePanelId);
        if (idx !== -1) {
            currentDash.panels[idx] = {
                id: activePanelId,
                title, agents, modules, chart_type: chartType, time_range: timeRange,
                custom_start: customStart, custom_end: customEnd,
                bg_theme: bgTheme, color_palette: colorPalette, width,
                interval_mode: intervalMode
            };
        }
    } else {
        // Add new panel
        const newPanel = {
            id: 'panel_' + Date.now(),
            title, agents, modules, chart_type: chartType, time_range: timeRange,
            custom_start: customStart, custom_end: customEnd,
            bg_theme: bgTheme, color_palette: colorPalette, width,
            interval_mode: intervalMode
        };
        if (!currentDash.panels) currentDash.panels = [];
        currentDash.panels.push(newPanel);
    }

    saveDashboardsToServer().then(() => {
        closePanelModal();
        showDashboardDetail(activeDashboardId);
    });
}

function deletePanel(panelId) {
    if (!confirm('Are you sure you want to delete this widget panel?')) return;

    const currentDash = dashboards.find(d => d.id === activeDashboardId);
    if (currentDash) {
        currentDash.panels = currentDash.panels.filter(p => p.id !== panelId);
        saveDashboardsToServer().then(() => {
            showDashboardDetail(activeDashboardId);
        });
    }
}

function triggerPanelExport(panelId) {
    const currentDash = dashboards.find(d => d.id === activeDashboardId);
    if (!currentDash) return;
    
    const panel = currentDash.panels.find(p => p.id === panelId);
    const data = panelLoadedData[panelId];
    
    if (panel && data) {
        exportPanelPNG(panel, data);
    } else {
        alert('Data is still loading. Please try again in a few seconds.');
    }
}

// 8. ECharts options generator helper
function getEchartsOption(panel, res) {
    const bgTheme = panel.bg_theme;
    const palette = panel.color_palette;
    const chartType = panel.chart_type;
    const customTitle = panel.title;

    const theme = themeStyles[bgTheme] || themeStyles.grafana;
    const colors = colorPalettes[palette] || colorPalettes.grafana;

    const history = res.history || [];
    const metaList = res.meta || [];

    let option = {};

    if (chartType === 'pie' || chartType === 'donut') {
        const pieData = metaList.map((m, idx) => {
            const modHist = history.filter(h => String(h.id_mod) === String(m.id_agente_modulo));
            let val = 0;
            if (modHist.length > 0) {
                const vals = modHist.map(h => h.val);
                if (panel.interval_mode === 'min') {
                    val = Math.min(...vals);
                } else if (panel.interval_mode === 'max') {
                    val = Math.max(...vals);
                } else {
                    const sum = vals.reduce((acc, v) => acc + v, 0);
                    val = sum / vals.length;
                }
                val = parseFloat(val.toFixed(2));
            }
            return {
                name: `${m.agent_alias} - ${m.module_name}`,
                value: val
            };
        });

        option = {
            backgroundColor: theme.bg,
            color: colors,
            title: {
                text: customTitle || 'Metrics Pie Distribution',
                textStyle: { color: theme.text, fontSize: 13 },
                left: 'center',
                top: 15
            },
            tooltip: {
                trigger: 'item',
                formatter: '{b}: <b>{c}</b> ({d}%)',
                appendToBody: true
            },
            legend: {
                orient: 'vertical',
                right: 10,
                top: 'center',
                textStyle: { color: theme.text, fontSize: 9 },
                type: 'scroll'
            },
            series: [{
                name: 'Average Value',
                type: 'pie',
                radius: chartType === 'pie' ? '65%' : ['35%', '65%'],
                center: ['40%', '55%'],
                data: pieData,
                label: { show: true, formatter: '{b}: {c}', fontSize: 8, color: theme.text },
                labelLine: { show: true, lineStyle: { color: theme.split } }
            }]
        };
    } else {
        const dataPrepared = prepareEchartsData(panel, history, metaList, colors, chartType, theme, false);
        const shortLabels = dataPrepared.shortLabels;
        const seriesData = dataPrepared.seriesData;
        const labels = dataPrepared.labels;

        option = {
            backgroundColor: theme.bg,
            color: colors,
            title: {
                text: customTitle || 'Metrics Trend Chart',
                subtext: labels.length > 0 ? `Time Range: ${labels[0]} to ${labels[labels.length - 1]}` : '',
                textStyle: { color: theme.text, fontSize: 13 },
                subtextStyle: { color: theme.axis, fontSize: 8 },
                left: 'center',
                top: 15
            },
            tooltip: {
                trigger: 'axis',
                appendToBody: true,
                backgroundColor: 'rgba(15, 23, 42, 0.95)',
                textStyle: { color: '#cbd5e1', fontSize: 11 },
                padding: 8,
                borderRadius: 4
            },
            legend: {
                type: 'scroll',
                bottom: 5,
                icon: 'circle',
                textStyle: { color: theme.text, fontSize: 8 }
            },
            grid: { left: '4%', right: '4%', top: 60, bottom: 50, containLabel: true },
            xAxis: {
                type: 'category',
                boundaryGap: chartType === 'bar',
                data: shortLabels,
                axisLabel: { fontSize: 7, color: theme.axis, rotate: 15 },
                axisLine: { lineStyle: { color: theme.split } },
                axisTick: { show: false }
            },
            yAxis: {
                type: 'value',
                splitLine: { lineStyle: { color: theme.split } },
                axisLabel: { fontSize: 7, color: theme.axis }
            },
            series: seriesData
        };
    }

    return option;
}

function prepareEchartsData(panel, history, metaList, colors, chartType, theme, isHighRes = false) {
    const intervalMode = panel.interval_mode || 'actual';
    let processedHistory = history;
    let uniqueTimes = [];
    let labels = [];
    let shortLabels = [];

    if (intervalMode !== 'actual') {
        const grouped = {};
        const daysSet = new Set();

        history.forEach(h => {
            const day = getDayString(h.time);
            if (!day) return;
            daysSet.add(day);
            
            const key = `${day}:${h.id_mod}`;
            if (!grouped[key]) {
                grouped[key] = [];
            }
            grouped[key].push(h.val);
        });

        const sortedDays = [...daysSet].sort();
        processedHistory = [];

        sortedDays.forEach(day => {
            metaList.forEach(m => {
                const key = `${day}:${m.id_agente_modulo}`;
                const vals = grouped[key] || [];
                if (vals.length > 0) {
                    let finalVal = 0;
                    if (intervalMode === 'avg') {
                        finalVal = vals.reduce((sum, v) => sum + v, 0) / vals.length;
                    } else if (intervalMode === 'min') {
                        finalVal = Math.min(...vals);
                    } else if (intervalMode === 'max') {
                        finalVal = Math.max(...vals);
                    }
                    processedHistory.push({
                        id_mod: m.id_agente_modulo,
                        time: day,
                        val: parseFloat(finalVal.toFixed(2))
                    });
                }
            });
        });

        uniqueTimes = sortedDays;
        labels = sortedDays;
        shortLabels = sortedDays;
    } else {
        uniqueTimes = [...new Set(history.map(h => h.utimestamp))].sort((a, b) => a - b);
        labels = uniqueTimes.map(ts => {
            const found = history.find(h => h.utimestamp === ts);
            return found ? found.time : '';
        });
        shortLabels = labels.map(ts => {
            const parts = ts.split(' ');
            if (parts.length === 2) {
                const dateParts = parts[0].split('/');
                const timeParts = parts[1].split(':');
                if (dateParts.length === 3 && timeParts.length === 3) {
                    return `${dateParts[0]}/${dateParts[1]} ${timeParts[0]}:${timeParts[1]}`;
                }
            }
            return ts;
        });
    }

    const seriesData = metaList.map((m, idx) => {
        const color = colors[idx % colors.length];
        const modHist = processedHistory.filter(h => String(h.id_mod) === String(m.id_agente_modulo));
        
        let lastVal = null;
        const dataPoints = uniqueTimes.map(t => {
            let h;
            if (intervalMode !== 'actual') {
                h = modHist.find(x => x.time === t);
            } else {
                h = modHist.find(x => x.utimestamp === t);
            }
            if (h) lastVal = h.val;
            return lastVal;
        });

        return {
            name: `${m.agent_alias} - ${m.module_name}`,
            type: chartType === 'bar' ? 'bar' : 'line',
            data: dataPoints,
            itemStyle: { color: color },
            areaStyle: chartType === 'area' ? { opacity: 0.15, color: color } : undefined,
            smooth: true,
            showSymbol: intervalMode !== 'actual',
            connectNulls: true,
            lineStyle: { width: chartType === 'bar' ? 0 : (isHighRes ? 3 : 2) }
        };
    });

    return { shortLabels, seriesData, labels };
}

function getDayString(timeStr) {
    if (!timeStr) return '';
    if (timeStr.includes('-') && timeStr.split('-').length === 3 && !timeStr.includes(':')) {
        return timeStr;
    }
    const parts = timeStr.split(' ');
    if (parts.length > 0) {
        const dateParts = parts[0].split('/');
        if (dateParts.length === 3) {
            return `${dateParts[2]}-${dateParts[1]}-${dateParts[0]}`;
        }
    }
    return '';
}

function exportPanelPNG(panel, data) {
    if (!data) return;

    const bgTheme = panel.bg_theme;
    const palette = panel.color_palette;
    const chartType = panel.chart_type;
    const customTitle = panel.title;

    const theme = themeStyles[bgTheme] || themeStyles.grafana;
    const colors = colorPalettes[palette] || colorPalettes.grafana;

    const history = data.history || [];
    const metaList = data.meta || [];

    const tempDiv = document.createElement('div');
    tempDiv.style.width = '1200px';
    tempDiv.style.height = '700px';
    tempDiv.style.position = 'absolute';
    tempDiv.style.left = '-9999px';
    tempDiv.style.top = '-9999px';
    document.body.appendChild(tempDiv);

    const highResChart = echarts.init(tempDiv);
    let option = {};

    if (chartType === 'pie' || chartType === 'donut') {
        const pieData = metaList.map((m, idx) => {
            const modHist = history.filter(h => String(h.id_mod) === String(m.id_agente_modulo));
            let val = 0;
            if (modHist.length > 0) {
                const vals = modHist.map(h => h.val);
                if (panel.interval_mode === 'min') {
                    val = Math.min(...vals);
                } else if (panel.interval_mode === 'max') {
                    val = Math.max(...vals);
                } else {
                    const sum = vals.reduce((acc, v) => acc + v, 0);
                    val = sum / vals.length;
                }
                val = parseFloat(val.toFixed(2));
            }
            return {
                name: `${m.agent_alias} - ${m.module_name}`,
                value: val
            };
        });

        option = {
            backgroundColor: theme.bg,
            color: colors,
            title: {
                text: customTitle || 'Metrics Pie Distribution',
                textStyle: { color: theme.text, fontSize: 20 },
                left: 'center',
                top: 25
            },
            tooltip: { trigger: 'item' },
            legend: {
                orient: 'vertical',
                right: 30,
                top: 'center',
                textStyle: { color: theme.text, fontSize: 13 }
            },
            series: [{
                name: 'Average Value',
                type: 'pie',
                radius: chartType === 'pie' ? '70%' : ['40%', '70%'],
                center: ['45%', '55%'],
                data: pieData,
                label: { show: true, formatter: '{b}: {c}', fontSize: 11, color: theme.text },
                labelLine: { show: true, lineStyle: { color: theme.split } }
            }]
        };
    } else {
        const dataPrepared = prepareEchartsData(panel, history, metaList, colors, chartType, theme, true);
        const shortLabels = dataPrepared.shortLabels;
        const seriesData = dataPrepared.seriesData;
        const labels = dataPrepared.labels;

        option = {
            backgroundColor: theme.bg,
            color: colors,
            title: {
                text: customTitle || 'Metrics Trend Chart',
                subtext: labels.length > 0 ? `Time Range: ${labels[0]} to ${labels[labels.length - 1]}` : '',
                textStyle: { color: theme.text, fontSize: 20 },
                subtextStyle: { color: theme.axis, fontSize: 12 },
                left: 'center',
                top: 25
            },
            legend: {
                type: 'scroll',
                bottom: 20,
                icon: 'circle',
                textStyle: { color: theme.text, fontSize: 11 }
            },
            grid: { left: '6%', right: '6%', top: 90, bottom: 85, containLabel: true },
            xAxis: {
                type: 'category',
                boundaryGap: chartType === 'bar',
                data: shortLabels,
                axisLabel: { fontSize: 10, color: theme.axis, rotate: 15 },
                axisLine: { lineStyle: { color: theme.split } },
                axisTick: { show: false }
            },
            yAxis: {
                type: 'value',
                splitLine: { lineStyle: { color: theme.split } },
                axisLabel: { fontSize: 10, color: theme.axis }
            },
            series: seriesData
        };
    }

    highResChart.setOption(option);

    setTimeout(() => {
        const imgUrl = highResChart.getDataURL({
            type: 'png',
            pixelRatio: 2,
            excludeComponents: ['toolbox']
        });

        const link = document.createElement('a');
        link.href = imgUrl;
        
        let filename = (customTitle || 'metrics_chart')
            .toLowerCase()
            .replace(/[^a-z0-9]/g, '_')
            .replace(/_+/g, '_');
        filename += `_${Date.now()}.png`;

        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        highResChart.dispose();
        document.body.removeChild(tempDiv);
    }, 300);
}

// 9. Helpers
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}
</script>

</body>
</html>
