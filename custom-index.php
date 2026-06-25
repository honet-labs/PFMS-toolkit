<?php
/**
 * PFMS-Toolkit
 * Version: 1.0.4 (Architecture Update & Auto-Updater)
 */

define('PORTAL_VERSION', '1.0.4');

// 1. SECURITY HEADERS
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

if (isset($_GET['clear_cache'])) {
    if (function_exists('opcache_reset') && opcache_reset()) {
        echo "PHP OpCache reset successfully!";
    } else {
        echo "OpCache is not enabled or failed to reset.";
    }
    exit;
}

// 2. CORE UTILS & DB CONNECTION
require_once __DIR__ . '/includes/db-connection.php';

$base_dir = __DIR__; 
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$base_url = rtrim(str_replace('\\', '/', dirname($script_name)), '/');
$pandora_base = dirname(dirname($base_url));
if ($pandora_base === '/' || $pandora_base === '\\') {
    $pandora_base = '';
}
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
    header("Location: " . ($pandora_base ?: '') . "/index.php");
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
    'exclude_dirs' => ['temp', 'cache', 'assets', 'includes', 'versions', 'scanning-mib', 'snmp-explorer', 'scratch'],
    'exclude_files' => ['nfx_local_config.php', 'pdb_local_config.php', 'config.php', 'utils.php', 'check_cols.php,', 'check_schema.php', 'check_schema_v2.php', 'temp_query.php', 'pfms_latency_map.php', 'api_network.php', 'cron.php', 'pfms_latency_map.php', 'pfms_lib.php', ],
    'custom_connections' => []
];

// Load Configuration if exists
if (file_exists($portal_config_file)) {
    $loaded_config = json_decode(file_get_contents($portal_config_file), true);
    if (is_array($loaded_config)) {
        $config_data['exclude_dirs'] = $loaded_config['exclude_dirs'] ?? $config_data['exclude_dirs'];
        $config_data['exclude_files'] = $loaded_config['exclude_files'] ?? $config_data['exclude_files'];
        $config_data['custom_connections'] = $loaded_config['custom_connections'] ?? $config_data['custom_connections'];
    }
}

// Ensure critical system files/dirs are ALWAYS excluded
$sys_dirs = ['.', '..', '.git'];
$sys_files = ['custom-index.php'];
$active_exclude_dirs = array_unique(array_merge($config_data['exclude_dirs'], $sys_dirs));
$active_exclude_files = array_unique(array_merge($config_data['exclude_files'], $sys_files));

// API Forwarding / Routing to sub-pages to prevent 500 errors caused by direct execution blocks in webservers
if (isset($_GET['api']) && !empty($_GET['page'])) {
    $target_page = $_GET['page'];
    if (!preg_match('/\.\./', $target_page)) {
        $target_file = $base_dir . '/' . $target_page;
        if (file_exists($target_file) && pathinfo($target_file, PATHINFO_EXTENSION) === 'php') {
            require $target_file;
            exit;
        }
    }
}

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
            'exclude_files' => array_values(array_filter(array_map('trim', $input['exclude_files']))),
            'custom_connections' => isset($input['custom_connections']) && is_array($input['custom_connections']) ? $input['custom_connections'] : []
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

