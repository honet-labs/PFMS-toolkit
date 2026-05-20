<?php
declare(strict_types=1);

/**
 * Pandora FMS NetPath (ULTIMATE ENTERPRISE EDITION)
 * UI/UX Overhaul: Minimalist Animated Flow (Solid lines removed)
 */

ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('memory_limit', '512M'); 

@set_time_limit(300);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// --- 1. DATABASE CONNECTION ---
$db_connection_file = __DIR__ . '/../../includes/db-connection.php';
if (file_exists($db_connection_file)) {
    require_once $db_connection_file;
} else {
    $possible_paths = [ dirname(__DIR__, 2) . '/includes/db-connection.php', $_SERVER['DOCUMENT_ROOT'] . '/pandora_console/includes/db-connection.php' ];
    foreach ($possible_paths as $p) { if (file_exists($p)) { require_once $p; break; } }
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("<div style='color:red; padding:20px; font-family:sans-serif;'><b>FATAL ERROR:</b> Central database connection failed.</div>");
}

// --- 2. CONFIG & HELPERS (PROTECTED) ---
$STORE_FILENAME = 'pfms-routepath.json';
$STORE_DIR = __DIR__;
foreach (['/var/spool/pandora/data_in', sys_get_temp_dir(), __DIR__] as $dir) { if (is_dir($dir) && is_writable($dir)) { $STORE_DIR = $dir; break; } }
$storeFile = rtrim($STORE_DIR, '/') . '/' . $STORE_FILENAME;
$lockFile = $storeFile . '.lock';
$ROUTE_PARSER_BIN = '/etc/pandora/plugins/route_parser';

if (!function_exists('h')) { function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }

if (!function_exists('loadDashboards')) {
    function loadDashboards(string $file): array { $raw = @file_get_contents($file); return json_decode((string)$raw, true) ?: []; }
}
if (!function_exists('saveDashboards')) {
    function saveDashboards(string $file, array $dash): bool { return file_put_contents($file, json_encode(array_values($dash), JSON_PRETTY_PRINT)) !== false; }
}
if (!function_exists('getPandoraAgents')) {
    function getPandoraAgents(PDO $pdo): array { return $pdo->query("SELECT id_agente, nombre, alias FROM tagente WHERE disabled = 0 ORDER BY alias ASC")->fetchAll(); }
}
if (!function_exists('labelFromModuleName')) {
    function labelFromModuleName(string $name): string {
        foreach (['RouteStepTarget_', 'RouteStep_'] as $p) { if (strpos($name, $p) === 0) return substr($name, strlen($p)); }
        return $name;
    }
}
if (!function_exists('statusColor')) {
    function statusColor(string $status): string { return match ($status) { 'ok'=>'#2ecc71', 'warn'=>'#f1c40f', 'crit'=>'#e74c3c', default=>'#95a5a6' }; }
}
if (!function_exists('estadoToStatus')) {
    function estadoToStatus(?int $estado): string { $e = (int)$estado; return match($e) { 0=>'ok', 1=>'crit', 2=>'warn', default=>'na' }; }
}

// --- 3. DISCOVERY EXECUTION ---
if (!function_exists('runDiscovery')) {
    function runDiscovery(string $agentName, string $target, ?string $from = null): string {
        global $ROUTE_PARSER_BIN;
        if (!is_executable($ROUTE_PARSER_BIN)) return "Discovery engine not found or not executable.";
        $cmd = $ROUTE_PARSER_BIN . " -t " . escapeshellarg($target);
        if ($from) $cmd .= " -f " . escapeshellarg($from);
        $output = []; $ret = 0;
        exec($cmd, $output, $ret); 
        $xml = implode("\n", $output);
        if (strpos($xml, '<module>') === false) return !empty($xml) ? $xml : "Target unreachable or failed to analyze.";
        $ts = date('Y-m-d H:i:s');
        $agentXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<agent_data agent_name=\"".h($agentName)."\" timestamp=\"$ts\" version=\"1.0\" os=\"Other\" interval=\"300\">\n$xml\n</agent_data>";
        $spoolDir = '/var/spool/pandora/data_in';
        if (!is_dir($spoolDir)) $spoolDir = sys_get_temp_dir();
        $filename = $spoolDir . '/netpath.' . bin2hex(random_bytes(4)) . '.' . time() . '.data';
        return @file_put_contents($filename, $agentXml) ? "ok" : "Failed to write to Pandora FMS spool.";
    }
}

