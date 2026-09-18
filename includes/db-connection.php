<?php
// Global Security Headers
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

/**
 * db-connection.php
 * Core configuration and database connection for PFMS-Toolkit
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Polyfills for PHP 8.0 string functions (for PHP 7.x compatibility)
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return (string)$needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle !== '' && substr($haystack, -strlen($needle)) === (string)$needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && strpos($haystack, $needle) !== false;
    }
}

// 1. DYNAMIC BREADCRUMB LOGIC
$raw_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$dir_only = dirname($raw_path);
$clean_path = trim($dir_only, '/');
$path_array = explode('/', $clean_path);
$formatted_array = array_map(function($p) {
    return ucwords(str_replace(['_', '-'], ' ', $p));
}, $path_array);
$dynamic_breadcrumb = implode(' / ', $formatted_array);

// 2. PANDORA FMS BASE CONFIG
$script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$PANEL_DIR_NAME = 'custom';
if (preg_match('#^(/.*?)/(custom|customize)/panel#', $script_dir, $matches)) {
    $PANDORA_BASE_URL = rtrim($matches[1], '/');
    $PANEL_DIR_NAME = $matches[2];
} else if (preg_match('#^/(custom|customize)/panel#', $script_dir, $matches)) {
    $PANDORA_BASE_URL = '';
    $PANEL_DIR_NAME = $matches[1];
} else {
    $PANDORA_BASE_URL = "/pandora_console";
}

// 2. SEARCH AND LOAD PANDORA CONFIG
if (!isset($config) || !is_array($config)) {
    $config = [];
}
$config_loaded = false;
$config_paths = [
    __DIR__ . '/../../../include/config.php',
    '/var/www/html/pandora_console/include/config.php',
    '../../../include/config.php',
    '../../../../include/config.php',
    '../../include/config.php'
];

foreach ($config_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $config_loaded = true;
        // Require functions_io.php if it exists for decryption helpers
        $io_path = dirname($path) . '/functions_io.php';
        if (file_exists($io_path)) {
            require_once($io_path);
        }
        break;
    }
}

// 3. LOAD CONFIG OVERRIDES FROM portal_config_local.json (falls back to portal_config.json)
$portal_config_path = dirname(__DIR__) . '/portal_config_local.json';
if (!file_exists($portal_config_path)) {
    $portal_config_path = dirname(__DIR__) . '/portal_config.json';
}
$primary_override = null;
$history_override = null;
if (file_exists($portal_config_path)) {
    $p_config = json_decode(file_get_contents($portal_config_path), true);
    if (is_array($p_config)) {
        if (isset($p_config['primary_override']) && is_array($p_config['primary_override'])) {
            $primary_override = $p_config['primary_override'];
        }
        if (isset($p_config['history_override']) && is_array($p_config['history_override'])) {
            $history_override = $p_config['history_override'];
        }
    }
}

if ($config_loaded) {
    if ($primary_override) {
        $config['dbhost'] = $primary_override['host'] ?? $config['dbhost'];
        $config['dbname'] = $primary_override['dbname'] ?? $config['dbname'];
        $config['dbuser'] = $primary_override['user'] ?? $config['dbuser'];
        $config['dbpass'] = isset($primary_override['pass']) ? $primary_override['pass'] : $config['dbpass'];
        $config['dbport'] = $primary_override['port'] ?? ($config['dbport'] ?? 3306);
    }
    if ($history_override) {
        $config['dbhost_history'] = $history_override['host'] ?? ($config['dbhost_history'] ?? '');
        $config['dbname_history'] = $history_override['dbname'] ?? ($config['dbname_history'] ?? '');
        $config['dbuser_history'] = $history_override['user'] ?? ($config['dbuser_history'] ?? '');
        $config['dbpass_history'] = isset($history_override['pass']) ? $history_override['pass'] : ($config['dbpass_history'] ?? '');
        $config['dbport_history'] = $history_override['port'] ?? ($config['dbport_history'] ?? 3306);
    }
}

// 4. DATABASE INITIALIZATION (PDO)
$pdo = null;
$db_status = false;
$db_error = '';

if ($config_loaded) {
    try {
        $db_port = !empty($config['dbport']) ? (int)$config['dbport'] : 3306;
        $dsn = "mysql:host=" . $config["dbhost"] . ";port=" . $db_port . ";dbname=" . $config["dbname"] . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $config["dbuser"], $config["dbpass"], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true
        ]);
        $db_status = true;
    } catch (PDOException $e) {
        $db_error = $e->getMessage();
    }
}

// 4. HISTORICAL DATABASE INITIALIZATION (PDO)
$pdo_history = null;
$history_db_status = false;
$history_db_host = null;
$history_db_name = null;
$history_db_user = null;

if ($config_loaded) {
    // 1. Check Pandora FMS global $config settings for history database
    if (!empty($config['dbhost_history']) && !empty($config['dbname_history'])) {
        try {
            $h_host = $config['dbhost_history'];
            $h_dbname = $config['dbname_history'];
            $h_user = $config['dbuser_history'] ?? $config['dbuser'];
            $h_pass = $config['dbpass_history'] ?? $config['dbpass'];
            $h_port = !empty($config['dbport_history']) ? (int)$config['dbport_history'] : 3306;
            
            $history_db_host = $h_host . ($h_port != 3306 ? ':' . $h_port : '');
            $history_db_name = $h_dbname;
            $history_db_user = $h_user;
            
            // 2. Build secondary PDO connection with dynamic port wrapped in Try-Catch
            $h_dsn = "mysql:host={$h_host};port={$h_port};dbname={$h_dbname};charset=utf8mb4";
            $pdo_history = new PDO($h_dsn, $h_user, $h_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_TIMEOUT => 2
            ]);
            $history_db_status = true;
        } catch (PDOException $e) {
            error_log("Historical DB Connection via config.php failed: " . $e->getMessage());
        }
    }
    
    // Fallback to tconfig tokens if config.php did not specify history details
    if (!$history_db_status && $db_status) {
        try {
            $stmt = $pdo->query("SELECT token, value FROM tconfig WHERE token IN ('history_host', 'history_port', 'history_db', 'history_user', 'history_pass')");
            $histConfig = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $histConfig[$row['token']] = $row['value'];
            }
            
            if (!empty($histConfig['history_host']) && !empty($histConfig['history_db'])) {
                $h_host = $histConfig['history_host'];
                $h_port = !empty($histConfig['history_port']) ? (int)$histConfig['history_port'] : 3306;
                $h_dbname = $histConfig['history_db'];
                $h_user = $histConfig['history_user'];
                $h_pass = $histConfig['history_pass'];
                
                $history_db_host = $h_host . ($h_port != 3306 ? ':' . $h_port : '');
                $history_db_name = $h_dbname;
                $history_db_user = $h_user;
                
                if (function_exists('io_safe_decrypt')) {
                    $h_pass = io_safe_decrypt($h_pass);
                }
                
                $h_dsn = "mysql:host={$h_host};port={$h_port};dbname={$h_dbname};charset=utf8mb4";
                $pdo_history = new PDO($h_dsn, $h_user, $h_pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => true,
                    PDO::ATTR_TIMEOUT => 2
                ]);
                $history_db_status = true;
            }
        } catch (Throwable $e) {
            error_log("Historical DB Connection via tconfig tokens failed: " . $e->getMessage());
        }
    }
}

// Backward compatibility helper mapping
$history_pdo = $pdo_history;

// 5. LOAD CUSTOM DATABASE CONNECTIONS FROM portal_config_local.json (falls back to portal_config.json)
$portal_config_path = dirname(__DIR__) . '/portal_config_local.json';
if (!file_exists($portal_config_path)) {
    $portal_config_path = dirname(__DIR__) . '/portal_config.json';
}
$custom_connections = [];
$custom_pdos = [];
$custom_db_statuses = [];

if (file_exists($portal_config_path)) {
    $p_config = json_decode(file_get_contents($portal_config_path), true);
    if (is_array($p_config) && isset($p_config['custom_connections']) && is_array($p_config['custom_connections'])) {
        $custom_connections = $p_config['custom_connections'];
        foreach ($custom_connections as $conn) {
            if (!empty($conn['id']) && !empty($conn['host']) && !empty($conn['dbname'])) {
                try {
                    $c_host = $conn['host'];
                    $c_port = !empty($conn['port']) ? (int)$conn['port'] : 3306;
                    $c_dbname = $conn['dbname'];
                    $c_user = $conn['user'] ?? '';
                    $c_pass = $conn['pass'] ?? '';
                    
                    $c_dsn = "mysql:host={$c_host};port={$c_port};dbname={$c_dbname};charset=utf8mb4";
                    $c_pdo = new PDO($c_dsn, $c_user, $c_pass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::ATTR_PERSISTENT => true,
                        PDO::ATTR_TIMEOUT => 2
                    ]);
                    $custom_pdos[$conn['id']] = $c_pdo;
                    $custom_db_statuses[$conn['id']] = true;
                } catch (Throwable $e) {
                    $custom_db_statuses[$conn['id']] = false;
                    $conn_name = !empty($conn['name']) ? $conn['name'] : $conn['id'];
                    error_log("Custom DB Connection '{$conn_name}' failed: " . $e->getMessage());
                }
            }
        }
    }
}

// 6. RESOLVE SERVER UNIQUE IDENTIFIER (UUID) FOR EACH NODE
$node_uuid_to_id = [];
$node_id_to_uuid = [];

// Resolve Primary
$primary_uuid = 'primary';
if ($db_status && $pdo) {
    try {
        $stmt = $pdo->query("SELECT value FROM tconfig WHERE token = 'server_unique_identifier'");
        if ($stmt) {
            $val = $stmt->fetchColumn();
            if (!empty($val)) {
                $primary_uuid = trim($val);
            }
        }
    } catch (Throwable $e) {
        error_log("Failed to resolve primary server_unique_identifier: " . $e->getMessage());
    }
}
$node_uuid_to_id[$primary_uuid] = 'primary';
$node_id_to_uuid['primary'] = $primary_uuid;

// Resolve Custom Connections
if (!empty($custom_pdos)) {
    foreach ($custom_pdos as $cid => $cpdo) {
        $custom_uuid = $cid;
        if ($cpdo) {
            try {
                $stmt = $cpdo->query("SELECT value FROM tconfig WHERE token = 'server_unique_identifier'");
                if ($stmt) {
                    $val = $stmt->fetchColumn();
                    if (!empty($val)) {
                        $custom_uuid = trim($val);
                    }
                }
            } catch (Throwable $e) {
                error_log("Failed to resolve server_unique_identifier for node '{$cid}': " . $e->getMessage());
            }
        }
        $node_uuid_to_id[$custom_uuid] = $cid;
        $node_id_to_uuid[$cid] = $custom_uuid;
    }
}

function get_node_uuid($node) {
    global $node_id_to_uuid;
    return $node_id_to_uuid[$node] ?? $node;
}

/**
 * Common Helper Functions
 */
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pretty_text')) {
    function pretty_text($s) {
        if ($s === null || $s === '') return '';
        $text = (string)$s;
        // Strip common Pandora FMS entity artifacts
        $text = str_replace(['&#x20;', '&#X20;', '&#32;', '&nbsp;', '&#160;', '&amp;#x20;', '&amp;#32;', '#@20;'], ' ', $text);
        for ($i = 0; $i < 3; $i++) {
            if (strpos($text, '&') === false) break;
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $text = str_replace(['&#x20;', '&#X20;', '&#32;', '&nbsp;', '&#160;', '&amp;#x20;', '&amp;#32;', '#@20;'], ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}

if (!function_exists('cleanPandoraText')) {
    function cleanPandoraText($s) {
        return pretty_text($s);
    }
}

function formatInterval($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return 'N/A';
    if ($seconds >= 86400) return round($seconds / 86400, 1) . " days";
    if ($seconds >= 3600) return round($seconds / 3600, 1) . " hours";
    if ($seconds >= 60) return round($seconds / 60, 1) . " minutes";
    return $seconds . " seconds";
}

function timeAgo($datetime) {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00') return 'N/A';
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 0) $diff = 0;
    if ($diff < 10) return "Now";

    $days = floor($diff / 86400);
    $hours = floor(($diff % 86400) / 3600);
    $minutes = floor(($diff % 3600) / 60);

    if ($days > 0) return $days . " days" . ($hours > 0 ? " " . $hours . " hours" : "");
    if ($hours > 0) return $hours . " hours" . ($minutes > 0 ? " " . $minutes . " minutes" : "");
    if ($minutes > 0) return $minutes . " minutes";
    return $diff . " seconds";
}

function downsample_history_data($data, $max_points) {
    $n = count($data);
    if ($n <= $max_points) {
        return $data;
    }
    
    $step = $n / $max_points;
    $sampled = [];
    
    for ($i = 0; $i < $max_points; $i++) {
        $start_idx = (int)floor($i * $step);
        $end_idx = (int)floor(($i + 1) * $step);
        if ($end_idx > $n) $end_idx = $n;
        if ($start_idx >= $end_idx) $start_idx = $end_idx - 1;
        
        $sum_val = 0.0;
        $count_val = 0;
        $timestamps = [];
        $is_numeric = true;
        
        for ($j = $start_idx; $j < $end_idx; $j++) {
            $val = $data[$j]['datos'];
            if (is_numeric($val)) {
                $sum_val += (float)$val;
                $count_val++;
            } else {
                $is_numeric = false;
            }
            $timestamps[] = (int)$data[$j]['ts'];
        }
        
        $avg_ts = count($timestamps) > 0 ? (int)round(array_sum($timestamps) / count($timestamps)) : 0;
        
        if ($is_numeric && $count_val > 0) {
            $avg_val = $sum_val / $count_val;
        } else {
            $avg_val = $data[(int)floor(($start_idx + $end_idx) / 2)]['datos'];
        }
        
        $sampled[] = [
            'ts' => $avg_ts,
            'datos' => $avg_val
        ];
    }
    
    return $sampled;
}


function parse_node_id($prefixed_id) {
    global $node_uuid_to_id;
    if (is_numeric($prefixed_id)) {
        return ['node' => 'primary', 'id' => (int)$prefixed_id];
    }
    if (is_string($prefixed_id) && strpos($prefixed_id, ':') !== false) {
        list($node, $id) = explode(':', $prefixed_id, 2);
        $conn_id = $node_uuid_to_id[$node] ?? $node;
        return ['node' => $conn_id, 'id' => (int)$id];
    }
    return ['node' => 'primary', 'id' => 0];
}

function parse_node_ids($prefixed_ids_str) {
    if (empty($prefixed_ids_str)) return [];
    $ids_by_node = [];
    $parts = array_filter(explode(',', $prefixed_ids_str));
    foreach ($parts as $part) {
        $parsed = parse_node_id($part);
        $ids_by_node[$parsed['node']][] = $parsed['id'];
    }
    return $ids_by_node;
}

function get_module_history_data($pdo, $pdo_history, $id_mod, $start, $end, $limit = 5000, $order = 'DESC') {
    $parsed = parse_node_id($id_mod);
    $node = $parsed['node'];
    $id_mod = $parsed['id'];

    global $custom_pdos;
    $active_pdo = ($node === 'primary') ? $pdo : ($custom_pdos[$node] ?? null);
    if ($active_pdo === null) {
        return [];
    }

    // 1. Determine the specific table for this module to optimize query performance (Numeric/String/Inc)
    $target_table = null;
    $mod_meta = null;
    try {
        $stType = $active_pdo->prepare("SELECT m.id_tipo_modulo, t.name, m.min, m.max, m.unit, m.nombre FROM tagente_modulo m JOIN ttipo_modulo t ON m.id_tipo_modulo = t.id_tipo_modulo WHERE m.id_agente_modulo = ?");
        $stType->execute([$id_mod]);
        $row = $stType->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $mod_meta = $row;
            $typeId = (int)$row['id_tipo_modulo'];
            $typeName = strtolower((string)$row['name']);
            
            if (in_array($typeId, [3, 12]) || strpos($typeName, 'string') !== false) {
                $target_table = 'tagente_datos_string';
            } else {
                // In Pandora FMS, computed rates for incremental modules as well as numeric values 
                // are stored in tagente_datos. tagente_datos_inc only holds the current raw counter 
                // and should not be used for historical charts.
                $target_table = 'tagente_datos';
            }
        }
    } catch (Throwable $e) {
        error_log("Failed to determine module type table: " . $e->getMessage());
    }

    // Determine validity bounds to filter out corrupted outliers / glitch values
    $min_bound = null;
    $max_bound = null;
    if ($mod_meta && $target_table === 'tagente_datos') {
        $raw_unit = trim($mod_meta['unit'] ?? '');
        $raw_nombre = strtolower(trim($mod_meta['nombre'] ?? ''));
        $mod_min = isset($mod_meta['min']) ? (float)$mod_meta['min'] : 0.0;
        $mod_max = isset($mod_meta['max']) ? (float)$mod_meta['max'] : 0.0;

        $is_percent = ($raw_unit === '%' || strpos($raw_unit, '%') !== false || strpos($raw_nombre, ' in %') !== false || strpos($raw_nombre, ' %') !== false);
        
        if ($is_percent) {
            // Percentage metrics must strictly stay within [0, 100] unless configured otherwise
            $min_bound = ($mod_min != 0.0) ? $mod_min : 0.0;
            $max_bound = ($mod_max > 0.0) ? $mod_max : 100.0;
        } else {
            // Non-percentage numeric metrics: respect explicit positive max or non-zero min if configured
            if ($mod_max > 0.0) {
                $max_bound = $mod_max;
            }
            if ($mod_min != 0.0) {
                $min_bound = $mod_min;
            }
        }
    }

    $historyData = [];
    $activeData = [];

    // 2. Query history database first (if connection exists)
    $all_history_pdos = [];
    if ($node === 'primary') {
        if ($pdo_history !== null) {
            $all_history_pdos['default'] = $pdo_history;
        }
    } else {
        $all_history_pdos[$node] = $active_pdo;
    }

    foreach ($all_history_pdos as $h_pdo) {
        if ($target_table !== null) {
            try {
                $stmtHist = $h_pdo->prepare("SELECT utimestamp as ts, datos FROM `$target_table` WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? ORDER BY utimestamp DESC LIMIT " . (int)$limit);
                $stmtHist->execute([$id_mod, $start, $end]);
                $res = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($res)) {
                    $historyData = array_merge($historyData, $res);
                }
                
                // FALLBACK: If query succeeded but returned 0 rows, and we didn't query tagente_datos originally,
                // try tagente_datos since Pandora FMS can archive all types of history data there
                if (empty($res) && $target_table !== 'tagente_datos') {
                    $stmtHist2 = $h_pdo->prepare("SELECT utimestamp as ts, datos FROM tagente_datos WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? ORDER BY utimestamp DESC LIMIT " . (int)$limit);
                    $stmtHist2->execute([$id_mod, $start, $end]);
                    $res2 = $stmtHist2->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($res2)) {
                        $historyData = array_merge($historyData, $res2);
                    }
                }
            } catch (Throwable $e) {
                // If it fails (e.g., table doesn't exist in history DB), fall back to tagente_datos
                if ($target_table !== 'tagente_datos') {
                    try {
                        $stmtHist = $h_pdo->prepare("SELECT utimestamp as ts, datos FROM tagente_datos WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? ORDER BY utimestamp DESC LIMIT " . (int)$limit);
                        $stmtHist->execute([$id_mod, $start, $end]);
                        $res = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($res)) {
                            $historyData = array_merge($historyData, $res);
                        }
                    } catch (Throwable $e2) {
                        error_log("Historical fallback query to tagente_datos failed: " . $e2->getMessage());
                    }
                }
            }
        } else {
            // Fallback to querying all tables individually if type is undetermined
            $tables = ['tagente_datos', 'tagente_datos_string', 'tagente_datos_inc'];
            foreach ($tables as $tbl) {
                try {
                    $stmtHist = $h_pdo->prepare("SELECT utimestamp as ts, datos FROM `$tbl` WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? ORDER BY utimestamp DESC LIMIT " . (int)$limit);
                    $stmtHist->execute([$id_mod, $start, $end]);
                    $res = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($res)) {
                        $historyData = array_merge($historyData, $res);
                    }
                } catch (Throwable $e) {
                    // Ignore missing tables
                }
            }
        }
    }

    // 3. Query active database
    if ($target_table !== null) {
        try {
            $stmt = $active_pdo->prepare("SELECT utimestamp as ts, datos FROM `$target_table` WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? ORDER BY utimestamp DESC LIMIT " . (int)$limit);
            $stmt->execute([$id_mod, $start, $end]);
            $activeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("Active DB query for $target_table failed: " . $e->getMessage());
        }
    } else {
        // Fallback to UNION query if type is undetermined
        $query = "SELECT utimestamp as ts, datos FROM tagente_datos WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ?
                  UNION ALL
                  SELECT utimestamp as ts, datos FROM tagente_datos_string WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ?
                  UNION ALL
                  SELECT utimestamp as ts, datos FROM tagente_datos_inc WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ?
                  ORDER BY ts DESC LIMIT " . (int)$limit;
        try {
            $stmt = $active_pdo->prepare($query);
            $stmt->execute([$id_mod, $start, $end, $id_mod, $start, $end, $id_mod, $start, $end]);
            $activeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("Active DB query error: " . $e->getMessage());
        }
    }

    // 4. Query last value before start to prevent empty charts for static modules
    $preData = null;
    if ($target_table !== null) {
        try {
            $stmtLookback = $active_pdo->prepare("SELECT utimestamp as ts, datos FROM `$target_table` WHERE id_agente_modulo = ? AND utimestamp < ? ORDER BY utimestamp DESC LIMIT 1");
            $stmtLookback->execute([$id_mod, $start]);
            $preData = $stmtLookback->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("Active DB lookback query error: " . $e->getMessage());
        }
    } else {
        $lookback_query = "SELECT utimestamp as ts, datos FROM tagente_datos WHERE id_agente_modulo = ? AND utimestamp < ?
                           UNION ALL
                           SELECT utimestamp as ts, datos FROM tagente_datos_string WHERE id_agente_modulo = ? AND utimestamp < ?
                           UNION ALL
                           SELECT utimestamp as ts, datos FROM tagente_datos_inc WHERE id_agente_modulo = ? AND utimestamp < ?
                           ORDER BY ts DESC LIMIT 1";
        try {
            $stmtLookback = $active_pdo->prepare($lookback_query);
            $stmtLookback->execute([$id_mod, $start, $id_mod, $start, $id_mod, $start]);
            $preData = $stmtLookback->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("Active DB lookback query error: " . $e->getMessage());
        }
    }

    if (!$preData && !empty($all_history_pdos)) {
        $tables = ['tagente_datos', 'tagente_datos_string', 'tagente_datos_inc'];
        $best_ts = 0;
        foreach ($all_history_pdos as $h_pdo) {
            foreach ($tables as $tbl) {
                try {
                    $stmtLookbackHist = $h_pdo->prepare("SELECT utimestamp as ts, datos FROM `$tbl` WHERE id_agente_modulo = ? AND utimestamp < ? ORDER BY utimestamp DESC LIMIT 1");
                    $stmtLookbackHist->execute([$id_mod, $start]);
                    $row = $stmtLookbackHist->fetch(PDO::FETCH_ASSOC);
                    if ($row && (int)$row['ts'] > $best_ts) {
                        $best_ts = (int)$row['ts'];
                        $preData = $row;
                    }
                } catch (Throwable $e) {
                    // Ignore if table does not exist
                }
            }
        }
    }

    if ($preData) {
        $pNum = is_numeric($preData['datos']) ? (float)$preData['datos'] : null;
        $pValid = true;
        if ($target_table === 'tagente_datos' && $pNum !== null) {
            if ($max_bound !== null && $pNum > $max_bound) $pValid = false;
            if ($min_bound !== null && $pNum < $min_bound) $pValid = false;
        }
        if ($pValid) {
            $preData['ts'] = (int)$start;
            $historyData[] = $preData;
        }
    }

    // Merge results using array_merge
    $merged = array_merge($historyData, $activeData);

    // Deduplicate by timestamp and discard corrupted outliers outside module thresholds
    $unique = [];
    foreach ($merged as $item) {
        if ($target_table === 'tagente_datos' && is_numeric($item['datos'])) {
            $num = (float)$item['datos'];
            if ($max_bound !== null && $num > $max_bound) {
                continue; // Discard outlier above max / 100%
            }
            if ($min_bound !== null && $num < $min_bound) {
                continue; // Discard outlier below min / 0%
            }
        }
        $unique[$item['ts']] = $item;
    }
    $result = array_values($unique);

    // Sort chronologically ascending (ASC) for downsampling/charting consistency
    usort($result, function($a, $b) {
        $tsA = (int)$a['ts'];
        $tsB = (int)$b['ts'];
        if ($tsA === $tsB) return 0;
        return ($tsA < $tsB) ? -1 : 1;
    });

    // Downsample if count exceeds limit to preserve the entire time-range
    if (count($result) > $limit) {
        $result = downsample_history_data($result, $limit);
    }

    // If descending order was requested, sort it DESC (newest first)
    if ($order === 'DESC') {
        usort($result, function($a, $b) {
            $tsA = (int)$a['ts'];
            $tsB = (int)$b['ts'];
            if ($tsA === $tsB) return 0;
            return ($tsA > $tsB) ? -1 : 1;
        });
    }

    return $result;
}

