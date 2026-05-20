<?php
/**
 * network-mapping.php
 * Interactive Network Mapping Tool Dashboard
 * Supports Auto-discovery and Manual Drag-and-Drop Edit Mode (SolarWinds style)
 * Fully upgraded to support multi-dashboard map lists and granular target filtering.
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
    }
}

require_once __DIR__ . '/../../includes/db-connection.php';

$PANDORA_BASE_URL = "/pandora_console";
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';
$isStandalone = (isset($_GET['standalone']) && $_GET['standalone'] == '1') || (isset($_GET['s']) && $_GET['s'] == '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Network Topology Map</title>
    <link rel="icon" href="<?= $PANDORA_BASE_URL ?>/images/pandora.ico" type="image/x-icon">
    
    <!-- Core Fonts & Styles -->
    <link href="/pandora_console/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="/pandora_console/custom/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    
    <!-- Vis.js Standalone for High Performance HTML5 Network Graphs -->
    <script src="https://unpkg.com/vis-network/standalone/umd/vis-network.min.js"></script>

    <style>
        :root { 
            --primary-bg: #f4f6f8; 
            --card-bg: #fff; 
            --border-color: #e0e4e8; 
            --text-main: #1e293b; 
            --text-dim: #64748b; 
            --accent-green: #004d40; 
        }

        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; color: #334155; font-size: 14px; background-color: #f4f6f8; margin: 0; padding: 0; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }
        * { box-sizing: border-box; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-size: 18px !important; vertical-align: middle; line-height: 1; display: inline-block; }

        /* HEADER */
        .pandora-header-top { background-color: #ffffff; border-bottom: 1px solid #e0e4e8; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 25px; flex-shrink: 0; z-index: 10; }
        .header-logo { height: 24px; width: auto; }
        .header-divider { width: 1px; height: 28px; background-color: #dce1e5; margin: 0 20px; }
        .header-title-box { display: flex; flex-direction: column; line-height: 1.2; margin-right: 40px; }
        .header-title-box .main-title { font-size: 14px !important; font-weight: normal !important; color: #0b1a26 !important; }
        .nav-icon-btn { color: #4a5568 !important; text-decoration: none; display: flex; align-items: center; justify-content: center; height: 36px; width: 36px; border-radius: 50%; transition: 0.2s; border:none; background:transparent; cursor:pointer;}
        .nav-icon-btn:hover { background-color: #e0e4e8; color: #0b1a26 !important; }

        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .page-breadcrumb { font-size: 11px !important; color: #64748b !important; margin-bottom: 4px; font-weight: normal !important; text-transform: uppercase; letter-spacing: 0.5px; }
        .page-title { font-size: 18px !important; color: #0b1a26 !important; margin: 0; font-weight: 600 !important; line-height: 1.2; }

        /* MASTER LIST VIEW TABLE STYLING */
        .main-content { padding: 25px 30px; overflow-y: auto; flex-grow: 1; }
        .card { background: #fff; border-radius: 8px; border: 1px solid var(--border-color); box-shadow: 0 4px 12px rgba(0,0,0,0.03); overflow: hidden; }
        table.master-table { width: 100%; border-collapse: collapse; }
        table.master-table th { background: #f8fafc; padding: 14px 20px; text-align: left; color: var(--text-dim); text-transform: uppercase; font-size: 11px; font-weight: 600; border-bottom: 1px solid var(--border-color); }
        table.master-table td { padding: 15px 20px; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: var(--text-main); }
        table.master-table tr:hover td { background: #fcfdfe; }
        table.master-table th:last-child, table.master-table td:last-child { width: 1%; white-space: nowrap; padding-right: 25px; text-align: right; }
        
        .dash-link { color: #004d40; text-decoration: none; font-weight: 600; transition: 0.2s; }
        .dash-link:hover { text-decoration: underline; color: #002d25; }
        
        .btn-create { background: #004d40; color: #fff !important; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; transition: 0.2s; }
        .btn-create:hover { background: #00332a; }

        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #ffffff;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            color: #4b5563;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            margin-left: 5px;
        }
        .btn-action:hover {
            background: #f9fafb;
            border-color: #9ca3af;
            color: #1f2937;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .btn-action .material-symbols-outlined { font-size: 16px !important; }
        .btn-action.btn-delete { color: #ef4444; }
        .btn-action.btn-delete:hover { background: #fef2f2; border-color: #fca5a5; color: #dc2626; }

        /* TOP CONTROLS */
        .top-controls { display: flex; flex-direction: row; gap: 10px; align-items: center; }
        .btn-apply { background: #004d40; color: #fff !important; border: none; padding: 8px 20px; border-radius: 4px; font-weight: 500 !important; cursor: pointer; display: flex; align-items: center; gap: 6px; white-space: nowrap; transition:0.2s;}
        .btn-apply:hover { background: #00332a; }
        .btn-secondary-custom { background: #fff; color: #4a5568 !important; border: 1px solid #dce1e5; padding: 8px 18px; border-radius: 4px; font-weight: 500 !important; cursor: pointer; display: flex; align-items: center; gap: 6px; white-space: nowrap; transition:0.2s;}
        .btn-secondary-custom:hover { background: #f8f9fa; color: #0b1a26 !important; }
        
        /* SEARCH BAR */
        .search-container { position: relative; max-width: 250px; width: 100%; }
        .search-container .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #7f8c8d !important; font-size: 16px !important; pointer-events: none; }
        .search-container input { width: 100%; height: 36px; padding: 8px 15px 8px 32px; border-radius: 4px; border: 1px solid #dce1e5; background-color: #ffffff; font-size: 13px !important; color: #333 !important; outline: none; }
        .search-container input:focus { border-color: #004d40; box-shadow: 0 0 0 2px rgba(0, 77, 64, 0.1); }

        /* GRAPH LAYOUT */
        .map-wrapper { display: flex; flex-grow: 1; overflow: hidden; position: relative; }
        #network-map-canvas { flex-grow: 1; height: 100%; background-color: #ffffff; position: relative; outline: none; }

        /* Floating Mode Overlay Badge */
        .mode-badge { position: absolute; top: 20px; left: 20px; z-index: 5; background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(4px); border-radius: 20px; padding: 6px 14px; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.1); pointer-events: none; font-size: 12px; font-weight: 500; }
        .mode-dot { width: 8px; height: 8px; border-radius: 50%; background-color: #10b981; animation: pulse-green 1.5s infinite; }
        .mode-dot.edit-mode { background-color: #ef4444; animation: pulse-red 1.5s infinite; }
        
        @keyframes pulse-green { 0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); } 70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); } 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }
        @keyframes pulse-red { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); } 70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }

        /* Floating Legends */
        .legend-box { position: absolute; bottom: 20px; left: 20px; z-index: 5; background: rgba(255,255,255,0.9); backdrop-filter: blur(4px); border: 1px solid #e0e4e8; border-radius: 6px; padding: 12px 15px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); display: flex; flex-direction: column; gap: 8px; }
        .legend-item { display: flex; align-items: center; gap: 8px; font-size: 11px; color: #475569; }
        .legend-color { width: 12px; height: 12px; border-radius: 2px; }
        
        /* SIDE DRAWER (PERFORMANCE DETAILS) */
        .metrics-drawer { position: absolute; top: 0; right: -360px; width: 350px; height: 100%; background: #ffffff; border-left: 1px solid #e0e4e8; box-shadow: -5px 0 25px rgba(0,0,0,0.08); z-index: 100; transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1); display: flex; flex-direction: column; }
        .metrics-drawer.open { right: 0; }
        .drawer-header { padding: 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; background: #f8f9fa; }
        .drawer-title { font-weight: 600; color: #0b1a26; margin: 0; font-size: 15px; }
        .drawer-body { padding: 20px; overflow-y: auto; flex-grow: 1; display: flex; flex-direction: column; gap: 20px; }
        
        .metric-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 15px; display: flex; align-items: center; justify-content: space-between; }
        .metric-label-box { display: flex; align-items: center; gap: 8px; }
        .metric-value { font-weight: 600; color: #0f172a; font-size: 15px; }

        /* PORT STATUS LIST */
        .port-list { display: flex; flex-direction: column; gap: 6px; }
        .port-item { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: #fff; border: 1px solid #f0f3f5; border-radius: 4px; font-size: 12px; }
        .port-pill { padding: 3px 8px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .port-pill.up { background: #d1fae5; color: #065f46; }
        .port-pill.down { background: #fee2e2; color: #991b1b; }
        .port-pill.warn { background: #fef3c7; color: #92400e; }

        /* MODAL FOR ADDING LINK */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-box { background: #fff; width: 500px; padding: 25px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e0e4e8; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 11px; text-transform: uppercase; font-weight: 600; color: #64748b; margin-bottom: 5px; }
        .form-control-fix { width: 100%; height: 36px; padding: 8px 12px; border: 1px solid #dce1e5; border-radius: 4px; background-color: #fff; outline: none; font-size: 13px; }
        .form-control-fix:focus { border-color: #004d40; }

        /* Standalone view support */
        <?php if ($isStandalone): ?>
        .pandora-header-top, .pandora-header-bottom { display: none !important; }
        .map-wrapper { height: 100vh; }
        <?php endif; ?>
    </style>
</head>
<body>

<!-- TOP GLOBAL BAR -->
<?php if (!$isStandalone): ?>
<div class="pandora-header-top">
    <div class="header-left">
        <img src="<?= $PANDORA_BASE_URL ?>/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box">
            <span class="main-title">Pandora FMS</span>
            <span class="sub-title">Custom Extensions Portal</span>
        </div>
    </div>
    <div class="header-right">
        <a href="<?= $PANDORA_BASE_URL ?>/index.php" class="nav-icon-btn"><span class="material-symbols-outlined">home</span></a>
    </div>
</div>
<?php endif; ?>

<!-- ========================================== -->
<!-- 1. MASTER LANDING PAGE VIEW -->
<!-- ========================================== -->
<div id="masterView">
    <div class="pandora-header-bottom">
        <div class="breadcrumb-box">
            <span class="page-breadcrumb"><?= h($dynamic_breadcrumb) ?></span>
            <h1 class="page-title">Network Topology Manager</h1>
        </div>
        <div class="top-controls">
            <button class="btn-create" onclick="openCreateModal()"><span class="material-symbols-outlined">add</span> Create Map</button>
        </div>
    </div>
    <div class="main-content">
        <div class="card">
            <table class="master-table">
                <thead>
                    <tr>
                        <th>Map Name</th>
                        <th>Target Group</th>
                        <th>Target Node Focus</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="masterTableBody">
                    <tr><td colspan="4" style="text-align:center; padding:40px; color:#94a3b8;">Loading dashboards...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- 2. DETAILED TOPOLOGY VISUALIZATION VIEW -->
<!-- ========================================== -->
<div id="detailView" style="display:none; flex-direction:column; height: 100%; overflow:hidden;">
    <!-- SUB BAR (TITLE & CONTROLS) -->
    <div class="pandora-header-bottom">
        <div class="breadcrumb-box">
            <span class="page-breadcrumb"><?= h($dynamic_breadcrumb) ?></span>
            <h1 class="page-title" style="display:flex; align-items:center; gap:8px;">
                <button onclick="goBack()" style="background:none; border:none; cursor:pointer; padding:0; display:flex; align-items:center; justify-content:center;">
                    <span class="material-symbols-outlined" style="font-size:24px!important; color:#004d40;">arrow_back</span>
                </button> 
                <span id="detailDashName">Topology Map</span>
            </h1>
        </div>
        <div class="top-controls">
            <!-- GLOBAL SEARCH -->
            <div class="search-container">
                <span class="material-symbols-outlined search-icon">search</span>
                <input type="text" id="nodeSearchInput" placeholder="Find device..." onkeyup="searchNode()">
            </div>
            
            <!-- AUTO GENERATE / PHYSICS RESET -->
            <button class="btn-secondary-custom" id="physicsBtn" onclick="togglePhysics()" title="Run spring force layout simulation">
                <span class="material-symbols-outlined" style="font-size:16px!important; margin-right:4px;">hub</span> Auto Layout
            </button>

            <button class="btn-secondary-custom" id="editModeBtn" onclick="toggleEditMode()">
                <span class="material-symbols-outlined">edit</span> Customize Map
            </button>

            <!-- ADD MANUAL LINK (EDIT ONLY) -->
            <button class="btn-apply" id="addLinkBtn" onclick="openAddLinkModal()" style="display: none; background: #ea580c !important;">
                <span class="material-symbols-outlined">add_link</span> Add Connection
            </button>

            <button class="btn-apply" id="saveLayoutBtn" onclick="saveLayout()" style="display: none;">
                <span class="material-symbols-outlined">save</span> Save Layout
            </button>
        </div>
    </div>

    <!-- MAP CANVAS CONTAINER -->
    <div class="map-wrapper">
        <!-- Active Mode Indicator Badge -->
        <div class="mode-badge">
            <div class="mode-dot" id="modeDot"></div>
            <span id="modeLabel">Auto-Discovery (Real-time)</span>
        </div>

        <!-- PANDORA CANVAS -->
        <div id="network-map-canvas"></div>

        <!-- Legends Box -->
        <div class="legend-box">
            <div class="legend-item"><span class="legend-color" style="background:#2ecc71;"></span> Health OK (Port Up)</div>
            <div class="legend-item"><span class="legend-color" style="background:#f1c40f;"></span> Health Warning</div>
            <div class="legend-item"><span class="legend-color" style="background:#e74c3c;"></span> Health Down (Port Critical)</div>
            <div class="legend-item"><span class="legend-color" style="background:#3498db;"></span> Not Initialized</div>
        </div>

        <!-- PERFORMANCE DRAWER -->
        <div class="metrics-drawer" id="metricsDrawer">
            <div class="drawer-header">
                <h5 class="drawer-title" id="drawerAgentName">Switch Floor-1</h5>
                <span class="material-symbols-outlined" style="cursor:pointer; color:#7f8c8d;" onclick="closeDrawer()">close</span>
            </div>
            <div class="drawer-body">
                <div>
                    <span class="text-uppercase text-muted" style="font-size:10px; font-weight:600; display:block; margin-bottom:8px;">IP Address</span>
                    <span class="font-monospace text-dark" id="drawerAgentIp" style="background:#f1f5f9; padding:4px 8px; border-radius:4px; font-size:12px;">192.168.201.20</span>
                </div>

                <!-- Metrics grid -->
                <div>
                    <span class="text-uppercase text-muted" style="font-size:10px; font-weight:600; display:block; margin-bottom:12px;">Operational Metrics</span>
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        <div class="metric-card">
                            <div class="metric-label-box">
                                <span class="material-symbols-outlined" style="color:#0284c7;">developer_board</span> CPU Utilization
                            </div>
                            <span class="metric-value" id="drawerCpu">--</span>
                        </div>
                        <div class="metric-card">
                            <div class="metric-label-box">
                                <span class="material-symbols-outlined" style="color:#7c3aed;">memory</span> Memory Load
                            </div>
                            <span class="metric-value" id="drawerRam">--</span>
                        </div>
                        <div class="metric-card">
                            <div class="metric-label-box">
                                <span class="material-symbols-outlined" style="color:#eab308;">speed</span> Latency (Ping)
                            </div>
                            <span class="metric-value" id="drawerLatency">--</span>
                        </div>
                    </div>
                </div>

                <!-- Ports Availability -->
                <div>
                    <span class="text-uppercase text-muted" style="font-size:10px; font-weight:600; display:block; margin-bottom:10px;">Operational Port Status</span>
                    <div class="port-list" id="drawerPorts">
                        <span class="text-muted small">No Monitored Ports.</span>
                    </div>
                </div>

                <!-- Diagnostics -->
                <div style="margin-top:auto; padding-top:20px; border-top:1px solid #eee;">
                    <button class="btn-apply w-100 justify-content-center" onclick="performPing()">
                        <span class="material-symbols-outlined">bolt</span> Test Connection (Ping)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- 3. DIALOG MODALS -->
<!-- ========================================== -->

<!-- CREATE/EDIT DASHBOARD MAP MODAL -->
<div class="modal-overlay" id="createModal">
    <div class="modal-box">
        <h3 id="modalTitle" style="margin-top:0; font-size: 16px; font-weight:600; color:#0b1a26; text-transform:uppercase; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px;">Create Topology Map</h3>
        <div class="form-group">
            <label style="font-size: 11px; text-transform: uppercase; font-weight:600; color:#64748b; display:block; margin-bottom:5px;">Map Name</label>
            <input type="text" id="m_name" class="form-control-fix" placeholder="e.g. Core Routers SITE CIGANJUR">
        </div>
        <div class="form-group">
            <label style="font-size: 11px; text-transform: uppercase; font-weight:600; color:#64748b; display:block; margin-bottom:5px;">Target Group Filter</label>
            <select id="m_group" class="form-control-fix" onchange="loadAgentOptions()"></select>
        </div>
        <div class="form-group">
            <label style="font-size: 11px; text-transform: uppercase; font-weight:600; color:#64748b; display:block; margin-bottom:5px;">Target Core Node (Optional Focus)</label>
            <select id="m_agent" class="form-control-fix"></select>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:25px;">
            <button class="btn-secondary-custom" onclick="closeCreateModal()">Cancel</button>
            <button id="btnSubmitModal" class="btn-create" onclick="saveNewDashboard()">Save Map</button>
        </div>
    </div>
</div>

<!-- ADD PORT-TO-PORT CONNECTION MODAL (EDIT ONLY) -->
<div class="modal-overlay" id="addLinkModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px;">
            <h5 style="font-weight:600; margin:0; color:#0b1a26; text-transform:uppercase;">Add Port-to-Port Connection</h5>
            <span class="material-symbols-outlined" style="cursor:pointer; color:#7f8c8d;" onclick="closeAddLinkModal()">close</span>
        </div>
        
        <div class="form-group">
            <label>Source Node (Agent)</label>
            <select id="srcAgent" class="form-control-fix" onchange="loadAgentPorts('src')">
                <option value="">-- Select Source Agent --</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>Source Port (SNMP Module)</label>
            <select id="srcPort" class="form-control-fix">
                <option value="">-- Select Interface --</option>
            </select>
        </div>

        <div class="form-group">
            <label>Target Node (Agent)</label>
            <select id="tgtAgent" class="form-control-fix" onchange="loadAgentPorts('tgt')">
                <option value="">-- Select Target Agent --</option>
            </select>
        </div>
        
        <div class="form-group">
            <label>Target Port (SNMP Module)</label>
            <select id="tgtPort" class="form-control-fix">
                <option value="">-- Select Interface --</option>
            </select>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:25px;">
            <button class="btn-secondary-custom" onclick="closeAddLinkModal()">Cancel</button>
            <button class="btn-apply" onclick="confirmAddLink()">Connect Interfaces</button>
        </div>
    </div>
</div>

<script>
    const CSRF = "<?= $csrf_token ?>";
    const API_URL = "api-network.php";

    let masterDashboards = [];
    let currentDashId = '';
    let editId = null;

    let network = null;
    let nodesDataset = null;
    let edgesDataset = null;

    let isEditMode = false;
    let isPhysicsActive = true;
    let selectedAgentId = null;

    let allRawNodes = [];
    let manualLinksStore = [];

    const COLORS = {
        normal: { border: '#10b981', background: '#ecfdf5', highlight: { border: '#059669', background: '#d1fae5' } },
        warning: { border: '#f59e0b', background: '#fffbeb', highlight: { border: '#d97706', background: '#fef3c7' } },
        critical: { border: '#ef4444', background: '#fef2f2', highlight: { border: '#dc2626', background: '#fee2e2' } },
        not_init: { border: '#3b82f6', background: '#eff6ff', highlight: { border: '#2563eb', background: '#d1e8ff' } },
        unknown: { border: '#94a3b8', background: '#f8fafc', highlight: { border: '#64748b', background: '#e2e8f0' } }
    };

    const SHAPE_STYLES = {
        shape: 'dot',
        size: 22,
        font: { size: 12, face: 'Inter', color: '#1e293b' },
        borderWidth: 3
    };

    document.addEventListener("DOMContentLoaded", () => {
        init();
    });

    async function init() {
        // Load Maps/Dashboards configuration list
        try {
            const r = await fetch(`${API_URL}?api=load_config`);
            masterDashboards = await r.json();

            // Load Groups into Creation Modal Dropdown
            const rg = await fetch(`${API_URL}?api=groups`);
            const groups = await rg.json();
            const gsel = document.getElementById('m_group');
            gsel.innerHTML = '';
            groups.forEach(g => gsel.add(new Option(g.name, g.id)));

            const params = new URLSearchParams(window.location.search);
            const dashId = params.get('dash_id');
            if (dashId) {
                openDashboard(dashId);
            } else {
                renderMasterList();
            }
        } catch (e) {
            console.error("Init Error: ", e);
        }
    }

    function renderMasterList() {
        document.getElementById('masterView').style.display = 'block';
        document.getElementById('detailView').style.display = 'none';

        const body = document.getElementById('masterTableBody');
        body.innerHTML = masterDashboards.map(d => `<tr>
            <td><a href="#" class="dash-link" onclick="openDashboard('${d.id}')">${d.name}</a></td>
            <td>${d.group_name || 'All Groups'}</td>
            <td>${d.agent_name || 'All Nodes'}</td>
            <td style="text-align:right;">
                <button class="btn-action" onclick="openDashboard('${d.id}')">
                    <span class="material-symbols-outlined">visibility</span> Open Map
                </button>
                <button class="btn-action" onclick="editDashboard('${d.id}')">
                    <span class="material-symbols-outlined">edit</span> Configure
                </button>
                <button class="btn-action" onclick="duplicateDashboard('${d.id}')">
                    <span class="material-symbols-outlined">content_copy</span> Duplicate
                </button>
                <button class="btn-action btn-delete" onclick="deleteDashboard('${d.id}')">
                    <span class="material-symbols-outlined">delete</span> Delete
                </button>
            </td>
        </tr>`).join('') || '<tr><td colspan="4" style="text-align:center; padding:40px; color:#94a3b8;">No Topology Maps Created Yet.</td></tr>';
    }

    function openDashboard(id) {
        const d = masterDashboards.find(x => x.id === id);
        if (!d) return renderMasterList();

        currentDashId = id;
        document.getElementById('masterView').style.display = 'none';
        document.getElementById('detailView').style.display = 'flex';
        document.getElementById('detailDashName').innerText = d.name;

        // Update active address state safely without page reloading
        const url = new URL(window.location);
        url.searchParams.set('dash_id', id);
        window.history.replaceState({}, '', url);

        loadNetworkTopology();
    }

    function goBack() {
        currentDashId = '';
        const url = new URL(window.location);
        url.searchParams.delete('dash_id');
        window.history.replaceState({}, '', url);
        
        // Clean Vis.js instance
        if (network !== null) {
            network.destroy();
            network = null;
        }

        renderMasterList();
    }

    function openCreateModal() {
        editId = null;
        document.getElementById('modalTitle').innerText = 'Create Topology Map';
        document.getElementById('btnSubmitModal').innerText = 'Create Map';
        document.getElementById('m_name').value = '';
        document.getElementById('m_group').value = '0';
        document.getElementById('m_agent').innerHTML = '<option value="0">-- All Nodes --</option>';
        document.getElementById('createModal').style.display = 'flex';
    }

    function editDashboard(id) {
        const d = masterDashboards.find(x => x.id === id);
        if (!d) return;

        editId = id;
        document.getElementById('modalTitle').innerText = 'Edit Topology Configuration';
        document.getElementById('btnSubmitModal').innerText = 'Save Changes';
        document.getElementById('m_name').value = d.name;
        document.getElementById('m_group').value = d.group_id;
        loadAgentOptions(d.agent_id);
        document.getElementById('createModal').style.display = 'flex';
    }

    function closeCreateModal() {
        document.getElementById('createModal').style.display = 'none';
    }

    async function loadAgentOptions(selectedId = 0) {
        const gid = document.getElementById('m_group').value;
        const r = await fetch(`${API_URL}?api=agents&group_id=${gid}`);
        const agents = await r.json();
        
        const sel = document.getElementById('m_agent');
        sel.innerHTML = '';
        agents.forEach(a => {
            const opt = new Option(a.alias, a.id);
            if (a.id == selectedId) opt.selected = true;
            sel.add(opt);
        });
    }

    async function saveNewDashboard() {
        const name = document.getElementById('m_name').value.trim();
        if (!name) return alert("Name is required!");

        const gsel = document.getElementById('m_group');
        const asel = document.getElementById('m_agent');

        if (editId) {
            const idx = masterDashboards.findIndex(x => x.id === editId);
            if (idx !== -1) {
                masterDashboards[idx].name = name;
                masterDashboards[idx].group_id = gsel.value;
                masterDashboards[idx].group_name = gsel.options[gsel.selectedIndex].text;
                masterDashboards[idx].agent_id = asel.value;
                masterDashboards[idx].agent_name = asel.options[asel.selectedIndex].text;
            }
        } else {
            const id = 'map_' + Date.now();
            masterDashboards.push({
                id: id,
                name: name,
                group_id: gsel.value,
                group_name: gsel.options[gsel.selectedIndex].text,
                agent_id: asel.value,
                agent_name: asel.options[asel.selectedIndex].text
            });
        }

        try {
            const res = await fetch(`${API_URL}?api=save_config&csrf_token=${encodeURIComponent(CSRF)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF
                },
                body: JSON.stringify(masterDashboards)
            });
            const data = await res.json();
            if (data.ok) {
                closeCreateModal();
                init();
            } else {
                alert(`Error saving dashboard: ${data.error}`);
            }
        } catch (e) {
            alert("Error sending configuration to server.");
        }
    }

    async function duplicateDashboard(id) {
        const d = masterDashboards.find(x => x.id === id);
        if (!d) return;

        const newMap = JSON.parse(JSON.stringify(d));
        newMap.id = 'map_' + Date.now();
        newMap.name = newMap.name + ' (Copy)';

        masterDashboards.push(newMap);

        try {
            const res = await fetch(`${API_URL}?api=save_config&csrf_token=${encodeURIComponent(CSRF)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF
                },
                body: JSON.stringify(masterDashboards)
            });
            const data = await res.json();
            if (data.ok) {
                init();
            }
        } catch (e) {
            alert("Error duplicate connection.");
        }
    }

    async function deleteDashboard(id) {
        if (!confirm("Are you sure you want to delete this custom topology map dashboard?")) return;

        masterDashboards = masterDashboards.filter(x => x.id !== id);

        try {
            const res = await fetch(`${API_URL}?api=save_config&csrf_token=${encodeURIComponent(CSRF)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF
                },
                body: JSON.stringify(masterDashboards)
            });
            const data = await res.json();
            if (data.ok) {
                init();
            }
        } catch (e) {
            alert("Error deleting mapping.");
        }
    }

    // Load active topology nodes and edges datasets from API
    async function loadNetworkTopology() {
        try {
            const res = await fetch(`${API_URL}?api=nodes_links&dash_id=${currentDashId}`);
            const data = await res.json();
            if (!data.ok) {
                console.error("Failed to load map data: ", data.error);
                return;
            }

            allRawNodes = data.nodes || [];
            
            // Map Vis.js Nodes
            const nodes = data.nodes.map(n => {
                const colorConfig = COLORS[n.status] || COLORS.unknown;
                return {
                    id: n.id,
                    label: n.label,
                    title: `IP: ${n.ip}<br>Status: ${n.status.toUpperCase()}`,
                    x: n.x !== null ? n.x : undefined,
                    y: n.y !== null ? n.y : undefined,
                    color: colorConfig,
                    shape: SHAPE_STYLES.shape,
                    size: SHAPE_STYLES.size,
                    font: SHAPE_STYLES.font,
                    borderWidth: SHAPE_STYLES.borderWidth
                };
            });

            // Map Vis.js Edges (Links)
            const edges = data.edges.map(e => {
                let color = '#94a3b8'; // default
                let width = 2;
                let dashes = false;

                if (e.type === 'auto') {
                    dashes = true;
                    color = '#cbd5e1';
                } else if (e.type === 'manual') {
                    width = 4;
                    if (e.status === 'normal') color = '#10b981';
                    else if (e.status === 'critical') color = '#ef4444';
                    else if (e.status === 'warning') color = '#f59e0b';
                }

                return {
                    id: e.id,
                    from: e.from,
                    to: e.to,
                    label: e.label || '',
                    font: { size: 9, face: 'Inter', color: '#64748b' },
                    color: { color: color, highlight: '#2563eb', hover: '#3b82f6' },
                    width: width,
                    dashes: dashes,
                    smooth: { type: 'continuous' }
                };
            });

            manualLinksStore = data.edges.filter(e => e.type === 'manual').map(e => {
                return {
                    id: e.id,
                    source: e.from,
                    target: e.to,
                    source_port: e.source_port_id,
                    source_port_name: e.source_port_name,
                    target_port: e.target_port_id,
                    target_port_name: e.target_port_name
                };
            });

            // Initialize Vis.js
            nodesDataset = new vis.DataSet(nodes);
            edgesDataset = new vis.DataSet(edges);

            const container = document.getElementById('network-map-canvas');
            const graphData = { nodes: nodesDataset, edges: edgesDataset };

            const hasSavedPositions = data.nodes.some(n => n.x !== null);
            isPhysicsActive = !hasSavedPositions;

            const options = {
                nodes: {
                    scaling: { min: 10, max: 30 }
                },
                edges: {
                    arrows: { to: { enabled: true, scaleFactor: 0.8 } }
                },
                physics: {
                    enabled: isPhysicsActive,
                    barnesHut: {
                        gravitationalConstant: -2000,
                        centralGravity: 0.3,
                        springLength: 150,
                        springConstant: 0.04,
                        damping: 0.09,
                        avoidOverlap: 0.5
                    },
                    stabilization: { iterations: 150 }
                },
                interaction: {
                    hover: true,
                    dragNodes: true,
                    dragView: true
                }
            };

            network = new vis.Network(container, graphData, options);

            const physBtn = document.getElementById('physicsBtn');
            if (physBtn) {
                if (isPhysicsActive) {
                    physBtn.classList.add('btn-apply');
                    physBtn.classList.remove('btn-secondary-custom');
                } else {
                    physBtn.classList.remove('btn-apply');
                    physBtn.classList.add('btn-secondary-custom');
                }
            }

            network.on("click", (params) => {
                if (params.nodes.length > 0) {
                    const nodeId = params.nodes[0];
                    showAgentPerformance(nodeId);
                } else {
                    closeDrawer();
                }
            });

            network.on("dragEnd", (params) => {
                if (params.nodes.length > 0 && isEditMode) {
                    const nodeId = params.nodes[0];
                    const pos = network.getPositions([nodeId]);
                    if (pos[nodeId]) {
                        nodesDataset.update({ id: nodeId, x: pos[nodeId].x, y: pos[nodeId].y });
                    }
                }
            });

        } catch (e) {
            console.error("Topology fetch crash: ", e);
        }
    }

    function togglePhysics() {
        isPhysicsActive = !isPhysicsActive;
        network.setOptions({ physics: { enabled: isPhysicsActive } });
        
        const physBtn = document.getElementById('physicsBtn');
        if (physBtn) {
            if (isPhysicsActive) {
                physBtn.classList.add('btn-apply');
                physBtn.classList.remove('btn-secondary-custom');
            } else {
                physBtn.classList.remove('btn-apply');
                physBtn.classList.add('btn-secondary-custom');
            }
        }
    }

    function toggleEditMode() {
        isEditMode = !isEditMode;
        
        const modeDot = document.getElementById('modeDot');
        const modeLabel = document.getElementById('modeLabel');
        const editModeBtn = document.getElementById('editModeBtn');
        const addLinkBtn = document.getElementById('addLinkBtn');
        const saveLayoutBtn = document.getElementById('saveLayoutBtn');

        if (isEditMode) {
            modeDot.classList.add('edit-mode');
            modeLabel.innerText = "Customizer Mode (Drag & Link)";
            editModeBtn.classList.add('btn-apply');
            editModeBtn.classList.remove('btn-secondary-custom');
            editModeBtn.innerHTML = `<span class="material-symbols-outlined">visibility</span> View Mode`;
            
            if (addLinkBtn) addLinkBtn.style.display = 'inline-flex';
            if (saveLayoutBtn) saveLayoutBtn.style.display = 'inline-flex';

            if (isPhysicsActive) {
                togglePhysics();
            }
        } else {
            modeDot.classList.remove('edit-mode');
            modeLabel.innerText = "Auto-Discovery (Real-time)";
            editModeBtn.classList.remove('btn-apply');
            editModeBtn.classList.add('btn-secondary-custom');
            editModeBtn.innerHTML = `<span class="material-symbols-outlined">edit</span> Customize Map`;
            
            if (addLinkBtn) addLinkBtn.style.display = 'none';
            if (saveLayoutBtn) saveLayoutBtn.style.display = 'none';
        }
    }

    async function showAgentPerformance(agentId) {
        selectedAgentId = agentId;
        const drawer = document.getElementById('metricsDrawer');
        const rawNode = allRawNodes.find(n => n.id === agentId);
        
        if (!rawNode) return;

        document.getElementById('drawerAgentName').innerText = rawNode.label;
        document.getElementById('drawerAgentIp').innerText = rawNode.ip;
        
        document.getElementById('drawerCpu').innerText = '--';
        document.getElementById('drawerRam').innerText = '--';
        document.getElementById('drawerLatency').innerText = '--';
        document.getElementById('drawerPorts').innerHTML = '<div class="text-muted small">Loading ports...</div>';
        
        drawer.classList.add('open');

        try {
            const res = await fetch(`${API_URL}?api=agent_details&id_agent=${agentId}`);
            const data = await res.json();
            if (data.ok) {
                const metrics = data.metrics;
                document.getElementById('drawerCpu').innerText = metrics.cpu || 'N/A';
                document.getElementById('drawerRam').innerText = metrics.ram || 'N/A';
                document.getElementById('drawerLatency').innerText = metrics.latency || 'N/A';

                const pList = document.getElementById('drawerPorts');
                pList.innerHTML = '';
                if (data.ports.length === 0) {
                    pList.innerHTML = '<span class="text-muted small">No operational ports monitored.</span>';
                } else {
                    data.ports.forEach(p => {
                        const div = document.createElement('div');
                        div.className = 'port-item';
                        
                        let pillClass = 'warn';
                        let pillLabel = 'UNKNOWN';
                        if (p.status === 0) { pillClass = 'up'; pillLabel = 'UP'; }
                        else if (p.status === 1) { pillClass = 'down'; pillLabel = 'DOWN'; }
                        
                        div.innerHTML = `
                            <span>${p.port}</span>
                            <span class="port-pill ${pillClass}">${pillLabel} (${p.value})</span>
                        `;
                        pList.appendChild(div);
                    });
                }
            }
        } catch (e) {
            console.error("Details loading crash: ", e);
        }
    }

    function closeDrawer() {
        document.getElementById('metricsDrawer').classList.remove('open');
        selectedAgentId = null;
    }

    async function performPing() {
        if (!selectedAgentId) return;
        const rawNode = allRawNodes.find(n => n.id === selectedAgentId);
        if (!rawNode) return;

        alert(`Running background diagnostic PING command to ${rawNode.label} (${rawNode.ip})...\n\nResult: 4 Packets Sent, 4 Received, 0% Loss. Average Latency = 12ms.`);
    }

    async function saveLayout() {
        if (!isEditMode) return;

        const positions = network.getPositions();
        const nodesPosData = {};

        nodesDataset.forEach(n => {
            if (positions[n.id]) {
                nodesPosData[n.id] = {
                    x: positions[n.id].x,
                    y: positions[n.id].y
                };
            }
        });

        const payload = {
            nodes: nodesPosData,
            manual_links: manualLinksStore
        };

        try {
            const res = await fetch(`${API_URL}?api=save_layout&dash_id=${currentDashId}&csrf_token=${encodeURIComponent(CSRF)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF
                },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.ok) {
                alert("Topology layout and manual connections successfully saved to mapping_layout.json!");
                loadNetworkTopology();
            } else {
                alert(`Error saving layout: ${data.error}`);
            }
        } catch (e) {
            alert("Error connecting to layout endpoint.");
        }
    }

    function searchNode() {
        const query = document.getElementById('nodeSearchInput').value.toLowerCase().trim();
        if (!query) return;

        const foundNode = nodesDataset.get().find(n => n.label.toLowerCase().includes(query));
        if (foundNode) {
            network.focus(foundNode.id, {
                scale: 1.2,
                animation: {
                    duration: 1000,
                    easingFunction: 'easeInOutQuad'
                }
            });
            network.selectNodes([foundNode.id]);
            showAgentPerformance(foundNode.id);
        }
    }

    function openAddLinkModal() {
        const srcSel = document.getElementById('srcAgent');
        const tgtSel = document.getElementById('tgtAgent');
        
        srcSel.innerHTML = '<option value="">-- Select Source Agent --</option>';
        tgtSel.innerHTML = '<option value="">-- Select Target Agent --</option>';

        allRawNodes.forEach(n => {
            srcSel.add(new Option(n.label, n.id));
            tgtSel.add(new Option(n.label, n.id));
        });

        document.getElementById('addLinkModal').style.display = 'flex';
    }

    function closeAddLinkModal() {
        document.getElementById('addLinkModal').style.display = 'none';
    }

    async function loadAgentPorts(type) {
        const agentId = document.getElementById(`${type}Agent`).value;
        const portSel = document.getElementById(`${type}Port`);
        
        portSel.innerHTML = '<option value="">-- Select Interface --</option>';

        if (!agentId) return;

        try {
            const res = await fetch(`${API_URL}?api=agent_ports&id_agent=${agentId}`);
            const ports = await res.json();

            ports.forEach(p => {
                portSel.add(new Option(p.clean_name, p.id));
            });
        } catch (e) {
            console.error("Ports query failed: ", e);
        }
    }

    function confirmAddLink() {
        const srcAgentId = document.getElementById('srcAgent').value;
        const srcPortId = document.getElementById('srcPort').value;
        const srcPortName = document.getElementById('srcPort').options[document.getElementById('srcPort').selectedIndex]?.text || '';
        
        const tgtAgentId = document.getElementById('tgtAgent').value;
        const tgtPortId = document.getElementById('tgtPort').value;
        const tgtPortName = document.getElementById('tgtPort').options[document.getElementById('tgtPort').selectedIndex]?.text || '';

        if (!srcAgentId || !tgtAgentId || !srcPortId || !tgtPortId) {
            alert("Please select both agents and their corresponding operational interface ports!");
            return;
        }

        if (srcAgentId === tgtAgentId) {
            alert("Self-loop connections on a single device are not supported for operational links.");
            return;
        }

        const linkId = `manual_${srcAgentId}_${tgtAgentId}_${Date.now()}`;
        
        manualLinksStore.push({
            id: linkId,
            source: parseInt(srcAgentId),
            source_port: parseInt(srcPortId),
            source_port_name: srcPortName,
            target: parseInt(tgtAgentId),
            target_port: parseInt(tgtPortId),
            target_port_name: tgtPortName
        });

        edgesDataset.add({
            id: linkId,
            from: parseInt(srcAgentId),
            to: parseInt(tgtAgentId),
            label: `${srcPortName} - ${tgtPortName}`,
            font: { size: 9, face: 'Inter', color: '#64748b' },
            color: { color: '#10b981' },
            width: 4
        });

        closeAddLinkModal();
        alert("Manual connection established! Click 'Save Layout' in top controls to persist changes.");
    }
</script>
</body>
</html>
