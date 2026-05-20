<?php
/**
 * PANDORA FMS - CUSTOM EXTENSIONS PORTAL
 * Version: 2.2 (Architecture Update & Standalone Fixes)
 */

// 1. SECURITY HEADERS
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// 2. CORE UTILS & DB CONNECTION
require_once __DIR__ . '/includes/db-connection.php';

$base_dir = __DIR__; 
$base_url = '/pandora_console/custom/panel';
$portal_config_file = $base_dir . '/portal_config.json';
$menu_cache_file = $base_dir . '/temp/menu_cache.json';

// =====================================================================
// 2. AUTHENTICATION CHECK (PANDORA FMS SESSION)
// =====================================================================
// Ensure session is started AFTER config.php is loaded to read Pandora's 'session_save_path'
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['id_usuario'])) {
    // If no valid session (not logged in), redirect to home page
    header("Location: /pandora_console/index.php");
    exit;
}

// Generate CSRF Token if not exists
if (empty($_SESSION['pfms_csrf_token'])) {
    $_SESSION['pfms_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['pfms_csrf_token'];

// =====================================================================
// 3. PORTAL CONFIGURATION & STATE MANAGEMENT
// =====================================================================
// Default Configuration
$config_data = [
    'exclude_dirs' => ['temp', 'cache', 'assets', 'includes', 'versions', 'scanning-mib', 'snmp-explorer'],
    'exclude_files' => ['nfx_local_config.php', 'pdb_local_config.php', 'config.php', 'utils.php', 'check_cols.php,', 'check_schema.php', 'check_schema_v2.php', 'temp_query.php', 'pfms_latency_map.php', 'api_network.php', 'cron.php', 'pfms_latency_map.php', 'pfms_lib.php', ]
];

// Load Configuration if exists
if (file_exists($portal_config_file)) {
    $loaded_config = json_decode(file_get_contents($portal_config_file), true);
    if (is_array($loaded_config)) {
        $config_data['exclude_dirs'] = $loaded_config['exclude_dirs'] ?? $config_data['exclude_dirs'];
        $config_data['exclude_files'] = $loaded_config['exclude_files'] ?? $config_data['exclude_files'];
    }
}

// Ensure critical system files/dirs are ALWAYS excluded
$sys_dirs = ['.', '..'];
$sys_files = ['custom-index.php'];
$active_exclude_dirs = array_unique(array_merge($config_data['exclude_dirs'], $sys_dirs));
$active_exclude_files = array_unique(array_merge($config_data['exclude_files'], $sys_files));

// =====================================================================
// 4. AJAX ENDPOINT FOR SAVING SETTINGS
// =====================================================================
if (isset($_GET['api']) && $_GET['api'] === 'read_docs') {
    ob_clean();
    header('Content-Type: application/json');
    $file = $_GET['file'] ?? 'doc';
    $target = ($file === 'changelog') ? $base_dir . '/CHANGELOG.md' : $base_dir . '/DOCUMENTATION.md';
    
    if (file_exists($target)) {
        echo json_encode(['ok' => true, 'content' => file_get_contents($target)]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'File not found.']);
    }
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'save_settings') {
    ob_clean();
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    // CSRF Validation
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($client_token !== $csrf_token) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh page.']);
        exit;
    }

    if (is_array($input) && isset($input['exclude_dirs']) && isset($input['exclude_files'])) {
        $save_data = [
            'exclude_dirs' => array_values(array_filter(array_map('trim', $input['exclude_dirs']))),
            'exclude_files' => array_values(array_filter(array_map('trim', $input['exclude_files'])))
        ];
        $bytes = file_put_contents($portal_config_file, json_encode($save_data, JSON_PRETTY_PRINT));
        
        // Clear menu cache on settings save
        if (file_exists($menu_cache_file)) @unlink($menu_cache_file);

        echo json_encode(['ok' => $bytes !== false, 'error' => $bytes === false ? 'Save failed. Check file/folder permissions.' : '']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid data']);
    }
    exit;
}

// =====================================================================
// 5. DIRECTORY SCANNER LOGIC
// =====================================================================
function formatName($filename) {
    $name = str_replace('.php', '', $filename);
    $name = str_replace(['-', '_'], ' ', $name);
    return ucwords($name);
}

function getMenuTree($dir, $baseDir, $exc_dirs, $exc_files) {
    $tree = [];
    if (!is_dir($dir)) return $tree;
    
    $items = scandir($dir);
    foreach ($items as $item) {
        if (in_array($item, $exc_dirs)) continue;
        
        $path = $dir . '/' . $item;
        $rel_path = str_replace($baseDir . '/', '', $path);

        if (is_dir($path)) {
            $children = getMenuTree($path, $baseDir, $exc_dirs, $exc_files);
            if (!empty($children)) {
                $tree[] = [
                    'type' => 'dir',
                    'name' => formatName($item),
                    'children' => $children
                ];
            }
        } else {
            if (pathinfo($item, PATHINFO_EXTENSION) === 'php' && !in_array($item, $exc_files)) {
                $tree[] = [
                    'type' => 'file',
                    'name' => formatName($item),
                    'path' => $rel_path
                ];
            }
        }
    }
    return $tree;
}

$menuTree = [];
// Clear cache if 't' parameter is present (Force Refresh)
if (isset($_GET['t']) && file_exists($menu_cache_file)) {
    @unlink($menu_cache_file);
}

if (file_exists($menu_cache_file)) {
    $menuTree = json_decode(file_get_contents($menu_cache_file), true);
}

if (empty($menuTree)) {
    $menuTree = getMenuTree($base_dir, $base_dir, $active_exclude_dirs, $active_exclude_files);
    if (!is_dir(dirname($menu_cache_file))) @mkdir(dirname($menu_cache_file), 0777, true);
    @file_put_contents($menu_cache_file, json_encode($menuTree));
}

// =====================================================================
// 6. SECURITY & PAGE ROUTING
// =====================================================================
$current_page = $_GET['page'] ?? '';
$iframe_src = '';

if (!empty($current_page)) {
    if (preg_match('/\.\./', $current_page)) die("Invalid path detected.");
    $target_file = $base_dir . '/' . $current_page;
    if (file_exists($target_file) && pathinfo($target_file, PATHINFO_EXTENSION) === 'php') {
        $v = filemtime($target_file);
        $iframe_src = $base_url . '/' . $current_page . '?v=' . $v;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PandoraFMS - Custom Extensions Portal</title>
    
    <link rel="icon" href="/pandora_console/images/pandora.ico" type="image/x-icon">
    
    
    <link href="/pandora_console/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="/pandora_console/custom/panel/vendor/fonts/fonts.css" />

    <script>
        // Hapus Header Ganda di Iframe (Defined early to prevent not defined errors on fast iframe loads)
        function cleanIframeHeader() {
            const iframe = document.getElementById('contentFrame');
            if (!iframe) return;
            try {
                const innerDoc = iframe.contentDocument || iframe.contentWindow.document;
                const topHeader = innerDoc.querySelector('.pandora-header-top');
                if (topHeader) topHeader.style.display = 'none';
            } catch (e) {}
        }
    </script>

    <style>
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #334155; font-size: 14px; -webkit-font-smoothing: antialiased; } * { box-sizing: border-box; }
        body { background-color: #f4f6f8; margin: 0; padding: 0; display: flex; flex-direction: column; height: 100vh; overflow: hidden;}
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-weight: normal !important; font-style: normal !important; font-size: 18px !important; line-height: 1 !important; display: inline-block; vertical-align: middle; color: inherit !important; }

        /* NAVBAR */
        .pandora-header-top { background-color: #ffffff; border-bottom: 1px solid #e0e4e8; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 25px; z-index: 100; flex-shrink: 0;}
        .header-left { display: flex; align-items: center; width: 60%;}
        .logo-link { display: flex; align-items: center; text-decoration: none !important; transition: opacity 0.2s; }
        .logo-link:hover { opacity: 0.8; }
        .header-logo { height: 24px; width: auto; object-fit: contain; border: none; }
        .header-divider { width: 1px; height: 28px; background-color: #dce1e5; margin: 0 20px; }
        .header-title-box { display: flex; flex-direction: column; line-height: 1.2; }
        .header-title-box .main-title { font-size: 14px !important; font-weight: normal !important; color: #0b1a26 !important; }
        .header-title-box .sub-title { font-size: 12px !important; font-weight: normal !important; color: #7f8c8d !important; }

        /* SEARCH BAR */
        .custom-search-container { position: relative; flex-grow: 1; max-width: 500px; margin-left: 30px; }
        .custom-search-container .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #7f8c8d !important; font-size: 18px !important; pointer-events: none; }
        .custom-search-container input { width: 100%; height: 32px; padding: 8px 15px 8px 35px; border-radius: 16px; border: 1px solid #dce1e5; background-color: #f8f9fa; font-size: 12px !important; color: #333 !important; transition: all 0.2s ease; }
        .custom-search-container input:focus { background-color: #ffffff; border-color: #b5c1c9; outline: none; box-shadow: 0 0 0 2px rgba(181, 193, 201, 0.2); }

        /* HEADER ICONS */
        .header-right { display: flex; align-items: center; gap: 10px; }
        .nav-icon-btn { color: #4a5568 !important; text-decoration: none; display: flex; align-items: center; justify-content: center; height: 36px; width: 36px; border-radius: 50%; transition: 0.2s; background: transparent; cursor: pointer; border: none;}
        .nav-icon-btn:hover { background-color: #e0e4e8; color: #0b1a26 !important; }

        /* LAYOUT & SIDEBAR */
        .layout-wrapper { display: flex; flex-grow: 1; overflow: hidden; }
        .sidebar { width: 260px; background-color: #ffffff; border-right: 1px solid #e0e4e8; display: flex; flex-direction: column; flex-shrink: 0; z-index: 50; }
        .sidebar-header { padding: 15px 20px; background-color: #f8f9fa; border-bottom: 1px solid #e0e4e8; font-weight: normal !important; font-size: 11px !important; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; }
        .sidebar-menu-container { flex-grow: 1; overflow-y: auto; padding: 15px 10px; }
        
        ul.sidebar-menu, ul.sidebar-submenu { list-style: none; padding: 0; margin: 0; }
        ul.sidebar-submenu { padding-left: 20px; display: none; }
        ul.sidebar-submenu.open { display: block; }
        
        .nav-item { margin-bottom: 2px; }
        .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 15px; text-decoration: none; color: #4a5568 !important; font-weight: normal !important; border-radius: 6px; transition: 0.2s; }
        
        /* Fixed Active Color */
        .nav-link:hover { background-color: #f4f6f8; color: #0b1a26 !important; }
        .nav-link:hover .menu-text, .nav-link:hover .material-symbols-outlined { color: #0b1a26 !important; }
        .nav-link.active { background-color: #004d40 !important; color: #ffffff !important; }
        .nav-link.active .menu-text, .nav-link.active .material-symbols-outlined { color: #ffffff !important; }
        
        .nav-link .arrow { margin-left: auto; transition: transform 0.3s; font-size: 16px !important; color: #b5c1c9 !important; }
        .nav-link.open .arrow { transform: rotate(180deg); }

        /* MAIN CONTENT */
        .main-content { flex-grow: 1; background-color: #f4f6f8; display: flex; flex-direction: column; position: relative; }
        #contentFrame { width: 100%; height: 100%; border: none; flex-grow: 1; background: #f4f6f8; }
        .welcome-screen { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #7f8c8d; text-align: center; }
        .welcome-screen .material-symbols-outlined { font-size: 64px !important; color: #dce1e5 !important; margin-bottom: 15px; }
        .welcome-screen h2 { font-size: 20px !important; color: #0b1a26 !important; margin: 0 0 10px 0; }

        /* SETTINGS MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; }
        .modal-box { background: #fff; width: 500px; max-width: 95%; border-radius: 8px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e0e4e8; padding-bottom: 15px; margin-bottom: 20px; }
        .modal-title { font-size: 16px !important; font-weight: normal !important; color: #0b1a26 !important; margin: 0; display: flex; align-items: center; gap: 8px; }
        
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 11px !important; font-weight: normal !important; color: #7f8c8d; margin-bottom: 8px; text-transform: uppercase; }
        .form-control { width: 100%; padding: 10px 12px; border: 1px solid #dce1e5; border-radius: 4px; resize: vertical; min-height: 80px; font-family: 'Courier New', Courier, monospace !important; font-size: 12px !important; outline: none; }
        .form-control:focus { border-color: #004d40; box-shadow: 0 0 0 2px rgba(0,77,64,0.1); }
        .form-hint { font-size: 11px !important; color: #b5c1c9; margin-top: 5px; }

        .btn-apply { background: #004d40; color: #fff !important; border: none; padding: 8px 20px; border-radius: 4px; font-weight: normal !important; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 5px;}
        .btn-apply:hover { background: #00695c; }
        .btn-outline { background: #fff; color: #4a5568 !important; border: 1px solid #dce1e5; padding: 8px 20px; border-radius: 4px; font-weight: normal !important; cursor: pointer; transition: 0.2s; }
        .btn-outline:hover { background: #f4f6f8; color: #0b1a26 !important; }

        /* DOCS MODAL */
        .docs-modal-box { width: 800px !important; max-width: 95%; display: flex; flex-direction: column; max-height: 85vh; }
        .docs-tabs { display: flex; gap: 20px; border-bottom: 1px solid #e0e4e8; margin-bottom: 20px; }
        .docs-tab { padding: 10px 0; cursor: pointer; font-weight: normal; color: #7f8c8d; border-bottom: 2px solid transparent; transition: 0.2s; }
        .docs-tab.active { color: #004d40; border-bottom-color: #004d40; }
        .docs-content-area { overflow-y: auto; flex-grow: 1; padding: 10px 5px; white-space: pre-wrap; font-family: 'Courier New', Courier, monospace !important; font-size: 12px !important; line-height: 1.6; background: #fafafa; border-radius: 4px; border: 1px solid #eee; }
    </style>
</head>
<body>

<div class="pandora-header-top">
    <div class="header-left">
        <a href="/pandora_console/index.php" class="logo-link" title="Go to Pandora FMS Home">
            <img src="/pandora_console/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Pandora Logo" class="header-logo" onerror="this.style.display='none'">
        </a>
        <div class="header-divider"></div>
        <div class="header-title-box">
            <span class="main-title">Pandora FMS</span>
            <span class="sub-title">Custom Extensions Portal</span>
        </div>
        
        <div class="custom-search-container">
            <span class="material-symbols-outlined search-icon">search</span>
            <input type="text" id="globalSearch" placeholder="Search menus, dashboards, or tools...">
        </div>
    </div>
    
    <div class="header-right">
        <!-- GLOBAL FORCE REFRESH -->
        <button class="nav-icon-btn" title="Force Global Refresh (Clear Cache)" onclick="forceGlobalRefresh()" style="color: #b91c1c !important;">
            <span class="material-symbols-outlined">bolt</span>
        </button>
        <button class="nav-icon-btn" title="Documentation & Changelog" onclick="openDocs()">
            <span class="material-symbols-outlined">menu_book</span>
        </button>
        <button class="nav-icon-btn" title="Portal Settings" onclick="openSettings()">
            <span class="material-symbols-outlined">settings</span>
        </button>
        <a href="/pandora_console/index.php" class="nav-icon-btn" title="Back to Pandora Console">
            <span class="material-symbols-outlined">logout</span>
        </a>
    </div>
</div>

<script>
    // GLOBAL FORCE REFRESH LOGIC
    function forceGlobalRefresh() {
        const url = new URL(window.location.href);
        url.searchParams.set('t', Date.now());
        window.location.href = url.toString();
    }
</script>

<div class="layout-wrapper">
    <div class="sidebar">
        <div class="sidebar-header">Directory Menu</div>
        <div class="sidebar-menu-container">
            <ul class="sidebar-menu" id="sidebarMenu">
                <?php
                function renderSidebar($tree, $current_page) {
                    $html = '';
                    foreach ($tree as $node) {
                        if ($node['type'] === 'dir') {
                            $isOpen = false;
                            array_walk_recursive($node['children'], function($val, $key) use (&$isOpen, $current_page) {
                                if ($key === 'path' && $val === $current_page) $isOpen = true;
                            });
                            
                            $openClass = $isOpen ? 'open' : '';
                            $html .= '<li class="nav-item has-submenu folder-item">';
                            $html .= '<a href="#" class="nav-link folder-toggle ' . $openClass . '" onclick="toggleFolder(this)">
                                        <span class="material-symbols-outlined" style="color:#f1c40f;">folder</span> <span class="menu-text">' . htmlspecialchars($node['name']) . '</span>
                                        <span class="material-symbols-outlined arrow">expand_more</span>
                                      </a>';
                            $html .= '<ul class="sidebar-submenu ' . $openClass . '">';
                            $html .= renderSidebar($node['children'], $current_page);
                            $html .= '</ul></li>';
                        } else {
                            $isActive = ($current_page === $node['path']) ? 'active' : '';
                            $url = '?page=' . urlencode($node['path']);
                            $icon = 'article';
                            
                            if (stripos($node['name'], 'dashboard') !== false) $icon = 'dashboard';
                            elseif (stripos($node['name'], 'query') !== false) $icon = 'database';
                            elseif (stripos($node['name'], 'converter') !== false) $icon = 'transform';
                            elseif (stripos($node['name'], 'alert') !== false) $icon = 'notifications_active';
                            elseif (stripos($node['name'], 'netflow') !== false) $icon = 'account_tree';

                            $html .= '<li class="nav-item file-item">';
                            $html .= '<a href="' . $url . '" class="nav-link menu-link ' . $isActive . '">
                                        <span class="material-symbols-outlined" style="color:#b5c1c9;">' . $icon . '</span> <span class="menu-text">' . htmlspecialchars($node['name']) . '</span>
                                      </a>';
                            $html .= '</li>';
                        }
                    }
                    return $html;
                }
                echo renderSidebar($menuTree, $current_page);
                ?>
            </ul>
        </div>
    </div>

    <div class="main-content">
        <?php if ($iframe_src !== ''): ?>
            <iframe id="contentFrame" src="<?= htmlspecialchars($iframe_src) ?>" onload="cleanIframeHeader()"></iframe>
        <?php else: ?>
            <div class="welcome-screen">
                <span class="material-symbols-outlined">extension</span>
                <h2>Welcome to Custom Extensions</h2>
                <p>Please select a module or dashboard from the sidebar menu.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="settingsModal">
    <div class="modal-box">
        <div class="modal-header">
            <h5 class="modal-title"><span class="material-symbols-outlined" style="color:#004d40;">settings_suggest</span> Portal Settings</h5>
            <button class="nav-icon-btn" onclick="closeSettings()" style="height:28px; width:28px;"><span class="material-symbols-outlined" style="font-size:16px!important;">close</span></button>
        </div>
        
        <div class="form-group">
            <label class="form-label">Excluded Directories (Folders)</label>
            <textarea class="form-control" id="cfg_dirs" placeholder="e.g. temp, cache, assets"></textarea>
            <div class="form-hint">Separate folder names with commas (,). '..' and '.' systems will always be excluded automatically.</div>
        </div>

        <div class="form-group">
            <label class="form-label">Excluded Files</label>
            <textarea class="form-control" id="cfg_files" placeholder="e.g. config.php, utils.php"></textarea>
            <div class="form-hint">Separate file names with commas (,). 'custom-index.php' file will always be excluded automatically.</div>
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:25px;">
            <button class="btn-outline" onclick="closeSettings()">Cancel</button>
            <button class="btn-apply" onclick="saveSettings()">
                <span class="material-symbols-outlined" style="font-size:16px!important;">save</span> Save & Reload
            </button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="docsModal">
    <div class="modal-box docs-modal-box">
        <div class="modal-header">
            <h5 class="modal-title"><span class="material-symbols-outlined" style="color:#004d40;">library_books</span> Project Information</h5>
            <button class="nav-icon-btn" onclick="closeDocs()" style="height:28px; width:28px;"><span class="material-symbols-outlined" style="font-size:16px!important;">close</span></button>
        </div>
        <div class="docs-tabs">
            <div class="docs-tab active" id="tab_doc" onclick="loadDoc('doc')">Documentation</div>
            <div class="docs-tab" id="tab_changelog" onclick="loadDoc('changelog')">Release Notes (v2.0)</div>
        </div>
        <div class="docs-content-area" id="docsContent">Loading content...</div>
        <div style="display:flex; justify-content:flex-end; margin-top:20px;">
            <button class="btn-apply" onclick="closeDocs()">Close</button>
        </div>
    </div>
</div>

<script>
    const currentConfig = <?= json_encode($config_data) ?>;

    // --- SIDEBAR SEARCH FUNCTION ONLY ---
    document.getElementById('globalSearch').addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase().trim();
        const categories = document.querySelectorAll('.sidebar-menu .has-submenu');
        const files = document.querySelectorAll('.sidebar-menu .file-item');

        if (searchTerm === '') {
            // Reset Sidebar to default
            files.forEach(file => file.style.display = '');
            categories.forEach(cat => {
                cat.style.display = '';
                const submenu = cat.querySelector('.sidebar-submenu');
                const link = cat.querySelector('.folder-toggle');
                if (!submenu.querySelector('.menu-link.active')) {
                    submenu.classList.remove('open');
                    link.classList.remove('open');
                }
            });
        } else {
            // Filter Sidebar
            categories.forEach(cat => {
                let hasVisibleChild = false;
                const childFiles = cat.querySelectorAll('.file-item');
                childFiles.forEach(file => {
                    const text = file.querySelector('.menu-text').textContent.toLowerCase();
                    if (text.includes(searchTerm)) {
                        file.style.display = '';
                        hasVisibleChild = true;
                    } else {
                        file.style.display = 'none';
                    }
                });

                if (hasVisibleChild) {
                    cat.style.display = '';
                    cat.querySelector('.sidebar-submenu').classList.add('open');
                    cat.querySelector('.folder-toggle').classList.add('open');
                } else {
                    cat.style.display = 'none';
                }
            });
            
            const rootFiles = document.querySelectorAll('.sidebar-menu > .file-item');
            rootFiles.forEach(file => {
                 const text = file.querySelector('.menu-text').textContent.toLowerCase();
                 file.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        }
    });

    // Toggle Menu Folder
    function toggleFolder(element) {
        event.preventDefault();
        element.classList.toggle('open');
        const submenu = element.nextElementSibling;
        if (submenu) {
            submenu.classList.toggle('open');
        }
    }

    // Modal Settings
    function openSettings() {
        let dirs = currentConfig.exclude_dirs.filter(d => d !== '.' && d !== '..').join(', ');
        let files = currentConfig.exclude_files.filter(f => f !== 'custom-index.php').join(', ');
        
        document.getElementById('cfg_dirs').value = dirs;
        document.getElementById('cfg_files').value = files;
        document.getElementById('settingsModal').style.display = 'flex';
    }

    function closeSettings() {
        document.getElementById('settingsModal').style.display = 'none';
    }

    async function saveSettings() {
        const btn = event.currentTarget;
        btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px!important;">sync</span> Saving...';
        btn.disabled = true;

        const dirsInput = document.getElementById('cfg_dirs').value;
        const filesInput = document.getElementById('cfg_files').value;

        const payload = {
            exclude_dirs: dirsInput.split(','),
            exclude_files: filesInput.split(',')
        };

        try {
            const response = await fetch('?api=save_settings', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= $csrf_token ?>'
                },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            
            if (result.ok) {
                window.location.reload();
            } else {
                alert('Save failed: ' + result.error);
                btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px!important;">save</span> Save & Reload';
                btn.disabled = false;
            }
        } catch (error) {
            alert('Network error while saving settings.');
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px!important;">save</span> Save & Reload';
            btn.disabled = false;
        }
    }

    // Docs Viewer Logic
    function openDocs() {
        document.getElementById('docsModal').style.display = 'flex';
        loadDoc('doc');
    }

    function closeDocs() {
        document.getElementById('docsModal').style.display = 'none';
    }

    async function loadDoc(type) {
        // Toggle tabs
        document.getElementById('tab_doc').classList.toggle('active', type === 'doc');
        document.getElementById('tab_changelog').classList.toggle('active', type === 'changelog');

        const contentArea = document.getElementById('docsContent');
        contentArea.innerText = 'Loading content...';

        try {
            const response = await fetch('?api=read_docs&file=' + type);
            const result = await response.json();
            if (result.ok) {
                contentArea.innerText = result.content;
            } else {
                contentArea.innerText = 'Error: ' + result.error;
            }
        } catch (e) {
            contentArea.innerText = 'Error loading documentation file.';
        }
    }
</script>

</body>
</html>


