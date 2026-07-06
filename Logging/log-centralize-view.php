<?php
/**
 * LOG CENTER - System Log Terminal (v1.0)
 * Efficiently view and monitor system logs from WebUI.
 */

require_once __DIR__ . '/../includes/db-connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';

$common_logs = [
    ['name' => 'Pandora Server Log', 'path' => '/var/log/pandora/pandora_server.log'],
    ['name' => 'Pandora Console Log', 'path' => '/var/log/pandora/pandora_console.log'],
    ['name' => 'Apache Error Log', 'path' => '/var/log/apache2/error.log'],
    ['name' => 'Apache Access Log', 'path' => '/var/log/apache2/access.log'],
    ['name' => 'System Log (syslog)', 'path' => '/var/log/syslog'],
];

$netflow_access_dir = realpath(__DIR__ . '/../Dashboard/Netflow-Explorer');
if ($netflow_access_dir) {
    $netflow_access_file = $netflow_access_dir . DIRECTORY_SEPARATOR . 'nfx_access.log';
    if (file_exists($netflow_access_file) || @touch($netflow_access_file)) {
        $common_logs[] = ['name' => 'Netflow Explorer Access Log', 'path' => realpath($netflow_access_file)];
    }
}

$custom_logs_file = __DIR__ . '/custom_logs.json';
$custom_logs = file_exists($custom_logs_file) ? json_decode(file_get_contents($custom_logs_file), true) : [];
if (!is_array($custom_logs)) $custom_logs = [];

// API: Save Preset
if (isset($_GET['api']) && $_GET['api'] === 'save_preset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $name = $input['name'] ?? '';
    $path = $input['path'] ?? '';
    
    if ($name && $path) {
        $custom_logs[] = ['name' => $name, 'path' => $path];
        file_put_contents($custom_logs_file, json_encode($custom_logs, JSON_PRETTY_PRINT));
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid data']);
    }
    exit;
}

// API: Delete Preset
if (isset($_GET['api']) && $_GET['api'] === 'delete_preset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $path = $input['path'] ?? '';
    
    $custom_logs = array_values(array_filter($custom_logs, function($l) use ($path) { return $l['path'] !== $path; }));
    file_put_contents($custom_logs_file, json_encode($custom_logs, JSON_PRETTY_PRINT));
    echo json_encode(['ok' => true]);
    exit;
}
// API: Update Preset
if (isset($_GET['api']) && $_GET['api'] === 'update_preset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean(); header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    $oldPath = $input['oldPath'] ?? '';
    $newName = $input['name'] ?? '';
    $newPath = $input['path'] ?? '';
    
    foreach ($custom_logs as &$l) {
        if ($l['path'] === $oldPath) {
            $l['name'] = $newName;
            $l['path'] = $newPath;
        }
    }
    file_put_contents($custom_logs_file, json_encode($custom_logs, JSON_PRETTY_PRINT));
    echo json_encode(['ok' => true]);
    exit;
}
// API: Tail Log
if (isset($_GET['api']) && $_GET['api'] === 'tail') {
    ob_clean(); header('Content-Type: application/json');
    $path = $_GET['path'] ?? '';
    $lines = (int)($_GET['lines'] ?? 100);
    
    if (empty($path)) { echo json_encode(['ok' => false, 'error' => 'No path provided']); exit; }
    if (!file_exists($path)) { echo json_encode(['ok' => false, 'error' => 'File not found: ' . $path]); exit; }
    if (!is_readable($path)) { echo json_encode(['ok' => false, 'error' => 'Access Denied. Check PHP permissions for this file.']); exit; }

    // Efficient Tail using fseek
    $handle = fopen($path, 'r');
    $content = "";
    if ($handle) {
        $line_count = 0;
        $pos = -1;
        $t_content = [];
        
        fseek($handle, 0, SEEK_END);
        $total_size = ftell($handle);
        
        while ($line_count < $lines && abs($pos) < $total_size) {
            fseek($handle, $pos, SEEK_END);
            $char = fgetc($handle);
            if ($char === "\n") {
                $line_count++;
            }
            $pos--;
        }
        
        $content = stream_get_contents($handle);
        fclose($handle);
    }
    
    echo json_encode(['ok' => true, 'content' => $content, 'size' => filesize($path)]);
    exit;
}

