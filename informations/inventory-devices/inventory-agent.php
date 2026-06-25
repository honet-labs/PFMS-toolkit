<?php
/**
 * PANDORA FMS - CUSTOM INVENTORY DEVICE DASHBOARD
 * Version: 7.0 (EXCLUDE FIELDS SUPPORT)
 */

require_once __DIR__ . '/../../includes/db-connection.php';

ini_set('display_errors', 0);
error_reporting(E_ALL);

$full_breadcrumb = "PANDORA CONSOLE / CUSTOM / PANEL / INFORMATIONS / INVENTORY AGENT";

// =====================================================================
// 2. CONFIG & SETTINGS
// =====================================================================
$SETTINGS_FILE = __DIR__ . '/inventory-settings.json';
$current_settings = [ 
    'dynamic_columns' => [],
    'excluded_cf' => [] // List of id_field to hide
];

if (file_exists($SETTINGS_FILE)) {
    $raw_json = file_get_contents($SETTINGS_FILE);
    $decoded = json_decode($raw_json, true);
    if ($decoded) {
        if (isset($decoded['dynamic_columns'])) $current_settings['dynamic_columns'] = $decoded['dynamic_columns'];
        if (isset($decoded['excluded_cf'])) $current_settings['excluded_cf'] = $decoded['excluded_cf'];
    }
}

// AJAX API
$api = isset($_GET['api']) ? $_GET['api'] : '';
if ($api === 'save_settings') {
    ob_clean(); header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['dynamic_columns']) || isset($input['excluded_cf'])) {
        if (isset($input['dynamic_columns'])) $current_settings['dynamic_columns'] = $input['dynamic_columns'];
        if (isset($input['excluded_cf'])) $current_settings['excluded_cf'] = $input['excluded_cf'];
        
        @file_put_contents($SETTINGS_FILE, json_encode($current_settings, JSON_PRETTY_PRINT));
        echo json_encode(['ok' => true]);
    } else { echo json_encode(['ok' => false]); }
    exit;
}

