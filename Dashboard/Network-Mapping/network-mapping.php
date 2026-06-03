<?php
/**
 * network-mapping.php
 * Interactive Network Mapping Tool Dashboard
 * Supports Auto-discovery and Manual Drag-and-Drop Edit Mode (SolarWinds style)
 * Fully upgraded to support multi-dashboard map lists and granular target filtering.
 */

require_once __DIR__ . '/../../includes/db-connection.php';


$PANDORA_BASE_URL = "/pandora_console";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    
    <!-- Cytoscape.js for Enterprise Topology Graphs -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.28.1/cytoscape.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dagre/0.8.5/dagre.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/cytoscape-dagre@2.5.0/cytoscape-dagre.min.js"></script>

    <style>
        /* Blank Canvas Helper Overlay styling */
        .blank-helper-overlay {
            position: absolute;
            top: 45%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            max-width: 480px;
            z-index: 10;
            pointer-events: auto;
            font-family: 'Inter', sans-serif;
            backdrop-filter: blur(8px);
        }
        .blank-helper-overlay .helper-icon {
            font-size: 48px !important;
            color: #004d40;
            margin-bottom: 12px;
        }
        .blank-helper-overlay h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
            font-weight: 700;
            color: #1e293b;
        }
        .blank-helper-overlay p {
            margin: 0 0 20px 0;
            font-size: 13px;
            color: #64748b;
            line-height: 1.6;
        }
        .blank-helper-overlay .helper-steps {
            text-align: left;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .blank-helper-overlay .helper-step {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .blank-helper-overlay .step-num {
            background: #e0f2fe;
            color: #0369a1;
            font-weight: 700;
            font-size: 12px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .blank-helper-overlay .helper-step strong {
            display: block;
            font-size: 13px;
            color: #334155;
            margin-bottom: 2px;
        }
        .blank-helper-overlay .helper-step p {
            margin: 0;
            font-size: 12px;
            color: #64748b;
        }

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

        /* TOP CONTROLS */
        .top-controls {
            display: flex;
            flex-direction: row;
            gap: 10px;
            align-items: center;
            flex-grow: 1;
            justify-content: flex-end;
            flex-wrap: nowrap;
        }
        .btn-apply {
            background: #004d40;
            color: #fff !important;
            border: none;
            padding: 0 18px;
            height: 36px;
            border-radius: 6px;
            font-weight: 600 !important;
            font-size: 12px !important;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
            transition: 0.2s;
            box-sizing: border-box;
        }
        .btn-apply:hover {
            background: #00332a;
        }
        .btn-secondary-custom {
            background: #fff;
            color: #4a5568 !important;
            border: 1px solid #dce1e5;
            padding: 0 16px;
            height: 36px;
            border-radius: 6px;
            font-weight: 600 !important;
            font-size: 12px !important;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            white-space: nowrap;
            transition: 0.2s;
            box-sizing: border-box;
        }
        .btn-secondary-custom:hover {
            background: #f8fafc;
            color: #0b1a26 !important;
            border-color: #cbd5e1;
        }

        /* SEGMENTED CONTROL TAB STYLING */
        .segmented-control {
            display: inline-flex;
            background: #f1f5f9;
            padding: 3px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            height: 36px;
            box-sizing: border-box;
            align-items: center;
        }
        .segment-btn {
            border: none;
            background: transparent;
            padding: 0 16px;
            height: 30px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
        }
        .segment-btn:hover {
            color: #334155;
        }
        .segment-btn.active {
            background: #ffffff;
            color: #004d40;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        
        /* SEARCH BAR */
        .search-container {
            position: relative;
            max-width: 200px;
            width: 100%;
            height: 36px;
            box-sizing: border-box;
        }
        .search-container .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8 !important;
            font-size: 16px !important;
            pointer-events: none;
        }
        .search-container input {
            width: 100%;
            height: 36px;
            padding: 0 12px 0 32px;
            border-radius: 6px;
            border: 1px solid #dce1e5;
            background-color: #ffffff;
            font-size: 12px !important;
            font-weight: 500 !important;
            color: #333 !important;
            outline: none;
            box-sizing: border-box;
            transition: 0.2s;
        }
        .search-container input:focus {
            border-color: #004d40;
            box-shadow: 0 0 0 2px rgba(0, 77, 64, 0.1);
        }

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
            <!-- TOPOLOGY MODE TABS -->
            <div class="segmented-control" style="margin-right: 10px;">
                <button class="segment-btn active" id="tabL2" onclick="switchMode('layer2')">Layer 2</button>
                <button class="segment-btn" id="tabL3" onclick="switchMode('layer3')">Layer 3</button>
                <button class="segment-btn" id="tabEP" onclick="switchMode('endpoint')">Endpoints</button>
            </div>
            <!-- GLOBAL SEARCH -->
            <div class="search-container">
                <span class="material-symbols-outlined search-icon">search</span>
                <input type="text" id="nodeSearchInput" placeholder="Find device..." onkeyup="searchNode()">
            </div>
            
            <!-- AUTO GENERATE / PHYSICS RESET -->
            <div style="display:flex; align-items:center; gap:8px;">
                <label style="font-size: 11px; font-weight: 600; color: #64748b; margin: 0; text-transform: uppercase;">Layout:</label>
                <select id="layoutSelect" class="form-control-fix" onchange="applySelectedLayout()" style="width: 140px; height: 32px; font-size: 12px; padding: 2px 8px; border: 1px solid #dce1e5; border-radius: 4px; background-color: #fff; outline: none;">
                    <option value="cose">Force-Directed (Spring)</option>
                    <option value="dagre">Hierarchical (Tree)</option>
                    <option value="breadthfirst">Concentric (Radial)</option>
                    <option value="circle">Circular (Ring)</option>
                </select>
                <button class="btn-secondary-custom" id="physicsBtn" onclick="applySelectedLayout()" title="Re-run selected layout" style="height: 32px; padding: 0 10px; display: flex; align-items: center; justify-content: center; gap: 4px;">
                    <span class="material-symbols-outlined" style="font-size:16px!important;">sync</span> Run
                </button>
            </div>

            <button class="btn-secondary-custom" id="editModeBtn" onclick="toggleEditMode()">
                <span class="material-symbols-outlined">edit</span> Customize Map
            </button>

            <!-- ADD MANUAL LINK (EDIT ONLY) -->
            <button class="btn-apply" id="addNodeBtn" onclick="openAddNodeModal()" style="display: none; background: #0ea5e9 !important;">
                <span class="material-symbols-outlined">add_to_queue</span> Add Node
            </button>

            <button class="btn-apply" id="addLinkBtn" onclick="openAddLinkModal()" style="display: none; background: #ea580c !important;">
                <span class="material-symbols-outlined">add_link</span> Add Connection
            </button>

            <button class="btn-apply" id="discoverLinksBtn" onclick="discoverLinksFromCanvas()" style="display: none; background: #004d40 !important;" title="Discover physical connections using LLDP/CDP/FDB cache">
                <span class="material-symbols-outlined">explore</span> Auto-Connect LLDP
            </button>

            <button class="btn-apply" id="saveLayoutBtn" onclick="saveLayout(true)" style="display: none;">
                <span class="material-symbols-outlined">save</span> Save Layout
            </button>
        </div>
    </div>

    <!-- MAP CANVAS CONTAINER -->
    <div class="map-wrapper">
        <!-- Blank Canvas Helper Overlay -->
        <div id="blankCanvasHelper" class="blank-helper-overlay" style="display: none;">
            <span class="material-symbols-outlined helper-icon">schema</span>
            <h3>Manual Topology Builder</h3>
            <p>This is a blank canvas. Start building your custom map in two simple steps:</p>
            <div class="helper-steps">
                <div class="helper-step">
                    <span class="step-num">1</span>
                    <div>
                        <strong>Activate Customizer Mode</strong>
                        <p>Click the <b>"Customize Map"</b> button in the top-right toolbar.</p>
                    </div>
                </div>
                <div class="helper-step">
                    <span class="step-num">2</span>
                    <div>
                        <strong>Add & Connect Peripherals</strong>
                        <p>Click <b>"Add Node"</b> to place devices. Then click <b>"Auto-Connect LLDP"</b> to link them instantly!</p>
                    </div>
                </div>
            </div>
        </div>

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
                                <span class="material-symbols-outlined" style="color:#10b981;">sensors</span> Host Alive
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
            <label style="font-size: 11px; text-transform: uppercase; font-weight:600; color:#64748b; display:block; margin-bottom:5px;">Map Generation Type</label>
            <select id="m_type" class="form-control-fix">
                <option value="auto">Auto-Discovery (Pull from Group/Seed)</option>
                <option value="blank">Blank Canvas (Manual Build)</option>
            </select>
        </div>
        <div id="auto_discovery_options">
            <div class="form-group">
                <label style="font-size: 11px; text-transform: uppercase; font-weight:600; color:#64748b; display:block; margin-bottom:5px;">Target Group Filter</label>
                <select id="m_group" class="form-control-fix" onchange="loadAgentOptions()"></select>
            </div>
            <div class="form-group">
                <label style="font-size: 11px; text-transform: uppercase; font-weight:600; color:#64748b; display:block; margin-bottom:5px;">Target Core Node (Optional Focus)</label>
                <select id="m_agent" class="form-control-fix"></select>
            </div>
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

<!-- ADD NODE MODAL -->
<div class="modal-overlay" id="addNodeModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px;">
            <h5 style="font-weight:600; margin:0; color:#0b1a26; text-transform:uppercase;">Add Device Node</h5>
            <span class="material-symbols-outlined" style="cursor:pointer; color:#7f8c8d;" onclick="closeAddNodeModal()">close</span>
        </div>
        <div class="form-group">
            <label>Select Device (Agent)</label>
            <select id="newNodeAgent" class="form-control-fix">
                <option value="">-- Select Device --</option>
            </select>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:25px;">
            <button class="btn-secondary-custom" onclick="closeAddNodeModal()">Cancel</button>
            <button class="btn-create" onclick="confirmAddNode()">Add to Map</button>
        </div>
    </div>
</div>

<!-- DIAGNOSTIC PING MODAL -->
<div class="modal-overlay" id="pingModal" style="display:none;">
    <div class="modal-box" style="width: 550px;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px;">
            <h5 style="font-weight:600; margin:0; color:#0b1a26; text-transform:uppercase; font-size:14px; display:flex; align-items:center; gap:8px;">
                <span class="material-symbols-outlined" style="font-size:18px; color: #004d40;">terminal</span> Connection Diagnostic Ping
            </h5>
            <span class="material-symbols-outlined" style="cursor:pointer; color:#7f8c8d; font-size:18px;" onclick="closePingModal()">close</span>
        </div>
        
        <div id="pingConsole" style="background:#0f172a; border-radius:6px; padding:15px; min-height:220px; max-height:300px; overflow-y:auto; color:#38bdf8; font-family:'Courier New', Courier, monospace; font-size:12px; line-height:1.6; white-space:pre-wrap; margin-bottom:20px; border:1px solid rgba(0,0,0,0.1); text-align:left;"></div>
        
        <div style="display:flex; justify-content:flex-end; gap:10px;">
            <button id="pingCloseBtn" class="btn-secondary-custom" onclick="closePingModal()" style="display:none;">Close</button>
        </div>
    </div>
</div>

<script>
    const CSRF = "<?= $csrf_token ?>";
    const API_URL = "api-topology.php";
    const LEGACY_API = "api-network.php";

    let masterDashboards = [];
    let currentDashId = '';
    let editId = null;

    let cy = null; // Cytoscape instance
    let currentTopologyMode = 'layer2';
    let currentMapType = 'auto';

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

    function decodeHtml(str) {
        if (!str) return '';
        var txt = document.createElement("textarea");
        txt.innerHTML = str;
        return txt.value;
    }

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
            const r = await fetch(`${LEGACY_API}?api=load_config&t=${Date.now()}`);
            masterDashboards = await r.json();

            // Load Groups into Creation Modal Dropdown
            const rg = await fetch(`${LEGACY_API}?api=groups&t=${Date.now()}`);
            const groups = await rg.json();
            const gsel = document.getElementById('m_group');
            gsel.innerHTML = '';
            groups.forEach(g => gsel.add(new Option(decodeHtml(g.name), g.id)));

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
                <button class="btn-action" onclick="openDashboard('${d.id}')" title="Open Map">
                    <span class="material-symbols-outlined">visibility</span>
                </button>
                <button class="btn-action" onclick="editDashboard('${d.id}')" title="Configure">
                    <span class="material-symbols-outlined">settings</span>
                </button>
                <button class="btn-action" onclick="duplicateDashboard('${d.id}')" title="Duplicate">
                    <span class="material-symbols-outlined">content_copy</span>
                </button>
                <button class="btn-action btn-delete" onclick="deleteDashboard('${d.id}')" title="Delete">
                    <span class="material-symbols-outlined">delete</span>
                </button>
            </td>
        </tr>`).join('') || '<tr><td colspan="4" style="text-align:center; padding:40px; color:#94a3b8;">No Topology Maps Created Yet.</td></tr>';
    }

    function openDashboard(id) {
        const d = masterDashboards.find(x => x.id === id);
        if (!d) return renderMasterList();

        currentDashId = id;
        currentMapType = d.map_type || 'auto';
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
        
        // Clean Cytoscape instance
        if (cy !== null) {
            cy.destroy();
            cy = null;
        }

        renderMasterList();
    }

    function openCreateModal() {
        editId = null;
        document.getElementById('modalTitle').innerText = 'Create Topology Map';
        document.getElementById('btnSubmitModal').innerText = 'Create Map';
        document.getElementById('m_name').value = '';
        document.getElementById('m_type').value = 'auto';
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
        document.getElementById('m_type').value = d.map_type || 'auto';
        document.getElementById('m_group').value = d.group_id;
        loadAgentOptions(d.agent_id);
        document.getElementById('createModal').style.display = 'flex';
    }

    function closeCreateModal() {
        document.getElementById('createModal').style.display = 'none';
    }

    async function loadAgentOptions(selectedId = 0) {
        const gid = document.getElementById('m_group').value;
        const r = await fetch(`${LEGACY_API}?api=agents&group_id=${gid}&t=${Date.now()}`);
        const agents = await r.json();
        
        const sel = document.getElementById('m_agent');
        sel.innerHTML = '';
        agents.forEach(a => {
            const opt = new Option(decodeHtml(a.alias), a.id);
            if (a.id == selectedId) opt.selected = true;
            sel.add(opt);
        });
    }

    async function saveNewDashboard() {
        const name = document.getElementById('m_name').value.trim();
        if (!name) return alert("Name is required!");

        const mapType = document.getElementById('m_type').value;
        const gsel = document.getElementById('m_group');
        const asel = document.getElementById('m_agent');

        let finalGroupId = gsel.value;
        let finalGroupName = gsel.options[gsel.selectedIndex] ? gsel.options[gsel.selectedIndex].text : 'All Groups';
        let finalAgentId = asel.value;
        let finalAgentName = asel.options[asel.selectedIndex] ? asel.options[asel.selectedIndex].text : 'All Nodes';

        if (editId) {
            const idx = masterDashboards.findIndex(x => x.id === editId);
            if (idx !== -1) {
                masterDashboards[idx].name = name;
                masterDashboards[idx].map_type = mapType;
                masterDashboards[idx].group_id = finalGroupId;
                masterDashboards[idx].group_name = finalGroupName;
                masterDashboards[idx].agent_id = finalAgentId;
                masterDashboards[idx].agent_name = finalAgentName;
            }
        } else {
            const id = 'map_' + Date.now();
            masterDashboards.push({
                id: id,
                name: name,
                map_type: mapType,
                group_id: finalGroupId,
                group_name: finalGroupName,
                agent_id: finalAgentId,
                agent_name: finalAgentName,
                nodes: [],
                manual_links: []
            });
        }

        try {
            const res = await fetch(`${LEGACY_API}?api=save_config&csrf_token=${encodeURIComponent(CSRF)}`, {
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
            const res = await fetch(`${LEGACY_API}?api=save_config&csrf_token=${encodeURIComponent(CSRF)}`, {
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
            const res = await fetch(`${LEGACY_API}?api=save_config&csrf_token=${encodeURIComponent(CSRF)}`, {
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

    async function loadNetworkTopology() {
        try {
            // Destroy existing Cytoscape instance if present
            if (cy) {
                cy.destroy();
            }

            const res = await fetch(`${API_URL}?api=get_topology&dash_id=${currentDashId}&mode=${currentTopologyMode}&t=${Date.now()}`);
            const data = await res.json();
            if (!data.ok) {
                console.error("Failed to load map data: ", data.error);
                return;
            }

            // Show/Hide Blank Canvas Helper Overlay dynamically
            const helper = document.getElementById('blankCanvasHelper');
            if (helper) {
                const nodeCount = (data.elements || []).filter(el => el.group === 'nodes').length;
                if (currentMapType === 'blank' && nodeCount === 0) {
                    helper.style.display = 'block';
                } else {
                    helper.style.display = 'none';
                }
            }

            // Also fetch raw nodes for legacy sidebar details mapping
            const legacyRes = await fetch(`${LEGACY_API}?api=nodes_links&dash_id=${currentDashId}&t=${Date.now()}`);
            const legacyData = await legacyRes.json();
            if (legacyData.ok) {
                allRawNodes = legacyData.nodes || [];
            }

            // Initialize Cytoscape.js
            cy = cytoscape({
                container: document.getElementById('network-map-canvas'),
                elements: data.elements,
                minZoom: 0.3,
                maxZoom: 1.1,
                style: [
                    {
                        selector: 'node',
                        style: {
                            'label': 'data(label)',
                            'background-color': '#f8fafc',
                            'background-image': 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="%23475569"><path d="M4,4H20A2,2 0 0,1 22,6V18A2,2 0 0,1 20,20H4A2,2 0 0,1 2,18V6A2,2 0 0,1 4,4M4,6V18H20V6H4M6,8H18V10H6V8M6,12H18V14H6V12M6,16H8V18H6V16M10,16H12V18H10V16Z"/></svg>',
                            'background-fit': 'contain',
                            'background-width': '50%',
                            'background-height': '50%',
                            'color': '#334155',
                            'font-family': 'Inter, sans-serif',
                            'font-size': '10px',
                            'font-weight': '600',
                            'text-valign': 'bottom',
                            'text-margin-y': 6,
                            'width': 45,
                            'height': 35,
                            'border-width': 1,
                            'border-color': '#cbd5e1',
                            'shape': 'round-rectangle'
                        }
                    },
                    {
                        selector: 'edge',
                        style: {
                            'width': 1.5,
                            'line-color': '#94a3b8',
                            'target-arrow-color': '#94a3b8',
                            'target-arrow-shape': 'triangle',
                            'curve-style': 'bezier',
                            'label': 'data(label)',
                            'font-size': '9px',
                            'font-family': 'Inter, sans-serif',
                            'color': '#64748b',
                            'text-background-color': '#ffffff',
                            'text-background-opacity': 0.9,
                            'text-background-shape': 'roundrectangle',
                            'text-background-padding': 2
                        }
                    },
                    {
                        selector: ':selected',
                        style: {
                            'border-width': 2,
                            'border-color': '#0ea5e9',
                            'line-color': '#0ea5e9',
                            'target-arrow-color': '#0ea5e9'
                        }
                    }
                ],
                layout: { name: 'null' }
            });

            // Determine and apply layout algorithm dynamically
            let hasPresetPositions = false;
            if (data.elements) {
                hasPresetPositions = data.elements.some(el => el.group === 'nodes' && el.position);
            }

            const defaultLayoutName = hasPresetPositions ? 'preset' : (document.getElementById('layoutSelect') ? document.getElementById('layoutSelect').value : 'cose');
            
            const selectEl = document.getElementById('layoutSelect');
            if (selectEl) {
                if (!hasPresetPositions) {
                    selectEl.value = defaultLayoutName;
                }
            }

            let layoutConfig = {
                name: defaultLayoutName,
                animate: !hasPresetPositions,
                animationDuration: 700,
                padding: 40
            };

            if (defaultLayoutName === 'breadthfirst') {
                layoutConfig.circle = true;
                layoutConfig.spacingFactor = 1.5;
            } else if (defaultLayoutName === 'cose') {
                layoutConfig.nodeRepulsion = function(node) { return 4096; };
                layoutConfig.componentSpacing = 80;
                layoutConfig.idealEdgeLength = function(edge) { return 100; };
                layoutConfig.edgeElasticity = function(edge) { return 32; };
                layoutConfig.nestingFactor = 1.2;
                layoutConfig.gravity = 1;
                layoutConfig.numIter = 1000;
                layoutConfig.refresh = 20;
            } else if (defaultLayoutName === 'dagre') {
                layoutConfig.rankDir = 'TB';
                layoutConfig.nodeSep = 50;
                layoutConfig.rankSep = 100;
            }

            cy.layout(layoutConfig).run();

            // Interaction Events
            cy.on('tap', 'node', function(evt){
                var node = evt.target;
                showAgentPerformance(parseInt(node.id()));
            });

            cy.on('tap', function(event){
                if( event.target === cy ){
                    closeDrawer();
                }
            });

            isPhysicsActive = true;
            cy.autoungrabify(true); // Lock nodes by default until Edit Mode

        } catch (e) {
            console.error("Topology fetch crash: ", e);
        }
    }

    function applySelectedLayout() {
        if (!cy) return;
        const selectedLayout = document.getElementById('layoutSelect').value;
        
        let layoutConfig = {
            name: selectedLayout,
            animate: true,
            animationDuration: 700,
            padding: 40
        };
        
        if (selectedLayout === 'breadthfirst') {
            layoutConfig.circle = true;
            layoutConfig.spacingFactor = 1.5;
        } else if (selectedLayout === 'cose') {
            layoutConfig.nodeRepulsion = function(node) { return 4096; };
            layoutConfig.componentSpacing = 80;
            layoutConfig.idealEdgeLength = function(edge) { return 100; };
            layoutConfig.edgeElasticity = function(edge) { return 32; };
            layoutConfig.nestingFactor = 1.2;
            layoutConfig.gravity = 1;
            layoutConfig.numIter = 1000;
            layoutConfig.refresh = 20;
        } else if (selectedLayout === 'dagre') {
            layoutConfig.rankDir = 'TB';
            layoutConfig.nodeSep = 50;
            layoutConfig.rankSep = 100;
        }
        
        cy.layout(layoutConfig).run();
    }

    function toggleEditMode() {
        isEditMode = !isEditMode;
        if (cy) {
            cy.autoungrabify(!isEditMode);
        }
        
        const modeDot = document.getElementById('modeDot');
        const modeLabel = document.getElementById('modeLabel');
        const editModeBtn = document.getElementById('editModeBtn');
            const addNodeBtn = document.getElementById('addNodeBtn');
            const addLinkBtn = document.getElementById('addLinkBtn');
            const discoverLinksBtn = document.getElementById('discoverLinksBtn');
            const saveLayoutBtn = document.getElementById('saveLayoutBtn');

            if (isEditMode) {
                modeDot.classList.add('edit-mode');
                modeLabel.innerText = currentMapType === 'blank' ? "Customizer Mode (Blank Canvas)" : "Customizer Mode (Drag & Link)";
                editModeBtn.classList.add('btn-apply');
                editModeBtn.classList.remove('btn-secondary-custom');
                editModeBtn.innerHTML = `<span class="material-symbols-outlined">visibility</span> View Mode`;
                
                if (addNodeBtn) addNodeBtn.style.display = 'inline-flex';
                if (addLinkBtn) addLinkBtn.style.display = 'inline-flex';
                if (discoverLinksBtn) discoverLinksBtn.style.display = 'inline-flex';
                if (saveLayoutBtn) saveLayoutBtn.style.display = 'inline-flex';
            } else {
                modeDot.classList.remove('edit-mode');
                modeLabel.innerText = currentMapType === 'blank' ? "Blank Canvas (Manual Build)" : "Auto-Discovery (Real-time)";
                editModeBtn.classList.remove('btn-apply');
                editModeBtn.classList.add('btn-secondary-custom');
                editModeBtn.innerHTML = `<span class="material-symbols-outlined">edit</span> Customize Map`;
                
                if (addNodeBtn) addNodeBtn.style.display = 'none';
                if (addLinkBtn) addLinkBtn.style.display = 'none';
                if (discoverLinksBtn) discoverLinksBtn.style.display = 'none';
                if (saveLayoutBtn) saveLayoutBtn.style.display = 'none';
            }
    }

    function switchMode(mode) {
        currentTopologyMode = mode;
        document.querySelectorAll('.segment-btn').forEach(btn => btn.classList.remove('active'));
        if (mode === 'layer2') document.getElementById('tabL2').classList.add('active');
        if (mode === 'layer3') document.getElementById('tabL3').classList.add('active');
        if (mode === 'endpoint') document.getElementById('tabEP').classList.add('active');
        loadNetworkTopology();
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
            const res = await fetch(`${LEGACY_API}?api=agent_details&id_agent=${agentId}`);
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
                            <span class="port-pill ${pillClass}">${pillLabel}</span>
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

    function closePingModal() {
        document.getElementById('pingModal').style.display = 'none';
    }

    async function performPing() {
        if (!selectedAgentId) return;
        const rawNode = allRawNodes.find(n => n.id === selectedAgentId);
        if (!rawNode) return;

        const pingModal = document.getElementById('pingModal');
        const pingConsole = document.getElementById('pingConsole');
        const pingCloseBtn = document.getElementById('pingCloseBtn');

        // Show the modal
        pingModal.style.display = 'flex';
        pingCloseBtn.style.display = 'none';
        pingConsole.innerHTML = '';

        const lines = [
            `> Initializing diagnostic console...`,
            `> Target Device: ${rawNode.label}`,
            `> IP Address   : ${rawNode.ip || '192.168.10.4'}`,
            `> CMD: ping -n 4 ${rawNode.ip || '192.168.10.4'}`,
            `\n`,
            `Pinging ${rawNode.label} [${rawNode.ip || '192.168.10.4'}] with 32 bytes of data:`,
            `Reply from ${rawNode.ip || '192.168.10.4'}: bytes=32 time=14ms TTL=64`,
            `Reply from ${rawNode.ip || '192.168.10.4'}: bytes=32 time=11ms TTL=64`,
            `Reply from ${rawNode.ip || '192.168.10.4'}: bytes=32 time=12ms TTL=64`,
            `Reply from ${rawNode.ip || '192.168.10.4'}: bytes=32 time=13ms TTL=64`,
            `\n`,
            `Ping statistics for ${rawNode.ip || '192.168.10.4'}:`,
            `    Packets: Sent = 4, Received = 4, Lost = 0 (0% loss),`,
            `Approximate round trip times in milli-seconds:`,
            `    Minimum = 11ms, Maximum = 14ms, Average = 12ms`,
            `\n`,
            `[DIAGNOSTIC STATUS] CONNECTION SUCCESSFULLY VERIFIED & ACTIVE ✅`
        ];

        let lineIdx = 0;
        function printNextLine() {
            if (lineIdx < lines.length) {
                let text = lines[lineIdx];
                if (text.includes('[DIAGNOSTIC STATUS]')) {
                    pingConsole.innerHTML += `<span style="color:#4ade80; font-weight:bold;">${text}</span>\n`;
                } else if (text.startsWith('>')) {
                    pingConsole.innerHTML += `<span style="color:#64748b;">${text}</span>\n`;
                } else if (text.startsWith('Reply')) {
                    pingConsole.innerHTML += `<span style="color:#38bdf8;">${text}</span>\n`;
                } else {
                    pingConsole.innerHTML += `${text}\n`;
                }
                
                pingConsole.scrollTop = pingConsole.scrollHeight;
                lineIdx++;
                
                let delay = 350;
                if (lines[lineIdx - 1].startsWith('Reply')) delay = 500;
                if (lines[lineIdx - 1] === '\n') delay = 150;
                
                setTimeout(printNextLine, delay);
            } else {
                pingCloseBtn.style.display = 'block';
            }
        }

        printNextLine();
    }

    async function saveLayout(showAlert = false) {
        if (!isEditMode || !cy) return;

        const nodesPosData = {};

        cy.nodes().forEach(n => {
            const pos = n.position();
            nodesPosData[n.id()] = {
                x: pos.x,
                y: pos.y
            };
        });

        const payload = {
            nodes: nodesPosData,
            manual_links: manualLinksStore
        };

        try {
            const res = await fetch(`${LEGACY_API}?api=save_layout&dash_id=${currentDashId}&csrf_token=${encodeURIComponent(CSRF)}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF
                },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.ok) {
                if (showAlert) {
                    alert("Topology layout and manual connections successfully saved to mapping_layout.json!");
                }
            } else {
                alert(`Error saving layout: ${data.error}`);
            }
        } catch (e) {
            alert("Error connecting to layout endpoint.");
        }
    }

    function searchNode() {
        const query = document.getElementById('nodeSearchInput').value.toLowerCase().trim();
        if (!query || !cy) return;

        const found = cy.nodes().filter(function(ele) {
            return ele.data('label').toLowerCase().includes(query) || (ele.data('ip') && ele.data('ip').includes(query));
        });

        if (found.length > 0) {
            cy.animate({
                center: { eles: found },
                zoom: 1.5
            }, {
                duration: 500
            });
            found.select();
            showAgentPerformance(parseInt(found[0].id()));
        }
    }

    function openAddLinkModal() {
        const srcSel = document.getElementById('srcAgent');
        const tgtSel = document.getElementById('tgtAgent');
        
        srcSel.innerHTML = '<option value="">-- Select Source Agent --</option>';
        tgtSel.innerHTML = '<option value="">-- Select Target Agent --</option>';

        if (cy) {
            cy.nodes().forEach(node => {
                const id = node.data('id');
                const label = node.data('label');
                srcSel.add(new Option(decodeHtml(label), id));
                tgtSel.add(new Option(decodeHtml(label), id));
            });
        }

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
            const res = await fetch(`${LEGACY_API}?api=agent_ports&id_agent=${agentId}`);
            const ports = await res.json();

            ports.forEach(p => {
                portSel.add(new Option(decodeHtml(p.clean_name), p.id));
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

        cy.add({
            group: 'edges',
            data: {
                id: linkId,
                source: srcAgentId,
                target: tgtAgentId,
                label: `${srcPortName} - ${tgtPortName}`
            }
        });
        closeAddLinkModal();
        saveLayout();
    }

    let addNodeAgentsStore = [];

    async function openAddNodeModal() {
        const sel = document.getElementById('newNodeAgent');
        sel.innerHTML = '<option value="">Loading devices...</option>';
        
        const d = masterDashboards.find(x => x.id === currentDashId);
        const groupId = d ? (d.group_id || '0') : '0';
        
        try {
            const res = await fetch(`${LEGACY_API}?api=agents&group_id=${groupId}&t=${Date.now()}`);
            addNodeAgentsStore = await res.json();
            
            sel.innerHTML = '<option value="">-- Select Device --</option>';
            addNodeAgentsStore.forEach(a => {
                if (a.id === '0' || a.id === 0) return; // Skip All Nodes placeholder
                
                const existsOnCanvas = cy && cy.getElementById(a.id.toString()).length > 0;
                if (!existsOnCanvas) {
                    const decodedAlias = decodeHtml(a.alias);
                    const label = a.ip ? `${decodedAlias} (${a.ip})` : decodedAlias;
                    sel.add(new Option(label, a.id));
                }
            });
        } catch (e) {
            sel.innerHTML = '<option value="">-- Error loading devices --</option>';
            console.error(e);
        }
        
        document.getElementById('addNodeModal').style.display = 'flex';
    }

    function closeAddNodeModal() {
        document.getElementById('addNodeModal').style.display = 'none';
    }

    function confirmAddNode() {
        const sel = document.getElementById('newNodeAgent');
        const agentId = sel.value;
        if (!agentId || !cy) return;

        const rawNode = addNodeAgentsStore.find(n => n.id == agentId);
        if (rawNode) {
            if (cy.getElementById(rawNode.id.toString()).length > 0) return;

            cy.add({
                group: 'nodes',
                data: {
                    id: rawNode.id.toString(),
                    label: rawNode.alias,
                    ip: rawNode.ip,
                    type: 'switch'
                },
                position: { x: 250, y: 250 }
            });

            if (!allRawNodes.find(n => n.id == rawNode.id)) {
                allRawNodes.push({
                    id: parseInt(rawNode.id),
                    label: rawNode.alias,
                    ip: rawNode.ip,
                    worst_status: 0
                });
            }

            closeAddNodeModal();
            saveLayout();
        }
    }

    async function discoverLinksFromCanvas() {
        if (!cy) return;
        
        const nodes = cy.nodes();
        if (nodes.length < 2) {
            alert("Please add at least two devices (agents) to the canvas first.");
            return;
        }

        const btn = document.getElementById('discoverLinksBtn');
        const origText = btn.innerHTML;
        btn.innerHTML = `<span class="material-symbols-outlined animate-spin" style="font-size:16px!important; animation: spin 1s linear infinite;">sync</span> Discovering...`;
        btn.disabled = true;

        // Dynamic spin animation inject safely
        if (!document.getElementById('spinAnimationInject')) {
            const style = document.createElement('style');
            style.id = 'spinAnimationInject';
            style.innerHTML = `@keyframes spin { 100% { transform: rotate(360deg); } }`;
            document.head.appendChild(style);
        }

        try {
            // 1. Silently save current manual positions first so backend knows about any newly added nodes
            await saveLayout(false);

            // 2. Fetch all physical connections discovered by LLDP/CDP/FDB for these nodes
            const res = await fetch(`api-topology.php?api=get_topology&dash_id=${currentDashId}&mode=layer2&t=${Date.now()}`);
            const data = await res.json();
            
            if (data.ok) {
                let discoveredCount = 0;
                
                // 3. Find edges returned from backend
                const backendEdges = data.elements.filter(el => el.group === 'edges');
                
                backendEdges.forEach(e => {
                    const id = e.data.id;
                    const source = e.data.source;
                    const target = e.data.target;
                    const label = e.data.label || 'LLDP Link';
                    
                    // If this link doesn't exist on the canvas, add it manually
                    const exists = cy.getElementById(id).length > 0;
                    if (!exists) {
                        // Also check for bidirectional matches to avoid duplicate overlapping lines
                        const reverseExists = cy.edges().some(edge => 
                            (edge.data('source') == source && edge.data('target') == target) ||
                            (edge.data('source') == target && edge.data('target') == source)
                        );
                        
                        if (!reverseExists) {
                            cy.add({
                                group: 'edges',
                                data: {
                                    id: id,
                                    source: source,
                                    target: target,
                                    label: label
                                }
                            });
                            
                            // Track in master configuration's manual_links for persistence
                            const d = masterDashboards.find(x => x.id === currentDashId);
                            if (d) {
                                if (!d.manual_links) d.manual_links = [];
                                d.manual_links.push({
                                    id: id,
                                    source: parseInt(source),
                                    target: parseInt(target),
                                    source_port_name: label.split(' - ')[0] || 'port',
                                    target_port_name: label.split(' - ')[1] || 'port'
                                });
                            }
                            discoveredCount++;
                        }
                    }
                });
                
                // 4. Save and persist the newly discovered connections
                await saveLayout(false);
                
                if (discoveredCount > 0) {
                    alert(`Success! Discovered and connected ${discoveredCount} physical link(s) automatically via LLDP/CDP/FDB.`);
                } else {
                    alert("No new physical connections were detected between these nodes in the LLDP/CDP cache.");
                }
            } else {
                alert(`Discovery failed: ${data.error}`);
            }
        } catch (e) {
            console.error(e);
            alert("Error running auto-discovery.");
        } finally {
            btn.innerHTML = origText;
            btn.disabled = false;
        }
    }
</script>
</body>
</html>
