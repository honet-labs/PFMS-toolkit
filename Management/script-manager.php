<?php
/**
 * SCRIPT MANAGER - PRO EDITION (v1.3)
 * - Features: Audit Log (Auto-table creation), In-Browser Editor, Versioning/Rollback, Backup & Restore (ZIP),
 *             Recursive Folder Navigation & Directory Creation directly from UI.
 */

require_once __DIR__ . '/../includes/db-connection.php';

$base_dir = realpath(__DIR__ . '/..');
$versions_dir = $base_dir . '/Management/versions';
$allowed_exts = ['php', 'json', 'css', 'js', 'zip'];
$target_dirs = ['Dashboard', 'generator', 'informations', 'tools', 'Management'];

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';
$user_id = $_SESSION['id_usuario'] ?? 0;

// HELPER: Recursive Directory Scan
function getDirectories($base_dir, $target_roots) {
    $dirs = [];
    foreach ($target_roots as $root) {
        $full_root = $base_dir . '/' . $root;
        if (is_dir($full_root)) {
            $dirs[] = $root;
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($full_root, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $item) {
                    if ($item->isDir()) {
                        $rel_path = str_replace($base_dir . '/', '', $item->getRealPath());
                        $path_parts = explode(DIRECTORY_SEPARATOR, $rel_path);
                        $skip = false;
                        foreach ($path_parts as $part) {
                            if ($part === 'temp' || $part === 'vendor' || $part === 'versions' || strpos($part, '.') === 0) {
                                $skip = true;
                                break;
                            }
                        }
                        if (!$skip) {
                            $dirs[] = str_replace('\\', '/', $rel_path);
                        }
                    }
                }
            } catch (Exception $e) { /* Iterator failed */ }
        }
    }
    sort($dirs);
    return array_values(array_unique($dirs));
}

$all_dirs = getDirectories($base_dir, $target_dirs);

// HELPER: Ensure Audit Table Exists
if ($db_status) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS custom_panel_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(100),
            action VARCHAR(100),
            target VARCHAR(255),
            details TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        // Ensure user_id is VARCHAR if it was previously created as INT
        $pdo->exec("ALTER TABLE custom_panel_audit_log MODIFY COLUMN user_id VARCHAR(100)");
    } catch (Exception $e) { /* Table creation/alter failed */ }
}

// HELPER: Audit Logging
function logAudit($action, $target, $details = '') {
    global $pdo, $db_status, $user_id;
    if (!$db_status) return;
    try {
        $stmt = $pdo->prepare("INSERT INTO custom_panel_audit_log (user_id, action, target, details) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $target, $details]);
    } catch (Exception $e) {
        @file_put_contents(__DIR__ . '/audit_fallback.log', date('Y-m-d H:i:s') . " | $user_id | $action | $target | $details\n", FILE_APPEND);
    }
}

// HELPER: Create Version
function createVersion($path) {
    global $versions_dir, $base_dir;
    if (!file_exists($path)) return;
    if (!is_dir($versions_dir)) @mkdir($versions_dir, 0777, true);
    
    $rel = str_replace($base_dir . '/', '', $path);
    $ver_name = str_replace(['/', '\\'], '_', $rel) . '.' . date('Ymd_His') . '.bak';
    copy($path, $versions_dir . '/' . $ver_name);
}

// API ENDPOINTS
$api = $_GET['api'] ?? '';

if ($api === 'list_files') {
    ob_clean(); header('Content-Type: application/json');
    function getFiles($dir, $base) {
        $results = [];
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === 'temp' || $item === 'vendor' || $item === 'includes' || $item === 'versions') continue;
            $path = $dir . '/' . $item;
            $rel = str_replace($base . '/', '', $path);
            if (is_dir($path)) {
                $results = array_merge($results, getFiles($path, $base));
            } else {
                $ext = pathinfo($item, PATHINFO_EXTENSION);
                $results[] = [
                    'name' => $item,
                    'path' => $rel,
                    'size' => filesize($path),
                    'mtime' => date('Y-m-d H:i:s', filemtime($path)),
                    'ext' => $ext
                ];
            }
        }
        return $results;
    }
    echo json_encode(['ok' => true, 'files' => getFiles($base_dir, $base_dir)]);
    exit;
}

