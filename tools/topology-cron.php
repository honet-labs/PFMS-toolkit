<?php
/**
 * CRON Script for Background Topology Discovery
 * Run via: php /path/to/topology-cron.php
 */
require_once __DIR__ . '/../includes/db-connection.php';
require_once __DIR__ . '/../Dashboard/Network-Mapping/Engine/TopologyInferenceEngine.php';

use NetworkMapping\Engine\TopologyInferenceEngine;

echo "[+] Starting Topology Auto-Discovery...\n";

if (!$db_status) {
    die("[-] DB Connection failed: " . $db_error . "\n");
}

$engine = new TopologyInferenceEngine($pdo);
$engine->runFullDiscovery();

echo "[+] Discovery completed successfully.\n";