/**
 * Batch retrieves historical data points for a list of module IDs.
 * Eliminates N+1 query problem by batch-querying each node's databases.
 */
function get_modules_history_data_batch($pdo, $pdo_history, $modIds, $start, $end, $limit_per_module = 2000) {
    if (empty($modIds)) return [];

    // Group modIds by node
    $node_mods = [];
    foreach ($modIds as $modId) {
        $parsed = parse_node_id($modId);
        $node_mods[$parsed['node']][] = $parsed['id'];
    }

    global $custom_pdos;
    $results = [];

    foreach ($node_mods as $node => $ids) {
        $active_pdo = ($node === 'primary') ? $pdo : ($custom_pdos[$node] ?? null);
        if ($active_pdo === null) continue;

        // Determine which database to query for history
        $h_pdos = [];
        if ($node === 'primary') {
            if ($pdo_history !== null) {
                $h_pdos[] = $pdo_history;
            } else {
                $h_pdos[] = $active_pdo;
            }
        } else {
            $h_pdos[] = $active_pdo;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $max_limit = min(5000, count($ids) * 500);

        // Fetch module boundaries to discard corrupted outliers
        $bounds_map = [];
        try {
            $stMeta = $active_pdo->prepare("SELECT id_agente_modulo, unit, min, max, nombre FROM tagente_modulo WHERE id_agente_modulo IN ($placeholders)");
            $stMeta->execute($ids);
            while ($mRow = $stMeta->fetch(PDO::FETCH_ASSOC)) {
                $u = trim($mRow['unit'] ?? '');
                $nm = strtolower(trim($mRow['nombre'] ?? ''));
                $mn = isset($mRow['min']) ? (float)$mRow['min'] : 0.0;
                $mx = isset($mRow['max']) ? (float)$mRow['max'] : 0.0;
                $is_pct = ($u === '%' || strpos($u, '%') !== false || strpos($nm, ' in %') !== false || strpos($nm, ' %') !== false);
                $bounds_map[$mRow['id_agente_modulo']] = [
                    'min' => $is_pct ? ($mn != 0.0 ? $mn : 0.0) : ($mn != 0.0 ? $mn : null),
                    'max' => $is_pct ? ($mx > 0.0 ? $mx : 100.0) : ($mx > 0.0 ? $mx : null),
                ];
            }
        } catch (Throwable $e) {}

        // Fast Path: Query indexed numeric table `tagente_datos` directly (99.9% of metrics)
        $direct_sql = "SELECT id_agente_modulo, utimestamp as ts, datos FROM tagente_datos WHERE id_agente_modulo IN ($placeholders) AND utimestamp BETWEEN ? AND ? ORDER BY utimestamp DESC LIMIT $max_limit";
        $direct_params = array_merge($ids, [$start, $end]);

        $node_data = [];
        foreach ($h_pdos as $h_pdo) {
            try {
                $stmt = $h_pdo->prepare($direct_sql);
                $stmt->execute($direct_params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $mid = $row['id_agente_modulo'];
                        if (isset($bounds_map[$mid]) && is_numeric($row['datos'])) {
                            $vNum = (float)$row['datos'];
                            $b = $bounds_map[$mid];
                            if ($b['max'] !== null && $vNum > $b['max']) continue;
                            if ($b['min'] !== null && $vNum < $b['min']) continue;
                        }
                        $prefixed_mod_id = $node . ':' . $mid;
                        $node_data[] = [
                            'id_mod' => $prefixed_mod_id,
                            'ts' => (int)$row['ts'],
                            'datos' => $row['datos']
                        ];
                    }
                    break;
                }
            } catch (Throwable $e) {
                error_log("Batch direct history query failed for node $node: " . $e->getMessage());
            }
        }

        // Fallback Path: Check string/incremental tables if direct query returned no rows (e.g. string/inc modules)
        if (empty($node_data)) {
            $queries = [];
            $fallback_params = [];
            $fallback_tables = ['tagente_datos_inc', 'tagente_datos_string'];
            foreach ($fallback_tables as $tbl) {
                $queries[] = "SELECT id_agente_modulo, utimestamp as ts, datos FROM `$tbl` WHERE id_agente_modulo IN ($placeholders) AND utimestamp BETWEEN ? AND ?";
                foreach ($ids as $id) $fallback_params[] = $id;
                $fallback_params[] = $start;
                $fallback_params[] = $end;
            }
            $fallback_union_sql = implode(" UNION ALL ", $queries) . " ORDER BY ts DESC LIMIT $max_limit";

            foreach ($h_pdos as $h_pdo) {
                try {
                    $stmt = $h_pdo->prepare($fallback_union_sql);
                    $stmt->execute($fallback_params);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        foreach ($rows as $row) {
                            $prefixed_mod_id = $node . ':' . $row['id_agente_modulo'];
                            $node_data[] = [
                                'id_mod' => $prefixed_mod_id,
                                'ts' => (int)$row['ts'],
                                'datos' => $row['datos']
                            ];
                        }
                        break;
                    }
                } catch (Throwable $e) {
                    error_log("Batch fallback history query failed for node $node: " . $e->getMessage());
                }
            }
        }

        // Fallback to active_pdo if primary history pdo was empty
        if (empty($node_data) && $node === 'primary' && $pdo_history !== null) {
            try {
                $stmt = $active_pdo->prepare($direct_sql);
                $stmt->execute($direct_params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $prefixed_mod_id = $node . ':' . $row['id_agente_modulo'];
                    $node_data[] = [
                        'id_mod' => $prefixed_mod_id,
                        'ts' => (int)$row['ts'],
                        'datos' => $row['datos']
                    ];
                }
            } catch (Throwable $e) {
                error_log("Batch history fallback query failed for primary node: " . $e->getMessage());
            }
        }

        $results = array_merge($results, $node_data);
    }

    return $results;
}

