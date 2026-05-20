<?php
// BREADCRUMB DYNAMIC LOGIC
$raw_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); // Ambil URL tanpa parameter ?...
$dir_only = dirname($raw_path); // Buang nama file.php, ambil foldernya saja
$clean_path = trim($dir_only, '/'); // Bersihkan slash di awal/akhir

// Rapikan teks (ganti _ dan - jadi spasi, lalu kapital huruf awal)
$path_array = explode('/', $clean_path);
$formatted_array = array_map(function($p) {
    return ucwords(str_replace(['_', '-'], ' ', $p));
}, $path_array);
$dynamic_breadcrumb = implode(' / ', $formatted_array);

// =====================================================================

$json_files = [
    'postgresql' => '/usr/share/pandora_server/util/plugin/postgresql_plugin/pg_queries.json',
    'mssql'      => '/usr/share/pandora_server/util/plugin/mssql_plugin/mssql_queries.json',
    'oracle'     => '/usr/share/pandora_server/util/plugin/oracle_plugin/oracle_queries.json'
];

$action = $_GET['action'] ?? '';
$db = $_GET['db'] ?? '';

if ($action === 'get' && array_key_exists($db, $json_files)) {
    header('Content-Type: application/json');
    echo file_exists($json_files[$db]) ? file_get_contents($json_files[$db]) : '{}';
    exit;
}