if (isset($_GET['api']) && $_GET['api'] === 'test_db_connection') {
    ob_clean();
    header('Content-Type: application/json');
    
    // CSRF Validation
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($client_token !== $csrf_token) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh page.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (is_array($input)) {
        $host = trim($input['host'] ?? '');
        $port = trim($input['port'] ?? '3306');
        $dbname = trim($input['dbname'] ?? '');
        $user = trim($input['user'] ?? '');
        $pass = trim($input['pass'] ?? '');

        if (empty($host) || empty($dbname) || empty($user)) {
            echo json_encode(['ok' => false, 'error' => 'Host, Database Name, and User are required.']);
            exit;
        }

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $test_pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2
            ]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid data']);
    }
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'test_core_connection') {
    ob_clean();
    header('Content-Type: application/json');
    
    // CSRF Validation
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($client_token !== $csrf_token) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh page.']);
        exit;
    }

    $type = $_GET['type'] ?? 'primary';
    if ($type === 'primary') {
        try {
            $h_host = $config['dbhost'] ?? '';
            $h_port = !empty($config['dbport']) ? (int)$config['dbport'] : 3306;
            $h_dbname = $config['dbname'] ?? '';
            $h_user = $config['dbuser'] ?? '';
            $h_pass = $config['dbpass'] ?? '';
            
            $dsn = "mysql:host={$h_host};port={$h_port};dbname={$h_dbname};charset=utf8mb4";
            $test_pdo = new PDO($dsn, $h_user, $h_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2
            ]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    } elseif ($type === 'history') {
        try {
            $h_host = null;
            $h_port = 3306;
            $h_dbname = null;
            $h_user = null;
            $h_pass = null;
            
            if (isset($config['dbhost_history']) && !empty($config['dbhost_history'])) {
                $h_host = $config['dbhost_history'];
                $h_dbname = $config['dbname_history'] ?? $config['dbname'];
                $h_user = $config['dbuser_history'] ?? $config['dbuser'];
                $h_pass = $config['dbpass_history'] ?? $config['dbpass'];
                $h_port = !empty($config['dbport_history']) ? (int)$config['dbport_history'] : 3306;
            } elseif ($db_status) {
                // Try from tconfig
                $stmt = $pdo->query("SELECT token, value FROM tconfig WHERE token LIKE 'history_%'");
                $histConfig = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $histConfig[$row['token']] = $row['value'];
                }
                
                if (!empty($histConfig['history_host']) && !empty($histConfig['history_db'])) {
                    $h_host = $histConfig['history_host'];
                    $h_port = !empty($histConfig['history_port']) ? (int)$histConfig['history_port'] : 3306;
                    $h_dbname = $histConfig['history_db'];
                    $h_user = $histConfig['history_user'];
                    $h_pass = $histConfig['history_pass'];
                    
                    if (function_exists('io_safe_decrypt')) {
                        $h_pass = io_safe_decrypt($h_pass);
                    }
                }
            }
            
            if (!$h_host || !$h_dbname) {
                echo json_encode(['ok' => false, 'error' => 'No core historical database configured.']);
                exit;
            }
            
            $dsn = "mysql:host={$h_host};port={$h_port};dbname={$h_dbname};charset=utf8mb4";
            $test_pdo = new PDO($dsn, $h_user, $h_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2
            ]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid type']);
    }
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'check_update') {
    ob_clean();
    header('Content-Type: application/json');

    $response = [
        'ok' => true,
        'update_available' => false,
        'local_version' => PORTAL_VERSION,
        'remote_version' => PORTAL_VERSION,
        'commit_message' => 'Your system is up to date.',
        'method' => 'git'
    ];

    $cache_file = $base_dir . '/temp/update_cache.json';
    $cache_lifetime = 10800; // 3 hours
    $force = isset($_GET['force']) && $_GET['force'] === '1';

    if (!$force && file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_lifetime) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if (is_array($cached)) {
            echo json_encode($cached);
            exit;
        }
    }

    $is_git = is_dir($base_dir . '/.git');
    $git_available = false;
    if ($is_git) {
        @exec('git --version', $out, $status);
        if ($status === 0) {
            $git_available = true;
        }
    }

    if ($git_available) {
        $fetch_output = [];
        $fetch_status = -1;
        @exec('git fetch origin main 2>&1', $fetch_output, $fetch_status);

        if ($fetch_status === 0) {
            $local_sha = trim(@shell_exec('git rev-parse HEAD'));
            $remote_sha = trim(@shell_exec('git rev-parse origin/main'));

            if ($local_sha !== $remote_sha && !empty($remote_sha)) {
                $count = trim(@shell_exec('git rev-list --count HEAD..origin/main'));
                $commits_log = @shell_exec('git log HEAD..origin/main --oneline -n 3');

                $response['update_available'] = true;
                $response['remote_version'] = substr($remote_sha, 0, 7);
                $response['local_version'] = substr($local_sha, 0, 7);
                $response['commit_message'] = "Found " . $count . " new commit(s) on GitHub:\n" . trim($commits_log);
                $response['method'] = 'git';
            }
        } else {
            $git_available = false;
        }
    }

    if (!$git_available) {
        $repo = 'aannddrrii294/PFMS-Toolkit';
        $url = "https://api.github.com/repos/{$repo}/commits/main";
        
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => [
                    'User-Agent: PFMS-Toolkit-Updater',
                    'Accept: application/vnd.github.v3+json'
                ],
                'timeout' => 5
            ]
        ];
        
        $context = stream_context_create($opts);
        $result = @file_get_contents($url, false, $context);
        
        if ($result !== false) {
            $data = json_decode($result, true);
            if (is_array($data) && isset($data['sha'])) {
                $remote_sha = $data['sha'];
                $commit_msg = $data['commit']['message'] ?? '';
                
                $local_sha = '';
                if ($is_git) {
                    $local_sha = trim(@shell_exec('git rev-parse HEAD'));
                }
                
                if (empty($local_sha)) {
                    $response['update_available'] = false;
                } else if ($local_sha !== $remote_sha) {
                    $response['update_available'] = true;
                    $response['remote_version'] = substr($remote_sha, 0, 7);
                    $response['local_version'] = substr($local_sha, 0, 7);
                    $response['commit_message'] = "Latest GitHub Commit:\n" . trim($commit_msg);
                    $response['method'] = 'zip';
                }
            } else {
                $response['ok'] = false;
                $response['error'] = 'Invalid response from GitHub API. Please try again.';
            }
        } else {
            $response['ok'] = false;
            $response['error'] = 'Web server cannot connect to api.github.com. Internet access is restricted or blocked on this server.';
        }
    }

    if (!is_dir(dirname($cache_file))) {
        @mkdir(dirname($cache_file), 0777, true);
    }
    @file_put_contents($cache_file, json_encode($response));

    echo json_encode($response);
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'execute_update') {
    ob_clean();
    header('Content-Type: application/json');

    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($client_token !== $csrf_token) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh page.']);
        exit;
    }

    @set_time_limit(0);

    $logs = [];
    $logs[] = "[1/4] Checking environment...";

    $method = $_GET['method'] ?? 'git';
    $success = false;

    if ($method === 'git') {
        $logs[] = "[2/4] Pulling latest code using Git...";
        
        $is_git = is_dir($base_dir . '/.git');
        if (!$is_git) {
            $logs[] = "ERROR: Local directory is not a Git repository.";
            echo json_encode(['ok' => false, 'logs' => implode("\n", $logs)]);
            exit;
        }

        $fetch_out = [];
        $fetch_status = -1;
        $logs[] = "Executing: git fetch origin main";
        @exec("git fetch origin main 2>&1", $fetch_out, $fetch_status);
        $logs = array_merge($logs, $fetch_out);

        if ($fetch_status !== 0) {
            $logs[] = "ERROR: git fetch failed with status code " . $fetch_status;
            echo json_encode(['ok' => false, 'logs' => implode("\n", $logs)]);
            exit;
        }

        $reset_out = [];
        $reset_status = -1;
        $logs[] = "Executing: git reset --hard origin/main";
        @exec("git reset --hard origin/main 2>&1", $reset_out, $reset_status);
        $logs = array_merge($logs, $reset_out);

        if ($reset_status === 0) {
            $success = true;
            $logs[] = "Git reset completed successfully.";
        } else {
            $logs[] = "ERROR: git reset failed with status code " . $reset_status;
        }
    } else {
        $logs[] = "[2/4] Downloading update ZIP from GitHub...";
        
        $zip_url = "https://github.com/aannddrrii294/PFMS-Toolkit/archive/refs/heads/main.zip";
        $zip_file = $base_dir . '/temp/update.zip';
        $extract_dir = $base_dir . '/temp/patch/';

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => ['User-Agent: PandoraFMS-Custom-Portal-Updater'],
                'timeout' => 30
            ]
        ];
        $context = stream_context_create($opts);
        $zip_data = @file_get_contents($zip_url, false, $context);

        if ($zip_data === false) {
            $logs[] = "ERROR: Failed to download update ZIP from GitHub.";
            echo json_encode(['ok' => false, 'logs' => implode("\n", $logs)]);
            exit;
        }

        if (!is_dir(dirname($zip_file))) {
            @mkdir(dirname($zip_file), 0777, true);
        }
        @file_put_contents($zip_file, $zip_data);
        $logs[] = "Downloaded ZIP (" . number_format(strlen($zip_data)) . " bytes).";

        if (!class_exists('ZipArchive')) {
            $logs[] = "ERROR: PHP ZipArchive extension is not enabled.";
            @unlink($zip_file);
            echo json_encode(['ok' => false, 'logs' => implode("\n", $logs)]);
            exit;
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_file) === TRUE) {
            if (is_dir($extract_dir)) {
                self_delete_dir_helper($extract_dir);
            }
            @mkdir($extract_dir, 0777, true);
            $zip->extractTo($extract_dir);
            $zip->close();
            $logs[] = "ZIP extracted to temp/patch/.";
        } else {
            $logs[] = "ERROR: Failed to open ZIP file.";
            @unlink($zip_file);
            echo json_encode(['ok' => false, 'logs' => implode("\n", $logs)]);
            exit;
        }

        $subdirs = glob($extract_dir . '*', GLOB_ONLYDIR);
        if (empty($subdirs)) {
            $logs[] = "ERROR: Extracted folder unexpected format.";
            @unlink($zip_file);
            self_delete_dir_helper($extract_dir);
            echo json_encode(['ok' => false, 'logs' => implode("\n", $logs)]);
            exit;
        }
        $patch_source = $subdirs[0];

        $logs[] = "Copying patch files to production...";
        $copy_success = copy_directory_helper($patch_source, $base_dir, ['portal_config.json', 'temp', 'cache']);

        @unlink($zip_file);
        self_delete_dir_helper($extract_dir);

        if ($copy_success) {
            $success = true;
            $logs[] = "ZIP update completed successfully.";
        } else {
            $logs[] = "ERROR: Failed to copy files. Check directory permissions.";
        }
    }

    if ($success) {
        $logs[] = "[3/4] Clearing cache files...";
        $menu_cache_file = $base_dir . '/temp/menu_cache.json';
        $update_cache_file = $base_dir . '/temp/update_cache.json';
        if (file_exists($menu_cache_file)) @unlink($menu_cache_file);
        if (file_exists($update_cache_file)) @unlink($update_cache_file);

        $logs[] = "[4/4] UPDATE COMPLETED SUCCESSFULLY!";
        echo json_encode(['ok' => true, 'logs' => implode("\n", $logs)]);
    } else {
        echo json_encode(['ok' => false, 'logs' => implode("\n", $logs)]);
    }
    exit;
}