// =====================================================================
// 5. SECURITY & ACCESS CONTROL: PANDORA ADMINISTRATOR PROFILE RESTRICTION
// =====================================================================

/**
 * Checks if the current user has the 'Pandora Administrator' profile.
 * Verifies against:
 * 1. Default superadmin username 'admin'
 * 2. Session flag `$_SESSION['is_admin']`
 * 3. Table `tusuario.is_admin = 1` or `tusuario.id_perfil = 1`
 * 4. Profile assignment table `tusuario_perfil` joined with `tperfil`
 */
function is_pandora_administrator($pdo, $user_id): bool {
    if (empty($user_id)) return false;

    // Fast check: root admin account
    if (strtolower(trim((string)$user_id)) === 'admin') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['pfms_checked_user'] = $user_id;
            $_SESSION['pfms_is_pandora_admin'] = true;
        }
        return true;
    }

    // Session cache check (per-session and per-user)
    if (session_status() === PHP_SESSION_ACTIVE &&
        isset($_SESSION['pfms_checked_user']) && 
        $_SESSION['pfms_checked_user'] === $user_id && 
        isset($_SESSION['pfms_is_pandora_admin'])) {
        return (bool)$_SESSION['pfms_is_pandora_admin'];
    }

    // Session is_admin flag check
    if (!empty($_SESSION['is_admin']) && ((int)$_SESSION['is_admin'] === 1 || $_SESSION['is_admin'] === true)) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['pfms_checked_user'] = $user_id;
            $_SESSION['pfms_is_pandora_admin'] = true;
        }
        return true;
    }

    if (!$pdo || !($pdo instanceof PDO)) {
        return false;
    }

    $is_admin = false;

    try {
        // 1. Check tusuario table
        $stUser = $pdo->prepare("SELECT is_admin, id_perfil FROM tusuario WHERE id_user = :user_id LIMIT 1");
        $stUser->execute([':user_id' => $user_id]);
        $userRow = $stUser->fetch(PDO::FETCH_ASSOC);

        if ($userRow) {
            if (!empty($userRow['is_admin']) && (int)$userRow['is_admin'] === 1) {
                $is_admin = true;
            } elseif (isset($userRow['id_perfil']) && (int)$userRow['id_perfil'] === 1) {
                $is_admin = true;
            }
        }

        // 2. Check tusuario_perfil joined with tperfil (matches profile name 'Pandora Administrator')
        if (!$is_admin) {
            $sql = "SELECT p.name, p.id_perfil 
                    FROM tusuario_perfil up 
                    JOIN tperfil p ON up.id_perfil = p.id_perfil 
                    WHERE up.id_user = :user_id";
            $stProf = $pdo->prepare($sql);
            $stProf->execute([':user_id' => $user_id]);
            $profiles = $stProf->fetchAll(PDO::FETCH_ASSOC);

            foreach ($profiles as $prof) {
                $pName = strtolower(trim((string)($prof['name'] ?? '')));
                $pId = (int)($prof['id_perfil'] ?? 0);

                if ($pId === 1 || 
                    $pName === 'pandora administrator' || 
                    strpos($pName, 'pandora administrator') !== false ||
                    $pName === 'administrator') {
                    $is_admin = true;
                    break;
                }
            }
        }
    } catch (Throwable $e) {
        error_log("PFMS Security: Error checking Pandora Administrator profile: " . $e->getMessage());
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['pfms_checked_user'] = $user_id;
        $_SESSION['pfms_is_pandora_admin'] = $is_admin;
    }

    return $is_admin;
}

