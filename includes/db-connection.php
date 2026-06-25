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
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

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

// 5. LOAD CUSTOM DATABASE CONNECTIONS FROM portal_config.json
$portal_config_path = dirname(__DIR__) . '/portal_config.json';
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
                } catch (PDOException $e) {
                    $custom_db_statuses[$conn['id']] = false;
                    error_log("Custom DB Connection '{$conn['name']}' failed: " . $e->getMessage());
                }
            }
        }
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

function get_module_history_data($pdo, $pdo_history, $id_mod, $start, $end, $limit = 5000, $order = 'DESC') {
    // 1. Determine the specific table for this module to optimize query performance (Numeric/String/Inc)
    $target_table = null;
    try {
        $stType = $pdo->prepare("SELECT m.id_tipo_modulo, t.name FROM tagente_modulo m JOIN ttipo_modulo t ON m.id_tipo_modulo = t.id_tipo_modulo WHERE m.id_agente_modulo = ?");
        $stType->execute([$id_mod]);
        $row = $stType->fetch(PDO::FETCH_ASSOC);
        if ($row) {
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

    $historyData = [];
    $activeData = [];

    // 2. Query history database first (if connection exists)
    global $custom_pdos;
    $all_history_pdos = [];
    if ($pdo_history !== null) {
        $all_history_pdos['default'] = $pdo_history;
    }
    if (!empty($custom_pdos)) {
        foreach ($custom_pdos as $cid => $cpdo) {
            $all_history_pdos[$cid] = $cpdo;
        }
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
            $stmt = $pdo->prepare("SELECT utimestamp as ts, datos FROM `$target_table` WHERE id_agente_modulo = ? AND utimestamp BETWEEN ? AND ? ORDER BY utimestamp DESC LIMIT " . (int)$limit);
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
            $stmt = $pdo->prepare($query);
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
            $stmtLookback = $pdo->prepare("SELECT utimestamp as ts, datos FROM `$target_table` WHERE id_agente_modulo = ? AND utimestamp < ? ORDER BY utimestamp DESC LIMIT 1");
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
            $stmtLookback = $pdo->prepare($lookback_query);
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
                    $stmtLookbackHist = $h_pdo->prepare("SELECT utimestamp as ts, datos FROM `$tbl` WHERE id_agente_modulo = ? AND utimestamp < ? ORDER BY ts DESC LIMIT 1");
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
        $preData['ts'] = (int)$start;
        $historyData[] = $preData;
    }

    // Merge results using array_merge
    $merged = array_merge($historyData, $activeData);

    // Deduplicate by timestamp to prevent duplicate points
    $unique = [];
    foreach ($merged as $item) {
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