if (!function_exists('layoutGraph')) {
    function layoutGraph(array $nodes, array $edges): array {
        if (empty($nodes)) return ['pos'=>[], 'svgW'=>1000, 'svgH'=>1000];
        $children = []; $parents = [];
        foreach ($edges as [$p, $c]) { $children[$p][] = $c; $parents[$c][] = $p; }
        $roots = []; foreach ($nodes as $name => $n) { if (empty($parents[$name])) $roots[$name] = true; }
        $rootList = array_keys($roots); sort($rootList);
        $depth = []; $q = []; foreach ($rootList as $r) { $depth[$r] = 0; $q[] = $r; }
        $safety = 0;
        for ($i=0; $i<count($q); $i++) { if ($safety++ > 3000) break; $u = $q[$i]; $d = $depth[$u]; foreach (($children[$u] ?? []) as $v) { if (!isset($depth[$v]) || $depth[$v] > $d+1) { $depth[$v]=$d+1; $q[]=$v; } } }
        foreach ($nodes as $name => $_) if (!isset($depth[$name])) $depth[$name] = 0;
        $y = []; $visited = []; $currentY = 200.0; $Y_SPACING = 200.0;
        $dfs_logic = function($u) use (&$dfs_logic, &$children, &$y, &$visited, &$currentY, $Y_SPACING) {
            if (isset($visited[$u])) return; $visited[$u] = true;
            $chs = $children[$u] ?? [];
            if (!$chs) { $y[$u] = $currentY; $currentY += $Y_SPACING; return; }
            foreach ($chs as $v) $dfs_logic($v);
            $sum = 0.0; $cnt = 0; foreach ($chs as $v) { if (isset($y[$v])) { $sum += $y[$v]; $cnt++; } }
            $y[$u] = ($cnt > 0) ? ($sum / $cnt) : $currentY;
            if ($cnt == 0) $currentY += $Y_SPACING; 
        };
        foreach ($rootList as $r) { $dfs_logic($r); $currentY += $Y_SPACING; }
        $X_START = 160.0; $X_SPACING = 300.0; $pos = []; $maxDepth = 0; $minY = 9999.0; $maxY = -9999.0;
        foreach ($nodes as $name => $_) { $d = min($depth[$name], 25); $maxDepth = max($maxDepth, $d); $xx = $X_START + $d * $X_SPACING; $yy = $y[$name] ?? 200.0; $minY = min($minY, $yy); $maxY = max($maxY, $yy); $pos[$name] = ['x'=>$xx, 'y'=>$yy]; }
        $paddingTop = 150.0; $viewH = 750.0; $scaleY = ($maxY > $minY) ? ($viewH / ($maxY - $minY)) : 1.0;
        foreach ($pos as $k => $p) { $pos[$k]['y'] = $paddingTop + (($p['y'] - $minY) * $scaleY); }
        return ['pos'=>$pos, 'svgW'=>($maxDepth+1)*$X_SPACING+350, 'svgH'=>1000];
    }
}

if (!function_exists('drawIcon')) {
    function drawIcon(string $type, string $color = "white", float $size = 24.0): string {
        $scale = $size / 24.0; $translate = ($size / 2) * -1;
        $markup = match($type) {
            'globe' => '<circle cx="12" cy="12" r="8.5" fill="none"/><path d="M3.5 12h17"/><path d="M12 3.5c3 3.2 3 13.8 0 17"/><path d="M12 3.5c-3 3.2-3 13.8 0 17"/>',
            'router' => '<path d="M5 15.5c4.8-4.2 9.2-4.2 14 0"/><path d="M7.7 12.7c3-2.6 5.6-2.6 8.6 0"/><path d="M10.3 10.1c1.4-1.1 2-1.1 3.4 0"/><path d="M7 18h10"/><circle cx="8.2" cy="20" r="0.9"/><circle cx="12" cy="20" r="0.9"/><circle cx="15.8" cy="20" r="0.9"/>',
            'target' => '<circle cx="12" cy="12" r="7.8" fill="none"/><circle cx="12" cy="12" r="3.2" fill="none"/><path d="M12 4V2.5"/><path d="M20 12h1.5"/>',
            default => '<path d="M5 15.5c4.8-4.2 9.2-4.2 14 0"/><path d="M7.7 12.7c3-2.6 5.6-2.6 8.6 0"/><path d="M10.3 10.1c1.4-1.1 2-1.1 3.4 0"/><path d="M7 18h10"/><circle cx="8.2" cy="20" r="0.9"/><circle cx="12" cy="20" r="0.9"/><circle cx="15.8" cy="20" r="0.9"/>'
        };
        return '<g transform="translate('.$translate.','.$translate.') scale('.$scale.')" stroke="'.$color.'" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">'.$markup.'</g>';
    }
}