/**
 * Returns list of profile names assigned to the specified user.
 */
function get_user_pandora_profiles($pdo, $user_id): array {
    if (empty($user_id) || !$pdo || !($pdo instanceof PDO)) return [];
    $names = [];
    try {
        $stProf = $pdo->prepare("SELECT DISTINCT p.name FROM tusuario_perfil up JOIN tperfil p ON up.id_perfil = p.id_perfil WHERE up.id_user = :user_id");
        $stProf->execute([':user_id' => $user_id]);
        while ($r = $stProf->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($r['name'])) $names[] = trim($r['name']);
        }
    } catch (Throwable $e) {}
    return $names;
}

/**
 * Returns list of all available Pandora FMS profiles from tperfil.
 */
function get_all_pandora_profiles($pdo): array {
    if (!$pdo || !($pdo instanceof PDO)) return [];
    try {
        $stmt = $pdo->query("SELECT id_perfil, name FROM tperfil ORDER BY name ASC");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Returns list of all available Pandora FMS users from tusuario.
 */
function get_all_pandora_users($pdo): array {
    if (!$pdo || !($pdo instanceof PDO)) return [];
    try {
        $stmt = $pdo->query("SELECT id_user, comments, email FROM tusuario ORDER BY id_user ASC");
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Checks if a user has 'view' or 'edit' permission for a given dashboard ACL configuration.
 *
 * @param array|null $acl The dashboard's access_control object
 * @param string $user_id The active Pandora FMS user ID
 * @param string $action 'view' or 'edit'
 * @param PDO|null $pdo Database PDO instance
 * @return bool True if permitted, false otherwise
 */
function check_dashboard_access(?array $acl, string $user_id, string $action = 'view', $pdo = null): bool {
    if (empty($user_id)) return false;

    // Super Admin (Pandora Administrator) ALWAYS has full access
    if ($pdo && is_pandora_administrator($pdo, $user_id)) {
        return true;
    }

    if (empty($acl) || !is_array($acl)) {
        // Default backward-compatible behavior: View is open to all authenticated users, Edit is admin-only
        return ($action === 'view');
    }

    $user_profiles = ($pdo) ? get_user_pandora_profiles($pdo, $user_id) : [];
    $user_profiles_lower = array_map('strtolower', array_map('trim', $user_profiles));
    $user_id_lower = strtolower(trim($user_id));

    if ($action === 'edit' || $action === 'manage') {
        $edit_policy = $acl['edit_policy'] ?? 'admin_only';
        if ($edit_policy === 'admin_only') {
            return false;
        }
        if ($edit_policy === 'profiles') {
            $allowed_profiles = array_map('strtolower', array_map('trim', $acl['edit_profiles'] ?? []));
            foreach ($user_profiles_lower as $up) {
                if (in_array($up, $allowed_profiles, true)) return true;
            }
            return false;
        }
        if ($edit_policy === 'users') {
            $allowed_users = array_map('strtolower', array_map('trim', $acl['edit_users'] ?? []));
            return in_array($user_id_lower, $allowed_users, true);
        }
        return false;
    }

    // View action
    $view_policy = $acl['view_policy'] ?? 'all';
    if ($view_policy === 'all') {
        return true;
    }
    if ($view_policy === 'profiles') {
        $allowed_profiles = array_map('strtolower', array_map('trim', $acl['view_profiles'] ?? []));
        foreach ($user_profiles_lower as $up) {
            if (in_array($up, $allowed_profiles, true)) return true;
        }
        return false;
    }
    if ($view_policy === 'users') {
        $allowed_users = array_map('strtolower', array_map('trim', $acl['view_users'] ?? []));
        return in_array($user_id_lower, $allowed_users, true);
    }

    return false;
}

/**
 * Checks if a user or any of their profiles has view permission on at least one dashboard.
 */
function user_has_any_dashboard_access(string $user_id, $pdo = null): bool {
    if (empty($user_id)) return false;
    if ($pdo && is_pandora_administrator($pdo, $user_id)) return true;

    $base = dirname(__DIR__);
    $files = [
        $base . '/Dashboard/Topology-Network/topology_dashboards.json',
        $base . '/Dashboard/Metrics-Dashboard/metrics-dashboards-saved.json',
        $base . '/Dashboard/Dynamic-Dashboard/dynamic-dashboards-master.json',
        $base . '/Dashboard/Traffic-Dashboard/traffic-dashboard-saved.json'
    ];

    foreach ($files as $file) {
        if (file_exists($file)) {
            $content = @file_get_contents($file);
            $dashboards = json_decode($content, true);
            if (is_array($dashboards)) {
                foreach ($dashboards as $d) {
                    $acl = $d['access_control'] ?? null;
                    if (check_dashboard_access($acl, $user_id, 'view', $pdo)) {
                        return true;
                    }
                }
            }
        }
    }

    return false;
}

/**
 * Renders a secure 403 Forbidden Access Denied page when a non-admin tries to access PFMS-Toolkit.
 */
function render_pfms_access_denied(string $user_id, string $pandora_base = '/pandora_console', $pdo = null): void {
    if (!headers_sent()) {
        http_response_code(403);
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    }

    // Clean JSON response for API or AJAX calls
    if (!empty($_GET['api']) || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Access Denied (403 Forbidden): Only the Pandora Administrator profile is authorized to access PFMS-Toolkit features.'
        ]);
        exit;
    }

    $profiles = ($pdo && !empty($user_id)) ? get_user_pandora_profiles($pdo, $user_id) : [];
    $profileText = !empty($profiles) ? implode(', ', array_map('htmlspecialchars', $profiles)) : 'Not Pandora Administrator';
    $vendor_url = rtrim($pandora_base, '/') . '/custom/panel/vendor';
    $login_url = rtrim($pandora_base, '/') . '/index.php';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>403 Access Denied - PFMS Toolkit</title>
        <link rel="stylesheet" href="<?= htmlspecialchars($vendor_url) ?>/fonts/fonts.css">
        <style>
            :root {
                --brand-green: #004d40;
                --brand-green-hover: #00695c;
                --primary-navy: #0b1a26;
                --danger-red: #ef4444;
                --border-color: #e2e8f0;
            }
            * { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Inter', system-ui, -apple-system, sans-serif;
                background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
                color: #1e293b;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
            }
            .denied-card {
                background: #ffffff;
                border: 1px solid #cbd5e1;
                border-radius: 16px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.08);
                max-width: 520px;
                width: 100%;
                overflow: hidden;
                text-align: center;
                animation: popIn 0.25s ease-out;
            }
            @keyframes popIn {
                from { transform: scale(0.96); opacity: 0; }
                to { transform: scale(1); opacity: 1; }
            }
            .denied-header {
                background: #fef2f2;
                border-bottom: 1px solid #fee2e2;
                padding: 32px 24px 24px 24px;
                display: flex;
                flex-direction: column;
                align-items: center;
            }
            .denied-icon-wrap {
                width: 64px;
                height: 64px;
                border-radius: 50%;
                background: #fee2e2;
                color: #dc2626;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 14px;
                box-shadow: 0 0 0 8px rgba(239, 68, 68, 0.12);
            }
            .denied-icon-wrap .material-symbols-outlined {
                font-size: 36px;
            }
            .denied-title {
                font-size: 20px;
                font-weight: 800;
                color: #991b1b;
                margin-bottom: 6px;
            }
            .denied-subtitle {
                font-size: 13px;
                color: #b91c1c;
                font-weight: 500;
            }
            .denied-body {
                padding: 28px 24px;
                font-size: 13.5px;
                line-height: 1.6;
                color: #475569;
            }
            .info-box {
                background: #f8fafc;
                border: 1px solid #e2e8f0;
                border-radius: 10px;
                padding: 14px 18px;
                margin: 20px 0;
                text-align: left;
                font-size: 12.5px;
            }
            .info-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 6px 0;
                border-bottom: 1px dashed #e2e8f0;
            }
            .info-row:last-child {
                border-bottom: none;
            }
            .info-label {
                color: #64748b;
                font-weight: 600;
            }
            .info-val {
                color: #0f172a;
                font-weight: 700;
                font-family: monospace;
                background: #ffffff;
                padding: 2px 8px;
                border-radius: 4px;
                border: 1px solid #cbd5e1;
            }
            .info-required {
                color: #059669;
                font-weight: 700;
                background: #ecfdf5;
                padding: 2px 8px;
                border-radius: 4px;
                border: 1px solid #a7f3d0;
            }
            .denied-footer {
                padding: 16px 24px 24px 24px;
                display: flex;
                gap: 12px;
                justify-content: center;
            }
            .btn-primary {
                background: var(--brand-green);
                color: #ffffff;
                border: none;
                border-radius: 8px;
                padding: 10px 20px;
                font-size: 13px;
                font-weight: 600;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                cursor: pointer;
                transition: background 0.15s ease;
            }
            .btn-primary:hover {
                background: var(--brand-green-hover);
            }
            .btn-secondary {
                background: #ffffff;
                color: #475569;
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                padding: 10px 18px;
                font-size: 13px;
                font-weight: 600;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                cursor: pointer;
                transition: all 0.15s ease;
            }
            .btn-secondary:hover {
                background: #f1f5f9;
                color: #0f172a;
            }
        </style>
    </head>
    <body>
        <div class="denied-card">
            <div class="denied-header">
                <div class="denied-icon-wrap">
                    <span class="material-symbols-outlined">gpp_bad</span>
                </div>
                <h1 class="denied-title">Access Denied (403 Forbidden)</h1>
                <div class="denied-subtitle">Insufficient Permissions</div>
            </div>
            <div class="denied-body">
                <p>This <strong>PFMS-Toolkit</strong> page is restricted and can only be accessed by users with the <strong>Pandora Administrator</strong> profile.</p>
                
                <div class="info-box">
                    <div class="info-row">
                        <span class="info-label">Your Account:</span>
                        <span class="info-val"><?= htmlspecialchars($user_id) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Detected Profiles:</span>
                        <span style="color:#b91c1c; font-weight:600;"><?= $profileText ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Required Profile:</span>
                        <span class="info-required">Pandora Administrator</span>
                    </div>
                </div>

                <p style="font-size:12.5px; color:#64748b;">Please contact your system administrator or sign in using an account with Pandora Administrator privileges.</p>
            </div>
            <div class="denied-footer">
                <a href="<?= htmlspecialchars($login_url) ?>" class="btn-primary">
                    <span class="material-symbols-outlined" style="font-size:18px;">arrow_back</span>
                    Back to Pandora FMS
                </a>
                <a href="<?= htmlspecialchars($login_url) ?>?bye=1" class="btn-secondary">
                    <span class="material-symbols-outlined" style="font-size:18px;">logout</span>
                    Switch Account
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
}

