<?php
declare(strict_types=1);

/**
 * SNMP Explorer & Module Provisioning System
 * PFMS-Toolkit Enterprise Edition
 * 
 * Features:
 * - Direct SNMP v1/v2c/v3 Device & Subnet (CIDR) Scanner with 9 Discovery Profiles
 * - Vendor Intelligence Engine: Huawei, Cisco, ZTE, Raisecom, Alcatel, Dahua, F5, Epson, Generic
 * - Sensor Normalization & Optical DOM Threshold Detection
 * - Real-time Pandora FMS Agent & Module Provisioning (id_modulo=2, id_tipo_modulo=15)
 * - Clean UI/UX matching PFMS-Toolkit Standards (Inter, Material Symbols, Teal Theme)
 */

// 1. SECURITY & TIMEOUTS
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

set_time_limit(180);
ini_set('memory_limit', '512M');

// 2. CORE DATABASE & SESSION
require_once __DIR__ . '/../../includes/db-connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = $_SESSION['id_usuario'] ?? '';
$pandora_base = !empty($PANDORA_BASE_URL) ? $PANDORA_BASE_URL : '/pandora_console';

if (empty($user_id)) {
    header("Location: " . $pandora_base . "/index.php");
    exit;
}

$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';
if (empty($csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['pfms_csrf_token'] = $csrf_token;
}

$is_admin = !empty($user_id) && isset($pdo) && ($pdo instanceof PDO) && is_pandora_administrator($pdo, $user_id);

// 3. BOOTSTRAP SNMP EXPLORER ENGINE
require_once __DIR__ . '/engine/bootstrap.php';
$engine = snmp_explorer_bootstrap($pdo);

$scanner = $engine['scanner'];
$subnetScanner = $engine['subnetScanner'];
$provisioner = $engine['provisioner'];
$deviceRepo = $engine['deviceRepo'];
$sensorRepo = $engine['sensorRepo'];
$agentRepo = $engine['agentRepo'];
$pandoraRepo = $engine['pandoraRepo'];
$engineConfig = $engine['config'];

// =====================================================================
// 4. AJAX API ENDPOINTS
// =====================================================================
$api = $_GET['api'] ?? '';

if (!empty($api)) {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    // Helper: CSRF verification for mutating requests
    $verify_csrf = function() use ($csrf_token) {
        $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        if (empty($client_token) || $client_token !== $csrf_token) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
            exit;
        }
    };

    // API: Get Global Stats
    if ($api === 'get_stats') {
        try {
            $stDev = $pdo->query("SELECT COUNT(*) FROM devices");
            $devCount = (int)$stDev->fetchColumn();

            $stSens = $pdo->query("SELECT COUNT(*), SUM(CASE WHEN provisioned = 1 THEN 1 ELSE 0 END) FROM sensor_inventory");
            $sensRow = $stSens->fetch(PDO::FETCH_NUM);
            $sensCount = (int)($sensRow[0] ?? 0);
            $provCount = (int)($sensRow[1] ?? 0);

            echo json_encode([
                'ok' => true,
                'devices' => $devCount,
                'sensors' => $sensCount,
                'provisioned' => $provCount,
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Scan Single Device
    if ($api === 'scan_device' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;

        $host = trim((string)($input['host'] ?? $input['ip_address'] ?? ''));
        $community = trim((string)($input['community'] ?? 'public'));
        $version = trim((string)($input['version'] ?? '2c'));
        $port = (int)($input['port'] ?? 161);
        $profile = trim((string)($input['profile'] ?? 'provisioning'));
        $v3_user = trim((string)($input['v3_user'] ?? ''));
        $v3_sec_level = trim((string)($input['v3_sec_level'] ?? 'authPriv'));
        $v3_auth_proto = trim((string)($input['v3_auth_proto'] ?? 'SHA'));
        $v3_auth_pass = (string)($input['v3_auth_pass'] ?? '');
        $v3_priv_proto = trim((string)($input['v3_priv_proto'] ?? 'AES'));
        $v3_priv_pass = (string)($input['v3_priv_pass'] ?? '');
        $v3_context = trim((string)($input['v3_context'] ?? ''));

        if (empty($host)) {
            echo json_encode(['ok' => false, 'error' => 'Target IP or Hostname is required.']);
            exit;
        }

        try {
            $result = $scanner->scan([
                'host' => $host,
                'community' => $community,
                'version' => $version,
                'port' => $port,
                'discovery_profile' => $profile,
                'v3_user' => $v3_user,
                'v3_sec_level' => $v3_sec_level,
                'v3_auth_proto' => $v3_auth_proto,
                'v3_auth_pass' => $v3_auth_pass,
                'v3_priv_proto' => $v3_priv_proto,
                'v3_priv_pass' => $v3_priv_pass,
                'v3_context' => $v3_context,
            ]);

            echo json_encode([
                'ok' => true,
                'data' => $result,
                'message' => sprintf(
                    'Device %s scanned successfully! Discovered %d sensors in %.2fs.',
                    $host,
                    count($result['sensors'] ?? []),
                    $result['scan']['duration_sec'] ?? 0
                )
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Scan Subnet (CIDR)
    if ($api === 'scan_subnet' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;

        $cidr = trim((string)($input['cidr'] ?? ''));
        $community = trim((string)($input['community'] ?? 'public'));
        $version = trim((string)($input['version'] ?? '2c'));
        $port = (int)($input['port'] ?? 161);
        $profile = trim((string)($input['profile'] ?? 'provisioning'));
        $v3_user = trim((string)($input['v3_user'] ?? ''));
        $v3_sec_level = trim((string)($input['v3_sec_level'] ?? 'authPriv'));
        $v3_auth_proto = trim((string)($input['v3_auth_proto'] ?? 'SHA'));
        $v3_auth_pass = (string)($input['v3_auth_pass'] ?? '');
        $v3_priv_proto = trim((string)($input['v3_priv_proto'] ?? 'AES'));
        $v3_priv_pass = (string)($input['v3_priv_pass'] ?? '');
        $v3_context = trim((string)($input['v3_context'] ?? ''));

        if (empty($cidr)) {
            echo json_encode(['ok' => false, 'error' => 'Subnet CIDR (e.g. 192.168.1.0/24) is required.']);
            exit;
        }

        try {
            $ips = $subnetScanner->expandSubnet($cidr);
            if (empty($ips)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid CIDR notation or range produces 0 hosts.']);
                exit;
            }

            // Limit sequential batch scan to 64 hosts per request for browser stability
            $ips = array_slice($ips, 0, 64);
            $scanned = [];
            $discovered_sensors = 0;

            foreach ($ips as $ip) {
                try {
                    $res = $scanner->scan([
                        'host' => $ip,
                        'community' => $community,
                        'version' => $version,
                        'port' => $port,
                        'discovery_profile' => $profile,
                        'v3_user' => $v3_user,
                        'v3_sec_level' => $v3_sec_level,
                        'v3_auth_proto' => $v3_auth_proto,
                        'v3_auth_pass' => $v3_auth_pass,
                        'v3_priv_proto' => $v3_priv_proto,
                        'v3_priv_pass' => $v3_priv_pass,
                        'v3_context' => $v3_context,
                    ]);
                    $scanned[] = [
                        'ip' => $ip,
                        'status' => 'success',
                        'hostname' => $res['device']['hostname'] ?? $ip,
                        'vendor' => $res['vendor'] ?? 'Generic',
                        'sensors' => count($res['sensors'] ?? []),
                    ];
                    $discovered_sensors += count($res['sensors'] ?? []);
                } catch (\Throwable $e) {
                    $scanned[] = [
                        'ip' => $ip,
                        'status' => 'offline',
                        'error' => $e->getMessage(),
                    ];
                }
            }

            echo json_encode([
                'ok' => true,
                'total_hosts' => count($ips),
                'discovered_sensors' => $discovered_sensors,
                'results' => $scanned,
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Get Inventory (Paginated & Filtered)
    if ($api === 'get_inventory') {
        try {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per_page = max(10, min(500, (int)($_GET['per_page'] ?? 50)));
            $offset = ($page - 1) * $per_page;

            $filters = [];
            $params = [];

            if (!empty($_GET['device_id'])) {
                $filters[] = "s.device_id = :device_id";
                $params[':device_id'] = (int)$_GET['device_id'];
            }
            if (!empty($_GET['ip_address'])) {
                $filters[] = "s.ip_address LIKE :ip_address";
                $params[':ip_address'] = '%' . trim($_GET['ip_address']) . '%';
            }
            if (!empty($_GET['vendor'])) {
                $filters[] = "s.vendor = :vendor";
                $params[':vendor'] = trim($_GET['vendor']);
            }
            if (!empty($_GET['sensor_class'])) {
                $filters[] = "s.sensor_class = :sensor_class";
                $params[':sensor_class'] = trim($_GET['sensor_class']);
            }
            if (isset($_GET['provisioned']) && $_GET['provisioned'] !== '') {
                $filters[] = "s.provisioned = :provisioned";
                $params[':provisioned'] = (int)$_GET['provisioned'];
            }
            if (!empty($_GET['q'])) {
                $q = trim($_GET['q']);
                $filters[] = "(s.sensor_name LIKE :q1 OR s.oid LIKE :q2 OR s.interface_name LIKE :q3 OR s.ip_address LIKE :q4)";
                $params[':q1'] = '%' . $q . '%';
                $params[':q2'] = '%' . $q . '%';
                $params[':q3'] = '%' . $q . '%';
                $params[':q4'] = '%' . $q . '%';
            }

            $whereSql = !empty($filters) ? 'WHERE ' . implode(' AND ', $filters) : '';

            // Count total
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sensor_inventory s $whereSql");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Fetch rows
            $dataSql = "SELECT s.*, d.hostname, a.nombre as agent_name 
                        FROM sensor_inventory s 
                        LEFT JOIN devices d ON s.device_id = d.id 
                        LEFT JOIN tagente a ON s.pandora_agent_id = a.id_agente 
                        $whereSql 
                        ORDER BY s.id DESC 
                        LIMIT $per_page OFFSET $offset";
            $dataStmt = $pdo->prepare($dataSql);
            $dataStmt->execute($params);
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok' => true,
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => ceil($total / $per_page),
                'rows' => $rows,
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Get Devices List
    if ($api === 'get_devices') {
        try {
            $stmt = $pdo->query("SELECT id, ip_address, hostname, vendor, snmp_version, last_scanned_at FROM devices ORDER BY ip_address ASC");
            echo json_encode(['ok' => true, 'devices' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Get Pandora Agents List
    if ($api === 'get_agents') {
        try {
            $agents = $agentRepo->all();
            echo json_encode(['ok' => true, 'agents' => $agents]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Get Metadata for New Agent Creation (Groups, OS, Servers)
    if ($api === 'get_agent_meta') {
        try {
            echo json_encode([
                'ok' => true,
                'groups' => $agentRepo->getGroups(),
                'operating_systems' => $agentRepo->getOperatingSystems(),
                'servers' => $agentRepo->getServers(),
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Quick Create Pandora Agent
    if ($api === 'create_agent' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;

        $alias = trim((string)($input['agent_alias'] ?? ''));
        $ip = trim((string)($input['ip_address'] ?? ''));
        $groupId = (int)($input['id_grupo'] ?? 1);
        $osId = (int)($input['id_os'] ?? 1);
        $interval = (int)($input['interval'] ?? 300);

        if (empty($alias) || empty($ip)) {
            echo json_encode(['ok' => false, 'error' => 'Agent Alias and IP Address are required.']);
            exit;
        }

        try {
            $agentRepo->createAgent([
                'agent_alias' => $alias,
                'use_alias_as_name' => 1,
                'ip_address' => $ip,
                'id_grupo' => $groupId,
                'id_os' => $osId,
                'interval' => $interval,
            ]);

            // Retrieve created agent
            $st = $pdo->prepare("SELECT id_agente, nombre, direccion FROM tagente WHERE nombre = ? OR alias = ? ORDER BY id_agente DESC LIMIT 1");
            $st->execute([$alias, $alias]);
            $created = $st->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok' => true,
                'message' => 'Pandora FMS Agent created successfully!',
                'agent' => $created,
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Execute Provisioning to Pandora
    if ($api === 'provision' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;

        $agentId = (int)($input['agent_id'] ?? 0);
        $sensorIds = array_values(array_filter(array_map('intval', (array)($input['sensor_ids'] ?? []))));

        if ($agentId <= 0 || empty($sensorIds)) {
            echo json_encode(['ok' => false, 'error' => 'Target Pandora Agent and at least one sensor must be selected.']);
            exit;
        }

        try {
            $summary = $provisioner->provision($sensorIds, $agentId);
            echo json_encode([
                'ok' => true,
                'summary' => $summary,
                'message' => sprintf(
                    'Provisioning completed: %d created, %d already existing, %d skipped.',
                    $summary['created'],
                    $summary['existing'],
                    $summary['skipped']
                ),
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Delete Device or Sensor
    if ($api === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;

        $type = $input['type'] ?? '';
        $id = (int)($input['id'] ?? 0);

        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid ID specified.']);
            exit;
        }

        try {
            if ($type === 'device') {
                $st = $pdo->prepare("DELETE FROM devices WHERE id = ?");
                $st->execute([$id]);
                echo json_encode(['ok' => true, 'message' => 'Device and associated sensors removed.']);
            } elseif ($type === 'sensor') {
                $st = $pdo->prepare("DELETE FROM sensor_inventory WHERE id = ?");
                $st->execute([$id]);
                echo json_encode(['ok' => true, 'message' => 'Sensor removed from inventory.']);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Unknown delete target.']);
            }
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Save Local Configuration
    if ($api === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;

        $settingsFile = __DIR__ . '/snmp_explorer_settings.json';
        $written = @file_put_contents($settingsFile, json_encode($input, JSON_PRETTY_PRINT));

        if ($written !== false) {
            echo json_encode(['ok' => true, 'message' => 'SNMP Explorer settings saved successfully.']);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to write settings file. Check directory permissions.']);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown API action.']);
    exit;
}

// =====================================================================
// 5. VIEW DATA PREPARATION
// =====================================================================
$profiles = (array)($engineConfig['discovery']['profiles'] ?? []);
$profileDescriptions = (array)($engineConfig['discovery']['descriptions'] ?? []);
$defaultProfile = (string)($engineConfig['discovery']['profile'] ?? 'provisioning');

$vendors = [];
$sensorClasses = [];
try {
    $vSt = $pdo->query("SELECT DISTINCT vendor FROM sensor_inventory ORDER BY vendor ASC");
    $vendors = $vSt->fetchAll(PDO::FETCH_COLUMN);

    $cSt = $pdo->query("SELECT DISTINCT sensor_class FROM sensor_inventory ORDER BY sensor_class ASC");
    $sensorClasses = $cSt->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {}

$dynamic_breadcrumb = "PANDORA CONSOLE / CUSTOM / PANEL / TOOLS / SNMP EXPLORER";
$vendor_url = $pandora_base . '/custom/panel/vendor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SNMP Explorer & Provisioning - PFMS Toolkit</title>
    
    <!-- 1. Offline Local Fallback Relative to File (Direct and guaranteed) -->
    <link href="../../vendor/fonts/fonts.css" rel="stylesheet">
    <link href="../../vendor/bootstrap/bootstrap.min.css" rel="stylesheet">

    <!-- 2. Dynamic Server Base Path Fallbacks -->
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">

    <!-- 3. High-res Google Fonts CDN Fallback (Inter & Material Symbols) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    
    <style>
        /* Fallback local font-face declarations to guarantee rendering */
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url('../../vendor/fonts/Inter-Variable.woff2') format('woff2');
        }
        @font-face {
            font-family: 'Material Symbols Outlined';
            font-style: normal;
            font-weight: 100 700;
            font-display: swap;
            src: url('../../vendor/fonts/MaterialSymbolsOutlined.woff2') format('woff2'),
                 url('../../vendor/fonts/material-symbols-outlined.ttf') format('truetype');
        }

        :root {
            --brand-green: #004d40;
            --brand-green-hover: #00695c;
            --primary-navy: #0b1a26;
            --secondary-text: #64748b;
            --muted-text: #94a3b8;
            --bg-body: #f4f6f8;
            --card-bg: #ffffff;
            --border-color: #e0e4e8;
            --border-light: #f0f3f5;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #0284c7;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body, html, input, button, select, textarea {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif !important;
        }

        body {
            background-color: var(--bg-body);
            color: #334155;
            font-size: 13px;
            line-height: 1.5;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* Material Symbols Outlined Icon Styling (Guarantees icons render as symbols, not raw text) */
        .material-symbols-outlined {
            font-family: 'Material Symbols Outlined' !important;
            font-weight: normal !important;
            font-style: normal !important;
            font-size: 18px;
            line-height: 1 !important;
            letter-spacing: normal;
            text-transform: none;
            display: inline-block;
            white-space: nowrap;
            word-wrap: normal;
            direction: ltr;
            vertical-align: middle;
            font-feature-settings: 'liga' 1;
            -webkit-font-feature-settings: 'liga';
            -webkit-font-smoothing: antialiased;
        }

        /* Standard Header Section matching Inventory Agent & PFMS-Toolkit PRD */
        .header-box {
            background: #f4f6f8;
            border-bottom: 1px solid var(--border-color);
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .breadcrumb-text {
            font-size: 10px;
            font-weight: normal;
            color: var(--muted-text);
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .page-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--primary-navy);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Quick Stat Badges */
        .stat-badge {
            background: #f8fafc;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 6px 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 600;
        }
        .stat-badge .num {
            color: var(--brand-green);
            font-weight: 800;
            font-size: 13px;
        }

        /* Container Layout */
        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px 30px;
        }

        /* Tab Navigation Bar */
        .tabs-nav {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 24px;
        }
        .tab-btn {
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            padding: 10px 18px;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--secondary-text);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            margin-bottom: -2px;
        }
        .tab-btn:hover {
            color: var(--brand-green);
        }
        .tab-btn.active {
            color: var(--brand-green);
            border-bottom-color: var(--brand-green);
        }
        .tab-btn .badge-pill {
            background: #e2e8f0;
            color: #475569;
            font-size: 11px;
            padding: 2px 7px;
            border-radius: 10px;
            font-weight: 700;
        }

        /* Standard Cards */
        .dashboard-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
            padding: 20px;
            margin-bottom: 20px;
        }
        .card-header-clean {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-light);
        }
        .card-header-clean h3 {
            font-size: 15px;
            font-weight: 700;
            color: var(--primary-navy);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Form Controls */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #475569;
        }
        .form-control, .form-select {
            height: 34px;
            padding: 0 12px;
            border: 1px solid #dce1e5;
            border-radius: 4px;
            font-size: 13px;
            font-family: inherit !important;
            color: #1e293b;
            background: #ffffff;
            outline: none;
            transition: border 0.15s ease, box-shadow 0.15s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--brand-green);
            box-shadow: 0 0 0 2px rgba(0, 77, 64, 0.12);
        }

        /* Buttons */
        .btn-apply {
            background: var(--brand-green);
            color: #ffffff !important;
            border: none;
            border-radius: 4px;
            height: 34px;
            padding: 0 16px;
            font-size: 13px;
            font-weight: 600;
            font-family: inherit !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .btn-apply:hover {
            background: var(--brand-green-hover);
        }
        .btn-apply:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .btn-secondary-custom {
            background: #ffffff;
            color: #475569 !important;
            border: 1px solid #dce1e5;
            border-radius: 4px;
            height: 32px;
            padding: 0 14px;
            font-size: 12px;
            font-weight: 500;
            font-family: inherit !important;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .btn-secondary-custom:hover {
            background: #f8fafc;
            color: #0f172a !important;
            border-color: #94a3b8;
        }
        .btn-danger-custom {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            border-radius: 6px;
            height: 36px;
            padding: 0 14px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-danger-custom:hover {
            background: #fca5a5;
        }

        /* Status Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11.5px;
            font-weight: 700;
        }
        .badge-success { background: #dcfce7; color: #166534; }
        .badge-warning { background: #fef9c3; color: #854d0e; }
        .badge-danger { background: #fee2e2; color: #b91c1c; }
        .badge-info { background: #dbeafe; color: #1d4ed8; }
        .badge-neutral { background: #e2e8f0; color: #475569; }

        /* Tables */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        .custom-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        .custom-table th {
            background: #f8fafc;
            color: #475569;
            font-weight: 700;
            padding: 10px 12px;
            text-align: left;
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }
        .custom-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border-light);
            color: #334155;
            vertical-align: middle;
        }
        .custom-table tr:hover td {
            background-color: #f8fafc;
        }

        /* Monospace Elements */
        .mono {
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
        }

        /* Terminal Console */
        .terminal-box {
            background: #0f172a;
            color: #38bdf8;
            border-radius: 8px;
            padding: 16px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            max-height: 280px;
            overflow-y: auto;
            white-space: pre-wrap;
            line-height: 1.4;
            display: none;
            margin-top: 14px;
            border: 1px solid #1e293b;
        }

        /* Drawer / Modal */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(2px);
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease;
        }
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .modal-card {
            background: #ffffff;
            border-radius: 12px;
            width: 100%;
            max-width: 600px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
            overflow: hidden;
        }
        .modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary-navy);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-body {
            padding: 20px 24px;
            max-height: 70vh;
            overflow-y: auto;
        }
        .modal-footer {
            padding: 14px 24px;
            border-top: 1px solid var(--border-color);
            background: #f8fafc;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Helper Classes */
        .d-none { display: none !important; }
        .text-truncate-cell {
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    </style>
</head>
<body>

    <!-- Header Section -->
    <div class="header-box">
        <div>
            <div class="breadcrumb-text"><?= htmlspecialchars($dynamic_breadcrumb) ?></div>
            <h1 class="page-title">SNMP Explorer & Provisioning</h1>
        </div>
        <div class="header-actions">
            <div class="stat-badge">
                Devices: <span class="num" id="stat-devices">0</span>
            </div>
            <div class="stat-badge">
                Sensors: <span class="num" id="stat-sensors">0</span>
            </div>
            <div class="stat-badge">
                Provisioned: <span class="num" id="stat-provisioned">0</span>
            </div>
            <button class="btn-secondary-custom" onclick="reloadCurrentTab()">
                Refresh
            </button>
        </div>
    </div>

    <!-- Main Container -->
    <div class="main-container">

        <!-- Tab Navigation -->
        <div class="tabs-nav">
            <button class="tab-btn active" data-tab="tab-scan" onclick="switchTab('tab-scan')">
                Scan Console
            </button>
            <button class="tab-btn" data-tab="tab-inventory" onclick="switchTab('tab-inventory')">
                Sensor Inventory
                <span class="badge-pill" id="inventory-tab-count">0</span>
            </button>
            <button class="tab-btn" data-tab="tab-provision" onclick="switchTab('tab-provision')">
                Pandora Provisioning
                <span class="badge-pill" id="selected-provision-count" style="background:#004d40; color:#fff;">0</span>
            </button>
            <button class="tab-btn" data-tab="tab-settings" onclick="switchTab('tab-settings')">
                Settings & Profiles
            </button>
        </div>

        <!-- ============================================================= -->
        <!-- TAB 1: SCAN CONSOLE                                           -->
        <!-- ============================================================= -->
        <div id="tab-scan" class="tab-content">
            <div class="dashboard-card">
                <div class="card-header-clean">
                    <h3>SNMP Discovery Engine</h3>
                    <div style="display:flex; gap:8px;">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:600;">
                            <input type="radio" name="scan_mode" value="single" checked onchange="toggleScanMode()">
                            Single IP / Host
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-weight:600; margin-left:14px;">
                            <input type="radio" name="scan_mode" value="subnet" onchange="toggleScanMode()">
                            Subnet (CIDR Range)
                        </label>
                    </div>
                </div>

                <form id="scan-form" onsubmit="executeScan(event)">
                    <div class="form-grid">
                        <div class="form-group" id="group-target-ip">
                            <label class="form-label">Target IP Address / Hostname *</label>
                            <input type="text" id="scan-target" class="form-control mono" placeholder="e.g. 192.168.1.1 or switch-core" required>
                        </div>
                        <div class="form-group d-none" id="group-target-cidr">
                            <label class="form-label">Subnet CIDR Range *</label>
                            <input type="text" id="scan-cidr" class="form-control mono" placeholder="e.g. 192.168.1.0/24 (Max 64 hosts)">
                        </div>
                        <div class="form-group" id="group-community">
                            <label class="form-label">SNMP Community *</label>
                            <input type="text" id="scan-community" class="form-control mono" value="public" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">SNMP Version</label>
                            <select id="scan-version" class="form-control" onchange="toggleScanVersion()">
                                <option value="2c" selected>SNMP v2c (Recommended)</option>
                                <option value="1">SNMP v1</option>
                                <option value="3">SNMP v3</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">SNMP UDP Port</label>
                            <input type="number" id="scan-port" class="form-control mono" value="161" min="1" max="65535">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Discovery Profile</label>
                            <select id="scan-profile" class="form-control" onchange="updateProfileInfo()">
                                <?php foreach ($profiles as $pName => $pModules): ?>
                                    <option value="<?= htmlspecialchars($pName) ?>" <?= $pName === $defaultProfile ? 'selected' : '' ?>>
                                        <?= strtoupper(htmlspecialchars($pName)) ?> (<?= count($pModules) ?> modules)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- SNMP v3 Security Parameters (Visible only when SNMP v3 is selected) -->
                    <div id="snmpv3-credentials-box" class="d-none" style="background:#f8fafc; border:1px solid #cbd5e1; border-left:4px solid var(--brand-green); border-radius:6px; padding:14px 16px; margin-bottom:16px;">
                        <div style="font-weight:700; color:var(--primary-navy); margin-bottom:12px; font-size:13px; display:flex; align-items:center; gap:8px;">
                            <span class="material-symbols-outlined" style="font-size:18px; color:var(--brand-green);">lock</span>
                            <span>SNMP v3 Security Credentials (USM)</span>
                            <span style="font-weight:normal; font-size:11px; color:#64748b;">User-based Security Model with Cryptographic Authentication & Encryption</span>
                        </div>
                        <div class="form-grid" style="margin-bottom:0;">
                            <div class="form-group">
                                <label class="form-label">Security Level *</label>
                                <select id="scan-v3-sec-level" class="form-control" onchange="toggleV3SecurityLevel()">
                                    <option value="authPriv" selected>authPriv (Auth & Privacy / Encryption - Recommended)</option>
                                    <option value="authNoPriv">authNoPriv (Authentication only, No Encryption)</option>
                                    <option value="noAuthNoPriv">noAuthNoPriv (No Auth, No Encryption)</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Security Name / Username *</label>
                                <input type="text" id="scan-v3-user" class="form-control mono" placeholder="e.g. snmpuser">
                            </div>
                            <div class="form-group" id="group-v3-auth-proto">
                                <label class="form-label">Auth Protocol</label>
                                <select id="scan-v3-auth-proto" class="form-control">
                                    <option value="SHA" selected>SHA (SHA-1)</option>
                                    <option value="SHA-256">SHA-256</option>
                                    <option value="MD5">MD5</option>
                                    <option value="SHA-512">SHA-512</option>
                                </select>
                            </div>
                            <div class="form-group" id="group-v3-auth-pass">
                                <label class="form-label">Auth Passphrase *</label>
                                <div style="position:relative; display:flex; align-items:center;">
                                    <input type="password" id="scan-v3-auth-pass" class="form-control mono" style="padding-right:48px;" placeholder="Min 8 characters">
                                    <button type="button" onclick="togglePassVisibility('scan-v3-auth-pass', this)" style="position:absolute; right:8px; background:none; border:none; cursor:pointer; color:#64748b; font-size:11px; font-weight:600;">Show</button>
                                </div>
                            </div>
                            <div class="form-group" id="group-v3-priv-proto">
                                <label class="form-label">Privacy (Encryption) Protocol</label>
                                <select id="scan-v3-priv-proto" class="form-control">
                                    <option value="AES" selected>AES (AES-128 - Recommended)</option>
                                    <option value="AES-256">AES-256</option>
                                    <option value="DES">DES</option>
                                    <option value="3DES">3DES</option>
                                </select>
                            </div>
                            <div class="form-group" id="group-v3-priv-pass">
                                <label class="form-label">Privacy Passphrase *</label>
                                <div style="position:relative; display:flex; align-items:center;">
                                    <input type="password" id="scan-v3-priv-pass" class="form-control mono" style="padding-right:48px;" placeholder="Min 8 characters">
                                    <button type="button" onclick="togglePassVisibility('scan-v3-priv-pass', this)" style="position:absolute; right:8px; background:none; border:none; cursor:pointer; color:#64748b; font-size:11px; font-weight:600;">Show</button>
                                </div>
                            </div>
                            <div class="form-group" id="group-v3-context">
                                <label class="form-label">Context Name (Optional)</label>
                                <input type="text" id="scan-v3-context" class="form-control mono" placeholder="Default: empty">
                            </div>
                        </div>
                    </div>

                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 14px; margin-bottom:16px; font-size:12px; color:#475569;" id="profile-description-box">
                        <strong id="profile-desc-title">Provisioning Profile:</strong>
                        <span id="profile-desc-text"><?= htmlspecialchars($profileDescriptions['provisioning'] ?? 'Fast discovery scan.') ?></span>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size:12px; color:var(--secondary-text);">
                            * Scanned devices and discovered sensors are normalized and saved automatically into inventory.
                        </span>
                        <button type="submit" class="btn-apply" id="btn-start-scan">
                            Start SNMP Discovery
                        </button>
                    </div>
                </form>

                <!-- Terminal / Log Box -->
                <div class="terminal-box" id="scan-terminal"></div>
            </div>

            <!-- Last Scan Summary Card -->
            <div class="dashboard-card d-none" id="scan-result-card">
                <div class="card-header-clean">
                    <h3>Discovery Result Summary</h3>
                    <button class="btn-apply" onclick="switchTab('tab-inventory')">
                        View in Inventory &rarr;
                    </button>
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px;" id="scan-summary-grid"></div>

                <!-- Discovered Modules & Sensors Table Preview -->
                <div style="margin-top: 22px; border-top: 1px solid var(--border-color); padding-top: 18px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                        <h4 style="font-size:13.5px; font-weight:700; color:var(--primary-navy);">
                            Discovered Modules & Sensors Preview (<span id="scan-preview-count">0</span>)
                        </h4>
                        <div style="display:flex; gap:8px;">
                            <button class="btn-apply" onclick="switchTab('tab-inventory')" style="font-size:12px; height:32px; padding:0 14px;">
                                Manage & Provision in Inventory &rarr;
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive" style="max-height: 480px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 6px; background:#fff;">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th style="width:40px; text-align:center;">#</th>
                                    <th>Sensor / Module Name</th>
                                    <th>Class</th>
                                    <th>Current Value</th>
                                    <th>SNMP OID</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="scan-preview-tbody">
                                <tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">No modules scanned yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================= -->
        <!-- TAB 2: SENSOR INVENTORY                                       -->
        <!-- ============================================================= -->
        <div id="tab-inventory" class="tab-content d-none">
            <div class="dashboard-card">
                <div class="card-header-clean">
                    <h3>Discovered Sensor Inventory</h3>
                    <div style="display:flex; gap:10px;">
                        <button class="btn-secondary-custom" onclick="clearSelectedSensors()">
                            Deselect All
                        </button>
                        <button class="btn-apply" onclick="proceedToProvisioning()">
                            Provision Selected (<span id="inv-selected-count">0</span>)
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom:14px;">
                    <div class="form-group">
                        <label class="form-label">Filter Device / IP</label>
                        <select id="filter-device" class="form-control" onchange="loadInventory(1)">
                            <option value="">-- All Devices --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Filter Vendor</label>
                        <select id="filter-vendor" class="form-control" onchange="loadInventory(1)">
                            <option value="">-- All Vendors --</option>
                            <?php foreach ($vendors as $v): ?>
                                <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Sensor Class</label>
                        <select id="filter-class" class="form-control" onchange="loadInventory(1)">
                            <option value="">-- All Classes --</option>
                            <?php foreach ($sensorClasses as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select id="filter-provisioned" class="form-control" onchange="loadInventory(1)">
                            <option value="">-- All Status --</option>
                            <option value="0">Pending (Not Provisioned)</option>
                            <option value="1">Provisioned</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Search Query</label>
                        <input type="text" id="filter-query" class="form-control" placeholder="Search OID, Sensor Name..." onkeyup="debounceLoadInventory()">
                    </div>
                </div>

                <!-- Table -->
                <div class="table-responsive">
                    <table class="custom-table" id="inventory-table">
                        <thead>
                            <tr>
                                <th style="width:36px; text-align:center;">
                                    <input type="checkbox" id="check-all-sensors" onchange="toggleSelectAll(this)">
                                </th>
                                <th>Target IP</th>
                                <th>Vendor</th>
                                <th>Class</th>
                                <th>Sensor Name</th>
                                <th>Normalized Value</th>
                                <th>SNMP OID</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="inventory-tbody">
                            <tr><td colspan="9" style="text-align:center; padding:30px; color:#94a3b8;">Loading inventory data...</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px;">
                    <div style="font-size:12px; color:var(--secondary-text);" id="pagination-info">Showing 0 of 0 entries</div>
                    <div style="display:flex; gap:6px;" id="pagination-controls"></div>
                </div>
            </div>
        </div>

        <!-- ============================================================= -->
        <!-- TAB 3: PANDORA PROVISIONING                                   -->
        <!-- ============================================================= -->
        <div id="tab-provision" class="tab-content d-none">
            <div class="dashboard-card">
                <div class="card-header-clean">
                    <h3>Provisioning to Pandora FMS</h3>
                    <button class="btn-secondary-custom" onclick="openCreateAgentModal()">
                        Create New Agent
                    </button>
                </div>

                <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr;">
                    <div class="form-group">
                        <label class="form-label">Select Target Pandora FMS Agent *</label>
                        <select id="prov-agent-select" class="form-control">
                            <option value="">-- Choose Agent from Pandora FMS --</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Module Interval</label>
                        <select id="prov-interval" class="form-control">
                            <option value="60">1 Minute (60s)</option>
                            <option value="300" selected>5 Minutes (300s)</option>
                            <option value="600">10 Minutes (600s)</option>
                            <option value="3600">1 Hour (3600s)</option>
                        </select>
                    </div>
                    <div class="form-group" style="justify-content: flex-end;">
                        <button class="btn-apply" onclick="executeProvisioning()" id="btn-execute-provision">
                            Deploy Modules to Agent
                        </button>
                    </div>
                </div>

                <div style="margin-top:20px;">
                    <h4 style="font-size:13.5px; font-weight:700; color:var(--primary-navy); margin-bottom:10px;">
                        Selected Sensors for Provisioning (<span id="prov-selected-count">0</span>)
                    </h4>
                    <div class="table-responsive" style="max-height:350px;">
                        <table class="custom-table" id="prov-selected-table">
                            <thead>
                                <tr>
                                    <th>IP Address</th>
                                    <th>Sensor Name</th>
                                    <th>Class</th>
                                    <th>OID</th>
                                    <th>Value</th>
                                </tr>
                            </thead>
                            <tbody id="prov-selected-tbody">
                                <tr><td colspan="5" style="text-align:center; padding:20px; color:#94a3b8;">No sensors currently selected. Go to Sensor Inventory to check items.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Provisioning Log / Feedback Card -->
                <div class="dashboard-card d-none" id="prov-results-card" style="margin-top:20px; background:#f8fafc;">
                    <div class="card-header-clean">
                        <h3>Provisioning Deployment Summary</h3>
                    </div>
                    <div id="prov-results-content"></div>
                </div>
            </div>
        </div>

        <!-- ============================================================= -->
        <!-- TAB 4: SETTINGS & PROFILES                                    -->
        <!-- ============================================================= -->
        <div id="tab-settings" class="tab-content d-none">
            <div class="dashboard-card">
                <div class="card-header-clean">
                    <h3>Discovery Engine & MIB Configuration</h3>
                    <button class="btn-apply" onclick="saveSettings()">
                        Save Configuration
                    </button>
                </div>

                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Default SNMP Community</label>
                        <input type="text" id="conf-community" class="form-control mono" value="<?= htmlspecialchars($engineConfig['snmp']['community'] ?? 'public') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Scan Timeout (Seconds)</label>
                        <input type="number" id="conf-timeout" class="form-control mono" value="<?= (int)($engineConfig['snmp']['scan_timeout_sec'] ?? 45) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Discovered Sensors Cap</label>
                        <input type="number" id="conf-max-sensors" class="form-control mono" value="<?= (int)($engineConfig['snmp']['scan_max_sensors'] ?? 10000) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">OID Translation (`snmptranslate`)</label>
                        <select id="conf-translate" class="form-control">
                            <option value="1" <?= ($engineConfig['snmp']['translate_oids'] ?? true) ? 'selected' : '' ?>>Enabled</option>
                            <option value="0" <?= !($engineConfig['snmp']['translate_oids'] ?? true) ? 'selected' : '' ?>>Disabled</option>
                        </select>
                    </div>
                </div>

                <h4 style="font-size:14px; font-weight:700; color:var(--primary-navy); margin: 20px 0 10px 0;">
                    Available Discovery Profiles Breakdown
                </h4>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:14px;">
                    <?php foreach ($profiles as $pKey => $pMods): ?>
                        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                <strong style="color:var(--brand-green); font-size:13px;"><?= strtoupper(htmlspecialchars($pKey)) ?></strong>
                                <span class="badge badge-neutral"><?= count($pMods) ?> Modules</span>
                            </div>
                            <p style="font-size:12px; color:#64748b; margin-bottom:8px; min-height:36px;">
                                <?= htmlspecialchars($profileDescriptions[$pKey] ?? '') ?>
                            </p>
                            <div style="display:flex; flex-wrap:wrap; gap:4px;">
                                <?php foreach (array_slice($pMods, 0, 5) as $m): ?>
                                    <span style="background:#e2e8f0; font-size:10.5px; padding:1px 6px; border-radius:4px;"><?= htmlspecialchars($m) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($pMods) > 5): ?>
                                    <span style="font-size:10.5px; color:#64748b;">+<?= count($pMods) - 5 ?> more</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>

    <!-- Modal: Quick Create Pandora Agent -->
    <div class="modal-overlay" id="modal-create-agent">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Create Pandora FMS Agent</h3>
                <button type="button" class="btn-secondary-custom" style="padding:2px 8px; font-size:16px; line-height:1;" onclick="closeCreateAgentModal()">&times;</button>
            </div>
            <form onsubmit="submitCreateAgent(event)">
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom:12px;">
                        <label class="form-label">Agent Name / Alias *</label>
                        <input type="text" id="new-agent-alias" class="form-control" placeholder="e.g. Switch-Core-01" required>
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label class="form-label">IP Address *</label>
                        <input type="text" id="new-agent-ip" class="form-control mono" placeholder="e.g. 192.168.1.1" required>
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label class="form-label">Agent Group *</label>
                        <select id="new-agent-group" class="form-control"></select>
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label class="form-label">Operating System</label>
                        <select id="new-agent-os" class="form-control"></select>
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label class="form-label">Execution Interval</label>
                        <select id="new-agent-interval" class="form-control">
                            <option value="60">1 Minute (60s)</option>
                            <option value="300" selected>5 Minutes (300s)</option>
                            <option value="600">10 Minutes (600s)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" onclick="closeCreateAgentModal()">Cancel</button>
                    <button type="submit" class="btn-apply">Create Agent</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript Application Logic -->
    <script>
        const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
        const PROFILE_DESCRIPTIONS = <?= json_encode($profileDescriptions) ?>;

        let selectedSensorMap = {};
        let currentInventoryPage = 1;
        let inventoryDebounceTimer = null;

        document.addEventListener('DOMContentLoaded', () => {
            loadStats();
            loadDevicesList();
            loadAgentsList();
            loadInventory(1);
        });

        // Tab Switching
        function switchTab(tabId) {
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.getAttribute('data-tab') === tabId);
            });
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.toggle('d-none', content.id !== tabId);
            });

            if (tabId === 'tab-inventory') {
                loadInventory(currentInventoryPage);
            } else if (tabId === 'tab-provision') {
                renderSelectedProvisionTable();
            }
        }

        function reloadCurrentTab() {
            loadStats();
            loadDevicesList();
            loadAgentsList();
            loadInventory(currentInventoryPage);
        }

        // Toggle Single vs Subnet Scan
        function toggleScanMode() {
            const isSubnet = document.querySelector('input[name="scan_mode"]:checked').value === 'subnet';
            document.getElementById('group-target-ip').classList.toggle('d-none', isSubnet);
            document.getElementById('group-target-cidr').classList.toggle('d-none', !isSubnet);
            document.getElementById('scan-target').required = !isSubnet;
            document.getElementById('scan-cidr').required = isSubnet;
        }

        // Toggle SNMP Version (v1/v2c vs v3)
        function toggleScanVersion() {
            const ver = document.getElementById('scan-version').value;
            const isV3 = ver === '3';
            const communityGroup = document.getElementById('group-community');
            const communityInput = document.getElementById('scan-community');
            const v3Box = document.getElementById('snmpv3-credentials-box');
            const v3UserInput = document.getElementById('scan-v3-user');

            if (isV3) {
                communityGroup.classList.add('d-none');
                communityInput.required = false;
                v3Box.classList.remove('d-none');
                v3UserInput.required = true;
                toggleV3SecurityLevel();
            } else {
                communityGroup.classList.remove('d-none');
                communityInput.required = true;
                v3Box.classList.add('d-none');
                v3UserInput.required = false;
                document.getElementById('scan-v3-auth-pass').required = false;
                document.getElementById('scan-v3-priv-pass').required = false;
            }
        }

        // Toggle SNMP v3 Security Level fields
        function toggleV3SecurityLevel() {
            const level = document.getElementById('scan-v3-sec-level').value;
            const authProtoGrp = document.getElementById('group-v3-auth-proto');
            const authPassGrp = document.getElementById('group-v3-auth-pass');
            const authPassInput = document.getElementById('scan-v3-auth-pass');
            const privProtoGrp = document.getElementById('group-v3-priv-proto');
            const privPassGrp = document.getElementById('group-v3-priv-pass');
            const privPassInput = document.getElementById('scan-v3-priv-pass');

            if (level === 'noAuthNoPriv') {
                authProtoGrp.classList.add('d-none');
                authPassGrp.classList.add('d-none');
                authPassInput.required = false;
                privProtoGrp.classList.add('d-none');
                privPassGrp.classList.add('d-none');
                privPassInput.required = false;
            } else if (level === 'authNoPriv') {
                authProtoGrp.classList.remove('d-none');
                authPassGrp.classList.remove('d-none');
                authPassInput.required = true;
                privProtoGrp.classList.add('d-none');
                privPassGrp.classList.add('d-none');
                privPassInput.required = false;
            } else { // authPriv
                authProtoGrp.classList.remove('d-none');
                authPassGrp.classList.remove('d-none');
                authPassInput.required = true;
                privProtoGrp.classList.remove('d-none');
                privPassGrp.classList.remove('d-none');
                privPassInput.required = true;
            }
        }

        // Toggle Password visibility for passphrases
        function togglePassVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                btn.innerText = 'Hide';
            } else {
                input.type = 'password';
                btn.innerText = 'Show';
            }
        }

        function updateProfileInfo() {
            const val = document.getElementById('scan-profile').value;
            const desc = PROFILE_DESCRIPTIONS[val] || 'Discovery scan profile.';
            document.getElementById('profile-desc-title').innerText = val.toUpperCase() + ' Profile:';
            document.getElementById('profile-desc-text').innerText = ' ' + desc;
        }

        // Load Global Stats
        function loadStats() {
            fetch('?api=get_stats')
                .then(r => r.json())
                .then(res => {
                    if (res.ok) {
                        document.getElementById('stat-devices').innerText = res.devices;
                        document.getElementById('stat-sensors').innerText = res.sensors;
                        document.getElementById('stat-provisioned').innerText = res.provisioned;
                        document.getElementById('inventory-tab-count').innerText = res.sensors;
                    }
                })
                .catch(console.error);
        }

        // Load Devices Dropdown
        function loadDevicesList() {
            fetch('?api=get_devices')
                .then(r => r.json())
                .then(res => {
                    if (res.ok) {
                        const sel = document.getElementById('filter-device');
                        sel.innerHTML = '<option value="">-- All Devices --</option>';
                        res.devices.forEach(d => {
                            const opt = document.createElement('option');
                            opt.value = d.id;
                            const vLabel = d.snmp_version ? (d.snmp_version === '3' ? 'v3' : 'v' + d.snmp_version) : '';
                            opt.innerText = (d.hostname ? d.hostname + ' (' + d.ip_address + ')' : d.ip_address) + (vLabel ? ' [' + vLabel + ']' : '');
                            sel.appendChild(opt);
                        });
                    }
                });
        }

        // Load Agents Dropdown
        function loadAgentsList() {
            fetch('?api=get_agents')
                .then(r => r.json())
                .then(res => {
                    if (res.ok) {
                        const sel = document.getElementById('prov-agent-select');
                        sel.innerHTML = '<option value="">-- Choose Agent from Pandora FMS --</option>';
                        res.agents.forEach(a => {
                            const opt = document.createElement('option');
                            opt.value = a.id_agente;
                            opt.innerText = a.nombre + (a.direccion ? ' [' + a.direccion + ']' : '');
                            sel.appendChild(opt);
                        });
                    }
                });
        }

        // Execute Scan
        function executeScan(e) {
            e.preventDefault();
            const btn = document.getElementById('btn-start-scan');
            const term = document.getElementById('scan-terminal');
            const summaryCard = document.getElementById('scan-result-card');

            btn.disabled = true;
            btn.innerText = 'Scanning...';
            term.style.display = 'block';
            term.innerText = '[INFO] Initializing SNMP scan session...\n';
            summaryCard.classList.add('d-none');

            const isSubnet = document.querySelector('input[name="scan_mode"]:checked').value === 'subnet';
            const endpoint = isSubnet ? '?api=scan_subnet' : '?api=scan_device';
            const version = document.getElementById('scan-version').value;
            const isV3 = version === '3';

            const payload = {
                host: document.getElementById('scan-target').value,
                cidr: document.getElementById('scan-cidr').value,
                community: document.getElementById('scan-community').value,
                version: version,
                port: document.getElementById('scan-port').value,
                profile: document.getElementById('scan-profile').value,
                v3_sec_level: isV3 ? document.getElementById('scan-v3-sec-level').value : '',
                v3_user: isV3 ? document.getElementById('scan-v3-user').value : '',
                v3_auth_proto: isV3 ? document.getElementById('scan-v3-auth-proto').value : '',
                v3_auth_pass: isV3 ? document.getElementById('scan-v3-auth-pass').value : '',
                v3_priv_proto: isV3 ? document.getElementById('scan-v3-priv-proto').value : '',
                v3_priv_pass: isV3 ? document.getElementById('scan-v3-priv-pass').value : '',
                v3_context: isV3 ? document.getElementById('scan-v3-context').value : '',
            };

            term.innerText += `[INFO] Target: ${isSubnet ? payload.cidr : payload.host} | Port: ${payload.port} | Profile: ${payload.profile}\n`;
            if (isV3) {
                term.innerText += `[INFO] Protocol: SNMP v3 | Security Level: ${payload.v3_sec_level} | User: ${payload.v3_user}\n`;
            } else {
                term.innerText += `[INFO] Protocol: SNMP v${payload.version} | Community: ${payload.community}\n`;
            }
            term.innerText += `[INFO] Querying system OIDs & matching vendor profile...\n`;

            fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.innerText = 'Start SNMP Discovery';

                if (res.ok) {
                    term.innerText += `[SUCCESS] ${res.message || 'Scan completed!'}\n`;
                    loadStats();
                    loadDevicesList();

                    if (!isSubnet && res.data) {
                        renderScanSummary(res.data);
                    } else if (isSubnet && res.results) {
                        term.innerText += `\n[RESULTS SUMMARY]\nTotal Scanned: ${res.total_hosts} hosts\nTotal Discovered Sensors: ${res.discovered_sensors}\n`;
                        res.results.forEach(r => {
                            term.innerText += ` - ${r.ip}: ${r.status.toUpperCase()} (${r.sensors || 0} sensors)\n`;
                        });
                    }
                } else {
                    term.innerText += `[ERROR] ${res.error || 'Scan failed.'}\n`;
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerText = 'Start SNMP Discovery';
                term.innerText += `[NETWORK ERROR] ${err.message}\n`;
            });
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function renderScanSummary(data) {
            const card = document.getElementById('scan-result-card');
            const grid = document.getElementById('scan-summary-grid');
            card.classList.remove('d-none');

            grid.innerHTML = `
                <div style="background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="font-size:11px; color:#64748b;">Target Hostname</div>
                    <strong style="color:#0f172a; font-size:13.5px;">${escapeHtml(data.device.hostname || data.device.ip_address)}</strong>
                </div>
                <div style="background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="font-size:11px; color:#64748b;">Detected Vendor</div>
                    <span class="badge badge-info" style="margin-top:2px;">${escapeHtml(data.vendor || 'Generic')}</span>
                </div>
                <div style="background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="font-size:11px; color:#64748b;">Discovered Sensors</div>
                    <strong style="color:#004d40; font-size:15px;">${(data.sensors || []).length}</strong>
                </div>
                <div style="background:#f8fafc; padding:12px; border-radius:6px; border:1px solid #e2e8f0;">
                    <div style="font-size:11px; color:#64748b;">Duration</div>
                    <strong style="color:#334155; font-size:13px;">${(data.scan.duration_sec || 0).toFixed(2)}s</strong>
                </div>
            `;

            // Render discovered sensors preview table directly on this Scan Console tab
            const tbody = document.getElementById('scan-preview-tbody');
            const countEl = document.getElementById('scan-preview-count');
            const sensors = data.sensors || [];
            if (countEl) countEl.innerText = sensors.length;

            if (tbody) {
                if (sensors.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:24px; color:#94a3b8;">No sensors discovered for this device.</td></tr>';
                } else {
                    let rowsHtml = '';
                    sensors.forEach((s, idx) => {
                        const val = (s.normalized_value !== null && s.normalized_value !== undefined)
                            ? `${s.normalized_value} ${s.unit || ''}`
                            : (s.raw_value || 'N/A');
                        
                        let classBadge = 'badge-neutral';
                        const c = (s.sensor_class || '').toLowerCase();
                        if (c.includes('interface')) classBadge = 'badge-info';
                        else if (c.includes('optical') || c.includes('dom') || c.includes('gpon')) classBadge = 'badge-warning';
                        else if (c.includes('env') || c.includes('temp') || c.includes('cpu') || c.includes('sys')) classBadge = 'badge-success';

                        rowsHtml += `
                            <tr>
                                <td class="mono" style="color:#94a3b8; text-align:center;">${idx + 1}</td>
                                <td><strong style="color:#0f172a;">${escapeHtml(s.sensor_name || 'Unnamed')}</strong></td>
                                <td><span class="badge ${classBadge}">${escapeHtml(s.sensor_class || 'general')}</span></td>
                                <td class="mono" style="color:#004d40; font-weight:700;">${escapeHtml(val)}</td>
                                <td class="mono text-truncate-cell" title="${escapeHtml(s.oid || '')}">${escapeHtml(s.oid || '-')}</td>
                                <td><span class="badge badge-success">Discovered</span></td>
                            </tr>
                        `;
                    });
                    tbody.innerHTML = rowsHtml;
                }
            }
        }

        // Debounced Load Inventory
        function debounceLoadInventory() {
            clearTimeout(inventoryDebounceTimer);
            inventoryDebounceTimer = setTimeout(() => {
                loadInventory(1);
            }, 350);
        }

        // Load Inventory Data
        function loadInventory(page = 1) {
            currentInventoryPage = page;
            const tbody = document.getElementById('inventory-tbody');
            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:24px; color:#94a3b8;">Loading inventory...</td></tr>';

            const params = new URLSearchParams({
                api: 'get_inventory',
                page: page,
                per_page: 50,
                device_id: document.getElementById('filter-device').value,
                vendor: document.getElementById('filter-vendor').value,
                sensor_class: document.getElementById('filter-class').value,
                provisioned: document.getElementById('filter-provisioned').value,
                q: document.getElementById('filter-query').value,
            });

            fetch('?' + params.toString())
                .then(r => r.json())
                .then(res => {
                    if (res.ok && res.rows) {
                        renderInventoryTable(res);
                    } else {
                        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:20px; color:#ef4444;">${res.error || 'Failed to load.'}</td></tr>`;
                    }
                })
                .catch(err => {
                    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:20px; color:#ef4444;">Error: ${err.message}</td></tr>`;
                });
        }

        function renderInventoryTable(res) {
            const tbody = document.getElementById('inventory-tbody');
            if (res.rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:30px; color:#94a3b8;">No matching sensors found in inventory.</td></tr>';
                document.getElementById('pagination-info').innerText = 'Showing 0 of 0 entries';
                document.getElementById('pagination-controls').innerHTML = '';
                return;
            }

            let html = '';
            res.rows.forEach(r => {
                const isChecked = !!selectedSensorMap[r.id];
                const provBadge = (parseInt(r.provisioned) === 1)
                    ? `<span class="badge badge-success">Provisioned ${r.agent_name ? '(' + r.agent_name + ')' : ''}</span>`
                    : `<span class="badge badge-warning">Pending</span>`;

                const valDisplay = r.normalized_value !== null ? `${r.normalized_value} ${r.unit || ''}` : (r.raw_value || 'N/A');

                html += `
                    <tr>
                        <td style="text-align:center;">
                            <input type="checkbox" class="sensor-chk" value="${r.id}" ${isChecked ? 'checked' : ''} onchange="toggleSensorSelect(${r.id}, this.checked, ${JSON.stringify(r).replace(/"/g, '&quot;')})">
                        </td>
                        <td class="mono"><strong>${r.ip_address}</strong></td>
                        <td><span class="badge badge-info">${r.vendor}</span></td>
                        <td><span class="badge badge-neutral">${r.sensor_class}</span></td>
                        <td class="text-truncate-cell" title="${r.sensor_name}"><strong>${r.sensor_name}</strong></td>
                        <td class="mono" style="color:#004d40; font-weight:700;">${valDisplay}</td>
                        <td class="mono text-truncate-cell" title="${r.oid}">${r.oid}</td>
                        <td>${provBadge}</td>
                        <td style="text-align:right;">
                            <button class="btn-danger-custom" style="padding:2px 8px; height:26px; font-size:11px;" onclick="deleteItem('sensor', ${r.id})">
                                Delete
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;

            // Pagination Controls
            const start = (res.page - 1) * res.per_page + 1;
            const end = Math.min(res.total, res.page * res.per_page);
            document.getElementById('pagination-info').innerText = `Showing ${start} to ${end} of ${res.total} entries`;

            let pagesHtml = '';
            if (res.page > 1) {
                pagesHtml += `<button class="btn-secondary-custom" style="height:28px; padding:0 10px;" onclick="loadInventory(${res.page - 1})">Prev</button>`;
            }
            pagesHtml += `<span style="padding:4px 8px; font-weight:700;">Page ${res.page} / ${res.total_pages || 1}</span>`;
            if (res.page < res.total_pages) {
                pagesHtml += `<button class="btn-secondary-custom" style="height:28px; padding:0 10px;" onclick="loadInventory(${res.page + 1})">Next</button>`;
            }
            document.getElementById('pagination-controls').innerHTML = pagesHtml;
        }

        // Selection Management
        function toggleSensorSelect(id, isChecked, sensorObj) {
            if (isChecked) {
                selectedSensorMap[id] = sensorObj;
            } else {
                delete selectedSensorMap[id];
            }
            updateSelectedBadge();
        }

        function toggleSelectAll(masterChk) {
            const chks = document.querySelectorAll('.sensor-chk');
            chks.forEach(chk => {
                chk.checked = masterChk.checked;
                const id = chk.value;
                if (masterChk.checked) {
                    selectedSensorMap[id] = { id: id };
                } else {
                    delete selectedSensorMap[id];
                }
            });
            updateSelectedBadge();
        }

        function clearSelectedSensors() {
            selectedSensorMap = {};
            document.querySelectorAll('.sensor-chk').forEach(c => c.checked = false);
            const master = document.getElementById('check-all-sensors');
            if (master) master.checked = false;
            updateSelectedBadge();
            renderSelectedProvisionTable();
        }

        function updateSelectedBadge() {
            const count = Object.keys(selectedSensorMap).length;
            document.getElementById('inv-selected-count').innerText = count;
            document.getElementById('selected-provision-count').innerText = count;
            document.getElementById('prov-selected-count').innerText = count;
        }

        function proceedToProvisioning() {
            const count = Object.keys(selectedSensorMap).length;
            if (count === 0) {
                alert('Please select at least one sensor from the inventory table first.');
                return;
            }
            switchTab('tab-provision');
        }

        function renderSelectedProvisionTable() {
            const tbody = document.getElementById('prov-selected-tbody');
            const items = Object.values(selectedSensorMap);

            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:20px; color:#94a3b8;">No sensors currently selected. Go to Sensor Inventory to check items.</td></tr>';
                return;
            }

            let html = '';
            items.forEach(s => {
                html += `
                    <tr>
                        <td class="mono"><strong>${s.ip_address || ''}</strong></td>
                        <td><strong>${s.sensor_name || 'Sensor #' + s.id}</strong></td>
                        <td><span class="badge badge-neutral">${s.sensor_class || ''}</span></td>
                        <td class="mono text-truncate-cell">${s.oid || ''}</td>
                        <td class="mono" style="color:#004d40;">${s.normalized_value !== undefined ? s.normalized_value + ' ' + (s.unit || '') : ''}</td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        // Execute Provisioning
        function executeProvisioning() {
            const agentId = document.getElementById('prov-agent-select').value;
            const sensorIds = Object.keys(selectedSensorMap).map(Number);
            const btn = document.getElementById('btn-execute-provision');
            const resultsCard = document.getElementById('prov-results-card');
            const resultsContent = document.getElementById('prov-results-content');

            if (!agentId) {
                alert('Please select a target Pandora FMS Agent.');
                return;
            }
            if (sensorIds.length === 0) {
                alert('No sensors selected to deploy.');
                return;
            }

            btn.disabled = true;
            btn.innerText = 'Deploying...';
            resultsCard.classList.remove('d-none');
            resultsContent.innerHTML = '<div style="color:#64748b;">Deploying SNMP modules into Pandora FMS agent database...</div>';

            fetch('?api=provision', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({
                    agent_id: agentId,
                    sensor_ids: sensorIds,
                    interval: document.getElementById('prov-interval').value
                })
            })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.innerText = 'Deploy Modules to Agent';

                if (res.ok) {
                    const sum = res.summary;
                    resultsContent.innerHTML = `
                        <div style="display:flex; gap:16px; margin-bottom:14px;">
                            <span class="badge badge-success" style="font-size:13px; padding:6px 12px;">+ ${sum.created} Modules Created</span>
                            <span class="badge badge-info" style="font-size:13px; padding:6px 12px;">= ${sum.existing} Already Existing</span>
                            <span class="badge badge-neutral" style="font-size:13px; padding:6px 12px;">- ${sum.skipped} Skipped</span>
                        </div>
                        <p style="font-size:12.5px; color:#166534; font-weight:600;">${res.message}</p>
                    `;
                    loadStats();
                    loadInventory(currentInventoryPage);
                } else {
                    resultsContent.innerHTML = `<div style="color:#b91c1c; font-weight:700;">Error: ${res.error || 'Provisioning failed.'}</div>`;
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerText = 'Deploy Modules to Agent';
                resultsContent.innerHTML = `<div style="color:#b91c1c;">Network Error: ${err.message}</div>`;
            });
        }

        // Quick Create Agent Modal
        function openCreateAgentModal() {
            fetch('?api=get_agent_meta')
                .then(r => r.json())
                .then(res => {
                    if (res.ok) {
                        const grpSel = document.getElementById('new-agent-group');
                        grpSel.innerHTML = '';
                        res.groups.forEach(g => {
                            grpSel.innerHTML += `<option value="${g.id_grupo}">${g.nombre}</option>`;
                        });

                        const osSel = document.getElementById('new-agent-os');
                        osSel.innerHTML = '';
                        res.operating_systems.forEach(o => {
                            osSel.innerHTML += `<option value="${o.id_os}">${o.name}</option>`;
                        });

                        document.getElementById('modal-create-agent').classList.add('active');
                    }
                });
        }

        function closeCreateAgentModal() {
            document.getElementById('modal-create-agent').classList.remove('active');
        }

        function submitCreateAgent(e) {
            e.preventDefault();
            const payload = {
                agent_alias: document.getElementById('new-agent-alias').value,
                ip_address: document.getElementById('new-agent-ip').value,
                id_grupo: document.getElementById('new-agent-group').value,
                id_os: document.getElementById('new-agent-os').value,
                interval: document.getElementById('new-agent-interval').value
            };

            fetch('?api=create_agent', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    alert('Agent created successfully!');
                    closeCreateAgentModal();
                    loadAgentsList();
                    if (res.agent) {
                        setTimeout(() => {
                            document.getElementById('prov-agent-select').value = res.agent.id_agente;
                        }, 500);
                    }
                } else {
                    alert('Error: ' + res.error);
                }
            })
            .catch(err => alert('Network error: ' + err.message));
        }

        // Delete Item
        function deleteItem(type, id) {
            if (!confirm(`Are you sure you want to delete this ${type}?`)) return;

            fetch('?api=delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({ type: type, id: id })
            })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    loadStats();
                    loadInventory(currentInventoryPage);
                } else {
                    alert('Error: ' + res.error);
                }
            });
        }

        // Save Settings
        function saveSettings() {
            const payload = {
                snmp: {
                    community: document.getElementById('conf-community').value,
                    scan_timeout_sec: parseInt(document.getElementById('conf-timeout').value) || 45,
                    scan_max_sensors: parseInt(document.getElementById('conf-max-sensors').value) || 10000,
                    translate_oids: document.getElementById('conf-translate').value === '1',
                }
            };

            fetch('?api=save_settings', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    alert('Configuration saved successfully!');
                } else {
                    alert('Error: ' + res.error);
                }
            });
        }
    </script>
</body>
</html>
