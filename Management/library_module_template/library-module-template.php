<?php
/**
 * PANDORA FMS - LIBRARY MODULE TEMPLATE
 * WebUI Manager for Module Templates (.txt configurations)
 * - UI/UX: Enterprise Dashboard with emerald/teal aesthetic, full responsive panels.
 * - Logic: Pure file-system architecture, directory tree parsing, and robust error checking.
 */

// Dynamically locate includes/db-connection.php
$dir = __DIR__;
while ($dir !== '/' && $dir !== '.' && !file_exists($dir . '/includes/db-connection.php')) {
    $parent = dirname($dir);
    if ($parent === $dir) break;
    $dir = $parent;
}
require_once $dir . '/includes/db-connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['id_usuario'] ?? 0;

// Security Check: Valid Pandora FMS Session
if (empty($user_id)) {
    header("Location: /pandora_console/index.php");
    exit;
}

$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';

// Directory Configuration
$BASE_TEMPLATE_DIR = realpath(__DIR__);
if (!is_dir($BASE_TEMPLATE_DIR)) {
    @mkdir($BASE_TEMPLATE_DIR, 0777, true);
}



// Write default CPU Usage template on first run if empty
$default_file = $BASE_TEMPLATE_DIR . '/System/CPU/CPU Usage (%).txt';
if (!file_exists($default_file)) {
    @mkdir(dirname($default_file), 0777, true);
    $default_content = "# Group: System\n" .
                       "# Sub Group: CPU\n" .
                       "module_begin\n" .
                       "module_name CPU Usage (%)\n" .
                       "module_type async_data\n" .
                       "module_exec top -b -n 1 | grep \"%Cpu(s)\" | awk '{print 100 - \$8}'\n" .
                       "module_description CPU Usage (%)\n" .
                       "module_end";
    @file_put_contents($default_file, $default_content);
}

// Parse Module Configuration File
function parse_template_file($path) {
    $content = file_get_contents($path);
    $lines = explode("\n", $content);
    $data = [
        'name' => '',
        'type' => 'async_data',
        'exec' => '',
        'description' => '',
        'min_warning' => '',
        'max_warning' => '',
        'min_critical' => '',
        'max_critical' => '',
        'group' => '',
        'subgroup' => '',
        'raw' => $content
    ];

    // Fallback info from path
    $parts = explode('/', str_replace('\\', '/', $path));
    if (count($parts) >= 3) {
        $data['name'] = str_replace('.txt', '', end($parts));
        $data['subgroup'] = $parts[count($parts) - 2];
        $data['group'] = $parts[count($parts) - 3];
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if (str_starts_with($line, '# Group:')) {
            $data['group'] = trim(substr($line, 8));
        } elseif (str_starts_with($line, '# Sub Group:')) {
            $data['subgroup'] = trim(substr($line, 12));
        } elseif (str_starts_with($line, 'module_name')) {
            $data['name'] = trim(substr($line, 11));
        } elseif (str_starts_with($line, 'module_type')) {
            $data['type'] = trim(substr($line, 11));
        } elseif (str_starts_with($line, 'module_exec')) {
            $data['exec'] = trim(substr($line, 11));
        } elseif (str_starts_with($line, 'module_description')) {
            $data['description'] = trim(substr($line, 18));
        } elseif (str_starts_with($line, 'module_min_warning')) {
            $data['min_warning'] = trim(substr($line, 18));
        } elseif (str_starts_with($line, 'module_max_warning')) {
            $data['max_warning'] = trim(substr($line, 18));
        } elseif (str_starts_with($line, 'module_min_critical')) {
            $data['min_critical'] = trim(substr($line, 19));
        } elseif (str_starts_with($line, 'module_max_critical')) {
            $data['max_critical'] = trim(substr($line, 19));
        }
    }
    return $data;
}

// Prune empty parent directories
function prune_empty_dirs($dir, $base_dir) {
    if (realpath($dir) === realpath($base_dir)) return;
    if (is_dir($dir)) {
        $files = array_diff(scandir($dir), ['.', '..']);
        if (empty($files)) {
            @rmdir($dir);
            prune_empty_dirs(dirname($dir), $base_dir);
        }
    }
}

// Breadcrumb dynamic text formatting
$raw_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); 
$dir_only = dirname($raw_path); 
$clean_path = trim($dir_only, '/'); 
$path_array = explode('/', $clean_path);
$formatted_array = array_map(function($p) {
    return ucwords(str_replace(['_', '-'], ' ', $p));
}, $path_array);
$dynamic_breadcrumb = implode(' / ', $formatted_array) . ' / LIBRARY MODULE TEMPLATE';

// =====================================================================
// AJAX API ENDPOINTS
// =====================================================================
$api = $_GET['api'] ?? '';