// API: Download
if (isset($_GET['api']) && $_GET['api'] === 'download') {
    $path = $_GET['path'] ?? '';
    if (file_exists($path) && is_readable($path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($path).'"');
        readfile($path);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Log Centralize View - Pandora FMS</title>
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6f8; color: #334155; font-size: 13px; margin: 0; display: flex; flex-direction: column; height: 100vh; }
        .material-symbols-outlined { vertical-align: middle; font-size: 18px; }
        
        .header { padding: 12px 30px; background: #fff; border-bottom: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 15px; font-weight: 600; margin: 0; color: #0b1a26; }
        
        .toolbar { padding: 10px 30px; background: #fff; border-bottom: 1px solid #e0e4e8; display: flex; gap: 15px; align-items: center; }
        .form-select-sm, .form-control-sm { height: 32px; font-size: 12px; }
        
        .terminal-container { flex: 1; background: #0b1a26; padding: 20px; overflow: hidden; display: flex; flex-direction: column; }
        #terminal { flex: 1; overflow-y: auto; color: #d1d5db; font-family: 'Consolas', 'Monaco', 'Courier New', monospace; font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-all; }
        
        .log-line { border-bottom: 1px solid rgba(255,255,255,0.05); padding: 2px 0; }
        .log-line:hover { background: rgba(255,255,255,0.03); }
        .log-error { color: #f87171; background: rgba(248,113,113,0.1); }
        .log-warn { color: #fbbf24; }
        .log-info { color: #60a5fa; }
        .log-success { color: #34d399; }
        .log-match { background: #fbbf24; color: #000; font-weight: bold; }

        .status-bar { padding: 5px 30px; background: #0b1a26; color: #94a3b8; font-size: 11px; border-top: 1px solid rgba(255,255,255,0.1); display: flex; justify-content: space-between; }
        
        .btn-pfms { font-size: 12px; font-weight: 500; padding: 6px 12px; border-radius: 6px; transition: 0.2s; display: flex; align-items: center; gap: 6px; }
        .btn-outline { border: 1px solid #dce1e5; background: #fff; color: #64748b; }
        .btn-outline:hover { background: #f8fafc; border-color: #cbd5e1; }
        .btn-primary-pfms { background: #004d40; color: #fff; border: none; }
        .btn-primary-pfms:hover { background: #003d33; }

        ::-webkit-scrollbar { width: 10px; }
        ::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 5px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.3); }
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; z-index: 1000; }
        .modal-box { background: #fff; width: 600px; border-radius: 8px; padding: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .form-label { font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px; display: block; }
        .btn-sm-icon { padding: 2px 6px; border-radius: 4px; border: 1px solid #e2e8f0; background: #fff; color: #64748b; cursor: pointer; }
        .btn-sm-icon:hover { background: #f8fafc; border-color: #cbd5e1; }
    </style>
</head>
<body>

<div class="header">
    <h1><span class="material-symbols-outlined">terminal</span> Log Centralize View</h1>
    <div style="display:flex; gap:10px;">
        <button class="btn-pfms btn-outline" onclick="openSettings()"><span class="material-symbols-outlined">settings</span> Settings</button>
        <button class="btn-pfms btn-outline" onclick="downloadLog()"><span class="material-symbols-outlined">download</span> Download</button>
        <button class="btn-pfms btn-primary-pfms" onclick="refreshLog()"><span class="material-symbols-outlined">refresh</span> Refresh</button>
    </div>
</div>

<div class="toolbar">
    <div style="width:300px;">
        <select id="logPreset" class="form-select form-select-sm" onchange="updatePath()">
            <option value="">-- Select Common Log --</option>
            <?php foreach($common_logs as $log): ?>
                <option value="<?= $log['path'] ?>"><?= $log['name'] ?></option>
            <?php endforeach; ?>
            <?php if(!empty($custom_logs)): ?>
                <option disabled>────────── Favorites ──────────</option>
                <?php foreach($custom_logs as $log): ?>
                    <option value="<?= $log['path'] ?>">⭐ <?= $log['name'] ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
            <option value="custom">Custom Path...</option>
        </select>
    </div>
    
    <div style="flex:1;">
        <input type="text" id="logPath" class="form-control form-control-sm" placeholder="/var/log/path/to/file.log" onkeypress="if(event.key==='Enter') refreshLog()">
    </div>

    <div style="width:120px;">
        <select id="logLines" class="form-select form-select-sm" onchange="refreshLog()">
            <option value="50">Last 50 Lines</option>
            <option value="100" selected>Last 100 Lines</option>
            <option value="250">Last 250 Lines</option>
            <option value="500">Last 500 Lines</option>
            <option value="1000">Last 1000 Lines</option>
        </select>
    </div>

    <div style="width:200px; position:relative;">
        <input type="text" id="logFilter" class="form-control form-control-sm" placeholder="Filter/Search content..." oninput="applyFilter()">
    </div>

    <div class="form-check form-switch" style="font-size:12px;">
        <input class="form-check-input" type="checkbox" id="autoRefresh" onchange="toggleAutoRefresh()">
        <label class="form-check-label">Auto (5s)</label>
    </div>
</div>

<div class="terminal-container">
    <div id="terminal">
        <div style="color:#64748b; font-style:italic;">Select a log file to begin...</div>
    </div>
</div>

<div class="status-bar">
    <div id="statusInfo">Ready</div>
    <div id="fileSize">-</div>
</div>

<!-- SETTINGS MODAL -->
<div class="modal-overlay" id="settingsModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; margin-bottom:20px; border-bottom:1px solid #eee; padding-bottom:10px;">
            <h5 style="margin:0; font-weight:600;">Log Presets Settings</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeSettings()">close</span>
        </div>
        
        <div style="background:#f8fafc; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #e2e8f0;">
            <div style="font-weight:600; font-size:11px; margin-bottom:10px; color:#0f172a;">ADD NEW PRESET</div>
            <div style="display:flex; gap:10px;">
                <div style="flex:1;"><label class="form-label">Display Name</label><input type="text" id="newName" class="form-control form-control-sm" placeholder="e.g. My App Log"></div>
                <div style="flex:1.5;"><label class="form-label">File Path</label><input type="text" id="newPath" class="form-control form-control-sm" placeholder="/var/log/..."></div>
                <div style="align-self: flex-end;"><button class="btn-pfms btn-primary-pfms" onclick="addPreset()">Add</button></div>
            </div>
        </div>

        <div style="max-height:300px; overflow-y:auto;">
            <table class="table table-sm" style="font-size:12px;">
                <thead><tr><th>Name</th><th>Path</th><th style="width:80px;">Action</th></tr></thead>
                <tbody>
                    <?php if(empty($custom_logs)): ?>
                        <tr><td colspan="3" align="center" style="color:#94a3b8; padding:20px;">No custom logs added yet.</td></tr>
                    <?php else: ?>
                        <?php foreach($custom_logs as $log): ?>
                            <tr>
                                <td><?= $log['name'] ?></td>
                                <td style="font-size:10px; color:#64748b;"><?= $log['path'] ?></td>
                                <td>
                                    <div style="display:flex; gap:5px;">
                                        <button class="btn-sm-icon" onclick="editPreset('<?= addslashes($log['path']) ?>', '<?= addslashes($log['name']) ?>')"><span class="material-symbols-outlined" style="font-size:14px;">edit</span></button>
                                        <button class="btn-sm-icon" style="color:#ef4444;" onclick="deletePreset('<?= addslashes($log['path']) ?>')"><span class="material-symbols-outlined" style="font-size:14px;">delete</span></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    let autoTimer = null;
    let currentRawContent = "";

    function updatePath() {
        const sel = document.getElementById('logPreset');
        const input = document.getElementById('logPath');
        
        if (sel.value && sel.value !== 'custom') {
            input.value = sel.value;
            refreshLog();
        } else {
            input.focus();
        }
    }

    function openSettings() { document.getElementById('settingsModal').style.display = 'flex'; }
    function closeSettings() { document.getElementById('settingsModal').style.display = 'none'; }

    async function addPreset() {
        const name = document.getElementById('newName').value;
        const path = document.getElementById('newPath').value;
        if (!name || !path) return alert('Please fill both fields');

        const res = await fetch('?api=save_preset', {
            method: 'POST',
            body: JSON.stringify({ name, path })
        });
        if ((await res.json()).ok) location.reload();
    }

    async function editPreset(oldPath, oldName) {
        const newName = prompt("Edit Display Name:", oldName);
        if (!newName) return;
        const newPath = prompt("Edit File Path:", oldPath);
        if (!newPath) return;

        const res = await fetch('?api=update_preset', {
            method: 'POST',
            body: JSON.stringify({ oldPath, name: newName, path: newPath })
        });
        if ((await res.json()).ok) location.reload();
    }

    async function deletePreset(path) {
        if (!confirm('Remove this log from favorites?')) return;

        const res = await fetch('?api=delete_preset', {
            method: 'POST',
            body: JSON.stringify({ path })
        });
        if ((await res.json()).ok) location.reload();
    }

    async function refreshLog() {
        const path = document.getElementById('logPath').value;
        const lines = document.getElementById('logLines').value;
        if (!path) return;

        document.getElementById('statusInfo').innerHTML = '<span class="spinner-border spinner-border-sm"></span> Loading...';
        
        try {
            const res = await fetch(`?api=tail&path=${encodeURIComponent(path)}&lines=${lines}`);
            const data = await res.json();
            
            if (data.ok) {
                currentRawContent = data.content;
                renderContent(data.content);
                document.getElementById('fileSize').innerText = `Size: ${(data.size / 1024 / 1024).toFixed(2)} MB`;
                document.getElementById('statusInfo').innerText = 'Last updated: ' + new Date().toLocaleTimeString();
            } else {
                document.getElementById('terminal').innerHTML = `<div style="color:#ef4444;">ERROR: ${data.error}</div>`;
                document.getElementById('statusInfo').innerText = 'Error';
            }
        } catch (e) {
            document.getElementById('terminal').innerHTML = `<div style="color:#ef4444;">Failed to connect to API.</div>`;
        }
    }

    function renderContent(content) {
        const kw = document.getElementById('logFilter').value.toLowerCase();
        const lines = content.split("\n");
        const term = document.getElementById('terminal');
        
        const html = lines.map(line => {
            if (!line.trim() && line !== "") return "";
            
            let cls = 'log-line';
            const lower = line.toLowerCase();
            if (lower.includes('error') || lower.includes('fail') || lower.includes('critical')) cls += ' log-error';
            else if (lower.includes('warn')) cls += ' log-warn';
            else if (lower.includes('info')) cls += ' log-info';
            else if (lower.includes('success') || lower.includes('done')) cls += ' log-success';

            if (kw && !lower.includes(kw)) return ""; // Filter out

            let displayLine = line;
            if (kw) {
                const reg = new RegExp(`(${kw})`, 'gi');
                displayLine = line.replace(reg, '<span class="log-match">$1</span>');
            }

            return `<div class="${cls}">${displayLine}</div>`;
        }).join('');

        term.innerHTML = html || '<div style="color:#64748b;">No matching lines found.</div>';
        
        // Scroll to bottom if not auto-refreshing or if user is at bottom
        term.scrollTop = term.scrollHeight;
    }

    function applyFilter() {
        renderContent(currentRawContent);
    }

    function toggleAutoRefresh() {
        const isChecked = document.getElementById('autoRefresh').checked;
        if (isChecked) {
            autoTimer = setInterval(refreshLog, 5000);
        } else {
            clearInterval(autoTimer);
        }
    }

    function downloadLog() {
        const path = document.getElementById('logPath').value;
        if (!path) return alert('Select a file first');
        window.open(`?api=download&path=${encodeURIComponent(path)}`, '_blank');
    }
</script>

</body>
</html>