if ($action === 'save' && array_key_exists($db, $json_files)) {
    header('Content-Type: application/json');
    $input = file_get_contents('php://input');
    $decoded = json_decode($input);
    if (json_last_error() === JSON_ERROR_NONE) {
        $pretty_json = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $target = $json_files[$db];
        
        if (@file_put_contents($target, $pretty_json) !== false) {
            echo json_encode(['success' => true]);
        } else {
            $user = exec('whoami');
            $dir = dirname($target);
            $err = "Permission denied. User '$user' cannot write to '$target'.";
            if (!file_exists($target)) $err .= " File does not exist and directory '$dir' is not writable.";
            else if (!is_writable($target)) $err .= " File exists but is not writable.";
            
            echo json_encode(['success' => false, 'error' => $err . ' Cek chown/chmod pada file & folder tersebut.']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid JSON format.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PandoraFMS - Query Manager Pro</title>
    
    <link rel="icon" href="/pandora_console/images/pandora.ico" type="image/x-icon">
    
    
    <link href="/pandora_console/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="/pandora_console/custom/panel/vendor/fonts/fonts.css" />

    <link href="/pandora_console/custom/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="/pandora_console/custom/panel/vendor/jsoneditor/jsoneditor.min.css" rel="stylesheet">
    <script src="/pandora_console/custom/panel/vendor/jsoneditor/jsoneditor.min.js"></script>

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
        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: flex-start; flex-direction: column; }
        .page-breadcrumb { font-size: 12px !important; font-weight: normal !important; color: #4a5568 !important; margin-bottom: 2px; }
        .page-title { font-size: 18px !important; font-weight: 600 !important; color: #0b1a26 !important; margin: 0; padding: 0; }

        /* DASHBOARD LAYOUT & CARDS */
        .dashboard-layout { padding: 0 30px 30px 30px; display: flex; gap: 20px; align-items: flex-start; }
        
        .sidebar-menu { width: 240px; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); padding: 15px; flex-shrink: 0; }
        .sidebar-title { font-size: 11px !important; text-transform: uppercase; font-weight: normal !important; color: #7f8c8d; margin-bottom: 10px; padding-left: 5px; }
        
        .nav-link-custom { display: flex; align-items: center; gap: 10px; padding: 10px 15px; border-radius: 6px; color: #4a5568 !important; font-weight: normal !important; cursor: pointer; transition: 0.2s; margin-bottom: 5px; }
        .nav-link-custom:hover { background-color: #f4f6f8; color: #0b1a26 !important; }
        .nav-link-custom.active { background-color: #004d40; color: #fff !important; }
        .nav-link-custom.active .material-symbols-outlined { color: #fff !important; }

        .dashboard-card { background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); flex-grow: 1; display: flex; flex-direction: column; }
        .dashboard-card-header { padding: 15px 20px; border-bottom: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; }
        .dashboard-card-title { font-size: 15px !important; font-weight: 500 !important; color: #0b1a26 !important; margin: 0; }
        .dashboard-card-body { padding: 20px; }

        /* BUTTONS */
        .btn-apply { background: #004d40; color: #fff !important; border: none; padding: 8px 20px; border-radius: 4px; font-weight: normal !important; transition: 0.2s; display: flex; align-items: center; gap: 8px; justify-content: center; }
        .btn-apply:hover { background: #00695c; color: #fff; }
        .btn-outline-apply { background: #fff; color: #004d40 !important; border: 1px solid #004d40; padding: 8px 20px; border-radius: 4px; font-weight: normal !important; transition: 0.2s; }
        .btn-outline-apply:hover { background: #f4f6f8; }

        /* JSON EDITOR OVERRIDES */
        #jsoneditor { height: 60vh; border-radius: 4px; border: 1px solid #dce1e5; }
        .jsoneditor { border: none !important; }
        .jsoneditor-menu { background-color: #f8f9fa !important; border-bottom: 1px solid #dce1e5 !important; }
        .jsoneditor-menu > button { color: #4a5568 !important; }
        .jsoneditor-menu > button:hover { background-color: #e0e4e8 !important; }
        
        /* MODAL STYLING */
        .modal-content { border: none; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .modal-header { border-bottom: 1px solid #e0e4e8; padding: 15px 20px; background-color: #f8f9fa; border-radius: 8px 8px 0 0; }
        .modal-title { font-size: 15px !important; font-weight: normal !important; color: #0b1a26 !important; }
        .modal-body { padding: 20px; }
        .modal-footer { border-top: 1px solid #e0e4e8; padding: 15px 20px; background-color: #f8f9fa; border-radius: 0 0 8px 8px; }
        .form-label { font-size: 11px !important; text-transform: uppercase; font-weight: normal !important; color: #7f8c8d; margin-bottom: 5px; }
        .form-control, .form-select { border: 1px solid #dce1e5; padding: 8px 12px; background-color: #fff; font-weight: normal !important; color: #000 !important; border-radius: 4px; }
        .form-control:focus, .form-select:focus { border-color: #004d40; box-shadow: 0 0 0 2px rgba(0,77,64,0.1); }
        .form-text { font-size: 11px !important; font-weight: normal !important; color: #7f8c8d; margin-top: 5px; }
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
            <input type="text" id="globalSearch" placeholder="Search queries..." readonly onclick="alert('Tip: Use the search icon inside the JSON Editor below to find keys/values.')">
        </div>
    </div>
    <div class="header-right">
        <a href="/pandora_console/index.php" class="nav-icon-btn" title="Back to Home">
            <span class="material-symbols-outlined">home</span>
        </a>
    </div>
</div>

<div class="pandora-header-bottom">
    <span class="page-breadcrumb"><?= htmlspecialchars($dynamic_breadcrumb) ?></span>
    <h1 class="page-title">Query Manager Pro</h1>
</div>

<div class="dashboard-layout">
    
    <div class="sidebar-menu">
        <div class="sidebar-title">Databases</div>
        <div id="db-menu">
            <div class="nav-link-custom active" onclick="loadData('postgresql', this)">
                <span class="material-symbols-outlined">database</span> PostgreSQL
            </div>
            <div class="nav-link-custom" onclick="loadData('mssql', this)">
                <span class="material-symbols-outlined">window</span> MS SQL Server
            </div>
            <div class="nav-link-custom" onclick="loadData('oracle', this)">
                <span class="material-symbols-outlined">storage</span> Oracle DB
            </div>
        </div>
        
        <hr style="border-color: #e0e4e8; margin: 15px 0;">
        
        <button class="btn-apply w-100" onclick="addNewQuery()">
            <span class="material-symbols-outlined" style="font-size: 16px !important;">add</span> Add New Query
        </button>
    </div>

    <div class="dashboard-card">
        <div class="dashboard-card-header">
            <h5 class="dashboard-card-title" id="editor-title">Editor: PostgreSQL</h5>
            <div style="display: flex; align-items: center; gap: 15px;">
                <span id="save-status" style="font-weight: normal; font-size: 12px !important;"></span>
                <button class="btn-apply" onclick="saveData()">
                    <span class="material-symbols-outlined" style="font-size: 16px !important;">save</span> Save Configuration
                </button>
            </div>
        </div>
        <div class="dashboard-card-body">
            <div id="jsoneditor"></div>
            <div class="mt-3" style="font-size: 12px !important; color: #7f8c8d; font-weight: normal;">
                <span class="material-symbols-outlined" style="font-size: 14px !important; color:#f1c40f;">lightbulb</span> 
                Tip: Right-click on any item inside the editor to duplicate, insert, or delete nodes.
            </div>
        </div>
    </div>

</div>

<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Monitoring Module</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="queryForm">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Module Name</label>
                            <input type="text" id="m_name" class="form-control" placeholder="e.g., Check_Block_Sessions" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Module Type</label>
                            <select id="m_type" class="form-select">
                                <option value="async_data">Generic Numeric (async_data)</option>
                                <option value="async_string">Generic String (async_string)</option>
                                <option value="generic_data_string">Table/Log (generic_data_string)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">SQL Query</label>
                        <textarea id="m_query" class="form-control" rows="4" style="font-family: monospace !important; font-weight:normal !important;" placeholder="SELECT ... FROM ..." required></textarea>
                        <div class="form-text">Supports multi-line. Enter (new lines) will be automatically converted to single spaces upon saving.</div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-12">
                            <label class="form-label">Description</label>
                            <input type="text" id="m_desc" class="form-control" placeholder="Monitoring blocking sessions oracle database">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">Module Group</label>
                            <input type="text" id="m_group" class="form-control" value="Databases" placeholder="e.g., Connections, System, etc.">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Unit (Optional)</label>
                            <input type="text" id="m_unit" class="form-control" placeholder="%, MB, ms, etc.">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-outline-apply" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-apply" onclick="insertNewQuery()">Insert to Editor</button>
            </div>
        </div>
    </div>
</div>

<script src="/pandora_console/custom/panel/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
    let editor;
    let currentDb = 'postgresql';
    const addModal = new bootstrap.Modal(document.getElementById('addModal'));

    const container = document.getElementById("jsoneditor");
    const options = {
        mode: 'tree',
        modes: ['tree', 'code'],
        name: 'Queries'
    };
    editor = new JSONEditor(container, options);

    // Initial Load
    loadData('postgresql', document.querySelector('.nav-link-custom.active'));

    function loadData(dbType, el) {
        currentDb = dbType;
        
        // Update Active Class
        document.querySelectorAll('.nav-link-custom').forEach(nav => nav.classList.remove('active'));
        el.classList.add('active');
        
        // Update Title
        let dbTitle = dbType === 'postgresql' ? 'PostgreSQL' : (dbType === 'mssql' ? 'MS SQL Server' : 'Oracle DB');
        document.getElementById('editor-title').innerText = 'Editor: ' + dbTitle;

        // Fetch Data
        fetch(`?action=get&db=${dbType}`)
            .then(res => res.json())
            .then(data => editor.set(data));
    }

    function addNewQuery() {
        document.getElementById('queryForm').reset();
        addModal.show();
    }

    function insertNewQuery() {
        const name = document.getElementById('m_name').value;
        if(!name) return alert('Module name is required!');

        const currentData = editor.get();
        if(currentData[name]) return alert('Module name already exists!');

        // Auto Cleaner (Remove breaks and excess spaces)
        let rawQuery = document.getElementById('m_query').value;
        let cleanQuery = rawQuery.replace(/\r?\n|\r/g, ' ').replace(/\s+/g, ' ').trim();

        currentData[name] = {
            "query": cleanQuery,
            "type": document.getElementById('m_type').value,
            "desc": document.getElementById('m_desc').value,
            "unit": document.getElementById('m_unit').value,
            "module_group": document.getElementById('m_group').value || "Databases"
        };

        editor.set(currentData);
        editor.expandAll();
        addModal.hide();
        document.getElementById('save-status').innerHTML = '<span style="color: #f1c40f;"><span class="material-symbols-outlined" style="font-size:14px!important;">warning</span> Unsaved changes</span>';
    }

    function saveData() {
        const status = document.getElementById('save-status');
        status.innerHTML = '<span style="color: #3498db;">Saving...</span>';

        fetch(`?action=save&db=${currentDb}`, {
            method: 'POST',
            body: JSON.stringify(editor.get())
        })
        .then(res => res.json())
        .then(data => {
            if(data.success) {
                status.innerHTML = '<span style="color: #2ecc71;"><span class="material-symbols-outlined" style="font-size:14px!important;">check_circle</span> Saved successfully</span>';
                setTimeout(() => status.innerHTML = '', 3000);
            } else {
                alert('Error: ' + data.error);
                status.innerHTML = '<span style="color: #e74c3c;"><span class="material-symbols-outlined" style="font-size:14px!important;">error</span> Save failed</span>';
            }
        });
    }
</script>
</body>
</html>


