<?php
/**
 * PANDORA FMS - CUSTOM MODULE INVENTORY DASHBOARD
 * Modernized version with 100% Consistent Typography (Inter).
 */

// 1. CORE UTILS & DB CONNECTION
require_once __DIR__ . '/../../includes/db-connection.php';

// Helper functions with existence check
if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('pretty_text')) {
    function pretty_text($s) {
        $decoded = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
        return str_replace(['&#x20;', '"'], [' ', ''], $decoded);
    }
}

// 2. BREADCRUMB LOGIC (Standardized Style)
$raw_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$relative_path = str_replace('/pandora_console/custom/panel/', '', $raw_path);
$dir_only = dirname($relative_path);
if ($dir_only === '.') $dir_only = '';
$path_array = array_filter(explode('/', $dir_only));
$breadcrumb_parts = ['PANDORA CONSOLE', 'CUSTOM', 'PANEL'];
foreach ($path_array as $p) {
    $breadcrumb_parts[] = strtoupper(str_replace(['_', '-'], ' ', $p));
}
$full_breadcrumb = implode(' / ', $breadcrumb_parts);

// 3. READ FILTERS FROM GET
$agentId      = isset($_GET['agent_id'])      ? trim($_GET['agent_id'])      : '';
$agentAlias   = isset($_GET['agent_alias'])   ? trim($_GET['agent_alias'])   : '';
$agentName    = isset($_GET['agent_name'])    ? trim($_GET['agent_name'])    : '';
$statusText   = isset($_GET['status'])        ? trim($_GET['status'])        : '';
$moduleName   = isset($_GET['module_name'])   ? trim($_GET['module_name'])   : '';
$moduleGroup  = isset($_GET['module_group'])  ? trim($_GET['module_group'])  : '';

// 4. BUILD WHERE & PARAMS
$where  = ["ta.disabled = 0", "tam.disabled = 0"];
$params = [];
if ($agentId !== '') { $where[] = "ta.id_agente = :agent_id"; $params[':agent_id'] = $agentId; }
if ($agentAlias !== '') { $where[] = "ta.alias LIKE :agent_alias"; $params[':agent_alias'] = "%$agentAlias%"; }
if ($agentName !== '') { $where[] = "ta.nombre LIKE :agent_name"; $params[':agent_name'] = "%$agentName%"; }
if ($statusText !== '') {
    $where[] = "CASE tae.estado WHEN 0 THEN 'OK' WHEN 1 THEN 'WARNING' WHEN 2 THEN 'CRITICAL' WHEN 3 THEN 'UNKNOWN' ELSE 'NOT_INIT' END = :status_text";
    $params[':status_text'] = $statusText;
}
if ($moduleName !== '') { $where[] = "tam.nombre LIKE :module_name"; $params[':module_name'] = "%$moduleName%"; }
if ($moduleGroup !== '') {
    $where[] = "(tmg.name LIKE :module_group OR tmg.name LIKE :module_group_enc)";
    $params[':module_group'] = "%$moduleGroup%";
    $params[':module_group_enc'] = '%' . htmlentities($moduleGroup, ENT_QUOTES, 'UTF-8') . '%';
}
$whereSql = implode(" AND ", $where);