function self_delete_dir_helper($dir) {
    if (!is_dir($dir)) return;
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            self_delete_dir_helper($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function copy_directory_helper($src, $dst, $exclude = []) {
    $success = true;
    $dir = opendir($src);
    @mkdir($dst, 0777, true);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (in_array($file, $exclude)) {
                continue;
            }
            if (is_dir($src . '/' . $file)) {
                $res = copy_directory_helper($src . '/' . $file, $dst . '/' . $file, $exclude);
                if (!$res) $success = false;
            } else {
                $res = @copy($src . '/' . $file, $dst . '/' . $file);
                if (!$res) $success = false;
            }
        }
    }
    closedir($dir);
    return $success;
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
        
        // Forward all query parameters except 'page' and 't' to the iframe
        $get_params = $_GET;
        unset($get_params['page'], $get_params['t']);
        $query_str = !empty($get_params) ? '&' . http_build_query($get_params) : '';
        
        $iframe_src = $base_url . '/' . $current_page . '?v=' . $v . $query_str;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PFMS-Toolkit</title>
    
    <link rel="icon" href="<?= htmlspecialchars($pandora_base) ?>/images/pandora.ico" type="image/x-icon">
    
    
    <link href="<?= htmlspecialchars($base_url) ?>/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($base_url) ?>/vendor/fonts/fonts.css" />

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
        
        /* UPDATER STYLES */
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .badge-pulse { animation: pulse 2s infinite; }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .updater-console { background: #0b1622 !important; color: #a5b4fc !important; border: 1px solid #1e293b; border-radius: 6px; padding: 15px; font-family: 'Courier New', Courier, monospace !important; font-size: 12px !important; height: 160px; overflow-y: auto; margin-bottom: 20px; white-space: pre-wrap; line-height: 1.5; }
        .update-badge { position: absolute; top: 3px; right: 3px; width: 8px; height: 8px; background-color: #ef4444; border-radius: 50%; }
        .version-tag { background: #e2e8f0; color: #475569; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
        .version-tag.latest { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>

<div class="pandora-header-top">
    <div class="header-left">
        <a href="<?= htmlspecialchars($pandora_base ?: '') ?>/index.php" class="logo-link" title="Go to Pandora FMS Home">
            <img src="<?= htmlspecialchars($pandora_base ?: '') ?>/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Pandora Logo" class="header-logo" onerror="this.style.display='none'">
        </a>
        <div class="header-divider"></div>
        <div class="header-title-box">
            <span class="main-title">Pandora FMS</span>
            <span class="sub-title">PFMS-Toolkit</span>
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
        <button class="nav-icon-btn" id="updateNavBtn" title="Check for Updates" onclick="openUpdater()" style="position: relative;">
            <span class="material-symbols-outlined">system_update_alt</span>
            <span id="updateBadge" class="update-badge badge-pulse" style="display: none;"></span>
        </button>
        <button class="nav-icon-btn" title="Portal Settings" onclick="openSettings()">
            <span class="material-symbols-outlined">settings</span>
        </button>
        <a href="<?= htmlspecialchars($pandora_base ?: '') ?>/index.php" class="nav-icon-btn" title="Back to Pandora Console">
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
                <h2>Welcome to PFMS-Toolkit</h2>
                <p>Please select a module or dashboard from the sidebar menu.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="settingsModal">
    <div class="modal-box" style="width: 650px; max-width: 95%; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="modal-header">
            <h5 class="modal-title"><span class="material-symbols-outlined" style="color:#004d40;">settings_suggest</span> Portal Settings</h5>
            <button class="nav-icon-btn" onclick="closeSettings()" style="height:28px; width:28px;"><span class="material-symbols-outlined" style="font-size:16px!important;">close</span></button>
        </div>
        
        <div style="flex-grow: 1; overflow-y: auto; padding-right: 5px; margin-bottom: 20px;">
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

            <div class="form-group" style="margin-top: 25px; border-top: 1px solid #e0e4e8; padding-top: 20px;">
                <label class="form-label" style="display: flex; align-items: center; gap: 5px; margin-bottom: 12px; color: #0b1a26; font-weight: 600;">
                    <span class="material-symbols-outlined" style="color: #004d40; font-size: 20px !important;">database</span>
                    Core Database Connections
                </label>
                
                <!-- Primary Database Connection Card -->
                <div style="background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 15px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-weight: 600; color: #334155; font-size: 13px;">Primary Database</span>
                        <span style="font-family: monospace; font-size: 11px; color: #64748b;">
                            Host: <?= htmlspecialchars($config['dbhost'] ?? 'N/A') ?> | DB: <?= htmlspecialchars($config['dbname'] ?? 'N/A') ?> | User: <?= htmlspecialchars($config['dbuser'] ?? 'N/A') ?>
                        </span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div id="primary_conn_status" style="display: flex; align-items: center; gap: 6px;">
                            <?php if ($db_status): ?>
                                <span style="display: inline-block; width: 8px; height: 8px; background-color: #10b981; border-radius: 50%;"></span>
                                <span style="font-size: 11px; color: #065f46; font-weight: 600; background-color: #d1fae5; padding: 2px 6px; border-radius: 4px;">Connected</span>
                            <?php else: ?>
                                <span style="display: inline-block; width: 8px; height: 8px; background-color: #ef4444; border-radius: 50%;"></span>
                                <span style="font-size: 11px; color: #991b1b; font-weight: 600; background-color: #fee2e2; padding: 2px 6px; border-radius: 4px;" title="<?= htmlspecialchars($db_error) ?>">Failed</span>
                            <?php endif; ?>
                        </div>
                        <button class="nav-icon-btn" style="height:28px; width:28px;" title="Test Connection" onclick="testCoreConnection('primary')">
                            <span class="material-symbols-outlined" style="font-size:16px!important; color:#004d40!important;">sync</span>
                        </button>
                    </div>
                </div>

                <!-- Historical Database Connection Card -->
                <div style="background: #f8f9fa; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px 15px; display: flex; align-items: center; justify-content: space-between;">
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <span style="font-weight: 600; color: #334155; font-size: 13px;">Default Historical Database</span>
                        <?php if ($history_db_host): ?>
                            <span style="font-family: monospace; font-size: 11px; color: #64748b;">
                                Host: <?= htmlspecialchars($history_db_host) ?> | DB: <?= htmlspecialchars($history_db_name) ?> | User: <?= htmlspecialchars($history_db_user) ?>
                            </span>
                        <?php else: ?>
                            <span style="font-size: 11px; color: #94a3b8; font-style: italic;">No core historical DB configured.</span>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div id="default_history_conn_status" style="display: flex; align-items: center; gap: 6px;">
                            <?php if ($history_db_status): ?>
                                <span style="display: inline-block; width: 8px; height: 8px; background-color: #10b981; border-radius: 50%;"></span>
                                <span style="font-size: 11px; color: #065f46; font-weight: 600; background-color: #d1fae5; padding: 2px 6px; border-radius: 4px;">Connected</span>
                            <?php elseif ($history_db_host): ?>
                                <span style="display: inline-block; width: 8px; height: 8px; background-color: #ef4444; border-radius: 50%;"></span>
                                <span style="font-size: 11px; color: #991b1b; font-weight: 600; background-color: #fee2e2; padding: 2px 6px; border-radius: 4px;">Failed</span>
                            <?php else: ?>
                                <span style="display: inline-block; width: 8px; height: 8px; background-color: #94a3b8; border-radius: 50%;"></span>
                                <span style="font-size: 11px; color: #475569; font-weight: 600; background-color: #e2e8f0; padding: 2px 6px; border-radius: 4px;">Not Configured</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($history_db_host): ?>
                            <button class="nav-icon-btn" style="height:28px; width:28px;" title="Test Connection" onclick="testCoreConnection('history')">
                                <span class="material-symbols-outlined" style="font-size:16px!important; color:#004d40!important;">sync</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Custom Connections Header -->
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; border-top: 1px solid #e0e4e8; padding-top: 15px; margin-bottom: 12px;">
                <label class="form-label" style="display: flex; align-items: center; gap: 5px; color: #0b1a26; font-weight: 600; margin-bottom: 0;">
                    <span class="material-symbols-outlined" style="color: #004d40; font-size: 20px !important;">dns</span>
                    Custom Connections (Historical DB Nodes)
                </label>
                <button class="btn-outline" style="padding: 4px 10px; font-size: 12px; display: flex; align-items: center; gap: 4px;" onclick="showAddConnectionForm()">
                    <span class="material-symbols-outlined" style="font-size: 14px !important;">add</span> Add Connection
                </button>
            </div>

            <!-- Custom Connection Form (Hidden by default) -->
            <div id="customConnForm" style="display: none; background: #f8f9fa; border: 1px solid #004d40; border-radius: 6px; padding: 15px; margin-bottom: 15px;">
                <h6 id="formTitle" style="margin: 0 0 12px 0; color: #004d40; font-size: 13px; font-weight: 600;">Add Custom Connection</h6>
                <input type="hidden" id="conn_index" value="">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <label class="form-label" style="font-size: 10px !important; margin-bottom: 4px;">Connection Name</label>
                        <input type="text" id="conn_name" class="form-control" style="min-height: auto; height: 32px; font-family: inherit !important; font-size: 12px !important;" placeholder="e.g. Historical Node 2">
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 10px !important; margin-bottom: 4px;">Host</label>
                        <input type="text" id="conn_host" class="form-control" style="min-height: auto; height: 32px; font-family: inherit !important; font-size: 12px !important;" placeholder="e.g. 192.168.1.100">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px;">
                    <div>
                        <label class="form-label" style="font-size: 10px !important; margin-bottom: 4px;">Port</label>
                        <input type="text" id="conn_port" class="form-control" style="min-height: auto; height: 32px; font-family: inherit !important; font-size: 12px !important;" placeholder="3306" value="3306">
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 10px !important; margin-bottom: 4px;">Database Name</label>
                        <input type="text" id="conn_dbname" class="form-control" style="min-height: auto; height: 32px; font-family: inherit !important; font-size: 12px !important;" placeholder="e.g. pandora_history2">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px;">
                    <div>
                        <label class="form-label" style="font-size: 10px !important; margin-bottom: 4px;">User</label>
                        <input type="text" id="conn_user" class="form-control" style="min-height: auto; height: 32px; font-family: inherit !important; font-size: 12px !important;" placeholder="e.g. root">
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 10px !important; margin-bottom: 4px;">Password</label>
                        <input type="password" id="conn_pass" class="form-control" style="min-height: auto; height: 32px; font-family: inherit !important; font-size: 12px !important;" placeholder="Enter password">
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <button class="btn-outline" style="padding: 4px 12px; font-size: 11px;" id="btnTestConn" onclick="testCustomConnection()">Test Connection</button>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn-outline" style="padding: 4px 12px; font-size: 11px;" onclick="cancelConnectionForm()">Cancel</button>
                        <button class="btn-apply" style="padding: 4px 15px; font-size: 11px; background: #004d40;" onclick="saveCustomConnection()">Save Node</button>
                    </div>
                </div>
                <div id="testConnResult" style="margin-top: 8px; font-size: 11px; display: none;"></div>
            </div>

            <!-- Custom Connections List Container -->
            <div id="customConnsList">
                <!-- Will be dynamically populated by Javascript -->
            </div>
        </div>
        
        <div style="display:flex; justify-content:flex-end; gap:10px; border-top: 1px solid #e0e4e8; padding-top: 15px;">
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

<div class="modal-overlay" id="updaterModal">
    <div class="modal-box" style="width: 550px; max-width: 95%; padding: 25px;">
        <div class="modal-header">
            <h5 class="modal-title"><span class="material-symbols-outlined" style="color:#004d40;">system_update_alt</span> System Updater</h5>
            <button class="nav-icon-btn" id="closeUpdaterBtn" onclick="closeUpdater()" style="height:28px; width:28px;"><span class="material-symbols-outlined" style="font-size:16px!important;">close</span></button>
        </div>
        
        <div id="updaterCheckState" style="padding: 20px 0; text-align: center;">
            <span class="material-symbols-outlined" style="font-size:48px!important; color:#004d40; animation: spin 2s linear infinite;">sync</span>
            <p style="margin-top: 15px; color:#4a5568; font-size:14px;">Checking for updates from GitHub...</p>
        </div>
        
        <div id="updaterViewState" style="display: none;">
            <div style="display: flex; gap: 20px; align-items: center; background: #f8f9fa; border: 1px solid #e2e8f0; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                <span class="material-symbols-outlined" id="updateStateIcon" style="font-size: 36px !important; color: #004d40;">check_circle</span>
                <div style="flex-grow: 1;">
                    <div style="font-weight: 600; color: #0b1a26; font-size: 15px;" id="updateStateTitle">Your system is up to date!</div>
                    <div style="margin-top: 4px; display: flex; gap: 15px; font-size: 12px; color: #64748b;">
                        <span>Current: <span class="version-tag" id="localVersionTag">v<?= PORTAL_VERSION ?></span></span>
                        <span id="latestVersionWrapper">Latest: <span class="version-tag latest" id="remoteVersionTag">v<?= PORTAL_VERSION ?></span></span>
                    </div>
                </div>
            </div>
            
            <div style="margin-bottom: 20px;">
                <label class="form-label" style="margin-bottom: 8px;">Release Notes / Commits</label>
                <div style="background: #fafafa; border: 1px solid #eee; border-radius: 4px; padding: 12px; font-family: monospace; font-size: 12px; line-height: 1.5; max-height: 120px; overflow-y: auto; white-space: pre-wrap; color: #475569;" id="updateChangelog">
                    No new updates.
                </div>
            </div>
            
            <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #e0e4e8; padding-top: 20px;">
                <button class="btn-outline" onclick="closeUpdater()">Cancel</button>
                <button class="btn-apply" id="updateExecuteBtn" onclick="runUpdate()">
                    <span class="material-symbols-outlined" style="font-size:16px!important;">system_update_alt</span> Update Now
                </button>
            </div>
        </div>
        
        <div id="updaterProgressState" style="display: none;">
            <h5 style="margin-top:0; color:#0b1a26; font-size: 15px; font-weight: 600; display:flex; align-items:center; gap:8px;">
                <span class="material-symbols-outlined" style="animation: spin 2s linear infinite; color:#004d40;">sync</span> Updating System
            </h5>
            <p style="font-size: 12px; color: #64748b; margin-top: 5px;">Executing script pull and clearing local caches...</p>
            
            <div class="updater-console" id="updaterConsole">
                Initializing console...
            </div>
            
            <div style="font-size: 11px; color:#94a3b8; display:flex; align-items:center; gap:5px; justify-content:center;">
                <span class="material-symbols-outlined" style="font-size:12px!important;">warning</span>
                <span>Please do not close this modal or refresh the page until the update finishes.</span>
            </div>
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
    let customConnectionsCopy = [];

    function openSettings() {
        let dirs = currentConfig.exclude_dirs.filter(d => d !== '.' && d !== '..').join(', ');
        let files = currentConfig.exclude_files.filter(f => f !== 'custom-index.php').join(', ');
        
        document.getElementById('cfg_dirs').value = dirs;
        document.getElementById('cfg_files').value = files;

        // Deep copy custom connections
        customConnectionsCopy = JSON.parse(JSON.stringify(currentConfig.custom_connections || []));
        cancelConnectionForm();
        renderCustomConnectionsList();
        
        document.getElementById('settingsModal').style.display = 'flex';
    }

    function closeSettings() {
        document.getElementById('settingsModal').style.display = 'none';
    }

    // Render the custom connections list
    function renderCustomConnectionsList() {
        const container = document.getElementById('customConnsList');
        container.innerHTML = '';

        if (customConnectionsCopy.length === 0) {
            container.innerHTML = '<div style="font-size:12px; color:#94a3b8; font-style:italic; padding:15px; text-align:center; background:#f8f9fa; border:1px dashed #e2e8f0; border-radius:6px;">No custom connections added yet.</div>';
            return;
        }

        customConnectionsCopy.forEach((conn, index) => {
            const card = document.createElement('div');
            card.style.background = '#f8f9fa';
            card.style.border = '1px solid #e2e8f0';
            card.style.borderRadius = '6px';
            card.style.padding = '12px 15px';
            card.style.marginBottom = '10px';
            card.style.display = 'flex';
            card.style.alignItems = 'center';
            card.style.justifyContent = 'space-between';

            card.innerHTML = `
                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <span style="font-weight: 600; color: #334155; font-size: 13px;">${escapeHtml(conn.name || 'Unnamed Connection')}</span>
                    <span style="font-family: monospace; font-size: 11px; color: #64748b;">
                        Host: ${escapeHtml(conn.host)}:${escapeHtml(conn.port || '3306')} | DB: ${escapeHtml(conn.dbname)} | User: ${escapeHtml(conn.user)}
                    </span>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div id="conn_status_${index}" style="display: flex; align-items: center; gap: 6px;">
                        <span style="display: inline-block; width: 8px; height: 8px; background-color: #94a3b8; border-radius: 50%;"></span>
                        <span style="font-size: 11px; color: #64748b; font-weight: 600; background-color: #e2e8f0; padding: 2px 6px; border-radius: 4px;">Testing...</span>
                    </div>
                    <div style="display: flex; gap: 4px;">
                        <button class="nav-icon-btn" style="height:28px; width:28px;" title="Edit Connection" onclick="editConnection(${index})">
                            <span class="material-symbols-outlined" style="font-size:16px!important; color:#0284c7!important;">edit</span>
                        </button>
                        <button class="nav-icon-btn" style="height:28px; width:28px;" title="Delete Connection" onclick="deleteConnection(${index})">
                            <span class="material-symbols-outlined" style="font-size:16px!important; color:#ef4444!important;">delete</span>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(card);
            
            // Trigger connection test asynchronously
            testConnectionAsync(conn, index);
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    async function testCoreConnection(type) {
        const statusEl = document.getElementById(type === 'primary' ? 'primary_conn_status' : 'default_history_conn_status');
        if (!statusEl) return;
        
        statusEl.innerHTML = `
            <span style="display: inline-block; width: 8px; height: 8px; background-color: #94a3b8; border-radius: 50%;"></span>
            <span style="font-size: 11px; color: #64748b; font-weight: 600; background-color: #e2e8f0; padding: 2px 6px; border-radius: 4px;">Testing...</span>
        `;
        
        try {
            const response = await fetch('?api=test_core_connection&type=' + type, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= $csrf_token ?>'
                }
            });
            const result = await response.json();
            if (result.ok) {
                statusEl.innerHTML = `
                    <span style="display: inline-block; width: 8px; height: 8px; background-color: #10b981; border-radius: 50%;"></span>
                    <span style="font-size: 11px; color: #065f46; font-weight: 600; background-color: #d1fae5; padding: 2px 6px; border-radius: 4px;">Connected</span>
                `;
            } else {
                statusEl.innerHTML = `
                    <span style="display: inline-block; width: 8px; height: 8px; background-color: #ef4444; border-radius: 50%;"></span>
                    <span style="font-size: 11px; color: #991b1b; font-weight: 600; background-color: #fee2e2; padding: 2px 6px; border-radius: 4px;" title="${escapeHtml(result.error)}">Failed</span>
                `;
            }
        } catch (e) {
            statusEl.innerHTML = `
                <span style="display: inline-block; width: 8px; height: 8px; background-color: #ef4444; border-radius: 50%;"></span>
                <span style="font-size: 11px; color: #991b1b; font-weight: 600; background-color: #fee2e2; padding: 2px 6px; border-radius: 4px;" title="Network Error">Error</span>
            `;
        }
    }

    async function testConnectionAsync(conn, index) {
        const statusEl = document.getElementById(`conn_status_${index}`);
        if (!statusEl) return;

        try {
            const response = await fetch('?api=test_db_connection', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= $csrf_token ?>'
                },
                body: JSON.stringify({
                    host: conn.host,
                    port: conn.port || '3306',
                    dbname: conn.dbname,
                    user: conn.user,
                    pass: conn.pass
                })
            });
            const result = await response.json();
            if (result.ok) {
                statusEl.innerHTML = `
                    <span style="display: inline-block; width: 8px; height: 8px; background-color: #10b981; border-radius: 50%;"></span>
                    <span style="font-size: 11px; color: #065f46; font-weight: 600; background-color: #d1fae5; padding: 2px 6px; border-radius: 4px;">Connected</span>
                `;
            } else {
                statusEl.innerHTML = `
                    <span style="display: inline-block; width: 8px; height: 8px; background-color: #ef4444; border-radius: 50%;"></span>
                    <span style="font-size: 11px; color: #991b1b; font-weight: 600; background-color: #fee2e2; padding: 2px 6px; border-radius: 4px;" title="${escapeHtml(result.error)}">Failed</span>
                `;
            }
        } catch (e) {
            statusEl.innerHTML = `
                <span style="display: inline-block; width: 8px; height: 8px; background-color: #ef4444; border-radius: 50%;"></span>
                <span style="font-size: 11px; color: #991b1b; font-weight: 600; background-color: #fee2e2; padding: 2px 6px; border-radius: 4px;" title="Network Error">Error</span>
            `;
        }
    }

    function showAddConnectionForm() {
        document.getElementById('formTitle').innerText = 'Add Custom Connection';
        document.getElementById('conn_index').value = '';
        document.getElementById('conn_name').value = '';
        document.getElementById('conn_host').value = '';
        document.getElementById('conn_port').value = '3306';
        document.getElementById('conn_dbname').value = '';
        document.getElementById('conn_user').value = '';
        document.getElementById('conn_pass').value = '';
        
        document.getElementById('testConnResult').style.display = 'none';
        document.getElementById('customConnForm').style.display = 'block';
    }

    function cancelConnectionForm() {
        document.getElementById('customConnForm').style.display = 'none';
    }

    function editConnection(index) {
        const conn = customConnectionsCopy[index];
        if (!conn) return;

        document.getElementById('formTitle').innerText = 'Edit Custom Connection';
        document.getElementById('conn_index').value = index;
        document.getElementById('conn_name').value = conn.name || '';
        document.getElementById('conn_host').value = conn.host || '';
        document.getElementById('conn_port').value = conn.port || '3306';
        document.getElementById('conn_dbname').value = conn.dbname || '';
        document.getElementById('conn_user').value = conn.user || '';
        document.getElementById('conn_pass').value = conn.pass || '';
        
        document.getElementById('testConnResult').style.display = 'none';
        document.getElementById('customConnForm').style.display = 'block';
    }

    function deleteConnection(index) {
        if (confirm('Are you sure you want to delete this database connection?')) {
            customConnectionsCopy.splice(index, 1);
            renderCustomConnectionsList();
            cancelConnectionForm();
        }
    }

    async function testCustomConnection() {
        const btn = document.getElementById('btnTestConn');
        const resultEl = document.getElementById('testConnResult');
        
        const host = document.getElementById('conn_host').value.trim();
        const port = document.getElementById('conn_port').value.trim() || '3306';
        const dbname = document.getElementById('conn_dbname').value.trim();
        const user = document.getElementById('conn_user').value.trim();
        const pass = document.getElementById('conn_pass').value;

        if (!host || !dbname || !user) {
            alert('Host, Database Name, and User are required to test connection.');
            return;
        }

        btn.disabled = true;
        btn.innerText = 'Testing...';
        resultEl.style.display = 'none';

        try {
            const response = await fetch('?api=test_db_connection', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '<?= $csrf_token ?>'
                },
                body: JSON.stringify({ host, port, dbname, user, pass })
            });
            const result = await response.json();
            resultEl.style.display = 'block';
            if (result.ok) {
                resultEl.style.color = '#065f46';
                resultEl.innerText = '✓ Connection successful!';
            } else {
                resultEl.style.color = '#b91c1c';
                resultEl.innerText = '✗ Connection failed: ' + result.error;
            }
        } catch (e) {
            resultEl.style.display = 'block';
            resultEl.style.color = '#b91c1c';
            resultEl.innerText = '✗ Network error trying to connect.';
        } finally {
            btn.disabled = false;
            btn.innerText = 'Test Connection';
        }
    }

    function saveCustomConnection() {
        const indexVal = document.getElementById('conn_index').value;
        const name = document.getElementById('conn_name').value.trim();
        const host = document.getElementById('conn_host').value.trim();
        const port = document.getElementById('conn_port').value.trim() || '3306';
        const dbname = document.getElementById('conn_dbname').value.trim();
        const user = document.getElementById('conn_user').value.trim();
        const pass = document.getElementById('conn_pass').value;

        if (!name || !host || !dbname || !user) {
            alert('Name, Host, Database Name, and User are required.');
            return;
        }

        const connObj = {
            id: indexVal !== '' ? customConnectionsCopy[indexVal].id : 'conn_' + Date.now(),
            name,
            host,
            port,
            dbname,
            user,
            pass
        };

        if (indexVal !== '') {
            customConnectionsCopy[indexVal] = connObj;
        } else {
            customConnectionsCopy.push(connObj);
        }

        renderCustomConnectionsList();
        cancelConnectionForm();
    }

    async function saveSettings() {
        const btn = event.currentTarget;
        btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:16px!important;">sync</span> Saving...';
        btn.disabled = true;

        const dirsInput = document.getElementById('cfg_dirs').value;
        const filesInput = document.getElementById('cfg_files').value;

        const payload = {
            exclude_dirs: dirsInput.split(','),
            exclude_files: filesInput.split(','),
            custom_connections: customConnectionsCopy
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

    // --- SYSTEM UPDATER LOGIC ---
    let updateMethod = 'git';
    let isUpdateChecked = false;

    // Check for updates silently on load
    window.addEventListener('DOMContentLoaded', () => {
        setTimeout(checkUpdateSilently, 1500); // Debounce check on slow loads
    });

    async function checkUpdateSilently() {
        try {
            const response = await fetch('?api=check_update');
            const data = await response.json();
            if (data.ok && data.update_available) {
                document.getElementById('updateBadge').style.display = 'block';
                document.getElementById('updateNavBtn').style.color = '#ef4444';
            }
        } catch (e) {
            console.error('Silent update check failed:', e);
        }
    }

    function openUpdater() {
        document.getElementById('updaterModal').style.display = 'flex';
        document.getElementById('updaterCheckState').style.display = 'block';
        document.getElementById('updaterViewState').style.display = 'none';
        document.getElementById('updaterProgressState').style.display = 'none';
        document.getElementById('closeUpdaterBtn').disabled = false;
        
        // Always force fresh check when modal is explicitly opened
        checkUpdate(true);
    }

    function closeUpdater() {
        document.getElementById('updaterModal').style.display = 'none';
    }

    async function checkUpdate(force = false) {
        try {
            const response = await fetch('?api=check_update' + (force ? '&force=1' : ''));
            const data = await response.json();
            
            document.getElementById('updaterCheckState').style.display = 'none';
            document.getElementById('updaterViewState').style.display = 'block';
            
            const stateIcon = document.getElementById('updateStateIcon');
            const stateTitle = document.getElementById('updateStateTitle');
            const localTag = document.getElementById('localVersionTag');
            const remoteTag = document.getElementById('remoteVersionTag');
            const changelog = document.getElementById('updateChangelog');
            const execBtn = document.getElementById('updateExecuteBtn');

            if (!data.ok) {
                stateIcon.innerText = 'error';
                stateIcon.style.color = '#ef4444';
                stateTitle.innerText = 'Update Check Failed';
                changelog.innerText = data.error || 'Connection failed: Web server cannot reach api.github.com. Verify internet access.';
                localTag.innerText = 'N/A';
                remoteTag.innerText = 'N/A';
                execBtn.style.display = 'none';
                return;
            }
            
            localTag.innerText = 'v' + data.local_version;
            remoteTag.innerText = 'v' + data.remote_version;
            updateMethod = data.method;

            if (data.update_available) {
                // Update Available!
                document.getElementById('updateBadge').style.display = 'block';
                document.getElementById('updateNavBtn').style.color = '#ef4444';
                stateIcon.innerText = 'system_update_alt';
                stateIcon.style.color = '#ef4444';
                stateTitle.innerText = 'New Update Available!';
                changelog.innerText = data.commit_message;
                execBtn.style.display = 'flex';
            } else {
                // Up to Date
                document.getElementById('updateBadge').style.display = 'none';
                document.getElementById('updateNavBtn').style.color = '';
                stateIcon.innerText = 'check_circle';
                stateIcon.style.color = '#10b981';
                stateTitle.innerText = 'Your system is up to date!';
                changelog.innerText = 'No new changes found. You are running the latest version.';
                execBtn.style.display = 'none';
            }
        } catch (e) {
            document.getElementById('updaterCheckState').style.display = 'none';
            document.getElementById('updaterViewState').style.display = 'block';
            document.getElementById('updateStateIcon').innerText = 'error';
            document.getElementById('updateStateIcon').style.color = '#ef4444';
            document.getElementById('updateStateTitle').innerText = 'Error checking updates';
            document.getElementById('updateChangelog').innerText = 'Could not fetch update status. Please verify networking or GitHub API access.';
            document.getElementById('updateExecuteBtn').style.display = 'none';
        }
    }

    async function runUpdate() {
        if (!confirm('Are you sure you want to update the portal? Your uncommitted changes (if any) will be lost.')) {
            return;
        }

        document.getElementById('updaterViewState').style.display = 'none';
        document.getElementById('updaterProgressState').style.display = 'block';
        document.getElementById('closeUpdaterBtn').disabled = true;

        const consoleArea = document.getElementById('updaterConsole');
        consoleArea.innerText = 'Initializing updater...\n';

        try {
            consoleArea.innerText += 'Connecting to backend... (Method: ' + updateMethod + ')\n';
            
            const response = await fetch('?api=execute_update&method=' + updateMethod, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '<?= $csrf_token ?>'
                }
            });
            
            const result = await response.json();
            consoleArea.innerText += '\nExecution log:\n' + result.logs + '\n';
            
            if (result.ok) {
                consoleArea.innerText += '\nSUCCESS! Reloading in 3 seconds...\n';
                setTimeout(() => {
                    window.location.reload();
                }, 3000);
            } else {
                consoleArea.innerText += '\nERROR: Update execution failed. Please verify git write permissions or server logs.\n';
                document.getElementById('closeUpdaterBtn').disabled = false;
            }
        } catch (e) {
            consoleArea.innerText += '\nERROR: Network request failed during update. Check server logs.\n';
            document.getElementById('closeUpdaterBtn').disabled = false;
        }
    }
</script>

</body>
</html>


