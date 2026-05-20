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

// 1. Fetch Distinct Agents
if (isset($_GET['api']) && $_GET['api'] === 'get_agents') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT a.id_agente, a.nombre 
            FROM tagente a 
            JOIN tagente_modulo m ON a.id_agente = m.id_agente 
            WHERE m.id_tipo_modulo IN (3, 4) OR m.nombre LIKE '%slow_query%' 
            ORDER BY a.nombre
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
            WHERE id_agente = ? AND (id_tipo_modulo IN (3, 4) OR nombre LIKE '%slow_query%') 
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
        $stmtName = $pdo->prepare("SELECT m.nombre as module_name, a.nombre as agent_name FROM tagente_modulo m JOIN tagente a ON m.id_agente = a.id_agente WHERE m.id_agente_modulo = ?");
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
    <link href="/pandora_console/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="/pandora_console/custom/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6f8; color: #334155; font-size: 13px; margin: 0; padding: 0; }
        .material-symbols-outlined { vertical-align: middle; font-size: 18px; }
        .header-section { padding: 15px 30px; background: #fff; border-bottom: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; }
        .page-title { font-size: 16px; font-weight: 600; color: #0b1a26; margin: 0; }
        .main-container { padding: 25px 30px; }
        
        .card-custom { background: #fff; border-radius: 8px; border: 1px solid #e0e4e8; box-shadow: 0 2px 8px rgba(0,0,0,0.04); overflow: hidden; margin-bottom: 25px; }
        .panel-header { padding: 12px 20px; border-bottom: 1px solid #e0e4e8; background: #f8fafc; display: flex; justify-content: space-between; align-items: center; }
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
    </style>
</head>
<body>

<?php if(!$is_share_mode): ?>
<div class="header-section">
    <div>
        <h1 class="page-title">Dynamic Table Viewer</h1>
        <p style="margin: 4px 0 0 0; font-size: 11px; color: #64748b;">Build custom dashboard grids from string modules.</p>
    </div>
    <div>
        <button class="btn-pfms btn-primary-pfms" onclick="Dashboard.addPanel()">
            <span class="material-symbols-outlined">add</span> Add Panel
        </button>
    </div>
</div>
<?php endif; ?>

<div class="main-container" id="dashboardGrid">
    <!-- Panels will be rendered here via JS -->
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
            <button class="btn-pfms btn-outline-pfms" onclick="Dashboard.removePanel('{ID}')">Cancel</button>
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

<script>
const Dashboard = {
    panels: [],
    globalAgents: [],
    intervals: {},
    isShareMode: <?php echo $is_share_mode ? 'true' : 'false'; ?>,
    shareModuleId: <?php echo $share_module_id; ?>,
    shareRefresh: <?php echo $share_refresh; ?>,

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
            this.renderAll();
            this.fetchData(panelId);
            return;
        }

        // Normal mode
        await this.loadAgents();
        this.loadState();
        if (this.panels.length === 0) {
            this.addPanel(); // add first default panel
        } else {
            this.renderAll();
            this.panels.forEach(p => {
                if (p.mode === 'view') this.fetchData(p.id);
            });
        }
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

    addPanel() {
        const id = 'panel-' + Date.now();
        this.panels.push({
            id: id,
            module_id: null,
            refresh: 0,
            mode: 'config',
            dataRows: [] 
        });
        this.saveState();
        this.renderAll();
    },

    removePanel(id) {
        if (this.intervals[id]) clearInterval(this.intervals[id]);
        this.panels = this.panels.filter(p => p.id !== id);
        this.saveState();
        this.renderAll();
    },

    editPanel(id) {
        if (this.intervals[id]) { clearInterval(this.intervals[id]); delete this.intervals[id]; }
        const panel = this.panels.find(p => p.id === id);
        if (panel) {
            panel.mode = 'config';
            this.saveState();
            this.renderAll();
        }
    },

    saveConfig(id) {
        const modId = document.getElementById(`moduleSelect-${id}`).value;
        if (!modId) { alert("Please search and select a module."); return; }
        
        const panel = this.panels.find(p => p.id === id);
        panel.module_id = modId;
        panel.mode = 'view';
        this.saveState();
        this.renderAll();
        this.fetchData(id);
    },

    changeRefresh(id, seconds) {
        const panel = this.panels.find(p => p.id === id);
        panel.refresh = parseInt(seconds);
        this.saveState();
        
        // Update UI badge
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
        
        // Force sync the latest refresh value just in case
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
                const titleEl = document.getElementById(`title-${id}`);
                if(titleEl) titleEl.innerText = data.module_name;
                
                // Neater Page Title in Share Mode
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
            if (lines[i].trim().match(/^[\-\+]{5,}$/)) { separatorIdx = i; break; }
        }
        
        const rawEl = document.getElementById(`raw-${id}`);
        const thead = document.getElementById(`thead-${id}`);
        
        if (separatorIdx === -1 || separatorIdx === 0) {
            thead.innerHTML = '';
            document.getElementById(`tbody-${id}`).innerHTML = '';
            rawEl.innerText = rawText;
            rawEl.classList.remove('d-none');
            panel.dataRows = [];
            return;
        }
        
        rawEl.classList.add('d-none');
        
        const headerLines = lines.slice(0, separatorIdx);
        const headersStr = headerLines.join(' '); 
        let headers = headersStr.split('|').map(h => h.trim());
        if (headers.length > 0 && headers[headers.length-1] === '') headers.pop();
        
        const dataLines = lines.slice(separatorIdx + 1);
        const dataStr = dataLines.join(' ');
        let cells = dataStr.split('|').map(c => c.trim());
        if (cells.length > 0 && cells[cells.length-1] === '') cells.pop();
        
        const numCols = headers.length || 1;
        panel.dataRows = [];
        let currentRow = [];
        for (let i = 0; i < cells.length; i++) {
            currentRow.push(cells[i]);
            if (currentRow.length === numCols) { panel.dataRows.push(currentRow); currentRow = []; }
        }
        if (currentRow.length > 0) {
            while (currentRow.length < numCols) currentRow.push('');
            panel.dataRows.push(currentRow);
        }
        
        thead.innerHTML = '<tr>' + headers.map(h => `<th>${this.escapeHtml(h)}</th>`).join('') + '</tr>';
        this.renderTableRows(id, panel.dataRows);
    },

    renderTableRows(id, rows) {
        const tbody = document.getElementById(`tbody-${id}`);
        if(!tbody) return;
        if (rows.length === 0) {
            tbody.innerHTML = `<tr><td colspan="100%" style="text-align: center; color: #94a3b8; padding: 20px;">No data rows found.</td></tr>`;
        } else {
            tbody.innerHTML = rows.map(r => '<tr>' + r.map(c => `<td>${this.escapeHtml(c)}</td>`).join('') + '</tr>').join('');
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

    saveState() {
        if (this.isShareMode) return;
        const state = this.panels.map(p => ({
            id: p.id, module_id: p.module_id, refresh: p.refresh, mode: p.mode
        }));
        localStorage.setItem('pfms_table_viewer', JSON.stringify(state));
    },

    loadState() {
        const saved = localStorage.getItem('pfms_table_viewer');
        if (saved) {
            try { this.panels = JSON.parse(saved); } catch(e) { this.panels = []; }
        }
    },

    renderAll() {
        const grid = document.getElementById('dashboardGrid');
        grid.innerHTML = '';
        
        this.panels.forEach(p => {
            const card = document.createElement('div');
            card.className = 'card-custom';
            card.id = p.id;
            
            // Header HTML
            let headerHtml = `
                <div class="panel-header">
                    <div class="d-flex align-items-center gap-2">
                        <span class="material-symbols-outlined text-muted">table_chart</span>
                        <h3 class="panel-title" id="title-${p.id}">${p.mode === 'config' ? 'Configure New Panel' : 'Loading Module...'}</h3>
                        <span class="badge-refresh ${p.refresh === 0 ? 'd-none' : ''}" id="refBadge-${p.id}">Auto: ${p.refresh}s</span>
                    </div>
            `;
            
            if (this.isShareMode) {
                // In share mode, no header buttons, maybe just a read-only badge
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
            
            // Body HTML
            const tplId = p.mode === 'config' ? 'tpl-panel-config' : 'tpl-panel-view';
            const tplHtml = document.getElementById(tplId).innerHTML.replace(/{ID}/g, p.id);
            
            card.innerHTML = headerHtml + `<div class="panel-body">${tplHtml}</div>`;
            grid.appendChild(card);
        });
    }
};

document.addEventListener('DOMContentLoaded', () => Dashboard.init());
</script>

</body>
</html>