if ($api === 'read_file') {
    ob_clean(); header('Content-Type: application/json');
    $path = $_GET['path'] ?? '';
    if (preg_match('/\.\./', $path)) { echo json_encode(['ok' => false, 'error' => 'Invalid path']); exit; }
    $full = $base_dir . '/' . $path;
    if (file_exists($full)) {
        echo json_encode(['ok' => true, 'content' => file_get_contents($full)]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'File not found']);
    }
    exit;
}

if ($api === 'save_file' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $path = $input['path'] ?? '';
    $content = $input['content'] ?? '';
    $client_csrf = $input['csrf'] ?? '';

    // Debug Log
    @file_put_contents(__DIR__ . '/save_debug.log', date('Y-m-d H:i:s') . " | Path: $path | CSRF Match: ".($client_csrf === $csrf_token ? 'YES' : 'NO')."\n", FILE_APPEND);

    if ($client_csrf !== $csrf_token) { echo json_encode(['ok' => false, 'error' => 'Invalid CSRF. Please refresh page.']); exit; }
    if (preg_match('/\.\./', $path)) { echo json_encode(['ok' => false, 'error' => 'Invalid path']); exit; }
    
    $full = $base_dir . '/' . $path;
    if (file_exists($full)) {
        createVersion($full);
        if (file_put_contents($full, $content) !== false) {
            logAudit('EDIT_FILE', $path, 'Edited via web editor');
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to write to file. Check folder permissions.']);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'File does not exist.']);
    }
    exit;
}

if ($api === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    if (($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '') !== $csrf_token) { echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']); exit; }

    $target_sub = $_POST['target_dir'] ?? 'Dashboard';
    $file = $_FILES['script_file'];
    $filename = basename($file['name']);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed_exts)) { echo json_encode(['ok' => false, 'error' => 'Forbidden extension']); exit; }

    // Special Case: RESTORE FROM ZIP
    if ($ext === 'zip' && $target_sub === 'Management') {
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) === TRUE) {
            $zip->extractTo($base_dir);
            $zip->close();
            logAudit('RESTORE_BACKUP', $filename, 'Restored from ZIP backup');
            if (file_exists($base_dir . '/temp/menu_cache.json')) @unlink($base_dir . '/temp/menu_cache.json');
            echo json_encode(['ok' => true, 'msg' => 'Backup restored successfully!']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to open ZIP']);
        }
        exit;
    }

    $dest = $base_dir . '/' . $target_sub . '/' . $filename;
    if (file_exists($dest)) createVersion($dest);
    if (move_uploaded_file($file['tmp_name'], $dest)) {
        logAudit('UPLOAD_FILE', $target_sub . '/' . $filename);
        if (file_exists($base_dir . '/temp/menu_cache.json')) @unlink($base_dir . '/temp/menu_cache.json');
        echo json_encode(['ok' => true]);
    }
    exit;
}

if ($api === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (($input['csrf'] ?? '') !== $csrf_token) { echo json_encode(['ok' => false, 'error' => 'Invalid CSRF']); exit; }
    $full = realpath($base_dir . '/' . $input['path']);
    if ($full && strpos($full, $base_dir) === 0 && file_exists($full)) {
        createVersion($full);
        if (unlink($full)) {
            logAudit('DELETE_FILE', $input['path']);
            if (file_exists($base_dir . '/temp/menu_cache.json')) @unlink($base_dir . '/temp/menu_cache.json');
            echo json_encode(['ok' => true]);
        }
    }
    exit;
}

if ($api === 'backup') {
    ob_clean();
    $zip = new ZipArchive();
    $filename = 'Backup_Portal_' . date('Ymd_His') . '.zip';
    $zip_path = $base_dir . '/temp/' . $filename;
    if (!is_dir($base_dir . '/temp')) @mkdir($base_dir . '/temp', 0777, true);

    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base_dir), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($base_dir) + 1);
                if (strpos($relativePath, 'vendor') === 0 || strpos($relativePath, 'temp') === 0 || strpos($relativePath, 'versions') !== false || strpos($relativePath, '.') === 0) continue;
                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();
        logAudit('BACKUP_PORTAL', $filename);
        header('Content-Type: application/zip');
        header('Content-disposition: attachment; filename='.$filename);
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        @unlink($zip_path);
    }
    exit;
}

