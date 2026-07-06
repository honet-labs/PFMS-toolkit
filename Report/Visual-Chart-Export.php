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

$api = $_GET['api'] ?? '';

// AJAX ENDPOINTS
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
    <title>Visual Chart Export - Pandora FMS</title>
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
        .header-section {
            padding: 15px 30px;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .page-title {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
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
        .search-select-box {
            position: relative;
        }
        .search-select-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            outline: none;
            transition: border-color 0.2s;
            font-size: 13px;
        }
        .search-select-input:focus {
            border-color: #004d40;
        }
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
            background-color: #0d1117; /* Default Grafana Dark background */
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        #chart-preview-canvas {
            width: 100%;
            height: 420px;
        }
        .no-data-placeholder {
            color: #94a3b8;
            font-size: 14px;
            text-align: center;
            padding: 40px;
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
            font-size: 12px;
        }
        .stats-table th {
            background: #f8fafc;
            padding: 8px 12px;
            text-align: left;
            font-weight: 600;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
            text-transform: uppercase;
            font-size: 10px;
        }
        .stats-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        .stats-table tbody tr:hover {
            background-color: #f8fafc;
        }
    </style>
</head>
<body>

<div class="header-section">
    <div>
        <h1 class="page-title">Visual Chart Export</h1>
    </div>
</div>

