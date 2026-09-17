<?php
declare(strict_types=1);

/**
 * topology-network.php
 * Enterprise SDDC & Network Topology Map Dashboard
 * PFMS-Toolkit
 * 
 * Features:
 * - Stunning visual topology with high-res vector device icons (VM, Hypervisor, Cluster, Datacenter, Datastore, vCenter, Switch, Router)
 * - Double-ring status alert indicators with warning/critical badging (!)
 * - Native Pandora FMS Agent & Group auto-discovery
 * - Interactive Cytoscape.js & Dagre hierarchical graph engine (Air-gapped ready)
 * - Segmented device filtering, live device search with auto pan/zoom
 * - Slide-in node inspector with live metrics and device role switcher
 * - 1-Click Reference SDDC/vSphere Demo topology loader matching user reference design
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Database & Core includes
$db_connection_file = __DIR__ . '/../../includes/db-connection.php';
if (file_exists($db_connection_file)) {
    require_once $db_connection_file;
} else {
    die("Database connection core library not found.");
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$user_id = $_SESSION['id_usuario'] ?? 0;
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';
$is_standalone = isset($_GET['standalone']) || isset($_GET['embed']);

if (empty($user_id) && !$is_standalone) {
    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $pandora_base = preg_match('#^(/.*?)/(custom|customize)/panel#', $script_dir, $m) ? rtrim($m[1], '/') : '/pandora_console';
    header("Location: " . $pandora_base . "/index.php");
    exit;
}

$dynamic_breadcrumb = "PANDORA CONSOLE / CUSTOM / PANEL / DASHBOARD / TOPOLOGY NETWORK";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Topology Network - PFMS-Toolkit</title>
    
    <!-- Google Fonts Inter & Material Symbols -->
    <link rel="stylesheet" href="../../vendor/fonts/fonts.css">
    <link rel="stylesheet" href="../../vendor/fonts/inter.css">
    <link rel="stylesheet" href="../../vendor/fonts/material-symbols.css">
    <link rel="stylesheet" href="../../vendor/bootstrap/bootstrap.min.css">

    <!-- Offline Cytoscape & Dagre Engines -->
    <script src="../../vendor/cytoscape/cytoscape.min.js"></script>
    <script src="../../vendor/cytoscape/dagre.min.js"></script>
    <script src="../../vendor/cytoscape/cytoscape-dagre.min.js"></script>

    <style>
        :root {
            --primary: #004d40;
            --primary-light: #00695c;
            --primary-dark: #00332c;
            --accent-blue: #2563eb;
            --surface-bg: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-main: #334155;
            --text-muted: #64748b;
            --status-crit: #ef4444;
            --status-warn: #f59e0b;
            --status-ok: #10b981;
        }

        body, input, button, select, textarea {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--surface-bg);
            color: var(--text-main);
            margin: 0;
            padding: 0;
            overflow: hidden;
            height: 100vh;
            width: 100vw;
            display: flex;
            flex-direction: column;
        }

        /* TOP NAVBAR / HEADER */
        .top-navbar {
            height: 64px;
            background: #ffffff;
            border-bottom: 1px solid var(--border-color);
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            z-index: 20;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            background: rgba(0, 77, 64, 0.08);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .page-titles {
            display: flex;
            flex-direction: column;
        }

        .breadcrumb-text {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-muted);
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .main-title {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
            line-height: 1.2;
        }

        /* TOOLBAR FILTER CONTROLS */
        .toolbar {
            height: 52px;
            background: #ffffff;
            border-bottom: 1px solid var(--border-color);
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            z-index: 15;
            gap: 12px;
        }

        .toolbar-left, .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .group-select {
            height: 36px;
            padding: 0 12px;
            font-size: 13px;
            font-weight: 500;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: #ffffff;
            color: var(--text-main);
            outline: none;
            cursor: pointer;
            min-width: 220px;
            transition: all 0.2s ease;
        }

        .group-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(0, 77, 64, 0.15);
        }

        .search-box {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-box input {
            height: 36px;
            padding: 0 12px 0 34px;
            font-size: 13px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: #ffffff;
            color: var(--text-main);
            width: 200px;
            outline: none;
            transition: all 0.2s ease;
        }

        .search-box input:focus {
            width: 260px;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(0, 77, 64, 0.15);
        }

        .search-box .material-symbols-outlined {
            position: absolute;
            left: 9px;
            font-size: 18px;
            color: var(--text-muted);
            pointer-events: none;
        }

        /* SEGMENTED FILTER BUTTONS */
        .filter-segmented {
            display: flex;
            background: #f1f5f9;
            padding: 3px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        .filter-btn {
            border: none;
            background: transparent;
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .filter-btn.active {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .btn-custom {
            height: 36px;
            padding: 0 14px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background: #ffffff;
            color: var(--text-main);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }

        .btn-custom:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .btn-demo {
            background: linear-gradient(135deg, #004d40, #00695c);
            color: #ffffff;
            border: none;
            font-weight: 600;
        }

        .btn-demo:hover {
            background: linear-gradient(135deg, #00382f, #004d40);
            color: #ffffff;
        }

        /* STATUS BADGE COUNTERS */
        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
        }

        .badge-crit { background: rgba(239, 68, 68, 0.1); color: var(--status-crit); }
        .badge-warn { background: rgba(245, 158, 11, 0.1); color: var(--status-warn); }
        .badge-ok   { background: rgba(16, 185, 129, 0.1); color: var(--status-ok); }

        /* CANVAS AREA */
        .canvas-wrapper {
            position: relative;
            flex-grow: 1;
            width: 100%;
            height: 100%;
            background: #f8fafc;
            overflow: hidden;
        }

        #cyCanvas {
            width: 100%;
            height: 100%;
            position: absolute;
            top: 0;
            left: 0;
        }

        /* FLOATING ACTION OVERLAY */
        .canvas-controls {
            position: absolute;
            bottom: 24px;
            left: 24px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            z-index: 10;
        }

        .ctrl-btn {
            width: 36px;
            height: 36px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .ctrl-btn:hover {
            background: #f8fafc;
            color: var(--primary);
            transform: translateY(-1px);
        }

        /* SIDEBAR INSPECTOR DRAWER */
        .inspector-drawer {
            position: absolute;
            top: 0;
            right: -380px;
            width: 360px;
            height: 100%;
            background: #ffffff;
            border-left: 1px solid var(--border-color);
            box-shadow: -4px 0 16px rgba(0,0,0,0.05);
            transition: right 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 25;
            display: flex;
            flex-direction: column;
        }

        .inspector-drawer.open {
            right: 0;
        }

        .inspector-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .inspector-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .inspector-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
        }

        .inspector-close:hover {
            background: #f1f5f9;
            color: var(--text-main);
        }

        .inspector-body {
            padding: 20px;
            overflow-y: auto;
            flex-grow: 1;
        }

        .node-hero {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .node-hero-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .node-hero-info h4 {
            font-size: 15px;
            font-weight: 700;
            margin: 0 0 4px 0;
            word-break: break-all;
        }

        .node-hero-info p {
            font-size: 12px;
            color: var(--text-muted);
            margin: 0;
        }

        .metric-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 9px 0;
            border-bottom: 1px dashed #e2e8f0;
            font-size: 13px;
        }

        .metric-label {
            color: var(--text-muted);
            font-weight: 500;
        }

        .metric-val {
            font-weight: 600;
            color: var(--text-main);
        }

        .role-switcher-box {
            margin-top: 20px;
            padding: 16px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .role-switcher-box label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            display: block;
        }

        .role-select {
            width: 100%;
            height: 36px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background: #ffffff;
            font-size: 13px;
            padding: 0 10px;
            font-weight: 500;
            outline: none;
        }

        /* LOADING OVERLAY */
        .loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(2px);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 50;
            transition: opacity 0.2s ease;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 3px solid rgba(0, 77, 64, 0.1);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    <!-- 1. TOP HEADER -->
    <header class="top-navbar">
        <div class="nav-left">
            <div class="brand-icon">
                <span class="material-symbols-outlined" style="font-size:24px;">hub</span>
            </div>
            <div class="page-titles">
                <span class="breadcrumb-text"><?= htmlspecialchars($dynamic_breadcrumb) ?></span>
                <h1 class="main-title">Topology Network</h1>
            </div>
        </div>

        <div class="nav-right" style="display:flex; align-items:center; gap:12px;">
            <div id="statusBadges" style="display:flex; gap:8px;">
                <span class="badge-pill badge-crit" id="badgeCrit" title="Critical Nodes"><span class="material-symbols-outlined" style="font-size:14px;">error</span> <span id="cntCrit">0</span></span>
                <span class="badge-pill badge-warn" id="badgeWarn" title="Warning Nodes"><span class="material-symbols-outlined" style="font-size:14px;">warning</span> <span id="cntWarn">0</span></span>
                <span class="badge-pill badge-ok" id="badgeOk" title="Normal Nodes"><span class="material-symbols-outlined" style="font-size:14px;">check_circle</span> <span id="cntOk">0</span></span>
            </div>
            <button class="btn-custom btn-demo" onclick="loadReferenceDemo()" title="Load exact reference vSphere SDDC topology">
                <span class="material-symbols-outlined" style="font-size:16px;">view_quilt</span>
                Load Reference SDDC Demo
            </button>
        </div>
    </header>

    <!-- 2. TOOLBAR CONTROLS -->
    <section class="toolbar">
        <div class="toolbar-left">
            <!-- AGENT GROUP SELECTION -->
            <select id="groupSelect" class="group-select" onchange="onGroupChange(this.value)">
                <option value="">📁 All Agent Groups</option>
            </select>

            <!-- SEGMENTED FILTER -->
            <div class="filter-segmented">
                <button class="filter-btn active" onclick="setCategoryFilter('all', this)">All Devices</button>
                <button class="filter-btn" onclick="setCategoryFilter('compute', this)">Compute</button>
                <button class="filter-btn" onclick="setCategoryFilter('storage', this)">Storage</button>
                <button class="filter-btn" onclick="setCategoryFilter('network', this)">Network</button>
            </div>

            <!-- LIVE SEARCH -->
            <div class="search-box">
                <span class="material-symbols-outlined">search</span>
                <input type="text" id="deviceSearch" placeholder="Search device or IP..." oninput="onSearchInput(this.value)">
            </div>
        </div>

        <div class="toolbar-right">
            <button class="btn-custom" onclick="fitTopologyView()" title="Center and fit topology to screen">
                <span class="material-symbols-outlined" style="font-size:16px;">fit_screen</span>
                Fit View
            </button>
            <button class="btn-custom" onclick="refreshTopology()" title="Reload topology from Pandora FMS">
                <span class="material-symbols-outlined" style="font-size:16px;">refresh</span>
                Refresh
            </button>
            <button class="btn-custom" onclick="exportTopologyImage()" title="Export high resolution PNG">
                <span class="material-symbols-outlined" style="font-size:16px;">download</span>
                Export PNG
            </button>
        </div>
    </section>

    <!-- 3. TOPOLOGY GRAPH CANVAS -->
    <main class="canvas-wrapper">
        <div id="cyCanvas"></div>

        <!-- Floating Zoom Controls -->
        <div class="canvas-controls">
            <button class="ctrl-btn" onclick="zoomIn()" title="Zoom In"><span class="material-symbols-outlined" style="font-size:18px;">add</span></button>
            <button class="ctrl-btn" onclick="zoomOut()" title="Zoom Out"><span class="material-symbols-outlined" style="font-size:18px;">remove</span></button>
            <button class="ctrl-btn" onclick="fitTopologyView()" title="Fit View"><span class="material-symbols-outlined" style="font-size:18px;">crop_free</span></button>
        </div>

        <!-- Loading Overlay -->
        <div id="loadingOverlay" class="loading-overlay">
            <div class="spinner"></div>
            <p style="margin-top:14px; font-size:13px; font-weight:600; color:var(--text-muted);">Rendering SDDC Network Topology...</p>
        </div>
    </main>

    <!-- 4. SIDEBAR INSPECTOR DRAWER -->
    <aside id="inspectorDrawer" class="inspector-drawer">
        <div class="inspector-header">
            <h3 class="inspector-title">
                <span class="material-symbols-outlined" style="color:var(--primary); font-size:20px;">info</span>
                Device Inspector
            </h3>
            <button class="inspector-close" onclick="closeInspector()">
                <span class="material-symbols-outlined" style="font-size:20px;">close</span>
            </button>
        </div>
        <div class="inspector-body">
            <div class="node-hero">
                <div class="node-hero-icon" id="drawerHeroIcon">
                    <span class="material-symbols-outlined" style="font-size:28px; color:var(--primary);">computer</span>
                </div>
                <div class="node-hero-info">
                    <h4 id="drawerNodeName">Node Name</h4>
                    <p id="drawerRoleTitle">VMware vSphere VM</p>
                </div>
            </div>

            <div class="metric-row">
                <span class="metric-label">Status Health</span>
                <span class="metric-val" id="drawerStatusBadge"><span class="badge-pill badge-ok">Normal</span></span>
            </div>
            <div class="metric-row">
                <span class="metric-label">IP Address</span>
                <span class="metric-val" id="drawerIp">10.0.0.1</span>
            </div>
            <div class="metric-row">
                <span class="metric-label">Agent Group</span>
                <span class="metric-val" id="drawerGroup">Management</span>
            </div>
            <div class="metric-row">
                <span class="metric-label">Operating System</span>
                <span class="metric-val" id="drawerOs">VMware ESXi</span>
            </div>
            <div class="metric-row">
                <span class="metric-label">Active Alerts</span>
                <span class="metric-val" id="drawerAlertCount">0</span>
            </div>

            <!-- ROLE SWITCHER BOX -->
            <div class="role-switcher-box">
                <label>Classify Device Role</label>
                <select id="roleSwitcherSelect" class="role-select" onchange="onRoleOverrideChange(this.value)">
                    <option value="vm">💻 VMware vSphere VM</option>
                    <option value="hypervisor">🖥️ VMware vSphere Hypervisor</option>
                    <option value="cluster">▦ VMware vSphere Cluster</option>
                    <option value="datacenter">🏢 VMware vSphere Datacenter</option>
                    <option value="storage">🗄️ VMware vSphere Datastore</option>
                    <option value="vcenter">🖥️ VMware vSphere vCenter</option>
                    <option value="switch">🔀 Network Switch</option>
                    <option value="router">🔄 Network Router</option>
                    <option value="firewall">🛡️ Security Firewall</option>
                    <option value="server">📦 Host Server</option>
                </select>
                <p style="font-size:11px; color:var(--text-muted); margin:8px 0 0 0;">
                    Changes are saved automatically and adjust the icon and layout hierarchy.
                </p>
            </div>

            <!-- DIRECT CONSOLE LINK -->
            <div style="margin-top:20px;" id="drawerAgentLinkBox">
                <a href="#" id="drawerAgentLink" target="_blank" class="btn-custom" style="justify-content:center; width:100%; text-decoration:none; background:var(--primary); color:#ffffff;">
                    <span class="material-symbols-outlined" style="font-size:16px;">open_in_new</span>
                    View Agent in Pandora Console
                </a>
            </div>
        </div>
    </aside>

    <script>
        const API_URL = 'api-topology.php';
        let cy = null;
        let topologyData = null;
        let selectedNodeData = null;
        let currentCategoryFilter = 'all';

        // -------------------------------------------------------------
        // 1. INITIALIZATION ON DOM READY
        // -------------------------------------------------------------
        document.addEventListener('DOMContentLoaded', async () => {
            await loadGroups();
            await loadTopology();
        });

        // -------------------------------------------------------------
        // 2. LOAD AGENT GROUPS
        // -------------------------------------------------------------
        async function loadGroups() {
            try {
                const res = await fetch(`${API_URL}?api=groups`);
                const data = await res.json();
                if (data.ok && Array.isArray(data.groups)) {
                    const sel = document.getElementById('groupSelect');
                    sel.innerHTML = '<option value="">📁 All Agent Groups</option>';
                    data.groups.forEach(g => {
                        const opt = document.createElement('option');
                        opt.value = g.id;
                        opt.textContent = g.display_name;
                        sel.appendChild(opt);
                    });
                }
            } catch (err) {
                console.error('Failed to load groups:', err);
            }
        }

        // -------------------------------------------------------------
        // 3. LOAD TOPOLOGY (REAL AGENTS OR DEMO)
        // -------------------------------------------------------------
        async function loadTopology(groupId = '', search = '', isDemo = false) {
            showLoading(true);
            try {
                let url = `${API_URL}?api=topology`;
                if (isDemo) url += '&demo=1';
                if (groupId) url += `&group_id=${encodeURIComponent(groupId)}`;
                if (search) url += `&search=${encodeURIComponent(search)}`;

                const res = await fetch(url);
                const data = await res.json();
                
                if (data.ok) {
                    topologyData = data;
                    renderCytoscape(data.nodes, data.edges);
                    updateCounters(data.stats);
                } else {
                    alert(data.error || 'Failed to load topology data.');
                }
            } catch (err) {
                console.error('Error fetching topology:', err);
                alert('Connection to topology API failed.');
            } finally {
                showLoading(false);
            }
        }

        async function loadReferenceDemo() {
            document.getElementById('groupSelect').value = '';
            document.getElementById('deviceSearch').value = '';
            await loadTopology('', '', true);
        }

        function refreshTopology() {
            const gid = document.getElementById('groupSelect').value;
            const q = document.getElementById('deviceSearch').value;
            loadTopology(gid, q);
        }

        function onGroupChange(gid) {
            const q = document.getElementById('deviceSearch').value;
            loadTopology(gid, q);
        }

        let searchDebounce = null;
        function onSearchInput(val) {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(() => {
                if (cy) {
                    const query = val.toLowerCase().trim();
                    if (!query) {
                        cy.nodes().style('opacity', 1);
                        cy.edges().style('opacity', 1);
                        return;
                    }
                    cy.batch(() => {
                        cy.nodes().forEach(node => {
                            const name = (node.data('name') || '').toLowerCase();
                            const ip = (node.data('ip') || '').toLowerCase();
                            const match = name.includes(query) || ip.includes(query);
                            node.style('opacity', match ? 1 : 0.15);
                        });
                    });
                    const firstMatch = cy.nodes().filter(n => (n.data('name') || '').toLowerCase().includes(query))[0];
                    if (firstMatch) {
                        cy.animate({ center: { eles: firstMatch }, zoom: 1.2 }, { duration: 400 });
                    }
                }
            }, 250);
        }

        // -------------------------------------------------------------
        // 4. VECTOR SVG GENERATOR FOR CRISP SDDC ICONS
        // -------------------------------------------------------------
        function buildNodeSvg(role, status, alertCount) {
            // Colors
            const isCrit = (status === 'critical');
            const isWarn = (status === 'warning');
            
            const ringColor = isCrit ? '#ef4444' : (isWarn ? '#f59e0b' : '#cbd5e1');
            const ringWidth = (isCrit || isWarn) ? '3.5' : '2';
            const iconFill = '#1e293b';

            let iconPath = '';
            switch (role) {
                case 'vm':
                    // Laptop
                    iconPath = `
                        <rect x="23" y="24" width="26" height="17" rx="2" fill="none" stroke="${iconFill}" stroke-width="2.2" />
                        <line x1="26" y1="36" x2="46" y2="36" stroke="${iconFill}" stroke-width="1.5" />
                        <path d="M18 43 L54 43 C55 43 56 44 56 45 L56 46 L16 46 L16 45 C16 44 17 43 18 43 Z" fill="${iconFill}" />
                    `;
                    break;
                case 'hypervisor':
                case 'server':
                    // Server Rack Chassis
                    iconPath = `
                        <rect x="21" y="22" width="30" height="28" rx="2.5" fill="${iconFill}" />
                        <line x1="25" y1="28" x2="43" y2="28" stroke="#ffffff" stroke-width="2" stroke-linecap="round" />
                        <line x1="25" y1="36" x2="43" y2="36" stroke="#ffffff" stroke-width="2" stroke-linecap="round" />
                        <line x1="25" y1="44" x2="43" y2="44" stroke="#ffffff" stroke-width="2" stroke-linecap="round" />
                        <circle cx="47" cy="28" r="1.2" fill="#10b981" />
                        <circle cx="47" cy="36" r="1.2" fill="#10b981" />
                        <circle cx="47" cy="44" r="1.2" fill="#10b981" />
                    `;
                    break;
                case 'cluster':
                    // 3x3 Grid
                    iconPath = `
                        <rect x="22" y="22" width="7" height="7" rx="1" fill="${iconFill}" />
                        <rect x="32.5" y="22" width="7" height="7" rx="1" fill="${iconFill}" />
                        <rect x="43" y="22" width="7" height="7" rx="1" fill="${iconFill}" />
                        <rect x="22" y="32.5" width="7" height="7" rx="1" fill="${iconFill}" />
                        <rect x="32.5" y="32.5" width="7" height="7" rx="1" fill="${iconFill}" />
                        <rect x="43" y="32.5" width="7" height="7" rx="1" fill="${iconFill}" />
                        <rect x="22" y="43" width="7" height="7" rx="1" fill="${iconFill}" />
                        <rect x="32.5" y="43" width="7" height="7" rx="1" fill="${iconFill}" />
                        <rect x="43" y="43" width="7" height="7" rx="1" fill="${iconFill}" />
                    `;
                    break;
                case 'datacenter':
                    // Building
                    iconPath = `
                        <path d="M25 50 L25 24 L47 24 L47 50 Z" fill="none" stroke="${iconFill}" stroke-width="2.5" />
                        <line x1="31" y1="29" x2="33" y2="29" stroke="${iconFill}" stroke-width="2.2" stroke-linecap="round" />
                        <line x1="39" y1="29" x2="41" y2="29" stroke="${iconFill}" stroke-width="2.2" stroke-linecap="round" />
                        <line x1="31" y1="35" x2="33" y2="35" stroke="${iconFill}" stroke-width="2.2" stroke-linecap="round" />
                        <line x1="39" y1="35" x2="41" y2="35" stroke="${iconFill}" stroke-width="2.2" stroke-linecap="round" />
                        <line x1="31" y1="41" x2="33" y2="41" stroke="${iconFill}" stroke-width="2.2" stroke-linecap="round" />
                        <line x1="39" y1="41" x2="41" y2="41" stroke="${iconFill}" stroke-width="2.2" stroke-linecap="round" />
                        <rect x="33" y="44" width="6" height="6" fill="${iconFill}" />
                    `;
                    break;
                case 'storage':
                    // Disk Stack
                    iconPath = `
                        <ellipse cx="36" cy="25" rx="16" ry="5.5" fill="${iconFill}" />
                        <path d="M20 25 v7 c0 3 7.2 5.5 16 5.5 s16 -2.5 16 -5.5 v-7" fill="${iconFill}" />
                        <path d="M20 35 v7 c0 3 7.2 5.5 16 5.5 s16 -2.5 16 -5.5 v-7" fill="${iconFill}" />
                    `;
                    break;
                case 'vcenter':
                    // Monitor Console
                    iconPath = `
                        <rect x="20" y="21" width="32" height="23" rx="2" fill="none" stroke="${iconFill}" stroke-width="2.5" />
                        <rect x="24" y="25" width="13" height="9" fill="${iconFill}" />
                        <line x1="36" y1="44" x2="36" y2="49" stroke="${iconFill}" stroke-width="2.5" />
                        <line x1="28" y1="49" x2="44" y2="49" stroke="${iconFill}" stroke-width="2.5" stroke-linecap="round" />
                    `;
                    break;
                case 'switch':
                    // Network Switch
                    iconPath = `
                        <rect x="20" y="27" width="32" height="18" rx="3" fill="${iconFill}" />
                        <path d="M26 36 h20 M30 32 l-4 4 l4 4 M42 32 l4 4 l-4 4" stroke="#ffffff" stroke-width="2" fill="none" stroke-linecap="round" />
                    `;
                    break;
                case 'router':
                    // Router Disc
                    iconPath = `
                        <circle cx="36" cy="36" r="15" fill="none" stroke="${iconFill}" stroke-width="2.5" />
                        <path d="M30 36 h12 M36 30 v12" stroke="${iconFill}" stroke-width="2.5" stroke-linecap="round" />
                    `;
                    break;
                default:
                    // Generic Server Box
                    iconPath = `
                        <rect x="22" y="22" width="28" height="28" rx="4" fill="${iconFill}" />
                        <circle cx="36" cy="36" r="4" fill="#ffffff" />
                    `;
            }

            // Alert Badge with '!'
            let badgeSvg = '';
            if (isCrit || isWarn || alertCount > 0) {
                const badgeBg = isCrit ? '#ef4444' : '#f59e0b';
                badgeSvg = `
                    <circle cx="56" cy="16" r="8.5" fill="${badgeBg}" stroke="#ffffff" stroke-width="2" />
                    <text x="56" y="20" font-family="'Inter', sans-serif" font-size="10" font-weight="900" fill="#ffffff" text-anchor="middle">!</text>
                `;
            }

            const svg = `
                <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 72 72">
                    <!-- Outer Alert Ring -->
                    <circle cx="36" cy="36" r="33" fill="#ffffff" stroke="${ringColor}" stroke-width="${ringWidth}" />
                    <!-- Device Icon -->
                    ${iconPath}
                    <!-- Alert Badge -->
                    ${badgeSvg}
                </svg>
            `;

            return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg.trim());
        }

        // -------------------------------------------------------------
        // 5. CYTOSCAPE RENDERING ENGINE
        // -------------------------------------------------------------
        function renderCytoscape(nodes, edges) {
            const elements = [];

            // Add Nodes
            nodes.forEach(n => {
                const svgUri = buildNodeSvg(n.role, n.status, n.alert_count);
                const displayLabel = `${n.name}\n${n.role_title || ''}`;
                elements.push({
                    group: 'nodes',
                    data: {
                        id: n.id,
                        name: n.name,
                        role: n.role,
                        role_title: n.role_title,
                        status: n.status,
                        alert_count: n.alert_count,
                        ip: n.ip,
                        group: n.group,
                        agent_id: n.agent_id || 0,
                        os: n.os || 'N/A',
                        svg_uri: svgUri,
                        display_label: displayLabel
                    }
                });
            });

            // Add Edges
            edges.forEach(e => {
                elements.push({
                    group: 'edges',
                    data: {
                        id: e.id,
                        source: e.source,
                        target: e.target
                    }
                });
            });

            if (cy) {
                cy.destroy();
            }

            cy = cytoscape({
                container: document.getElementById('cyCanvas'),
                elements: elements,
                layout: {
                    name: 'dagre',
                    rankDir: 'LR',
                    nodeSep: 60,
                    rankSep: 110,
                    edgeSep: 30,
                    padding: 60
                },
                style: [
                    {
                        selector: 'node',
                        style: {
                            'width': 64,
                            'height': 64,
                            'shape': 'ellipse',
                            'background-color': '#ffffff',
                            'background-image': 'data(svg_uri)',
                            'background-fit': 'contain',
                            'background-clip': 'none',
                            'label': 'data(display_label)',
                            'text-wrap': 'wrap',
                            'text-max-width': 180,
                            'text-valign': 'bottom',
                            'text-margin-y': 8,
                            'font-family': 'Inter, sans-serif',
                            'font-size': 11,
                            'font-weight': 600,
                            'color': '#0f172a',
                            'text-background-opacity': 0.85,
                            'text-background-color': '#ffffff',
                            'text-background-padding': 3,
                            'text-background-shape': 'roundrectangle',
                            'text-border-opacity': 0.4,
                            'text-border-color': '#e2e8f0',
                            'text-border-width': 1,
                            'cursor': 'pointer',
                            'transition-property': 'transform, opacity',
                            'transition-duration': '0.15s'
                        }
                    },
                    {
                        selector: 'node:selected',
                        style: {
                            'border-width': 3,
                            'border-color': '#004d40',
                            'border-opacity': 0.8
                        }
                    },
                    {
                        selector: 'edge',
                        style: {
                            'width': 2.2,
                            'line-color': '#94a3b8',
                            'curve-style': 'bezier',
                            'target-arrow-shape': 'triangle',
                            'target-arrow-color': '#94a3b8',
                            'arrow-scale': 0.8,
                            'opacity': 0.8
                        }
                    },
                    {
                        selector: 'edge:selected',
                        style: {
                            'line-color': '#004d40',
                            'target-arrow-color': '#004d40',
                            'width': 3.5,
                            'opacity': 1
                        }
                    }
                ],
                wheelSensitivity: 0.25,
                minZoom: 0.2,
                maxZoom: 3.5
            });

            // Node Click Event -> Open Inspector Drawer
            cy.on('tap', 'node', function(evt) {
                const node = evt.target;
                openInspector(node.data());
            });

            // Canvas Click (background) -> Close Drawer
            cy.on('tap', function(evt) {
                if (evt.target === cy) {
                    closeInspector();
                }
            });
        }

        // -------------------------------------------------------------
        // 6. INSPECTOR DRAWER CONTROLS
        // -------------------------------------------------------------
        function openInspector(data) {
            selectedNodeData = data;
            
            document.getElementById('drawerNodeName').textContent = data.name;
            document.getElementById('drawerRoleTitle').textContent = data.role_title || 'Device';
            document.getElementById('drawerIp').textContent = data.ip || 'N/A';
            document.getElementById('drawerGroup').textContent = data.group || 'N/A';
            document.getElementById('drawerOs').textContent = data.os || 'N/A';
            document.getElementById('drawerAlertCount').textContent = data.alert_count || 0;

            const badgeElem = document.getElementById('drawerStatusBadge');
            if (data.status === 'critical') {
                badgeElem.innerHTML = '<span class="badge-pill badge-crit">Critical</span>';
            } else if (data.status === 'warning') {
                badgeElem.innerHTML = '<span class="badge-pill badge-warn">Warning</span>';
            } else {
                badgeElem.innerHTML = '<span class="badge-pill badge-ok">Normal</span>';
            }

            // Role dropdown selection
            const selRole = document.getElementById('roleSwitcherSelect');
            if (selRole) {
                selRole.value = data.role || 'vm';
            }

            // Agent link
            const linkBox = document.getElementById('drawerAgentLinkBox');
            if (data.agent_id && data.agent_id > 0) {
                linkBox.style.display = 'block';
                document.getElementById('drawerAgentLink').href = `../../index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${data.agent_id}`;
            } else {
                linkBox.style.display = 'none';
            }

            document.getElementById('inspectorDrawer').classList.add('open');
        }

        function closeInspector() {
            document.getElementById('inspectorDrawer').classList.remove('open');
            if (cy) {
                cy.nodes().unselect();
            }
        }

        async function onRoleOverrideChange(newRole) {
            if (!selectedNodeData || !selectedNodeData.id) return;
            try {
                const res = await fetch(`${API_URL}?api=save_role`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ node_id: selectedNodeData.id, role: newRole })
                });
                const data = await res.json();
                if (data.ok) {
                    // Update in-memory and cy element
                    selectedNodeData.role = newRole;
                    const nodeEl = cy.getElementById(selectedNodeData.id);
                    if (nodeEl) {
                        const newSvg = buildNodeSvg(newRole, selectedNodeData.status, selectedNodeData.alert_count);
                        nodeEl.data('role', newRole);
                        nodeEl.data('svg_uri', newSvg);
                    }
                } else {
                    alert(data.error || 'Failed to update role.');
                }
            } catch (err) {
                console.error('Role override failed:', err);
            }
        }

        // -------------------------------------------------------------
        // 7. FILTER CATEGORY (ALL, COMPUTE, STORAGE, NETWORK)
        // -------------------------------------------------------------
        function setCategoryFilter(cat, btn) {
            currentCategoryFilter = cat;
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            if (!cy) return;

            cy.batch(() => {
                cy.nodes().forEach(node => {
                    const r = node.data('role');
                    let show = true;
                    if (cat === 'compute') {
                        show = ['vm', 'hypervisor', 'cluster', 'datacenter', 'server'].includes(r);
                    } else if (cat === 'storage') {
                        show = ['storage'].includes(r);
                    } else if (cat === 'network') {
                        show = ['switch', 'router', 'firewall', 'vcenter'].includes(r);
                    }
                    node.style('display', show ? 'element' : 'none');
                });
            });

            fitTopologyView();
        }

        // -------------------------------------------------------------
        // 8. CANVAS CONTROLS (FIT, ZOOM, EXPORT)
        // -------------------------------------------------------------
        function fitTopologyView() {
            if (cy) {
                cy.fit(null, 50);
            }
        }

        function zoomIn() {
            if (cy) cy.zoom(cy.zoom() * 1.25);
        }

        function zoomOut() {
            if (cy) cy.zoom(cy.zoom() * 0.8);
        }

        function exportTopologyImage() {
            if (!cy) return;
            const pngBlob = cy.png({ bg: '#ffffff', full: true, scale: 2 });
            const link = document.createElement('a');
            link.download = `sddc-topology-${Date.now()}.png`;
            link.href = pngBlob;
            link.click();
        }

        function updateCounters(stats) {
            if (!stats) return;
            document.getElementById('cntCrit').textContent = stats.critical || 0;
            document.getElementById('cntWarn').textContent = stats.warning || 0;
            document.getElementById('cntOk').textContent = stats.normal || 0;
        }

        function showLoading(show) {
            document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
        }
    </script>
</body>
</html>