// --- 5. ACTIONS ---
$pageParam = $_GET['page'] ?? 'tools/pfms-routepath/pfms_routepath.php';
$baseUrl = "?page=" . urlencode($pageParam);
$agents = getPandoraAgents($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $dashboards = loadDashboards($storeFile);
        if ($_POST['action'] === 'create') {
            $id = bin2hex(random_bytes(16));
            $target = $_POST['target'] ?: '8.8.8.8';
            $agentId = (int)$_POST['agent_id'];
            $agentName = ''; $agentAlias = '';
            foreach ($agents as $a) { if ((int)$a['id_agente'] === $agentId) { $agentName = $a['nombre']; $agentAlias = $a['alias']; break; } }
            $res = runDiscovery($agentName, $target);
            if ($res === 'ok') {
                $dashboards[] = ['id'=>$id, 'name'=>$_POST['name'] ?: 'Path to '.$target, 'target'=>$target, 'agent_id'=>$agentId, 'agent_name'=>$agentName, 'agent_alias'=>$agentAlias, 'last_update'=>time(), 'segments'=>[]];
                saveDashboards($storeFile, $dashboards);
                header("Location: $baseUrl&path_id=$id"); exit;
            } else { header("Location: $baseUrl&error=" . urlencode($res)); exit; }
        }
        if (in_array($_POST['action'], ['rescan', 'lazy_rescan']) && isset($_POST['id'])) {
            $lockId = $_POST['id'];
            $lockPath = $lockFile . '.' . $lockId;
            if (file_exists($lockPath) && (time() - filemtime($lockPath) < 60)) {
                if ($_POST['action'] === 'lazy_rescan') { header("Location: $baseUrl&path_id=".$lockId); exit; }
                header("Location: $baseUrl&path_id=".$lockId."&error=Update already in progress."); exit;
            }
            @touch($lockPath);
            $err = null;
            foreach ($dashboards as &$d) {
                if ($d['id'] === $lockId) {
                    $res = runDiscovery($d['agent_name'], $d['target']);
                    if ($res !== 'ok') $err = $res;
                    foreach (($d['segments'] ?? []) as $s) {
                        $res2 = runDiscovery($d['agent_name'], $s['to'], $s['from']);
                        if ($res2 !== 'ok') $err = $res2;
                    }
                    $d['last_update'] = time(); break;
                }
            }
            saveDashboards($storeFile, $dashboards);
            @unlink($lockPath);
            header("Location: $baseUrl&path_id=".$lockId . ($err && $_POST['action'] !== 'lazy_rescan' ? "&error=".urlencode($err) : "")); exit;
        }
        if ($_POST['action'] === 'add_segment' && isset($_POST['id'])) {
            $err = null;
            foreach ($dashboards as &$d) {
                if ($d['id'] === $_POST['id']) {
                    $targets = preg_split('/[\s,]+/', $_POST['to_ip'], -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($targets as $t) {
                        $res = runDiscovery($d['agent_name'], $t, $_POST['from_hop']);
                        if ($res === 'ok') { $d['segments'][] = ['from' => $_POST['from_hop'], 'to' => $t]; } else { $err = $res; }
                    }
                    $d['last_update'] = time(); break;
                }
            }
            saveDashboards($storeFile, $dashboards);
            header("Location: $baseUrl&path_id=".$_POST['id'] . ($err ? "&error=".urlencode($err) : "")); exit;
        }
        if ($_POST['action'] === 'delete_module' && isset($_POST['mid'])) {
            $pdo->prepare("DELETE FROM tagente_modulo WHERE id_agente_modulo = ?")->execute([(int)$_POST['mid']]);
            header("Location: $baseUrl&path_id=".$_POST['id']); exit;
        }
    } catch (Exception $e) { header("Location: $baseUrl&error=" . urlencode($e->getMessage())); exit; }
}

if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $dashboards = loadDashboards($storeFile);
    $dashboards = array_filter($dashboards, function($d) { return $d['id'] !== $_GET['id']; });
    saveDashboards($storeFile, $dashboards);
    header("Location: $baseUrl"); exit;
}

