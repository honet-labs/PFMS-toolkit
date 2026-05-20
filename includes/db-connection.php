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
$config = [];
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

/**
 * Common Helper Functions
 */

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function pretty_text($s) {
    if ($s === null || $s === '') return '';
    $text = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