if ($api === 'list_versions') {
    ob_clean(); header('Content-Type: application/json');
    $path = $_GET['path'] ?? '';
    $prefix = str_replace(['/', '\\'], '_', $path);
    $vers = [];
    if (is_dir($versions_dir)) {
        foreach (scandir($versions_dir) as $item) {
            if (strpos($item, $prefix) === 0) {
                $vers[] = ['name' => $item, 'mtime' => date('Y-m-d H:i:s', filemtime($versions_dir.'/'.$item)), 'size' => filesize($versions_dir.'/'.$item)];
            }
        }
    }
    echo json_encode(['ok' => true, 'versions' => array_reverse($vers)]);
    exit;
}

if ($api === 'rollback' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $ver_full = $versions_dir . '/' . $input['version'];
    $orig_full = $base_dir . '/' . $input['path'];
    if (file_exists($ver_full)) {
        if (file_exists($orig_full)) createVersion($orig_full);
        if (copy($ver_full, $orig_full)) {
            logAudit('ROLLBACK_FILE', $input['path'], "Restored from version {$input['version']}");
            echo json_encode(['ok' => true]);
        }
    }
    exit;
}

if ($api === 'audit_logs') {
    ob_clean(); header('Content-Type: application/json');
    try {
        if (!$db_status || !$pdo) {
            echo json_encode(['ok' => false, 'error' => 'Database connection not initialized.']);
            exit;
        }
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        if ($page < 1) $page = 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 25;
        if ($limit < 5) $limit = 25;
        $offset = ($page - 1) * $limit;

        $total_stmt = $pdo->query("SELECT COUNT(*) FROM custom_panel_audit_log");
        $total_count = (int)$total_stmt->fetchColumn();

        $limit = (int)$limit;
        $offset = (int)$offset;
        $stmt = $pdo->prepare("SELECT * FROM custom_panel_audit_log ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute();

        echo json_encode([
            'ok' => true,
            'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total_count,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total_count / $limit)
        ]);
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => 'Audit table issue: ' . $e->getMessage()]); }
    exit;
}

