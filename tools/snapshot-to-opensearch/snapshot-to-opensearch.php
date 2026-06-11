<?php
/**
 * PANDORA FMS - SNAPSHOT TO OPENSEARCH INTEGRATION (v1.0)
 * Parse Pandora FMS table/snapshot modules and index them to OpenSearch.
 */

// 1. CLI / CRON DETECT
$is_cli = (php_sapi_name() === 'cli' || defined('STDIN') || (isset($argv) && count($argv) > 0));

if ($is_cli) {
    // Cron execution path
    require_once __DIR__ . '/../../includes/db-connection.php';
    runBackgroundCron($pdo);
    exit;
}

// 2. WEB WEB CONTEXT & AUTHENTICATION
require_once __DIR__ . '/../../includes/db-connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['id_usuario'])) {
    die("Access Denied. Please log in to Pandora FMS console.");
}

$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';
$config_file = __DIR__ . '/config.json';
$script_name = $_SERVER['SCRIPT_NAME'] ?? '';
$base_url = rtrim(str_replace('\\', '/', dirname(dirname(dirname($script_name)))), '/');

// Initialize default configuration
$default_config = [
    'opensearch' => [
        'url' => 'http://localhost:9200',
        'index' => 'pandora-snapshots',
        'username' => '',
        'password' => '',
        'ssl_verify' => false
    ],
    'sync_modules' => [],
    'last_cron_run' => null,
    'last_cron_status' => null
];

$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : $default_config;
if (!is_array($config)) $config = $default_config;

// Safe fallback helper to clean Pandora FMS HTML-entity encoded text
if (!function_exists('cleanPandoraText')) {
    function cleanPandoraText($s) {
        if ($s === null || $s === '') return '';
        if (function_exists('pretty_text')) {
            return pretty_text($s);
        }
        $text = html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return str_replace(['&#x20;', '&nbsp;'], ' ', $text);
    }
}

// Clean index name to comply with OpenSearch index naming conventions
if (!function_exists('cleanIndexName')) {
    function cleanIndexName($name) {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9_\-]/', '_', $name);
        $name = preg_replace('/__+/', '_', $name);
        return trim($name, '_-');
    }
}

// Safely write to config.json with descriptive error messages for permission issues
function saveConfigSafely($config_file, $config) {
    if (file_exists($config_file) && !is_writable($config_file)) {
        return [
            'ok' => false,
            'error' => 'Configuration file config.json is not writable by the web server. Please fix file permissions (e.g. run "chown apache:apache config.json" or similar on the server).'
        ];
    }
    $json = json_encode($config, JSON_PRETTY_PRINT);
    if (@file_put_contents($config_file, $json) === false) {
        return [
            'ok' => false,
            'error' => 'Failed to write config.json. Please check write permissions on the directory and file.'
        ];
    }
    return ['ok' => true];
}