if ($api === 'list') {
    ob_clean();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Content-Type: application/json');
    $flat_list = [];
    $groups = [];
    $subgroups = [];

    function scan_flat($dir, $base_dir, &$flat, &$grps, &$subgrps) {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                scan_flat($path, $base_dir, $flat, $grps, $subgrps);
            } else {
                if (pathinfo($item, PATHINFO_EXTENSION) === 'txt') {
                    $parsed = parse_template_file($path);
                    $rel_path = str_replace($base_dir . '/', '', $path);
                    $flat[] = [
                        'name' => str_replace('.txt', '', $item),
                        'path' => $rel_path,
                        'group' => $parsed['group'],
                        'subgroup' => $parsed['subgroup'],
                        'data' => $parsed
                    ];
                    if ($parsed['group'] && !in_array($parsed['group'], $grps)) $grps[] = $parsed['group'];
                    if ($parsed['subgroup']) {
                        if (!isset($subgrps[$parsed['group']])) $subgrps[$parsed['group']] = [];
                        if (!in_array($parsed['subgroup'], $subgrps[$parsed['group']])) {
                            $subgrps[$parsed['group']][] = $parsed['subgroup'];
                        }
                    }
                }
            }
        }
    }

    scan_flat($BASE_TEMPLATE_DIR, $BASE_TEMPLATE_DIR, $flat_list, $groups, $subgroups);
    sort($groups);
    foreach ($subgroups as &$sg) sort($sg);

    echo json_encode([
        'ok' => true,
        'templates' => $flat_list,
        'groups' => $groups,
        'subgroups' => $subgroups
    ]);
    exit;
}

if ($api === 'save') {
    ob_clean(); header('Content-Type: application/json');
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf_token) || $client_token !== $csrf_token) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh page.']); exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        echo json_encode(['ok' => false, 'error' => 'No input received.']); exit;
    }

    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $type = trim($input['type'] ?? 'async_data');
    $exec = trim($input['exec'] ?? '');
    $group = trim($input['group'] ?? 'Unassigned');
    $subgroup = trim($input['subgroup'] ?? 'General');

    $min_warn = trim($input['min_warning'] ?? '');
    $max_warn = trim($input['max_warning'] ?? '');
    $min_crit = trim($input['min_critical'] ?? '');
    $max_crit = trim($input['max_critical'] ?? '');

    $original_path = trim($input['original_path'] ?? '');

    if (empty($name) || empty($exec)) {
        echo json_encode(['ok' => false, 'error' => 'Module Name and Monitored Command (Exec) are required fields.']); exit;
    }

    // Clean naming for safe filesystem storing
    $group_safe = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $group);
    $subgroup_safe = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $subgroup);
    $name_safe = preg_replace('/[^a-zA-Z0-9_\-\s\(\)%]/', '', $name);

    if (empty($group_safe)) $group_safe = 'Unassigned';
    if (empty($subgroup_safe)) $subgroup_safe = 'General';
    if (empty($name_safe)) $name_safe = 'Unnamed_Module';

    $dest_dir = $BASE_TEMPLATE_DIR . '/' . $group_safe . '/' . $subgroup_safe;
    $dest_file = $dest_dir . '/' . $name_safe . '.txt';

    if (!is_dir($dest_dir)) {
        @mkdir($dest_dir, 0777, true);
    }

    // Build standard Pandora FMS txt template format
    $txt = "# Group: " . $group . "\n";
    $txt .= "# Sub Group: " . $subgroup . "\n";
    $txt .= "module_begin\n";
    $txt .= "module_name " . $name . "\n";
    $txt .= "module_type " . $type . "\n";
    $txt .= "module_exec " . $exec . "\n";
    if ($description !== '') $txt .= "module_description " . $description . "\n";
    if ($min_warn !== '') $txt .= "module_min_warning " . $min_warn . "\n";
    if ($max_warn !== '') $txt .= "module_max_warning " . $max_warn . "\n";
    if ($min_crit !== '') $txt .= "module_min_critical " . $min_crit . "\n";
    if ($max_crit !== '') $txt .= "module_max_critical " . $max_crit . "\n";
    $txt .= "module_end";

    $bytes = @file_put_contents($dest_file, $txt);
    if ($bytes === false) {
        $err = error_get_last();
        $errMsg = $err['message'] ?? 'Write Permission Denied';
        echo json_encode(['ok' => false, 'error' => "Gagal menulis template. Alasan: $errMsg"]); exit;
    }

    // If edited and moved, delete original file
    if (!empty($original_path)) {
        $old_full = realpath($BASE_TEMPLATE_DIR . '/' . $original_path);
        $new_full = realpath($dest_file);
        if ($old_full && $old_full !== $new_full && str_starts_with($old_full, realpath($BASE_TEMPLATE_DIR))) {
            @unlink($old_full);
            prune_empty_dirs(dirname($old_full), $BASE_TEMPLATE_DIR);
        }
    }

    echo json_encode(['ok' => true, 'path' => str_replace($BASE_TEMPLATE_DIR . '/', '', $dest_file)]);
    exit;
}