if ($api === 'create_folder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $parent = $input['parent_dir'] ?? '';
    $folder_name = trim($input['folder_name'] ?? '');
    $client_csrf = $input['csrf'] ?? '';

    if ($client_csrf !== $csrf_token) { echo json_encode(['ok' => false, 'error' => 'Invalid CSRF. Please refresh page.']); exit; }
    if (empty($folder_name)) { echo json_encode(['ok' => false, 'error' => 'Folder name is required.']); exit; }
    
    // Alphanumeric, underscores, hyphens only
    if (preg_match('/[^a-zA-Z0-9_\-]/', $folder_name)) {
        echo json_encode(['ok' => false, 'error' => 'Invalid folder name. Use alphanumeric characters, underscores, or hyphens only.']);
        exit;
    }
    if (preg_match('/\.\./', $parent)) { echo json_encode(['ok' => false, 'error' => 'Invalid parent path.']); exit; }

    $allowed = false;
    foreach ($target_dirs as $root) {
        if ($parent === $root || strpos($parent, $root . '/') === 0) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) { echo json_encode(['ok' => false, 'error' => 'Parent folder not allowed.']); exit; }

    $target_path = $base_dir . '/' . $parent . '/' . $folder_name;
    if (file_exists($target_path)) { echo json_encode(['ok' => false, 'error' => 'Folder or file already exists.']); exit; }

    if (mkdir($target_path, 0777, true)) {
        logAudit('CREATE_FOLDER', $parent . '/' . $folder_name, 'Created new directory');
        if (file_exists($base_dir . '/temp/menu_cache.json')) @unlink($base_dir . '/temp/menu_cache.json');
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Failed to create folder. Check permissions.']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Script Manager - Pandora FMS</title>
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/custom/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6f8; color: #334155; font-size: 13px; }
        .material-symbols-outlined { vertical-align: middle; font-size: 18px; }
        .header-section { padding: 15px 30px; background: #fff; border-bottom: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; }
        .page-title { font-size: 16px; font-weight: 600; color: #0b1a26; margin: 0; }
        .nav-tabs { border-bottom: 1px solid #e0e4e8; padding: 0 30px; background: #fff; display: flex; gap: 20px; }
        .nav-link { color: #64748b; border: none; padding: 12px 0; font-weight: 500; cursor: pointer; background: transparent; border-bottom: 2px solid transparent; }
        .nav-link.active { color: #004d40; border-bottom-color: #004d40; }
        .main-container { padding: 25px 30px; }
        .card-custom { background: #fff; border-radius: 8px; border: 1px solid #e0e4e8; box-shadow: 0 2px 8px rgba(0,0,0,0.04); overflow: hidden; }
        .table-pfms { width: 100%; border-collapse: collapse; }
        .table-pfms th { background: #f8f9fa; padding: 10px 20px; text-align: left; font-size: 10px; text-transform: uppercase; color: #7f8c8d; border-bottom: 1px solid #e0e4e8; }
        .table-pfms td { padding: 10px 20px; border-bottom: 1px solid #f0f3f5; }
        .btn-pfms { padding: 6px 14px; border-radius: 4px; font-size: 12px; font-weight: 500; cursor: pointer; transition: 0.2s; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary-pfms { background: #004d40; color: #fff; }
        .btn-outline-pfms { background: #fff; border-color: #dce1e5; color: #4a5568; }
        #editorOverlay { position: fixed; inset: 0; background: #fff; z-index: 2000; display: none; flex-direction: column; }
        #editorContainer { flex-grow: 1; }
        .editor-header { padding: 10px 20px; background: #0b1a26; color: #fff; display: flex; justify-content: space-between; align-items: center; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; }
        .modal-box { background: #fff; width: 650px; border-radius: 8px; padding: 20px; max-height: 90vh; overflow-y: auto; }
        .drop-zone { border: 2px dashed #cbd5e1; border-radius: 8px; padding: 30px; text-align: center; color: #64748b; cursor: pointer; }
        .drop-zone.active { border-color: #004d40; background: #f0fdf4; }
        
        .search-box { position: relative; margin-bottom: 20px; }
        .search-box input { width: 100%; padding: 10px 15px 10px 40px; border-radius: 6px; border: 1px solid #dce1e5; outline: none; transition: 0.2s; }
        .search-box input:focus { border-color: #004d40; box-shadow: 0 0 0 3px rgba(0,77,64,0.1); }
        .search-box .material-symbols-outlined { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
    </style>
</head>
<body>

<div class="header-section">
    <div><h1 class="page-title">Script Manager</h1></div>
    <div style="display:flex; gap:10px;">
        <button class="btn-pfms btn-outline-pfms" onclick="location.href='?api=backup'"><span class="material-symbols-outlined">archive</span> Backup</button>
        <button class="btn-pfms btn-outline-pfms" onclick="openCreateFolderModal()"><span class="material-symbols-outlined">create_new_folder</span> Create Folder</button>
        <button class="btn-pfms btn-primary-pfms" onclick="openUploadModal()"><span class="material-symbols-outlined">upload</span> Upload/Restore</button>
    </div>
</div>

<div class="nav-tabs">
    <button class="nav-link active" onclick="switchTab('files', this)">File Manager</button>
    <button class="nav-link" onclick="switchTab('audit', this)">Audit Logs</button>
</div>

<div class="main-container">
    <div id="tab_files">
        <div class="search-box">
            <span class="material-symbols-outlined">search</span>
            <input type="text" id="fileSearch" placeholder="Search scripts by name or path..." oninput="renderFiles()">
        </div>
        <div class="card-custom">
            <table class="table-pfms">
                <thead><tr><th>Name</th><th>Path</th><th>Size</th><th>Modified</th><th style="text-align: right;">Action</th></tr></thead>
                <tbody id="fileTableBody"></tbody>
            </table>
        </div>
    </div>
    <div id="tab_audit" style="display:none;">
        <div class="card-custom" style="margin-bottom: 20px;">
            <table class="table-pfms">
                <thead><tr><th>User</th><th>Action</th><th>Target</th><th>Details</th><th>Timestamp</th></tr></thead>
                <tbody id="auditTableBody"></tbody>
            </table>
        </div>
        <div id="auditPagination" style="display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 12px 20px; border: 1px solid #e0e4e8; border-radius: 8px;">
            <div style="font-size:12px; color:#64748b;" id="auditPaginationInfo">Showing 0 to 0 of 0 entries</div>
            <div style="display:flex; gap:10px;">
                <button class="btn-pfms btn-outline-pfms" id="btnAuditPrev" onclick="changeAuditPage(-1)" style="padding: 4px 12px; height: auto;">Prev</button>
                <span id="auditPageNumber" style="align-self:center; font-size:13px; font-weight:500;">Page 1 / 1</span>
                <button class="btn-pfms btn-outline-pfms" id="btnAuditNext" onclick="changeAuditPage(1)" style="padding: 4px 12px; height: auto;">Next</button>
            </div>
        </div>
    </div>
</div>

<!-- EDITOR OVERLAY -->
<div id="editorOverlay">
    <div class="editor-header"><div id="editorTitle">Editor</div><div style="display:flex; gap:10px;"><button class="btn-pfms" onclick="closeEditor()" style="background:rgba(255,255,255,0.1); color:#fff; border:1px solid rgba(255,255,255,0.4); padding:5px 15px;">Cancel</button><button class="btn-pfms btn-primary-pfms" onclick="saveFile()">Save Changes</button></div></div>
    <div id="editorContainer"></div>
</div>

<!-- UPLOAD MODAL -->
<div class="modal-overlay" id="uploadModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;"><h5>Upload Script / Restore Backup</h5><span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeUploadModal()">close</span></div>
        <div style="margin-bottom:15px;">
            <label style="display:block; font-size:11px; color:#64748b; margin-bottom:5px;">Target Directory (Select 'Management' to restore ZIP backup)</label>
            <select id="targetDir" class="form-select">
                <?php foreach($all_dirs as $td): ?>
                    <option value="<?= htmlspecialchars($td) ?>"><?= htmlspecialchars($td) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="drop-zone" id="dropZone" onclick="document.getElementById('fileInput').click()"><span class="material-symbols-outlined" style="font-size:40px;">cloud_upload</span><p>Click or Drop File Here</p><p id="previewName" style="font-size:11px; font-weight:600; color:#004d40;"></p><input type="file" id="fileInput" style="display:none;" onchange="handleFile(event)"></div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;"><button class="btn-pfms btn-outline-pfms" onclick="closeUploadModal()">Cancel</button><button class="btn-pfms btn-primary-pfms" id="uploadBtn" onclick="doUpload()" disabled>Start Upload</button></div>
    </div>
</div>

<!-- CREATE FOLDER MODAL -->
<div class="modal-overlay" id="createFolderModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; margin-bottom:15px; border-bottom:1px solid #eee; padding-bottom:10px;">
            <h5>Create New Folder</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeCreateFolderModal()">close</span>
        </div>
        <div style="margin-bottom:15px;">
            <label style="display:block; font-size:11px; color:#64748b; margin-bottom:5px;">Parent Directory</label>
            <select id="parentDir" class="form-select">
                <?php foreach($all_dirs as $td): ?>
                    <option value="<?= htmlspecialchars($td) ?>"><?= htmlspecialchars($td) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="margin-bottom:15px;">
            <label style="display:block; font-size:11px; color:#64748b; margin-bottom:5px;">Folder Name</label>
            <input type="text" id="newFolderName" class="form-control" placeholder="e.g. Traffic-Dashboard">
            <small style="display:block; font-size:10px; color:#94a3b8; margin-top:4px;">Alphanumeric characters, underscores, or hyphens only.</small>
        </div>
        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
            <button class="btn-pfms btn-outline-pfms" onclick="closeCreateFolderModal()">Cancel</button>
            <button class="btn-pfms btn-primary-pfms" onclick="doCreateFolder()">Create Folder</button>
        </div>
    </div>
</div>

<!-- VERSION MODAL -->
<div class="modal-overlay" id="rollbackModal"><div class="modal-box"><div style="display:flex; justify-content:space-between; margin-bottom:15px; border-bottom:1px solid #eee;"><h5>Versions: <span id="verPath"></span></h5><span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeRollbackModal()">close</span></div><table class="table-pfms"><thead><tr><th>Version</th><th>Size</th><th>Created</th><th></th></tr></thead><tbody id="versionTableBody"></tbody></table></div></div>

    <!-- Ace Editor Local -->
    <script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/custom/panel/vendor/ace/ace.js"></script>
    <script>
        const csrf = '<?= $_SESSION['pfms_csrf_token'] ?>';
        let currentPath = '';
        let selectedFile = null;
        
        // Configure Ace to use local path for themes/modes
        ace.config.set("basePath", "<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/custom/panel/vendor/ace");
        
        const editor = ace.edit("editorContainer");
        editor.setTheme("ace/theme/monokai");
        editor.session.setMode("ace/mode/php");

    function switchTab(t, el) {
        document.getElementById('tab_files').style.display = t==='files'?'block':'none';
        document.getElementById('tab_audit').style.display = t==='audit'?'block':'none';
        document.querySelectorAll('.nav-link').forEach(n=>n.classList.remove('active'));
        el.classList.add('active');
        if(t==='audit') loadAudit();
    }

    async function loadFiles() {
        const res = await fetch('?api=list_files');
        const data = await res.json();
        allFiles = data.files || [];
        renderFiles();
    }

    function renderFiles() {
        const kw = document.getElementById('fileSearch').value.toLowerCase();
        const tbody = document.getElementById('fileTableBody');
        const filtered = allFiles.filter(f => f.name.toLowerCase().includes(kw) || f.path.toLowerCase().includes(kw));

        tbody.innerHTML = filtered.map(f=>`<tr><td><b>${f.name}</b></td><td><span style="font-size:10px; background:#eee; padding:2px 4px; border-radius:3px;">${f.path}</span></td><td>${(f.size/1024).toFixed(1)} KB</td><td>${f.mtime}</td><td style="text-align:right;"><button class="btn-pfms btn-outline-pfms" onclick="openVersions('${f.path}')"><span class="material-symbols-outlined">history</span></button><button class="btn-pfms btn-outline-pfms" onclick="openEditor('${f.path}')"><span class="material-symbols-outlined">edit</span></button><button class="btn-pfms btn-outline-pfms" style="color:#e74c3c;" onclick="deleteFile('${f.path}')"><span class="material-symbols-outlined">delete</span></button></td></tr>`).join('');
        
        if (filtered.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; padding:30px; color:#94a3b8;">No scripts found.</td></tr>`;
        }
    }

    let auditPage = 1;
    const auditLimit = 25;

    async function loadAudit() {
        const res = await fetch(`?api=audit_logs&page=${auditPage}&limit=${auditLimit}`);
        const data = await res.json();
        if(!data.ok) { 
            document.getElementById('auditTableBody').innerHTML = `<tr><td colspan="5" align="center">${data.error}</td></tr>`; 
            return; 
        }
        
        const logs = data.logs || [];
        document.getElementById('auditTableBody').innerHTML = logs.map(l=>`<tr><td>${l.user_id || 'Sys'}</td><td><span style="font-size:10px; font-weight:bold; color:#004d40;">${l.action}</span></td><td>${l.target}</td><td style="font-size:11px; color:#64748b;">${l.details||'-'}</td><td>${l.created_at}</td></tr>`).join('');
        
        if (logs.length === 0) {
            document.getElementById('auditTableBody').innerHTML = `<tr><td colspan="5" align="center" style="padding:30px; color:#94a3b8;">No audit logs found.</td></tr>`;
        }

        const total = data.total || 0;
        const totalPages = data.total_pages || 1;
        const start = total === 0 ? 0 : (auditPage - 1) * auditLimit + 1;
        const end = Math.min(auditPage * auditLimit, total);
        
        document.getElementById('auditPaginationInfo').innerText = `Showing ${start} to ${end} of ${total} entries`;
        document.getElementById('auditPageNumber').innerText = `Page ${auditPage} / ${totalPages}`;
        
        document.getElementById('btnAuditPrev').disabled = (auditPage === 1);
        document.getElementById('btnAuditNext').disabled = (auditPage === totalPages);
    }

    function changeAuditPage(dir) {
        auditPage += dir;
        loadAudit();
    }

    function openUploadModal() { document.getElementById('uploadModal').style.display='flex'; }
    function closeUploadModal() { document.getElementById('uploadModal').style.display='none'; selectedFile=null; document.getElementById('previewName').innerText=''; document.getElementById('uploadBtn').disabled=true; }
    function handleFile(e) { selectedFile = e.target.files[0]; if(selectedFile){ document.getElementById('previewName').innerText=selectedFile.name; document.getElementById('uploadBtn').disabled=false; } }
    async function doUpload() {
        const fd = new FormData(); fd.append('script_file', selectedFile); fd.append('target_dir', document.getElementById('targetDir').value);
        const res = await fetch('?api=upload', { method:'POST', headers:{'X-CSRF-TOKEN':csrf}, body:fd });
        const data = await res.json();
        if(data.ok) { alert(data.msg || 'Done!'); closeUploadModal(); loadFiles(); } else { alert(data.error); }
    }

    function openCreateFolderModal() { 
        document.getElementById('createFolderModal').style.display = 'flex'; 
        document.getElementById('newFolderName').value = '';
    }
    function closeCreateFolderModal() { 
        document.getElementById('createFolderModal').style.display = 'none'; 
    }
    async function doCreateFolder() {
        const parentDir = document.getElementById('parentDir').value;
        const folderName = document.getElementById('newFolderName').value;
        if (!folderName) { alert('Please enter folder name.'); return; }
        
        try {
            const res = await fetch('?api=create_folder', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ parent_dir: parentDir, folder_name: folderName, csrf: csrf })
            });
            const data = await res.json();
            if (data.ok) {
                alert('Folder created successfully!');
                closeCreateFolderModal();
                location.reload(); 
            } else {
                alert('Error creating folder: ' + data.error);
            }
        } catch (e) {
            alert('Network error while creating folder.');
        }
    }

    async function openEditor(p) {
        currentPath = p;
        const res = await fetch('?api=read_file&path='+p);
        const data = await res.json();
        if(data.ok) { 
            document.getElementById('editorTitle').innerText = 'Editor: ' + p; 
            editor.setValue(data.content, -1);
            if(p.endsWith('.json')) editor.session.setMode("ace/mode/json");
            else if(p.endsWith('.css')) editor.session.setMode("ace/mode/css");
            else editor.session.setMode("ace/mode/php");
            document.getElementById('editorOverlay').style.display='flex';
        }
    }
    function closeEditor() { document.getElementById('editorOverlay').style.display='none'; }
    async function saveFile() {
        try {
            const res = await fetch('?api=save_file', { 
                method:'POST', 
                headers: { 'Content-Type': 'application/json' },
                body:JSON.stringify({path:currentPath, content:editor.getValue(), csrf:csrf}) 
            });
            const data = await res.json();
            if(data.ok) { 
                alert('Changes saved successfully!');
                closeEditor(); 
                loadFiles(); 
            } else {
                alert('Error saving changes: ' + (data.error || 'Unknown error'));
            }
        } catch (e) {
            alert('Network error while saving.');
        }
    }

    async function openVersions(p) {
        currentPath = p; document.getElementById('verPath').innerText = p;
        const res = await fetch('?api=list_versions&path='+p);
        const data = await res.json();
        document.getElementById('versionTableBody').innerHTML = data.versions.map(v=>`<tr><td style="font-size:9px;">${v.name}</td><td>${(v.size/1024).toFixed(1)} KB</td><td>${v.mtime}</td><td><button class="btn-pfms btn-outline-pfms" onclick="rollback('${v.name}')">Restore</button></td></tr>`).join('');
        document.getElementById('rollbackModal').style.display='flex';
    }
    function closeRollbackModal() { document.getElementById('rollbackModal').style.display='none'; }
    async function rollback(v) {
        if(!confirm('Restore this version?')) return;
        const res = await fetch('?api=rollback', { method:'POST', body:JSON.stringify({version:v, path:currentPath, csrf:csrf}) });
        if((await res.json()).ok) { closeRollbackModal(); loadFiles(); }
    }

    async function deleteFile(p) {
        if(!confirm(`Delete ${p}?`)) return;
        const res = await fetch('?api=delete', { method:'POST', body:JSON.stringify({path:p, csrf:csrf}) });
        if((await res.json()).ok) loadFiles();
    }

    loadFiles();
</script>
</body>
</html>
