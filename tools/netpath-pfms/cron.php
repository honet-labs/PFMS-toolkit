<?php
/**
 * Pandora FMS NetPath - Background Scheduler (CRON)
 * This script updates all network paths defined in pfms-routepath.json
 * Run this via system crontab every 5 minutes.
 */

// Disable web execution if needed (optional)
if (php_sapi_name() !== 'cli' && !isset($_GET['force'])) {
    die("This script must be run from CLI (Cron).");
}

require_once __DIR__ . '/pfms_routepath.php';

echo "[".date('Y-m-d H:i:s')."] Starting NetPath Background Update...\n";

$dashboards = loadDashboards($storeFile);
if (empty($dashboards)) {
    echo "No paths configured. Skipping.\n";
    exit;
}

foreach ($dashboards as &$d) {
    echo " - Processing: " . $d['name'] . " (Target: " . $d['target'] . ")\n";
    
    // 1. Discovery Main Path
    $res = runDiscovery($d['agent_name'], $d['target']);
    echo "   * Main Discovery: " . $res . "\n";

    // 2. Discovery Segments (Branches)
    if (!empty($d['segments'])) {
        foreach ($d['segments'] as $s) {
            echo "   * Branch [" . $s['from'] . " -> " . $s['to'] . "]: ";
            $res2 = runDiscovery($d['agent_name'], $s['to'], $s['from']);
            echo $res2 . "\n";
        }
    }

    $d['last_update'] = time();
}

// Save updated timestamps
saveDashboards($storeFile, $dashboards);

echo "[".date('Y-m-d H:i:s')."] Update Completed Successfully.\n";