<div class="main-container">
    <div class="row">
        <!-- Configuration Form Panel -->
        <div class="col-lg-4 col-md-12">
            <div class="card-custom">
                <div class="card-header-custom">
                    <span>Chart configuration</span>
                </div>
                <div class="card-body-custom">
                    <!-- Target Selection -->
                    <div class="mb-3">
                        <label class="form-label-custom">Select Agents</label>
                        <input type="text" id="agent-search" class="form-control form-control-sm mb-2" placeholder="Search agents..." oninput="filterAgentCheckboxList()">
                        <div class="checkbox-list-container" id="agent-checkbox-list" style="max-height: 180px;">
                            <div class="text-muted p-2" style="font-size: 12px;"><span class="material-symbols-outlined spinner-icon">sync</span> Loading agents...</div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label-custom">Select Modules</label>
                        <input type="text" id="module-search" class="form-control form-control-sm mb-2" placeholder="Search modules..." oninput="filterModuleCheckboxList()" disabled>
                        <div class="checkbox-list-container" id="module-checkbox-list">
                            <div class="text-muted p-2" style="font-size: 12px;">Select an agent first...</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Chart Title</label>
                        <input type="text" id="chart-title-input" class="form-control" value="Metrics Trend Chart">
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Chart Type</label>
                        <select id="chart-type-select" class="form-select" onchange="renderChartPreview()">
                            <option value="line">Line Chart</option>
                            <option value="area">Area Chart</option>
                            <option value="bar">Bar Chart</option>
                            <option value="pie">Pie Chart</option>
                            <option value="donut">Donut Chart</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Time Range</label>
                        <select id="time-range-select" class="form-select" onchange="handleTimeRangeChange()">
                            <option value="3600">Last 1 Hour</option>
                            <option value="86400" selected>Last 24 Hours</option>
                            <option value="604800">Last 7 Days</option>
                            <option value="2592000">Last 30 Days</option>
                            <option value="custom">Custom Range...</option>
                        </select>
                    </div>

                    <!-- Custom Range Datetime Fields -->
                    <div id="custom-range-container" class="mb-3 d-none">
                        <div class="row g-2">
                            <div class="col-6">
                                <label style="font-size: 11px; color: #64748b; margin-bottom: 2px; display:block;">Start Time</label>
                                <input type="datetime-local" id="custom-start" class="form-control form-control-sm">
                            </div>
                            <div class="col-6">
                                <label style="font-size: 11px; color: #64748b; margin-bottom: 2px; display:block;">End Time</label>
                                <input type="datetime-local" id="custom-end" class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Background Theme</label>
                        <select id="bg-theme-select" class="form-select" onchange="updatePreviewTheme()">
                            <option value="grafana">Grafana Dark (#0d1117)</option>
                            <option value="midnight">Midnight (#1a1b26)</option>
                            <option value="slate">Slate (#1f1f1f)</option>
                            <option value="light">Light (#ffffff)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label-custom">Color Palette</label>
                        <select id="color-palette-select" class="form-select" onchange="renderChartPreview()">
                            <option value="grafana">Grafana Classic</option>
                            <option value="cool">Cool Gradient</option>
                            <option value="warm">Warm Palette</option>
                            <option value="neon">Neon Brights</option>
                            <option value="monochrome">Sleek Slate</option>
                        </select>
                    </div>
                    
                    <div class="d-flex gap-2 mt-4">
                        <button class="btn-pfms btn-primary-pfms flex-grow-1 justify-content-center" id="btn-load-data" onclick="loadChartData()">
                            <span class="material-symbols-outlined">sync</span> Generate Preview
                        </button>
                        <button class="btn-pfms btn-outline-pfms" id="btn-export-png" onclick="exportHighResChart()" disabled>
                            <span class="material-symbols-outlined">download</span> Export PNG
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Chart Preview & Stats Panel -->
        <div class="col-lg-8 col-md-12">
            <div class="card-custom" style="min-height: 520px;">
                <div class="card-header-custom">
                    <span>Live Preview</span>
                    <span id="data-status-label" class="badge bg-secondary" style="font-size: 11px; font-weight: normal;">No Data Loaded</span>
                </div>
                <div class="card-body-custom">
                    <div class="preview-box" id="preview-box-container">
                        <div id="chart-preview-canvas">
                            <div class="no-data-placeholder">
                                <span class="material-symbols-outlined" style="font-size: 48px; color: #cbd5e1; display: block; margin-bottom: 12px;">insights</span>
                                Select an Agent and Modules on the left, then click <b>Generate Preview</b>.
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stats summary container -->
                    <div id="stats-summary-container" class="d-none">
                        <h6 class="mt-4 mb-2" style="font-weight: 600; color: #334155;">Data Series Summary</h6>
                        <div class="table-responsive">
                            <table class="stats-table">
                                <thead>
                                    <tr>
                                        <th>Agent / Module</th>
                                        <th>Min Value</th>
                                        <th>Max Value</th>
                                        <th>Avg Value</th>
                                        <th>Current / Last Value</th>
                                    </tr>
                                </thead>
                                <tbody id="stats-table-body">
                                    <!-- Populated dynamically -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
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

let activeEchartsInstance = null;
let loadedModulesData = null; // Stores parsed chart data

// Set initial timepickers
const now = new Date();
const last24h = new Date(Date.now() - 24 * 60 * 60 * 1000);
document.getElementById('custom-start').value = toLocalISOString(last24h);
document.getElementById('custom-end').value = toLocalISOString(now);

function toLocalISOString(date) {
    const offset = date.getTimezoneOffset() * 60000;
    return new Date(date.getTime() - offset).toISOString().slice(0, 16);
}

// 1. Initial Load of Agents List
document.addEventListener('DOMContentLoaded', () => {
    fetch('?api=agents_list')
        .then(res => res.json())
        .then(data => {
            const container = document.getElementById('agent-checkbox-list');
            if (data.length === 0) {
                container.innerHTML = '<div class="text-muted p-2" style="font-size: 12px;">No active agents found.</div>';
                return;
            }

            container.innerHTML = data.map(agent => `
                <div class="checkbox-item agent-item" data-name="${agent.alias.toLowerCase()}">
                    <input type="checkbox" class="agent-chk" value="${agent.id}" id="chk-agent-${agent.id.replace(/:/g, '-')}" onchange="loadModulesForSelectedAgents()">
                    <label for="chk-agent-${agent.id.replace(/:/g, '-')}">${agent.alias}</label>
                </div>
            `).join('');
        })
        .catch(err => {
            console.error('Error loading agents:', err);
            const container = document.getElementById('agent-checkbox-list');
            container.innerHTML = '<div class="text-danger p-2" style="font-size: 12px;">Failed to load agents.</div>';
        });
});

function filterAgentCheckboxList() {
    const q = document.getElementById('agent-search').value.toLowerCase().trim();
    const items = document.querySelectorAll('#agent-checkbox-list .agent-item');
    items.forEach(item => {
        const name = item.getAttribute('data-name');
        if (name.includes(q)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

// 2. Load Modules list for selected Agents
function loadModulesForSelectedAgents() {
    const selectedAgentChks = Array.from(document.querySelectorAll('.agent-chk:checked'));
    const checkboxList = document.getElementById('module-checkbox-list');
    const searchInput = document.getElementById('module-search');

    if (selectedAgentChks.length === 0) {
        checkboxList.innerHTML = '<div class="text-muted p-2" style="font-size: 12px;">Select an agent first...</div>';
        searchInput.disabled = true;
        searchInput.value = '';
        return;
    }

    checkboxList.innerHTML = '<div class="text-muted p-2" style="font-size: 12px;"><span class="material-symbols-outlined spinner-icon">sync</span> Loading modules...</div>';
    searchInput.disabled = false;
    searchInput.value = '';

    const agentIds = selectedAgentChks.map(cb => cb.value).join(',');

    fetch(`?api=module_list&agent_ids=${encodeURIComponent(agentIds)}`)
        .then(res => res.json())
        .then(data => {
            if (data.length === 0) {
                checkboxList.innerHTML = '<div class="text-muted p-2" style="font-size: 12px;">No active modules found for selected agents.</div>';
                return;
            }

            checkboxList.innerHTML = data.map(m => `
                <div class="checkbox-item" data-name="${m.name.toLowerCase()}">
                    <input type="checkbox" class="module-chk" value="${m.id}" id="chk-${m.id.replace(/:/g, '-')}" onchange="validateCheckboxes()">
                    <label for="chk-${m.id.replace(/:/g, '-')}">${m.name}</label>
                </div>
            `).join('');
        })
        .catch(err => {
            console.error('Error loading modules:', err);
            checkboxList.innerHTML = '<div class="text-danger p-2" style="font-size: 12px;">Failed to load modules.</div>';
        });
}

function filterModuleCheckboxList() {
    const q = document.getElementById('module-search').value.toLowerCase().trim();
    const items = document.querySelectorAll('#module-checkbox-list .checkbox-item');
    items.forEach(item => {
        const name = item.getAttribute('data-name');
        if (name.includes(q)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

function validateCheckboxes() {
    // Just a placeholder for checkbox verification
}

function handleTimeRangeChange() {
    const range = document.getElementById('time-range-select').value;
    const container = document.getElementById('custom-range-container');
    if (range === 'custom') {
        container.classList.remove('d-none');
    } else {
        container.classList.add('d-none');
    }
}

function updatePreviewTheme() {
    const bgTheme = document.getElementById('bg-theme-select').value;
    const theme = themeStyles[bgTheme] || themeStyles.grafana;
    const container = document.getElementById('preview-box-container');
    
    container.style.backgroundColor = theme.bg;
    container.style.borderColor = theme.split;
    
    if (activeEchartsInstance && loadedModulesData) {
        renderChartPreview();
    }
}

// 3. Load Data & Render Chart
function loadChartData() {
    const selectedChks = Array.from(document.querySelectorAll('.module-chk:checked'));
    if (selectedChks.length === 0) {
        alert('Please select at least one module.');
        return;
    }
    
    const moduleIds = selectedChks.map(cb => cb.value).join(',');
    const range = document.getElementById('time-range-select').value;
    
    let url = `?api=chart_data&module_ids=${encodeURIComponent(moduleIds)}&time_range=${range}`;
    if (range === 'custom') {
        const startVal = document.getElementById('custom-start').value;
        const endVal = document.getElementById('custom-end').value;
        if (!startVal || !endVal) {
            alert('Please select custom start and end datetimes.');
            return;
        }
        const startTs = Math.floor(new Date(startVal).getTime() / 1000);
        const endTs = Math.floor(new Date(endVal).getTime() / 1000);
        url += `&start_time=${startTs}&end_time=${endTs}`;
    }
    
    // Set loading state
    const btn = document.getElementById('btn-load-data');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<span class="material-symbols-outlined spinner-icon">sync</span> Loading...';
    btn.disabled = true;
    
    const container = document.getElementById('preview-box-container');
    container.innerHTML = `
        <div class="text-center" style="color: #cbd5e1; font-size: 13px; padding: 40px;">
            <span class="material-symbols-outlined spinner-icon" style="font-size: 32px; color: #004d40; margin-bottom: 8px;">sync</span>
            <div>Loading historical data points...</div>
        </div>
    `;

    fetch(url)
        .then(res => res.json())
        .then(res => {
            btn.innerHTML = originalText;
            btn.disabled = false;
            
            if (res.ok) {
                loadedModulesData = res;
                container.innerHTML = '<div id="chart-preview-canvas"></div>';
                
                if (activeEchartsInstance) {
                    activeEchartsInstance.dispose();
                }
                activeEchartsInstance = echarts.init(document.getElementById('chart-preview-canvas'));
                
                document.getElementById('btn-export-png').disabled = false;
                document.getElementById('data-status-label').textContent = 'Data Loaded';
                document.getElementById('data-status-label').className = 'badge bg-success';
                
                renderChartPreview();
                renderStatsSummary();
            } else {
                alert('Error loading data: ' + res.error);
                container.innerHTML = `<div class="no-data-placeholder text-danger">${res.error}</div>`;
                document.getElementById('btn-export-png').disabled = true;
            }
        })
        .catch(err => {
            console.error('Error fetching chart data:', err);
            btn.innerHTML = originalText;
            btn.disabled = false;
            container.innerHTML = '<div class="no-data-placeholder text-danger">Failed to fetch chart data. Connection error.</div>';
            document.getElementById('btn-export-png').disabled = true;
        });
}

// 4. ECharts Configuration Builder
function renderChartPreview() {
    if (!loadedModulesData || !activeEchartsInstance) return;

    const bgTheme = document.getElementById('bg-theme-select').value;
    const palette = document.getElementById('color-palette-select').value;
    const chartType = document.getElementById('chart-type-select').value;
    const customTitle = document.getElementById('chart-title-input').value;

    const theme = themeStyles[bgTheme] || themeStyles.grafana;
    const colors = colorPalettes[palette] || colorPalettes.grafana;

    const history = loadedModulesData.history || [];
    const metaList = loadedModulesData.meta || [];

    let option = {};

    if (chartType === 'pie' || chartType === 'donut') {
        // Pie/Donut: show average or last value per module
        const pieData = metaList.map((m, idx) => {
            const modHist = history.filter(h => String(h.id_mod) === String(m.id_agente_modulo));
            let val = 0;
            if (modHist.length > 0) {
                // Use average value
                const sum = modHist.reduce((acc, h) => acc + h.val, 0);
                val = parseFloat((sum / modHist.length).toFixed(2));
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
                textStyle: { color: theme.text, fontSize: 14 },
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
                right: 15,
                top: 'center',
                textStyle: { color: theme.text, fontSize: 10 }
            },
            series: [{
                name: 'Average Value',
                type: 'pie',
                radius: chartType === 'pie' ? '65%' : ['35%', '65%'],
                center: ['40%', '55%'],
                data: pieData,
                label: { show: true, formatter: '{b}: {c}', fontSize: 9, color: theme.text },
                labelLine: { show: true, lineStyle: { color: theme.split } }
            }]
        };
    } else {
        // Time-series (Line, Area, Bar)
        const uniqueTimestamps = [...new Set(history.map(h => h.utimestamp))].sort((a, b) => a - b);
        const labels = uniqueTimestamps.map(ts => {
            const found = history.find(h => h.utimestamp === ts);
            return found ? found.time : '';
        });

        // Format short labels for cleaner X-axis
        const shortLabels = labels.map(ts => {
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

        const seriesData = metaList.map((m, idx) => {
            const color = colors[idx % colors.length];
            const modHist = history.filter(h => String(h.id_mod) === String(m.id_agente_modulo));
            
            // Build continuous data points matching the unique timestamps array
            let lastVal = null;
            const dataPoints = uniqueTimestamps.map(ts => {
                const h = modHist.find(x => x.utimestamp === ts);
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
                showSymbol: false,
                connectNulls: true,
                lineStyle: { width: chartType === 'bar' ? 0 : 2 }
            };
        });

        option = {
            backgroundColor: theme.bg,
            color: colors,
            title: {
                text: customTitle || 'Metrics Trend Chart',
                subtext: labels.length > 0 ? `Time Range: ${labels[0]} to ${labels[labels.length - 1]}` : '',
                textStyle: { color: theme.text, fontSize: 14 },
                subtextStyle: { color: theme.axis, fontSize: 9 },
                left: 'center',
                top: 15
            },
            tooltip: {
                trigger: 'axis',
                appendToBody: true,
                backgroundColor: 'rgba(15, 23, 42, 0.95)',
                textStyle: { color: '#cbd5e1', fontSize: 12 },
                padding: 10,
                borderRadius: 6
            },
            legend: {
                type: 'scroll',
                bottom: 5,
                icon: 'circle',
                textStyle: { color: theme.text, fontSize: 9 }
            },
            grid: { left: '5%', right: '5%', top: 70, bottom: 60, containLabel: true },
            xAxis: {
                type: 'category',
                boundaryGap: chartType === 'bar',
                data: shortLabels,
                axisLabel: { fontSize: 8, color: theme.axis, rotate: 15 },
                axisLine: { lineStyle: { color: theme.split } },
                axisTick: { show: false }
            },
            yAxis: {
                type: 'value',
                splitLine: { lineStyle: { color: theme.split } },
                axisLabel: { fontSize: 8, color: theme.axis }
            },
            series: seriesData
        };
    }

    activeEchartsInstance.setOption(option);
}

// 5. Render stats summary table below chart
function renderStatsSummary() {
    if (!loadedModulesData) return;
    
    const container = document.getElementById('stats-summary-container');
    const tbody = document.getElementById('stats-table-body');
    tbody.innerHTML = '';
    
    const history = loadedModulesData.history || [];
    const metaList = loadedModulesData.meta || [];
    
    if (metaList.length === 0) {
        container.classList.add('d-none');
        return;
    }
    
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
            <td><b>${m.agent_alias}</b><br><span class="text-muted" style="font-size: 11px;">${m.module_name}</span></td>
            <td>${min} ${m.unit}</td>
            <td>${max} ${m.unit}</td>
            <td>${avg} ${m.unit}</td>
            <td><span class="badge bg-light text-dark" style="font-size:11px; border: 1px solid #cbd5e1;">${last} ${m.unit}</span></td>
        `;
        tbody.appendChild(tr);
    });
    
    container.classList.remove('d-none');
}

// 6. High-Resolution Chart Export
function exportHighResChart() {
    if (!loadedModulesData) return;
    
    const bgTheme = document.getElementById('bg-theme-select').value;
    const palette = document.getElementById('color-palette-select').value;
    const chartType = document.getElementById('chart-type-select').value;
    const customTitle = document.getElementById('chart-title-input').value;

    const theme = themeStyles[bgTheme] || themeStyles.grafana;
    const colors = colorPalettes[palette] || colorPalettes.grafana;

    const history = loadedModulesData.history || [];
    const metaList = loadedModulesData.meta || [];

    // Create a temporary high-resolution container hidden from view
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
                const sum = modHist.reduce((acc, h) => acc + h.val, 0);
                val = parseFloat((sum / modHist.length).toFixed(2));
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
        const uniqueTimestamps = [...new Set(history.map(h => h.utimestamp))].sort((a, b) => a - b);
        const labels = uniqueTimestamps.map(ts => {
            const found = history.find(h => h.utimestamp === ts);
            return found ? found.time : '';
        });

        const shortLabels = labels.map(ts => {
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

        const seriesData = metaList.map((m, idx) => {
            const color = colors[idx % colors.length];
            const modHist = history.filter(h => String(h.id_mod) === String(m.id_agente_modulo));
            
            let lastVal = null;
            const dataPoints = uniqueTimestamps.map(ts => {
                const h = modHist.find(x => x.utimestamp === ts);
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
                showSymbol: false,
                connectNulls: true,
                lineStyle: { width: chartType === 'bar' ? 0 : 2 }
            };
        });

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

    // Wait slightly for rendering, then export and download the PNG
    setTimeout(() => {
        const imgUrl = highResChart.getDataURL({
            type: 'png',
            pixelRatio: 2, // Retains high resolution
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
</script>

</body>
</html>
