<?php
declare(strict_types=1);

/**
 * Pandora FMS Shared Library (NetPath Engine Edition)
 * Includes original layout and graph logic for NetPath tools
 */

if (ob_get_level() === 0) ob_start();

try {
    $possible_paths = [
        __DIR__ . '/../../includes/db-connection.php',
        dirname(__DIR__, 2) . '/includes/db-connection.php',
        $_SERVER['DOCUMENT_ROOT'] . '/pandora_console/includes/db-connection.php'
    ];
    $db_path = null;
    foreach ($possible_paths as $p) { if (file_exists($p)) { $db_path = $p; break; } }
    if ($db_path) require_once $db_path;
    else throw new Exception("db-connection.php not found.");
} catch (Throwable $t) {
    http_response_code(500);
    die("<h1>500 Internal Server Error</h1><p>Library Initialization Failed: " . $t->getMessage() . "</p>");
}

function get_db_conn(): PDO {
    global $pdo, $db_status;
    if (!isset($pdo) || !$db_status) throw new Exception("Database Connection is not active.");
    return $pdo;
}

if (!function_exists('h')) {
    function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

function sanitize_id(string $id): string { return preg_replace('/[^a-zA-Z0-9_\-]/', '', $id); }

// ---------- DATA WRAPPERS ----------
function pfms_get_agents(): array {
    try { return get_db_conn()->query("SELECT id_agente, nombre FROM tagente WHERE disabled = 0 ORDER BY nombre")->fetchAll(); }
    catch (Throwable $t) { return []; }
}

function pfms_get_module_status_batch(array $moduleIds): array {
    if (empty($moduleIds)) return [];
    try {
        $ids = array_unique(array_map('intval', $moduleIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = get_db_conn()->prepare("SELECT id_agente_modulo, datos, estado, utimestamp FROM tagente_estado WHERE id_agente_modulo IN ($placeholders)");
        $st->execute($ids);
        $results = [];
        while ($row = $st->fetch()) {
            $results[(int)$row['id_agente_modulo']] = ['val' => $row['datos'], 'status' => (int)$row['estado'], 'ts' => $row['utimestamp']];
        }
        return $results;
    } catch (Throwable $t) { return []; }
}

function pfms_status_to_color(int $status): string {
    return match ($status) { 0 => '#2ecc71', 1 => '#e74c3c', 2 => '#f1c40f', default => '#95a5a6' };
}

function pfms_json_response(array $data, int $code = 200): void {
    if (ob_get_level()) ob_clean();
    header('Content-Type: application/json');
    http_response_code($code); echo json_encode($data); exit;
}

// ---------- GRAPH ENGINE (ORIGINAL) ----------
function pfms_layout_graph(array $nodes, array $edges): array {
    $children = []; $parents = [];
    foreach ($edges as [$p, $c]) { $children[$p][] = $c; $parents[$c][] = $p; }
    
    $roots = [];
    foreach ($nodes as $name => $n) {
        $hasParent = !empty($n['parent']) && isset($nodes[$n['parent']]);
        if (($n['unlink'] ?? false) || !$hasParent) $roots[$name] = true;
    }
    $rootList = array_keys($roots); sort($rootList);

    $depth = []; $q = [];
    foreach ($rootList as $r) { $depth[$r] = 0; $q[] = $r; }
    for ($i=0; $i<count($q); $i++) {
        $u = $q[$i]; $d = $depth[$u] ?? 0;
        foreach (($children[$u] ?? []) as $v) {
            if (!isset($depth[$v]) || $depth[$v] > $d + 1) { $depth[$v] = $d + 1; $q[] = $v; }
        }
    }
    foreach ($nodes as $name => $_) if (!isset($depth[$name])) $depth[$name] = 0;

    $y = []; $visited = []; $currentY = 160.0; $Y_SPACING = 160.0;
    $dfs = function($u) use (&$dfs, &$children, &$y, &$visited, &$currentY, $Y_SPACING) {
        if (isset($visited[$u])) return;
        $visited[$u] = true;
        $chs = $children[$u] ?? [];
        if (!$chs) { $y[$u] = $currentY; $currentY += $Y_SPACING; return; }
        foreach ($chs as $v) $dfs($v);
        $sum = 0.0; $cnt = 0;
        foreach ($chs as $v) { if (isset($y[$v])) { $sum += $y[$v]; $cnt++; } }
        $y[$u] = ($cnt > 0) ? ($sum / $cnt) : $currentY;
        if ($cnt === 0) $currentY += $Y_SPACING;
    };
    foreach ($rootList as $r) $dfs($r);
    foreach (array_keys($nodes) as $n) if (!isset($y[$n])) $dfs($n);

    $pos = []; $maxDepth = 0; $minY = 1e9; $maxY = -1e9;
    foreach ($nodes as $name => $_) {
        $d = $depth[$name] ?? 0; $maxDepth = max($maxDepth, $d);
        $xx = 160.0 + $d * 220.0; $yy = $y[$name] ?? 200.0;
        $minY = min($minY, $yy); $maxY = max($maxY, $yy);
        $pos[$name] = ['x'=>$xx, 'y'=>$yy];
    }
    
    $svgW = (int)max(1100, 160.0 + ($maxDepth + 1) * 220.0 + 220);
    $svgH = (int)max(560, ($maxY - $minY) + 300);
    return ['pos'=>$pos, 'svgW'=>$svgW, 'svgH'=>$svgH];
}

function pfms_draw_icon(string $type, float $cx, float $cy): string {
    $markup = match($type) {
        'globe' => '<circle cx="12" cy="12" r="8.5" fill="none"/><path d="M3.5 12h17M12 3.5c3 3.2 3 13.8 0 17M12 3.5c-3 3.2-3 13.8 0 17"/>',
        'server' => '<rect x="5.5" y="4.5" width="13" height="6" rx="2" fill="none"/><rect x="5.5" y="13.5" width="13" height="6" rx="2" fill="none"/><path d="M8 7.5h.01M8 16.5h.01M11.5 7.5h6M11.5 16.5h6"/>',
        'target' => '<circle cx="12" cy="12" r="7.8" fill="none"/><circle cx="12" cy="12" r="3.2" fill="none"/><path d="M12 4V2.5M20 12h1.5"/>',
        default => '<path d="M5 15.5c4.8-4.2 9.2-4.2 14 0M7.7 12.7c3-2.6 5.6-2.6 8.6 0M10.3 10.1c1.4-1.1 2-1.1 3.4 0M7 18h10"/><circle cx="8.2" cy="20" r="0.9"/><circle cx="12" cy="20" r="0.9"/><circle cx="15.8" cy="20" r="0.9"/>'
    };
    return '<g transform="translate('.($cx-12).','.($cy-12).')" stroke="white" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">'.$markup.'</g>';
}

// ---------- UI RENDERING ----------
function pfms_render_header(string $title, string $breadcrumb, string $actionsHtml = ''): void {
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= h($title) ?> | Pandora FMS</title>
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="pfms_routepath.css?v=<?= time() ?>">
</head>
<body style="margin:0; background:#f4f7f9; font-family:Inter, sans-serif; overflow-x:hidden;">
    <div class="header-top-bar" style="background:#fff; border-bottom:1px solid #e0e6ed; padding:10px 20px; display:flex; align-items:center; position:sticky; top:0; z-index:1000;">
        <div style="font-weight:900; font-size:18px; color:#111;">PANDORA <span style="font-weight:300; color:#76838f;">FMS</span></div>
        <div style="width:1px; height:20px; background:#e0e6ed; margin:0 15px;"></div>
        <div style="font-size:12px; color:#76838f; font-weight:600;">PFMS-Toolkit</div>
    </div>
    <div class="dash-header" style="padding:20px; display:flex; justify-content:space-between; align-items:flex-end;">
        <div><div style="font-size:10px; color:#76838f; font-weight:700; text-transform:uppercase; margin-bottom:5px;"><?= h($breadcrumb) ?></div>
        <h1 style="margin:0; font-size:24px; font-weight:700; color:#111;"><?= h($title) ?></h1></div>
        <div class="dash-actions"><?= $actionsHtml ?></div>
    </div>
<?php
}
function pfms_render_footer(): void { echo '</body></html>'; }