// Safely write messages to a log file inside /var/log/pandora/ or local fallback directory
function writeLog($message, $level = 'INFO') {
    $log_dir = '/var/log/pandora';
    $log_file = $log_dir . '/snapshot_to_opensearch.log';
    
    // Check if directory exists and is writable, if not try to use current directory as fallback
    if (!is_dir($log_dir) || !is_writable($log_dir)) {
        $log_dir = __DIR__;
        $log_file = $log_dir . '/snapshot_to_opensearch.log';
    } elseif (file_exists($log_file) && !is_writable($log_file)) {
        $log_file = __DIR__ . '/snapshot_to_opensearch.log';
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[$timestamp] [$level] $message\n";
    @file_put_contents($log_file, $formatted, FILE_APPEND);
}

// =====================================================================
// 3. API ENDPOINTS
// =====================================================================
$api = $_GET['api'] ?? '';

if (!empty($api)) {
    ob_clean();
    header('Content-Type: application/json');
    
    // Save Settings API
    if ($api === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if ($input) {
            $config['opensearch'] = [
                'url' => trim($input['url'] ?? ''),
                'index' => trim($input['index'] ?? ''),
                'username' => trim($input['username'] ?? ''),
                'password' => trim($input['password'] ?? ''),
                'ssl_verify' => !empty($input['ssl_verify'])
            ];
             $saveRes = saveConfigSafely($config_file, $config);
            if ($saveRes['ok']) {
                writeLog("Configuration settings updated successfully via web interface.");
                echo json_encode(['ok' => true]);
            } else {
                writeLog("Failed to save configuration settings: " . $saveRes['error'], "ERROR");
                echo json_encode(['ok' => false, 'error' => $saveRes['error']]);
            }
        } else {
            echo json_encode(['ok' => false, 'error' => 'Invalid data payload']);
        }
        exit;
    }

    // Toggle Sync Module API
    if ($api === 'toggle_module' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $modId = (int)($input['id_agente_modulo'] ?? 0);
        $enabled = !empty($input['enabled']);
        
        if ($modId > 0) {
            if ($enabled) {
                // Check if already in list
                $found = false;
                foreach ($config['sync_modules'] as $sm) {
                    if ((int)$sm['id_agente_modulo'] === $modId) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $config['sync_modules'][] = [
                        'id_agente_modulo' => $modId,
                        'last_synced_contact' => 0
                    ];
                }
            } else {
                // Remove from list
                $config['sync_modules'] = array_values(array_filter($config['sync_modules'], function($sm) use ($modId) {
                    return (int)$sm['id_agente_modulo'] !== $modId;
                }));
            }
            $saveRes = saveConfigSafely($config_file, $config);
            if ($saveRes['ok']) {
                writeLog("Module ID $modId auto-sync updated. Enabled: " . ($enabled ? "Yes" : "No"));
                echo json_encode(['ok' => true]);
            } else {
                writeLog("Failed to update auto-sync status for module ID $modId: " . $saveRes['error'], "ERROR");
                echo json_encode(['ok' => false, 'error' => $saveRes['error']]);
            }
        } else {
            echo json_encode(['ok' => false, 'error' => 'Invalid module ID']);
        }
        exit;
    }



    // Test OpenSearch Connection API
    if ($api === 'test_connection') {
        $url = trim($_GET['url'] ?? '');
        $username = trim($_GET['username'] ?? '');
        $password = trim($_GET['password'] ?? '');
        $ssl_verify = !empty($_GET['ssl_verify']);
        
        if (empty($url)) {
            echo json_encode(['ok' => false, 'error' => 'OpenSearch URL is required']);
            exit;
        }

        $ch = curl_init(rtrim($url, '/'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (!empty($username)) {
            curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");
        }
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $res = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            echo json_encode(['ok' => false, 'error' => $err]);
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            echo json_encode(['ok' => true, 'response' => json_decode($res, true) ?: $res]);
        } else {
            echo json_encode(['ok' => false, 'error' => "HTTP Status $httpCode", 'response' => $res]);
        }
        exit;
    }

    // Load Snapshot Modules list
    if ($api === 'get_modules') {
        // Query to find eligible modules
        $stmt = $pdo->query("SELECT a.id_agente, a.alias AS agent_alias, m.id_agente_modulo, m.nombre AS module_name, e.datos AS current_val, e.utimestamp AS last_contact, a.id_grupo, COALESCE(g.nombre, 'No Group') AS group_name
                             FROM tagente_modulo m
                             JOIN tagente a ON m.id_agente = a.id_agente
                             JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                             LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                             WHERE m.disabled = 0 AND a.disabled = 0
                               AND (e.datos LIKE '%|%|%' OR m.nombre LIKE '%snapshot%' OR m.nombre LIKE '%query%' OR m.nombre LIKE '%table%')
                             ORDER BY a.alias ASC, m.nombre ASC");
        $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Map sync status
        foreach ($modules as &$m) {
            $m['agent_alias'] = cleanPandoraText($m['agent_alias']);
            $m['module_name'] = cleanPandoraText($m['module_name']);
            $m['group_name'] = cleanPandoraText($m['group_name']);
            
            $m['is_synced'] = false;
            foreach ($config['sync_modules'] as $sm) {
                if ((int)$sm['id_agente_modulo'] === (int)$m['id_agente_modulo']) {
                    $m['is_synced'] = true;
                    break;
                }
            }
            // Snippet of current value
            $cleanVal = cleanPandoraText($m['current_val']);
            $m['val_snippet'] = strlen($cleanVal) > 80 ? substr($cleanVal, 0, 80) . '...' : $cleanVal;
        }
        echo json_encode($modules);
        exit;
    }

    // Manual Sync Now API
    if ($api === 'sync_now') {
        $modId = (int)($_GET['id_agente_modulo'] ?? 0);
        if ($modId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid module ID']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT a.alias AS agent_alias, m.nombre AS module_name, e.datos AS current_val, e.utimestamp AS last_contact
                               FROM tagente_modulo m
                               JOIN tagente a ON m.id_agente = a.id_agente
                               JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                               WHERE m.id_agente_modulo = ?");
        $stmt->execute([$modId]);
        $mod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mod) {
            echo json_encode(['ok' => false, 'error' => 'Module data not found']);
            exit;
        }

        $agentAlias = cleanPandoraText($mod['agent_alias']);
        $moduleName = cleanPandoraText($mod['module_name']);
        $currentVal = cleanPandoraText($mod['current_val']);

        $parsed = parseSnapshotTable($currentVal);
        if (empty($parsed)) {
            echo json_encode(['ok' => false, 'error' => 'No rows parsed from module value']);
            exit;
        }

        // Prepare documents
        $documents = [];
        $lastUpdateStr = date('Y-m-d H:i:s', $mod['last_contact']);
        $ingestTimestamp = date('Y-m-d H:i:s');
        foreach ($parsed as $row) {
            $documents[] = array_merge([
                'agent_alias' => $agentAlias,
                'module_name' => $moduleName,
                'last_update' => $lastUpdateStr,
                'ingest_timestamp' => $ingestTimestamp
            ], $row);
        }

        $resolvedIndex = $config['opensearch']['index'] . '-' . cleanIndexName($moduleName);

        $res = sendToOpenSearch($config['opensearch'], $documents, $resolvedIndex);
        if ($res['ok']) {
            // Update last synced contact timestamp in config if this module is configured for auto sync
            $updatedConfig = false;
            foreach ($config['sync_modules'] as &$sm) {
                if ((int)$sm['id_agente_modulo'] === $modId) {
                    $sm['last_synced_contact'] = (int)$mod['last_contact'];
                    $updatedConfig = true;
                    break;
                }
            }
            if ($updatedConfig) {
                $saveRes = saveConfigSafely($config_file, $config);
                if (!$saveRes['ok']) {
                    writeLog("Manual sync succeeded for module ID $modId ($agentAlias - $moduleName) but failed to save updated config: " . $saveRes['error'], "WARNING");
                    echo json_encode(['ok' => false, 'error' => $saveRes['error']]);
                    exit;
                }
            }
            writeLog("Manual sync succeeded for module ID $modId ($agentAlias - $moduleName). Sent " . count($parsed) . " document(s) to OpenSearch.");
            echo json_encode(['ok' => true, 'parsed_rows' => count($parsed), 'opensearch_res' => $res['response']]);
        } else {
            writeLog("Manual sync failed for module ID $modId ($agentAlias - $moduleName). Error: " . $res['error'], "ERROR");
            echo json_encode(['ok' => false, 'error' => $res['error'], 'details' => $res['response'] ?? null]);
        }
        exit;
    }

    // Force Trigger Background Sync API
    if ($api === 'trigger_cron') {
        $res = runBackgroundCron($pdo);
        echo json_encode($res);
        exit;
    }

    // Get Logs API
    if ($api === 'get_logs') {
        $log_dir = '/var/log/pandora';
        $log_file = $log_dir . '/snapshot_to_opensearch.log';
        
        if (!is_dir($log_dir) || !is_writable($log_dir)) {
            $log_file = __DIR__ . '/snapshot_to_opensearch.log';
        } elseif (file_exists($log_file) && !is_writable($log_file)) {
            $log_file = __DIR__ . '/snapshot_to_opensearch.log';
        }

        if (!file_exists($log_file)) {
            echo json_encode(['ok' => true, 'logs' => []]);
            exit;
        }

        $lines = @file($log_file);
        if ($lines === false) {
            echo json_encode(['ok' => false, 'error' => 'Failed to read log file.']);
            exit;
        }

        $last_lines = array_slice($lines, -100);
        $last_lines = array_map('trim', $last_lines);
        
        echo json_encode(['ok' => true, 'logs' => $last_lines]);
        exit;
    }
}

// =====================================================================
// 4. PARSER & SENDER CORE FUNCTIONS
// =====================================================================
function parseSnapshotTable($rawText) {
    $lines = array_filter(array_map('trim', explode("\n", $rawText)), function($l) { return $l !== ''; });
    $lines = array_values($lines);
    if (empty($lines)) return [];

    $separatorIdx = -1;
    for ($i = 0; $i < count($lines); $i++) {
        $trimmed = $lines[$i];
        if (preg_match('/^[|:\-\+\s]{5,}$/', $trimmed) && strpos($trimmed, '-') !== false) {
            $separatorIdx = $i;
            break;
        }
    }

    $headers = [];
    $dataLines = [];

    if ($separatorIdx === -1 || $separatorIdx === 0) {
        if (count($lines) > 0 && strpos($lines[0], '|') !== false) {
            $firstLine = $lines[0];
            $firstCells = array_map('trim', explode('|', $firstLine));
            if (!empty($firstCells) && $firstCells[0] === '' && strpos($firstLine, '|') === 0) {
                array_shift($firstCells);
            }
            if (!empty($firstCells) && $firstCells[count($firstCells) - 1] === '' && substr($firstLine, -1) === '|') {
                array_pop($firstCells);
            }
            $numCols = count($firstCells);
            for ($i = 1; $i <= $numCols; $i++) {
                $headers[] = "Col_$i";
            }
            $dataLines = $lines;
        } else {
            return [['value' => $rawText]];
        }
    } else {
        $headerLines = array_slice($lines, 0, $separatorIdx);
        foreach ($headerLines as $line) {
            $cols = array_filter(array_map('trim', explode('|', $line)), function($h) { return $h !== ''; });
            $headers = array_merge($headers, $cols);
        }
        $dataLines = array_slice($lines, $separatorIdx + 1);
    }

    $numCols = count($headers) ?: 1;
    $parsedRows = [];
    $currentCells = [];

    foreach ($dataLines as $line) {
        $pipeCount = substr_count($line, '|');
        if ($pipeCount >= 2) {
            if (!empty($currentCells)) {
                $parsedRows[] = $currentCells;
            }
            $cells = array_map('trim', explode('|', $line));
            if (count($cells) > 0 && $cells[0] === '' && strpos($line, '|') === 0) {
                array_shift($cells);
            }
            if (count($cells) > 0 && $cells[count($cells) - 1] === '' && substr($line, -1) === '|') {
                array_pop($cells);
            }
            $currentCells = $cells;
        } else {
            if (!empty($currentCells)) {
                $lastIdx = count($currentCells) - 1;
                $currentCells[$lastIdx] = $currentCells[$lastIdx] . "\n" . trim($line);
            } else {
                $currentCells = array_map('trim', explode('|', $line));
            }
        }
    }
    if (!empty($currentCells)) {
        $parsedRows[] = $currentCells;
    }

    $result = [];
    foreach ($parsedRows as $cells) {
        while (count($cells) < $numCols) $cells[] = '';
        if (count($cells) > $numCols) $cells = array_slice($cells, 0, $numCols);
        
        $rowObj = [];
        for ($i = 0; $i < $numCols; $i++) {
            $key = $headers[$i] ?: "Col_" . ($i + 1);
            $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
            $rowObj[$cleanKey] = $cells[$i];
        }
        $result[] = $rowObj;
    }

    return $result;
}

function sendToOpenSearch($osConfig, $documents, $customIndex = null) {
    if (empty($osConfig['url'])) {
        return ['ok' => false, 'error' => 'OpenSearch connection not configured'];
    }

    $indexName = !empty($customIndex) ? $customIndex : $osConfig['index'];
    $url = rtrim($osConfig['url'], '/') . '/' . urlencode($indexName) . '/_bulk';
    $payload = "";
    foreach ($documents as $doc) {
        $payload .= json_encode(['index' => (object)[]]) . "\n";
        $payload .= json_encode($doc) . "\n";
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-ndjson'
    ]);
    
    if (!empty($osConfig['username'])) {
        curl_setopt($ch, CURLOPT_USERPWD, $osConfig['username'] . ":" . ($osConfig['password'] ?? ''));
    }
    
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !empty($osConfig['ssl_verify']));
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !empty($osConfig['ssl_verify']) ? 2 : 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'error' => $err];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        $resDec = json_decode($response, true);
        if (isset($resDec['errors']) && $resDec['errors'] === true) {
            return ['ok' => false, 'error' => 'Some bulk items failed', 'response' => $resDec];
        }
        return ['ok' => true, 'response' => $resDec];
    } else {
        return ['ok' => false, 'error' => "HTTP status $httpCode", 'response' => $response];
    }
}

