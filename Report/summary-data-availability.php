<?php
/* summary_poweroff_ct.php ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€¦Ã‚Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â PowerState OFF Summary
 *
 * - Version: 3.1 (STABLE: Strict TXT Layout & ZIP Multiple Files Export)
 * - Support: Dual DB (Active+History), Dual Table (String+Numeric), TXT/ZIP/CSV Export
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// Bypass resource limits for heavy queries
ini_set('memory_limit', '512M');
set_time_limit(300);

// =====================================================================
// 1. DYNAMIC BREADCRUMB
// =====================================================================
$raw_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); 
$dir_only = dirname($raw_path); 
$clean_path = trim($dir_only, '/'); 
$path_array = explode('/', $clean_path);
$formatted_array = array_map(function($p) { return ucwords(str_replace(['_', '-'], ' ', $p)); }, $path_array);
$dynamic_breadcrumb = implode(' / ', $formatted_array);

// =====================================================================
// 2. CONFIG LOADING (DYNAMIC ACTIVE DB & STATIC HISTORY)
// =====================================================================
$PANDORA_BASE_URL = "/pandora_console";
require_once __DIR__ . '/../includes/db-connection.php';

$pdoActive = $pdo;
$pdoHistory = $pdo_history;
$db_status = ($pdo !== null);

global $custom_pdos, $custom_connections;
$target_nodes = ['primary' => $pdo];
if (!empty($custom_pdos)) {
    foreach ($custom_pdos as $cid => $cpdo) {
        $target_nodes[$cid] = $cpdo;
    }
}

function get_node_label($node) {
    global $custom_connections;
    if ($node === 'primary') return '';
    foreach ($custom_connections as $cc) {
        if ($cc['id'] === $node) { return '[' . $cc['name'] . '] '; }
    }
    return '[' . $node . '] ';
}

function parse_node_from_filter($filter) {
    global $custom_connections;
    $filter = trim($filter);
    if (preg_match('/^\[(.*?)\]\s*(.*)$/', $filter, $matches)) {
        $node_name = $matches[1];
        $search_term = $matches[2];
        if (strcasecmp($node_name, 'primary') === 0) {
            return ['node' => 'primary', 'search' => $search_term];
        }
        foreach ($custom_connections as $cc) {
            if (strcasecmp($cc['name'], $node_name) === 0 || strcasecmp($cc['id'], $node_name) === 0) {
                return ['node' => $cc['id'], 'search' => $search_term];
            }
        }
    }
    return ['node' => null, 'search' => $filter];
}

function ts_to_local_long($ts, $tz) {
    if (!$ts) return "-";
    $dt = new DateTime('@'.(int)$ts);
    $dt->setTimezone(new DateTimeZone($tz));
    return $dt->format('Y-m-d H:i:s');
}

function ts_to_local_human($ts, $tz) {
    if (!$ts) return "-";
    $dt = new DateTime('@'.(int)$ts);
    $dt->setTimezone(new DateTimeZone($tz));
    return $dt->format('F j, Y, g:i a');
}

function fmt_dur($sec){
    $sec = (int)$sec; if ($sec < 0) $sec = 0;
    $d = intdiv($sec,86400); $sec%=86400;
    $h = intdiv($sec,3600);  $sec%=3600;
    $m = intdiv($sec,60);    $s=$sec%60;
    $parts = [];
    if ($d) $parts[] = $d.' Days';
    if ($h || $d) $parts[] = $h.' Hours';
    if ($m || $h || $d) $parts[] = $m.' Minutes';
    $parts[] = $s.' Seconds';
    return implode(' ', $parts);
}

function map_dynamic_state($raw, $arr_up, $arr_off) {
    $v = strtolower(trim((string)$raw));
    foreach ($arr_off as $off_kw) {
        if (strpos($v, $off_kw) !== false || $v === $off_kw) return 'Off';
    }
    foreach ($arr_up as $up_kw) {
        if (strpos($v, $up_kw) !== false || $v === $up_kw) return 'On';
    }
    return 'Other';
}

// =====================================================================
// 4. AJAX API ENDPOINTS FOR DROPDOWNS
// =====================================================================
$api = $_GET['api'] ?? '';
if ($api === 'get_agents' && $db_status) {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    $agents = [];
    foreach ($target_nodes as $node => $active_pdo) {
        if (!$active_pdo) continue;
        $node_label = get_node_label($node);
        $st = $active_pdo->query("SELECT alias FROM tagente WHERE disabled = 0 ORDER BY alias ASC");
        if ($st) {
            while ($alias = $st->fetchColumn()) {
                $agents[] = $node_label . pretty_text($alias);
            }
        }
    }
    sort($agents);
    echo json_encode($agents); exit;
}
if ($api === 'get_modules' && $db_status) {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    $agent_filter = $_GET['agent_filter'] ?? '';
    $modules = [];
    if (!empty($agent_filter)) {
        $parsed_filter = parse_node_from_filter($agent_filter);
        $nodes_to_search = $parsed_filter['node'] ? [$parsed_filter['node']] : array_keys($target_nodes);
        $search_term = $parsed_filter['search'];
        
        $ag_kw = "%" . str_replace(' ', '%', trim($search_term)) . "%";
        foreach ($nodes_to_search as $node) {
            $active_pdo = $target_nodes[$node] ?? null;
            if (!$active_pdo) continue;
            $st = $active_pdo->prepare("SELECT DISTINCT m.nombre FROM tagente_modulo m JOIN tagente a ON m.id_agente = a.id_agente WHERE m.disabled = 0 AND a.disabled = 0 AND (a.alias LIKE ? OR a.nombre LIKE ?) ORDER BY m.nombre ASC");
            $st->execute([$ag_kw, $ag_kw]);
            if ($st) {
                while ($name = $st->fetchColumn()) {
                    $modules[] = pretty_text($name);
                }
            }
        }
    }
    $modules = array_unique($modules);
    sort($modules);
    echo json_encode($modules); exit;
}

// =====================================================================
// 5. INPUT PROCESSING
// =====================================================================
$agent    = $_REQUEST['agent'] ?? '';
$module   = $_REQUEST['module'] ?? 'Power:State';
$val_up   = $_REQUEST['val_up'] ?? '1, on, online, up, run, ok';
$val_off  = $_REQUEST['val_off'] ?? '0, off, offline, down, crit, abended, stop';
$tz       = $_REQUEST['tz'] ?? $DEFAULT_TZ;
$from_s   = $_REQUEST['from'] ?? '';
$to_s     = $_REQUEST['to'] ?? '';
$preset   = $_REQUEST['preset'] ?? 'this_month'; 
$export   = $_REQUEST['export'] ?? '';

$arr_up  = array_filter(array_map('trim', explode(',', strtolower($val_up))));
$arr_off = array_filter(array_map('trim', explode(',', strtolower($val_off))));

$from_epoch = null; $to_epoch = null;
if ($preset === 'custom' && $from_s !== '' && $to_s !== '') {
    try {
        $from_dt = new DateTime($from_s, new DateTimeZone($tz));
        $to_dt   = new DateTime($to_s, new DateTimeZone($tz));
        $from_epoch = $from_dt->getTimestamp();
        $to_epoch   = $to_dt->getTimestamp();
        $from_s = $from_dt->format('Y-m-d H:i:s');
        $to_s = $to_dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        $from_epoch = strtotime($from_s);
        $to_epoch   = strtotime($to_s);
    }
} else {
    $from_dt = new DateTime('now', new DateTimeZone($tz));
    $to_dt = clone $from_dt;
    if ($preset === 'this_month') {
        $from_dt->modify('first day of this month 00:00:00');
    } elseif ($preset === 'last_month') {
        $from_dt->modify('first day of last month 00:00:00');
        $to_dt->modify('last day of last month 23:59:59');
    } elseif ($preset === '1d') {
        $from_dt->modify('-1 day');
    } elseif ($preset === '7d') {
        $from_dt->modify('-7 days');
    }
    $from_epoch = $from_dt->getTimestamp();
    $to_epoch = $to_dt->getTimestamp();
    $from_s = $from_dt->format('Y-m-d H:i:s');
    $to_s = $to_dt->format('Y-m-d H:i:s');
}

$MAX_TARGETS = 50;
$errors = [];
$results = [];
$grand_total_sec = 0;

if ($db_status && !empty($agent) && empty($errors)) {
    try {
        $parsed_agent = parse_node_from_filter($agent);
        $nodes_to_search = $parsed_agent['node'] ? [$parsed_agent['node']] : array_keys($target_nodes);
        $agent_search = $parsed_agent['search'];
        
        $ag_kw  = "%" . $agent_search . "%";
        $mod_kw = "%" . trim($module) . "%";
        
        $targets = [];
        $tooMany = false;
        foreach ($nodes_to_search as $node) {
            $active_pdo = $target_nodes[$node] ?? null;
            if (!$active_pdo) continue;
            
            $node_label = get_node_label($node);
            
            $st = $active_pdo->prepare("SELECT m.id_agente_modulo, a.alias as agent_alias, a.nombre as agent_name, m.nombre as module_name FROM tagente_modulo m JOIN tagente a ON m.id_agente = a.id_agente WHERE (a.alias LIKE ? OR a.nombre LIKE ?) AND m.nombre LIKE ? ORDER BY a.alias ASC LIMIT ?");
            $st->bindValue(1, $ag_kw, PDO::PARAM_STR);
            $st->bindValue(2, $ag_kw, PDO::PARAM_STR);
            $st->bindValue(3, $mod_kw, PDO::PARAM_STR);
            $st->bindValue(4, ($MAX_TARGETS - count($targets) + 1), PDO::PARAM_INT);
            $st->execute();
            foreach ($st->fetchAll() as $res) {
                $res['id_agente_modulo'] = get_node_uuid($node) . ':' . $res['id_agente_modulo'];
                $res['agent_alias'] = $node_label . $res['agent_alias'];
                $res['node'] = $node;
                $targets[] = $res;
                if (count($targets) >= $MAX_TARGETS) {
                    $tooMany = true;
                    break 2;
                }
            }
        }

        if (empty($targets)) {
            $errors[] = "No matching Agent/Module found in the database.";
        } else {
            $qBeforeNum = "SELECT utimestamp, datos FROM tagente_datos WHERE id_agente_modulo = ? AND utimestamp < ? ORDER BY utimestamp DESC LIMIT 1";
            $qBeforeStr = "SELECT utimestamp, datos FROM tagente_datos_string WHERE id_agente_modulo = ? AND utimestamp < ? ORDER BY utimestamp DESC LIMIT 1";
            $qRangeNum = "SELECT utimestamp, datos FROM tagente_datos WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? ORDER BY utimestamp ASC LIMIT 10000";
            $qRangeStr = "SELECT utimestamp, datos FROM tagente_datos_string WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? ORDER BY utimestamp ASC LIMIT 10000";

            foreach ($targets as $t) {
                $prefixed_mid = $t['id_agente_modulo'];
                $parsed_mid = parse_node_id($prefixed_mid);
                $node = $parsed_mid['node'];
                $real_id = $parsed_mid['id'];
                
                $active_pdo = $target_nodes[$node] ?? null;
                if (!$active_pdo) continue;
                
                $active_history_pdo = ($node === 'primary') ? $pdoHistory : $active_pdo;
                
                $stBefNumActive = $active_pdo->prepare($qBeforeNum);
                $stBefStrActive = $active_pdo->prepare($qBeforeStr);
                $stRanNumActive = $active_pdo->prepare($qRangeNum);
                $stRanStrActive = $active_pdo->prepare($qRangeStr);
                
                if ($active_history_pdo) {
                    $stBefNumHist = $active_history_pdo->prepare($qBeforeNum);
                    $stBefStrHist = $active_history_pdo->prepare($qBeforeStr);
                    $stRanNumHist = $active_history_pdo->prepare($qRangeNum);
                    $stRanStrHist = $active_history_pdo->prepare($qRangeStr);
                }

                $befores = [];
                $stBefNumActive->execute([$real_id, $from_epoch]); if ($r = $stBefNumActive->fetch()) $befores[] = $r;
                $stBefStrActive->execute([$real_id, $from_epoch]); if ($r = $stBefStrActive->fetch()) $befores[] = $r;
                if ($active_history_pdo) {
                    $stBefNumHist->execute([$real_id, $from_epoch]); if ($r = $stBefNumHist->fetch()) $befores[] = $r;
                    $stBefStrHist->execute([$real_id, $from_epoch]); if ($r = $stBefStrHist->fetch()) $befores[] = $r;
                }
                
                $prev = null;
                foreach ($befores as $b) {
                    if (!$prev || (int)$b['utimestamp'] > (int)$prev['utimestamp']) $prev = $b;
                }
                
                $initial_state = $prev ? map_dynamic_state($prev['datos'], $arr_up, $arr_off) : 'On';
                if ($initial_state === 'Other') $initial_state = 'On';

                $raw_rows = [];
                $stRanNumActive->execute([$real_id, $from_epoch, $to_epoch]); while ($r = $stRanNumActive->fetch()) $raw_rows[] = $r;
                $stRanStrActive->execute([$real_id, $from_epoch, $to_epoch]); while ($r = $stRanStrActive->fetch()) $raw_rows[] = $r;
                if ($active_history_pdo) {
                    $stRanNumHist->execute([$real_id, $from_epoch, $to_epoch]); while ($r = $stRanNumHist->fetch()) $raw_rows[] = $r;
                    $stRanStrHist->execute([$real_id, $from_epoch, $to_epoch]); while ($r = $stRanStrHist->fetch()) $raw_rows[] = $r;
                }

                usort($raw_rows, function($a, $b) { return ((int)$a['utimestamp']) <=> ((int)$b['utimestamp']); });
                $clean_rows = []; $seen = [];
                foreach ($raw_rows as $r) {
                    $k = ((int)$r['utimestamp']).'|'.(string)$r['datos'];
                    if (isset($seen[$k])) continue;
                    $seen[$k] = true; $clean_rows[] = $r;
                }

                $intervals = [];
                $state = $initial_state;
                $openOff = ($state === 'Off') ? $from_epoch : null;

                foreach ($clean_rows as $r) {
                    $t_ts = (int)$r['utimestamp'];
                    $s = map_dynamic_state($r['datos'], $arr_up, $arr_off);
                    if ($s === 'Other') continue;

                    if ($state !== 'Off' && $s === 'Off') {
                        $openOff = max($t_ts, $from_epoch);
                    } elseif ($state === 'Off' && $s !== 'Off') {
                        if ($openOff !== null && $t_ts > $openOff) {
                            $intervals[] = [$openOff, min($t_ts, $to_epoch)];
                        }
                        $openOff = null;
                    }
                    $state = $s;
                }
                if ($openOff !== null && $openOff < $to_epoch) $intervals[] = [$openOff, $to_epoch];

                $total_sec = 0;
                foreach ($intervals as $iv) $total_sec += max(0, (int)$iv[1] - (int)$iv[0]);

                $grand_total_sec += $total_sec;

                $results[] = [
                    'agent_alias'   => pretty_text($t['agent_alias']),
                    'module_name'   => pretty_text($t['module_name']),
                    'initial_state' => $initial_state,
                    'intervals'     => $intervals,
                    'total_sec'     => $total_sec,
                    'too_many'      => $tooMany
                ];
            }
        }
    } catch (Throwable $e){ $errors[] = "Calculation Process Failed: " . $e->getMessage(); }
}

// =====================================================================
// 7. EXPORT LOGIC (TXT SINGLE / TXT ZIP / CSV)
// =====================================================================
if (!empty($export) && !empty($results)) {
    if (ob_get_level() > 0) ob_clean();
    $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $agent ?: 'agent');
    $base_fn = "PowerOFF_Summary_{$safe}_".date('Ymd_His');

    if ($export === 'csv') {
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="'.$base_fn.'.csv"');
        echo "Agent Name,Module,Initial State,Total Power Off Time,Interval Count\n";
        foreach ($results as $r) {
            echo "\"{$r['agent_alias']}\",\"{$r['module_name']}\",\"{$r['initial_state']}\",\"".fmt_dur($r['total_sec'])."\",\"".count($r['intervals'])."\"\n";
        }
    } 
    elseif ($export === 'txt_zip') {
        // ZIP EXPORT: 1 FILE PER AGENT
        $zipFile = tempnam(sys_get_temp_dir(), 'pfms_zip');
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            die("Failed to create ZIP file. Ensure PHP ZipArchive extension is active.");
        }

        foreach ($results as $r) {
            $txt = "Agent: {$r['agent_alias']}\n";
            $txt .= "Start: " . ts_to_local_human($from_epoch, $tz) . "\n";
            $txt .= "End: " . ts_to_local_human($to_epoch, $tz) . "\n";
            $txt .= "Total Power Off: " . fmt_dur($r['total_sec']) . "\n\n";
            $txt .= "History:\n";
            
            if (empty($r['intervals'])) {
                $txt .= "(None)\n";
            } else {
                $i = 1;
                foreach ($r['intervals'] as $iv) {
                    $s = ts_to_local_human($iv[0], $tz);
                    $e = ts_to_local_human($iv[1], $tz);
                    $d = fmt_dur($iv[1]-$iv[0]);
                    $txt .= "{$i}. Start: {$s} | End: {$e} | Duration: {$d}\n";
                    $i++;
                }
            }

            $safe_agent = preg_replace('/[^A-Za-z0-9_\-]/', '_', $r['agent_alias']);
            // If multiple modules exist for same agent, append random str to avoid overwrite
            $file_name = "power_off_{$safe_agent}_".substr(md5(rand()), 0, 5)."_".date('Ymd_His').".txt";
            $zip->addFromString($file_name, $txt);
        }
        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.$base_fn.'.zip"');
        header('Content-Length: ' . filesize($zipFile));
        readfile($zipFile);
        unlink($zipFile);
    } 
    elseif ($export === 'txt_single') {
        // SINGLE FILE EXPORT: EXACT LAYOUT MATCHING SCREENSHOT
        header('Content-Type: text/plain; charset=utf-8'); 
        header('Content-Disposition: attachment; filename="'.$base_fn.'.txt"');
        
        $out = [];
        foreach ($results as $r) {
            $txt = "Agent: {$r['agent_alias']}\n";
            $txt .= "Start: " . ts_to_local_human($from_epoch, $tz) . "\n";
            $txt .= "End: " . ts_to_local_human($to_epoch, $tz) . "\n";
            $txt .= "Total Power Off: " . fmt_dur($r['total_sec']) . "\n\n";
            $txt .= "History:\n";
            
            if (empty($r['intervals'])) {
                $txt .= "(None)\n";
            } else {
                $i = 1;
                foreach ($r['intervals'] as $iv) {
                    $s = ts_to_local_human($iv[0], $tz);
                    $e = ts_to_local_human($iv[1], $tz);
                    $d = fmt_dur($iv[1]-$iv[0]);
                    $txt .= "{$i}. Start: {$s} | End: {$e} | Duration: {$d}\n";
                    $i++;
                }
            }
            $out[] = $txt;
        }
        echo implode("\n\n\n", $out);
    }
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PowerState OFF Summary</title>
    <link rel="icon" href="/pandora_console/images/pandora.ico" type="image/x-icon">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" />
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <style>
        * { font-family: 'Lato', sans-serif !important; color: #333; font-size: 14px !important; }
        body { background-color: #f4f6f8; margin: 0; padding: 0; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-weight: normal !important; font-size: 18px !important; line-height: 1 !important; display: inline-block; vertical-align: middle; color: inherit !important; }

        .pandora-header-top { background-color: #ffffff; border-bottom: 1px solid #e0e4e8; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 25px; z-index: 10; }
        .header-logo { height: 24px; width: auto; }
        .header-divider { width: 1px; height: 28px; background-color: #dce1e5; margin: 0 20px; }
        .header-title-box { display: flex; flex-direction: column; line-height: 1.2; margin-right: 40px; }
        .header-title-box .main-title { font-size: 14px !important; font-weight: normal !important; color: #0b1a26 !important; }
        .nav-icon-btn { color: #4a5568 !important; text-decoration: none; display: flex; align-items: center; justify-content: center; height: 36px; width: 36px; border-radius: 50%; transition: 0.2s; }
        
        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; }
        .breadcrumb-box { display: flex; flex-direction: column; }
        .page-breadcrumb { font-size: 11px !important; color: #64748b !important; margin-bottom: 4px; font-weight: normal !important; text-transform: uppercase; letter-spacing: 0.5px; }
        .page-title { font-size: 18px !important; color: #0b1a26 !important; margin: 0; font-weight: 600 !important; line-height: 1.2; }

        .btn-apply { background: #004d40; color: #fff !important; border: none; padding: 8px 25px; border-radius: 4px; font-weight: normal !important; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;}
        .btn-apply:hover { background: #00695c; }
        .btn-secondary-custom { background: #fff; color: #4a5568 !important; border: 1px solid #dce1e5; padding: 8px 20px; border-radius: 4px; font-weight: normal !important; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;}
        .btn-secondary-custom:disabled { opacity: 0.5; cursor: not-allowed; }

        .main-content { padding: 30px; }
        .dashboard-card { background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border: 1px solid #f0f3f5; margin-bottom: 25px; }
        .dashboard-card-body { padding: 25px; }

        .form-label { font-size: 11px !important; font-weight: normal; color: #7f8c8d; text-transform: uppercase; margin-bottom: 5px; display: block; }
        .form-control-fix { width: 100%; height: 38px; padding: 8px 12px; border: 1px solid #dce1e5; border-radius: 4px; font-weight: normal !important; background-color: #fff; outline: none; }
        
        table.table-pfms { width: 100%; border-collapse: collapse; margin-top: 0; }
        table.table-pfms thead th { background-color: #f8f9fa; border-bottom: 2px solid #e0e4e8; text-transform: uppercase; padding: 12px 15px; font-weight: normal; color: #7f8c8d; font-size: 11px !important; text-align: left; }
        table.table-pfms tbody td { font-weight: normal; border-bottom: 1px solid #f0f3f5; padding: 12px 15px; color: #0b1a26; vertical-align: middle; }
        
        .status-badge { padding: 4px 10px; border-radius: 4px; color: #fff; font-size: 10px !important; text-transform: uppercase; font-weight: normal; }
        .bg-on { background-color: #2ecc71; } .bg-off { background-color: #e74c3c; }

        /* CUSTOM AUTOCOMPLETE DROPDOWN */
        .autocomplete-container { position: relative; width: 100%; }
        .custom-dropdown { position: absolute; top: 100%; left: 0; width: 100%; max-height: 250px; overflow-y: auto; background-color: #ffffff; border: 1px solid #dce1e5; border-radius: 0 0 4px 4px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 9999; display: none; }
        .custom-dropdown-item { padding: 10px 12px; cursor: pointer; font-size: 13px !important; border-bottom: 1px solid #f0f3f5; color: #333; transition: background 0.1s; }
        .custom-dropdown-item:last-child { border-bottom: none; }
        .custom-dropdown-item:hover { background-color: #f8f9fa; color: #1976d2; font-weight: normal; }

        /* LOADING SPINNER & OVERLAY CSS */
        #loader-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(255, 255, 255, 0.85);
            z-index: 10000; display: flex; flex-direction: column; 
            align-items: center; justify-content: center; backdrop-filter: blur(2px);
        }
        .spinner {
            width: 50px; height: 50px; border: 5px solid #e0e4e8;
            border-top: 5px solid #004d40; border-radius: 50%;
            animation: spin 1s linear infinite; margin-bottom: 15px;
        }
        .rotating { animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loader-text { font-size: 16px !important; font-weight: normal; color: #0b1a26; }
        .loader-subtext { font-size: 12px !important; color: #7f8c8d; margin-top: 5px; }

        /* SUMMARY & CARD STYLES */
        .summary-box { background: #e1f5fe; border: 1px solid #b3e5fc; border-radius: 8px; padding: 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .summary-box-title { font-size: 14px !important; color: #01579b; font-weight: normal; margin-bottom: 5px; text-transform: uppercase; }
        .summary-box-value { font-size: 28px !important; color: #004d40; font-weight: normal; line-height: 1; }
    </style>
</head>
<body>

<div id="loader-overlay">
    <div class="spinner"></div>
    <div class="loader-text">Loading Page...</div>
    <div class="loader-subtext" id="loader-subtext-dynamic">Preparing interface components.</div>
</div>

<div class="pandora-header-top">
    <div class="header-left">
        <img src="/pandora_console/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box"><span class="main-title">Pandora FMS</span><span class="sub-title">PFMS-Toolkit</span></div>
    </div>
    <div class="header-right"><a href="/pandora_console/index.php" class="nav-icon-btn"><span class="material-symbols-outlined">home</span></a></div>
</div>

<div class="pandora-header-bottom">
    <div class="breadcrumb-box"><span class="page-breadcrumb"><?= h($dynamic_breadcrumb) ?></span><h1 class="page-title">PowerState OFF Summary</h1></div>
</div>

<div class="main-content">
    <div class="dashboard-card">
        <div class="dashboard-card-body">
            <form method="get" id="queryForm">
                <?php if(isset($_GET['page'])): ?>
                    <input type="hidden" name="page" value="<?= h($_GET['page']) ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Agent (Select or Type Pattern)</label>
                        <div class="autocomplete-container">
                            <input type="text" id="agent_input" name="agent" class="form-control-fix" value="<?= h($agent) ?>" autocomplete="off" placeholder="e.g. CGK3A-4A-AF10 or %GPU-NVL%">
                            <span class="material-symbols-outlined rotating" id="agent_spinner" style="display:none; position:absolute; right:10px; top:10px; color:#004d40; pointer-events:none;">autorenew</span>
                            <div id="agent_dropdown" class="custom-dropdown"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Module Name (Loads based on Agent)</label>
                        <div class="autocomplete-container">
                            <input type="text" id="module_input" name="module" class="form-control-fix" value="<?= h($module) ?>" autocomplete="off" placeholder="e.g. Power:State">
                            <span class="material-symbols-outlined rotating" id="module_spinner" style="display:none; position:absolute; right:10px; top:10px; color:#004d40; pointer-events:none;">autorenew</span>
                            <div id="module_dropdown" class="custom-dropdown"></div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Value Normal / UP (Separate with comma)</label>
                        <input type="text" name="val_up" class="form-control-fix" value="<?= h($val_up) ?>" placeholder="e.g. 1, ON, RUNNING, OK">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Value Downtime / OFF (Separate with comma)</label>
                        <input type="text" name="val_off" class="form-control-fix" value="<?= h($val_off) ?>" placeholder="e.g. 0, OFF, STOPPED, ABENDED, CRITICAL">
                    </div>

                    <div class="col-md-3"><label class="form-label">Start Date</label><input type="datetime-local" name="from" class="form-control-fix" value="<?= h(date('Y-m-d\TH:i', $from_epoch)) ?>"></div>
                    <div class="col-md-3"><label class="form-label">End Date</label><input type="datetime-local" name="to" class="form-control-fix" value="<?= h(date('Y-m-d\TH:i', $to_epoch)) ?>"></div>
                    <div class="col-md-3"><label class="form-label">Timelapse Preset</label>
                        <select name="preset" class="form-control-fix">
                            <option value="custom" <?= $preset=='custom'?'selected':'' ?>>Custom Range</option>
                            <option value="this_month" <?= $preset=='this_month'?'selected':'' ?>>This Month (Start - Now)</option>
                            <option value="last_month" <?= $preset=='last_month'?'selected':'' ?>>Last Month (Full)</option>
                            <option value="1d" <?= $preset=='1d'?'selected':'' ?>>Last 24 Hours</option>
                            <option value="7d" <?= $preset=='7d'?'selected':'' ?>>Last 7 Days</option>
                        </select>
                    </div>
                    <div class="col-md-3"><label class="form-label">Timezone</label>
                        <select name="tz" class="form-control-fix">
                            <?php foreach (['Asia/Jakarta','Asia/Bangkok','UTC'] as $opt): ?>
                                <option value="<?= h($opt) ?>" <?= $opt==$tz?'selected':'' ?>><?= h($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button class="btn-apply" type="submit" onclick="setSubmitAction('run')"><span class="material-symbols-outlined">play_arrow</span> Run Query</button>
                    <button class="btn-secondary-custom" type="button" onclick="handleExport('txt_single')" <?= $results ? '' : 'disabled' ?>><span class="material-symbols-outlined">description</span> TXT (Combined)</button>
                    <button class="btn-secondary-custom" type="button" onclick="handleExport('txt_zip')" <?= $results ? '' : 'disabled' ?>><span class="material-symbols-outlined">folder_zip</span> TXT (ZIP / Agent)</button>
                    <button class="btn-secondary-custom" type="button" onclick="handleExport('csv')" <?= $results ? '' : 'disabled' ?>><span class="material-symbols-outlined">table_view</span> Download CSV</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger" style="font-size:12px; font-weight: normal; padding: 15px; border-radius: 6px; border: 1px solid #f5c6cb;">
        <span class="material-symbols-outlined" style="font-size:16px;">error</span> <?= h(implode(" | ", $errors)) ?>
      </div>
    <?php endif; ?>

    <?php if (!$errors && !empty($agent) && $results): ?>
        
        <?php $tooMany = false; foreach ($results as $r){ if (!empty($r['too_many'])) { $tooMany = true; break; } } ?>
        <?php if ($tooMany): ?>
            <div class="alert alert-warning mb-3" style="background:#fff3cd; color:#856404; font-size:12px; font-weight: normal; padding:12px; border-radius:6px; border: 1px solid #ffeeba;">
                <span class="material-symbols-outlined" style="font-size:16px;">warning</span>
                Agent criteria is too broad. Only showing the first <?= $MAX_TARGETS ?> targets to maintain server performance. Please narrow down the Agent name.
            </div>
        <?php endif; ?>

        <div class="summary-box">
            <div>
                <div class="summary-box-title">Grand Total OFF Duration</div>
                <div style="font-size:12px; color:#4a5568;">Range: <?= h(ts_to_local_long($from_epoch,$tz)) ?> to <?= h(ts_to_local_long($to_epoch,$tz)) ?></div>
            </div>
            <div class="summary-box-value"><?= h(fmt_dur($grand_total_sec)) ?></div>
        </div>
    
        <?php foreach ($results as $r): ?>
        <div class="dashboard-card">
            <div class="dashboard-card-header" style="background-color: #f8f9fa; border-bottom: 2px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; padding: 15px 25px;">
                <div style="display:flex; flex-direction:column; gap:6px;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span class="material-symbols-outlined" style="color:#1976d2; font-size:22px!important;">dns</span>
                        <span style="font-size:16px!important; font-weight: normal; color:#0b1a26;"><?= h($r['agent_alias']) ?></span>
                        <span style="font-size:12px!important; color:#4a5568; font-weight: normal; background:#e0e4e8; padding:2px 8px; border-radius:4px; margin-left:5px;"><?= h($r['module_name']) ?></span>
                    </div>
                    <div style="font-size:11px!important; color:#7f8c8d; font-weight: normal; display:flex; align-items:center; gap:6px;">
                        Initial State: <span class="status-badge <?= $r['initial_state']=='On' ? 'bg-on' : 'bg-off' ?>" style="font-size:9px!important; padding:3px 6px; letter-spacing:0.5px;"><?= h($r['initial_state']) ?></span>
                    </div>
                </div>
                <div style="text-align:right; background: #ffffff; border: 1px solid #dce1e5; padding: 8px 15px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);">
                    <div style="font-size:10px!important; color:#7f8c8d; font-weight: normal; text-transform:uppercase; margin-bottom:4px;">Total OFF Duration</div>
                    <div style="font-size:16px!important; font-weight: normal; color:#e74c3c; line-height:1; display:flex; align-items:center; gap:4px; justify-content:flex-end;">
                        <span class="material-symbols-outlined" style="font-size:18px!important;">timer</span> <?= h(fmt_dur($r['total_sec'])) ?>
                    </div>
                </div>
            </div>
            <div class="dashboard-card-body p-0" style="overflow-x:auto;">
                <?php if (empty($r['intervals'])): ?>
                    <div style="padding:25px; text-align:center; font-size:13px; color:#7f8c8d; font-weight: normal;">
                        No downtime (OFF) history found in this range.
                    </div>
                <?php else: ?>
                <table class="table-pfms">
                    <thead>
                        <tr>
                            <th style="width:5%; text-align:center;">#</th>
                            <th style="width:30%">Downtime Start</th>
                            <th style="width:30%">Downtime End</th>
                            <th style="width:35%">Durasi (Duration)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $idx=1; foreach ($r['intervals'] as $iv): ?>
                        <tr>
                            <td style="text-align:center; color:#7f8c8d;"><?= $idx++ ?></td>
                            <td><?= h(ts_to_local_human($iv[0],$tz)) ?></td>
                            <td><?= h(ts_to_local_human($iv[1],$tz)) ?></td>
                            <td style="color:#e74c3c; font-weight: normal;"><?= h(fmt_dur($iv[1]-$iv[0])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

    <?php elseif(!$errors && !empty($agent) && !$results): ?>
        <div class="alert" style="background:#fff3cd; color:#856404; font-size:12px; font-weight: normal; padding:15px; border-radius:6px; border: 1px solid #ffeeba;">
            <span class="material-symbols-outlined" style="font-size:16px;">warning</span>
            No historical downtime data found for the search criteria in this range.
        </div>
    <?php endif; ?>
</div>

<script>
// ==========================================
// 1. PAGE LOAD & FORM SUBMIT EVENTS
// ==========================================
let submitAction = 'run';
function setSubmitAction(action) { submitAction = action; }

document.getElementById('queryForm').addEventListener('submit', function() {
    if (submitAction === 'run') {
        const overlay = document.getElementById('loader-overlay');
        overlay.style.display = 'flex';
        overlay.querySelector('.loader-text').innerText = 'Calculating Duration...';
        overlay.querySelector('.loader-subtext').innerText = 'Merging data from Active & History Database. Please wait.';
    }
});

// ==========================================
// 2. EXPORT BLOB FETCH LOGIC (SUPPORTS ZIP)
// ==========================================
function handleExport(format) {
    const overlay = document.getElementById('loader-overlay');
    const loaderText = overlay.querySelector('.loader-text');
    const loaderSubtext = overlay.querySelector('.loader-subtext');

    overlay.style.display = 'flex';
    loaderText.innerText = 'Preparing Export File...';
    loaderSubtext.innerText = 'Please wait, the system is formatting data into the requested file.';

    const form = document.getElementById('queryForm');
    const formData = new FormData(form);
    const params = new URLSearchParams(formData);
    params.set('export', format);

    fetch('?' + params.toString())
    .then(response => {
        if (!response.ok) throw new Error('Network error');
        const disposition = response.headers.get('Content-Disposition');
        let filename = 'PowerOFF_Summary_Export';
        if (format === 'txt_zip') filename += '.zip';
        else if (format === 'txt_single') filename += '.txt';
        else filename += '.csv';

        if (disposition && disposition.indexOf('filename=') !== -1) {
            filename = disposition.split('filename=')[1].replace(/"/g, '');
        }
        return response.blob().then(blob => ({blob, filename}));
    })
    .then(({blob, filename}) => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);

        overlay.style.display = 'none';
    })
    .catch(error => {
        console.error(error);
        alert("An error occurred while downloading the file.");
        overlay.style.display = 'none';
    });
}

// ==========================================
// 3. CASCADING AUTOCOMPLETE & INIT FETCH
// ==========================================
document.addEventListener("DOMContentLoaded", function() {
    let agentsList = [];
    let modulesList = [];

    const urlParams = new URLSearchParams(window.location.search);
    const pageParam = urlParams.get('page');
    const apiPrefix = pageParam ? '?page=' + encodeURIComponent(pageParam) + '&api=' : '?api=';

    const overlay = document.getElementById('loader-overlay');
    const subtext = document.getElementById('loader-subtext-dynamic');
    const agentSpinner = document.getElementById('agent_spinner');
    const moduleSpinner = document.getElementById('module_spinner');
    
    overlay.style.display = 'flex';
    subtext.innerText = 'Fetching device list from database...';
    agentSpinner.style.display = 'block';

    fetch(apiPrefix + 'get_agents')
        .then(r=>r.json())
        .then(data => { 
            agentsList = data; 
            agentSpinner.style.display = 'none';
            overlay.style.display = 'none'; 
        })
        .catch(e => {
            console.error("Failed to load agents:", e);
            agentSpinner.style.display = 'none';
            overlay.style.display = 'none';
        });

    let lastFetchedAgent = '';
    function fetchModulesForAgent(agentKw) {
        if(!agentKw || agentKw.trim() === '') return;
        agentKw = agentKw.trim();
        if (agentKw === lastFetchedAgent) return;
        lastFetchedAgent = agentKw;
        
        moduleSpinner.style.display = 'block';
        fetch(apiPrefix + 'get_modules&agent_filter=' + encodeURIComponent(agentKw))
            .then(r=>r.json())
            .then(data => { 
                modulesList = data; 
                moduleSpinner.style.display = 'none';
            })
            .catch(() => {
                moduleSpinner.style.display = 'none';
            });
    }

    const agentInput = document.getElementById('agent_input');
    const moduleInput = document.getElementById('module_input');
    const agentDropdown = document.getElementById('agent_dropdown');
    const moduleDropdown = document.getElementById('module_dropdown');

    agentInput.addEventListener('blur', function() {
        setTimeout(() => fetchModulesForAgent(this.value), 200); 
    });

    if (agentInput.value && agentInput.value.trim() !== '') {
        fetchModulesForAgent(agentInput.value);
    }

    function setupDropdown(input, dropdown, getListFn, isAgentField) {
        function renderList(val) {
            const dataList = getListFn();
            dropdown.innerHTML = '';
            if (!dataList || dataList.length === 0) return;

            const filtered = dataList.filter(item => item.toLowerCase().includes(val.toLowerCase()));
            const displayList = filtered.slice(0, 100);

            if (displayList.length === 0) {
                dropdown.style.display = 'none';
                return;
            }

            displayList.forEach(item => {
                const div = document.createElement('div');
                div.className = 'custom-dropdown-item';
                div.textContent = item;
                div.addEventListener('mousedown', function(e) {
                    e.preventDefault(); 
                    input.value = item;
                    dropdown.style.display = 'none';
                    if (isAgentField) fetchModulesForAgent(item);
                });
                dropdown.appendChild(div);
            });
            dropdown.style.display = 'block';
        }

        input.addEventListener('focus', function() { renderList(this.value); });
        input.addEventListener('input', function() { renderList(this.value); });
        input.addEventListener('blur', function() { 
            setTimeout(() => { dropdown.style.display = 'none'; }, 250); 
        });
    }

    setupDropdown(agentInput, agentDropdown, () => agentsList, true);
    setupDropdown(moduleInput, moduleDropdown, () => modulesList, false);
});
</script>
</body>
</html>