// Global Automated Guard: Enforce strict access control between Management Portal and Shared/Embedded Dashboards
if (php_sapi_name() !== 'cli') {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    $active_user = $_SESSION['id_usuario'] ?? '';

    // Check if this request is an embed / shared dashboard or widget (e.g. inside Pandora FMS dashboard or NOC wallboard)
    $is_embed_request = !empty($_GET['embed']) 
        || !empty($_GET['standalone']) 
        || (isset($_GET['s']) && ($_GET['s'] === '1' || $_GET['s'] === 1 || $_GET['s'] === 'true'))
        || (isset($_GET['pure']) && $_GET['pure'] === '1')
        || (isset($_GET['minimal']) && $_GET['minimal'] === '1')
        || (!empty($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe')
        || (!empty($_SERVER['HTTP_REFERER']) && (
            strpos($_SERVER['HTTP_REFERER'], 'sec=dashboard') !== false ||
            strpos($_SERVER['HTTP_REFERER'], 'sec=operation') !== false ||
            strpos($_SERVER['HTTP_REFERER'], 'embed=') !== false ||
            strpos($_SERVER['HTTP_REFERER'], 'standalone=') !== false ||
            strpos($_SERVER['HTTP_REFERER'], 's=1') !== false
        ));

    if (!empty($active_user) && isset($pdo) && ($pdo instanceof PDO)) {
        $is_admin = is_pandora_administrator($pdo, $active_user);

        if (!$is_admin) {
            // STRICT VIEW-ONLY ENFORCEMENT: Non-administrators can NEVER perform write/mutating/management operations
            $is_write_action = ($_SERVER['REQUEST_METHOD'] === 'POST') ||
                               (!empty($_GET['action']) && preg_match('/save|delete|create|edit|update|import|reset|remove|connect/i', (string)$_GET['action'])) ||
                               (!empty($_GET['api']) && preg_match('/save|delete|create|edit|update|import|reset|remove|connect/i', (string)$_GET['api']));

            if ($is_write_action) {
                while (ob_get_level() > 0) ob_end_clean();
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode([
                    'ok' => false, 
                    'error' => 'Restricted Access (View Only): This dashboard is View Only. Only accounts with the Pandora Administrator profile can modify, edit, or manage configurations.'
                ]);
                exit;
            }

            // If it's a direct browser access (NOT an embed/shared dashboard/widget), allow access if user or their profile has view permission on at least one dashboard
            if (!$is_embed_request) {
                $has_dash_access = user_has_any_dashboard_access((string)$active_user, $pdo);
                if (!$has_dash_access) {
                    $p_base = !empty($PANDORA_BASE_URL) ? $PANDORA_BASE_URL : '/pandora_console';
                    render_pfms_access_denied((string)$active_user, $p_base, $pdo);
                    exit;
                }
            }
        }
    }
}

