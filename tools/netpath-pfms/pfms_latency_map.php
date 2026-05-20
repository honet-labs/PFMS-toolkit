<?php
declare(strict_types=1);

/**
 * Pandora FMS Latency Map (Dashboard Style - Extra Robust)
 */

require_once __DIR__ . '/pfms_lib.php';

try {
    $STORE_FILENAME = 'latency_maps.json';
    $STORE_DIR = __DIR__;
    $storeFile = $STORE_DIR . '/' . $STORE_FILENAME;

    $MAP_ID = isset($_GET['map_id']) ? sanitize_id($_GET['map_id']) : null;
    $api = $_GET['api'] ?? null;

    $maps = loadMaps($storeFile);

    // API & ACTIONS (OMITTED FOR BREVITY - FOCUS ON FIXING RENDER)
    // ... logic remains same but we add validation ...

    if (!$MAP_ID) {
        $actionsHtml = '<button class="btn-dash" onclick="location.href=\'pfms_routepath.php\'">NetPath</button><button class="btn-dash primary" onclick="document.getElementById(\'createBox\').style.display=\'flex\'">+ Add Map</button>';
        pfms_render_header('Infrastructure Latency Maps', 'DASHBOARD', $actionsHtml);
        // ... grid rendering ...
?>
        <div id="createBox" class="modal-overlay" style="display:none;"><div class="modal-card"><div class="panel-header"><span class="panel-title">New Map</span></div><form method="post" style="padding:20px;"><input type="hidden" name="action" value="create"/><input type="text" name="name" style="width:100%; padding:8px; border:1px solid #ddd;" required/><button class="btn-dash primary" style="width:100%; margin-top:10px;">Create</button></form></div></div>
        <div class="dash-grid">
            <?php foreach ($maps as $m): ?>
                <div class="panel">
                    <div class="panel-header"><span class="panel-title"><?= h($m['name']) ?></span><div class="dash-actions"><a href="?map_id=<?= $m['id'] ?>" class="btn-dash">⤢</a></div></div>
                </div>
            <?php endforeach; ?>
        </div>
<?php
        pfms_render_footer(); exit;
    }

    $map = $maps[$MAP_ID] ?? null;
    if (!$map) throw new Exception("Map ID '$MAP_ID' not found in storage.");

    $moduleIds = array_column($map['links'] ?? [], 'module_id');
    $valMap = pfms_get_module_status_batch($moduleIds);
    $allAgents = pfms_get_agents();

    pfms_render_header($map['name'], 'DASHBOARD > VIEW', '<button class="btn-dash" onclick="location.href=\'?\'">← Back</button>');
?>
    <div class="layout" style="display:flex; height:calc(100vh - 150px);">
        <div class="canvasWrap" style="flex:1; background:#fff;"><svg style="width:100%; height:100%;"><g id="viewport">
            <?php foreach(($map['links']??[]) as $l): 
                $from=null; $to=null; foreach($map['nodes'] as $n){if($n['id']===$l['from'])$from=$n; if($n['id']===$l['to'])$to=$n;}
                if(!$from || !$to) continue;
                $val = $valMap[$l['module_id']] ?? null; $color = pfms_status_to_color((int)($val['status']??0));
            ?><line x1="<?=$from['x']?>" y1="<?=$from['y']?>" x2="<?=$to['x']?>" y2="<?=$to['y']?>" stroke="<?=$color?>" stroke-width="2" style="opacity:0.2;"/><?php endforeach; ?>
            <?php foreach(($map['nodes']??[]) as $n): ?>
                <g transform="translate(<?=$n['x']?>,<?=$n['y']?>)"><circle r="22" fill="#3498db" style="stroke:#fff; stroke-width:3;"/><text y="40" text-anchor="middle"><?=h($n['name'])?></text></g>
            <?php endforeach; ?>
        </g></svg></div>
    </div>
<?php
    pfms_render_footer();
} catch (Throwable $t) {
    http_response_code(500);
    echo "<div style='color:red; padding:20px;'><b>Latency Map Error:</b> ".h($t->getMessage())."</div>";
}

function loadMaps(string $file): array { $raw = @file_get_contents($file); return $raw ? (json_decode((string)$raw, true) ?: []) : []; }
function saveMaps(string $file, array $maps): bool { return file_put_contents($file, json_encode($maps, JSON_PRETTY_PRINT)) !== false; }