if ($api === 'delete') {
    ob_clean();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Content-Type: application/json');
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf_token) || $client_token !== $csrf_token) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh page.']); exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $path = trim($input['path'] ?? '');

    if (empty($path)) {
        echo json_encode(['ok' => false, 'error' => 'Path is required.']); exit;
    }

    $target_file = $BASE_TEMPLATE_DIR . '/' . $path;
    $real_target = realpath($target_file);
    $real_base = realpath($BASE_TEMPLATE_DIR);

    if ($real_target && str_starts_with($real_target, $real_base)) {
        if (@unlink($real_target)) {
            prune_empty_dirs(dirname($real_target), $real_base);
            echo json_encode(['ok' => true]);
        } else {
            $err = error_get_last();
            $errMsg = $err['message'] ?? 'Permission Denied / File locked';
            echo json_encode(['ok' => false, 'error' => "Gagal menghapus file dari disk. Alasan: $errMsg"]);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Access Denied atau file template tidak ditemukan di disk server.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Module Templates</title>
    
    <link rel="icon" href="/pandora_console/images/pandora.ico" type="image/x-icon">
    <link href="/pandora_console/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="/pandora_console/custom/panel/vendor/bootstrap/bootstrap.min.css" />

    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; color: #1e293b; font-size: 13px; margin: 0; padding: 0; background-color: #f4f6f8; -webkit-font-smoothing: antialiased; }
        * { box-sizing: border-box; }
        
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-weight: normal !important; font-style: normal !important; font-size: 18px !important; line-height: 1 !important; display: inline-block; vertical-align: middle; color: inherit !important; }

        /* HEADER DYNAMIC */
        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: flex-start; flex-direction: column; border-bottom: 1px solid #e2e8f0; }
        .page-breadcrumb { font-size: 11px !important; font-weight: normal !important; color: #64748b !important; margin-bottom: 2px; text-transform: uppercase; letter-spacing: 0.5px; }
        .page-title { font-size: 18px !important; font-weight: 600 !important; color: #0f172a !important; margin: 0; padding: 0; }

        /* CONTAINER GRID */
        .main-container { padding: 25px 30px; }
        
        /* SLEEK PREMIUM CARD */
        .card-dyn { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03); overflow: hidden; display: flex; flex-direction: column; transition: all 0.2s ease-in-out; }
        .card-header-dyn { padding: 15px 20px; background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .card-title-dyn { font-size: 13px !important; font-weight: 600 !important; color: #0f172a !important; margin: 0; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 8px; }
        .card-body-dyn { padding: 20px; flex-grow: 1; overflow-y: auto; }

        /* BUTTONS */
        .btn-premium { background: #004d40; color: #ffffff !important; border: none; padding: 8px 16px; border-radius: 6px; font-weight: 500; font-size: 12px; transition: all 0.2s; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
        .btn-premium:hover { background: #00695c; box-shadow: 0 4px 8px rgba(0, 77, 64, 0.2); }
        .btn-premium:active { transform: scale(0.98); }
        
        .btn-outline-dyn { background: #ffffff; border: 1px solid #cbd5e1; color: #475569 !important; padding: 7px 14px; border-radius: 6px; font-weight: 500; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; cursor: pointer; }
        .btn-outline-dyn:hover { background: #f8fafc; border-color: #94a3b8; color: #0f172a !important; }
        
        .btn-danger-dyn { background: #fff5f5; border: 1px solid #fed7d7; color: #e53e3e !important; padding: 7px 14px; border-radius: 6px; font-weight: 500; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; cursor: pointer; }
        .btn-danger-dyn:hover { background: #fff5f5; border-color: #fc8181; box-shadow: 0 2px 6px rgba(229, 62, 62, 0.1); }

        /* TREE AND LIST */
        .search-box { position: relative; margin-bottom: 15px; }
        .search-box span { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 16px !important; }
        .search-box input { width: 100%; height: 36px; padding: 8px 15px 8px 36px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 12px; background: #ffffff; transition: 0.2s; }
        .search-box input:focus { border-color: #004d40; outline: none; box-shadow: 0 0 0 2px rgba(0, 77, 64, 0.1); }

        .list-group-custom { list-style: none; padding: 0; margin: 0; }
        .group-header { font-weight: 600; font-size: 11px; text-transform: uppercase; color: #64748b; padding: 8px 12px; display: flex; align-items: center; gap: 6px; background: #f1f5f9; border-radius: 6px; margin-top: 10px; margin-bottom: 4px; }
        .subgroup-header { font-weight: 600; font-size: 10.5px; text-transform: uppercase; color: #475569; padding: 6px 12px 6px 20px; display: flex; align-items: center; gap: 6px; }
        .template-item { padding: 8px 12px 8px 36px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; transition: all 0.15s; margin-bottom: 2px; color: #334155; }
        .template-item:hover { background: #e0f2f1; color: #004d40; font-weight: 500; }
        .template-item.active { background: #004d40; color: #ffffff !important; font-weight: 600; }
        .template-item.active:hover { color: #ffffff !important; }
        
        .sub-badge { font-size: 9px; padding: 2px 6px; border-radius: 10px; background: #f1f5f9; color: #64748b; font-weight: 600; }
        .template-item.active .sub-badge { background: rgba(255,255,255,0.2); color: #ffffff; }

        /* CODE BOX PREVIEW */
        .code-preview-box { font-family: 'Courier New', Courier, monospace !important; font-size: 12px !important; background: #0f172a; color: #e2e8f0; padding: 20px; border-radius: 8px; border: 1px solid #1e293b; overflow-x: auto; white-space: pre; margin: 0; }

        /* FORM ELEMENTS */
        .form-label-dyn { font-size: 11px !important; font-weight: 600 !important; text-transform: uppercase; color: #475569; margin-bottom: 6px; display: block; letter-spacing: 0.5px; }
        .form-control-dyn { border: 1px solid #cbd5e1; padding: 8px 12px; background-color: #ffffff; border-radius: 6px; font-size: 12px; width: 100%; transition: all 0.2s; color: #0f172a; }
        .form-control-dyn:focus { border-color: #004d40; outline: none; box-shadow: 0 0 0 2px rgba(0, 77, 64, 0.1); }
        .form-control-dyn::placeholder { color: #94a3b8; }
        textarea.form-control-dyn { resize: vertical; min-height: 80px; }
        textarea.code-textarea { font-family: 'Courier New', Courier, monospace !important; font-size: 12.5px !important; line-height: 1.4; min-height: 120px; }

        /* RESPONSIVE LAYOUT */
        .library-layout { display: grid; grid-template-columns: 320px 1fr; gap: 25px; min-height: calc(100vh - 140px); }
        @media (max-width: 992px) {
            .library-layout { grid-template-columns: 1fr; }
        }

        /* GRID THRESHOLDS */
        .threshold-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }

        /* TOAST */
        .toast-notify { position: fixed; bottom: 20px; right: 20px; background: #0f172a; color: #ffffff; padding: 12px 24px; border-radius: 8px; font-weight: 500; font-size: 12px; display: flex; align-items: center; gap: 8px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3); z-index: 1000; transform: translateY(100px); opacity: 0; transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
        .toast-notify.show { transform: translateY(0); opacity: 1; }
        .toast-success { border-left: 4px solid #10b981; }
        .toast-error { border-left: 4px solid #ef4444; }
    </style>
</head>
<body>

<div class="pandora-header-bottom">
    <span class="page-breadcrumb"><?= htmlspecialchars($dynamic_breadcrumb) ?></span>
    <h1 class="page-title">Library Module Templates</h1>
</div>

<div class="main-container">
    <div class="library-layout">
        
        <!-- LEFT PANEL: FILE LIST & SEARCH -->
        <div class="card-dyn" style="max-height: 80vh;">
            <div class="card-header-dyn">
                <h5 class="card-title-dyn"><span class="material-symbols-outlined" style="color:#004d40;">folder_open</span> MODULE TEMPLATES</h5>
                <button class="btn-premium" onclick="openCreateForm()" style="padding: 5px 10px;">
                    <span class="material-symbols-outlined" style="font-size:16px!important;">add</span> New
                </button>
            </div>
            <div class="card-body-dyn" style="padding: 15px;">
                <div class="search-box">
                    <span class="material-symbols-outlined">search</span>
                    <input type="text" id="tplSearch" placeholder="Cari template atau command..." onkeyup="renderTemplatesList()">
                </div>
                
                <div style="overflow-y: auto; flex-grow: 1;" id="listContainer">
                    <!-- Dynamic Tree populated by JS -->
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL: DETAILS VIEW OR EDIT FORM -->
        <div id="rightContainer" class="card-dyn" style="min-height: 60vh;">
            <!-- Initial Blank View -->
            <div class="card-body-dyn d-flex flex-column align-items-center justify-content-center" style="padding: 50px; text-align: center; color: #64748b;">
                <span class="material-symbols-outlined" style="font-size: 64px !important; color: #cbd5e1; margin-bottom: 15px;">library_books</span>
                <h4 style="margin: 0 0 8px 0; color: #0f172a; font-weight: 600;">Library Module Template</h4>
                <p style="margin: 0; font-size: 12px; max-width: 400px; line-height: 1.5;">Pilih salah satu template di panel kiri untuk melihat detail konfigurasinya, atau klik "New" untuk menambahkan template baru.</p>
            </div>
        </div>

    </div>
</div>

<!-- Dynamic Toast Notification -->
<div id="toast" class="toast-notify">
    <span id="toastIcon" class="material-symbols-outlined">check_circle</span>
    <span id="toastMsg">Action completed!</span>
</div>

<script>
    const CSRF_TOKEN = '<?= $csrf_token ?>';
    let templatesData = [];
    let groupsData = [];
    let subgroupsData = {};
    let selectedTemplatePath = null;
    let isEditing = false;

    // Trigger Initial Load
    loadTemplatesFromServer();

    function loadTemplatesFromServer(selectPath = null) {
        fetch('?api=list&_t=' + Date.now())
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    templatesData = res.templates;
                    groupsData = res.groups;
                    subgroupsData = res.subgroups;
                    
                    renderTemplatesList();
                    
                    if (selectPath) {
                        const target = templatesData.find(t => t.path === selectPath);
                        if (target) viewTemplate(target);
                    } else if (selectedTemplatePath) {
                        const target = templatesData.find(t => t.path === selectedTemplatePath);
                        if (target) viewTemplate(target);
                        else showBlankState();
                    } else {
                        showBlankState();
                    }
                } else {
                    showToast('Failed to load templates from server', 'error');
                }
            })
            .catch(() => showToast('Failed to communicate with server', 'error'));
    }

    // Render tree view
    function renderTemplatesList() {
        const container = document.getElementById('listContainer');
        const kw = document.getElementById('tplSearch').value.toLowerCase().trim();
        container.innerHTML = '';

        if (templatesData.length === 0) {
            container.innerHTML = `<div style="text-align:center; padding:30px; color:#94a3b8;">Empty Library folder.</div>`;
            return;
        }

        // Group templates in-memory
        const grouped = {};
        templatesData.forEach(t => {
            const matches = !kw || 
                            t.name.toLowerCase().includes(kw) || 
                            t.group.toLowerCase().includes(kw) || 
                            t.subgroup.toLowerCase().includes(kw) || 
                            (t.data.exec || '').toLowerCase().includes(kw) ||
                            (t.data.description || '').toLowerCase().includes(kw);

            if (!matches) return;

            const grp = t.group || 'Unassigned';
            const sub = t.subgroup || 'General';

            if (!grouped[grp]) grouped[grp] = {};
            if (!grouped[grp][sub]) grouped[grp][sub] = [];
            grouped[grp][sub].push(t);
        });

        const grpKeys = Object.keys(grouped).sort();
        if (grpKeys.length === 0) {
            container.innerHTML = `<div style="text-align:center; padding:30px; color:#94a3b8;">No matching templates found.</div>`;
            return;
        }

        grpKeys.forEach(grp => {
            const grpDiv = document.createElement('div');
            grpDiv.innerHTML = `<div class="group-header"><span class="material-symbols-outlined" style="font-size:16px!important; color:#004d40;">folder</span> ${grp}</div>`;
            
            const subs = Object.keys(grouped[grp]).sort();
            subs.forEach(sub => {
                const subHeader = document.createElement('div');
                subHeader.className = 'subgroup-header';
                subHeader.innerHTML = `<span class="material-symbols-outlined" style="font-size:14px!important; color:#64748b;">folder_open</span> ${sub}`;
                grpDiv.appendChild(subHeader);

                grouped[grp][sub].forEach(t => {
                    const item = document.createElement('div');
                    const isActive = selectedTemplatePath === t.path ? 'active' : '';
                    item.className = `template-item ${isActive}`;
                    item.innerHTML = `
                        <span style="font-size:12px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${t.name}</span>
                        <span class="sub-badge">${t.data.type}</span>
                    `;
                    item.onclick = () => viewTemplate(t);
                    grpDiv.appendChild(item);
                });
            });
            container.appendChild(grpDiv);
        });
    }

    function showBlankState() {
        selectedTemplatePath = null;
        isEditing = false;
        document.getElementById('rightContainer').innerHTML = `
            <div class="card-body-dyn d-flex flex-column align-items-center justify-content-center" style="padding: 50px; text-align: center; color: #64748b;">
                <span class="material-symbols-outlined" style="font-size: 64px !important; color: #cbd5e1; margin-bottom: 15px;">library_books</span>
                <h4 style="margin: 0 0 8px 0; color: #0f172a; font-weight: 600;">Library Module Template</h4>
                <p style="margin: 0; font-size: 12px; max-width: 400px; line-height: 1.5;">Pilih salah satu template di panel kiri untuk melihat detail konfigurasinya, atau klik "New" untuk menambahkan template baru.</p>
            </div>
        `;
    }

    // View selected template detail
    function viewTemplate(t) {
        selectedTemplatePath = t.path;
        isEditing = false;
        
        // Refresh active state in list
        document.querySelectorAll('.template-item').forEach(el => el.classList.remove('active'));
        renderTemplatesList();

        const data = t.data;
        
        // Build Thresholds HTML
        let thresholdsHtml = '';
        if (data.min_warning !== '' || data.max_warning !== '' || data.min_critical !== '' || data.max_critical !== '') {
            thresholdsHtml = `
                <div class="row g-3 mb-4">
                    <div class="col-sm-6">
                        <div style="background: #fff9f2; border:1px solid #ffe8cc; border-radius:6px; padding:10px 15px;">
                            <div style="font-size:10px; text-transform:uppercase; font-weight:600; color:#f08c00; margin-bottom:4px;">Threshold Warning</div>
                            <div style="font-weight:500; font-size:12px; color:#2b2f33;">
                                Min: <b>${data.min_warning !== '' ? data.min_warning : 'N/A'}</b> | Max: <b>${data.max_warning !== '' ? data.max_warning : 'N/A'}</b>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div style="background: #fff5f5; border:1px solid #ffe3e3; border-radius:6px; padding:10px 15px;">
                            <div style="font-size:10px; text-transform:uppercase; font-weight:600; color:#e03131; margin-bottom:4px;">Threshold Critical</div>
                            <div style="font-weight:500; font-size:12px; color:#2b2f33;">
                                Min: <b>${data.min_critical !== '' ? data.min_critical : 'N/A'}</b> | Max: <b>${data.max_critical !== '' ? data.max_critical : 'N/A'}</b>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        const html = `
            <div class="card-header-dyn">
                <h5 class="card-title-dyn"><span class="material-symbols-outlined" style="color:#004d40;">description</span> ${t.name}</h5>
                <div style="display:flex; gap:10px;">
                    <button class="btn-outline-dyn" onclick="openEditForm()"><span class="material-symbols-outlined" style="font-size:16px!important;">edit</span> Edit</button>
                    <button class="btn-danger-dyn" onclick="deleteTemplate('${t.path}')"><span class="material-symbols-outlined" style="font-size:16px!important;">delete</span> Delete</button>
                </div>
            </div>
            <div class="card-body-dyn">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <span style="font-size:10px; font-weight:600; text-transform:uppercase; color:#94a3b8; display:block;">Group Module</span>
                        <span style="font-size:12px; font-weight:500; color:#0f172a;">${t.group}</span>
                    </div>
                    <div class="col-md-3">
                        <span style="font-size:10px; font-weight:600; text-transform:uppercase; color:#94a3b8; display:block;">Sub Group Module</span>
                        <span style="font-size:12px; font-weight:500; color:#0f172a;">${t.subgroup}</span>
                    </div>
                    <div class="col-md-3">
                        <span style="font-size:10px; font-weight:600; text-transform:uppercase; color:#94a3b8; display:block;">Module Type</span>
                        <span style="font-size:11px; padding:2px 8px; border-radius:12px; background:#e0f2f1; color:#004d40; font-weight:600; display:inline-block; margin-top:2px;">${data.type}</span>
                    </div>
                </div>

                ${data.description ? `
                <div class="mb-3" style="background:#f8fafc; border:1px solid #f1f5f9; padding:10px 15px; border-radius:6px;">
                    <span style="font-size:10px; font-weight:600; text-transform:uppercase; color:#94a3b8; display:block; margin-bottom:2px;">Description</span>
                    <span style="font-size:12px; color:#475569;">${data.description}</span>
                </div>` : ''}

                ${thresholdsHtml}

                <div class="mb-4">
                    <span style="font-size:10px; font-weight:600; text-transform:uppercase; color:#94a3b8; display:block; margin-bottom:5px;">Monitored Command (module_exec)</span>
                    <div style="font-family:monospace; font-size:12px; background:#0f172a; color:#38bdf8; padding:12px 15px; border-radius:6px; word-break:break-all;">
                        ${data.exec}
                    </div>
                </div>

                <div class="mb-2" style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:10px; font-weight:600; text-transform:uppercase; color:#94a3b8;">Config File Preview (.txt)</span>
                    <div style="display:flex; gap:8px;">
                        <button class="btn-outline-dyn" style="padding:4px 8px; font-size:11px;" onclick="copyConfigToClipboard()"><span class="material-symbols-outlined" style="font-size:14px!important;">content_copy</span> Copy Raw</button>
                        <button class="btn-outline-dyn" style="padding:4px 8px; font-size:11px;" onclick="downloadConfig()"><span class="material-symbols-outlined" style="font-size:14px!important;">download</span> Download</button>
                    </div>
                </div>
                <pre class="code-preview-box" id="rawOutputText">${data.raw}</pre>
            </div>
        `;
        document.getElementById('rightContainer').innerHTML = html;
    }

    // Opens edit / create form
    function openCreateForm() {
        selectedTemplatePath = null;
        isEditing = true;
        
        // Remove active state
        document.querySelectorAll('.template-item').forEach(el => el.classList.remove('active'));
        renderFormState();
    }

    function openEditForm() {
        if (!selectedTemplatePath) return;
        isEditing = true;
        const target = templatesData.find(t => t.path === selectedTemplatePath);
        if (target) renderFormState(target);
    }

    function renderFormState(t = null) {
        const titleText = t ? `Edit Module Template: ${t.name}` : 'Create New Module Template';
        
        // Pre-populate values
        const name = t ? t.name : '';
        const description = t ? t.data.description : '';
        const type = t ? t.data.type : 'async_data';
        const exec = t ? t.data.exec : '';
        const group = t ? t.group : '';
        const subgroup = t ? t.subgroup : '';
        
        const min_warn = t ? t.data.min_warning : '';
        const max_warn = t ? t.data.max_warning : '';
        const min_crit = t ? t.data.min_critical : '';
        const max_crit = t ? t.data.max_critical : '';

        const typeOptions = [
            {val: 'async_data', label: 'async_data (Asynchronous Numeric)'},
            {val: 'generic_data', label: 'generic_data (Numeric)'},
            {val: 'generic_data_string', label: 'generic_data_string (Text/String)'},
            {val: 'generic_proc', label: 'generic_proc (Process/Boolean)'},
            {val: 'async_data_string', label: 'async_data_string (Asynchronous Text)'}
        ];

        let selectOptionsHtml = '';
        let isCustomType = true;
        typeOptions.forEach(opt => {
            const sel = opt.val === type ? 'selected' : '';
            if (opt.val === type) isCustomType = false;
            selectOptionsHtml += `<option value="${opt.val}" ${sel}>${opt.label}</option>`;
        });
        selectOptionsHtml += `<option value="custom" ${isCustomType ? 'selected' : ''}>-- Custom Type --</option>`;

        const html = `
            <div class="card-header-dyn">
                <h5 class="card-title-dyn"><span class="material-symbols-outlined" style="color:#004d40;">edit_document</span> ${titleText}</h5>
                <button class="btn-outline-dyn" onclick="cancelForm()"><span class="material-symbols-outlined" style="font-size:16px!important;">close</span> Cancel</button>
            </div>
            <div class="card-body-dyn">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label-dyn">Nama Module *</label>
                        <input type="text" id="f_name" class="form-control-dyn" value="${name.replace(/"/g, '&quot;')}" placeholder="e.g. CPU Usage (%)" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-dyn">Type Module</label>
                        <select id="f_type_select" class="form-control-dyn" onchange="toggleCustomType(this.value)">
                            ${selectOptionsHtml}
                        </select>
                        <input type="text" id="f_type_custom" class="form-control-dyn mt-2 ${isCustomType ? '' : 'd-none'}" value="${isCustomType ? type : ''}" placeholder="Ketik tipe kustom anda...">
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label-dyn">Group Module (Pilih / Tulis Baru)</label>
                        <input type="text" id="f_group" list="group_options" class="form-control-dyn" value="${group.replace(/"/g, '&quot;')}" placeholder="e.g. System" onchange="onFormGroupChange(this.value)">
                        <datalist id="group_options">
                            ${groupsData.map(g => `<option value="${g}"></option>`).join('')}
                        </datalist>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-dyn">Sub Group Module (Pilih / Tulis Baru)</label>
                        <input type="text" id="f_subgroup" list="subgroup_options" class="form-control-dyn" value="${subgroup.replace(/"/g, '&quot;')}" placeholder="e.g. CPU">
                        <datalist id="subgroup_options">
                            <!-- Dynamic populated by Group change -->
                        </datalist>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label-dyn">Description Module</label>
                    <textarea id="f_description" class="form-control-dyn" placeholder="Deskripsi ringkas mengenai fungsi sensor ini...">${description}</textarea>
                </div>

                <div class="threshold-grid mb-3">
                    <div style="background: #fff9f2; border:1px solid #ffe8cc; padding: 15px; border-radius: 6px;">
                        <span style="font-size:10px; font-weight:600; text-transform:uppercase; color:#f08c00; margin-bottom:8px; display:block;">Threshold Warning</span>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label-dyn" style="font-size:9px!important;">Min Value</label>
                                <input type="number" step="any" id="f_min_warning" class="form-control-dyn" value="${min_warn}" placeholder="N/A">
                            </div>
                            <div class="col-6">
                                <label class="form-label-dyn" style="font-size:9px!important;">Max Value</label>
                                <input type="number" step="any" id="f_max_warning" class="form-control-dyn" value="${max_warn}" placeholder="N/A">
                            </div>
                        </div>
                    </div>
                    
                    <div style="background: #fff5f5; border:1px solid #ffe3e3; padding: 15px; border-radius: 6px;">
                        <span style="font-size:10px; font-weight:600; text-transform:uppercase; color:#e03131; margin-bottom:8px; display:block;">Threshold Critical</span>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label-dyn" style="font-size:9px!important;">Min Value</label>
                                <input type="number" step="any" id="f_min_critical" class="form-control-dyn" value="${min_crit}" placeholder="N/A">
                            </div>
                            <div class="col-6">
                                <label class="form-label-dyn" style="font-size:9px!important;">Max Value</label>
                                <input type="number" step="any" id="f_max_critical" class="form-control-dyn" value="${max_crit}" placeholder="N/A">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label-dyn">Monitored Command (module_exec) *</label>
                    <textarea id="f_exec" class="form-control-dyn code-textarea" placeholder="top -b -n 1 | grep '%Cpu(s)' | awk '{print 100 - $8}'" required>${exec}</textarea>
                </div>

                <button class="btn-premium w-100 py-2.5" onclick="saveTemplateForm()" style="justify-content: center; height:40px; font-size:13px;">
                    <span class="material-symbols-outlined" style="font-size:18px!important;">save</span> SIMPAN TEMPLATE
                </button>
            </div>
        `;
        document.getElementById('rightContainer').innerHTML = html;
        onFormGroupChange(group);
    }

    function toggleCustomType(val) {
        const inp = document.getElementById('f_type_custom');
        if (val === 'custom') {
            inp.classList.remove('d-none');
            inp.focus();
        } else {
            inp.classList.add('d-none');
            inp.value = '';
        }
    }

    function onFormGroupChange(grp) {
        const list = document.getElementById('subgroup_options');
        if (!list) return;
        list.innerHTML = '';
        const subs = subgroupsData[grp] || [];
        subs.forEach(s => {
            list.innerHTML += `<option value="${s}"></option>`;
        });
    }

    function cancelForm() {
        if (selectedTemplatePath) {
            const target = templatesData.find(t => t.path === selectedTemplatePath);
            if (target) viewTemplate(target);
            else showBlankState();
        } else {
            showBlankState();
        }
    }

    // Save Template Handler
    function saveTemplateForm() {
        const name = document.getElementById('f_name').value.trim();
        const exec = document.getElementById('f_exec').value.trim();
        const typeSelect = document.getElementById('f_type_select').value;
        const typeCustom = document.getElementById('f_type_custom').value.trim();
        const type = typeSelect === 'custom' ? typeCustom : typeSelect;

        const group = document.getElementById('f_group').value.trim() || 'Unassigned';
        const subgroup = document.getElementById('f_subgroup').value.trim() || 'General';

        const min_warning = document.getElementById('f_min_warning').value.trim();
        const max_warning = document.getElementById('f_max_warning').value.trim();
        const min_critical = document.getElementById('f_min_critical').value.trim();
        const max_critical = document.getElementById('f_max_critical').value.trim();

        if (!name || !exec) {
            showToast('Module Name and Command are strictly required!', 'error');
            return;
        }

        if (typeSelect === 'custom' && !typeCustom) {
            showToast('Custom type is selected but blank!', 'error');
            return;
        }

        const payload = {
            name, exec, type, group, subgroup,
            min_warning, max_warning, min_critical, max_critical,
            original_path: selectedTemplatePath
        };

        fetch('?api=save', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN
            },
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                showToast('Template successfully saved on server!', 'success');
                selectedTemplatePath = res.path;
                loadTemplatesFromServer(res.path);
            } else {
                showToast(res.error || 'Write Permission Denied', 'error');
            }
        })
        .catch(() => showToast('Connection failure with server', 'error'));
    }

    // Delete Template Handler
    function deleteTemplate(path) {
        if (!confirm('Apakah Anda yakin ingin menghapus template ini dari disk server?')) return;

        fetch('?api=delete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN
            },
            body: JSON.stringify({ path })
        })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                showToast('Template successfully removed!', 'success');
                selectedTemplatePath = null;
                loadTemplatesFromServer();
            } else {
                showToast(res.error || 'Failed to delete template', 'error');
            }
        })
        .catch(() => showToast('Failed to communicate with server', 'error'));
    }

    // Utilities (Copy, Download, Toast)
    function copyConfigToClipboard() {
        const text = document.getElementById('rawOutputText').innerText;
        navigator.clipboard.writeText(text).then(() => {
            showToast('Config raw successfully copied!', 'success');
        });
    }

    function downloadConfig() {
        const text = document.getElementById('rawOutputText').innerText;
        const target = templatesData.find(t => t.path === selectedTemplatePath);
        const filename = (target ? target.name : 'module') + '.txt';
        
        const blob = new Blob([text], { type: 'text/plain' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Show dynamic floating toast notification
    function showToast(msg, type = 'success') {
        const toast = document.getElementById('toast');
        const icon = document.getElementById('toastIcon');
        const text = document.getElementById('toastMsg');

        toast.className = 'toast-notify ' + (type === 'success' ? 'toast-success' : 'toast-error');
        icon.innerText = type === 'success' ? 'check_circle' : 'error';
        text.innerText = msg;

        toast.classList.add('show');
        setTimeout(() => {
            toast.classList.remove('show');
        }, 3000);
    }
</script>
</body>
</html>
