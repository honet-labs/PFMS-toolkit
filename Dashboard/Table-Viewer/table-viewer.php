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

// ==========================================
// API ENDPOINTS
// ==========================================

$CONFIG_FILE = __DIR__ . '/table_viewer_config.json';

// Load Config
if (isset($_GET['api']) && $_GET['api'] === 'load_config') {
    ob_clean();
    header('Content-Type: application/json');
    echo file_exists($CONFIG_FILE) ? file_get_contents($CONFIG_FILE) : json_encode([]);
    exit;
}

// Save Config
if (isset($_GET['api']) && $_GET['api'] === 'save_config') {
    ob_clean();
    header('Content-Type: application/json');
    $input = file_get_contents('php://input');
    $bytes = @file_put_contents($CONFIG_FILE, $input);
    echo json_encode(['ok' => $bytes !== false]);
    exit;
}

// 1. Fetch Distinct Agents
if (isset($_GET['api']) && $_GET['api'] === 'get_agents') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("
            SELECT id_agente, nombre 
            FROM tagente 
            WHERE disabled = 0
            ORDER BY nombre
        ");
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 2. Fetch Modules by Agent
if (isset($_GET['api']) && $_GET['api'] === 'get_modules') {
    ob_clean();
    header('Content-Type: application/json');
    $agent_id = (int)($_GET['agent_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            SELECT id_agente_modulo, nombre 
            FROM tagente_modulo 
            WHERE id_agente = ? AND disabled = 0
            ORDER BY nombre
        ");
        $stmt->execute([$agent_id]);
        echo json_encode(['ok' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 3. Fetch Module Data
if (isset($_GET['api']) && $_GET['api'] === 'fetch_module') {
    ob_clean();
    header('Content-Type: application/json');
    $module_id = (int)($_GET['module_id'] ?? 0);
    
    if ($module_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid Module ID']);
        exit;
    }
    
    try {
        $stmtName = $pdo->prepare("SELECT m.nombre as module_name, a.nombre as agent_name, a.alias as agent_alias FROM tagente_modulo m JOIN tagente a ON m.id_agente = a.id_agente WHERE m.id_agente_modulo = ?");
        $stmtName->execute([$module_id]);
        $modInfo = $stmtName->fetch(PDO::FETCH_ASSOC);
        $modName = $modInfo ? $modInfo['agent_name'] . ' -> ' . $modInfo['module_name'] : "Module $module_id";

        $stmt = $pdo->prepare("SELECT datos, utimestamp FROM tagente_datos_string WHERE id_agente_modulo = ? ORDER BY utimestamp DESC LIMIT 1");
        $stmt->execute([$module_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            echo json_encode([
                'ok' => true, 
                'module_name' => $modName,
                'agent_name' => $modInfo ? $modInfo['agent_name'] : '',
                'agent_alias' => $modInfo ? $modInfo['agent_alias'] : '',
                'timestamp' => date('Y-m-d H:i:s', $data['utimestamp']),
                'raw_data' => $data['datos']
            ]);
        } else {
            $stmtNum = $pdo->prepare("SELECT datos, utimestamp FROM tagente_datos WHERE id_agente_modulo = ? ORDER BY utimestamp DESC LIMIT 1");
            $stmtNum->execute([$module_id]);
            $numData = $stmtNum->fetch(PDO::FETCH_ASSOC);
            
            if ($numData) {
                 echo json_encode([
                    'ok' => true, 
                    'module_name' => $modName,
                    'agent_name' => $modInfo ? $modInfo['agent_name'] : '',
                    'agent_alias' => $modInfo ? $modInfo['agent_alias'] : '',
                    'timestamp' => date('Y-m-d H:i:s', $numData['utimestamp']),
                    'raw_data' => "value | \n-------\n" . $numData['datos'] . " | "
                ]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'No data found for this module.']);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// SHARE MODE DETECTION
$is_share_mode = isset($_GET['share_module']) ? true : false;
$share_module_id = $is_share_mode ? (int)$_GET['share_module'] : 0;
$share_refresh = $is_share_mode ? (int)($_GET['refresh'] ?? 0) : 0;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dynamic Table Viewer - Pandora FMS</title>
    <!-- Core fonts and style dependencies -->
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6f8; color: #334155; font-size: 13px; margin: 0; padding: 0; }
        .material-symbols-outlined { vertical-align: middle; font-size: 18px; }
        .header-section { padding: 15px 30px; background: #fff; border-bottom: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; }
        .page-title { font-size: 16px; font-weight: 600; color: #0b1a26; margin: 0; }
        .main-container { padding: 25px 30px; }
        
        .card-custom { background: #fff; border-radius: 8px; border: 1px solid #e0e4e8; box-shadow: 0 2px 8px rgba(0,0,0,0.04); overflow: visible; margin-bottom: 25px; }
        .panel-header { padding: 12px 20px; border-bottom: 1px solid #e0e4e8; background: #f8fafc; display: flex; justify-content: space-between; align-items: center; border-top-left-radius: 7px; border-top-right-radius: 7px; }
        .panel-title { font-size: 14px; font-weight: 600; color: #0b1a26; margin: 0; }
        .panel-body { padding: 20px; }
        
        .table-pfms { width: 100%; border-collapse: collapse; }
        .table-pfms th { background: #f8f9fa; padding: 12px 20px; text-align: left; font-size: 11px; text-transform: uppercase; color: #7f8c8d; border-bottom: 2px solid #e0e4e8; white-space: nowrap; }
        .table-pfms td { padding: 12px 20px; border-bottom: 1px solid #f0f3f5; word-break: break-word; vertical-align: top; }
        .table-pfms tr:hover { background: #f8fafc; }
        
        .btn-pfms { padding: 6px 14px; border-radius: 4px; font-size: 12px; font-weight: 500; cursor: pointer; transition: 0.2s; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary-pfms { background: #004d40; color: #fff; }
        .btn-primary-pfms:hover { background: #00332a; }
        .btn-outline-pfms { background: #fff; border-color: #dce1e5; color: #4a5568; }
        .btn-outline-pfms:hover { border-color: #cbd5e1; background: #f8fafc; }
        .btn-danger-pfms { background: #fee2e2; color: #b91c1c; border-color: #fca5a5; }
        .btn-danger-pfms:hover { background: #fecaca; color: #991b1b; }
        .btn-icon { padding: 6px; }
        
        .search-box { position: relative; }
        .search-box input { width: 100%; padding: 8px 15px 8px 35px; border-radius: 6px; border: 1px solid #dce1e5; outline: none; transition: 0.2s; font-size: 12px; }
        .search-box input:focus { border-color: #004d40; box-shadow: 0 0 0 3px rgba(0,77,64,0.1); }
        .search-box .material-symbols-outlined { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 16px; }
        
        .d-none { display: none !important; }
        .badge-status { background: #e0f2fe; color: #0284c7; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-refresh { background: #dcfce7; color: #166534; padding: 4px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; }
        
        .spinner { border: 3px solid rgba(0,0,0,0.1); border-left-color: #004d40; border-radius: 50%; width: 24px; height: 24px; animation: spin 1s linear infinite; display: inline-block; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        /* Custom Searchable Dropdown Styles */
        .custom-searchable-select { position: relative; }
        .custom-searchable-select .select-input { padding-right: 30px; cursor: text; }
        .custom-searchable-select .select-caret { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; pointer-events: none; font-size: 20px; }
        .custom-searchable-select .select-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #dce1e5; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); max-height: 250px; overflow-y: auto; z-index: 1000; margin-top: 4px; }
        .custom-searchable-select .select-option { padding: 8px 15px; font-size: 12px; cursor: pointer; color: #334155; border-bottom: 1px solid #f8fafc; }
        .custom-searchable-select .select-option:hover { background: #f1f5f9; color: #004d40; }
        
        /* Layout specific for Share Mode */
        <?php if($is_share_mode): ?>
        .header-section { display: none !important; }
        .main-container { padding: 0 !important; }
        .card-custom { margin: 0; border: none; border-radius: 0; min-height: 100vh; display: flex; flex-direction: column; }
        .panel-body { padding: 20px; flex: 1; display: flex; flex-direction: column; }
        .table-scroll-wrapper { max-height: none !important; flex: 1; }
        <?php else: ?>
        .table-scroll-wrapper { max-height: 400px; }
        <?php endif; ?>

        /* Sleek Modern Modal System */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(4px); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-box { background: #fff; width: 450px; padding: 24px; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); border: 1px solid #e2e8f0; }
        .modal-header-custom { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; margin-bottom: 18px; }
        .modal-header-custom h5 { font-size: 16px; font-weight: 600; margin: 0; color: #0f172a; }
        .modal-header-custom .close-btn { cursor: pointer; color: #64748b; font-size: 20px; transition: color 0.15s; }
        .modal-header-custom .close-btn:hover { color: #0f172a; }
        .modal-footer-custom { display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e2e8f0; padding-top: 16px; margin-top: 18px; }
    </style>
</head>
<body>

<!-- Dashboard List View -->
<div id="view_list" class="main-container">
    <div class="header-section" style="padding: 15px 0; border: none; margin-bottom: 25px; background: transparent;">
        <div>
            <h1 class="page-title">Dynamic Table Viewer Dashboard</h1>
            <p style="margin: 4px 0 0 0; font-size: 11px; color: #64748b;">Manage and view your custom string table dashboards.</p>
        </div>
        <div>
            <button class="btn-pfms btn-primary-pfms" onclick="Dashboard.createDashboard()">
                <span class="material-symbols-outlined">add</span> Create Dashboard
            </button>
        </div>
    </div>
    
    <div class="card-custom">
        <div class="panel-header">
            <h3 class="panel-title">My Dashboards</h3>
        </div>
        <div class="panel-body">
            <table class="table-pfms" id="dashListTable">
                <thead>
                    <tr>
                        <th style="width: 60%;">Dashboard Name</th>
                        <th style="text-align: center; width: 20%;">Total Panels</th>
                        <th style="text-align: right; width: 20%; padding-right: 30px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="dashListBody">
                    <tr><td colspan="3" style="text-align: center; padding: 30px; color: #94a3b8;">Loading dashboards...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Dashboard Detail/Grid View -->
<div id="view_detail" class="d-none">
    <?php if(!$is_share_mode): ?>
    <div class="header-section">
        <div class="d-flex align-items-center gap-3">
            <button class="btn-pfms btn-outline-pfms" onclick="Dashboard.closeDashboard()" style="padding: 6px 10px;">
                <span class="material-symbols-outlined" style="font-size: 16px;">arrow_back</span> Back
            </button>
            <div>
                <h1 class="page-title" id="activeDashTitle">Active Dashboard</h1>
                <p style="margin: 4px 0 0 0; font-size: 11px; color: #64748b;" id="activeDashSubtitle">Customize your string modules tabular display.</p>
            </div>
        </div>
        <div class="d-flex gap-2">
            <button class="btn-pfms btn-outline-pfms" onclick="Dashboard.renameActiveDashboard()">
                <span class="material-symbols-outlined" style="font-size: 14px;">edit</span> Rename
            </button>
            <button class="btn-pfms btn-primary-pfms" onclick="Dashboard.addPanel()">
                <span class="material-symbols-outlined">add</span> Add Panel
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div class="main-container" id="dashboardGrid">
        <!-- Panels will be rendered here via JS -->
    </div>
</div>

<!-- TEMPLATES -->
<template id="tpl-panel-config">
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label" style="font-size: 12px; font-weight: 600;">1. Search & Select Agent</label>
            <div class="custom-searchable-select" id="wrapper-agent-{ID}">
                <input type="hidden" id="agentVal-{ID}">
                <input type="text" class="form-control select-input" id="agentInput-{ID}" placeholder="Select or search agent..." autocomplete="off"
                    onfocus="Dashboard.toggleDropdown('{ID}', 'agent', true)" 
                    oninput="Dashboard.filterDropdown('{ID}', 'agent', this.value)"
                    onblur="Dashboard.toggleDropdown('{ID}', 'agent', false)">
                <span class="material-symbols-outlined select-caret">expand_more</span>
                <div class="select-dropdown d-none" id="agentDrop-{ID}"></div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label" style="font-size: 12px; font-weight: 600;">2. Search & Select Module</label>
            <div class="custom-searchable-select" id="wrapper-module-{ID}">
                <input type="hidden" id="moduleSelect-{ID}">
                <input type="text" class="form-control select-input" id="moduleInput-{ID}" placeholder="Select agent first..." autocomplete="off" disabled
                    onfocus="Dashboard.toggleDropdown('{ID}', 'module', true)" 
                    oninput="Dashboard.filterDropdown('{ID}', 'module', this.value)"
                    onblur="Dashboard.toggleDropdown('{ID}', 'module', false)">
                <span class="material-symbols-outlined select-caret">expand_more</span>
                <div class="select-dropdown d-none" id="moduleDrop-{ID}"></div>
            </div>
        </div>
        <div class="col-12">
            <button class="btn-pfms btn-primary-pfms" onclick="Dashboard.saveConfig('{ID}')">Save Config</button>
            <button class="btn-pfms btn-outline-pfms" onclick="Dashboard.cancelEdit('{ID}')">Cancel</button>
        </div>
    </div>
</template>

<template id="tpl-panel-view">
    <div class="mb-3 d-flex justify-content-between align-items-center">
        <div>
            <div style="font-size: 11px; color: #64748b; margin-bottom: 4px;">Last Updated: <span id="timestamp-{ID}" style="font-weight: 600;">-</span></div>
        </div>
        <div class="search-box" style="width: 250px;">
            <span class="material-symbols-outlined">search</span>
            <input type="text" placeholder="Filter this table..." oninput="Dashboard.filterTable('{ID}', this.value)">
        </div>
    </div>
    <div class="table-scroll-wrapper" style="overflow-x: auto; overflow-y: auto;">
        <table class="table-pfms">
            <thead id="thead-{ID}"></thead>
            <tbody id="tbody-{ID}"></tbody>
        </table>
    </div>
    <!-- Fallback raw block -->
    <div id="raw-{ID}" class="d-none" style="padding: 15px; background: #1e293b; color: #e2e8f0; font-family: monospace; font-size: 11px; white-space: pre-wrap;"></div>
</template>

<!-- Custom Create/Rename Dashboard Modal -->
<div class="modal-overlay" id="dashMetaModal">
    <div class="modal-box">
        <div class="modal-header-custom">
            <h5 id="dashMetaTitle">Create Dashboard</h5>
            <span class="material-symbols-outlined close-btn" onclick="Dashboard.closeDashMetaModal()">close</span>
        </div>
        <div class="form-group mb-3">
            <label class="form-label" style="font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px;">Dashboard Name</label>
            <input type="text" id="m_dash_title" class="form-control" placeholder="e.g. Hive Database Table Viewers" autocomplete="off" style="font-size: 13px;"
                onkeydown="if(event.key === 'Enter') { Dashboard.saveDashboardMeta(); } else if(event.key === 'Escape') { Dashboard.closeDashMetaModal(); }">
        </div>
        <div class="modal-footer-custom">
            <button class="btn-pfms btn-outline-pfms" onclick="Dashboard.closeDashMetaModal()">Cancel</button>
            <button class="btn-pfms btn-primary-pfms" onclick="Dashboard.saveDashboardMeta()">Save Changes</button>
        </div>
    </div>
</div>

<script>
const Dashboard = {
    panels: [],
    globalAgents: [],
    intervals: {},
    isShareMode: <?php echo $is_share_mode ? 'true' : 'false'; ?>,
    shareModuleId: <?php echo $share_module_id; ?>,
    shareRefresh: <?php echo $share_refresh; ?>,
    masterDashboards: [],
    currentDashId: null,

    async init() {
        if (this.isShareMode) {
            // Boot directly into share mode with 1 panel
            const panelId = 'share-1';
            this.panels = [{
                id: panelId,
                module_id: this.shareModuleId,
                refresh: this.shareRefresh,
                mode: 'view',
                dataRows: []
            }];
            document.getElementById('view_list').classList.add('d-none');
            document.getElementById('view_detail').classList.remove('d-none');
            this.renderAll();
            this.fetchData(panelId);
            return;
        }

        // Normal mode
        await this.loadAgents();
        await this.loadConfig();

        // Migrate old localStorage if present
        const saved = localStorage.getItem('pfms_table_viewer');
        if (saved && this.masterDashboards.length === 0) {
            try {
                const oldPanels = JSON.parse(saved);
                if (oldPanels.length > 0) {
                    this.masterDashboards = [{
                        id: 'dash-' + Date.now(),
                        name: 'My Migrated Dashboard',
                        panels: oldPanels.map(p => ({ ...p, dataRows: [] }))
                    }];
                    await this.saveConfigToServer();
                    localStorage.removeItem('pfms_table_viewer');
                }
            } catch(e) {}
        }

        const urlParams = new URLSearchParams(window.location.search);
        let activeId = urlParams.get('dashboard_id') || localStorage.getItem('pfms_table_viewer_active_id');

        if (activeId && this.masterDashboards.some(d => d.id === activeId)) {
            this.openDashboard(activeId);
        } else {
            this.showDashboardList();
        }
    },

    async loadConfig() {
        try {
            const res = await fetch('?api=load_config');
            const data = await res.json();
            if (Array.isArray(data)) {
                this.masterDashboards = data;
            } else {
                this.masterDashboards = [];
            }
        } catch(e) {
            console.error("Error loading config", e);
            this.masterDashboards = [];
        }
    },

    async saveConfigToServer() {
        if (this.isShareMode) return;
        try {
            const cleanDashboards = this.masterDashboards.map(d => ({
                id: d.id,
                name: d.name,
                panels: (d.panels || []).map(p => ({
                    id: p.id,
                    module_id: p.module_id,
                    refresh: p.refresh,
                    mode: p.mode
                }))
            }));
            const res = await fetch('?api=save_config', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(cleanDashboards)
            });
            const data = await res.json();
            return data.ok;
        } catch(e) {
            console.error("Error saving config", e);
            return false;
        }
    },

    showDashboardList() {
        this.currentDashId = null;
        localStorage.removeItem('pfms_table_viewer_active_id');

        const newUrl = window.location.origin + window.location.pathname;
        window.history.replaceState({}, '', newUrl);

        document.getElementById('view_detail').classList.add('d-none');
        document.getElementById('view_list').classList.remove('d-none');
        this.renderDashboardList();
    },

    renderDashboardList() {
        const body = document.getElementById('dashListBody');
        if (this.masterDashboards.length === 0) {
            body.innerHTML = `<tr><td colspan="3" style="text-align: center; padding: 40px; color: #94a3b8;">
                <span class="material-symbols-outlined" style="font-size: 48px; color: #cbd5e1; display: block; margin-bottom: 10px;">space_dashboard</span>
                No dashboards found. Click <b>"Create Dashboard"</b> to get started!
            </td></tr>`;
            return;
        }

        body.innerHTML = this.masterDashboards.map(d => {
            const totalPanels = d.panels ? d.panels.length : 0;
            return `
                <tr>
                    <td>
                        <a href="javascript:void(0)" onclick="Dashboard.openDashboard('${d.id}')" style="color: #004d40; font-weight: 600; text-decoration: none; font-size: 14px;">
                            ${this.escapeHtml(d.name)}
                        </a>
                    </td>
                    <td style="text-align: center; font-weight: 500; color: #475569; font-size: 13px;">${totalPanels} Panels</td>
                    <td style="text-align: right;">
                        <button class="btn-pfms btn-outline-pfms btn-icon" onclick="Dashboard.renameDashboard('${d.id}')" title="Rename"><span class="material-symbols-outlined" style="font-size:14px;">edit</span></button>
                        <button class="btn-pfms btn-danger-pfms btn-icon" onclick="Dashboard.deleteDashboard('${d.id}')" title="Delete"><span class="material-symbols-outlined" style="font-size:14px;">delete</span></button>
                    </td>
                </tr>
            `;
        }).join('');
    },

    createDashboard() {
        this.editingDashId = null;
        document.getElementById('dashMetaTitle').innerText = 'Create Dashboard';
        const input = document.getElementById('m_dash_title');
        input.value = '';
        document.getElementById('dashMetaModal').style.display = 'flex';
        input.focus();
    },

    renameDashboard(id) {
        const dash = this.masterDashboards.find(d => d.id === id);
        if (!dash) return;
        this.editingDashId = id;
        document.getElementById('dashMetaTitle').innerText = 'Rename Dashboard';
        const input = document.getElementById('m_dash_title');
        input.value = dash.name;
        document.getElementById('dashMetaModal').style.display = 'flex';
        input.focus();
    },

    renameActiveDashboard() {
        if (!this.currentDashId) return;
        this.renameDashboard(this.currentDashId);
    },

    closeDashMetaModal() {
        document.getElementById('dashMetaModal').style.display = 'none';
        this.editingDashId = null;
    },

    async saveDashboardMeta() {
        const titleInput = document.getElementById('m_dash_title');
        const name = titleInput.value.trim();
        if (!name) { alert("Please enter a valid dashboard name."); return; }

        if (this.editingDashId) {
            // Rename mode
            const dash = this.masterDashboards.find(d => d.id === this.editingDashId);
            if (dash) {
                dash.name = name;
                await this.saveConfigToServer();
                this.renderDashboardList();
                if (this.currentDashId === this.editingDashId) {
                    document.getElementById('activeDashTitle').innerText = dash.name;
                }
            }
        } else {
            // Create mode
            const newDash = {
                id: 'dash-' + Date.now(),
                name: name,
                panels: []
            };
            this.masterDashboards.push(newDash);
            await this.saveConfigToServer();
            this.openDashboard(newDash.id);
        }
        this.closeDashMetaModal();
    },

    async deleteDashboard(id) {
        const dash = this.masterDashboards.find(d => d.id === id);
        if (!dash) return;
        if (!confirm(`Are you sure you want to delete dashboard "${dash.name}"?`)) return;
        this.masterDashboards = this.masterDashboards.filter(d => d.id !== id);
        await this.saveConfigToServer();
        this.showDashboardList();
    },

    openDashboard(id) {
        const dash = this.masterDashboards.find(d => d.id === id);
        if (!dash) {
            this.showDashboardList();
            return;
        }

        this.currentDashId = id;
        localStorage.setItem('pfms_table_viewer_active_id', id);

        const newUrl = window.location.origin + window.location.pathname + `?dashboard_id=${id}`;
        window.history.replaceState({}, '', newUrl);

        document.getElementById('activeDashTitle').innerText = dash.name;
        document.getElementById('view_list').classList.add('d-none');
        document.getElementById('view_detail').classList.remove('d-none');

        Object.keys(this.intervals).forEach(k => clearInterval(this.intervals[k]));
        this.intervals = {};

        this.panels = dash.panels || [];
        this.renderAll();

        this.panels.forEach(p => {
            if (p.mode === 'view') this.fetchData(p.id);
        });
    },

    closeDashboard() {
        this.showDashboardList();
    },

    async loadAgents() {
        try {
            const res = await fetch('?api=get_agents');
            const json = await res.json();
            if (json.ok) {
                this.globalAgents = json.data;
            }
        } catch(e) { console.error("Error loading agents", e); }
    },

    moduleCache: {},

    toggleDropdown(id, type, show) {
        const drop = document.getElementById(`${type}Drop-${id}`);
        if(!drop) return;
        if(show) {
            drop.classList.remove('d-none');
            this.populateDropdown(id, type, '');
        } else {
            setTimeout(() => drop.classList.add('d-none'), 200);
        }
    },

    filterDropdown(id, type, keyword) {
        this.populateDropdown(id, type, keyword.toLowerCase());
    },

    populateDropdown(id, type, keyword) {
        const drop = document.getElementById(`${type}Drop-${id}`);
        let data = type === 'agent' ? this.globalAgents : (this.moduleCache[id] || []);

        let filtered = keyword ? data.filter(item => item.nombre.toLowerCase().includes(keyword)) : data;

        if (filtered.length === 0) {
            drop.innerHTML = '<div class="select-option" style="color:#94a3b8; cursor:default;">No results found</div>';
            return;
        }

        let html = '';
        filtered.forEach(item => {
            const val = type === 'agent' ? item.id_agente : item.id_agente_modulo;
            const name = item.nombre.replace(/'/g, "\\'");
            html += `<div class="select-option" onclick="Dashboard.selectOption('${id}', '${type}', '${val}', '${name}')">${item.nombre}</div>`;
        });
        drop.innerHTML = html;
    },

    selectOption(id, type, val, name) {
        if(type === 'agent') {
            document.getElementById(`agentVal-${id}`).value = val;
            document.getElementById(`agentInput-${id}`).value = name;

            // Reset module
            document.getElementById(`moduleSelect-${id}`).value = '';
            const modInput = document.getElementById(`moduleInput-${id}`);
            modInput.value = '';
            modInput.disabled = false;
            modInput.placeholder = 'Loading modules...';
            this.loadModules(id, val);
        } else {
            document.getElementById(`moduleSelect-${id}`).value = val;
            document.getElementById(`moduleInput-${id}`).value = name;
        }
        document.getElementById(`${type}Drop-${id}`).classList.add('d-none');
    },

    async loadModules(id, agentId) {
        if(!agentId) return;
        try {
            const res = await fetch('?api=get_modules&agent_id=' + agentId);
            const json = await res.json();
            if (json.ok) {
                this.moduleCache[id] = json.data;
                document.getElementById(`moduleInput-${id}`).placeholder = 'Select or search module...';
            }
        } catch(e) {
            document.getElementById(`moduleInput-${id}`).placeholder = 'Error loading';
        }
    },

    async addPanel() {
        const id = 'panel-' + Date.now();
        this.panels.push({
            id: id,
            module_id: null,
            refresh: 0,
            mode: 'config',
            dataRows: []
        });
        const dash = this.masterDashboards.find(d => d.id === this.currentDashId);
        if (dash) {
            dash.panels = this.panels;
            await this.saveConfigToServer();
        }
        this.renderAll();
    },

    async removePanel(id) {
        if (this.intervals[id]) clearInterval(this.intervals[id]);
        this.panels = this.panels.filter(p => p.id !== id);
        const dash = this.masterDashboards.find(d => d.id === this.currentDashId);
        if (dash) {
            dash.panels = this.panels;
            await this.saveConfigToServer();
        }
        this.renderAll();
    },

    editPanel(id) {
        if (this.intervals[id]) { clearInterval(this.intervals[id]); delete this.intervals[id]; }
        const panel = this.panels.find(p => p.id === id);
        if (panel) {
            panel.mode = 'config';
            this.renderAll();
        }
    },

    cancelEdit(id) {
        const panel = this.panels.find(p => p.id === id);
        if (!panel) return;
        if (!panel.module_id) {
            this.removePanel(id);
        } else {
            panel.mode = 'view';
            this.renderAll();
        }
    },

    async saveConfig(id) {
        const modId = document.getElementById(`moduleSelect-${id}`).value;
        if (!modId) { alert("Please search and select a module."); return; }

        const panel = this.panels.find(p => p.id === id);
        panel.module_id = modId;
        panel.mode = 'view';
        const dash = this.masterDashboards.find(d => d.id === this.currentDashId);
        if (dash) {
            dash.panels = this.panels;
            await this.saveConfigToServer();
        }
        this.renderAll();
        this.fetchData(id);
    },

    async changeRefresh(id, seconds) {
        const panel = this.panels.find(p => p.id === id);
        panel.refresh = parseInt(seconds);
        const dash = this.masterDashboards.find(d => d.id === this.currentDashId);
        if (dash) {
            dash.panels = this.panels;
            await this.saveConfigToServer();
        }

        const badge = document.getElementById(`refBadge-${id}`);
        if(badge) {
            if(panel.refresh === 0) badge.classList.add('d-none');
            else { badge.classList.remove('d-none'); badge.innerText = `Auto: ${panel.refresh}s`; }
        }

        this.setupInterval(id);
    },

    setupInterval(id) {
        if (this.intervals[id]) clearInterval(this.intervals[id]);
        const panel = this.panels.find(p => p.id === id);
        if (panel && panel.refresh > 0 && panel.mode === 'view') {
            this.intervals[id] = setInterval(() => {
                this.fetchData(id, true);
            }, panel.refresh * 1000);
        }
    },

    shareUrl(id) {
        const panel = this.panels.find(p => p.id === id);

        const refSelect = document.getElementById(`refreshSelect-${id}`);
        if(refSelect) panel.refresh = parseInt(refSelect.value) || 0;

        const url = window.location.origin + window.location.pathname + `?share_module=${panel.module_id}&refresh=${panel.refresh}`;
        navigator.clipboard.writeText(url).then(() => {
            alert(`Shareable URL copied to clipboard!\n(Auto-refresh: ${panel.refresh > 0 ? panel.refresh + 's' : 'Off'})\n\n` + url);
        });
    },

    async fetchData(id, isSilent = false) {
        const panel = this.panels.find(p => p.id === id);
        if (!panel || !panel.module_id) return;

        if (!isSilent) {
            document.getElementById(`tbody-${id}`).innerHTML = `<tr><td colspan="100%" class="text-center py-4"><div class="spinner"></div></td></tr>`;
        }

        try {
            const res = await fetch(`?api=fetch_module&module_id=${panel.module_id}`);
            const data = await res.json();
            if (data.ok) {
                panel.module_name = data.module_name;
                panel.agent_name = data.agent_name;
                panel.agent_alias = data.agent_alias;
                panel.timestamp = data.timestamp;
                panel.raw_data = data.raw_data;

                const titleEl = document.getElementById(`title-${id}`);
                if(titleEl) titleEl.innerText = data.module_name;

                if(this.isShareMode) document.title = data.module_name;

                const tsEl = document.getElementById(`timestamp-${id}`);
                if(tsEl) tsEl.innerText = data.timestamp;

                this.parseAndRender(id, data.raw_data);
            } else {
                document.getElementById(`tbody-${id}`).innerHTML = `<tr><td colspan="100%" class="text-danger">${data.error}</td></tr>`;
            }
        } catch (e) {
            if (!isSilent) document.getElementById(`tbody-${id}`).innerHTML = `<tr><td colspan="100%" class="text-danger">Network Error</td></tr>`;
        }

        this.setupInterval(id);
    },

    parseAndRender(id, rawText) {
        const panel = this.panels.find(p => p.id === id);
        const lines = rawText.split(/\r?\n/).filter(l => l.trim() !== '');

        let separatorIdx = -1;
        for (let i = 0; i < lines.length; i++) {
            const trimmed = lines[i].trim();
            if (trimmed.match(/^[|:\-\+\s]{5,}$/) && trimmed.includes('-')) {
                separatorIdx = i;
                break;
            }
        }

        const rawEl = document.getElementById(`raw-${id}`);
        const thead = document.getElementById(`thead-${id}`);

        if (separatorIdx === -1 || separatorIdx === 0) {
            // Check if the first line contains a pipe '|'
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
                        if (currentCells.length > 0) {
                            parsedRows.push(currentCells);
                        }
                        let cells = line.split('|').map(c => c.trim());
                        if (cells.length > 0 && cells[0] === '' && line.startsWith('|')) cells.shift();
                        if (cells.length > 0 && cells[cells.length - 1] === '' && line.endsWith('|')) cells.pop();
                        currentCells = cells;
                    } else {
                        if (currentCells.length > 0) {
                            const lastIdx = currentCells.length - 1;
                            currentCells[lastIdx] = currentCells[lastIdx] + '\n' + line.trim();
                        } else {
                            let cells = line.split('|').map(c => c.trim());
                            currentCells = cells;
                        }
                    }
                });
                if (currentCells.length > 0) {
                    parsedRows.push(currentCells);
                }

                panel.dataRows = [];
                parsedRows.forEach(cells => {
                    while (cells.length < numCols) cells.push('');
                    if (cells.length > numCols) cells = cells.slice(0, numCols);
                    panel.dataRows.push(cells);
                });

                thead.innerHTML = '<tr>' + headers.map(h => `<th>${this.escapeHtml(h)}</th>`).join('') + '</tr>';
                this.renderTableRows(id, panel.dataRows);
                rawEl.classList.add('d-none');
                return;
            }

            thead.innerHTML = '';
            document.getElementById(`tbody-${id}`).innerHTML = '';
            rawEl.innerText = rawText;
            rawEl.classList.remove('d-none');
            panel.dataRows = [];
            return;
        }

        rawEl.classList.add('d-none');

        // Parse wrapped headers by concatenating all header lines before the separator
        const headerLines = lines.slice(0, separatorIdx);
        let headers = [];
        headerLines.forEach(line => {
            let cols = line.split('|').map(h => h.trim()).filter(h => h !== '');
            headers = headers.concat(cols);
        });

        const numCols = headers.length || 1;
        
        // Parse multi-line wrapped data rows
        const dataLines = lines.slice(separatorIdx + 1);
        let parsedRows = [];
        let currentCells = [];

        dataLines.forEach(line => {
            const pipeCount = (line.match(/\|/g) || []).length;
            if (pipeCount >= 3) {
                if (currentCells.length > 0) {
                    parsedRows.push(currentCells);
                }
                let cells = line.split('|').map(c => c.trim());
                if (cells.length > 0 && cells[0] === '' && line.startsWith('|')) cells.shift();
                if (cells.length > 0 && cells[cells.length - 1] === '' && line.endsWith('|')) cells.pop();
                currentCells = cells;
            } else {
                if (currentCells.length > 0) {
                    const lastIdx = currentCells.length - 1;
                    currentCells[lastIdx] = currentCells[lastIdx] + '\n' + line.trim();
                } else {
                    let cells = line.split('|').map(c => c.trim());
                    currentCells = cells;
                }
            }
        });
        if (currentCells.length > 0) {
            parsedRows.push(currentCells);
        }

        panel.dataRows = [];
        parsedRows.forEach(cells => {
            while (cells.length < numCols) cells.push('');
            if (cells.length > numCols) cells = cells.slice(0, numCols);
            panel.dataRows.push(cells);
        });

        thead.innerHTML = '<tr>' + headers.map(h => `<th>${this.escapeHtml(h)}</th>`).join('') + '</tr>';
        this.renderTableRows(id, panel.dataRows);
    },

    renderTableRows(id, rows) {
        const tbody = document.getElementById(`tbody-${id}`);
        if(!tbody) return;
        const panel = this.panels.find(p => p.id === id);
        const agentLabel = (panel && panel.agent_alias) ? `${panel.agent_alias}/${panel.agent_name}` : 'Table Viewer';
        if (rows.length === 0) {
            tbody.innerHTML = `<tr><td colspan="100%" style="text-align: center; color: #94a3b8; padding: 20px;">No data rows found.</td></tr>`;
        } else {
            tbody.innerHTML = rows.map(r => '<tr>' + r.map(c => {
                const cellStr = String(c || '');
                if (cellStr.length > 45 || cellStr.includes('\n')) {
                    return `<td style="vertical-align: middle;"><button class="btn-pfms btn-outline-pfms" style="padding:2px 6px; font-size:10px; font-weight:600; cursor:pointer;" onclick="showLongValuePopup('Detail Query', '${agentLabel}', \`${cellStr.replace(/`/g, "\\`").replace(/\$/g, "\\$")}\`)">View</button></td>`;
                }
                return `<td>${this.escapeHtml(c)}</td>`;
            }).join('') + '</tr>').join('');
        }
    },

    filterTable(id, keyword) {
        keyword = keyword.toLowerCase();
        const panel = this.panels.find(p => p.id === id);
        if (!keyword) { this.renderTableRows(id, panel.dataRows); return; }
        const filtered = panel.dataRows.filter(row => row.some(cell => cell.toLowerCase().includes(keyword)));
        this.renderTableRows(id, filtered);
    },

    escapeHtml(unsafe) {
        if (!unsafe) return '';
        return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    },

    renderAll() {
        const grid = document.getElementById('dashboardGrid');
        grid.innerHTML = '';

        this.panels.forEach(p => {
            const card = document.createElement('div');
            card.className = 'card-custom';
            card.id = p.id;

            let headerHtml = `
                <div class="panel-header">
                    <div class="d-flex align-items-center gap-2">
                        <span class="material-symbols-outlined text-muted">table_chart</span>
                        <h3 class="panel-title" id="title-${p.id}">${p.mode === 'config' ? 'Configure Panel' : 'Loading Module...'}</h3>
                        <span class="badge-refresh ${p.refresh === 0 ? 'd-none' : ''}" id="refBadge-${p.id}">Auto: ${p.refresh}s</span>
                    </div>
            `;

            if (this.isShareMode) {
                headerHtml += `</div>`;
            } else {
                headerHtml += `
                    <div class="d-flex gap-2">
                        ${p.mode === 'view' ? `
                            <select class="form-select form-select-sm" id="refreshSelect-${p.id}" style="width: auto; font-size: 11px;" onchange="Dashboard.changeRefresh('${p.id}', this.value)">
                                <option value="0" ${p.refresh === 0 ? 'selected' : ''}>Refresh: Off</option>
                                <option value="5" ${p.refresh === 5 ? 'selected' : ''}>5s</option>
                                <option value="10" ${p.refresh === 10 ? 'selected' : ''}>10s</option>
                                <option value="30" ${p.refresh === 30 ? 'selected' : ''}>30s</option>
                                <option value="60" ${p.refresh === 60 ? 'selected' : ''}>1m</option>
                                <option value="300" ${p.refresh === 300 ? 'selected' : ''}>5m</option>
                            </select>
                            <button class="btn-pfms btn-outline-pfms btn-icon" title="Share URL" onclick="Dashboard.shareUrl('${p.id}')"><span class="material-symbols-outlined" style="font-size: 14px;">share</span></button>
                            <button class="btn-pfms btn-outline-pfms btn-icon" title="Edit Source" onclick="Dashboard.editPanel('${p.id}')"><span class="material-symbols-outlined" style="font-size: 14px;">edit</span></button>
                        ` : ''}
                        <button class="btn-pfms btn-danger-pfms btn-icon" title="Remove Panel" onclick="Dashboard.removePanel('${p.id}')"><span class="material-symbols-outlined" style="font-size: 14px;">close</span></button>
                    </div>
                </div>
                `;
            }

            const tplId = p.mode === 'config' ? 'tpl-panel-config' : 'tpl-panel-view';
            const tplHtml = document.getElementById(tplId).innerHTML.replace(/{ID}/g, p.id);

            card.innerHTML = headerHtml + `<div class="panel-body">${tplHtml}</div>`;
            grid.appendChild(card);

            // Populate cached values immediately to prevent Loading Module flash/bug
            if (p.mode === 'view' && p.raw_data) {
                const titleEl = card.querySelector(`#title-${p.id}`);
                if(titleEl && p.module_name) titleEl.innerText = p.module_name;

                const tsEl = card.querySelector(`#timestamp-${p.id}`);
                if(tsEl && p.timestamp) tsEl.innerText = p.timestamp;

                this.parseAndRender(p.id, p.raw_data);
            }
        });
    }
};

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

document.addEventListener('DOMContentLoaded', () => Dashboard.init());
</script>

</body>
</html>