// Special API to get ALL available custom fields for the settings UI
if ($api === 'get_all_cf') {
    ob_clean(); header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT id_field, name FROM tagent_custom_fields ORDER BY name ASC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($api === 'load_settings') {
    ob_clean(); header('Content-Type: application/json');
    echo json_encode($current_settings);
    exit;
}

// =====================================================================
// 3. DATABASE ENGINE
// =====================================================================
$inventoryData = [];
$agentModuleMap = []; 
$agentCustomData = []; 
$displayCustomFields = []; 
$db_error = null;

try {
    if (!isset($pdo)) throw new Exception("DB Error");

    // A. Fetch Custom Fields (Filtered by Exclusion List)
    $primaryCF = [];
    $stmtAllCF = $pdo->query("SELECT id_field, name FROM tagent_custom_fields ORDER BY name ASC");
    if ($stmtAllCF) {
        $primaryCF = $stmtAllCF->fetchAll(PDO::FETCH_ASSOC);
        foreach ($primaryCF as $cf) {
            // Only add to display list if NOT in excluded list
            if (!in_array($cf['id_field'], $current_settings['excluded_cf'])) {
                $displayCustomFields[] = $cf;
            }
        }
    }

    // B. Query primary and custom PDOs
    global $custom_pdos, $custom_connections;
    $target_nodes = ['primary' => $pdo];
    if (!empty($custom_pdos)) {
        foreach ($custom_pdos as $cid => $cpdo) {
            $target_nodes[$cid] = $cpdo;
        }
    }

    foreach ($target_nodes as $node => $active_pdo) {
        if ($active_pdo === null) continue;

        $node_label = '';
        if ($node !== 'primary') {
            foreach ($custom_connections as $cc) {
                if ($cc['id'] === $node) { $node_label = '[' . $cc['name'] . '] '; break; }
            }
            if (empty($node_label)) $node_label = '[' . $node . '] ';
        }

        // B. Detect Column Schemas
        $ipCol = 'direccion';
        try {
            $checkIp = $active_pdo->query("SHOW COLUMNS FROM tagente LIKE 'ip_address'");
            if ($checkIp && $checkIp->rowCount() > 0) $ipCol = 'ip_address';
        } catch (Throwable $e) {}

        $dataCol = 'value';
        try {
            $checkData = $active_pdo->query("SHOW COLUMNS FROM tagent_custom_data LIKE 'description'");
            if ($checkData && $checkData->rowCount() > 0) $dataCol = 'description';
        } catch (Throwable $e) {}

        // Fetch local custom field ID mapping for this node
        $localCFMap = []; // name -> local id_field
        try {
            $stmtLocalCF = $active_pdo->query("SELECT id_field, name FROM tagent_custom_fields");
            if ($stmtLocalCF) {
                while ($cfRow = $stmtLocalCF->fetch(PDO::FETCH_ASSOC)) {
                    $localCFMap[$cfRow['name']] = $cfRow['id_field'];
                }
            }
        } catch (Throwable $e) {}

        // C. Fetch Custom Data for Displayed Fields
        if (!empty($displayCustomFields)) {
            $localFieldIds = [];
            $fieldIdMapping = []; // local_id -> primary_id
            foreach ($displayCustomFields as $cf) {
                $cfName = $cf['name'];
                if (isset($localCFMap[$cfName])) {
                    $localId = $localCFMap[$cfName];
                    $localFieldIds[] = $localId;
                    $fieldIdMapping[$localId] = $cf['id_field'];
                }
            }

            if (!empty($localFieldIds)) {
                $displayedIdsStr = implode(',', $localFieldIds);
                try {
                    $stmtCD = $active_pdo->query("SELECT id_agent, id_field, $dataCol as val FROM tagent_custom_data WHERE id_field IN ($displayedIdsStr)");
                    if ($stmtCD) {
                        while ($row = $stmtCD->fetch(PDO::FETCH_ASSOC)) {
                            $prefixedAgentId = $node . ':' . $row['id_agent'];
                            $primaryFieldId = $fieldIdMapping[$row['id_field']] ?? null;
                            if ($primaryFieldId !== null) {
                                $agentCustomData[$prefixedAgentId][$primaryFieldId] = $row['val'];
                            }
                        }
                    }
                } catch (Throwable $e) {}
            }
        }

        // D. Fetch Main Agent List
        $nodeAgents = [];
        try {
            $sqlA = "SELECT a.id_agente, a.alias, a.$ipCol as ip, g.nombre as group_name, os.name as os, 
                            os.icon_name as icon, a.ultimo_contacto
                     FROM tagente a
                     LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                     LEFT JOIN tconfig_os os ON a.id_os = os.id_os
                     WHERE a.disabled = 0";
            $stmtA = $active_pdo->query($sqlA);
            if ($stmtA) {
                while ($row = $stmtA->fetch(PDO::FETCH_ASSOC)) {
                    $prefixedAgentId = $node . ':' . $row['id_agente'];
                    $row['id_agente'] = $prefixedAgentId;
                    $row['alias'] = $node_label . pretty_text($row['alias']);
                    $nodeAgents[] = $row;
                }
            }
        } catch (Throwable $e) {}

        // Merge this node's agents into inventoryData
        $inventoryData = array_merge($inventoryData, $nodeAgents);

        // E. Bulk Fetch Module Data
        if (!empty($nodeAgents) && !empty($current_settings['dynamic_columns'])) {
            $localAgentIds = [];
            foreach ($nodeAgents as $na) {
                $parsed = parse_node_id($na['id_agente']);
                $localAgentIds[] = $parsed['id'];
            }
            $idString = implode(',', array_map('intval', $localAgentIds));
            
            $allKeywords = [];
            foreach ($current_settings['dynamic_columns'] as $col) {
                foreach (($col['keywords'] ?? []) as $kw) { if(trim($kw)!=='') $allKeywords[] = trim($kw); }
            }
            $allKeywords = array_unique($allKeywords);

            if (!empty($allKeywords)) {
                $kwList = implode("','", array_map(function($k){ return str_replace("'","''",$k); }, $allKeywords));
                $sqlM = "SELECT tm.id_agente, tm.nombre, e.datos 
                         FROM tagente_modulo tm 
                         INNER JOIN tagente_estado e ON tm.id_agente_modulo = e.id_agente_modulo 
                         WHERE tm.id_agente IN ($idString) 
                         AND (tm.nombre IN ('$kwList') OR " . 
                         implode(" OR ", array_map(function($k){ return "tm.nombre LIKE '%" . str_replace("'","''",$k) . "%'"; }, $allKeywords)) . ")";
                try {
                    $stmtM = $active_pdo->query($sqlM);
                    if ($stmtM) {
                        while ($row = $stmtM->fetch(PDO::FETCH_ASSOC)) {
                            $prefixedAgentId = $node . ':' . $row['id_agente'];
                            $agentModuleMap[$prefixedAgentId][$row['nombre']] = $row['datos'];
                        }
                    }
                } catch (Throwable $e) {}
            }
        }
    }

    // Sort aggregated agents by alias
    usort($inventoryData, function($a, $b) {
        return strcasecmp($a['alias'], $b['alias']);
    });

} catch (Exception $e) { $db_error = $e->getMessage(); }

// HELPERS
function cleanText($txt) { 
    if (empty($txt)) return "-";
    return htmlspecialchars(html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8')); 
}
function getAgo($ts) {
    if (!$ts) return "Never";
    $d = time() - (is_numeric($ts) ? $ts : strtotime($ts));
    if ($d < 60) return "Just now";
    if ($d < 3600) return round($d/60)."m ago";
    return round($d/3600)."h ago";
}
function formatUp($val, $type) {
    if (!is_numeric($val)) return $val;
    $s = ($type === 'uptime_ticks') ? floor($val/100) : floor($val);
    $days = floor($s/86400); $hours = floor(($s%86400)/3600); $mins = floor(($s%3600)/60);
    $p = []; if ($days > 0) $p[] = $days."d"; if ($hours > 0) $p[] = $hours."h"; if ($mins > 0) $p[] = $mins."m";
    return count($p) ? implode(" ", $p) : ($s."s");
}
function findModuleData($aid, $col, $map) {
    if (!isset($map[$aid])) return '-';
    $keywords = $col['keywords'] ?? [];
    foreach ($keywords as $kw) {
        $kw = trim($kw);
        if (($col['match_type'] ?? '') === 'exact') { if (isset($map[$aid][$kw])) return $map[$aid][$kw]; } 
        else { foreach ($map[$aid] as $modName => $data) { if (stripos($modName, $kw) !== false) return $data; } }
    }
    return '-';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Agent - Filtered</title>
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/datatables/buttons.bootstrap5.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6f8; color: #334155; font-size: 13px; margin: 0; }
        .header-box { background: #f4f6f8; padding: 15px 30px; border-bottom: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; }
        .page-title { font-size: 20px; font-weight: 600; color: #0b1a26; margin: 0; }
        .main-content { padding: 25px 30px; }
        .dashboard-card { background: #fff; border-radius: 8px; border: 1px solid #f0f3f5; box-shadow: 0 2px 8px rgba(0,0,0,0.04); padding: 20px; overflow-x: auto; }
        
        table.table-pfms thead th { background: #fff; border-bottom: 2px solid #e0e4e8; color: #94a3b8; font-size: 10px; text-transform: uppercase; padding: 12px 15px; }
        table.table-pfms tbody td { padding: 12px 15px; border-bottom: 1px solid #f0f3f5; vertical-align: middle; }
        .custom-col-head { background: #ecfdf5 !important; color: #059669 !important; font-weight: 600; }
        .agent-link { color: #1976d2; text-decoration: none; font-weight: 600; }
        .btn-custom { background: #fff; border: 1px solid #dce1e5; color: #4a5568; padding: 5px 12px; border-radius: 4px; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; }
        
        /* Settings Modal Styling */
        .settings-tab { border-bottom: 1px solid #dee2e6; margin-bottom: 15px; }
        .settings-tab button { background: none; border: none; padding: 10px 20px; color: #64748b; font-weight: 500; cursor: pointer; }
        .settings-tab button.active { color: #0b1a26; border-bottom: 2px solid #0b1a26; }
        .cf-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; padding: 10px; }
        .cf-item { background: #f8fafc; padding: 8px 12px; border-radius: 4px; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .cf-item:hover { background: #f1f5f9; }
    </style>
</head>
<body>

<div class="header-box">
    <div>
        <div style="font-size:10px; color:#94a3b8; text-transform:uppercase;"><?php echo $full_breadcrumb; ?></div>
        <h1 class="page-title">Inventory Agent</h1>
    </div>
    <button class="btn-custom" onclick="openSettingsModal()"><span class="material-symbols-outlined" style="font-size:16px;">settings</span> Dashboard Settings</button>
</div>

<div class="main-content">
    <div class="dashboard-card">
        <table id="inventoryTable" class="table table-pfms">
            <thead>
                <tr>
                    <th>Alias</th>
                    <th>IP Address</th>
                    <th>Group</th>
                    <?php foreach ($displayCustomFields as $cf): ?>
                        <th class="custom-col-head"><?php echo htmlspecialchars($cf['name']); ?></th>
                    <?php endforeach; ?>
                    <?php foreach ($current_settings['dynamic_columns'] as $col): ?>
                        <th><?php echo htmlspecialchars($col['title']); ?></th>
                    <?php endforeach; ?>
                    <th>OS</th>
                    <th>Contact</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inventoryData as $row): $id = $row['id_agente']; ?>
                <tr>
                    <td>
                        <?php 
                        $parsed = parse_node_id($id);
                        if ($parsed['node'] === 'primary'): ?>
                            <a href="/pandora_console/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=<?php echo $parsed['id']; ?>" target="_blank" class="agent-link"><?php echo cleanText($row['alias']); ?></a>
                        <?php else: ?>
                            <span class="agent-link-text" style="font-weight:600; color:#334155;"><?php echo cleanText($row['alias']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="font-monospace text-muted"><?php echo htmlspecialchars($row['ip']); ?></td>
                    <td style="color:#64748b;"><?php echo cleanText($row['group_name']); ?></td>
                    
                    <?php foreach ($displayCustomFields as $cf): $val = isset($agentCustomData[$id][$cf['id_field']]) ? $agentCustomData[$id][$cf['id_field']] : '-'; ?>
                        <td style="color:#059669; font-weight:600;"><?php echo htmlspecialchars($val); ?></td>
                    <?php endforeach; ?>

                    <?php foreach ($current_settings['dynamic_columns'] as $col): 
                        $val = findModuleData($id, $col, $agentModuleMap);
                        if ($val !== '-' && in_array($col['format'] ?? '', ['uptime_ticks', 'uptime_sec'])) $val = formatUp($val, $col['format']);
                    ?>
                        <td><?php echo htmlspecialchars($val); ?></td>
                    <?php endforeach; ?>

                    <td><?php echo htmlspecialchars($row['os']); ?></td>
                    <td><?php echo getAgo($row['ultimo_contacto']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SETTINGS MODAL -->
<div class="modal fade" id="settingsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5>Dashboard Settings</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="settings-tab">
                    <button id="tabModules" class="active" onclick="showTab('modules')">Dynamic Columns</button>
                    <button id="tabFields" onclick="showTab('fields')">Custom Fields Visibility</button>
                </div>

                <!-- Tab 1: Module Columns -->
                <div id="sectionModules">
                    <div id="builderContainer"></div>
                    <button class="btn btn-sm btn-outline-primary mt-2" onclick="addColumn()">+ Add Module Column</button>
                </div>

                <!-- Tab 2: Custom Fields Filter -->
                <div id="sectionFields" style="display:none;">
                    <p class="text-muted small mb-3">Uncheck fields you want to hide from the dashboard.</p>
                    <div id="cfFilterGrid" class="cf-grid">
                        <!-- Rendered via JS -->
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-sm btn-primary" onclick="saveSettings()">Save & Reload</button></div>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/jquery/jquery-3.7.0.min.js"></script>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/datatables/jquery.dataTables.min.js"></script>
<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/datatables/dataTables.bootstrap5.min.js"></script>

<script>
    let currentCols = [];
    let excludedCF = [];
    let allCF = [];

    $(document).ready(function() { $('#inventoryTable').DataTable({ pageLength: 30 }); });

    function showTab(tab) {
        $('.settings-tab button').removeClass('active');
        $('#sectionModules, #sectionFields').hide();
        if (tab === 'modules') { $('#tabModules').addClass('active'); $('#sectionModules').show(); }
        else { $('#tabFields').addClass('active'); $('#sectionFields').show(); }
    }

    async function openSettingsModal() {
        const res = await fetch('?api=load_settings');
        const settings = await res.json();
        currentCols = settings.dynamic_columns || [];
        excludedCF = settings.excluded_cf || [];

        const cfRes = await fetch('?api=get_all_cf');
        allCF = await cfRes.json();

        renderBuilder();
        renderCFFilter();
        new bootstrap.Modal('#settingsModal').show();
    }

    function renderBuilder() {
        const container = document.getElementById('builderContainer');
        container.innerHTML = currentCols.map((col, i) => `
            <div class="border p-2 mb-2 rounded bg-light">
                <div class="row g-2">
                    <div class="col-4"><input type="text" class="form-control form-control-sm" placeholder="Title" value="${col.title}" onchange="currentCols[${i}].title=this.value"></div>
                    <div class="col-6"><input type="text" class="form-control form-control-sm" placeholder="Keywords" value="${col.keywords.join(',')}" onchange="currentCols[${i}].keywords=this.value.split(',')"></div>
                    <div class="col-2 text-end"><button class="btn btn-sm btn-danger" onclick="currentCols.splice(${i},1);renderBuilder()">X</button></div>
                </div>
            </div>
        `).join('');
    }

    function renderCFFilter() {
        const container = document.getElementById('cfFilterGrid');
        container.innerHTML = allCF.map(cf => {
            const checked = !excludedCF.includes(cf.id_field) ? 'checked' : '';
            return `
                <div class="cf-item" onclick="toggleCF(${cf.id_field})">
                    <input type="checkbox" id="cf_${cf.id_field}" ${checked}>
                    <label class="mb-0 ms-1" style="cursor:pointer;">${cf.name}</label>
                </div>
            `;
        }).join('');
    }

    function toggleCF(id) {
        id = parseInt(id);
        if (excludedCF.includes(id)) excludedCF = excludedCF.filter(x => x !== id);
        else excludedCF.push(id);
        renderCFFilter();
    }

    function addColumn() { currentCols.push({ id: 'col_'+Date.now(), title: '', keywords: [], format: 'raw' }); renderBuilder(); }

    function saveSettings() {
        fetch('?api=save_settings', {
            method: 'POST',
            body: JSON.stringify({ dynamic_columns: currentCols, excluded_cf: excludedCF })
        }).then(() => location.reload());
    }
</script>
</body>
</html>
