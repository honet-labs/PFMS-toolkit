<?php
// Global Security Headers
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

/**
 * db-connection.php
 * Core configuration and database connection for Pandora FMS Custom Panel
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// 1. DYNAMIC BREADCRUMB LOGIC
$raw_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$dir_only = dirname($raw_path);
$clean_path = trim($dir_only, '/');
$path_array = explode('/', $clean_path);
$formatted_array = array_map(function($p) {
    return ucwords(str_replace(['_', '-'], ' ', $p));
}, $path_array);
$dynamic_breadcrumb = implode(' / ', $formatted_array);

// 2. PANDORA FMS BASE CONFIG
$PANDORA_BASE_URL = "/pandora_console";

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

// 3. DATABASE INITIALIZATION (PDO)
$pdo = null;
$db_status = false;
$db_error = '';

if ($config_loaded) {
    try {
        $dsn = "mysql:host=" . $config["dbhost"] . ";dbname=" . $config["dbname"] . ";charset=utf8mb4";
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
$history_pdo = null;
$history_db_status = false;

if ($db_status) {
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
            
            if (function_exists('io_safe_decrypt')) {
                $h_pass = io_safe_decrypt($h_pass);
            }
            
            $h_dsn = "mysql:host={$h_host};port={$h_port};dbname={$h_dbname};charset=utf8mb4";
            $history_pdo = new PDO($h_dsn, $h_user, $h_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true
            ]);
            $history_db_status = true;
        }
    } catch (Exception $e) {
        error_log("Historical DB Connection error: " . $e->getMessage());
    }
}

/**
 * Common Helper Functions
 */

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function pretty_text($s) {
    if ($s === null || $s === '') return '';
    $text = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return str_replace(['&#x20;', '&nbsp;'], ' ', $text);
}

function cleanPandoraText($s) {
    return pretty_text($s);
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

function get_module_history_data($pdo, $history_pdo, $id_mod, $start, $end, $limit = 500, $order = 'DESC') {
    // Use a large database limit to capture the entire date range, then downsample in PHP
    $db_limit = 50000;
    $query = "SELECT utimestamp as ts, datos FROM tagente_datos WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ?
              UNION ALL
              SELECT utimestamp as ts, datos FROM tagente_datos_string WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ?
              UNION ALL
              SELECT utimestamp as ts, datos FROM tagente_datos_inc WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ?
              ORDER BY ts ASC LIMIT {$db_limit}";

    // 1. Fetch from active database
    $activeData = [];
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id_mod, $start, $end, $id_mod, $start, $end, $id_mod, $start, $end]);
        $activeData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Active DB query error: " . $e->getMessage());
    }

    // 2. Fetch from historical database if available
    $historyData = [];
    if ($history_pdo) {
        try {
            $stmtHist = $history_pdo->prepare($query);
            $stmtHist->execute([$id_mod, $start, $end, $id_mod, $start, $end, $id_mod, $start, $end]);
            $historyData = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Historical DB query error: " . $e->getMessage());
        }
    }

    // 3. Merge and deduplicate by exact timestamp
    $merged = array_merge($activeData, $historyData);
    $unique = [];
    foreach ($merged as $item) {
        $unique[$item['ts']] = $item;
    }

    // 4. Sort chronologically (ASC) for downsampling
    ksort($unique);
    $result = array_values($unique);

    // 5. Downsample to the requested limit if we exceed it
    if (count($result) > $limit) {
        $result = downsample_history_data($result, $limit);
    }

    // 6. Apply requested ordering
    if ($order === 'DESC') {
        $result = array_reverse($result);
    }

    return $result;
}
