<?php
/**
 * SNMP TO CONSOLE TOOL - Network Diagnostic Terminal
 * Modernized UI/UX: Version 7.1
 * Consistent with Inventory Agent Dashboard
 */

require_once __DIR__ . '/../../includes/db-connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';

// Set time limit to avoid browser timeout on long walks
set_time_limit(60);

// =====================================================================
// 1. API: Execute Walk
// =====================================================================
if (isset($_POST['api']) && $_POST['api'] === 'walk') {
    ob_clean(); header('Content-Type: application/json');
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($client_token !== $csrf_token) { echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token']); exit; }

    $target = escapeshellarg(trim($_POST['target'] ?? ''));
    $community = escapeshellarg(trim($_POST['community'] ?? 'public'));
    $version = escapeshellarg($_POST['version'] ?? '2c');
    $oid_input = trim($_POST['oid'] ?? '');
    $oid = escapeshellarg(empty($oid_input) ? '.1' : $oid_input);
    $mib_mode = $_POST['mib_mode'] ?? 'default';

    $cmd = "snmpwalk -t 2 -r 1 -O n -v $version -c $community ";
    
    // Dynamic MIB Path
    if ($mib_mode === 'pandora') {
        $mib_dir = realpath(__DIR__ . '/../../../../attachment/mibs');
        if ($mib_dir) {
            $cmd .= "-M " . escapeshellarg("+" . $mib_dir) . " ";
        }
    }
    
    $cmd .= "$target $oid 2>&1";
    
    // Execute and handle output limit in PHP instead of shell 'head' for cross-platform compatibility
    $raw_output = shell_exec($cmd);
    
    if ($raw_output === null) {
        echo json_encode(['ok' => false, 'error' => 'Failed to execute snmpwalk. Is it installed and in the system PATH?']);
        exit;
    }

    $lines = explode("\n", $raw_output);
    $output = implode("\n", array_slice($lines, 0, 500));
    if (count($lines) > 500) $output .= "\n... (Output truncated to 500 lines)";
    
    echo json_encode(['ok' => true, 'command' => $cmd, 'output' => $output]);
    exit;
}

// =====================================================================
// 2. API: Get Resources (Agents, Groups, Fields)
// =====================================================================
if (isset($_GET['api']) && $_GET['api'] === 'get_resources') {
    ob_clean(); header('Content-Type: application/json');
    try {
        if (!$db_status) throw new Exception("Database not connected.");
        $groups = $pdo->query("SELECT id_grupo, nombre FROM tgrupo ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
        
        $ipCol = 'direccion';
        $checkIpCol = $pdo->query("SHOW COLUMNS FROM tagente LIKE 'ip_address'");
        if ($checkIpCol->rowCount() > 0) $ipCol = 'ip_address';
        
        $agents = $pdo->query("SELECT id_agente, alias as name, $ipCol as address, id_grupo FROM tagente WHERE disabled = 0 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $fields = $pdo->query("SELECT id_field, name FROM tagent_custom_fields ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'agents' => $agents, 'groups' => $groups, 'fields' => $fields]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}

// =====================================================================
// 3. API: Bulk Sync Logic
// =====================================================================
if (isset($_POST['api']) && $_POST['api'] === 'push_custom_data') {
    ob_clean(); header('Content-Type: application/json');
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($client_token !== $csrf_token) { echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token']); exit; }

    try {
        if (!$db_status) throw new Exception("Database not connected.");
        $agent_ids = $_POST['agent_ids'] ?? [];
        $field_names = $_POST['field_names'] ?? [];
        $new_field = trim($_POST['new_field'] ?? '');
        $oid = trim($_POST['oid'] ?? '');
        $community = trim($_POST['community'] ?? 'public');
        $version = $_POST['version'] ?? '2c';
        $fallback_value = trim($_POST['fallback_value'] ?? '');

        if (empty($agent_ids)) throw new Exception("Select at least one agent.");
        
        $target_field_ids = [];
        if (!empty($field_names)) {
            foreach ($field_names as $fname) {
                $stmt = $pdo->prepare("SELECT id_field FROM tagent_custom_fields WHERE name = ?");
                $stmt->execute([$fname]);
                $f = $stmt->fetch();
                if ($f) $target_field_ids[] = $f['id_field'];
            }
        }
        if (!empty($new_field)) {
            $stmt = $pdo->prepare("SELECT id_field FROM tagent_custom_fields WHERE name = ?");
            $stmt->execute([$new_field]);
            $f = $stmt->fetch();
            if ($f) { if (!in_array($f['id_field'], $target_field_ids)) $target_field_ids[] = $f['id_field']; } 
            else {
                $pdo->prepare("INSERT INTO tagent_custom_fields (name, display_on_front) VALUES (?, 1)")->execute([$new_field]);
                $target_field_ids[] = $pdo->lastInsertId();
            }
        }

        $dataCol = $pdo->query("SHOW COLUMNS FROM tagent_custom_data LIKE 'description'")->rowCount() > 0 ? 'description' : 'value';
        $ipCol = 'direccion';
        $checkIpCol = $pdo->query("SHOW COLUMNS FROM tagente LIKE 'ip_address'");
        if ($checkIpCol->rowCount() > 0) $ipCol = 'ip_address';

        $sc = 0; $fc = 0;
        foreach ($agent_ids as $aid) {
            $stmtA = $pdo->prepare("SELECT $ipCol as address FROM tagente WHERE id_agente = ?");
            $stmtA->execute([(int)$aid]);
            $agent_data = $stmtA->fetch();
            $target_ip = $agent_data['address'] ?? '';

            $final_value = $fallback_value;
            if (!empty($target_ip) && !empty($oid)) {
                $s_ip = escapeshellarg($target_ip); $s_com = escapeshellarg($community); $s_ver = escapeshellarg($version); $s_oid = escapeshellarg($oid);
                $get_cmd = "snmpget -t 1 -r 0 -v $s_ver -c $s_com $s_ip $s_oid 2>&1";
                $raw_val = shell_exec($get_cmd);
                if ($raw_val && strpos($raw_val, ' = ') !== false) {
                    $val_part = explode(' = ', $raw_val)[1];
                    if (strpos($val_part, ': ') !== false) $val_part = explode(': ', $val_part, 2)[1];
                    $final_value = trim(str_replace('"', '', $val_part));
                    $sc++;
                } else { $fc++; }
            }

            foreach ($target_field_ids as $fid) {
                $stmtCheck = $pdo->prepare("SELECT 1 FROM tagent_custom_data WHERE id_field = ? AND id_agent = ?");
                $stmtCheck->execute([$fid, $aid]);
                if ($stmtCheck->fetch()) {
                    $pdo->prepare("UPDATE tagent_custom_data SET $dataCol = ? WHERE id_field = ? AND id_agent = ?")->execute([$final_value, $fid, $aid]);
                } else {
                    $pdo->prepare("INSERT INTO tagent_custom_data (id_field, id_agent, $dataCol) VALUES (?, ?, ?)")->execute([$fid, $aid, $final_value]);
                }
            }
        }
        echo json_encode(['ok' => true, 'msg' => "Sync Complete!\n- $sc Success\n- $fc Fallback"]);
    } catch (Exception $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage()]); }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SNMP to Console - Modernized</title>
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/custom/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6f8; color: #334155; font-size: 13px; margin: 0; }
        .material-symbols-outlined { vertical-align: middle; font-size: 18px; }
        
        .header-box { background: #f4f6f8; padding: 15px 30px; border-bottom: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; }
        .page-title { font-size: 20px; font-weight: 600; color: #0b1a26; margin: 0; }
        
        .main-container { padding: 25px 30px; display: flex; gap: 25px; }
        .form-card { width: 320px; background: #fff; border-radius: 8px; border: 1px solid #f0f3f5; box-shadow: 0 2px 8px rgba(0,0,0,0.04); padding: 20px; }
        .result-card { flex: 1; background: #fff; border-radius: 8px; border: 1px solid #f0f3f5; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; flex-direction: column; overflow: hidden; min-height: 500px; }
        
        .form-label { font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-bottom: 5px; display: block; }
        .form-control, .form-select { font-size: 13px; border-color: #dce1e5; padding: 7px 10px; }
        .form-control:focus { border-color: #059669; box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1); }
        
        .btn-premium { background: #059669; color: #fff; border: none; padding: 10px 15px; border-radius: 6px; font-weight: 600; width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; transition: 0.2s; }
        .btn-premium:hover { background: #047857; }
        .btn-premium:disabled { background: #94a3b8; }
        
        .tab-nav { background: #f8fafc; border-bottom: 1px solid #f0f3f5; padding: 10px 20px; display: flex; gap: 15px; }
        .tab-btn { background: none; border: none; padding: 5px 15px; color: #64748b; font-weight: 500; font-size: 12px; cursor: pointer; border-radius: 4px; }
        .tab-btn.active { background: #0b1a26; color: #fff; }
        
        #terminal { background: #0b1a26; color: #d1d5db; font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; padding: 20px; flex: 1; overflow-y: auto; white-space: pre-wrap; line-height: 1.6; }
        #tableContainer { flex: 1; display: none; overflow-y: auto; }
        
        .table-modern { width: 100%; border-collapse: collapse; }
        .table-modern th { background: #fff; border-bottom: 2px solid #f0f3f5; padding: 12px 15px; text-align: left; font-size: 10px; text-transform: uppercase; color: #94a3b8; position: sticky; top: 0; }
        .table-modern td { padding: 10px 15px; border-bottom: 1px solid #f0f3f5; font-size: 12px; }
        .badge-type { background: #f1f5f9; color: #475569; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; }
        
        .btn-action { border: none; padding: 5px 10px; border-radius: 4px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; color: #fff; }
        .bg-blue { background: #3b82f6; } .bg-emerald { background: #10b981; }
        
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.6); display: none; justify-content: center; align-items: center; z-index: 9999; backdrop-filter: blur(2px); }
        .modal-box { background: #fff; border-radius: 12px; width: 600px; padding: 30px; box-shadow: 0 20px 40px rgba(0,0,0,0.2); max-height: 90vh; overflow-y: auto; }
        .field-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; max-height: 150px; overflow-y: auto; }
        .field-item { font-size: 12px; display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 5px; border-radius: 4px; }
        .field-item:hover { background: #f1f5f9; }
    </style>
</head>
<body>

<div class="header-box">
    <div>
        <div style="font-size:11px; color:#7f8c8d; text-transform:uppercase; margin-bottom:4px;">Pandora Console / Custom / Management</div>
        <h1 class="page-title">SNMP to Console</h1>
    </div>
</div>

<div class="main-container">
    <div class="form-card">
        <div class="mb-3">
            <label class="form-label">Target IP / Hostname</label>
            <input type="text" id="target" class="form-control" placeholder="e.g. 192.168.1.1">
        </div>
        <div class="row g-2 mb-3">
            <div class="col-4"><label class="form-label">Version</label><select id="version" class="form-select"><option value="1">v1</option><option value="2c" selected>v2c</option></select></div>
            <div class="col-8"><label class="form-label">Community</label><input type="text" id="community" class="form-control" value="public"></div>
        </div>
        <div class="mb-3">
            <label class="form-label">Start OID</label>
            <input type="text" id="oid" class="form-control" value=".1">
        </div>
        <div class="mb-4">
            <label class="form-label">MIB Mode</label>
            <select id="mibMode" class="form-select"><option value="default">System Default</option><option value="pandora">Pandora Attachment</option></select>
        </div>
        <button id="runBtn" class="btn-premium" onclick="runWalk()">
            <span class="material-symbols-outlined">rocket_launch</span> Run SNMP Walk
        </button>
        <div id="loader" class="text-center mt-3" style="display:none; color:#059669; font-weight:600;">
            <div class="spinner-border spinner-border-sm"></div> Walking...
        </div>
    </div>

    <div class="result-card">
        <div class="tab-nav">
            <button class="tab-btn active" id="tabTerm" onclick="switchView('terminal')">Terminal Output</button>
            <button class="tab-btn" id="tabTable" onclick="switchView('table')">Interactive Table</button>
            <div style="flex:1; text-align:right; color:#94a3b8; font-size:11px; padding-top:5px;" id="cmdHint">Ready</div>
        </div>
        <div id="terminal">Waiting for input...</div>
        <div id="tableContainer">
            <table class="table-modern">
                <thead><tr><th>OID Name / Number</th><th>Type</th><th>Value</th><th style="width:180px;">Action</th></tr></thead>
                <tbody id="tableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- SYNC MODAL -->
<div class="modal-overlay" id="pushModal">
    <div class="modal-box">
        <div class="d-flex justify-content-between mb-4">
            <h5 class="fw-bold m-0"><span class="material-symbols-outlined text-success">sync_alt</span> Bulk Discovery & Sync</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;" onclick="closeModal('pushModal')">close</span>
        </div>
        
        <div class="alert alert-success py-2 px-3 small border-0" style="background:#ecfdf5; color:#065f46;">
            <strong>Pro Mode:</strong> System will fetch unique values for each agent automatically.
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label">Group Filter</label><select id="groupFilter" class="form-select form-select-sm" onchange="filterAgents()"><option value="all">-- All --</option></select></div>
            <div class="col-6 d-flex align-items-end"><button class="btn btn-sm btn-outline-secondary w-100" onclick="selectAllAgents()">Select All Visible</button></div>
        </div>

        <div class="mb-3">
            <label class="form-label">Target Agents</label>
            <select id="pushAgent" class="form-select" multiple style="height:120px;" onchange="updateCount()"></select>
            <div id="agentCount" class="small text-muted mt-1">0 agents selected</div>
        </div>

        <div class="mb-3">
            <label class="form-label">Custom Fields (Existing)</label>
            <div id="fieldGrid" class="field-grid"></div>
        </div>

        <div class="mb-3"><label class="form-label">Create New Field (Optional)</label><input type="text" id="pushNewField" class="form-control" placeholder="e.g. SerialNumber"></div>
        <div class="mb-4"><label class="form-label">OID Source</label><input type="text" id="pushOid" class="form-control bg-light" readonly></div>

        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-sm btn-light" onclick="closeModal('pushModal')">Cancel</button>
            <button class="btn btn-sm btn-success px-4" id="pushBtn" onclick="submitPush()">Discover & Sync All</button>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/custom/panel/vendor/jquery/jquery-3.7.0.min.js"></script>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/custom/panel/vendor/bootstrap/bootstrap.bundle.min.js"></script>

<script>
    let resData = { agents: [], fields: [] };
    let lastOutput = '';

    $(document).ready(() => loadResources());

    async function loadResources() {
        const r = await fetch('?api=get_resources');
        const d = await r.json();
        if (d.ok) {
            resData = d;
            $('#groupFilter').append(d.groups.map(g => `<option value="${g.id_grupo}">${g.nombre}</option>`));
            $('#fieldGrid').html(d.fields.map(f => `<label class="field-item"><input type="checkbox" name="pushFields" value="${f.name}"><span>${f.name}</span></label>`));
            filterAgents();
        }
    }

    function filterAgents() {
        const gid = $('#groupFilter').val();
        const filtered = gid === 'all' ? resData.agents : resData.agents.filter(a => a.id_grupo == gid);
        $('#pushAgent').html(filtered.map(a => `<option value="${a.id_agente}">${a.name} (${a.address})</option>`));
        updateCount();
    }
    function selectAllAgents() { $('#pushAgent option').prop('selected', true); updateCount(); }
    function updateCount() { $('#agentCount').text(`${$('#pushAgent :selected').length} agents selected`); }
    function closeModal(id) { $(`#${id}`).fadeOut(200); }
    function switchView(mode) {
        $('.tab-btn').removeClass('active');
        $(`#tab${mode.charAt(0).toUpperCase() + mode.slice(1)}`).addClass('active');
        $('#terminal, #tableContainer').hide();
        if (mode === 'terminal') $('#terminal').show(); else { $('#tableContainer').show(); renderTable(); }
    }

    function renderTable() {
        if (!lastOutput) { $('#tableBody').html('<tr><td colspan="4" class="text-center p-4 text-muted">No data available</td></tr>'); return; }
        const lines = lastOutput.split('\n').filter(l => l.includes(' = '));
        $('#tableBody').html(lines.map(l => {
            const parts = l.split(' = ');
            const oid = parts[0].trim();
            const valParts = parts[1].split(': ');
            const type = valParts[0].trim();
            const val = (valParts[1] || '').trim().replace(/"/g, '');
            return `<tr>
                <td class="font-monospace fw-bold" style="color:#0f172a;">${oid}</td>
                <td><span class="badge-type">${type}</span></td>
                <td class="text-muted">${val}</td>
                <td class="d-flex gap-1">
                    <button class="btn-action bg-blue" title="Add as Module"><span class="material-symbols-outlined">add_box</span></button>
                    <button class="btn-action bg-emerald" onclick="openPush('${oid}', '${val}')"><span class="material-symbols-outlined">sync_alt</span> Push</button>
                </td>
            </tr>`;
        }).join(''));
    }

    function openPush(oid, val) {
        $('#pushOid').val(oid);
        window.currentVal = val;
        $('#pushModal').css('display', 'flex').hide().fadeIn(200);
    }

    async function runWalk() {
        const btn = $('#runBtn'); btn.prop('disabled', true); $('#loader').show();
        const fd = new FormData();
        fd.append('api', 'walk'); fd.append('target', $('#target').val());
        fd.append('community', $('#community').val()); fd.append('version', $('#version').val());
        fd.append('oid', $('#oid').val()); fd.append('mib_mode', $('#mibMode').val());

        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 60000); // 60s timeout

        try {
            const r = await fetch('', { 
                method: 'POST', 
                headers: { 'X-CSRF-TOKEN': '<?= $csrf_token ?>' }, 
                body: fd,
                signal: controller.signal 
            });
            clearTimeout(timeoutId);
            const d = await r.json();
            if (d.ok) { lastOutput = d.output; $('#terminal').text(d.output); $('#cmdHint').text(d.command); if ($('#tabTable').hasClass('active')) renderTable(); }
            else $('#terminal').text("Error: " + d.error);
        } catch (e) {
            if (e.name === 'AbortError') $('#terminal').text("Error: Request timed out (60s). The target might be slow or unreachable.");
            else $('#terminal').text("Error: " + e.message);
        } finally { btn.prop('disabled', false); $('#loader').hide(); }
    }

    async function submitPush() {
        const ids = $('#pushAgent').val();
        const fnames = $('input[name="pushFields"]:checked').map(function(){return $(this).val();}).get();
        if (!ids.length) return alert("Select agents first!");
        
        const btn = $('#pushBtn'); btn.prop('disabled', true).text('Syncing...');
        const fd = new FormData();
        fd.append('api', 'push_custom_data');
        ids.forEach(id => fd.append('agent_ids[]', id));
        fnames.forEach(f => fd.append('field_names[]', f));
        fd.append('new_field', $('#pushNewField').val());
        fd.append('oid', $('#pushOid').val());
        fd.append('community', $('#community').val());
        fd.append('version', $('#version').val());
        fd.append('fallback_value', window.currentVal);

        try {
            const r = await fetch('', { method: 'POST', headers: { 'X-CSRF-TOKEN': '<?= $csrf_token ?>' }, body: fd });
            const d = await r.json();
            if (d.ok) { alert(d.msg); closeModal('pushModal'); } else alert(d.error);
        } finally { btn.prop('disabled', false).text('Discover & Sync All'); }
    }
</script>
</body>
</html>
