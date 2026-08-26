<?php
declare(strict_types=1);

/**
 * Pandora FMS Route Parser - Scheduled Background Poller (Cron Job)
 * 
 * Usage:
 *   CLI / Manual: php cron_route_parser.php
 *   Crontab:      * / 5 * * * * php /var/www/html/pandora_console/custom/panel/Dashboard/Route-Parser/cron_route_parser.php > /dev/null 2>&1
 */

ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_NOTICE);
ini_set('memory_limit', '512M');
@set_time_limit(600);

echo "[" . date('Y-m-d H:i:s') . "] Starting Route Parser Poller...\n";

// 1. Database Connection
$db_connection_file = __DIR__ . '/../../includes/db-connection.php';
if (file_exists($db_connection_file)) {
    require_once $db_connection_file;
} else {
    $possible_paths = [
        dirname(__DIR__, 2) . '/includes/db-connection.php',
        '/var/www/html/pandora_console/includes/db-connection.php',
        '/var/www/pandora_console/includes/db-connection.php'
    ];
    foreach ($possible_paths as $p) {
        if (file_exists($p)) { require_once $p; break; }
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die("[FATAL] Central database connection failed.\n");
}

// 2. Binary Locator
$possible_bins = [
    '/usr/share/pandora_server/util/plugin/route_parser',
    '/etc/pandora/plugins/route_parser',
    '/usr/lib/pandora/plugins/route_parser',
    '/var/www/html/pandora_console/attachment/plugin/route_parser',
    __DIR__ . '/route_parser',
    __DIR__ . '/../../tools/netpath-pfms/route_parser'
];
$bin_path = null;
foreach ($possible_bins as $pb) {
    if (file_exists($pb) && is_executable($pb)) {
        $bin_path = $pb;
        break;
    }
}
if (!$bin_path) {
    $which = trim((string)@shell_exec('which route_parser 2>/dev/null'));
    if (!empty($which) && is_executable($which)) $bin_path = $which;
}

echo "[INFO] Using route_parser binary: " . ($bin_path ?: "Fallback probe") . "\n";

// 3. Find All Agents with RouteStep / RouteTarget modules
$stAgents = $pdo->query("
    SELECT DISTINCT a.id_agente, a.nombre, a.alias, a.direccion
    FROM tagente a
    JOIN tagente_modulo tm ON tm.id_agente = a.id_agente
    WHERE a.disabled = 0 
      AND tm.disabled = 0
      AND (tm.nombre LIKE 'RouteStep%' OR tm.nombre LIKE 'RouteTarget%')
    ORDER BY a.alias ASC
");

$agents = $stAgents ? $stAgents->fetchAll(PDO::FETCH_ASSOC) : [];
echo "[INFO] Found " . count($agents) . " agent(s) with route monitoring configured.\n";

$spool_dir = '/var/spool/pandora/data_in';
if (!is_dir($spool_dir) || !is_writable($spool_dir)) {
    $spool_dir = sys_get_temp_dir();
}

$total_probes = 0;
$success_probes = 0;

foreach ($agents as $ag) {
    $agent_id = (int)$ag['id_agente'];
    $agent_name = $ag['nombre'];
    $agent_alias = $ag['alias'] ?: $agent_name;
    $source_ip = $ag['direccion'] ?: '';

    echo "\n--> Processing Agent: {$agent_alias} (ID: {$agent_id}, IP: {$source_ip})\n";

    $stTargets = $pdo->prepare("
        SELECT id_agente_modulo, nombre, parent_module_id 
        FROM tagente_modulo 
        WHERE id_agente = ? 
          AND disabled = 0 
          AND (nombre LIKE 'RouteStepTarget_%' OR nombre LIKE 'RouteTarget_%')
        ORDER BY id_agente_modulo ASC
    ");
    $stTargets->execute([$agent_id]);
    $targets = $stTargets->fetchAll(PDO::FETCH_ASSOC);

    if (empty($targets)) {
        echo "    [WARN] No target modules found for this agent. Skipping.\n";
        continue;
    }

    $name_to_id = [];
    $root_mod_id = 0;
    $stAllMods = $pdo->prepare("SELECT id_agente_modulo, nombre, parent_module_id FROM tagente_modulo WHERE id_agente = ? AND disabled = 0");
    $stAllMods->execute([$agent_id]);
    while ($m = $stAllMods->fetch(PDO::FETCH_ASSOC)) {
        $name_to_id[$m['nombre']] = (int)$m['id_agente_modulo'];
        if ($m['parent_module_id'] == 0 || (!empty($source_ip) && strpos($m['nombre'], $source_ip) !== false)) {
            if ($root_mod_id === 0) $root_mod_id = (int)$m['id_agente_modulo'];
        }
    }

    foreach ($targets as $t) {
        $total_probes++;
        $target_ip = preg_replace('/^Route(?:Step)?Target_/i', '', $t['nombre']);
        $target_ip = trim($target_ip);

        if (empty($target_ip)) continue;

        echo "    * Probing Target: {$target_ip}... ";

        $xml_output = '';
        $exec_output = [];
        $ret = 0;

        if ($bin_path) {
            $cmd = escapeshellcmd($bin_path) . " -t " . escapeshellarg($target_ip);
            @exec($cmd, $exec_output, $ret);
            $xml_output = implode("\n", $exec_output);
        }

        if (empty($xml_output) || strpos($xml_output, '<module>') === false) {
            echo "FAILED (No XML output)\n";
            continue;
        }

        // If agent has a specific IP different from Pandora server, replace server root hop with Agent IP
        if (!empty($source_ip) && $source_ip !== '172.17.8.189') {
            $xml_output = preg_replace('/RouteStep_172\.17\.8\.189/', 'RouteStep_' . $source_ip, $xml_output);
            try {
                $pdo->prepare("UPDATE tagente_modulo SET nombre = ? WHERE id_agente = ? AND nombre = 'RouteStep_172.17.8.189'")->execute(['RouteStep_' . $source_ip, $agent_id]);
            } catch (Throwable $e) {}
        }

        // Write to Pandora Spooler
        $ts = date('Y-m-d H:i:s');
        $agent_xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<agent_data agent_name=\"" . htmlspecialchars($agent_name, ENT_QUOTES) . "\" timestamp=\"$ts\" version=\"1.0\" os=\"Other\" interval=\"300\">\n" . $xml_output . "\n</agent_data>";
        $fn = rtrim($spool_dir, '/') . '/netpath.cron.' . bin2hex(random_bytes(4)) . '.' . time() . '.data';
        @file_put_contents($fn, $agent_xml);

        // Update tagente_estado & tagente_datos
        preg_match_all('/<module>(.*?)<\/module>/s', $xml_output, $mod_matches);
        $prev_mod_id = $root_mod_id;
        $now_ts = time();
        $updated_cnt = 0;

        foreach ($mod_matches[1] as $mblock) {
            $m_name = ''; $m_data = 0.0; $m_parent = '';
            if (preg_match('/<name>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?<\/name>/', $mblock, $nm)) $m_name = trim($nm[1]);
            if (preg_match('/<data>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?<\/data>/', $mblock, $dm)) $m_data = (float)trim($dm[1]);
            if (preg_match('/<module_parent>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?<\/module_parent>/', $mblock, $pm)) $m_parent = trim($pm[1]);

            if (empty($m_name)) continue;

            $parent_mid = 0;
            if (!empty($m_parent) && isset($name_to_id[$m_parent])) {
                $parent_mid = $name_to_id[$m_parent];
            } elseif ($prev_mod_id > 0 && (empty($source_ip) || strpos($m_name, $source_ip) === false)) {
                $parent_mid = $prev_mod_id;
            } elseif ($root_mod_id > 0 && (empty($source_ip) || strpos($m_name, $source_ip) === false)) {
                $parent_mid = $root_mod_id;
            }

            // Check if module exists
            $stCheck = $pdo->prepare("SELECT id_agente_modulo FROM tagente_modulo WHERE id_agente = ? AND nombre = ?");
            $stCheck->execute([$agent_id, $m_name]);
            $ex = $stCheck->fetch(PDO::FETCH_ASSOC);

            $mod_id = 0;
            if ($ex) {
                $mod_id = (int)$ex['id_agente_modulo'];
                $pdo->prepare("UPDATE tagente_modulo SET parent_module_id = ?, unit = 'ms', disabled = 0 WHERE id_agente_modulo = ?")->execute([$parent_mid, $mod_id]);
            } else {
                $pdo->prepare("INSERT INTO tagente_modulo (id_agente, id_tipo_modulo, nombre, parent_module_id, unit, descripcion, disabled, id_module_group, history_data) VALUES (?, 4, ?, ?, 'ms', ?, 0, 1, 1)")->execute([$agent_id, $m_name, $parent_mid, 'Route parser hop for ' . $m_name]);
                $mod_id = (int)$pdo->lastInsertId();
            }

            if ($mod_id > 0) {
                $name_to_id[$m_name] = $mod_id;
                if ($parent_mid === 0 && (strpos($m_name, $source_ip) !== false || $root_mod_id === 0)) {
                    $root_mod_id = $mod_id;
                }
                $prev_mod_id = $mod_id;
                $updated_cnt++;

                // Upsert tagente_estado
                $pdo->prepare("INSERT INTO tagente_estado (id_agente_modulo, datos, estado, utimestamp) VALUES (?, ?, 0, ?) ON DUPLICATE KEY UPDATE datos = VALUES(datos), estado = VALUES(estado), utimestamp = VALUES(utimestamp)")->execute([$mod_id, $m_data, $now_ts]);

                // Insert into tagente_datos
                $pdo->prepare("INSERT INTO tagente_datos (id_agente_modulo, datos, utimestamp) VALUES (?, ?, ?)")->execute([$mod_id, $m_data, $now_ts]);
            }
        }

        $success_probes++;
        echo "OK ($updated_cnt modules updated with fresh latency)\n";
    }
}

echo "\n=======================================================\n";
echo "[" . date('Y-m-d H:i:s') . "] Route Poller Complete. {$success_probes}/{$total_probes} probes succeeded.\n";