// 5. DATABASE QUERY
$modulesData = [];
$is_filtered = (count($params) > 0);
$limit_sql = $is_filtered ? "" : "LIMIT 500"; 
try {
    if (!$db_status) throw new Exception($db_error);
    $sql = "SELECT tam.id_agente_modulo AS module_id, tam.nombre AS module_name, ta.id_agente AS agent_id, ta.nombre AS agent_name, ta.alias AS agent_alias, tg.nombre AS agent_group, tmg.name AS module_group, tam.unit AS module_unit, tae.estado AS status_code, CASE tae.estado WHEN 0 THEN 'OK' WHEN 1 THEN 'WARNING' WHEN 2 THEN 'CRITICAL' WHEN 3 THEN 'UNKNOWN' ELSE 'NOT_INIT' END AS status_text, tae.datos AS last_data, FROM_UNIXTIME(tae.utimestamp) AS last_execution, CONCAT(IFNULL(CONCAT(tam.min_warning, '/', tam.max_warning), 'N/A'), ' - ', IFNULL(CONCAT(tam.min_critical, '/', tam.max_critical), 'N/A')) AS thresholds FROM tagente_modulo AS tam JOIN tagente AS ta ON ta.id_agente = tam.id_agente LEFT JOIN tgrupo AS tg ON tg.id_grupo = ta.id_grupo LEFT JOIN tagente_estado AS tae ON tae.id_agente_modulo = tam.id_agente_modulo LEFT JOIN tmodule_group AS tmg ON tmg.id_mg = tam.id_module_group WHERE $whereSql ORDER BY ta.alias ASC, tam.nombre ASC $limit_sql";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $modulesData = $stmt->fetchAll();
} catch(Exception $e) {
    die("<div style='font-family:Inter, sans-serif; padding:20px; text-align:center; color:#e74c3c;'><b>FATAL ERROR:</b> " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PandoraFMS - Module Inventory</title>
    <link rel="icon" href="/pandora_console/images/pandora.ico" type="image/x-icon">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fontawesome/all.min.css">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/datatables/buttons.bootstrap5.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #334155; font-size: 14px; -webkit-font-smoothing: antialiased; background-color: #f4f6f8; margin: 0; padding: 0; }
        * { box-sizing: border-box; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-size: 18px !important; vertical-align: middle; line-height: 1 !important; }
        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e0e4e8; }
        .breadcrumb-box { display: flex; flex-direction: column; }
        .page-breadcrumb { font-size: 11px !important; color: #7f8c8d !important; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: normal !important; }
        .page-title { font-size: 20px !important; font-weight: 600 !important; color: #0b1a26 !important; margin: 0; }
        .main-content { padding: 25px 30px; }
        .filter-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin-bottom: 20px; }
        .filter-row { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 10px; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-group label { display: block; font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 600; margin-bottom: 4px; }
        .filter-group input, .filter-group select { width: 100%; padding: 7px 10px; border: 1px solid #dce1e5; border-radius: 4px; font-size: 12px; outline: none; background: #fff;}
        .btn-apply { background: #1976d2; color: #fff !important; border: none; padding: 7px 18px; border-radius: 4px; font-weight: 500; cursor: pointer; height: 34px; display: inline-flex; align-items: center; gap: 6px;}
        .btn-reset { background: #fff; color: #4a5568 !important; border: 1px solid #dce1e5; padding: 7px 18px; border-radius: 4px; text-decoration: none; display: inline-flex; align-items: center; height: 34px; font-size: 12px;}
        .dashboard-card { background: #fff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border: 1px solid #f0f3f5; }
        .dashboard-card-body { padding: 20px; overflow-x: auto; }
        table.table-pfms { width: 100% !important; margin: 0 !important; border-collapse: collapse !important;}
        table.table-pfms thead th { background: #fff !important; border-bottom: 2px solid #e0e4e8 !important; text-transform: uppercase; padding: 12px 15px !important; font-size: 11px !important; color: #7f8c8d !important; font-weight: normal !important; white-space: nowrap; }
        table.table-pfms tbody td { padding: 12px 15px !important; border-bottom: 1px solid #f0f3f5; color: #0b1a26 !important; vertical-align: middle; white-space: nowrap; font-size: 14px; }
        .status-pill { padding: 2px 10px; border-radius: 999px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; }
        .status-ok { background: #dcfce7; color: #166534; }
        .status-warning { background: #fef9c3; color: #854d0e; }
        .status-critical { background: #fee2e2; color: #b91c1c; }
        .status-unknown { background: #e5e7eb; color: #374151; }
        .agent-link { color: #1976d2 !important; text-decoration: none; font-weight: normal !important; }
        .agent-link:hover { text-decoration: underline; color: #0d47a1 !important; }
        .text-soft { color: #64748b; font-size: 12px; }
        .dt-buttons .btn { background: #fff !important; color: #4a5568 !important; border: 1px solid #dce1e5 !important; font-size: 11px !important; height: 34px; }
        .dataTables_filter input { padding: 6px 12px 6px 32px !important; border: 1px solid #dce1e5; border-radius: 4px; font-size: 12px; outline: none; width: 220px;}
        .dataTables_filter label { position: relative; font-size: 0; }
        .dataTables_filter label::before { content: "\e8b6"; font-family: 'Material Symbols Outlined'; position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #7f8c8d; font-size: 18px; pointer-events: none; }
    </style>
</head>
<body>
<div class="pandora-header-bottom">
    <div class="breadcrumb-box">
        <span class="page-breadcrumb"><?= h($full_breadcrumb) ?></span>
        <h1 class="page-title">Module Inventory</h1>
    </div>
</div>
<div class="main-content">
    <form method="get" class="filter-box shadow-sm">
        <input type="hidden" name="page" value="<?= h($_GET['page'] ?? '') ?>">
        <div class="filter-row">
            <div class="filter-group"><label>Agent ID</label><input type="text" name="agent_id" value="<?= h($agentId) ?>"></div>
            <div class="filter-group"><label>Agent Alias</label><input type="text" name="agent_alias" value="<?= h($agentAlias) ?>"></div>
            <div class="filter-group"><label>Agent Name</label><input type="text" name="agent_name" value="<?= h($agentName) ?>"></div>
            <div class="filter-group" style="max-width: 120px;"><label>Status</label><select name="status"><option value="">Any</option><?php foreach (['OK','WARNING','CRITICAL','UNKNOWN'] as $st): ?><option value="<?= $st ?>" <?= $statusText === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="filter-row">
            <div class="filter-group"><label>Module Name</label><input type="text" name="module_name" value="<?= h($moduleName) ?>"></div>
            <div class="filter-group"><label>Module Group</label><input type="text" name="module_group" value="<?= h($moduleGroup) ?>"></div>
            <div style="display: flex; align-items: flex-end; gap: 8px;">
                <button type="submit" class="btn-apply"><span class="material-symbols-outlined" style="font-size: 16px!important;">filter_list</span> Apply Filter</button>
                <a href="?page=<?= h($_GET['page'] ?? '') ?>" class="btn-reset">Reset</a>
            </div>
        </div>
    </form>
    <div class="dashboard-card"><div class="dashboard-card-body"><table id="moduleTable" class="table-pfms"><thead><tr><th>ID</th><th>Agent Name / Alias</th><th>Module Name</th><th>Module Group</th><th class="text-center">Status</th><th>Last Data</th><th>Thresholds (W/C)</th><th>Last Execution</th><th>Agent Group</th></tr></thead><tbody><?php foreach ($modulesData as $row): $st = strtoupper($row['status_text']); $stClass = match($st) { 'OK' => 'status-ok', 'WARNING' => 'status-warning', 'CRITICAL' => 'status-critical', default => 'status-unknown' }; $alias = pretty_text($row['agent_alias']); $mname = pretty_text($row['module_name']); $ldata = pretty_text($row['last_data']); ?><tr><td class="text-soft"><?= $row['module_id'] ?></td><td><a href="/pandora_console/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=<?= $row['agent_id'] ?>" target="_blank" class="agent-link"><?= h($alias) ?></a><br><span class="text-soft" style="font-size:10px;"><?= h($row['agent_name']) ?></span></td><td><span style="font-weight: 500; color: #1e293b;"><?= h($mname) ?></span></td><td><span class="text-soft"><?= h(pretty_text($row['module_group'] ?: 'General')) ?></span></td><td class="text-center"><span class="status-pill <?= $stClass ?>"><?= $row['status_text'] ?></span></td><td><span style="font-weight: 500; color: #0b1a26;"><?= h($ldata) ?></span> <span class="text-soft"><?= h($row['module_unit']) ?></span></td><td class="text-soft"><?= h($row['thresholds']) ?></td><td class="text-soft"><?= h($row['last_execution']) ?></td><td class="text-soft" style="font-size: 12px;"><?= h(pretty_text($row['agent_group'])) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/jquery/jquery-3.7.0.min.js"></script>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/datatables/jquery.dataTables.min.js"></script>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/datatables/dataTables.bootstrap5.min.js"></script>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/datatables/dataTables.buttons.min.js"></script>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/datatables/buttons.bootstrap5.min.js"></script>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/jszip/jszip.min.js"></script>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/pdfmake/pdfmake.min.js"></script>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/pdfmake/vfs_fonts.js"></script>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/datatables/buttons.html5.min.js"></script>
<script>
$(document).ready(function() {
    $('#moduleTable').DataTable({
        "lengthMenu": [[15, 30, 50, 100, -1], [15, 30, 50, 100, "All"]],
        "pageLength": 30,
        "dom": "<'row mb-3 align-items-center'<'col-sm-12 col-md-6'B><'col-sm-12 col-md-6 text-end'f>>" + "<'row'<'col-sm-12'tr>>" + "<'row mt-3 align-items-center'<'col-sm-12 col-md-4'l><'col-sm-12 col-md-4 text-center'i><'col-sm-12 col-md-4'p>>",
        "language": { "search": "", "searchPlaceholder": "Search table data..." },
        "buttons": [
            { extend: 'csvHtml5', text: '<span class="material-symbols-outlined" style="font-size:16px!important;">download</span> CSV', className: 'btn-secondary-custom', title: 'Module_Inventory_' + new Date().toISOString().slice(0,10) },
            { extend: 'pdfHtml5', text: '<span class="material-symbols-outlined" style="font-size:16px!important;">picture_as_pdf</span> PDF', className: 'btn-secondary-custom', orientation: 'landscape', pageSize: 'A4' }
        ]
    });
});
</script>
</body>
</html>