$dashboards = loadDashboards($storeFile);
$PATH_ID = $_GET['path_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NetPath Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <style>
        :root { --primary-dark: #0b1a26; --secondary-text: #64748b; --border-color: #e0e4e8; --bg-color: #f4f6f8; --accent-green: #004d40; --accent-green-hover: #00332a; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-color); color: #334155; margin: 0; font-size: 14px; -webkit-font-smoothing: antialiased; }
        .pandora-header-bottom { background: var(--bg-color); padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; }
        .page-title { font-size: 18px; color: var(--primary-dark); margin: 0; font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .btn-apply { background: var(--accent-green); color: #fff !important; border: none; padding: 8px 20px; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; transition: 0.2s; }
        .btn-apply:hover { background: var(--accent-green-hover); }
        .btn-secondary-custom { background: #fff; color: #4a5568 !important; border: 1px solid #dce1e5; padding: 8px 16px; border-radius: 4px; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .btn-secondary-custom:hover { background: #f8f9fa; }
        .grid-layout { display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px; padding: 0 30px 30px 30px; }
        .dashboard-card { background: #fff; border-radius: 8px; border: 1px solid #f0f3f5; box-shadow: 0 2px 8px rgba(0,0,0,0.04); overflow: hidden; transition: 0.2s; }
        .dashboard-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .topology-container { display: flex; height: calc(100vh - 120px); position: relative; overflow: hidden; margin: 0 30px 30px 30px; background: #fff; border-radius: 8px; border: 1px solid var(--border-color); box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
        #graph-area { flex: 1; position: relative; overflow: hidden; cursor: grab; }
        .sidebar { width: 320px; border-left: 1px solid var(--border-color); display: flex; flex-direction: column; background: #fff; }
        .loading-overlay { position: fixed; inset: 0; background: rgba(255,255,255,0.95); z-index: 9999; display: none; flex-direction: column; align-items: center; justify-content: center; }
        .spinner { width: 44px; height: 44px; border: 4px solid #e2e8f0; border-top-color: var(--accent-green); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .switch { position: relative; display: inline-block; width: 40px; height: 20px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--accent-green); }
        input:checked + .slider:before { transform: translateX(20px); }
        .lazy-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 10px; font-weight: 700; color: #475569; background: #f1f5f9; padding: 4px 12px; border-radius: 12px; border: 1px solid #e2e8f0; }
        .form-control-fix { width: 100%; height: 36px; padding: 8px 12px; border: 1px solid #dce1e5; border-radius: 4px; outline: none; box-sizing: border-box; font-family: inherit; }
        .alert-premium { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); background: #fff; border-left: 5px solid #ef4444; padding: 15px 25px; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); z-index: 10000; display: flex; align-items: center; gap: 15px; }
        @keyframes flow { from { stroke-dashoffset: 24; } to { stroke-dashoffset: 0; } }
    </style>
</head>
<body>

<div id="globalLoading" class="loading-overlay"><div class="spinner"></div><h2 style="margin:16px 0 0 0; font-size:16px; font-weight:600; color:var(--primary-dark);">Updating Route Topology...</h2><p style="font-size:12px; color:#64748b; margin-top:8px;">Running discovery in background</p></div>

<?php if (isset($_GET['error'])): ?>
<div class="alert-premium" id="errorAlert"><span class="material-symbols-outlined" style="color:#ef4444;">error</span><div style="flex:1;"><p style="margin:0; font-size:12px; color:#ef4444; font-weight:500;"><?= h($_GET['error']) ?></p></div><button style="border:none; background:none; cursor:pointer;" onclick="document.getElementById('errorAlert').style.display='none'"><span class="material-symbols-outlined">close</span></button></div>
<?php endif; ?>

<script>
    function showLoading() { document.getElementById('globalLoading').style.display = 'flex'; }
    let refreshInterval = null;
    function toggleAutoRefresh() {
        const isChecked = document.getElementById('autoRefreshCheck').checked;
        localStorage.setItem('pfms_netpath_refresh', isChecked ? 'on' : 'off');
        if (refreshInterval) clearInterval(refreshInterval);
        if (isChecked) refreshInterval = setInterval(() => { location.reload(); }, 60000);
    }
</script>

<?php if (!$PATH_ID): ?>
    <div class="pandora-header-bottom">
        <div><h1 class="page-title"><span class="material-symbols-outlined" style="color:var(--accent-green);">hub</span> Network Path Monitoring</h1></div>
        <div style="display:flex; align-items:center; gap:20px;">
            <div style="display:flex; align-items:center; gap:10px; background:#fff; padding:5px 15px; border-radius:20px; border:1px solid #e2e8f0;"><span style="font-size:11px; font-weight:600; color:#475569;">AUTO REFRESH</span><label class="switch"><input type="checkbox" id="autoRefreshCheck" onchange="toggleAutoRefresh()"><span class="slider"></span></label></div>
            <button class="btn-apply" onclick="document.getElementById('modalCreate').style.display='flex'"><span class="material-symbols-outlined">add</span> New Path</button>
        </div>
    </div>
    <div class="grid-layout">
        <?php foreach($dashboards as $d): ?>
            <div class="dashboard-card">
                <div style="padding:15px 20px; border-bottom:1px solid #f1f5f9; background:#f8fafc; display:flex; justify-content:space-between; align-items:center;"><h5 style="margin:0; font-weight:600; color:#1e293b;"><?= h($d['name']) ?></h5><span style="font-size:10px; font-weight:700; color:#475569; background:#e2e8f0; padding:2px 8px; border-radius:10px;"><?= h($d['target']) ?></span></div>
                <div style="padding:20px;">
                    <div style="font-size:12px; color:#64748b;">Monitoring via <b><?= h($d['agent_alias'] ?? $d['agent_name']) ?></b></div>
                    <div class="lazy-badge" style="margin-top:10px;"><span class="material-symbols-outlined" style="font-size:14px;">schedule</span> Updated: <?= date('H:i', $d['last_update']) ?></div>
                    <div style="display:flex; gap:10px; margin-top:20px;">
                        <a href="<?= $baseUrl ?>&path_id=<?= h($d['id']) ?>" class="btn-apply" style="flex:1; justify-content:center;">View Topology</a>
                        <button class="btn-secondary-custom" style="color:#ef4444!important;" onclick="if(confirm('Delete?')){location.href='<?= $baseUrl ?>&action=delete&id=<?= h($d['id']) ?>';}"><span class="material-symbols-outlined">delete</span></button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div id="modalCreate" style="position:fixed; inset:0; background:rgba(0,0,0,0.5); display:none; align-items:center; justify-content:center; z-index:2000;"><div style="background:#fff; width:450px; padding:30px; border-radius:8px;"><h3 style="margin-top:0; font-weight:600;">Setup New Path</h3><form method="post" onsubmit="showLoading()"><input type="hidden" name="action" value="create"><div style="margin-bottom:15px;"><label style="display:block; font-size:11px; font-weight:600; color:#64748b; margin-bottom:5px;">NAME</label><input type="text" name="name" class="form-control-fix" placeholder="Path Name" required></div><div style="margin-bottom:15px;"><label style="display:block; font-size:11px; font-weight:600; color:#64748b; margin-bottom:5px;">TARGET IP</label><input type="text" name="target" class="form-control-fix" placeholder="8.8.8.8" required></div><div style="margin-bottom:20px;"><label style="display:block; font-size:11px; font-weight:600; color:#64748b; margin-bottom:5px;">AGENT</label><select name="agent_id" class="form-control-fix" required><option value="">-- Select Agent --</option><?php foreach ($agents as $a): ?><option value="<?= (int)$a['id_agente'] ?>"><?= h($a['alias'] ?: $a['nombre']) ?></option><?php endforeach; ?></select></div><div style="display:flex; justify-content:flex-end; gap:10px;"><button type="button" class="btn-secondary-custom" onclick="document.getElementById('modalCreate').style.display='none'">Cancel</button><button type="submit" class="btn-apply">Initialize</button></div></form></div></div>
<?php else: ?>
    <?php
    $dash = null; foreach($dashboards as $d) { if($d['id']===$PATH_ID) { $dash=$d; break; } }
    if(!$dash) die("Path not found.");
    if (time() - ($dash['last_update'] ?? 0) > 300) {
        $lockPath = $lockFile . '.' . $PATH_ID;
        if (!file_exists($lockPath)) {
            ?>
            <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:80vh;">
                <div class="spinner"></div>
                <h2 style="margin:20px 0 10px 0; font-weight:600; color:var(--primary-dark);">Discovery in Progress...</h2>
                <p style="color:#64748b;">Mapping new network hops for <b><?= h($dash['name']) ?></b></p>
                <form id='lazyForm' method='post' style='display:none;'><input type='hidden' name='action' value='lazy_rescan'><input type='hidden' name='id' value='<?= h($PATH_ID) ?>'></form>
                <script>setTimeout(() => { document.getElementById('lazyForm').submit(); }, 500);</script>
            </div>
            <?php exit;
        }
    }
    $agentId = (int)$dash['agent_id'];
    $st = $pdo->prepare("SELECT tm.id_agente_modulo, tm.nombre, tm.parent_module_id, te.datos, te.estado FROM tagente_modulo tm JOIN tagente_estado te ON te.id_agente_modulo = tm.id_agente_modulo WHERE tm.id_agente = ? AND tm.nombre LIKE 'RouteStep%'");
    $st->execute([$agentId]); $rows = $st->fetchAll();
    $allNodes = []; $byId = [];
    foreach ($rows as $r) { $name = $r['nombre']; $mid = (int)$r['id_agente_modulo']; $allNodes[$name] = ['id'=>$mid, 'parent'=>$r['parent_module_id'], 'ip'=>labelFromModuleName($name), 'val'=>$r['datos'], 'status'=>(int)$r['estado']]; $byId[$mid] = $name; }
    $dashTargets = [ $dash['target'] ]; foreach ($dash['segments'] as $s) $dashTargets[] = $s['to'];
    $keep = [];
    foreach ($allNodes as $name => $n) {
        $isStep = false; foreach ($dashTargets as $dt) if (strpos($name, $dt) !== false) { $isStep = true; break; }
        if ($isStep) { $curr = $name; $safety = 0; while ($curr && isset($allNodes[$curr]) && $safety++ < 100) { $keep[$curr] = true; $pId = $allNodes[$curr]['parent']; $curr = ($pId && isset($byId[$pId])) ? $byId[$pId] : null; } }
    }
    $nodes = []; $edges = []; foreach ($keep as $name => $_) $nodes[$name] = $allNodes[$name];
    $rootsFound = []; foreach ($nodes as $name => $n) { if (empty($parents[$name]) || (int)$n['parent'] === 0) $rootsFound[] = $name; }
    $sourceNodeName = $rootsFound[0] ?? null;
    foreach($rootsFound as $r) { if(strpos($r, '172.17.8.189') !== false) { $sourceNodeName = $r; break; } }
    foreach ($nodes as $name => $n) {
        $parentId = (int)$n['parent'];
        if ($parentId > 0 && isset($byId[$parentId]) && isset($nodes[$byId[$parentId]])) { $edges[] = [$byId[$parentId], $name]; } 
        else if ($name !== $sourceNodeName && $sourceNodeName) { $edges[] = [$sourceNodeName, $name]; }
    }
    $layout = layoutGraph($nodes, $edges); $pos = $layout['pos'];
    ?>
    <div class="pandora-header-bottom">
        <div><h1 class="page-title"><a href="<?= $baseUrl ?>" style="color:inherit; text-decoration:none;"><span class="material-symbols-outlined">arrow_back</span></a> <?= h($dash['name']) ?></h1></div>
        <div style="display:flex; gap:10px; align-items:center;">
            <div style="display:flex; align-items:center; gap:10px; background:#fff; padding:5px 15px; border-radius:20px; border:1px solid #e2e8f0;"><span style="font-size:11px; font-weight:600; color:#475569;">AUTO REFRESH</span><label class="switch"><input type="checkbox" id="autoRefreshCheck" onchange="toggleAutoRefresh()"><span class="slider"></span></label></div>
            <form method="post" onsubmit="showLoading()"><input type="hidden" name="action" value="rescan"><input type="hidden" name="id" value="<?= h($dash['id']) ?>"><button type="submit" class="btn-secondary-custom"><span class="material-symbols-outlined">sync</span> Rescan</button></form>
            <button class="btn-apply" onclick="document.getElementById('modalAdd').style.display='flex'"><span class="material-symbols-outlined">add</span> Add Branch</button>
        </div>
    </div>
    <div class="topology-container"><div id="graph-area"><svg id="main-svg" style="width:100%; height:100%;"><g id="zoom-group">
        <?php foreach($edges as [$p,$c]): if(!isset($pos[$p],$pos[$c])) continue; $p1=$pos[$p]; $p2=$pos[$c]; $mx=($p1['x']+$p2['x'])/2; $my=($p1['y']+$p2['y'])/2; ?>
            <path id="flow-<?= h($p) ?>-<?= h($c) ?>" class="flow-path" data-p="<?= h($p) ?>" data-c="<?= h($c) ?>" d="M <?= $p1['x'] ?> <?= $p1['y'] ?> C <?= $mx ?> <?= $p1['y'] ?>, <?= $mx ?> <?= $p2['y'] ?>, <?= $p2['x'] ?> <?= $p2['y'] ?>" stroke="var(--accent-green)" stroke-width="3" fill="none" stroke-dasharray="8 8" style="animation: flow 3s linear infinite; opacity: 0.9;"/><foreignObject id="label-<?= h($p) ?>-<?= h($c) ?>" class="edge-label" data-p="<?= h($p) ?>" data-c="<?= h($c) ?>" x="<?= $mx - 40 ?>" y="<?= $my - 16 ?>" width="80" height="32"><div style="background:#fff; border:1px solid #e0e4e8; border-radius:100px; padding:2px 8px; font-size:11px; font-weight:600; color:var(--primary-dark); text-align:center; box-shadow:0 2px 4px rgba(0,0,0,0.05);"><?= round((float)$nodes[$c]['val'], 2) ?>ms</div></foreignObject><?php endforeach; ?>
        <?php foreach($nodes as $name => $n): $p=$pos[$name]; $st=estadoToStatus($n['status']); $ic = strpos($name,'Target')!==false ? 'target' : ($p['x'] < 250 ? 'globe' : 'router'); ?><g id="node-<?= h($name) ?>" class="hop-node" data-name="<?= h($name) ?>" transform="translate(<?= $p['x'] ?>,<?= $p['y'] ?>)" style="cursor:grab;"><circle r="26" fill="<?= statusColor($st) ?>" style="stroke:#fff; stroke-width:4;"/><?= drawIcon($ic) ?><text y="50" text-anchor="middle" style="font-size:11px; font-weight:600; fill:var(--primary-dark); pointer-events:none;"><?= h($n['ip']) ?></text></g><?php endforeach; ?>
    </g></svg></div><div class="sidebar"><div style="padding:25px; border-bottom:1px solid #e0e4e8; background:#f8f9fa;"><span style="font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase;">Node Details</span><h4 id="selIP" style="margin:8px 0 0 0; font-size:18px;">Select Node</h4></div><div style="padding:25px; display:flex; flex-direction:column; gap:20px; flex:1;"><div><span style="font-size:10px; font-weight:600; color:#64748b;">Status</span><div id="selStat" style="font-weight:600; margin-top:5px;">--</div></div><div><span style="font-size:10px; font-weight:600; color:#64748b;">Latency</span><div id="selLast" style="font-weight:600; margin-top:5px; font-size:16px;">--</div></div><div><span style="font-size:10px; font-weight:600; color:#64748b;">Module Name</span><div id="selMod" style="font-size:12px; color:#4a5568; margin-top:5px; word-break:break-all;">--</div></div><div style="margin-top:auto; display:flex; flex-direction:column; gap:10px;"><button id="btnBranch" class="btn-apply" style="width:100%; justify-content:center; display:none;" onclick="document.getElementById('modalAdd').style.display='flex'">+ Add Branch</button><form method="post" id="formDelMod" style="display:none;" onsubmit="return confirm('Delete?');"><input type="hidden" name="action" value="delete_module"><input type="hidden" name="id" value="<?= h($PATH_ID) ?>"><input type="hidden" name="mid" id="selMID"><button type="submit" class="btn-secondary-custom" style="width:100%; justify-content:center; color:#ef4444!important;"><span class="material-symbols-outlined">delete</span> Delete Node</button></form></div></div></div></div>
    <div id="modalAdd" style="position:fixed; inset:0; background:rgba(0,0,0,0.5); display:none; align-items:center; justify-content:center; z-index:2000;"><div style="background:#fff; width:450px; padding:30px; border-radius:8px;"><h3>Add Branch</h3><form method="post" onsubmit="showLoading()"><input type="hidden" name="action" value="add_segment"><input type="hidden" name="id" value="<?= h($PATH_ID) ?>"><div style="margin-bottom:15px;"><label style="font-size:11px; font-weight:600; color:#64748b;">FROM HOP</label><select id="fromHopSelect" name="from_hop" class="form-control-fix" required><?php foreach(array_unique(array_column($nodes,'ip')) as $ip): ?><option value="<?= h($ip) ?>"><?= h($ip) ?></option><?php endforeach; ?></select></div><div style="margin-bottom:20px;"><label style="font-size:11px; font-weight:600; color:#64748b;">TARGET</label><input type="text" name="to_ip" class="form-control-fix" placeholder="1.1.1.1" required></div><div style="display:flex; justify-content:flex-end; gap:10px;"><button type="button" class="btn-secondary-custom" onclick="document.getElementById('modalAdd').style.display='none'">Cancel</button><button type="submit" class="btn-apply">Extend Path</button></div></form></div></div>
    <script>
        let scale = 1, pointX = 0, pointY = 0, start = { x: 0, y: 0 }, isPanning = false;
        let activeNode = null, nodeOffset = { x: 0, y: 0 };
        const nodePositions = <?php echo json_encode($pos); ?>;
        const container = document.getElementById('graph-area'), zoomGroup = document.getElementById('zoom-group');
        const nodeInfo = <?php $ni=[]; foreach($nodes as $name=>$n) $ni[$name]=['ip'=>$n['ip'], 'mod'=>$name, 'ms'=>$n['val'], 'st'=>estadoToStatus($n['status']), 'id'=>$n['id']]; echo json_encode($ni); ?>;
        function setTransform() { if (!zoomGroup) return; zoomGroup.style.transform = `translate(${pointX}px, ${pointY}px) scale(${scale})`; zoomGroup.style.transformOrigin = "0 0"; }
        function updateEdges(nodeName) {
            const edges = document.querySelectorAll(`.flow-path[data-p="${nodeName}"], .flow-path[data-c="${nodeName}"]`);
            edges.forEach(e => {
                const p = e.getAttribute('data-p'), c = e.getAttribute('data-c'), p1 = nodePositions[p], p2 = nodePositions[c];
                if (!p1 || !p2) return; const mx = (p1.x + p2.x) / 2, my = (p1.y + p2.y) / 2;
                e.setAttribute('d', `M ${p1.x} ${p1.y} C ${mx} ${p1.x}, ${mx} ${p2.y}, ${p2.x} ${p2.y}`);
                const label = document.getElementById(`label-${p}-${c}`); if (label) { label.setAttribute('x', mx - 40); label.setAttribute('y', my - 16); }
            });
        }
        container.onmousedown = (e) => {
            const targetNode = e.target.closest('.hop-node');
            if (targetNode) { activeNode = targetNode; const name = activeNode.getAttribute('data-name'), p = nodePositions[name]; nodeOffset = { x: (e.clientX - pointX) / scale - p.x, y: (e.clientY - pointY) / scale - p.y }; activeNode.style.cursor = 'grabbing'; const ni = nodeInfo[name]; selNode(ni.ip, ni.mod, ni.ms, ni.st, ni.id); return; }
            isPanning = true; start = { x: e.clientX - pointX, y: e.clientY - pointY };
        };
        window.onmousemove = (e) => { if (activeNode) { const name = activeNode.getAttribute('data-name'), newX = (e.clientX-pointX)/scale-nodeOffset.x, newY = (e.clientY-pointY)/scale-nodeOffset.y; nodePositions[name] = { x: newX, y: newY }; activeNode.setAttribute('transform', `translate(${newX}, ${newY})`); updateEdges(name); return; } if (isPanning) { pointX = e.clientX - start.x; pointY = e.clientY - start.y; setTransform(); } };
        window.onmouseup = () => { isPanning = false; activeNode = null; };
        container.onwheel = (e) => { e.preventDefault(); let xs = (e.clientX - pointX) / scale, ys = (e.clientY - pointY) / scale, delta = -e.deltaY; (delta > 0) ? (scale *= 1.1) : (scale /= 1.1); pointX = e.clientX - xs * scale; pointY = e.clientY - ys * scale; setTransform(); };
        function zoom(f) { scale *= f; setTransform(); }
        function resetZoom() { scale = 1; pointX = 0; pointY = 0; setTransform(); }
        function selNode(ip, mod, ms, st, mid) { document.getElementById('selIP').innerText=ip; document.getElementById('selMod').innerText=mod; document.getElementById('selLast').innerText=parseFloat(ms).toFixed(2)+' ms'; document.getElementById('selStat').innerText=st.toUpperCase(); document.getElementById('selStat').style.color=(st==='ok'?'#2ecc71':(st==='crit'?'#e74c3c':'#f1c40f')); document.getElementById('btnBranch').style.display='flex'; document.getElementById('formDelMod').style.display='block'; document.getElementById('selMID').value = mid; }
        setTransform();
        if (localStorage.getItem('pfms_netpath_refresh') === 'on') { document.getElementById('autoRefreshCheck').checked = true; toggleAutoRefresh(); }
    </script>
<?php endif; ?>
</body>
</html>
