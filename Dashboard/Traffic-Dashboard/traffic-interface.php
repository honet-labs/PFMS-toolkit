<?php
/* traffic-interface.php
 *
 * Enterprise Traffic Interfaces Dashboard
 * - UI/UX: Identical Flow to Dynamic Dashboard Template
 * - Logic: PURE REALITY MODE
 * - Features: Master List Landing Page, Create Dashboard, Shareable Links
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
set_time_limit(120);

// 1. DYNAMIC BREADCRUMB & HEADERS
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: interest-cohort=()');

$dynamic_breadcrumb = "PANDORA CONSOLE / CUSTOM / PANEL / TRAFFIC DASHBOARD";
$PANDORA_BASE_URL = "/pandora_console";
$panelDir = $PANEL_DIR_NAME ?? "custom";
$directScriptUrl = $_SERVER['SCRIPT_NAME'];

// 2. CENTRALIZED DB & SECURITY
require_once __DIR__ . '/../../includes/db-connection.php';
header_remove('X-Frame-Options');
header("Content-Security-Policy: frame-ancestors 'self' *;");

$CONFIG_FILE = __DIR__ . '/traffic-interface-saved.json';

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';
if (empty($csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['pfms_csrf_token'] = $csrf_token;
}

// 3. HELPERS
if (!function_exists('valid_ip')) {
    function valid_ip(string $s): bool { return filter_var(trim($s), FILTER_VALIDATE_IP) !== false; }
}
if (!function_exists('pick_best_ip')) {
    function pick_best_ip(string $a, string $b, string $c): string {
        foreach ([$a,$b,$c] as $cand) { if (valid_ip($cand)) return $cand; } return '';
    }
}
if (!function_exists('h')) {
    function h($string) { return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8'); }
}

// 4. TRAFFIC MODULE SETTINGS
$moduleSuffixesIn  = ['_ifHCIInOctets', '_ifHCInOctets', 'ifHCInOctets', '_ifInOctets', 'ifInOctets'];
$moduleSuffixesOut = ['_ifHCIOutOctets', '_ifHCOutOctets', 'ifHCOutOctets', '_ifOutOctets', 'ifOutOctets'];
$moduleSuffixesSpeed = ['_ifHighSpeed', 'ifHighSpeed', '_ifSpeed', 'ifSpeed', ' - Speed', '- Speed', '_Speed']; 
$modulePrefixesSpeed = ['ifhighspeed - ', 'ifhighspeed-', 'ifspeed - ', 'ifspeed-', 'speed - ', 'speed-'];
$moduleSuffixesIndex = ['_ifIndex', 'ifIndex'];
$moduleSuffixesStatus = ['_ifOperStatus', 'ifOperStatus'];

$warnThreshold = 70.0;
$critThreshold = 80.0;
$pollingIntervalSeconds = 300;
$moduleValueIsBytesPerSecond = true;

function clean_base(string $base): string {
    $base = trim($base);
    $base = preg_replace('/\s+\d+(\.\d+)?\s*(gbps|mbps|kbps|bps|mb\/s|gb\/s|b\/s|g|m|k)$/i', '', $base);
    return trim($base, " \t\n\r\0\x0B-_");
}

function format_rate_ui(float $bytesPerSec, string $unit): string {
    if ($unit === 'Auto') {
        if ($bytesPerSec < 1000) return number_format($bytesPerSec, 2) . ' B/s';
        if ($bytesPerSec < 1000000) return number_format($bytesPerSec / 1000, 2) . ' KB/s';
        if ($bytesPerSec < 1000000000) return number_format($bytesPerSec / 1000000, 2) . ' MB/s';
        return number_format($bytesPerSec / 1000000000, 2) . ' GB/s';
    }
    if ($unit === 'Bps') return number_format(max(0.0, $bytesPerSec), 0, '.', ',') . ' B/s';
    if ($unit === 'MBps') { $v = $bytesPerSec / 1000000.0; return number_format($v, 2) . ' MB/s'; }
    if ($unit === 'GBps') { 
        $v = $bytesPerSec / 1000000000.0; 
        if ($v > 0 && $v < 0.01) return number_format($v, 4) . ' GB/s'; 
        return number_format($v, 2) . ' GB/s'; 
    }
    if ($unit === 'Gbps') {
        $v = ($bytesPerSec * 8.0) / 1000000000.0;
        return number_format($v, 2) . ' Gbps';
    }
    $v = ($bytesPerSec * 8.0) / 1000000.0; return number_format($v, 2) . ' Mbps';
}

function util_level(float $pct, float $warn, float $crit): string {
    if ($pct > 100.0) return 'anomali';
    if ($pct >= $crit) return 'crit'; 
    if ($pct >= $warn) return 'warn'; 
    return 'ok';
}

// =====================================================================
// AJAX API ENDPOINTS
// =====================================================================
$api = $_GET['api'] ?? '';

if ($api === 'load_config') {
    ob_clean(); header('Content-Type: application/json');
    if(file_exists($CONFIG_FILE)) { echo file_get_contents($CONFIG_FILE); } 
    else { echo json_encode([]); } 
    exit;
}

if ($api === 'save_config') {
    ob_clean(); header('Content-Type: application/json');
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf_token) || $client_token !== $csrf_token) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh portal.']); exit;
    }
    $input = file_get_contents('php://input');
    $bytes = @file_put_contents($CONFIG_FILE, $input);
    if ($bytes === false) {
        $err = error_get_last();
        $errMsg = $err['message'] ?? 'Permission Denied / Unknown Error';
        echo json_encode(['ok' => false, 'error' => "Gagal menulis ke file ($CONFIG_FILE). Alasan: $errMsg", 'file' => basename($CONFIG_FILE)]);
    } else {
        echo json_encode(['ok' => true, 'file' => basename($CONFIG_FILE)]);
    }
    exit;
}

if ($api === 'export') {
    try {
        $format = $_GET['format'] ?? 'csv';
        $config = [];
        if (file_exists($CONFIG_FILE)) $config = json_decode(file_get_contents($CONFIG_FILE), true);
        
        $agent_ids = [];
        foreach ($config as $dash) {
            $aid = (int)($dash['agent_id'] ?? 0);
            $gid = (int)($dash['group_id'] ?? 0);
            if ($aid > 0) {
                $agent_ids[] = $aid;
            } elseif ($gid > 0) {
                $stG = $pdo->prepare("SELECT id_agente FROM tagente WHERE id_grupo = ? AND disabled = 0");
                $stG->execute([$gid]);
                while($rowG = $stG->fetch(PDO::FETCH_COLUMN)) $agent_ids[] = (int)$rowG;
            } else {
                $stA = $pdo->query("SELECT id_agente FROM tagente WHERE disabled = 0");
                while($rowA = $stA->fetch(PDO::FETCH_COLUMN)) $agent_ids[] = (int)$rowA;
            }
        }
        $agent_ids = array_unique($agent_ids);
        
        if (empty($agent_ids)) { echo "No agents found in configuration."; exit; }

        $placeholders = implode(',', array_fill(0, count($agent_ids), '?'));
        $sql = "SELECT a.id_agente, a.alias, a.nombre, a.direccion, am.id_agente_modulo, am.nombre as mod_name, am.unit, ae.datos, am.id_category, c.name AS category_name 
                FROM tagente a
                JOIN tagente_modulo am ON a.id_agente = am.id_agente
                LEFT JOIN tcategory c ON am.id_category = c.id
                LEFT JOIN tagente_estado ae ON am.id_agente_modulo = ae.id_agente_modulo
                WHERE a.id_agente IN ($placeholders) AND a.disabled = 0 AND am.disabled = 0";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($agent_ids));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $agents_data = [];
        foreach ($rows as $r) {
            $aid = $r['id_agente'];
            if (!isset($agents_data[$aid])) {
                $agents_data[$aid] = [
                    'alias' => $r['alias'] ?: $r['nombre'], 'ip' => pick_best_ip($r['direccion'], '', ''), 'mods' => []
                ];
            }
            $agents_data[$aid]['mods'][] = $r;
        }

        $final_export = [];
        foreach ($agents_data as $aid => $a) {
            $interfaces = [];
            foreach ($a['mods'] as $m) {
                $modName = (string)$m['mod_name'];
                $modNameL = strtolower($modName);
                $dir = null; $base = null;
                foreach ($modulePrefixesSpeed as $p) { if(str_starts_with($modNameL, strtolower($p))) { $dir='speed'; $base=substr($modName, strlen($p)); break; } }
                if(!$dir) {
                    foreach ($moduleSuffixesIn as $s) {
                        $sL = strtolower($s);
                        $pos = strpos($modNameL, $sL);
                        if ($pos !== false) {
                            $dir = 'in';
                            $base = substr($modName, 0, $pos) . substr($modName, $pos + strlen($s));
                            break;
                        }
                    }
                    if(!$dir) foreach ($moduleSuffixesOut as $s) {
                        $sL = strtolower($s);
                        $pos = strpos($modNameL, $sL);
                        if ($pos !== false) {
                            $dir = 'out';
                            $base = substr($modName, 0, $pos) . substr($modName, $pos + strlen($s));
                            break;
                        }
                    }
                    if(!$dir) foreach ($moduleSuffixesSpeed as $s) {
                        $sL = strtolower($s);
                        $pos = strpos($modNameL, $sL);
                        if ($pos !== false) {
                            $dir = 'speed';
                            $base = substr($modName, 0, $pos) . substr($modName, $pos + strlen($s));
                            break;
                        }
                    }
                }
                if (!$dir) continue;
                $base = clean_base($base);
                if ($base === '') $base = $modName;
                if (!isset($interfaces[$base])) $interfaces[$base] = ['name' => $base, 'rx' => 0, 'tx' => 0, 'speed' => 0, 'has_data' => false, 'category_name' => ''];
                if (!empty($m['category_name']) && empty($interfaces[$base]['category_name'])) {
                    $interfaces[$base]['category_name'] = html_entity_decode((string)$m['category_name'], ENT_QUOTES, 'UTF-8');
                }
                $val = (float)$m['datos'];
                if ($dir === 'speed') {
                    $mult = (stripos($modName, 'HighSpeed') !== false || strtolower($m['unit']) === 'mbps') ? 1000000.0 : 1.0;
                    if (strtolower($m['unit']) === 'gbps') $mult = 1000000000.0;
                    $interfaces[$base]['speed'] = $val * $mult;
                } else {
                    $mult = (stripos($modName, 'Mbps') !== false) ? 1000000.0 : 8.0;
                    if ($dir === 'in') $interfaces[$base]['rx'] = $val * $mult; else $interfaces[$base]['tx'] = $val * $mult;
                }
                $interfaces[$base]['has_data'] = true;
            }
            foreach ($interfaces as $iface) {
                if (!$iface['has_data']) continue;
                $rx_pct = ($iface['speed'] > 0) ? ($iface['rx'] / $iface['speed'] * 100) : 0;
                $tx_pct = ($iface['speed'] > 0) ? ($iface['tx'] / $iface['speed'] * 100) : 0;
                $final_export[] = [
                    'agent' => $a['alias'], 'ip' => $a['ip'], 'interface' => $iface['name'],
                    'category' => $iface['category_name'] ?: 'N/A',
                    'speed' => ($iface['speed'] / 1000000) . " Mbps",
                    'rx' => round($iface['rx']/1000000, 2) . " Mbps (" . round($rx_pct,2) . "%)",
                    'tx' => round($iface['tx']/1000000, 2) . " Mbps (" . round($tx_pct,2) . "%)"
                ];
            }
        }

        $filename = "Interface_Traffic_Report_" . date('Ymd_His');
        while (ob_get_level()) ob_end_clean();
        
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$filename.'.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Agent', 'IP Address', 'Interface', 'Category', 'Speed Capacity', 'Receive (RX)', 'Transmit (TX)']);
            foreach ($final_export as $row) fputcsv($out, array_values($row));
            fclose($out);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$filename.'.txt"');
            echo "INTERFACE TRAFFIC REPORT - " . date('Y-m-d H:i:s') . "\n";
            echo str_repeat("=", 120) . "\n";
            printf("%-25s %-15s %-20s %-15s %-15s %-25s %-25s\n", "AGENT", "IP", "INTERFACE", "CATEGORY", "SPEED", "RX TRAFFIC", "TX TRAFFIC");
            echo str_repeat("-", 120) . "\n";
            foreach ($final_export as $row) {
                printf("%-25s %-15s %-20s %-15s %-15s %-25s %-25s\n", substr($row['agent'],0,24), $row['ip'], substr($row['interface'],0,19), substr($row['category'],0,14), $row['speed'], $row['rx'], $row['tx']);
            }
        }
    } catch (Exception $e) {
        echo "Export Error: " . $e->getMessage();
    }
    exit;
}

if ($api === 'categories') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT id, name FROM tcategory ORDER BY name ASC");
    $categories = [];
    while($c = $stmt->fetch(PDO::FETCH_ASSOC)) { 
        $categories[] = [
            'id' => $c['id'], 
            'name' => html_entity_decode((string)$c['name'], ENT_QUOTES, 'UTF-8')
        ]; 
    }
    echo json_encode($categories); exit;
}

if ($api === 'groups') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $stmt = $pdo->query("SELECT id_grupo AS id, nombre AS name FROM tgrupo ORDER BY name ASC");
    $dropdown = [['id' => '0', 'name' => '-- Select Target Group --']];
    while($g = $stmt->fetch(PDO::FETCH_ASSOC)) { $dropdown[] = ['id' => $g['id'], 'name' => html_entity_decode((string)$g['name'], ENT_QUOTES, 'UTF-8')]; }
    echo json_encode($dropdown); exit;
}

if ($api === 'agents') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');
    $groupId = (int)($_GET['group_id'] ?? 0);
    $params = [];
    $sql = "SELECT id_agente AS id, alias FROM tagente WHERE disabled = 0";
    if ($groupId > 0) { $sql .= " AND id_grupo = ?"; $params[] = $groupId; }
    $sql .= " ORDER BY alias ASC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); exit;
}

if ($api === 'data') {
    if (ob_get_length()) ob_clean(); header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $groupId = (int)($input['group_id'] ?? 0);
    $agentId = (int)($input['agent_id'] ?? 0);
    $search = trim($input['search'] ?? '');
    $unit = $input['unit'] ?? 'Mbps';
    $speed_filter = $input['speed_filter'] ?? 'all';
    $page = max(1, (int)($input['page'] ?? 1));
    $perPage = max(1, (int)($input['per_page'] ?? 25));
    $warnThreshold = (float)($input['warn'] ?? 70.0);
    $critThreshold = (float)($input['crit'] ?? 80.0);
    $dashId = $input['dash_id'] ?? '';

    // Ambil daftar interface yang disembunyikan dari dashboard ini
    $hiddenKeys = [];
    if ($dashId && file_exists($CONFIG_FILE)) {
        $config = json_decode(file_get_contents($CONFIG_FILE), true);
        foreach ($config as $dash) {
            if (($dash['id'] ?? '') === $dashId) {
                $hiddenKeys = $dash['hidden_interfaces'] ?? [];
                break;
            }
        }
    }

    try {
        $params = [];
        $likeClauses = [];
        $suffixes = array_merge($moduleSuffixesIn, $moduleSuffixesOut, $moduleSuffixesSpeed, $moduleSuffixesIndex, $moduleSuffixesStatus);
        foreach ($suffixes as $i => $suf) { $likeClauses[] = "LOWER(am.nombre) LIKE :l{$i}"; $params[":l{$i}"] = "%" . strtolower($suf) . "%"; }
        foreach ($modulePrefixesSpeed as $i => $pref) { $likeClauses[] = "LOWER(am.nombre) LIKE :lp{$i}"; $params[":lp{$i}"] = "%" . strtolower($pref) . "%"; }
        $allLikes = implode(" OR ", $likeClauses);

        $sql = "SELECT am.id_agente_modulo, am.nombre AS module_name, am.unit AS module_unit, am.descripcion AS description, a.id_agente, a.alias, a.nombre AS agent_name, a.direccion AS ip, am.id_category, c.name AS category_name 
                FROM tagente_modulo am 
                JOIN tagente a ON a.id_agente = am.id_agente 
                LEFT JOIN tcategory c ON am.id_category = c.id
                WHERE am.disabled = 0 AND a.disabled = 0 AND ({$allLikes})";

        if ($groupId > 0) { $sql .= " AND a.id_grupo = :gid"; $params[':gid'] = $groupId; }
        if ($agentId > 0) { $sql .= " AND a.id_agente = :aid"; $params[':aid'] = $agentId; }


        $stmt = $pdo->prepare($sql); $stmt->execute($params); 
        $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $interfaces = []; $moduleIds = [];
        foreach ($modules as $m) {
            $aid = (int)$m['id_agente']; $modId = (int)$m['id_agente_modulo']; $modName = (string)$m['module_name'];
            $dir = null; $base = null; $modNameL = strtolower($modName);

            foreach ($modulePrefixesSpeed as $p) { if(str_starts_with($modNameL, strtolower($p))) { $dir='speed'; $base=substr($modName, strlen($p)); break; } }
            if(!$dir) {
                foreach ($moduleSuffixesIn as $s) {
                    $sL = strtolower($s);
                    $pos = strpos($modNameL, $sL);
                    if ($pos !== false) {
                        $dir = 'in';
                        $base = substr($modName, 0, $pos) . substr($modName, $pos + strlen($s));
                        break;
                    }
                }
                if(!$dir) foreach ($moduleSuffixesOut as $s) {
                    $sL = strtolower($s);
                    $pos = strpos($modNameL, $sL);
                    if ($pos !== false) {
                        $dir = 'out';
                        $base = substr($modName, 0, $pos) . substr($modName, $pos + strlen($s));
                        break;
                    }
                }
                if(!$dir) foreach ($moduleSuffixesSpeed as $s) {
                    $sL = strtolower($s);
                    $pos = strpos($modNameL, $sL);
                    if ($pos !== false) {
                        $dir = 'speed';
                        $base = substr($modName, 0, $pos) . substr($modName, $pos + strlen($s));
                        break;
                    }
                }
                if(!$dir) foreach ($moduleSuffixesIndex as $s) {
                    $sL = strtolower($s);
                    $pos = strpos($modNameL, $sL);
                    if ($pos !== false) {
                        $dir = 'index';
                        $base = substr($modName, 0, $pos) . substr($modName, $pos + strlen($s));
                        break;
                    }
                }
                if(!$dir) foreach ($moduleSuffixesStatus as $s) {
                    $sL = strtolower($s);
                    $pos = strpos($modNameL, $sL);
                    if ($pos !== false) {
                        $dir = 'status';
                        $base = substr($modName, 0, $pos) . substr($modName, $pos + strlen($s));
                        break;
                    }
                }
            }
            if (!$dir) continue;
            $base = clean_base($base);
            if ($base === '') $base = $modName;
            $key = "$aid|$base";
            if (!isset($interfaces[$key])) {
                $interfaces[$key] = ['agent_id'=>$aid, 'node'=>html_entity_decode((string)$m['alias'], ENT_QUOTES, 'UTF-8'), 'ip'=>pick_best_ip((string)$m['ip'], (string)$m['alias'], (string)$m['agent_name']), 'interface'=>$base, 'interface_alias'=>'', 'mod_in'=>0, 'mod_out'=>0, 'mod_speed'=>0, 'mod_index'=>0, 'mod_status'=>0, 'mod_speed_unit'=>'', 'mod_in_name'=>'', 'mod_out_name'=>'', 'desc_speed'=>0.0, 'category_name'=>''];
            }
            if ($dir==='speed') { $interfaces[$key]['mod_speed']=$modId; $interfaces[$key]['mod_speed_unit']=(string)$m['module_unit']; $interfaces[$key]['mod_speed_name']=$modName; }
            elseif ($dir==='in') { $interfaces[$key]['mod_in']=$modId; $interfaces[$key]['mod_in_name']=$modName; }
            elseif ($dir==='out') { $interfaces[$key]['mod_out']=$modId; $interfaces[$key]['mod_out_name']=$modName; }
            elseif ($dir==='index') { $interfaces[$key]['mod_index']=$modId; }
            elseif ($dir==='status') { $interfaces[$key]['mod_status']=$modId; }
            if (!empty($m['category_name']) && empty($interfaces[$key]['category_name'])) {
                $interfaces[$key]['category_name'] = html_entity_decode((string)$m['category_name'], ENT_QUOTES, 'UTF-8');
            }
            if (!empty($m['description'])) {
                $desc = html_entity_decode((string)$m['description'], ENT_QUOTES, 'UTF-8');
                if (stripos($desc, 'Alias:') !== false) {
                    $parts = explode(' - ', $desc);
                    foreach ($parts as $part) {
                        if (stripos($part, 'Alias:') !== false) {
                            $interfaces[$key]['interface_alias'] = trim(str_ireplace('Alias:', '', $part), " \t\n\r\0\x0B)");
                            break;
                        }
                    }
                }
                if (preg_match('/speed\s+(\d+)\s+bps/i', $desc, $matches)) {
                    $interfaces[$key]['desc_speed'] = (float)$matches[1];
                }
            }
            $moduleIds[] = $modId;
        }

        $values = [];
        $status_values = [];
        if (!empty($moduleIds)) {
            $ids = implode(',', array_fill(0, count(array_unique($moduleIds)), '?'));
            $st = $pdo->prepare("SELECT id_agente_modulo, datos FROM tagente_estado WHERE id_agente_modulo IN ($ids)");
            $st->execute(array_values(array_unique($moduleIds)));
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $values[(int)$row['id_agente_modulo']] = (float)$row['datos'];
                $status_values[(int)$row['id_agente_modulo']] = trim((string)$row['datos']);
            }
        }

        foreach ($interfaces as &$iface) {
            $rx_raw = $values[$iface['mod_in']] ?? 0.0; $tx_raw = $values[$iface['mod_out']] ?? 0.0;
            $speed_raw = $values[$iface['mod_speed']] ?? null;
            if ($values[$iface['mod_index']] ?? null) $iface['if_index'] = (int)$values[$iface['mod_index']]; else $iface['if_index'] = '-';

            // Format status badge based on RFC 2863 ifOperStatus value (1 = UP, 2 = DOWN, 3 = TESTING)
            $mod_status_id = $iface['mod_status'];
            $raw_status = $status_values[$mod_status_id] ?? null;

            if ($mod_status_id <= 0 || $raw_status === null || $raw_status === '') {
                $iface['status_text'] = 'N/A';
                $iface['status_badge'] = 'badge-warn';
            } else {
                $status_val = (float)$raw_status;
                if ($status_val === 1.0 || strtolower($raw_status) === 'up') {
                    $iface['status_text'] = 'UP';
                    $iface['status_badge'] = 'badge-ok';
                } elseif ($status_val === 2.0 || strtolower($raw_status) === 'down') {
                    $iface['status_text'] = 'DOWN';
                    $iface['status_badge'] = 'badge-crit';
                } elseif ($status_val === 3.0 || strtolower($raw_status) === 'testing') {
                    $iface['status_text'] = 'TESTING';
                    $iface['status_badge'] = 'badge-warn';
                } elseif ($status_val === 4.0 || strtolower($raw_status) === 'unknown') {
                    $iface['status_text'] = 'UNKNOWN';
                    $iface['status_badge'] = 'badge-warn';
                } elseif ($status_val === 5.0 || strtolower($raw_status) === 'dormant') {
                    $iface['status_text'] = 'DORMANT';
                    $iface['status_badge'] = 'badge-warn';
                } elseif ($status_val === 6.0 || strtolower($raw_status) === 'notpresent') {
                    $iface['status_text'] = 'NOT PRESENT';
                    $iface['status_badge'] = 'badge-warn';
                } elseif ($status_val === 7.0 || strtolower($raw_status) === 'lowerlayerdown') {
                    $iface['status_text'] = 'LOWER DOWN';
                    $iface['status_badge'] = 'badge-crit';
                } else {
                    $iface['status_text'] = strtoupper($raw_status);
                    $iface['status_badge'] = 'badge-warn';
                }
            }

            $isRxMbps = stripos($iface['mod_in_name'], 'Mbps') !== false;
            $isTxMbps = stripos($iface['mod_out_name'], 'Mbps') !== false;
            $iface['rx_bps'] = $isRxMbps ? $rx_raw * 1000000 : $rx_raw * 8.0;
            $iface['tx_bps'] = $isTxMbps ? $tx_raw * 1000000 : $tx_raw * 8.0;

            $cap_bps = 0.0;
            if ($speed_raw !== null && $speed_raw > 0) {
                $u = strtolower($iface['mod_speed_unit']);
                if (stripos($iface['mod_speed_name'], 'HighSpeed') !== false || $u === 'mbps') $cap_bps = $speed_raw * 1000000.0;
                elseif ($u === 'gbps') $cap_bps = $speed_raw * 1000000000.0;
                else $cap_bps = $speed_raw;
            } elseif ($iface['desc_speed'] > 0) { $cap_bps = $iface['desc_speed']; }
            $iface['cap_bps'] = $cap_bps;

            if ($cap_bps > 0) {
                $iface['rx_pct'] = ($iface['rx_bps'] / $cap_bps) * 100.0; $iface['tx_pct'] = ($iface['tx_bps'] / $cap_bps) * 100.0;
                $iface['speed_disp'] = ($cap_bps >= 1000000000.0) ? round($cap_bps / 1000000000.0, 1) . ' Gbps' : round($cap_bps / 1000000.0, 1) . ' Mbps';
                $iface['rx_pct_disp'] = number_format($iface['rx_pct'], 2) . '%'; $iface['tx_pct_disp'] = number_format($iface['tx_pct'], 2) . '%';
                $iface['rxLevel'] = util_level($iface['rx_pct'], $warnThreshold, $critThreshold); $iface['txLevel'] = util_level($iface['tx_pct'], $warnThreshold, $critThreshold);
            } else {
                $iface['rx_pct'] = 0; $iface['tx_pct'] = 0;
                $iface['speed_disp'] = 'N/A'; $iface['rx_pct_disp'] = 'N/A'; $iface['tx_pct_disp'] = 'N/A';
                $iface['rxLevel'] = 'ok'; $iface['txLevel'] = 'ok';
            }
            $iface['rx_disp'] = format_rate_ui($isRxMbps ? $rx_raw * 125000 : $rx_raw, $unit);
            $iface['tx_disp'] = format_rate_ui($isTxMbps ? $tx_raw * 125000 : $tx_raw, $unit);
            $iface['rowLevel'] = ($iface['rxLevel']==='crit'||$iface['txLevel']==='crit')?'crit':(($iface['rxLevel']==='warn'||$iface['txLevel']==='warn')?'warn':'ok');
        }

        $interfaces = array_values(array_filter($interfaces, function($i) { return ($i['rx_disp'] !== '0.00 Mbps' || $i['tx_disp'] !== '0.00 Mbps' || $i['speed_disp'] !== 'N/A'); }));

        $enabled_categories = $input['enabled_categories'] ?? null;
        if ($enabled_categories !== null) {
            $interfaces = array_values(array_filter($interfaces, function($iface) use ($enabled_categories) {
                $cat = $iface['category_name'] ?: 'N/A';
                return in_array($cat, $enabled_categories);
            }));
        }

        if ($speed_filter !== 'all') {
            $interfaces = array_values(array_filter($interfaces, function($iface) use ($speed_filter) {
                $cap = (float)$iface['cap_bps'];
                if ($speed_filter === 'gbps') {
                    return ($cap >= 1000000000.0);
                } elseif ($speed_filter === 'mbps') {
                    return ($cap >= 1000000.0 && $cap < 1000000000.0);
                } elseif ($speed_filter === 'gbps_mbps') {
                    return ($cap >= 1000000.0);
                } elseif ($speed_filter === 'na') {
                    return ($cap <= 0);
                }
                return true;
            }));
        }

        if ($search !== '') {
            $interfaces = array_values(array_filter($interfaces, function($iface) use ($search) {
                return (
                    stripos($iface['status_text'] ?? '', $search) !== false ||
                    stripos($iface['node'] ?? '', $search) !== false ||
                    stripos($iface['ip'] ?? '', $search) !== false ||
                    stripos($iface['interface'] ?? '', $search) !== false ||
                    stripos($iface['interface_alias'] ?? '', $search) !== false ||
                    stripos($iface['speed_disp'] ?? '', $search) !== false ||
                    stripos($iface['category_name'] ?? '', $search) !== false
                );
            }));
        }

        // ** Filter interface yang disembunyikan **
        if (!empty($hiddenKeys)) {
            $interfaces = array_values(array_filter($interfaces, function($iface) use ($hiddenKeys) {
                $key = $iface['agent_id'] . '|' . $iface['interface'];
                return !in_array($key, $hiddenKeys);
            }));
        }

        $sort = $input['sort'] ?? 'default';

        if ($sort === 'top_10_rx_pct') {
            usort($interfaces, function($a, $b) {
                return $b['rx_pct'] <=> $a['rx_pct'];
            });
            $interfaces = array_slice($interfaces, 0, 10);
        } elseif ($sort === 'top_10_tx_pct') {
            usort($interfaces, function($a, $b) {
                return $b['tx_pct'] <=> $a['tx_pct'];
            });
            $interfaces = array_slice($interfaces, 0, 10);
        } else {
            // Separate interfaces into Pinned (Warning/Critical) and Normal (OK)
            $pinned = [];
            $normal = [];
            foreach ($interfaces as $iface) {
                if ($iface['rowLevel'] === 'crit' || $iface['rowLevel'] === 'warn') {
                    $pinned[] = $iface;
                } else {
                    $normal[] = $iface;
                }
            }

            // Helper sorting function based on the selected option
            $sorter = function($a, $b) use ($sort) {
                if ($sort === 'default') {
                    $aPct = max((float)$a['rx_pct'], (float)$a['tx_pct']);
                    $bPct = max((float)$b['rx_pct'], (float)$b['tx_pct']);
                    if ($aPct !== $bPct) {
                        return $bPct <=> $aPct; // Highest utilization percentage first
                    }
                    $aCap = (float)($a['cap_bps'] ?? 0.0);
                    $bCap = (float)($b['cap_bps'] ?? 0.0);
                    if ($aCap !== $bCap) {
                        return $bCap <=> $aCap; // Highest bandwidth speed first
                    }
                    return 0;
                }
                elseif ($sort === 'rx_desc') return $b['rx_bps'] <=> $a['rx_bps'];
                elseif ($sort === 'rx_asc') return $a['rx_bps'] <=> $b['rx_bps'];
                elseif ($sort === 'tx_desc') return $b['tx_bps'] <=> $a['tx_bps'];
                elseif ($sort === 'tx_asc') return $a['tx_bps'] <=> $b['tx_bps'];
                elseif ($sort === 'rx_pct_desc') return $b['rx_pct'] <=> $a['rx_pct'];
                elseif ($sort === 'rx_pct_asc') return $a['rx_pct'] <=> $b['rx_pct'];
                elseif ($sort === 'tx_pct_desc') return $b['tx_pct'] <=> $a['tx_pct'];
                elseif ($sort === 'tx_pct_asc') return $a['tx_pct'] <=> $b['tx_pct'];
                return 0;
            };

            // Sort pinned group: Critical always floats above Warning
            usort($pinned, function($a, $b) use ($sorter) {
                if ($a['rowLevel'] !== $b['rowLevel']) {
                    if ($a['rowLevel'] === 'crit') return -1;
                    if ($b['rowLevel'] === 'crit') return 1;
                }
                return $sorter($a, $b);
            });

            // Sort normal group
            usort($normal, $sorter);

            // Recombine, ensuring Pinned interfaces are on top
            $interfaces = array_merge($pinned, $normal);
        }

        echo json_encode(['ok'=>true, 'data'=>array_slice($interfaces, ($page-1)*$perPage, $perPage), 'pagination'=>['total'=>count($interfaces), 'page'=>$page, 'total_pages'=>ceil(count($interfaces)/$perPage)], 'updated_at'=>date('H:i:s')]);
    } catch(Exception $e) { echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]); }
    exit;
}

if ($api === 'series') {
    ob_clean(); header('Content-Type: application/json');
    $inId = (int)($_GET['in']??0); $outId = (int)($_GET['out']??0);
    $unit = $_GET['unit'] ?? 'Mbps';
    $rangeHours = (int)($_GET['range'] ?? 24);
    
    $get_mult = function($id, $selected_unit) use ($pdo) {
        if($id <= 0) return 1.0;
        $st = $pdo->prepare("SELECT nombre FROM tagente_modulo WHERE id_agente_modulo = ?");
        $st->execute([$id]);
        $name = (string)$st->fetchColumn();
        $is_mbps_mod = (stripos($name, 'Mbps') !== false);
        
        if ($selected_unit === 'Auto') return $is_mbps_mod ? 125000.0 : 1.0; // Return as Bytes/s for Auto
        if ($selected_unit === 'Mbps') return $is_mbps_mod ? 1.0 : (8.0 / 1000000.0);
        if ($selected_unit === 'Gbps') return $is_mbps_mod ? 0.001 : (8.0 / 1000000000.0);
        if ($selected_unit === 'Bps') return $is_mbps_mod ? (1000000.0 / 8.0) : 1.0;
        if ($selected_unit === 'MBps') return $is_mbps_mod ? (1.0 / 8.0) : (1.0 / 1000000.0);
        if ($selected_unit === 'GBps') return $is_mbps_mod ? (1.0 / 8000.0) : (1.0 / 1000000000.0);
        return 1.0;
    };

    $mult_rx = $get_mult($inId, $unit);
    $mult_tx = $get_mult($outId, $unit);

    $fetchData = function($id, $m) use ($pdo, $history_pdo, $rangeHours) {
        if($id<=0) return ['pts' => [], 'avg' => 0];
        $from = time() - ($rangeHours * 3600);
        $to = time();
        if (isset($_GET['start']) && !empty($_GET['start'])) {
            $from = strtotime($_GET['start']);
            $to = isset($_GET['end']) && !empty($_GET['end']) ? strtotime($_GET['end']) : time();
        }

        $all_pts = [];
        // 1. Fetch active
        try {
            $st = $pdo->prepare("SELECT utimestamp as ts, datos FROM tagente_datos WHERE id_agente_modulo=? AND utimestamp BETWEEN ? AND ?");
            $st->execute([$id, $from, $to]);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $all_pts[] = $r;
            }
        } catch (Exception $e) {
            error_log("Active DB traffic query error: " . $e->getMessage());
        }

        // 2. Fetch historical
        if ($history_pdo) {
            try {
                $stH = $history_pdo->prepare("SELECT utimestamp as ts, datos FROM tagente_datos WHERE id_agente_modulo=? AND utimestamp BETWEEN ? AND ?");
                $stH->execute([$id, $from, $to]);
                while ($r = $stH->fetch(PDO::FETCH_ASSOC)) {
                    $all_pts[] = $r;
                }
            } catch (Exception $e) {
                error_log("Historical DB traffic query error: " . $e->getMessage());
            }
        }

        // 3. Deduplicate by exact timestamp
        $unique_pts = [];
        foreach ($all_pts as $pt) {
            $unique_pts[$pt['ts']] = $pt['datos'];
        }

        // 4. Group by 10-minute intervals
        $grouped = [];
        foreach ($unique_pts as $ts => $val) {
            $bin = floor($ts / 600) * 600;
            if (!isset($grouped[$bin])) {
                $grouped[$bin] = ['sum' => 0.0, 'count' => 0];
            }
            $grouped[$bin]['sum'] += (float)$val;
            $grouped[$bin]['count']++;
        }

        // 5. Sort chronologically
        ksort($grouped);

        // 6. Format points
        $pts = [];
        $sum = 0.0;
        $count = 0;
        foreach ($grouped as $bin => $data) {
            $avg_val = $data['sum'] / $data['count'];
            $scaled_val = $avg_val * $m;
            $pts[] = [(int)$bin * 1000, $scaled_val];
            $sum += $scaled_val;
            $count++;
        }

        return ['pts' => $pts, 'avg' => $count > 0 ? $sum/$count : 0];
    };
    $rx = $fetchData($inId, $mult_rx); $tx = $fetchData($outId, $mult_tx);
    echo json_encode(['ok'=>true, 'rx'=>$rx['pts'], 'tx'=>$tx['pts'], 'avg_rx'=>$rx['avg'], 'avg_tx'=>$tx['avg']]); exit;
}
$isStandalone = (isset($_GET['standalone']) && $_GET['standalone'] == '1') || (isset($_GET['s']) && $_GET['s'] == '1');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Traffic Dashboard</title>
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/echarts/echarts.min.js"></script>
    <style>
        :root { --primary-bg: #f4f6f8; --card-bg: #fff; --toolbar-bg: #fff; --border-color: #e0e4e8; --text-main: #1e293b; --text-dim: #64748b; --accent: #10b981; }
        body { font-family: Arial, Helvetica, sans-serif; background: var(--primary-bg); color: var(--text-main); margin: 0; font-size: 12px; container-type: inline-size; container-name: body-container; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-size: 18px !important; vertical-align: middle; }

        /* HEADER BREADCRUMB SYSTEMS */
        .pandora-header-top { background-color: #ffffff; border-bottom: 1px solid #e0e4e8; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 25px; z-index: 10; }
        .header-logo { height: 24px; width: auto; }
        .header-divider { width: 1px; height: 28px; background-color: #dce1e5; margin: 0 20px; }
        .header-title-box { display: flex; flex-direction: column; line-height: 1.2; margin-right: 40px; }
        .header-title-box .main-title { font-size: 14px !important; font-weight: normal !important; color: #0b1a26 !important; }
        .header-title-box .sub-title { font-size: 10px !important; color: #64748b !important; }
        .nav-icon-btn { color: #4a5568 !important; text-decoration: none; display: flex; align-items: center; justify-content: center; height: 36px; width: 36px; border-radius: 50%; transition: 0.2s; border:none; background:transparent; cursor:pointer;}
        .nav-icon-btn:hover { background: #f1f5f9; }

        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #e0e4e8; }
        .breadcrumb-box { display: flex; flex-direction: column; }
        .page-breadcrumb { font-size: 11px !important; color: #64748b !important; margin-bottom: 4px; font-weight: normal !important; text-transform: uppercase; letter-spacing: 0.5px; }
        .page-title { font-size: 18px !important; color: #0b1a26 !important; margin: 0; font-weight: 600 !important; line-height: 1.1; display:flex; align-items:center; gap:8px;}
        .breadcrumb-link { cursor: pointer; color: #1976d2 !important; text-decoration: none; transition:0.2s;}
        .breadcrumb-link:hover { text-decoration: underline; color:#0d47a1!important; }

        /* Top controls for alignment */
        .top-controls { display: flex; flex-direction: row; flex-wrap: nowrap; gap: 10px; align-items: center; justify-content: flex-end; }

        <?php if ($isStandalone): ?>
        .pandora-header-top, .pandora-header-bottom, #detailView > .header-bottom { display: none !important; }
        .main-content { padding: 20px 25px !important; }
        button[onclick="goBack()"] { display: none !important; }
        <?php endif; ?>

        /* Hide inner detail header-bottom when not standalone */
        body:not(.is-standalone-view) #detailView > .header-bottom {
            display: none !important;
        }
        
        .header-top { background: #fff; height: 50px; display: flex; align-items: center; padding: 0 25px; border-bottom: 1px solid #e0e4e8; }
        .header-bottom { padding: 10px 25px; background: #fff; border-bottom: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; transition: all 0.2s; }
        .page-title { font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px; margin: 0; color: #0f172a; }
        .breadcrumb { font-size: 9px; color: var(--text-dim); text-transform: uppercase; margin-bottom: 0px; letter-spacing: 0.8px; opacity: 0.8; }
        @media (max-width: 768px) {
            .header-bottom { padding: 8px 15px; flex-direction: column; align-items: flex-start; gap: 5px; }
            .page-title { font-size: 13px; }
            .breadcrumb { font-size: 8px; }
        }

        .btn-create { background: #004d40; color: #fff; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
        .btn-create:hover { background: #00332a; }

        .main-content { padding: 20px 25px; overflow-x: auto; }
        @media (max-width: 768px) {
            .main-content { padding: 15px; }
            table.master-table td, table.master-table th { padding: 10px 12px; font-size: 11px; }
        }
        .card { background: #fff; border-radius: 8px; border: 1px solid var(--border-color); box-shadow: 0 2px 4px rgba(0,0,0,0.02); overflow: hidden; }
        
        table.master-table { width: 100%; border-collapse: collapse; }
        
        /* Master List (Fixed Size) */
        #masterView table.master-table th { font-size: 10px; padding: 12px 15px; }
        #masterView table.master-table td { font-size: 12px; padding: 12px 15px; }

        /* Detail Dashboard (Customizable Size & Resizable Column Layout) */
        #detailView table.master-table {
            table-layout: fixed;
            width: 100%;
        }
        #detailView table.master-table th {
            font-size: calc(var(--table-font-size, 12px) - 2px);
            position: relative;
        }
        #detailView table.master-table td {
            font-size: var(--table-font-size, 12px);
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .col-resize-handle {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            width: 6px;
            cursor: col-resize;
            user-select: none;
            z-index: 10;
        }
        .col-resize-handle:hover,
        .col-resize-handle.resizing {
            background: rgba(0, 77, 64, 0.25);
            border-right: 2px solid #004d40;
        }

        table.master-table th { background: #f8fafc; padding: 10px 15px; text-align: left; color: var(--text-dim); text-transform: uppercase; border-bottom: 1px solid var(--border-color); }
        table.master-table td { padding: 10px 15px; border-bottom: 1px solid #f1f5f9; }
        table.master-table tr:hover td { background: #fcfdfe; }
        table.master-table th:last-child, table.master-table td:last-child { width: 1%; white-space: nowrap; padding-right: 25px; text-align: right; }

        #detailView table.master-table th:first-child,
        #detailView table.master-table td:first-child {
            text-align: center;
        }
        #detailView table.master-table th:nth-child(5),
        #detailView table.master-table td:nth-child(5) {
            text-align: center;
        }
        #detailView table.master-table th:nth-child(5) > div,
        #detailView table.master-table td:nth-child(5) > div {
            justify-content: center;
        }
        #detailView table.master-table th.col-actions,
        #detailView table.master-table td.col-actions {
            width: 8% !important;
            white-space: nowrap;
            padding: 10px 35px 10px 25px !important;
            text-align: center !important;
        }
        .dash-link { color: #1976d2; text-decoration: none; font-weight: 600; }
        .dash-link:hover { text-decoration: underline; }
        
        /* Relational Scaling */
        .sub-text { font-size: 0.85em; color: var(--text-dim); margin-top: 2px; }
        .pct-text { font-size: 0.8em; opacity: 0.7; font-weight: normal; margin-left: 4px; }
        .traffic-bar { height: calc(var(--table-font-size, 12px) / 3); min-height: 4px; max-height: 10px; background: #f1f5f9; border-radius: 10px; margin-top: 5px; overflow: hidden; width: calc(var(--table-font-size, 12px) * 5.5); }
        .table-icon { font-size: calc(var(--table-font-size, 12px) + 4px) !important; }

        .toolbar { display: flex; flex-direction: column; background: #fff; border-bottom: 1px solid #e0e4e8; padding: 0 !important; }
        .toolbar-header { display: flex; align-items: center; justify-content: space-between; padding: 10px 25px; gap: 12px; box-sizing: border-box; width: 100%; min-height: 52px; }
        .toolbar-header-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .toolbar-header-right { display: flex; align-items: center; }
        
        .toolbar-collapse { 
            max-height: 0; 
            overflow: hidden; 
            transition: max-height 0.25s ease-out; 
            width: 100%; 
            box-sizing: border-box;
        }
        .toolbar-collapse.show { 
            max-height: 300px; 
        }
        .toolbar-collapse-content { 
            padding: 15px 25px; 
            border-top: 1px solid #f1f5f9; 
            display: flex; 
            flex-direction: column; 
            gap: 12px; 
            box-sizing: border-box; 
            width: 100%;
            background: #f8fafc;
        }

        .toolbar-left, .toolbar-right { display:flex; align-items:center; gap:10px; flex-wrap: wrap; }
        .toolbar-item { display: flex; align-items: center; }
        
        .refresh-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 11px;
            color: #c2410c;
            font-weight: 500;
        }
        .refresh-status span {
            font-size: 11px;
            white-space: nowrap;
        }
        #countdown_text {
            color: #ea580c;
            font-weight: 600;
        }
        .refresh-select {
            background: transparent;
            border: none;
            font-size: 11px;
            color: #c2410c;
            font-weight: 600;
            cursor: pointer;
            outline: none;
            padding: 0;
        }
        .refresh-select option {
            background: #fff;
            color: #1e293b;
        }

        #f_search { width: 0; opacity: 0; padding: 0; border: none; transition: all 0.3s ease; overflow: hidden; white-space: nowrap; }
        #f_search.active { width: 180px; opacity: 1; padding: 0 10px; border: 1px solid #dce1e5; margin-left: 8px; }

        @container body-container (max-width: 1024px) {
            .toolbar-collapse-content .btn-text { display: none !important; }
            .toolbar-collapse-content .btn-neutral { padding: 0; width: 34px; height: 34px; min-width: 34px; justify-content: center; }
        }

        @container body-container (max-width: 600px) {
            .toolbar-header { padding: 8px 15px; }
            .toolbar-header-left { gap: 8px; }
            .toolbar-collapse-content { padding: 10px 15px; }
            .btn-toggle-expand .btn-text { display: none !important; }
            .btn-toggle-expand { padding: 0; width: 34px; height: 34px; min-width: 34px; justify-content: center; }
            #f_search.active { width: 150px; }
        }
        .toolbar-select, .btn-neutral, .threshold-input { height: 32px; box-sizing: border-box; }
        .threshold-input { width: 50px; border: 1px solid #dce1e5; border-radius: 4px; padding: 0 5px; font-size: 12px; text-align: center; }
        .toolbar-label { font-size:11px; color:var(--text-dim); text-transform:uppercase; margin-right:8px; }
        .toolbar-select { background:#fff; border:1px solid #dce1e5; border-radius:4px; padding:0 10px; font-size:13px; color:#1e293b; outline:none; transition: all 0.2s; max-width: 180px; }
        .btn-neutral { background:#fff; color:#1e293b; border:1px solid #dce1e5; padding:0 12px; border-radius:4px; cursor:pointer; font-size:13px; font-weight:600; display:flex; align-items:center; justify-content:center; gap:6px; transition: all 0.2s; margin: 0; }
        .btn-neutral:hover { background: #f8fafc; border-color: #94a3b8; }
        .btn-neutral .material-symbols-outlined { font-size: 18px!important; }

        .dropdown { position: relative; display: inline-block; }
        .dropdown-content { display: none; position: absolute; right: 0; top: 100%; background-color: #fff; min-width: 180px; box-shadow: 0px 8px 24px rgba(0,0,0,0.12); border-radius: 6px; border: 1px solid #dce1e5; z-index: 1000; padding: 5px 0; }
        .dropdown-content::before { content: ""; position: absolute; top: -10px; left: 0; right: 0; height: 10px; background: transparent; }
        .dropdown-content a { color: #475569; padding: 10px 15px; text-decoration: none; display: flex; align-items: center; gap: 10px; font-size: 12px; font-weight: 600; }
        .dropdown-content a:hover { background-color: #f8fafc; color: var(--accent); }
        .dropdown:hover .dropdown-content { display: block; }

        .col-ok { color: #10b981; } .col-warn { color: #f59e0b; } .col-crit { color: #ef4444; }
        .bg-ok { background: #10b981; } .bg-warn { background: #f59e0b; } .bg-crit { background: #ef4444; }
        .traffic-bar { height: 4px; background: #f1f5f9; border-radius: 2px; overflow: hidden; margin-top: 5px; width: 80px; }
        .traffic-fill { height: 100%; transition: width 0.4s; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: flex-start; justify-content: center; z-index: 1000; overflow-y: auto; padding: 40px 20px; box-sizing: border-box; }
        .modal-box { background: #fff; width: 500px; border-radius: 8px; padding: 25px; box-shadow: 0 15px 35px rgba(0,0,0,0.2); box-sizing: border-box; margin: auto; min-width: 0; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 12px; color: var(--text-dim); margin-bottom: 5px; text-transform: uppercase; }
        .form-input { width: 100%; height: 36px; border: 1px solid #dce1e5; border-radius: 4px; padding: 0 10px; font-size: 13px; }
        .form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }

        .status-badge { padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .badge-ok { background: #ecfdf5; color: #059669; }
        .badge-warn { background: #fff7ed; color: #ea580c; }
        .badge-crit { background: #fef2f2; color: #dc2626; }
        .category-badge { padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 600; background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; display: inline-block; white-space: nowrap; }

        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            padding: 0;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            margin-left: 5px;
            box-sizing: border-box;
        }
        .btn-action:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #0f172a;
        }
        .btn-action .material-symbols-outlined {
            font-size: 16px !important;
        }
        .btn-action.btn-delete {
            color: #ef4444;
            border-color: #fee2e2;
        }
        .btn-action.btn-delete:hover {
            background: #fef2f2;
            border-color: #fca5a5;
            color: #dc2626;
        }
        
        /* Tombol hapus interface dalam tabel */
        .btn-icon-only {
            background: none;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            cursor: pointer;
            padding: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .btn-icon-only:hover {
            background: #fef2f2;
            border-color: #fca5a5;
        }
        .btn-icon-only.delete-icon {
            color: #ef4444;
        }
        .btn-icon-only.delete-icon:hover {
            color: #dc2626;
        }

        /* RESPONSIVE TABLE & THREE-DOT DROPDOWN MENUS */
        .card-table-wrapper {
            container-type: inline-size;
            container-name: table-container;
        }
        
        .action-buttons-desktop {
            display: inline-flex;
            gap: 5px;
            justify-content: flex-end;
            align-items: center;
        }
        
        .action-dropdown-mobile {
            display: none;
            position: relative;
        }
        
        .dropdown-menu-custom {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            background-color: #ffffff;
            min-width: 160px;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            z-index: 1100;
            padding: 4px 0;
            margin-top: 4px;
        }
        
        .dropdown-menu-custom.show {
            display: block;
        }
        
        .dropdown-menu-custom a {
            color: #475569;
            padding: 8px 16px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 500;
            text-align: left;
            transition: background 0.1s;
        }
        
        .dropdown-menu-custom a:hover {
            background-color: #f1f5f9;
            color: #0f172a;
        }
        
        .dropdown-menu-custom a.delete-item {
            color: #ef4444;
        }
        
        .dropdown-menu-custom a.delete-item:hover {
            background-color: #fef2f2;
            color: #dc2626;
        }
        
        .dropdown-menu-custom a .material-symbols-outlined {
            font-size: 16px !important;
        }
        
        /* Media query for mobile viewports */
        @media (max-width: 768px) {
            .action-buttons-desktop {
                display: none !important;
            }
            .action-dropdown-mobile {
                display: inline-block !important;
            }
        }
        @media (max-width: 600px) {
            .col-category, .col-speed {
                display: none !important;
            }
        }
        
        /* Container query for narrow iframe embedded views */
        @container table-container (max-width: 800px) {
            .action-buttons-desktop {
                display: none !important;
            }
            .action-dropdown-mobile {
                display: inline-block !important;
            }
        }
        @container table-container (max-width: 600px) {
            .col-category, .col-speed {
                display: none !important;
            }
        }
    </style>
</head>
<body class="<?= $isStandalone ? 'is-standalone-view' : '' ?>">

<div class="pandora-header-top">
    <div class="header-left">
        <img src="<?= htmlspecialchars($PANDORA_BASE_URL) ?>/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box"><span class="main-title">Pandora FMS</span><span class="sub-title">Custom Dashboard Portal</span></div>
    </div>
    <div class="header-right"><a href="<?= htmlspecialchars($PANDORA_BASE_URL) ?>/index.php" class="nav-icon-btn"><span class="material-symbols-outlined">home</span></a></div>
</div>

<div class="pandora-header-bottom">
    <div class="breadcrumb-box">
        <span class="page-breadcrumb" id="mainBreadcrumb"><?= h($dynamic_breadcrumb) ?></span>
        <h1 class="page-title" id="pageMainTitle">Traffic Dashboard</h1>
    </div>
    
    <div class="top-controls" id="listTopControls">
        <button class="btn-create" onclick="openCreateModal()"><span class="material-symbols-outlined">add</span> Create Dashboard</button>
        <input type="file" id="importBackupFile" style="display:none" onchange="importDashboardConfig(event)">
    </div>
</div>

<div id="masterView">
    <div class="main-content card-table-wrapper">
        <div class="card">
            <div style="overflow-x: auto; width: 100%;">
                <table class="master-table">
                    <thead><tr><th>Dashboard Name</th><th>Target Group</th><th>Target Node</th><th>Actions</th></tr></thead>
                    <tbody id="masterTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="detailView" style="display:none;">
    <div class="header-bottom">
        <div><div class="breadcrumb">Interface Traffic</div><h1 class="page-title"><button class="btn-dash" onclick="goBack()" style="background:none; border:none; cursor:pointer; padding:0; margin-right:5px;"><span class="material-symbols-outlined" style="font-size:24px!important; color:#004d40;">arrow_back</span></button> <span id="detailDashName">DASHBOARD NAME</span></h1></div>
    </div>
    <div class="toolbar">
        <div class="toolbar-header">
            <div class="toolbar-header-left">
                <button class="btn-neutral btn-back" onclick="goBack()" title="Back to List" style="height:32px; padding:0 10px;"><span class="material-symbols-outlined" style="font-size:18px!important; color:#1e293b;">arrow_back</span></button>
                <span id="last_update_text" style="font-size:11px; color:var(--text-dim); white-space:nowrap;">-</span>
                <div class="refresh-status">
                    <span class="material-symbols-outlined" style="font-size:14px!important; color:#ea580c; vertical-align:middle; line-height:1;">update</span>
                    <span id="countdown_text">Wait...</span>
                    <span style="color:#fdba74;">|</span>
                    <select id="f_refresh" class="refresh-select" onchange="setupTimer()">
                        <option value="0">Off</option>
                        <option value="30">30s</option>
                        <option value="60" selected>1m</option>
                        <option value="300">5m</option>
                    </select>
                </div>
            </div>
            <div class="toolbar-header-right">
                <button class="btn-neutral btn-toggle-expand" onclick="toggleToolbarExpand()" title="Filters & Actions" style="height:32px; padding:0 10px; display:flex; align-items:center; gap:6px;">
                    <span class="material-symbols-outlined" id="expand_icon">tune</span>
                    <span class="btn-text">Filters & Tools</span>
                </button>
            </div>
        </div>
        <div class="toolbar-collapse" id="toolbarCollapse">
            <div class="toolbar-collapse-content">
                <div class="toolbar-left">
                    <div class="toolbar-item"><select id="f_unit" class="toolbar-select" onchange="fetchData()"><option value="Auto" selected>Auto</option><option value="Mbps">Mbps</option><option value="Gbps">Gbps</option><option value="Bps">B/s</option><option value="MBps">MB/s</option><option value="GBps">GB/s</option></select></div>
                    <div class="toolbar-item"><select id="f_sort" class="toolbar-select" onchange="fetchData()"><option value="default">Default</option><option value="rx_desc">Largest RX (Max)</option><option value="rx_asc">Smallest RX (Min)</option><option value="tx_desc">Largest TX (Max)</option><option value="tx_asc">Smallest TX (Min)</option><option value="rx_pct_desc">Largest RX % (Max)</option><option value="rx_pct_asc">Smallest RX % (Min)</option><option value="tx_pct_desc">Largest TX % (Max)</option><option value="tx_pct_asc">Smallest TX % (Min)</option><option value="top_10_rx_pct">Top 10 RX (%)</option><option value="top_10_tx_pct">Top 10 TX (%)</option></select></div>
                    <div class="toolbar-item"><select id="f_speed_filter" class="toolbar-select" onchange="fetchData()"><option value="all" selected>All Speeds</option><option value="gbps">Gbps Only</option><option value="mbps">Mbps Only</option><option value="gbps_mbps">Gbps & Mbps</option><option value="na">N/A Only</option></select></div>
                    <div class="toolbar-item" id="search_wrapper" style="display:flex; align-items:center;">
                        <button class="btn-neutral" style="width:32px; padding:0; height:32px;" onclick="toggleSearch()" title="Search">
                            <span class="material-symbols-outlined">search</span>
                        </button>
                        <input type="text" id="f_search" class="toolbar-select" placeholder="Filter..." onkeyup="if(event.key==='Enter') fetchData()">
                    </div>
                </div>
                <div class="toolbar-right">
                    <button id="btnSettings" class="btn-neutral" onclick="openSettingsModal()" title="Threshold & Display Settings" style="display:none; height:32px;">
                        <span class="material-symbols-outlined">settings</span> <span class="btn-text">Settings</span>
                    </button>
                    <button id="btnHidden" class="btn-neutral" onclick="openHiddenModal()" title="Manage Hidden Interfaces" style="display:none; height:32px;">
                        <span class="material-symbols-outlined">visibility_off</span> <span class="btn-text">Hidden</span>
                    </button>
                    <div class="dropdown">
                        <button class="btn-neutral" style="height:32px; padding:0 12px;" title="Export Data"><span class="material-symbols-outlined" style="font-size:16px!important; color:#64748b;">download</span> <span class="btn-text">Export</span> <span class="material-symbols-outlined" style="font-size:12px!important;">expand_more</span></button>
                        <div class="dropdown-content">
                            <a href="?api=export&format=csv"><span class="material-symbols-outlined">table_view</span> Download as CSV</a>
                            <a href="?api=export&format=txt"><span class="material-symbols-outlined">description</span> Download as TXT</a>
                        </div>
                    </div>
                    <button class="btn-neutral" style="height:32px; padding:0 12px;" onclick="copyShareLink()" title="Share Dashboard"><span class="material-symbols-outlined" style="font-size:16px!important; color:#64748b;">share</span> <span class="btn-text">Share</span></button>
                    <button class="btn-neutral" style="height:32px; padding:0 12px;" onclick="fetchData()" title="Refresh Data"><span class="material-symbols-outlined" style="font-size:16px!important; color:#64748b;">sync</span> <span class="btn-text">Refresh</span></button>
                </div>
            </div>
        </div>
    </div>
        <div class="main-content card-table-wrapper">
            <div class="card">
                <div style="overflow-x: auto; width: 100%;">
                    <table class="master-table"><thead><tr><th style="width: 8%;">Status</th><th style="width: 15%;">Agent</th><th style="width: 18%;">Interface</th><th class="col-category" style="width: 12%;">Category</th><th class="col-speed" style="width: 10%;">Speed</th><th style="width: 15%;">RECEIVE (RX)</th><th style="width: 15%;">TRANSMIT (TX)</th><th class="col-actions" style="width: 8%;">Actions</th></tr></thead><tbody id="detailTableBody"></tbody></table>
                </div>
                <div id="paginationControls" style="padding:15px 20px; border-top:1px solid #e0e4e8; background:#f8fafc; display:flex; justify-content:space-between; align-items:center;"></div>
            </div>
        </div>
</div>

<div class="modal-overlay" id="chartModal">
    <div class="modal-box" style="width:900px; max-width:95%; min-width:0; height:600px; max-height:90vh; background:#fff; border:1px solid #e0e4e8; box-shadow: 0 10px 40px rgba(0,0,0,0.15); padding: 0; overflow: hidden; display: flex; flex-direction: column;">
        <div style="padding:15px 20px; border-bottom:1px solid #e0e4e8; display:flex; justify-content:space-between; align-items:center; flex-shrink: 0;">
            <div>
                <strong id="chartTitle" style="color:#1e293b; font-size:16px;">History</strong>
                <div id="chartStats" style="font-size:11px; margin-top:4px; display:flex; gap:15px;">
                    <span style="color:#10b981;">AVG RX: <b id="avgRxText">-</b></span>
                    <span style="color:#3b82f6;">AVG TX: <b id="avgTxText">-</b></span>
                </div>
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
                <select id="chartRange" class="toolbar-select" style="height:28px; font-size:11px;" onchange="reloadChart()">
                    <option value="1">Last 1 Hour</option>
                    <option value="3">Last 3 Hours</option>
                    <option value="6">Last 6 Hours</option>
                    <option value="24" selected>Last 24 Hours</option>
                    <option value="168">Last 7 Days</option>
                    <option value="custom">Custom Range</option>
                </select>
                <div style="display:flex; border:1px solid #dce1e5; border-radius:4px; overflow:hidden;">
                    <button onclick="manualZoom(1.1)" class="btn-neutral" style="border:none; border-right:1px solid #dce1e5; padding:4px 8px; border-radius:0; height:28px;" title="Zoom In"><span class="material-symbols-outlined" style="font-size:16px!important;">zoom_in</span></button>
                    <button onclick="manualZoom(0.9)" class="btn-neutral" style="border:none; border-right:1px solid #dce1e5; padding:4px 8px; border-radius:0; height:28px;" title="Zoom Out"><span class="material-symbols-outlined" style="font-size:16px!important;">zoom_out</span></button>
                    <button onclick="resetZoom()" class="btn-neutral" style="border:none; padding:4px 8px; border-radius:0; height:28px; font-size:11px;">Reset</button>
                </div>
                <button onclick="document.getElementById('chartModal').style.display='none'" style="background:none; border:none; color:#64748b; cursor:pointer;"><span class="material-symbols-outlined">close</span></button>
            </div>
        </div>
        <div id="customRangeInputs" style="display:none; padding:10px 20px; background:#f8fafc; border-bottom:1px solid #e0e4e8; align-items:center; gap:10px; flex-shrink: 0;">
            <span style="font-size:11px; font-weight:600; color:#64748b;">FROM</span>
            <input type="datetime-local" id="c_from" class="toolbar-select" style="height:28px; font-size:11px;">
            <span style="font-size:11px; font-weight:600; color:#64748b;">TO</span>
            <input type="datetime-local" id="c_to" class="toolbar-select" style="height:28px; font-size:11px;">
            <button class="btn-create" style="height:28px; padding:0 12px; font-size:11px;" onclick="reloadChart()">Apply Range</button>
        </div>
        <div style="padding:20px; flex: 1; min-height: 250px; display: flex; flex-direction: column; overflow: hidden; box-sizing: border-box; width: 100%;"><div id="trafficCanvas" style="width:100%; height:100%; min-height: 0;"></div></div>
        <div style="padding:10px 20px; font-size:10px; color:#94a3b8; text-align:center; border-top:1px solid #f1f5f9; flex-shrink: 0;">Scroll to Zoom • Drag to Pan</div>
    </div>
</div>

<div class="modal-overlay" id="settingsModal">
    <div class="modal-box" style="width:350px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="margin:0; font-size:16px;">Dashboard Settings</h3>
            <button onclick="closeSettingsModal()" style="background:none; border:none; cursor:pointer;"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div class="form-group">
            <label>Traffic Thresholds (%)</label>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                <div>
                    <span style="font-size:11px; color:var(--text-dim);">Warning</span>
                    <input type="number" id="f_warn" class="form-input" value="70">
                </div>
                <div>
                    <span style="font-size:11px; color:var(--text-dim);">Critical</span>
                    <input type="number" id="f_crit" class="form-input" value="80">
                </div>
            </div>
        </div>
        <div class="form-group">
            <label>Display Preference</label>
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <span style="font-size:13px;">Table Font Size</span>
                <div style="display:flex; align-items:center; gap:5px;">
                    <input type="number" id="f_fontsize" class="form-input" style="width:60px; text-align:center;" value="12" min="8" max="24" oninput="applyFontSize()">
                    <span style="font-size:12px; color:var(--text-dim);">px</span>
                </div>
            </div>
            <div style="display:flex; align-items:center; justify-content:space-between; margin-top:12px;">
                <span style="font-size:13px;">Row Limit</span>
                <div style="display:flex; align-items:center; gap:5px;">
                    <input type="number" id="f_perpage" class="form-input" style="width:60px; text-align:center;" value="20" min="1" max="100">
                    <span style="font-size:12px; color:var(--text-dim);">rows</span>
                </div>
            </div>
            <div style="display:flex; align-items:center; justify-content:space-between; margin-top:12px;">
                <span style="font-size:13px;">Show Unit Suffixes</span>
                <input type="checkbox" id="f_showunits" checked style="width:18px; height:18px; cursor:pointer;">
            </div>
        </div>
        <div class="form-group" style="margin-top:15px; border-top: 1px solid #f1f5f9; padding-top:12px;">
            <label style="font-weight:600; color:#475569; font-size:12px;">ENABLED UNITS IN FILTER</label>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-top:8px;">
                <label style="display:flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer; font-size:12px;">
                    <input type="checkbox" id="unit_opt_Auto" value="Auto" checked> Auto
                </label>
                <label style="display:flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer; font-size:12px;">
                    <input type="checkbox" id="unit_opt_Mbps" value="Mbps" checked> Mbps
                </label>
                <label style="display:flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer; font-size:12px;">
                    <input type="checkbox" id="unit_opt_Gbps" value="Gbps" checked> Gbps
                </label>
                <label style="display:flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer; font-size:12px;">
                    <input type="checkbox" id="unit_opt_Bps" value="Bps" checked> B/s
                </label>
                <label style="display:flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer; font-size:12px;">
                    <input type="checkbox" id="unit_opt_MBps" value="MBps" checked> MB/s
                </label>
                <label style="display:flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer; font-size:12px;">
                    <input type="checkbox" id="unit_opt_GBps" value="GBps" checked> GB/s
                </label>
            </div>
        </div>
        <div class="form-group" style="margin-top:15px; border-top: 1px solid #f1f5f9; padding-top:12px;">
            <label style="font-weight:600; color:#475569; font-size:12px;">ENABLED CATEGORIES IN FILTER</label>
            <div id="category_filter_container" style="display:grid; grid-template-columns: 1fr 1fr; gap:8px; margin-top:8px; max-height:120px; overflow-y:auto;"></div>
        </div>
        <div class="form-actions" style="margin-top:30px;">
            <button class="btn-neutral" onclick="closeSettingsModal()">Cancel</button>
            <button class="btn-create" onclick="saveSettings()">Save Configuration</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="createModal">
    <div class="modal-box">
        <h3 id="modalTitle" style="margin-top:0;">Create Dashboard</h3>
        <div class="form-group"><label>Name</label><input type="text" id="m_name" class="form-input" placeholder="e.g. Core Routers"></div>
        <div class="form-group"><label>Target Group</label><select id="m_group" class="form-input" onchange="loadAgentOptions()"></select></div>
        <div class="form-group"><label>Target Node (Optional)</label><select id="m_agent" class="form-input"></select></div>
        <div class="form-actions">
            <button class="btn-create" style="background:#64748b;" onclick="closeCreateModal()">Cancel</button>
            <button id="btnSubmitModal" class="btn-create" onclick="saveNewDashboard()">Save Dashboard</button>
        </div>
    </div>
</div>

<!-- Modal untuk Hidden Interfaces -->
<div class="modal-overlay" id="hiddenModal">
    <div class="modal-box" style="width:500px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
            <h3 style="margin:0;">Hidden Interfaces</h3>
            <button onclick="closeHiddenModal()" style="background:none; border:none; cursor:pointer;"><span class="material-symbols-outlined">close</span></button>
        </div>
        <div id="hiddenList" style="max-height:300px; overflow-y:auto;"></div>
        <div class="form-actions" style="margin-top:20px;">
            <button class="btn-create" style="background:#ef4444;" onclick="clearAllHidden()">Restore All</button>
        </div>
    </div>
</div>

<script>
    const CSRF_TOKEN = '<?= $csrf_token ?>';
    const IS_STANDALONE = <?= $isStandalone ? 'true' : 'false' ?>;
    const DIRECT_SCRIPT_URL = '<?= $directScriptUrl ?>';
    let masterDashboards = [], currentDashId = '', currentAgents = [], currentPage = 1, chartInstance = null;
    let timerInterval = null, countdown = 60, editId = null, availableCategories = [];

    const ALL_UNITS = [
        { value: 'Auto', label: 'Auto' },
        { value: 'Mbps', label: 'Mbps' },
        { value: 'Gbps', label: 'Gbps' },
        { value: 'Bps', label: 'B/s' },
        { value: 'MBps', label: 'MB/s' },
        { value: 'GBps', label: 'GB/s' }
    ];

    function updateUnitDropdown(enabledValues) {
        const select = document.getElementById('f_unit');
        if (!select) return;
        
        const currentValue = select.value;
        select.innerHTML = '';
        
        const activeUnits = ALL_UNITS.filter(u => !enabledValues || enabledValues.length === 0 || enabledValues.includes(u.value));
        
        activeUnits.forEach(u => {
            const opt = new Option(u.label, u.value);
            if (u.value === currentValue) opt.selected = true;
            select.add(opt);
        });
        
        if (!activeUnits.some(u => u.value === currentValue) && activeUnits.length > 0) {
            select.value = activeUnits[0].value;
        }
    }

    function renderCategoryFilterUI() {
        const container = document.getElementById('category_filter_container');
        if (!container) return;
        let html = '';
        availableCategories.forEach(c => {
            html += `
                <label style="display:flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer; font-size:12px;">
                    <input type="checkbox" id="cat_opt_${c.id}" value="${c.name}" checked> ${c.name}
                </label>
            `;
        });
        html += `
            <label style="display:flex; align-items:center; gap:6px; font-weight:normal; cursor:pointer; font-size:12px;">
                <input type="checkbox" id="cat_opt_na" value="N/A" checked> N/A (Unassigned)
            </label>
        `;
        container.innerHTML = html;
    }

    function syncEnabledCategoriesUI(list) {
        availableCategories.forEach(c => {
            const cb = document.getElementById('cat_opt_' + c.id);
            if (cb) cb.checked = (list === null) || list.includes(c.name);
        });
        const cbNA = document.getElementById('cat_opt_na');
        if (cbNA) cbNA.checked = (list === null) || list.includes('N/A');
    }

    function syncEnabledUnitsUI(list) {
        ['Auto', 'Mbps', 'Gbps', 'Bps', 'MBps', 'GBps'].forEach(u => {
            const cb = document.getElementById('unit_opt_' + u);
            if (cb) cb.checked = !list || list.length === 0 || list.includes(u);
        });
    }

    function decodeHtml(str) {
        if (!str) return '';
        var txt = document.createElement("textarea");
        txt.innerHTML = str;
        return txt.value;
    }

    function toggleSearch() {
        const input = document.getElementById('f_search');
        input.classList.toggle('active');
        if(input.classList.contains('active')) input.focus();
    }

    function toggleToolbarExpand() {
        const collapse = document.getElementById('toolbarCollapse');
        const icon = document.getElementById('expand_icon');
        const isShown = collapse.classList.contains('show');
        if (isShown) {
            collapse.classList.remove('show');
            icon.innerText = 'tune';
            localStorage.setItem('pfms_toolbar_expand', '0');
        } else {
            collapse.classList.add('show');
            icon.innerText = 'close';
            localStorage.setItem('pfms_toolbar_expand', '1');
        }
    }

    async function init() {
        const r = await fetch('?api=load_config'); masterDashboards = await r.json();
        masterDashboards.forEach(d => { if (!d.hidden_interfaces) d.hidden_interfaces = []; });
        const rg = await fetch('?api=groups'); const groups = await rg.json();
        const gsel = document.getElementById('m_group'); groups.forEach(g => gsel.add(new Option(decodeHtml(g.name), g.id)));

        try {
            const rc = await fetch('?api=categories');
            availableCategories = await rc.json();
            renderCategoryFilterUI();
        } catch(e) {
            console.error("Failed to load categories:", e);
        }

        const params = new URLSearchParams(window.location.search);
        
        // Load from LocalStorage first (User Defaults)
        const savedSettings = IS_STANDALONE ? {} : JSON.parse(localStorage.getItem('pfms_dashboard_settings') || '{}');
        if (savedSettings.warn) document.getElementById('f_warn').value = savedSettings.warn;
        if (savedSettings.crit) document.getElementById('f_crit').value = savedSettings.crit;
        if (savedSettings.fs) document.getElementById('f_fontsize').value = savedSettings.fs;
        if (savedSettings.show_units !== undefined) document.getElementById('f_showunits').checked = savedSettings.show_units;
        if (savedSettings.per_page) document.getElementById('f_perpage').value = savedSettings.per_page;

        let activeUnitsList = savedSettings.enabled_units || null;

        // URL Params override saved settings
        if(params.has('unit')) document.getElementById('f_unit').value = params.get('unit');
        if(params.has('sort')) document.getElementById('f_sort').value = params.get('sort');
        if(params.has('speed_filter')) document.getElementById('f_speed_filter').value = params.get('speed_filter');
        if(params.has('search')) {
            const fs = document.getElementById('f_search');
            fs.value = params.get('search');
            fs.classList.add('active');
        }
        if(params.has('warn')) document.getElementById('f_warn').value = params.get('warn');
        if(params.has('crit')) document.getElementById('f_crit').value = params.get('crit');
        if(params.has('fs')) document.getElementById('f_fontsize').value = params.get('fs');
        if(params.has('show_units')) document.getElementById('f_showunits').checked = params.get('show_units') !== '0';
        if(params.has('per_page')) document.getElementById('f_perpage').value = params.get('per_page');
        let activeCategoriesList = savedSettings.enabled_categories || null;
        if(params.has('enabled_units')) activeUnitsList = params.get('enabled_units').split(',');
        if(params.has('enabled_categories')) activeCategoriesList = params.get('enabled_categories').split(',');
        
        syncEnabledUnitsUI(activeUnitsList);
        updateUnitDropdown(activeUnitsList);
        syncEnabledCategoriesUI(activeCategoriesList);
        applyFontSize();

        const dashId = params.get('dash_id');
        if (dashId) openDashboard(dashId, true); else renderMasterList();
    }

    function renderMasterList() {
        if(timerInterval) clearInterval(timerInterval);
        document.getElementById('masterView').style.display = 'block';
        document.getElementById('detailView').style.display = 'none';
        document.getElementById('btnSettings').style.display = 'none';
        document.getElementById('btnHidden').style.display = 'none';
        document.getElementById('search_wrapper').style.display = 'none';
        
        const listCtrls = document.getElementById('listTopControls');
        if(listCtrls) listCtrls.style.display = 'flex';
        
        const pageTitle = document.getElementById('pageMainTitle');
        if(pageTitle) pageTitle.innerText = "Traffic Dashboard";

        const mainBreadcrumb = document.getElementById('mainBreadcrumb');
        if (mainBreadcrumb) mainBreadcrumb.innerText = "PANDORA CONSOLE / CUSTOM / PANEL / TRAFFIC DASHBOARD";
        
        // Reset to default font for master list
        document.getElementById('detailView').style.setProperty('--table-font-size', '12px');

        const body = document.getElementById('masterTableBody');
        body.innerHTML = masterDashboards.map(d => `<tr>
            <td><a href="#" class="dash-link" onclick="openDashboard('${d.id}')">${d.name}</a></td>
            <td>${d.group_name || 'All Groups'}</td>
            <td>${d.agent_name || 'All Nodes'}</td>
            <td class="action-cell" style="text-align:right;">
                <div class="action-buttons-desktop">
                    <button class="btn-action" onclick="openDashboard('${d.id}')" title="Open Dashboard">
                        <span class="material-symbols-outlined">visibility</span>
                    </button>
                    <button class="btn-action" onclick="editDashboard('${d.id}')" title="Configure">
                        <span class="material-symbols-outlined">settings</span>
                    </button>
                    <button class="btn-action" onclick="exportDashboardConfig('${d.id}')" title="Backup Dashboard Config">
                        <span class="material-symbols-outlined">download</span>
                    </button>
                    <button class="btn-action" onclick="triggerImport('${d.id}')" title="Load Dashboard Config">
                        <span class="material-symbols-outlined">upload</span>
                    </button>
                    <button class="btn-action" onclick="duplicateDashboardFromList('${d.id}')" title="Duplicate">
                        <span class="material-symbols-outlined">content_copy</span>
                    </button>
                    <button class="btn-action btn-delete" onclick="deleteDashboard('${d.id}')" title="Delete">
                        <span class="material-symbols-outlined">delete</span>
                    </button>
                </div>
                <div class="action-dropdown-mobile dropdown">
                    <button class="btn-icon-only btn-more" onclick="toggleActionDropdown(event, this)" title="Actions">
                        <span class="material-symbols-outlined">more_vert</span>
                    </button>
                    <div class="dropdown-menu-custom">
                        <a href="#" onclick="event.preventDefault(); openDashboard('${d.id}')">
                            <span class="material-symbols-outlined">visibility</span> Open Dashboard
                        </a>
                        <a href="#" onclick="event.preventDefault(); editDashboard('${d.id}')">
                            <span class="material-symbols-outlined">settings</span> Configure
                        </a>
                        <a href="#" onclick="event.preventDefault(); exportDashboardConfig('${d.id}')">
                            <span class="material-symbols-outlined">download</span> Backup Config
                        </a>
                        <a href="#" onclick="event.preventDefault(); triggerImport('${d.id}')">
                            <span class="material-symbols-outlined">upload</span> Load Config
                        </a>
                        <a href="#" onclick="event.preventDefault(); duplicateDashboardFromList('${d.id}')">
                            <span class="material-symbols-outlined">content_copy</span> Duplicate
                        </a>
                        <a href="#" class="delete-item" onclick="event.preventDefault(); deleteDashboard('${d.id}')">
                            <span class="material-symbols-outlined">delete</span> Delete
                        </a>
                    </div>
                </div>
            </td>
        </tr>`).join('') || '<tr><td colspan="4" style="text-align:center; padding:40px; color:#94a3b8;">No Dashboards Created Yet.</td></tr>';
    }

    function openDashboard(id, isInitial = false) {
        const d = masterDashboards.find(x => x.id === id); if(!d) return renderMasterList();
        currentDashId = id;
        document.getElementById('masterView').style.display = 'none';
        document.getElementById('detailView').style.display = 'block';
        if (IS_STANDALONE) {
            document.querySelectorAll('button[onclick="goBack()"]').forEach(btn => btn.style.setProperty('display', 'none', 'important'));
        }
        document.getElementById('btnSettings').style.display = 'flex';
        document.getElementById('btnHidden').style.display = 'flex';
        if (!d.hidden_interfaces) d.hidden_interfaces = [];
        document.getElementById('search_wrapper').style.display = 'flex';
        document.getElementById('detailDashName').innerText = d.name;

        const listCtrls = document.getElementById('listTopControls');
        if(listCtrls) listCtrls.style.display = 'none';

        const pageTitle = document.getElementById('pageMainTitle');
        if(pageTitle) pageTitle.innerText = d.name;

        const mainBreadcrumb = document.getElementById('mainBreadcrumb');
        if (mainBreadcrumb) {
            mainBreadcrumb.innerText = "PANDORA CONSOLE / CUSTOM / PANEL / TRAFFIC DASHBOARD / " + d.name.toUpperCase();
        }
        
        // Load per-dashboard settings
        const saved = IS_STANDALONE ? {} : JSON.parse(localStorage.getItem('pfms_settings_' + id) || '{}');
        const params = new URLSearchParams(window.location.search);

        const warn = (isInitial && params.has('warn')) ? params.get('warn') : (saved.warn || 70);
        const crit = (isInitial && params.has('crit')) ? params.get('crit') : (saved.crit || 80);
        const fs = (isInitial && params.has('fs')) ? params.get('fs') : (saved.fs || 12);
        const unit = (isInitial && params.has('unit')) ? params.get('unit') : (saved.unit || 'Auto');
        const sort = (isInitial && params.has('sort')) ? params.get('sort') : (saved.sort || 'default');
        const speed_filter = (isInitial && params.has('speed_filter')) ? params.get('speed_filter') : (saved.speed_filter || 'all');
        const search = (isInitial && params.has('search')) ? params.get('search') : (saved.search || '');
        const showUnits = (isInitial && params.has('show_units')) ? (params.get('show_units') !== '0') : (saved.show_units !== false);
        const perPage = (isInitial && params.has('per_page')) ? params.get('per_page') : (saved.per_page || 20);
        const refresh = (isInitial && params.has('refresh')) ? params.get('refresh') : (saved.refresh || '60');

        const enabledUnits = (isInitial && params.has('enabled_units')) ? params.get('enabled_units').split(',') : (saved.enabled_units || null);
        const enabledCategories = (isInitial && params.has('enabled_categories')) ? params.get('enabled_categories').split(',') : (saved.enabled_categories || null);

        document.getElementById('f_warn').value = warn;
        document.getElementById('f_crit').value = crit;
        document.getElementById('f_fontsize').value = fs;
        document.getElementById('f_unit').value = unit;
        document.getElementById('f_sort').value = sort;
        document.getElementById('f_speed_filter').value = speed_filter;
        document.getElementById('f_showunits').checked = showUnits;
        document.getElementById('f_perpage').value = perPage;
        document.getElementById('f_refresh').value = refresh;

        syncEnabledUnitsUI(enabledUnits);
        updateUnitDropdown(enabledUnits);
        syncEnabledCategoriesUI(enabledCategories);

        const searchEl = document.getElementById('f_search');
        searchEl.value = search;
        if (search) {
            searchEl.classList.add('active');
        } else {
            searchEl.classList.remove('active');
        }

        applyFontSize();

        const url = new URL(window.location); url.searchParams.set('dash_id', id); window.history.replaceState({}, '', url);
        if (window.parent && window.parent !== window) {
            try {
                const parentUrl = new URL(window.parent.location);
                parentUrl.searchParams.set('dash_id', id);
                window.parent.history.replaceState({}, '', parentUrl);
            } catch (e) {
                console.error("Failed to update parent window URL:", e);
            }
        }
        const expandSaved = localStorage.getItem('pfms_toolbar_expand') === '1';
        const collapse = document.getElementById('toolbarCollapse');
        const icon = document.getElementById('expand_icon');
        if (collapse) {
            if (expandSaved) {
                collapse.classList.add('show');
                if (icon) icon.innerText = 'close';
            } else {
                collapse.classList.remove('show');
                if (icon) icon.innerText = 'tune';
            }
        }
        setupTimer();
        fetchData();

        setTimeout(() => {
            const table = document.querySelector('#detailView table.master-table');
            if (table) {
                table.querySelectorAll('.col-resize-handle').forEach(h => h.remove());
                makeTableResizable(table);
            }
        }, 150);
    }

    function makeTableResizable(table) {
        const cols = table.querySelectorAll('thead th');
        const savedWidths = JSON.parse(localStorage.getItem('pfms_table_widths_' + currentDashId) || '{}');
        
        cols.forEach((col, index) => {
            if (savedWidths[index]) {
                col.style.width = savedWidths[index];
            }
            
            if (index === cols.length - 1) return; // Skip Graph (history)
            
            const resizer = document.createElement('div');
            resizer.classList.add('col-resize-handle');
            col.appendChild(resizer);
            
            let startX, startWidth;
            
            resizer.addEventListener('mousedown', function(e) {
                startX = e.pageX;
                startWidth = col.offsetWidth;
                
                resizer.classList.add('resizing');
                
                function onMouseMove(e) {
                    const width = startWidth + (e.pageX - startX);
                    col.style.width = Math.max(50, width) + 'px';
                }
                
                function onMouseUp() {
                    resizer.classList.remove('resizing');
                    document.removeEventListener('mousemove', onMouseMove);
                    document.removeEventListener('mouseup', onMouseUp);
                    
                    const widths = {};
                    cols.forEach((c, idx) => {
                        widths[idx] = c.style.width || (c.offsetWidth + 'px');
                    });
                    localStorage.setItem('pfms_table_widths_' + currentDashId, JSON.stringify(widths));
                }
                
                document.addEventListener('mousemove', onMouseMove);
                document.addEventListener('mouseup', onMouseUp);
                e.preventDefault();
            });
        });
    }

    function goBack() { 
        currentDashId = ''; 
        const url = new URL(window.location); 
        url.searchParams.delete('dash_id'); 
        ['unit', 'sort', 'speed_filter', 'search', 'warn', 'crit', 'fs', 'show_units', 'per_page', 'enabled_units', 'enabled_categories', 'refresh'].forEach(p => url.searchParams.delete(p));
        window.history.replaceState({}, '', url); 
        if (window.parent && window.parent !== window) {
            try {
                const parentUrl = new URL(window.parent.location);
                parentUrl.searchParams.delete('dash_id');
                ['unit', 'sort', 'speed_filter', 'search', 'warn', 'crit', 'fs', 'show_units', 'per_page', 'enabled_units', 'enabled_categories', 'refresh'].forEach(p => parentUrl.searchParams.delete(p));
                window.parent.history.replaceState({}, '', parentUrl);
            } catch (e) {
                console.error("Failed to update parent window URL on goBack:", e);
            }
        }
        renderMasterList(); 
    }

    function duplicateDashboardFromList(id) {
        const dash = masterDashboards.find(d => d.id === id);
        if(!dash) return;
        
        const newDash = JSON.parse(JSON.stringify(dash));
        newDash.id = 'd' + Date.now();
        newDash.name = newDash.name + ' (Copy)';
        if (!newDash.hidden_interfaces) newDash.hidden_interfaces = [];
        
        masterDashboards.push(newDash);
        saveConfigToServer(() => {
            alert('Dashboard duplicated successfully!');
            renderMasterList();
        });
    }

    function saveConfigToServer(callback) {
        fetch('?api=save_config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
            body: JSON.stringify(masterDashboards)
        })
        .then(r => r.json())
        .then(res => {
            if(res.ok) {
                if(callback) callback();
            } else {
                alert(res.error || 'Failed to save configuration.');
            }
        })
        .catch(err => {
            alert('Error communicating with server: ' + err.message);
        });
    }

    async function fetchData() {
        const d = masterDashboards.find(x => x.id === currentDashId); if(!d) return;

        const warn = document.getElementById('f_warn').value;
        const crit = document.getElementById('f_crit').value;
        const fs = document.getElementById('f_fontsize').value;
        const unit = document.getElementById('f_unit').value;
        const sort = document.getElementById('f_sort').value;
        const speed_filter = document.getElementById('f_speed_filter').value;
        const search = document.getElementById('f_search').value;
        const showUnits = document.getElementById('f_showunits').checked;
        const perPage = document.getElementById('f_perpage').value || 20;
        const stripUnit = (text) => {
            if (!text || text === 'N/A') return text;
            return text.replace(/\s*(gbps|mbps|kbps|bps|mb\/s|gb\/s|b\/s|kb\/s|B\/s|KB\/s|MB\/s|GB\/s)\s*$/i, '').trim();
        };

        const enabled_units = [];
        ['Auto', 'Mbps', 'Gbps', 'Bps', 'MBps', 'GBps'].forEach(u => {
            const cb = document.getElementById('unit_opt_' + u);
            if (cb && cb.checked) enabled_units.push(u);
        });

        const enabled_categories = [];
        availableCategories.forEach(c => {
            const cb = document.getElementById('cat_opt_' + c.id);
            if (cb && cb.checked) enabled_categories.push(c.name);
        });
        const cbNA = document.getElementById('cat_opt_na');
        if (cbNA && cbNA.checked) enabled_categories.push('N/A');

        const refresh = document.getElementById('f_refresh').value;

        localStorage.setItem('pfms_settings_' + currentDashId, JSON.stringify({
            warn, crit, fs, unit, sort, speed_filter, search, show_units: showUnits, enabled_units: enabled_units, enabled_categories: enabled_categories, per_page: perPage, refresh: refresh
        }));

        const body = document.getElementById('detailTableBody'); body.innerHTML = '<tr><td colspan="8" style="text-align:center;">Loading...</td></tr>';
        const payload = { 
            group_id: d.group_id, 
            agent_id: d.agent_id, 
            dash_id: currentDashId,
            unit: unit, 
            speed_filter: speed_filter, 
            search: search, 
            sort: sort,  
            page: currentPage, 
            per_page: parseInt(perPage) || 20,
            warn: parseFloat(warn) || 70,
            crit: parseFloat(crit) || 80,
            enabled_categories: enabled_categories
        };
        const r = await fetch('?api=data', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF_TOKEN}, body:JSON.stringify(payload) });
        const res = await r.json();
        if(!res.ok) return alert(res.error);
        console.log("Returned Data:", res.data);
        
        const warnT = parseFloat(document.getElementById('f_warn').value) || 70;
        const critT = parseFloat(document.getElementById('f_crit').value) || 80;
        
        document.getElementById('last_update_text').innerText = `Update: ${res.updated_at}`;
        body.innerHTML = res.data.map(r => {
            const rxLevel = r.rx_pct >= critT ? 'crit' : (r.rx_pct >= warnT ? 'warn' : 'ok');
            const txLevel = r.tx_pct >= critT ? 'crit' : (r.tx_pct >= warnT ? 'warn' : 'ok');
            const speedDisp = showUnits ? r.speed_disp : stripUnit(r.speed_disp);
            const rxDisp = showUnits ? r.rx_disp : stripUnit(r.rx_disp);
            const txDisp = showUnits ? r.tx_disp : stripUnit(r.tx_disp);

            const categoryDisp = r.category_name 
                ? `<span class="category-badge">${r.category_name}</span>`
                : `<span class="category-badge" style="background:transparent; color:#94a3b8; border-color:#e2e8f0; border-style:dashed;">N/A</span>`;

            return `<tr>
            <td><span class="status-badge ${r.status_badge}">${r.status_text}</span></td>
            <td>
                <div style="font-weight:600;">
                    <a href="/pandora_console/index.php?sec=estado&sec2=operation/agentes/ver_agente&id_agente=${r.agent_id}" 
                       target="_blank" 
                       style="color:#004d40; text-decoration:none;" 
                       onmouseover="this.style.textDecoration='underline'" 
                       onmouseout="this.style.textDecoration='none'">
                        ${r.node}
                    </a>
                </div>
                <div class="sub-text">${r.ip}</div>
            </td>
            <td>
                <div style="font-weight:600;"><span class="iface-badge">${r.interface}</span></div>
                ${r.interface_alias ? `<div class="sub-text" style="color:#64748b; margin-top:2px;">${r.interface_alias}</div>` : ''}
            </td>
            <td class="col-category">
                <div style="font-weight:600;">${categoryDisp}</div>
            </td>
            <td class="col-speed">
                <div style="display:flex; align-items:center; gap:5px; font-weight:500;">
                    ${speedDisp}
                    ${r.speed_disp === 'N/A' ? `<span class="material-symbols-outlined table-icon" style="color:#f59e0b; cursor:pointer;" onclick="alert('Please check if the IfSpeed/IfHighSpeed module is available?')">info</span>` : ''}
                </div>
            </td>
            <td><div class="col-${rxLevel}" style="font-weight:600;">${rxDisp} <span class="pct-text">(${r.rx_pct_disp})</span></div><div class="traffic-bar"><div class="traffic-fill bg-${rxLevel}" style="width:${r.rx_pct}%"></div></div></td>
            <td><div class="col-${txLevel}" style="font-weight:600;">${txDisp} <span class="pct-text">(${r.tx_pct_disp})</span></div><div class="traffic-bar"><div class="traffic-fill bg-${txLevel}" style="width:${r.tx_pct}%"></div></div></td>
            <td class="action-cell col-actions" style="text-align:center;">
                <div class="action-buttons-desktop">
                    <button class="btn-icon-only" onclick="openChart(${r.mod_in}, ${r.mod_out}, '${r.node.replace(/'/g, "\\'")} - ${r.interface.replace(/'/g, "\\'")}')" title="View Graph">
                        <span class="material-symbols-outlined table-icon" style="color:#3b82f6;">show_chart</span>
                    </button>
                    <button class="btn-icon-only delete-icon" onclick="hideInterface(${r.agent_id}, '${r.interface.replace(/'/g, "\\'")}')" title="Hide this interface">
                        <span class="material-symbols-outlined table-icon">delete</span>
                    </button>
                </div>
                <div class="action-dropdown-mobile dropdown">
                    <button class="btn-icon-only btn-more" onclick="toggleActionDropdown(event, this)" title="Actions">
                        <span class="material-symbols-outlined">more_vert</span>
                    </button>
                    <div class="dropdown-menu-custom">
                        <a href="#" onclick="event.preventDefault(); openChart(${r.mod_in}, ${r.mod_out}, '${r.node.replace(/'/g, "\\'")} - ${r.interface.replace(/'/g, "\\'")}')">
                            <span class="material-symbols-outlined" style="color:#3b82f6;">show_chart</span> View Graph
                        </a>
                        <a href="#" class="delete-item" onclick="event.preventDefault(); hideInterface(${r.agent_id}, '${r.interface.replace(/'/g, "\\'")}')">
                            <span class="material-symbols-outlined">delete</span> Hide Interface
                        </a>
                    </div>
                </div>
            </td>
        </tr>`;}).join('') || '<tr><td colspan="8" style="text-align:center;">No Interfaces Found</td></tr>';
        
        renderPagination(res.pagination);
        updateURLState();
        resetTimer();
    }

    function renderPagination(p) {
        const wrap = document.getElementById('paginationControls');
        if(!p || p.total_pages <= 1) { wrap.style.display = 'none'; return; }
        wrap.style.display = 'flex';
        const perPage = parseInt(document.getElementById('f_perpage').value) || 20;
        wrap.innerHTML = `
            <div style="color:var(--text-dim);">Showing <b>${(p.page-1)*perPage+1}</b> to <b>${Math.min(p.page*perPage, p.total)}</b> of <b>${p.total}</b></div>
            <div style="display:flex; gap:5px; align-items:center;">
                <button class="btn-neutral" style="padding:4px 8px;" onclick="changePage(${p.page-1})" ${p.page<=1?'disabled':''}><span class="material-symbols-outlined">chevron_left</span></button>
                <div style="display:flex; align-items:center; padding:0 10px; font-weight:600; font-size:11px;">Page ${p.page} of ${p.total_pages}</div>
                <button class="btn-neutral" style="padding:4px 8px;" onclick="changePage(${p.page+1})" ${p.page>=p.total_pages?'disabled':''}><span class="material-symbols-outlined">chevron_right</span></button>
            </div>
        `;
    }

    function changePage(p) { currentPage = p; fetchData(); }

    function setupTimer() {
        if(timerInterval) clearInterval(timerInterval);
        const el = document.getElementById('f_refresh');
        if(!el) return;
        let sec = parseInt(el.value);
        if(sec <= 0) { document.getElementById('countdown_text').innerText = 'Auto: Off'; return; }
        countdown = sec;
        timerInterval = setInterval(() => {
            countdown--;
            if(countdown <= 0) { fetchData(); countdown = sec; }
            document.getElementById('countdown_text').innerText = `Refresh in ${countdown}s`;
        }, 1000);
    }

    function resetTimer() {
        const el = document.getElementById('f_refresh');
        if(!el) return;
        let sec = parseInt(el.value);
        if(sec > 0) countdown = sec;
    }

    function openSettingsModal() { document.getElementById('settingsModal').style.display = 'flex'; }
    function closeSettingsModal() { document.getElementById('settingsModal').style.display = 'none'; }

    function saveSettings() {
        if(!currentDashId) return;
        const warn = document.getElementById('f_warn').value;
        const crit = document.getElementById('f_crit').value;
        const fs = document.getElementById('f_fontsize').value;
        const unit = document.getElementById('f_unit').value;
        const sort = document.getElementById('f_sort').value;
        const speed_filter = document.getElementById('f_speed_filter').value;
        const search = document.getElementById('f_search').value;
        const show_units = document.getElementById('f_showunits').checked;
        const per_page = document.getElementById('f_perpage').value || 20;

        const enabled_units = [];
        ['Auto', 'Mbps', 'Gbps', 'Bps', 'MBps', 'GBps'].forEach(u => {
            const cb = document.getElementById('unit_opt_' + u);
            if (cb && cb.checked) enabled_units.push(u);
        });

        const enabled_categories = [];
        availableCategories.forEach(c => {
            const cb = document.getElementById('cat_opt_' + c.id);
            if (cb && cb.checked) enabled_categories.push(c.name);
        });
        const cbNA = document.getElementById('cat_opt_na');
        if (cbNA && cbNA.checked) enabled_categories.push('N/A');

        // Save to LocalStorage with Dashboard ID as Key
        localStorage.setItem('pfms_settings_' + currentDashId, JSON.stringify({ warn, crit, fs, unit, sort, speed_filter, search, show_units, enabled_units, enabled_categories, per_page }));
        
        updateUnitDropdown(enabled_units);
        applyFontSize();
        fetchData();
        closeSettingsModal();
    }

    function applyFontSize() {
        const fs = document.getElementById('f_fontsize').value;
        const target = document.getElementById('detailView');
        if(target) target.style.setProperty('--table-font-size', fs + 'px');
        updateURLState();
    }

    function updateURLState() {
        const unit = document.getElementById('f_unit').value;
        const sort = document.getElementById('f_sort').value;
        const speedFilter = document.getElementById('f_speed_filter').value;
        const search = document.getElementById('f_search').value;
        const warn = document.getElementById('f_warn').value;
        const crit = document.getElementById('f_crit').value;
        const fs = document.getElementById('f_fontsize').value;
        const showUnits = document.getElementById('f_showunits').checked ? '1' : '0';
        const perPage = document.getElementById('f_perpage').value || 20;

        const enabled_units = [];
        ['Auto', 'Mbps', 'Gbps', 'Bps', 'MBps', 'GBps'].forEach(u => {
            const cb = document.getElementById('unit_opt_' + u);
            if (cb && cb.checked) enabled_units.push(u);
        });

        const refresh = document.getElementById('f_refresh').value;

        const newUrl = new URL(window.location);
        newUrl.searchParams.set('unit', unit);
        newUrl.searchParams.set('sort', sort);
        newUrl.searchParams.set('speed_filter', speedFilter);
        newUrl.searchParams.set('search', search);
        newUrl.searchParams.set('warn', warn);
        newUrl.searchParams.set('crit', crit);
        newUrl.searchParams.set('fs', fs);
        newUrl.searchParams.set('show_units', showUnits);
        newUrl.searchParams.set('per_page', perPage);
        newUrl.searchParams.set('refresh', refresh);
        const enabled_categories = [];
        availableCategories.forEach(c => {
            const cb = document.getElementById('cat_opt_' + c.id);
            if (cb && cb.checked) enabled_categories.push(c.name);
        });
        const cbNA = document.getElementById('cat_opt_na');
        if (cbNA && cbNA.checked) enabled_categories.push('N/A');
        newUrl.searchParams.set('enabled_units', enabled_units.join(','));
        newUrl.searchParams.set('enabled_categories', enabled_categories.join(','));
        if(currentDashId) newUrl.searchParams.set('dash_id', currentDashId);
        window.history.replaceState({}, '', newUrl);

        if (window.parent && window.parent !== window) {
            try {
                const parentUrl = new URL(window.parent.location);
                parentUrl.searchParams.set('unit', unit);
                parentUrl.searchParams.set('sort', sort);
                parentUrl.searchParams.set('speed_filter', speedFilter);
                parentUrl.searchParams.set('search', search);
                parentUrl.searchParams.set('warn', warn);
                parentUrl.searchParams.set('crit', crit);
                parentUrl.searchParams.set('fs', fs);
                parentUrl.searchParams.set('show_units', showUnits);
                parentUrl.searchParams.set('per_page', perPage);
                parentUrl.searchParams.set('refresh', refresh);
                parentUrl.searchParams.set('enabled_units', enabled_units.join(','));
                parentUrl.searchParams.set('enabled_categories', enabled_categories.join(','));
                if(currentDashId) parentUrl.searchParams.set('dash_id', currentDashId);
                window.parent.history.replaceState({}, '', parentUrl);
            } catch (e) {
                console.error("Failed to update parent window URL state:", e);
            }
        }
    }

    function openCreateModal() { 
        editId = null;
        document.getElementById('modalTitle').innerText = 'Create Dashboard';
        document.getElementById('btnSubmitModal').innerText = 'Create Dashboard';
        document.getElementById('m_name').value = '';
        document.getElementById('m_group').value = '0';
        document.getElementById('m_agent').innerHTML = '<option value="0">-- All Nodes --</option>';
        document.getElementById('createModal').style.display = 'flex'; 
    }

    function editDashboard(id) {
        const d = masterDashboards.find(x => x.id === id); if(!d) return;
        editId = id;
        document.getElementById('modalTitle').innerText = 'Edit Dashboard';
        document.getElementById('btnSubmitModal').innerText = 'Save Changes';
        document.getElementById('m_name').value = d.name;
        document.getElementById('m_group').value = d.group_id;
        loadAgentOptions(d.agent_id);
        document.getElementById('createModal').style.display = 'flex';
    }

    function closeCreateModal() { document.getElementById('createModal').style.display = 'none'; }

    async function loadAgentOptions(selectedId = 0) {
        const gid = document.getElementById('m_group').value;
        const r = await fetch(`?api=agents&group_id=${gid}`); const agents = await r.json();
        const sel = document.getElementById('m_agent'); sel.innerHTML = '<option value="0">-- All Nodes --</option>';
        agents.forEach(a => {
            const opt = new Option(decodeHtml(a.alias), a.id);
            if(a.id == selectedId) opt.selected = true;
            sel.add(opt);
        });
    }

    function saveNewDashboard() {
        const name = document.getElementById('m_name').value; if(!name) return alert("Name required");
        const gsel = document.getElementById('m_group');
        const asel = document.getElementById('m_agent');
        
        if (editId) {
            const idx = masterDashboards.findIndex(x => x.id === editId);
            if (idx !== -1) {
                masterDashboards[idx].name = name;
                masterDashboards[idx].group_id = gsel.value;
                masterDashboards[idx].group_name = gsel.options[gsel.selectedIndex].text;
                masterDashboards[idx].agent_id = asel.value;
                masterDashboards[idx].agent_name = asel.options[asel.selectedIndex].text;
            }
        } else {
            const id = 'dash_'+Math.random().toString(36).substr(2,9);
            masterDashboards.push({ id, name, group_id: gsel.value, group_name: gsel.options[gsel.selectedIndex].text, agent_id: asel.value, agent_name: asel.options[asel.selectedIndex].text, hidden_interfaces: [] });
        }
        
        saveConfigToServer(() => {
            closeCreateModal(); 
            renderMasterList();
        });
    }

    function deleteDashboard(id) { 
        if(confirm("Delete this dashboard?")) { 
            masterDashboards = masterDashboards.filter(x => x.id !== id); 
            saveConfigToServer(() => {
                renderMasterList();
            });
        } 
    }
    function copyShareLink() {
        const u = new URL(window.location.origin + DIRECT_SCRIPT_URL);
        u.searchParams.set('s', '1');
        if (currentDashId) {
            u.searchParams.set('dash_id', currentDashId);
            
            // Append current filter settings to the shared URL
            const unit = document.getElementById('f_unit').value;
            const sort = document.getElementById('f_sort').value;
            const speedFilter = document.getElementById('f_speed_filter').value;
            const search = document.getElementById('f_search').value;
            const warn = document.getElementById('f_warn').value;
            const crit = document.getElementById('f_crit').value;
            const fs = document.getElementById('f_fontsize').value;
            const showUnits = document.getElementById('f_showunits').checked ? '1' : '0';
            const perPage = document.getElementById('f_perpage').value || 20;
            const refresh = document.getElementById('f_refresh').value;

            const enabled_units = [];
            ['Auto', 'Mbps', 'Gbps', 'Bps', 'MBps', 'GBps'].forEach(unitVal => {
                const cb = document.getElementById('unit_opt_' + unitVal);
                if (cb && cb.checked) enabled_units.push(unitVal);
            });

            const enabled_categories = [];
            availableCategories.forEach(c => {
                const cb = document.getElementById('cat_opt_' + c.id);
                if (cb && cb.checked) enabled_categories.push(c.name);
            });
            const cbNA = document.getElementById('cat_opt_na');
            if (cbNA && cbNA.checked) enabled_categories.push('N/A');

            u.searchParams.set('unit', unit);
            u.searchParams.set('sort', sort);
            u.searchParams.set('speed_filter', speedFilter);
            u.searchParams.set('search', search);
            u.searchParams.set('warn', warn);
            u.searchParams.set('crit', crit);
            u.searchParams.set('fs', fs);
            u.searchParams.set('show_units', showUnits);
            u.searchParams.set('per_page', perPage);
            u.searchParams.set('refresh', refresh);
            u.searchParams.set('enabled_units', enabled_units.join(','));
            u.searchParams.set('enabled_categories', enabled_categories.join(','));
        }
        const urlString = u.toString();
        
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(urlString).then(() => alert("Link Copied!"));
        } else {
            const textArea = document.createElement("textarea");
            textArea.value = urlString;
            textArea.style.position = "fixed";
            textArea.style.left = "-999999px";
            textArea.style.top = "-999999px";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                document.execCommand('copy');
                alert("Link Copied!");
            } catch (err) {
                prompt("Copy manual:", urlString);
            }
            textArea.remove();
        }
    }

    let importTargetDashId = null;
    function triggerImport(id) {
        importTargetDashId = id;
        document.getElementById('importBackupFile').click();
    }

    function exportDashboardConfig(id) {
        const targetId = id || currentDashId;
        if(!targetId) return;
        const d = masterDashboards.find(x => x.id === targetId);
        if(!d) return;
        const exportData = {
            name: d.name,
            group_id: d.group_id,
            group_name: d.group_name,
            agent_id: d.agent_id,
            agent_name: d.agent_name,
            hidden_interfaces: d.hidden_interfaces || []
        };
        const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(exportData, null, 2));
        const dlAnchorElem = document.createElement('a');
        dlAnchorElem.setAttribute("href",     dataStr);
        dlAnchorElem.setAttribute("download", `traffic_dashboard_${d.name.toLowerCase().replace(/\s+/g, '_')}_backup.json`);
        dlAnchorElem.click();
    }

    function importDashboardConfig(event) {
        const targetId = importTargetDashId || currentDashId;
        if(!targetId) return;
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const loaded = JSON.parse(e.target.result);
                if (loaded && typeof loaded === 'object' && !Array.isArray(loaded)) {
                    if (loaded.group_id === undefined || !loaded.name) {
                        alert("Format file tidak valid. Pastikan file JSON berisi konfigurasi dashboard traffic yang benar.");
                        return;
                    }
                    masterDashboards = masterDashboards.map(d => {
                        if (d.id === targetId) {
                            d.name = loaded.name;
                            d.group_id = loaded.group_id;
                            d.group_name = loaded.group_name || '';
                            d.agent_id = loaded.agent_id || '0';
                            d.agent_name = loaded.agent_name || '';
                            d.hidden_interfaces = loaded.hidden_interfaces || [];
                        }
                        return d;
                    });
                    saveConfigToServer(() => {
                        alert("Dashboard configuration loaded successfully!");
                        if (targetId === currentDashId) {
                            openDashboard(targetId);
                        } else {
                            renderMasterList();
                        }
                    });
                } else {
                    alert("Format file JSON tidak valid.");
                }
            } catch (err) {
                alert("Invalid JSON file: " + err.message);
            } finally {
                event.target.value = '';
                importTargetDashId = null;
            }
        };
        reader.readAsText(file);
    }



    let lastInId, lastOutId, lastTitle;
    function openChart(inId, outId, title) {
        // Close any active action dropdowns
        document.querySelectorAll('.dropdown-menu-custom.show').forEach(menu => {
            menu.classList.remove('show');
        });
        lastInId = inId; lastOutId = outId; lastTitle = title;
        document.getElementById('chartTitle').innerText = title; 
        
        // Reset chart settings to default (Last 24 Hours)
        const rangeSelect = document.getElementById('chartRange');
        rangeSelect.value = '24';
        document.getElementById('customRangeInputs').style.display = 'none';
        document.getElementById('c_from').value = '';
        document.getElementById('c_to').value = '';
        
        document.getElementById('chartModal').style.display = 'flex';
        reloadChart();
    }

    function reloadChart() {
        const unit = document.getElementById('f_unit').value;
        const range = document.getElementById('chartRange').value;
        const customDiv = document.getElementById('customRangeInputs');
        
        let url = `?api=series&in=${lastInId}&out=${lastOutId}&unit=${unit}`;
        
        if (range === 'custom') {
            customDiv.style.display = 'flex';
            const start = document.getElementById('c_from').value;
            const end = document.getElementById('c_to').value;
            if(start) url += `&start=${encodeURIComponent(start)}`;
            if(end) url += `&end=${encodeURIComponent(end)}`;
        } else {
            customDiv.style.display = 'none';
            url += `&range=${range}`;
        }

        fetch(url).then(r=>r.json()).then(d => {
            // Align RX and TX timestamps to solve Chart.js index tooltip misalignment
            const allTimestampsSet = new Set();
            d.rx.forEach(pt => allTimestampsSet.add(pt[0]));
            d.tx.forEach(pt => allTimestampsSet.add(pt[0]));
            const sortedTimestamps = Array.from(allTimestampsSet).sort((a, b) => a - b);

            const rxMap = new Map(d.rx.map(pt => [pt[0], pt[1]]));
            const txMap = new Map(d.tx.map(pt => [pt[0], pt[1]]));

            d.rx = sortedTimestamps.map(ts => [ts, rxMap.has(ts) ? rxMap.get(ts) : null]);
            d.tx = sortedTimestamps.map(ts => [ts, txMap.has(ts) ? txMap.get(ts) : null]);

            let displayUnit = unit;
            let m = 1.0;

            if (unit === 'Auto') {
                const maxVal = Math.max(...d.rx.map(x=>x[1]).filter(v => v !== null), ...d.tx.map(x=>x[1]).filter(v => v !== null), 1);
                if (maxVal < 1000) { displayUnit = 'B/s'; m = 1.0; }
                else if (maxVal < 1000000) { displayUnit = 'KB/s'; m = 1/1000; }
                else if (maxVal < 1000000000) { displayUnit = 'MB/s'; m = 1/1000000; }
                else { displayUnit = 'GB/s'; m = 1/1000000000; }
                
                d.rx = d.rx.map(x => [x[0], x[1] !== null ? x[1] * m : null]);
                d.tx = d.tx.map(x => [x[0], x[1] !== null ? x[1] * m : null]);
                d.avg_rx *= m; d.avg_tx *= m;
            }

            document.getElementById('avgRxText').innerText = d.avg_rx.toFixed(2) + ' ' + displayUnit;
            document.getElementById('avgTxText').innerText = d.avg_tx.toFixed(2) + ' ' + displayUnit;
            
            if(chartInstance && typeof chartInstance.dispose === 'function') chartInstance.dispose();
            chartInstance = echarts.init(document.getElementById('trafficCanvas'));
            chartInstance.setOption({
                tooltip: { 
                    trigger: 'axis', 
                    backgroundColor: 'rgba(255, 255, 255, 0.95)', 
                    textStyle: { color: '#475569', fontSize: 12 }, 
                    padding: 12, borderRadius: 6, borderColor: '#e2e8f0', borderWidth: 1, 
                    valueFormatter: (value) => value !== null ? value.toFixed(2) + ' ' + displayUnit : '-' 
                },
                legend: { textStyle: { fontSize: 12, color: '#1e293b', fontWeight: 'bold' } },
                grid: { left: 15, right: 15, top: 30, bottom: 65, containLabel: true },
                xAxis: { 
                    type: 'time', 
                    boundaryGap: false, 
                    splitLine: { show: true, lineStyle: { color: '#f1f5f9' } }, 
                    axisLabel: { color: '#64748b' }, 
                    axisLine: { show: false }, 
                    axisTick: { show: false } 
                },
                yAxis: { 
                    type: 'value', 
                    splitLine: { lineStyle: { color: '#f1f5f9' } }, 
                    axisLabel: { color: '#64748b' } 
                },
                dataZoom: [
                    { type: 'inside', xAxisIndex: [0] },
                    { 
                        type: 'slider', 
                        xAxisIndex: [0], 
                        height: 20, 
                        bottom: 15,
                        left: 45,
                        right: 45,
                        labelFormatter: (value) => {
                            const d = new Date(value);
                            const hh = String(d.getHours()).padStart(2, '0');
                            const mm = String(d.getMinutes()).padStart(2, '0');
                            return hh + ':' + mm;
                        }
                    }
                ],
                series: [
                    { 
                        name: `RX`, 
                        type: 'line', 
                        data: d.rx.map(x => [x[0] > 9999999999 ? x[0] : x[0] * 1000, x[1]]), 
                        itemStyle: { color: '#10b981' }, 
                        areaStyle: { color: 'rgba(16,185,129,0.1)' }, 
                        smooth: true, showSymbol: false, connectNulls: true, lineStyle: { width: 2 } 
                    },
                    { 
                        name: `TX`, 
                        type: 'line', 
                        data: d.tx.map(x => [x[0] > 9999999999 ? x[0] : x[0] * 1000, x[1]]), 
                        itemStyle: { color: '#3b82f6' }, 
                        areaStyle: { color: 'rgba(59,130,246,0.1)' }, 
                        smooth: true, showSymbol: false, connectNulls: true, lineStyle: { width: 2 } 
                    }
                ]
            });
            if (chartInstance) {
                chartInstance.resize();
                setTimeout(() => {
                    if (chartInstance) chartInstance.resize();
                }, 50);
            }
        });
    }

    function resetZoom() {
        if (chartInstance) {
            chartInstance.dispatchAction({ type: 'dataZoom', start: 0, end: 100 });
        }
    }
    
    function manualZoom(f) {
        // ECharts zooming typically relies on UI slider or scroll. Programmatic pan can be added using dispatchAction.
    }

    // Fungsi untuk menyembunyikan interface
    function hideInterface(agentId, iface) {
        // Close any active action dropdowns
        document.querySelectorAll('.dropdown-menu-custom.show').forEach(menu => {
            menu.classList.remove('show');
        });
        const key = agentId + '|' + iface;
        const dash = masterDashboards.find(d => d.id === currentDashId);
        if (!dash) return;
        if (!dash.hidden_interfaces) dash.hidden_interfaces = [];
        if (!dash.hidden_interfaces.includes(key)) {
            dash.hidden_interfaces.push(key);
            saveConfigToServer(() => {
                fetchData();
            });
        } else {
            alert('Interface already hidden.');
        }
    }

    // Modal hidden management
    function openHiddenModal() {
        const dash = masterDashboards.find(d => d.id === currentDashId);
        if (!dash) return;
        const hidden = dash.hidden_interfaces || [];
        const list = document.getElementById('hiddenList');
        if (hidden.length === 0) {
            list.innerHTML = '<p style="color:#94a3b8; text-align:center;">No hidden interfaces.</p>';
        } else {
            list.innerHTML = hidden.map(key => {
                const [aid, iface] = key.split('|');
                return `<div style="display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px solid #f1f5f9;">
                    <span>${iface} (Agent ID: ${aid})</span>
                    <button class="btn-neutral" style="padding:2px 8px; font-size:11px;" onclick="unhideInterface('${key}')">Restore</button>
                </div>`;
            }).join('');
        }
        document.getElementById('hiddenModal').style.display = 'flex';
    }
    function closeHiddenModal() { document.getElementById('hiddenModal').style.display = 'none'; }
    function unhideInterface(key) {
        const dash = masterDashboards.find(d => d.id === currentDashId);
        if (!dash) return;
        dash.hidden_interfaces = (dash.hidden_interfaces || []).filter(k => k !== key);
        saveConfigToServer(() => {
            fetchData();
            openHiddenModal(); // refresh daftar
        });
    }
    function clearAllHidden() {
        if(!confirm("Restore all hidden interfaces?")) return;
        const dash = masterDashboards.find(d => d.id === currentDashId);
        if (!dash) return;
        dash.hidden_interfaces = [];
        saveConfigToServer(() => {
            fetchData();
            closeHiddenModal();
        });
    }

    function toggleActionDropdown(event, button) {
        event.stopPropagation();
        document.querySelectorAll('.dropdown-menu-custom.show').forEach(menu => {
            if (menu !== button.nextElementSibling) {
                menu.classList.remove('show');
            }
        });
        const menu = button.nextElementSibling;
        menu.classList.toggle('show');
    }

    window.addEventListener('load', init);
    window.addEventListener('resize', () => {
        if (typeof chartInstance !== 'undefined' && chartInstance && typeof chartInstance.resize === 'function') {
            chartInstance.resize();
        }
    });

    document.addEventListener('click', e => { 
        if(e.target.id==='chartModal') document.getElementById('chartModal').style.display='none'; 
        if(e.target.id==='hiddenModal') closeHiddenModal(); 
        
        if (!e.target.closest('.action-dropdown-mobile')) {
            document.querySelectorAll('.dropdown-menu-custom.show').forEach(menu => {
                menu.classList.remove('show');
            });
        }
    });
</script>
</body>
</html>