// Background Cron synchronization logic (highly optimized)
function runBackgroundCron($pdo) {
    writeLog("Background cron started.");
    
    $config_file = __DIR__ . '/config.json';
    if (!file_exists($config_file)) {
        writeLog("Cron aborted: Configuration file config.json does not exist.", "ERROR");
        return ['ok' => false, 'error' => 'Configuration file config.json does not exist.'];
    }

    $config = json_decode(file_get_contents($config_file), true);
    if (!is_array($config) || empty($config['sync_modules'])) {
        writeLog("Cron completed. No modules configured for sync.");
        return ['ok' => true, 'message' => 'No modules configured for sync.'];
    }

    $syncCount = 0;
    $errors = [];
    $totalIndexedRows = 0;

    foreach ($config['sync_modules'] as &$sm) {
        $modId = (int)$sm['id_agente_modulo'];
        $lastSynced = (int)$sm['last_synced_contact'];

        // Query the module details and current contact timestamp
        $stmt = $pdo->prepare("SELECT a.alias AS agent_alias, m.nombre AS module_name, e.datos AS current_val, e.utimestamp AS last_contact
                               FROM tagente_modulo m
                               JOIN tagente a ON m.id_agente = a.id_agente
                               JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                               WHERE m.id_agente_modulo = ? AND m.disabled = 0 AND a.disabled = 0");
        $stmt->execute([$modId]);
        $mod = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$mod) continue;

        // Skip if data is not newer than last synced contact timestamp (optimisation)
        if ((int)$mod['last_contact'] <= $lastSynced) {
            continue; 
        }

        $agentAlias = cleanPandoraText($mod['agent_alias']);
        $moduleName = cleanPandoraText($mod['module_name']);
        $currentVal = cleanPandoraText($mod['current_val']);

        $parsed = parseSnapshotTable($currentVal);
        if (empty($parsed)) continue;

        $documents = [];
        $lastUpdateStr = date('Y-m-d H:i:s', $mod['last_contact']);
        $ingestTimestamp = date('Y-m-d H:i:s');
        foreach ($parsed as $row) {
            $documents[] = array_merge([
                'agent_alias' => $agentAlias,
                'module_name' => $moduleName,
                'last_update' => $lastUpdateStr,
                'ingest_timestamp' => $ingestTimestamp
            ], $row);
        }

        $resolvedIndex = $config['opensearch']['index'] . '-' . cleanIndexName($moduleName);
        $res = sendToOpenSearch($config['opensearch'], $documents, $resolvedIndex);
        if ($res['ok']) {
            $sm['last_synced_contact'] = (int)$mod['last_contact'];
            $syncCount++;
            $totalIndexedRows += count($parsed);
            writeLog("Cron synced module ID $modId ($agentAlias - $moduleName) successfully. Sent " . count($parsed) . " row(s).");
        } else {
            $errStr = "Module ID $modId ($agentAlias - $moduleName) sync error: " . $res['error'];
            $errors[] = $errStr;
            writeLog($errStr, "ERROR");
        }
    }

    $config['last_cron_run'] = date('Y-m-d H:i:s');
    $config['last_cron_status'] = [
        'synced_modules' => $syncCount,
        'indexed_rows' => $totalIndexedRows,
        'errors' => $errors
    ];

    $saveRes = saveConfigSafely($config_file, $config);
    writeLog("Background cron completed. Modules Synced: $syncCount, Total Rows Indexed: $totalIndexedRows, Errors: " . count($errors));

    return [
        'ok' => empty($errors) && $saveRes['ok'],
        'synced_modules' => $syncCount,
        'indexed_rows' => $totalIndexedRows,
        'errors' => array_merge($errors, $saveRes['ok'] ? [] : [$saveRes['error']])
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>OpenSearch Snapshot Indexer - Pandora FMS</title>
    <link href="<?= htmlspecialchars($base_url ?? "") ?>/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($base_url ?? "") ?>/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6f8; color: #334155; font-size: 13px; margin: 0; padding: 25px; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-weight: normal !important; font-style: normal !important; font-size: 18px !important; line-height: 1 !important; display: inline-block; vertical-align: middle; color: inherit !important; }
        
        .header-section { background: #fff; padding: 20px 30px; border-radius: 8px; border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
        .header-section h1 { font-size: 18px; font-weight: 600; margin: 0; color: #0b1a26; display: flex; align-items: center; gap: 8px; }
        
        .main-card { background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; padding: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); margin-bottom: 25px; }
        .card-title-sub { font-size: 14px; font-weight: 600; color: #1e293b; margin-bottom: 20px; display: flex; align-items: center; gap: 6px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
        
        .tab-nav { display: flex; gap: 15px; border-bottom: 1px solid #e2e8f0; margin-bottom: 25px; }
        .tab-btn { padding: 10px 15px; border: none; background: transparent; color: #64748b; font-weight: 500; cursor: pointer; border-bottom: 2px solid transparent; transition: 0.2s; font-size: 13px; display: flex; align-items: center; gap: 6px; }
        .tab-btn:hover { color: #004d40; }
        .tab-btn.active { color: #004d40; border-bottom-color: #004d40; font-weight: 600; }
        
        .form-label-custom { font-size: 11px; font-weight: 600; color: #475569; text-transform: uppercase; margin-bottom: 6px; display: block; }
        .form-control-custom { width: 100%; height: 36px; padding: 8px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px; outline: none; transition: 0.2s; }
        .form-control-custom:focus { border-color: #004d40; box-shadow: 0 0 0 2px rgba(0,77,64,0.1); }
        
        .btn-pfms { font-size: 12px; font-weight: 500; padding: 8px 16px; border-radius: 6px; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; border: none; outline: none; }
        .btn-primary-pfms { background: #004d40; color: #fff; }
        .btn-primary-pfms:hover { background: #003d33; }
        .btn-outline-pfms { border: 1px solid #cbd5e1; background: #fff; color: #64748b; }
        .btn-outline-pfms:hover { background: #f8fafc; border-color: #94a3b8; color: #1e293b; }
        
        .badge-status { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-block; }
        .badge-green { background: #d1fae5; color: #065f46; }
        .badge-gray { background: #f1f5f9; color: #475569; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        
        .table-custom { width: 100%; border-collapse: collapse; font-size: 12px; }
        .table-custom th { background: #f8fafc; padding: 10px 15px; text-align: left; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; }
        .table-custom td { padding: 12px 15px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; color: #334155; }
        .table-custom tr:hover { background: #fafafa; }
        
        .logs-box { background: #0b1a26; color: #94a3b8; font-family: monospace; padding: 15px; border-radius: 6px; max-height: 200px; overflow-y: auto; font-size: 11px; line-height: 1.5; }
    </style>
</head>
<body>

<div class="header-section">
    <h1><span class="material-symbols-outlined" style="font-size:24px!important; color:#004d40;">cloud_upload</span> OpenSearch Snapshot Indexer</h1>
    <div>
        <button class="btn-pfms btn-outline-pfms" onclick="triggerCronSync()" id="cronBtn">
            <span class="material-symbols-outlined">sync</span> Force Auto Sync (Cron)
        </button>
    </div>
</div>

<div class="main-card">
    <div class="tab-nav">
        <button class="tab-btn active" id="tabStatusBtn" onclick="switchTab('status')"><span class="material-symbols-outlined">dashboard</span> Status & Logs</button>
        <button class="tab-btn" id="tabModulesBtn" onclick="switchTab('modules')"><span class="material-symbols-outlined">view_list</span> Snapshot Modules</button>
        <button class="tab-btn" id="tabConfigBtn" onclick="switchTab('config')"><span class="material-symbols-outlined">settings</span> OpenSearch Config</button>
    </div>

    <!-- TAB 1: STATUS & LOGS -->
    <div id="tabStatus">
        <div class="row">
            <div class="col-md-6 mb-4">
                <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; background: #f8fafc; height: 100%;">
                    <div class="card-title-sub" style="margin-bottom:15px; border-bottom:none; padding-bottom:0;"><span class="material-symbols-outlined" style="color:#004d40;">analytics</span> Sync Status</div>
                    
                    <div style="display:flex; flex-direction:column; gap:12px; font-size:13px;">
                        <div style="display:flex; justify-content:space-between; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">
                            <span style="color:#64748b;">Active Modules to Sync:</span>
                            <strong style="color:#0f172a;" id="activeModulesCount"><?= count($config['sync_modules']) ?> Modules</strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; border-bottom:1px solid #e2e8f0; padding-bottom:8px;">
                            <span style="color:#64748b;">Last Cron Execution:</span>
                            <strong style="color:#0f172a;"><?= $config['last_cron_run'] ?: 'Never run' ?></strong>
                        </div>
                        <div style="display:flex; justify-content:space-between;">
                            <span style="color:#64748b;">Last Sync Results:</span>
                            <strong>
                                <?php if ($config['last_cron_status']): ?>
                                    <span class="badge-status badge-green">Synced: <?= $config['last_cron_status']['synced_modules'] ?></span>
                                    <span class="badge-status badge-gray">Rows: <?= $config['last_cron_status']['indexed_rows'] ?></span>
                                    <?php if (!empty($config['last_cron_status']['errors'])): ?>
                                        <span class="badge-status badge-red">Errors: <?= count($config['last_cron_status']['errors']) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">N/A</span>
                                <?php endif; ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6 mb-4">
                <div style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; background: #f8fafc; height: 100%; display:flex; flex-direction:column;">
                    <div class="card-title-sub" style="margin-bottom:15px; border-bottom:none; padding-bottom:0;"><span class="material-symbols-outlined" style="color:#004d40;">terminal</span> Background Cron Setup</div>
                    <p style="color:#64748b; font-size:12px; margin-bottom:12px;">Untuk melakukan sinkronisasi otomatis secara optimal di latar belakang, daftarkan script ini ke crontab Linux server Anda:</p>
                    <div style="background:#0f172a; color:#38bdf8; font-family:monospace; padding:12px; border-radius:6px; font-size:11px; margin-bottom:12px; overflow-x:auto; white-space:nowrap;">
                        * * * * * php <?= realpath(__FILE__) ?>
                    </div>
                    <span style="color:#94a3b8; font-size:11px; font-style:italic;">* Rekomendasi: jalankan setiap menit (* * * * *). Script dioptimasi secara otomatis hanya akan mengirim data baru saat u_timestamp berubah.</span>
                </div>
            </div>
        </div>

        <div class="card-title-sub" style="margin-top:25px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
            <span style="display:flex; align-items:center; gap:6px;"><span class="material-symbols-outlined" style="color:#004d40;">receipt_long</span> Execution & Sync Logs</span>
            <button class="btn-pfms btn-outline-pfms" style="padding:4px 10px; font-size:11px; display:inline-flex; align-items:center;" onclick="loadLiveLogs()">
                <span class="material-symbols-outlined" style="font-size:14px!important;">refresh</span> Refresh
            </button>
        </div>
        <div class="logs-box" id="liveLogsBox" style="max-height:300px; overflow-y:auto; font-family:monospace; font-size:11px; background:#0b1a26; color:#94a3b8; padding:15px; border-radius:6px; line-height:1.5;">
            Loading logs...
        </div>
    </div>

    <!-- TAB 2: SNAPSHOT MODULES -->
    <div id="tabModules" style="display:none;">
        <div class="card-title-sub"><span class="material-symbols-outlined" style="color:#004d40;">search</span> Available Snapshot Modules</div>
        
        <!-- Search & Filter Controls -->
        <div style="display:flex; flex-wrap:wrap; gap:15px; background:#f8fafc; border:1px solid #e2e8f0; padding:15px; border-radius:6px; margin-bottom:20px;">
            <div style="flex:1; min-width:200px;">
                <label class="form-label-custom">Search Keyword</label>
                <input type="text" id="moduleSearchInput" class="form-control-custom" placeholder="Search agent or module name..." oninput="applyModulesFilter()">
            </div>
            <div style="width:220px; min-width:180px;">
                <label class="form-label-custom">Filter Group Agent</label>
                <select id="moduleFilterGroup" class="form-control-custom" style="padding:4px 10px; height:36px;" onchange="applyModulesFilter()">
                    <option value="">All Groups</option>
                </select>
            </div>
            <div style="width:220px; min-width:180px;">
                <label class="form-label-custom">Filter Agent</label>
                <select id="moduleFilterAgent" class="form-control-custom" style="padding:4px 10px; height:36px;" onchange="applyModulesFilter()">
                    <option value="">All Agents</option>
                </select>
            </div>
        </div>

        <div style="overflow-x:auto;">
            <table class="table-custom" id="modulesTable">
                <thead>
                    <tr>
                        <th style="width:180px;">Agent Alias</th>
                        <th style="width:180px;">Module Name</th>
                        <th>Value Module</th>
                        <th style="width:160px;">Last Update</th>
                        <th style="width:180px;">OpenSearch Index</th>
                        <th style="width:100px; text-align:center;">Auto Sync</th>
                        <th style="width:100px; text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody id="modulesTableBody">
                    <tr><td colspan="7" align="center" style="color:#94a3b8; padding:30px;"><span class="spinner-border spinner-border-sm"></span> Loading modules...</td></tr>
            </table>
        </div>

        <!-- Pagination Controls -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:15px; background:#f8fafc; border:1px solid #e2e8f0; padding:10px 15px; border-radius:6px;">
            <div style="color:#64748b; font-size:12px;" id="paginationInfo">
                Showing 0-0 of 0 modules
            </div>
            <div style="display:flex; gap:5px;">
                <button class="btn-pfms btn-outline-pfms" style="padding:4px 10px; font-size:11px; display:flex; align-items:center;" onclick="prevPage()" id="prevPageBtn" disabled>
                    <span class="material-symbols-outlined" style="font-size:16px!important; margin-right:3px;">chevron_left</span> Previous
                </button>
                <button class="btn-pfms btn-outline-pfms" style="padding:4px 10px; font-size:11px; display:flex; align-items:center;" onclick="nextPage()" id="nextPageBtn" disabled>
                    Next <span class="material-symbols-outlined" style="font-size:16px!important; margin-left:3px;">chevron_right</span>
                </button>
            </div>
        </div>
    </div>

    <!-- TAB 3: CONFIGURATION -->
    <div id="tabConfig" style="display:none;">
        <div class="card-title-sub"><span class="material-symbols-outlined" style="color:#004d40;">key</span> OpenSearch Endpoint Connection</div>
        
        <div class="row">
            <div class="col-md-8">
                <div class="row mb-3">
                    <div class="col-md-8">
                        <label class="form-label-custom">OpenSearch URL</label>
                        <input type="text" id="osUrl" class="form-control-custom" placeholder="http://localhost:9200" value="<?= htmlspecialchars($config['opensearch']['url']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-custom">Index Name</label>
                        <input type="text" id="osIndex" class="form-control-custom" placeholder="pandora-snapshots" value="<?= htmlspecialchars($config['opensearch']['index']) ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label-custom">Username</label>
                        <input type="text" id="osUser" class="form-control-custom" placeholder="admin (optional)" value="<?= htmlspecialchars($config['opensearch']['username']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-custom">Password</label>
                        <input type="password" id="osPass" class="form-control-custom" placeholder="password (optional)" value="<?= htmlspecialchars($config['opensearch']['password']) ?>">
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="form-check form-switch" style="font-size:13px; margin-top:10px;">
                            <input class="form-check-input" type="checkbox" id="osSslVerify" <?= !empty($config['opensearch']['ssl_verify']) ? 'checked' : '' ?>>
                            <label class="form-check-label" style="font-weight: 500; color:#475569;">Verify SSL Certificate (HTTPS)</label>
                        </div>
                    </div>
                </div>

                <div style="display:flex; gap:12px;">
                    <button class="btn-pfms btn-outline-pfms" onclick="testOpenSearchConnection()" id="testBtn">
                        <span class="material-symbols-outlined">checklist</span> Test Connection
                    </button>
                    <button class="btn-pfms btn-primary-pfms" onclick="saveOpenSearchConfig()" id="saveBtn">
                        <span class="material-symbols-outlined">save</span> Save Config
                    </button>
                </div>
            </div>
            
            <div class="col-md-4">
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; font-size:12px; color:#475569; line-height:1.6;">
                    <strong style="color:#0f172a; display:block; margin-bottom:8px;">💡 Info Integrasi OpenSearch:</strong>
                    Masing-masing baris tabel Snapshot akan di-index secara individual sebagai sebuah dokumen.
                    <br><br>
                    <strong>Skema Dokumen:</strong>
                    <pre style="background:#0f172a; color:#86efac; padding:10px; border-radius:6px; font-size:10px; font-family:monospace; margin-top:5px; margin-bottom:0;">
{
  "agent_alias": "alias_agent",
  "module_name": "slow_query",
  "last_update": "2026-06-08 15:37:27",
  "header_col_1": "value_col_1",
  "header_col_2": "value_col_2"
}</pre>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const globalDefaultIndex = <?= json_encode($config['opensearch']['index'] ?: 'pandora-snapshots') ?>;
    let modulesList = [];

    // Switch Tabs
    function switchTab(tabId) {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('tabStatus').style.display = 'none';
        document.getElementById('tabModules').style.display = 'none';
        document.getElementById('tabConfig').style.display = 'none';

        if (tabId === 'status') {
            document.getElementById('tabStatusBtn').classList.add('active');
            document.getElementById('tabStatus').style.display = 'block';
            loadLiveLogs();
        } else if (tabId === 'modules') {
            document.getElementById('tabModulesBtn').classList.add('active');
            document.getElementById('tabModules').style.display = 'block';
            loadModules();
        } else if (tabId === 'config') {
            document.getElementById('tabConfigBtn').classList.add('active');
            document.getElementById('tabConfig').style.display = 'block';
        }
    }

    let currentPage = 1;
    const pageSize = 50;
    let filteredList = [];

    // Load Snapshot Modules list
    async function loadModules() {
        const body = document.getElementById('modulesTableBody');
        body.innerHTML = `<tr><td colspan="7" align="center" style="color:#94a3b8; padding:30px;"><span class="spinner-border spinner-border-sm"></span> Loading modules...</td></tr>`;
        
        try {
            const res = await fetch('?api=get_modules');
            modulesList = await res.json();
            
            if (modulesList.length === 0) {
                body.innerHTML = `<tr><td colspan="7" align="center" style="color:#94a3b8; padding:30px;">No snapshot modules found in agent states.</td></tr>`;
                document.getElementById('paginationInfo').innerText = "Showing 0-0 of 0 modules";
                document.getElementById('prevPageBtn').disabled = true;
                document.getElementById('nextPageBtn').disabled = true;
                return;
            }

            // Populate group filter dropdown
            const groupFilter = document.getElementById('moduleFilterGroup');
            const uniqueGroups = [...new Set(modulesList.map(m => m.group_name))].sort();
            groupFilter.innerHTML = '<option value="">All Groups</option>' + uniqueGroups.map(g => `<option value="${escapeHtml(g)}">${escapeHtml(g)}</option>`).join('');

            // Populate agent filter dropdown
            const agentFilter = document.getElementById('moduleFilterAgent');
            const uniqueAgents = [...new Set(modulesList.map(m => m.agent_alias))].sort();
            agentFilter.innerHTML = '<option value="">All Agents</option>' + uniqueAgents.map(a => `<option value="${escapeHtml(a)}">${escapeHtml(a)}</option>`).join('');

            currentPage = 1;
            filteredList = [...modulesList];
            renderCurrentPage();
        } catch (e) {
            body.innerHTML = `<tr><td colspan="7" align="center" style="color:#ef4444; padding:30px;">Error fetching modules: ${e.message}</td></tr>`;
        }
    }

    function renderCurrentPage() {
        const body = document.getElementById('modulesTableBody');
        const total = filteredList.length;
        
        if (total === 0) {
            body.innerHTML = `<tr><td colspan="7" align="center" style="color:#94a3b8; padding:30px;">No matching modules found.</td></tr>`;
            document.getElementById('paginationInfo').innerText = "Showing 0-0 of 0 modules";
            document.getElementById('prevPageBtn').disabled = true;
            document.getElementById('nextPageBtn').disabled = true;
            return;
        }

        const totalPages = Math.ceil(total / pageSize);
        if (currentPage < 1) currentPage = 1;
        if (currentPage > totalPages) currentPage = totalPages;

        const startIndex = (currentPage - 1) * pageSize;
        const endIndex = Math.min(startIndex + pageSize, total);
        const pageItems = filteredList.slice(startIndex, endIndex);

        body.innerHTML = pageItems.map(m => {
            const checked = m.is_synced ? 'checked' : '';
            const timeStr = m.last_contact ? new Date(m.last_contact * 1000).toLocaleString() : '-';
            const defaultPlaceholder = globalDefaultIndex + '-' + cleanIndexName(m.module_name);
            return `
                <tr>
                    <td><strong>${escapeHtml(m.agent_alias)}</strong></td>
                    <td>${escapeHtml(m.module_name)}</td>
                    <td><code style="font-size:10px; color:#c7254e; background:#f9f2f4; padding:2px 4px; border-radius:3px;">${escapeHtml(m.val_snippet)}</code></td>
                    <td>${timeStr}</td>
                    <td>
                        <div style="display:inline-flex; align-items:center; gap:8px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:4px 10px; font-family:monospace; font-size:11px; color:#334155;">
                            <span>${escapeHtml(defaultPlaceholder)}</span>
                            <button style="background:none; border:none; padding:0; cursor:pointer; display:inline-flex; align-items:center; color:#64748b;" onclick="copyIndexName('${escapeHtml(defaultPlaceholder)}', this)" title="Copy index name">
                                <span class="material-symbols-outlined" style="font-size:14px!important;">content_copy</span>
                            </button>
                        </div>
                    </td>
                    <td align="center">
                        <div class="form-check form-switch d-inline-block">
                            <input class="form-check-input" type="checkbox" onchange="toggleModule(${m.id_agente_modulo}, this.checked)" ${checked}>
                        </div>
                    </td>
                    <td align="center">
                        <button class="btn-pfms btn-primary-pfms" style="padding:4px 10px; font-size:11px;" onclick="syncNow(${m.id_agente_modulo}, this)">
                            <span class="material-symbols-outlined" style="font-size:13px!important;">upload</span> Sync Now
                        </button>
                    </td>
                </tr>
            `;
        }).join('');

        document.getElementById('paginationInfo').innerText = `Showing ${startIndex + 1}-${endIndex} of ${total} modules`;
        document.getElementById('prevPageBtn').disabled = (currentPage === 1);
        document.getElementById('nextPageBtn').disabled = (currentPage === totalPages || totalPages === 0);
    }

    function prevPage() {
        if (currentPage > 1) {
            currentPage--;
            renderCurrentPage();
        }
    }

    function nextPage() {
        const totalPages = Math.ceil(filteredList.length / pageSize);
        if (currentPage < totalPages) {
            currentPage++;
            renderCurrentPage();
        }
    }

    function applyModulesFilter() {
        const searchVal = document.getElementById('moduleSearchInput').value.toLowerCase().trim();
        const groupVal = document.getElementById('moduleFilterGroup').value;
        const agentFilter = document.getElementById('moduleFilterAgent');
        const currentAgentVal = agentFilter.value;

        // Dynamic update of agent dropdown options based on group selection
        const agentsInGroup = modulesList
            .filter(m => groupVal === '' || m.group_name === groupVal)
            .map(m => m.agent_alias);
        const uniqueAgents = [...new Set(agentsInGroup)].sort();
        agentFilter.innerHTML = '<option value="">All Agents</option>' + uniqueAgents.map(a => `<option value="${escapeHtml(a)}">${escapeHtml(a)}</option>`).join('');

        if (uniqueAgents.includes(currentAgentVal)) {
            agentFilter.value = currentAgentVal;
        }

        const agentVal = agentFilter.value;

        filteredList = modulesList.filter(m => {
            const matchSearch = searchVal === '' || 
                                m.agent_alias.toLowerCase().includes(searchVal) || 
                                m.module_name.toLowerCase().includes(searchVal);
            const matchGroup = groupVal === '' || m.group_name === groupVal;
            const matchAgent = agentVal === '' || m.agent_alias === agentVal;

            return matchSearch && matchGroup && matchAgent;
        });

        currentPage = 1;
        renderCurrentPage();
    }

    // Toggle module sync
    async function toggleModule(modId, checked) {
        try {
            const res = await fetch('?api=toggle_module', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id_agente_modulo: modId, enabled: checked })
            });
            const data = await res.json();
            if (data.ok) {
                const mod = modulesList.find(m => m.id_agente_modulo === modId);
                if (mod) {
                    mod.is_synced = checked;
                }
                // Refresh status counts
                const currentCount = parseInt(document.getElementById('activeModulesCount').innerText);
                document.getElementById('activeModulesCount').innerText = (checked ? currentCount + 1 : currentCount - 1) + " Modules";
            } else {
                alert("Failed to toggle module sync: " + data.error);
            }
        } catch (e) {
            alert("Error toggling sync module");
        }
    }

    // Copy Index Name helper
    function copyIndexName(text, btn) {
        if (!navigator.clipboard) {
            const el = document.createElement('textarea');
            el.value = text;
            el.style.position = 'absolute';
            el.style.left = '-9999px';
            document.body.appendChild(el);
            el.select();
            document.execCommand('copy');
            document.body.removeChild(el);
            showSuccessIcon(btn);
            return;
        }
        navigator.clipboard.writeText(text).then(() => {
            showSuccessIcon(btn);
        }).catch(err => {
            console.error('Failed to copy: ', err);
        });
    }

    function showSuccessIcon(btn) {
        const icon = btn.querySelector('.material-symbols-outlined');
        if (!icon) return;
        const originalText = icon.innerText;
        icon.innerText = 'check';
        icon.style.color = '#10b981';
        setTimeout(() => {
            icon.innerText = originalText;
            icon.style.color = '';
        }, 1500);
    }

    // Sync specific module now
    async function syncNow(modId, btn) {
        const originalHtml = btn.innerHTML;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Syncing...`;
        btn.disabled = true;

        try {
            const res = await fetch(`?api=sync_now&id_agente_modulo=${modId}`);
            const data = await res.json();
            if (data.ok) {
                alert(`Successfully synced! Indexed ${data.parsed_rows} row(s) to OpenSearch.`);
            } else {
                alert(`Sync Failed: ${data.error}`);
            }
        } catch (e) {
            alert("Network error occurred during manual sync");
        } finally {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
    }

    // Test OpenSearch Connection
    async function testOpenSearchConnection() {
        const url = document.getElementById('osUrl').value;
        const username = document.getElementById('osUser').value;
        const password = document.getElementById('osPass').value;
        const ssl_verify = document.getElementById('osSslVerify').checked ? 1 : 0;
        
        const btn = document.getElementById('testBtn');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Testing...`;
        btn.disabled = true;

        try {
            const res = await fetch(`?api=test_connection&url=${encodeURIComponent(url)}&username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}&ssl_verify=${ssl_verify}`);
            const data = await res.json();
            if (data.ok) {
                alert("Connection successful! OpenSearch is reachable.");
            } else {
                alert(`Connection Failed: ${data.error}`);
            }
        } catch (e) {
            alert("Network error testing connection.");
        } finally {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
    }

    // Save configuration
    async function saveOpenSearchConfig() {
        const url = document.getElementById('osUrl').value;
        const index = document.getElementById('osIndex').value;
        const username = document.getElementById('osUser').value;
        const password = document.getElementById('osPass').value;
        const ssl_verify = document.getElementById('osSslVerify').checked;

        const btn = document.getElementById('saveBtn');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Saving...`;
        btn.disabled = true;

        try {
            const res = await fetch('?api=save_settings', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url, index, username, password, ssl_verify })
            });
            const data = await res.json();
            if (data.ok) {
                alert("Configuration saved successfully!");
            } else {
                alert(`Failed to save config: ${data.error}`);
            }
        } catch (e) {
            alert("Error saving config");
        } finally {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
    }

    // Force Trigger Background Cron
    async function triggerCronSync() {
        const btn = document.getElementById('cronBtn');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Executing...`;
        btn.disabled = true;

        try {
            const res = await fetch('?api=trigger_cron');
            const data = await res.json();
            if (data.ok) {
                alert(`Auto sync executed! Synced ${data.synced_modules} module(s) and indexed ${data.indexed_rows} row(s).`);
                location.reload();
            } else {
                alert(`Sync partially failed or configuration issue:\n${data.errors.join('\n')}`);
            }
        } catch (e) {
            alert("Network error triggering cron.");
        } finally {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
        }
    }

    async function loadLiveLogs() {
        const box = document.getElementById('liveLogsBox');
        if (!box) return;
        
        try {
            const res = await fetch('?api=get_logs');
            const data = await res.json();
            if (data.ok) {
                if (!data.logs || data.logs.length === 0) {
                    box.innerHTML = `<div style="color:#64748b; font-style:italic;">No log entries found in snapshot_to_opensearch.log</div>`;
                    return;
                }
                box.innerHTML = data.logs.map(line => {
                    let color = '#94a3b8';
                    if (line.includes('[ERROR]')) {
                        color = '#f87171';
                    } else if (line.includes('[WARNING]')) {
                        color = '#fbbf24';
                    } else if (line.includes('[INFO]')) {
                        color = '#a7f3d0';
                    }
                    return `<div style="color:${color}; margin-bottom:4px;">${escapeHtml(line)}</div>`;
                }).join('');
                
                box.scrollTop = box.scrollHeight;
            } else {
                box.innerHTML = `<div style="color:#ef4444;">Error loading logs: ${escapeHtml(data.error)}</div>`;
            }
        } catch (e) {
            box.innerHTML = `<div style="color:#ef4444;">Failed to fetch logs: ${escapeHtml(e.message)}</div>`;
        }
    }

    // Load logs on page load
    loadLiveLogs();

    function escapeHtml(text) {
        if (!text) return '';
        return text
            .toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function cleanIndexName(name) {
        if (!name) return '';
        return name.toLowerCase()
                   .replace(/[^a-z0-9_\-]/g, '_')
                   .replace(/__+/g, '_')
                   .replace(/^_+|_+$/g, '')
                   .replace(/^-+|-+$/g, '');
    }
</script>

</body>
</html>
