<?php
/**
 * PANDORA FMS - SHARED UTILITIES
 * Centralized logic for status mapping, text formatting, and DB management.
 */

if (!defined('PFMS_UTILS_LOADED')) {
    define('PFMS_UTILS_LOADED', true);

    if (!function_exists('h')) {
        function h($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('pretty_text')) {
        function pretty_text($s) {
            if ($s === null) return '';
            $decoded = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
            return str_replace('&#x20;', ' ', $decoded);
        }
    }

    function map_pandora_status($estado) {
        switch ((int)$estado) {
            case 0: return ['label' => 'NORMAL', 'color' => 'bg-green', 'val' => 0];
            case 1: return ['label' => 'CRITICAL', 'color' => 'bg-red', 'val' => 1];
            case 2: return ['label' => 'WARNING', 'color' => 'bg-yellow', 'val' => 2];
            case 4: return ['label' => 'NOT INIT', 'color' => 'bg-blue', 'val' => 4];
            default: return ['label' => 'UNKNOWN', 'color' => 'bg-gray', 'val' => 3];
        }
    }

    function format_time_ago($ts) {
        if ($ts <= 0) return 'N/A';
        $now = time();
        $diff = $now - (int)$ts;
        if ($diff < 0) $diff = 0;
        
        if ($diff < 60) return $diff . " seconds";
        if ($diff < 3600) return round($diff / 60) . " min";
        if ($diff < 86400) return round($diff / 3600) . " hours";
        return round($diff / 86400) . " days";
    }

    /**
     * Singleton for PDO Connection
     */
    function get_db_connection($config) {
        static $pdo = null;
        if ($pdo === null) {
            $dsn = "mysql:host=" . $config["dbhost"] . ";dbname=" . $config["dbname"] . ";charset=utf8mb4";
            $pdo = new PDO($dsn, $config["dbuser"], $config["dbpass"], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true // Use persistent connection for performance
            ]);
        }
        return $pdo;
    }
    
    /**
     * Helper to get recursive child groups efficiently
     */
    function get_all_child_groups($pdo, $parentId) {
        $stmt = $pdo->query("SELECT id_grupo, parent FROM tgrupo");
        $allGroups = $stmt->fetchAll();
        
        $children = [$parentId];
        $findChildren = function($pId) use (&$findChildren, &$children, $allGroups) {
            foreach ($allGroups as $g) {
                if ($g['parent'] == $pId) {
                    $children[] = (int)$g['id_grupo'];
                    $findChildren($g['id_grupo']);
                }
            }
        };
        
        $findChildren($parentId);
        return array_unique($children);
    }
}
