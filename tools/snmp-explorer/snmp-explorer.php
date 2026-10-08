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
try {
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
    $oidTranslator = $engine['oidTranslator'] ?? null;
    $engineMibDirs = $engine['mibDirs'] ?? [__DIR__ . '/engine/mibs'];
} catch (\Throwable $e) {
    if (isset($_GET['api'])) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'SNMP Explorer Engine Init Error: ' . $e->getMessage()]);
        exit;
    }
    die('<div style="padding:24px; font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif; color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; margin:24px;">
        <h3 style="margin-top:0; font-size:16px; font-weight:700;">SNMP Explorer Initialization Error</h3>
        <p style="font-size:14px; margin-bottom:12px;">' . htmlspecialchars($e->getMessage()) . '</p>
        <pre style="background:#fff; border:1px solid #fee2e2; padding:12px; border-radius:6px; font-size:12px; overflow:auto; max-height:300px; color:#475569;">' . htmlspecialchars($e->getTraceAsString()) . '</pre>
    </div>');
}

// =====================================================================
// 4. AJAX API ENDPOINTS
// =====================================================================
$api = $_GET['api'] ?? '';

if (!empty($api)) {
    // Register fatal error shutdown handler to guarantee JSON error output instead of empty 500 response
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok' => false,
                'error' => sprintf('Server Fatal Error: %s in %s on line %d', $error['message'], basename($error['file']), $error['line']),
            ]);
        }
    });

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    // Helper: CSRF verification for mutating requests
    $verify_csrf = function() use ($csrf_token) {
        $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        if (empty($client_token)) {
            $rawInput = file_get_contents('php://input');
            if (!empty($rawInput)) {
                $decoded = @json_decode($rawInput, true);
                if (is_array($decoded) && !empty($decoded['csrf_token'])) {
                    $client_token = (string)$decoded['csrf_token'];
                }
            }
        }
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
            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/json; charset=utf-8');
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

            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/json; charset=utf-8');

            $json = json_encode([
                'ok' => true,
                'data' => $result,
                'message' => sprintf(
                    'Device %s scanned successfully! Discovered %d sensors in %.2fs.',
                    $host,
                    count($result['sensors'] ?? []),
                    $result['scan']['duration_sec'] ?? 0
                )
            ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            if ($json === false) {
                echo json_encode([
                    'ok' => false,
                    'error' => 'JSON encoding failed: ' . json_last_error_msg()
                ]);
            } else {
                echo $json;
            }
        } catch (\Throwable $e) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: application/json; charset=utf-8');
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
            $dataSql = "SELECT s.*, d.hostname, COALESCE(d.snmp_version, '2c') AS snmp_version, d.snmp_security_level, d.snmp_security_name, a.nombre as agent_name 
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
        $interval = (int)($input['interval'] ?? 300);

        if ($agentId <= 0 || empty($sensorIds)) {
            echo json_encode(['ok' => false, 'error' => 'Target Pandora Agent and at least one sensor must be selected.']);
            exit;
        }

        try {
            $summary = $provisioner->provision($sensorIds, $agentId, $interval);
            $pandoraRepo->repairModuleGroupsAndDescriptions();
            echo json_encode([
                'ok' => true,
                'summary' => $summary,
                'message' => sprintf(
                    'Provisioning completed: %d created, %d already existing / updated, %d skipped.',
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

    // API: Auto-repair HR-STORAGE and Memory Modules in Pandora FMS
    if ($api === 'repair_storage_modules' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;
        $agentId = isset($input['agent_id']) ? (int) $input['agent_id'] : 0;

        try {
            $rep = $pandoraRepo->repairHrStorageModules($agentId > 0 ? $agentId : null);
            echo json_encode([
                'ok' => true,
                'repaired' => $rep['repaired'],
                'details' => $rep['details'],
                'message' => sprintf(
                    'Successfully repaired %d memory/storage percentage module(s) in Pandora FMS! OIDs corrected to hrStorageUsed and post_process multipliers applied.',
                    $rep['repaired']
                ),
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Auto-repair Environmental and Scaled Modules in Pandora FMS
    if ($api === 'repair_environmental_modules' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;
        $agentId = isset($input['agent_id']) ? (int) $input['agent_id'] : 0;

        try {
            $rep = $pandoraRepo->repairEnvironmentalScaleModules($agentId > 0 ? $agentId : null);
            echo json_encode([
                'ok' => true,
                'repaired' => $rep['repaired'],
                'details' => $rep['details'],
                'message' => sprintf(
                    'Successfully repaired %d environmental module(s) in Pandora FMS! Applied post_process multipliers (0.1 for deci-Celsius, 0.001 for milliAmperes).',
                    $rep['repaired']
                ),
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Get Provisioned Sensors List in Pandora FMS
    if ($api === 'get_provisioned_sensors') {
        try {
            $pandoraRepo->repairModuleGroupsAndDescriptions();

            $page = max(1, (int)($_GET['page'] ?? 1));
            $per_page = max(10, min(500, (int)($_GET['per_page'] ?? 50)));
            $offset = ($page - 1) * $per_page;

            $filters = ["s.provisioned = 1"];
            $params = [];

            if (!empty($_GET['agent_id'])) {
                $filters[] = "s.pandora_agent_id = :agent_id";
                $params[':agent_id'] = (int)$_GET['agent_id'];
            }
            if (!empty($_GET['q'])) {
                $q = trim((string)$_GET['q']);
                $filters[] = "(s.sensor_name LIKE :q1 OR s.oid LIKE :q2 OR s.ip_address LIKE :q3 OR COALESCE(m.nombre, '') LIKE :q4 OR COALESCE(a.nombre, '') LIKE :q5)";
                $params[':q1'] = '%' . $q . '%';
                $params[':q2'] = '%' . $q . '%';
                $params[':q3'] = '%' . $q . '%';
                $params[':q4'] = '%' . $q . '%';
                $params[':q5'] = '%' . $q . '%';
            }

            $whereSql = 'WHERE ' . implode(' AND ', $filters);

            // Count total
            $countSql = "SELECT COUNT(*) 
                         FROM sensor_inventory s 
                         LEFT JOIN tagente a ON s.pandora_agent_id = a.id_agente 
                         LEFT JOIN tagente_modulo m ON (s.pandora_module_id = m.id_agente_modulo OR m.custom_id = CONCAT('snmpbridge:', s.id) OR m.custom_id = CONCAT('snmpbridge:sensor:', s.id))
                         $whereSql";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            // Fetch rows
            $dataSql = "SELECT 
                            s.*, 
                            d.hostname, 
                            COALESCE(d.snmp_version, '2c') AS snmp_version, 
                            COALESCE(a.nombre, CONCAT('Agent #', s.pandora_agent_id)) AS agent_name, 
                            a.alias AS agent_alias, 
                            COALESCE(m.nombre, s.sensor_name) AS module_name, 
                            COALESCE(m.id_agente_modulo, s.pandora_module_id) AS resolved_module_id,
                            m.descripcion AS module_description,
                            COALESCE(mg.name, 'General') AS module_group_name,
                            e.datos AS module_latest_data,
                            e.estado AS module_status
                        FROM sensor_inventory s 
                        LEFT JOIN devices d ON s.device_id = d.id 
                        LEFT JOIN tagente a ON s.pandora_agent_id = a.id_agente 
                        LEFT JOIN tagente_modulo m ON (s.pandora_module_id = m.id_agente_modulo OR m.custom_id = CONCAT('snmpbridge:', s.id) OR m.custom_id = CONCAT('snmpbridge:sensor:', s.id)) 
                        LEFT JOIN tmodule_group mg ON m.id_module_group = mg.id_mg
                        LEFT JOIN tagente_estado e ON (m.id_agente_modulo IS NOT NULL AND m.id_agente_modulo = e.id_agente_modulo)
                        $whereSql 
                        ORDER BY s.provisioned_at DESC, s.id DESC 
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

    // API: Unprovision / Delete Module from Pandora FMS
    if ($api === 'unprovision' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;

        $ids = array_values(array_filter(array_map('intval', (array)($input['sensor_ids'] ?? [$input['sensor_id'] ?? 0]))));
        $deleteSensor = !empty($input['delete_sensor']);

        if (empty($ids)) {
            echo json_encode(['ok' => false, 'error' => 'No sensors selected for unprovisioning.']);
            exit;
        }

        try {
            $inPlaceholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id, pandora_agent_id, pandora_module_id FROM sensor_inventory WHERE id IN ($inPlaceholders)");
            $stmt->execute($ids);
            $sensors = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $unprovisionedCount = 0;
            $agentModuleDecrement = [];

            foreach ($sensors as $sensor) {
                $sensorId = (int)$sensor['id'];
                $agentId = (int)($sensor['pandora_agent_id'] ?? 0);
                $moduleId = (int)($sensor['pandora_module_id'] ?? 0);

                if ($moduleId <= 0) {
                    $modFind = $pdo->prepare("SELECT id_agente_modulo, id_agente FROM tagente_modulo WHERE custom_id = ? LIMIT 1");
                    $modFind->execute(['snmpbridge:' . $sensorId]);
                    $modRow = $modFind->fetch(PDO::FETCH_ASSOC);
                    if ($modRow) {
                        $moduleId = (int)$modRow['id_agente_modulo'];
                        if ($agentId <= 0) {
                            $agentId = (int)$modRow['id_agente'];
                        }
                    }
                }

                if ($moduleId > 0) {
                    try { $pdo->prepare("DELETE FROM tagente_datos WHERE id_agente_modulo = ?")->execute([$moduleId]); } catch (\Throwable $t) {}
                    try { $pdo->prepare("DELETE FROM tagente_datos_inc WHERE id_agente_modulo = ?")->execute([$moduleId]); } catch (\Throwable $t) {}
                    try { $pdo->prepare("DELETE FROM tagente_datos_string WHERE id_agente_modulo = ?")->execute([$moduleId]); } catch (\Throwable $t) {}
                    try { $pdo->prepare("DELETE FROM tagente_estado WHERE id_agente_modulo = ?")->execute([$moduleId]); } catch (\Throwable $t) {}
                    try { $pdo->prepare("DELETE FROM tagente_modulo WHERE id_agente_modulo = ?")->execute([$moduleId]); } catch (\Throwable $t) {}

                    if ($agentId > 0) {
                        $agentModuleDecrement[$agentId] = ($agentModuleDecrement[$agentId] ?? 0) + 1;
                    }
                }

                if ($deleteSensor) {
                    $pdo->prepare("DELETE FROM sensor_inventory WHERE id = ?")->execute([$sensorId]);
                } else {
                    $pdo->prepare("UPDATE sensor_inventory SET provisioned = 0, pandora_agent_id = NULL, pandora_module_id = NULL, provisioned_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$sensorId]);
                }
                $unprovisionedCount++;
            }

            foreach ($agentModuleDecrement as $aId => $decCount) {
                try {
                    $pdo->prepare("UPDATE tagente SET total_modules = GREATEST(0, total_modules - ?) WHERE id_agente = ?")->execute([$decCount, $aId]);
                } catch (\Throwable $t) {}
            }

            echo json_encode([
                'ok' => true,
                'count' => $unprovisionedCount,
                'message' => sprintf('Successfully unprovisioned and deleted %d module(s) from Pandora FMS.', $unprovisionedCount),
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Synchronize Provisioned Modules status with Pandora FMS database
    if ($api === 'sync_provisioned_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        try {
            $syncedCount = 0;
            // 1. Detect modules in tagente_modulo that match custom_id 'snmpbridge:%'
            $stMods = $pdo->query("SELECT id_agente_modulo, id_agente, custom_id FROM tagente_modulo WHERE custom_id LIKE 'snmpbridge:%'");
            $activeModuleIds = [];
            while ($mRow = $stMods->fetch(PDO::FETCH_ASSOC)) {
                $cid = $mRow['custom_id'];
                $sId = (int)str_replace(['snmpbridge:sensor:', 'snmpbridge:'], '', $cid);
                $mId = (int)$mRow['id_agente_modulo'];
                $aId = (int)$mRow['id_agente'];
                $activeModuleIds[$mId] = true;

                if ($sId > 0) {
                    $upd = $pdo->prepare("UPDATE sensor_inventory SET provisioned = 1, pandora_agent_id = ?, pandora_module_id = ?, provisioned_at = COALESCE(provisioned_at, CURRENT_TIMESTAMP), updated_at = CURRENT_TIMESTAMP WHERE id = ? AND (provisioned = 0 OR pandora_module_id IS NULL OR pandora_module_id != ?)");
                    $upd->execute([$aId, $mId, $sId, $mId]);
                    if ($upd->rowCount() > 0) {
                        $syncedCount++;
                    }
                }
            }

            // 2. Detect sensor_inventory marked as provisioned whose module no longer exists in tagente_modulo
            $stSens = $pdo->query("SELECT id, pandora_module_id FROM sensor_inventory WHERE provisioned = 1 AND pandora_module_id IS NOT NULL");
            $staleCount = 0;
            while ($sRow = $stSens->fetch(PDO::FETCH_ASSOC)) {
                $sMid = (int)$sRow['pandora_module_id'];
                if (!isset($activeModuleIds[$sMid])) {
                    $chk = $pdo->prepare("SELECT COUNT(*) FROM tagente_modulo WHERE id_agente_modulo = ?");
                    $chk->execute([$sMid]);
                    if ((int)$chk->fetchColumn() === 0) {
                        $rst = $pdo->prepare("UPDATE sensor_inventory SET provisioned = 0, pandora_agent_id = NULL, pandora_module_id = NULL, provisioned_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $rst->execute([(int)$sRow['id']]);
                        $staleCount++;
                    }
                }
            }

            // 3. Repair module groups and descriptions for active modules
            $pandoraRepo->repairModuleGroupsAndDescriptions();

            echo json_encode([
                'ok' => true,
                'synced' => $syncedCount,
                'stale_cleared' => $staleCount,
                'message' => sprintf('Sync completed! %d modules updated, %d stale records reconciled.', $syncedCount, $staleCount),
            ]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Delete Device or Sensor (Single or Bulk)
    if ($api === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;

        $type = (string)($input['type'] ?? '');

        // 1. Bulk Delete Specific Sensors by ID
        if ($type === 'bulk_sensors') {
            $ids = array_values(array_filter(array_map('intval', (array)($input['ids'] ?? []))));
            if (empty($ids)) {
                echo json_encode(['ok' => false, 'error' => 'No sensors selected for deletion.']);
                exit;
            }

            try {
                $deletedCount = 0;
                $chunkSize = 500;
                foreach (array_chunk($ids, $chunkSize) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $st = $pdo->prepare("DELETE FROM sensor_inventory WHERE id IN ($placeholders)");
                    $st->execute($chunk);
                    $deletedCount += $st->rowCount();
                }
                echo json_encode([
                    'ok' => true,
                    'count' => $deletedCount,
                    'message' => "Successfully deleted {$deletedCount} sensor(s) from inventory.",
                ]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }

        // 2. Clear All or Filtered Sensors
        if ($type === 'clear_sensors') {
            $scope = (string)($input['scope'] ?? 'all');
            try {
                if ($scope === 'all') {
                    $deletedCount = (int)$pdo->exec("DELETE FROM sensor_inventory");
                    echo json_encode([
                        'ok' => true,
                        'count' => $deletedCount,
                        'message' => "Successfully cleared all {$deletedCount} sensors from inventory.",
                    ]);
                } else {
                    $filters = [];
                    $params = [];
                    if (!empty($input['device_id'])) {
                        $filters[] = "device_id = :device_id";
                        $params[':device_id'] = (int)$input['device_id'];
                    }
                    if (!empty($input['ip_address'])) {
                        $filters[] = "ip_address LIKE :ip_address";
                        $params[':ip_address'] = '%' . trim((string)$input['ip_address']) . '%';
                    }
                    if (!empty($input['vendor'])) {
                        $filters[] = "vendor = :vendor";
                        $params[':vendor'] = trim((string)$input['vendor']);
                    }
                    if (!empty($input['sensor_class'])) {
                        $filters[] = "sensor_class = :sensor_class";
                        $params[':sensor_class'] = trim((string)$input['sensor_class']);
                    }
                    if (isset($input['provisioned']) && $input['provisioned'] !== '') {
                        $filters[] = "provisioned = :provisioned";
                        $params[':provisioned'] = (int)$input['provisioned'];
                    }
                    if (!empty($input['q'])) {
                        $q = trim((string)$input['q']);
                        $filters[] = "(sensor_name LIKE :q1 OR oid LIKE :q2 OR interface_name LIKE :q3 OR ip_address LIKE :q4)";
                        $params[':q1'] = '%' . $q . '%';
                        $params[':q2'] = '%' . $q . '%';
                        $params[':q3'] = '%' . $q . '%';
                        $params[':q4'] = '%' . $q . '%';
                    }

                    if (empty($filters)) {
                        $deletedCount = (int)$pdo->exec("DELETE FROM sensor_inventory");
                    } else {
                        $whereSql = 'WHERE ' . implode(' AND ', $filters);
                        $st = $pdo->prepare("DELETE FROM sensor_inventory $whereSql");
                        $st->execute($params);
                        $deletedCount = (int)$st->rowCount();
                    }
                    echo json_encode([
                        'ok' => true,
                        'count' => $deletedCount,
                        'message' => "Successfully deleted {$deletedCount} filtered sensor(s) from inventory.",
                    ]);
                }
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            }
            exit;
        }

        // 3. Single Item Deletion (Device or Sensor)
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid ID specified.']);
            exit;
        }

        try {
            if ($type === 'device') {
                $stSens = $pdo->prepare("DELETE FROM sensor_inventory WHERE device_id = ?");
                $stSens->execute([$id]);
                $sensCount = (int)$stSens->rowCount();

                $st = $pdo->prepare("DELETE FROM devices WHERE id = ?");
                $st->execute([$id]);
                echo json_encode([
                    'ok' => true,
                    'message' => "Device #{$id} and {$sensCount} associated sensor(s) permanently removed.",
                ]);
            } elseif ($type === 'sensor') {
                $st = $pdo->prepare("DELETE FROM sensor_inventory WHERE id = ?");
                $st->execute([$id]);
                $count = (int)$st->rowCount();
                if ($count === 0) {
                    echo json_encode([
                        'ok' => false,
                        'error' => "Sensor ID #{$id} was not found in database (it may have already been deleted).",
                    ]);
                } else {
                    echo json_encode([
                        'ok' => true,
                        'count' => $count,
                        'message' => "Sensor #{$id} permanently deleted from inventory.",
                    ]);
                }
            } else {
                echo json_encode(['ok' => false, 'error' => 'Unknown delete target.']);
            }
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // API: Clean / Deduplicate Sensors in Inventory
    if ($api === 'deduplicate_sensors' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;
        $deviceId = (int)($input['device_id'] ?? 0);

        try {
            $whereDev = $deviceId > 0 ? "WHERE device_id = :dev_id" : "";
            $dupSql = "SELECT id, device_id, oid, provisioned FROM sensor_inventory $whereDev ORDER BY device_id, oid, provisioned DESC, id DESC";
            $st = $pdo->prepare($dupSql);
            if ($deviceId > 0) {
                $st->execute([':dev_id' => $deviceId]);
            } else {
                $st->execute();
            }
            $allRows = $st->fetchAll(PDO::FETCH_ASSOC);

            $seen = [];
            $toDeleteIds = [];
            foreach ($allRows as $row) {
                $key = $row['device_id'] . '_' . $row['oid'];
                if (isset($seen[$key])) {
                    if ((int)$row['provisioned'] === 0) {
                        $toDeleteIds[] = (int)$row['id'];
                    }
                } else {
                    $seen[$key] = true;
                }
            }

            $deletedCount = 0;
            if (!empty($toDeleteIds)) {
                $chunkSize = 500;
                foreach (array_chunk($toDeleteIds, $chunkSize) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $delStmt = $pdo->prepare("DELETE FROM sensor_inventory WHERE id IN ($placeholders)");
                    $delStmt->execute($chunk);
                    $deletedCount += (int)$delStmt->rowCount();
                }
            }

            echo json_encode([
                'ok' => true,
                'count' => $deletedCount,
                'message' => "Successfully cleaned {$deletedCount} duplicate unprovisioned sensor(s) from inventory.",
            ]);
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

    // API: List Installed MIBs
    if ($api === 'list_mibs') {
        $localMibDir = __DIR__ . '/engine/mibs';
        if (!is_dir($localMibDir)) {
            @mkdir($localMibDir, 0755, true);
        }

        $mibsList = [];
        
        $scanMibDir = function(string $baseDir, string $currentDir, string $sourceLabel, bool $canDelete) use (&$mibsList, &$scanMibDir) {
            if (!is_dir($currentDir)) return;
            $items = @scandir($currentDir) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..' || $item === '.gitkeep' || str_starts_with($item, '.')) {
                    continue;
                }
                $fullPath = $currentDir . '/' . $item;
                if (is_dir($fullPath)) {
                    $subLabel = $sourceLabel . '/' . $item;
                    $scanMibDir($baseDir, $fullPath, $subLabel, $canDelete);
                    continue;
                }
                if (!is_file($fullPath)) continue;

                $size = filesize($fullPath);
                $mtime = filemtime($fullPath);

                $normBase = rtrim(str_replace('\\', '/', $baseDir), '/');
                $normFull = str_replace('\\', '/', $fullPath);
                $relPath = ltrim(str_replace($normBase, '', $normFull), '/');

                // Quick parse module name from ASN.1 DEFINITIONS
                $moduleName = $item;
                $sample = @file_get_contents($fullPath, false, null, 0, 32768);
                if ($sample && preg_match('/^\s*([A-Za-z0-9_-]+)\s+DEFINITIONS\s*::=\s*BEGIN/mi', $sample, $m)) {
                    $moduleName = trim($m[1]);
                }

                $mibsList[] = [
                    'filename' => $relPath,
                    'module_name' => $moduleName,
                    'size_bytes' => $size,
                    'size_formatted' => $size > 1048576 ? round($size / 1048576, 2) . ' MB' : round($size / 1024, 1) . ' KB',
                    'modified_at' => date('Y-m-d H:i:s', $mtime),
                    'source' => $sourceLabel,
                    'can_delete' => $canDelete && ($item !== 'IF-MIB' && $item !== 'IF-MIB.mib')
                ];
            }
        };

        // Scan local toolkit MIBs (including any vendor subfolders)
        if (is_dir($localMibDir)) {
            $scanMibDir($localMibDir, $localMibDir, 'Toolkit (engine/mibs)', true);
        }

        // Also detect Pandora FMS attachment mibs folder
        $pandoraMibDir = realpath(__DIR__ . '/../../../../attachment/mibs') ?: realpath(__DIR__ . '/../../../../../attachment/mibs');
        if ($pandoraMibDir && is_dir($pandoraMibDir)) {
            $scanMibDir($pandoraMibDir, $pandoraMibDir, 'Pandora Console (attachment/mibs)', false);
        }

        echo json_encode([
            'ok' => true,
            'total' => count($mibsList),
            'local_dir' => $localMibDir,
            'mibs' => $mibsList
        ]);
        exit;
    }

    // API: Upload MIB File
    if ($api === 'upload_mib' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();

        $localMibDir = __DIR__ . '/engine/mibs';
        if (!is_dir($localMibDir)) {
            @mkdir($localMibDir, 0755, true);
        }

        if (empty($_FILES['mib_file']) || $_FILES['mib_file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['mib_file']['error'] ?? -1;
            echo json_encode(['ok' => false, 'error' => 'File upload failed with error code: ' . $errorCode]);
            exit;
        }

        $origName = $_FILES['mib_file']['name'];
        $cleanName = preg_replace('/[^A-Za-z0-9_.-]/', '', basename($origName));
        if (empty($cleanName)) {
            $cleanName = 'CUSTOM-MIB-' . time() . '.mib';
        }

        $tmpFile = $_FILES['mib_file']['tmp_name'];
        $sample = @file_get_contents($tmpFile, false, null, 0, 8192);

        // Basic check for text/ASCII and ASN.1
        if ($sample === false || strpos($sample, "\0") !== false) {
            echo json_encode(['ok' => false, 'error' => 'Uploaded file is not a valid text-based MIB definition file.']);
            exit;
        }

        $targetPath = $localMibDir . '/' . $cleanName;
        if (!move_uploaded_file($tmpFile, $targetPath)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to move uploaded file to engine/mibs/. Check directory permissions.']);
            exit;
        }

        // Parse module name
        $moduleName = $cleanName;
        $content = @file_get_contents($targetPath, false, null, 0, 32768);
        if ($content && preg_match('/^\s*([A-Za-z0-9_-]+)\s+DEFINITIONS\s*::=\s*BEGIN/mi', $content, $m)) {
            $moduleName = trim($m[1]);
        }

        echo json_encode([
            'ok' => true,
            'message' => "MIB module '{$moduleName}' ({$cleanName}) uploaded and registered successfully.",
            'filename' => $cleanName,
            'module_name' => $moduleName
        ]);
        exit;
    }

    // API: Import MIB via Text / URL / Preset
    if ($api === 'import_mib_text' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;

        $localMibDir = __DIR__ . '/engine/mibs';
        if (!is_dir($localMibDir)) {
            @mkdir($localMibDir, 0755, true);
        }

        $method = trim((string)($input['method'] ?? 'text'));
        $content = '';
        $mibName = trim((string)($input['mib_name'] ?? ''));

        if ($method === 'text') {
            $content = (string)($input['content'] ?? '');
            if (empty(trim($content))) {
                echo json_encode(['ok' => false, 'error' => 'MIB content cannot be empty.']);
                exit;
            }
        } elseif ($method === 'url') {
            $url = trim((string)($input['url'] ?? ''));
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                echo json_encode(['ok' => false, 'error' => 'Invalid source URL specified.']);
                exit;
            }
            $ctx = stream_context_create([
                'http' => ['timeout' => 10, 'follow_location' => 1, 'user_agent' => 'PFMS-Toolkit-MIB-Importer']
            ]);
            $fetched = @file_get_contents($url, false, $ctx);
            if ($fetched === false || empty($fetched)) {
                echo json_encode(['ok' => false, 'error' => 'Failed to download MIB from URL. Ensure the URL is accessible.']);
                exit;
            }
            $content = $fetched;
            if (empty($mibName)) {
                $urlPath = parse_url($url, PHP_URL_PATH);
                $mibName = basename((string)$urlPath);
            }
        } elseif ($method === 'preset') {
            $presetKey = trim((string)($input['preset'] ?? ''));
            $presets = [
                'HOST-RESOURCES-MIB' => 'https://raw.githubusercontent.com/librenms/librenms/master/mibs/HOST-RESOURCES-MIB',
                'ENTITY-MIB' => 'https://raw.githubusercontent.com/librenms/librenms/master/mibs/ENTITY-MIB',
                'CISCO-PROCESS-MIB' => 'https://raw.githubusercontent.com/librenms/librenms/master/mibs/cisco/CISCO-PROCESS-MIB',
                'MIKROTIK-MIB' => 'https://raw.githubusercontent.com/librenms/librenms/master/mibs/mikrotik/MIKROTIK-MIB',
                'HUAWEI-ENTITY-EXTENT-MIB' => 'https://raw.githubusercontent.com/librenms/librenms/master/mibs/huawei/HUAWEI-ENTITY-EXTENT-MIB',
                'FORTINET-CORE-MIB' => 'https://raw.githubusercontent.com/librenms/librenms/master/mibs/fortinet/FORTINET-CORE-MIB',
                'FORTINET-FORTIGATE-MIB' => 'https://raw.githubusercontent.com/librenms/librenms/master/mibs/fortinet/FORTINET-FORTIGATE-MIB'
            ];
            if (!isset($presets[$presetKey])) {
                echo json_encode(['ok' => false, 'error' => 'Unknown preset key.']);
                exit;
            }
            $mibName = $presetKey;
            $ctx = stream_context_create([
                'http' => ['timeout' => 10, 'follow_location' => 1, 'user_agent' => 'PFMS-Toolkit-MIB-Importer']
            ]);
            $fetched = @file_get_contents($presets[$presetKey], false, $ctx);
            if ($fetched === false || empty($fetched)) {
                echo json_encode(['ok' => false, 'error' => "Failed to download preset MIB '{$presetKey}'. Check internet connection."]);
                exit;
            }
            $content = $fetched;
        }

        // Try to parse module name from content if empty
        if (preg_match('/^\s*([A-Za-z0-9_-]+)\s+DEFINITIONS\s*::=\s*BEGIN/mi', $content, $m)) {
            $parsedName = trim($m[1]);
            if (empty($mibName) || $mibName === 'CUSTOM') {
                $mibName = $parsedName;
            }
        }

        $cleanName = preg_replace('/[^A-Za-z0-9_.-]/', '', $mibName);
        if (empty($cleanName)) {
            $cleanName = 'CUSTOM-MIB-' . time();
        }
        if (!str_ends_with(strtolower($cleanName), '.mib') && !str_ends_with(strtolower($cleanName), '.txt')) {
            $cleanName .= '.mib';
        }

        $targetPath = $localMibDir . '/' . $cleanName;
        $bytes = @file_put_contents($targetPath, $content);
        if ($bytes === false) {
            echo json_encode(['ok' => false, 'error' => 'Failed to save MIB content to engine/mibs/. Check directory permissions.']);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'message' => "MIB module '{$cleanName}' imported successfully (" . round($bytes / 1024, 1) . " KB).",
            'filename' => $cleanName
        ]);
        exit;
    }

    // API: Delete MIB File
    if ($api === 'delete_mib' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;

        $rawFilename = trim((string)($input['filename'] ?? ''));
        $rawFilename = str_replace('\\', '/', $rawFilename);

        // Disallow path traversal or absolute paths
        if (empty($rawFilename) || str_contains($rawFilename, '..') || str_starts_with($rawFilename, '/') || preg_match('/^[a-zA-Z]:/', $rawFilename)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid filename path.']);
            exit;
        }

        $baseName = basename($rawFilename);
        if ($baseName === 'IF-MIB' || $baseName === 'IF-MIB.mib') {
            echo json_encode(['ok' => false, 'error' => 'Protected System Base MIB (IF-MIB) cannot be deleted.']);
            exit;
        }

        $localMibDir = realpath(__DIR__ . '/engine/mibs');
        $targetPath = __DIR__ . '/engine/mibs/' . $rawFilename;
        $realTarget = realpath($targetPath);

        if (!$localMibDir || !$realTarget || !file_exists($realTarget) || !str_starts_with(str_replace('\\', '/', $realTarget), str_replace('\\', '/', $localMibDir))) {
            echo json_encode(['ok' => false, 'error' => 'MIB file not found in local toolkit directory. (Pandora console MIBs cannot be deleted from here)']);
            exit;
        }

        if (@unlink($realTarget)) {
            // Invalidate disk cache for candidate objects
            $cacheDir = __DIR__ . '/engine/storage/cache';
            if (is_dir($cacheDir)) {
                foreach (glob($cacheDir . '/mibs_manifest_*.json') ?: [] as $cf) {
                    @unlink($cf);
                }
            }
            echo json_encode(['ok' => true, 'message' => "MIB file '{$rawFilename}' deleted successfully."]);
        } else {
            echo json_encode(['ok' => false, 'error' => "Failed to delete '{$rawFilename}'. Check file permissions."]);
        }
        exit;
    }

    // API: View MIB Content
    if ($api === 'view_mib') {
        $rawFilename = trim((string)($_GET['filename'] ?? ''));
        $rawFilename = str_replace('\\', '/', $rawFilename);

        if (empty($rawFilename) || str_contains($rawFilename, '..') || str_starts_with($rawFilename, '/') || preg_match('/^[a-zA-Z]:/', $rawFilename)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid filename path.']);
            exit;
        }

        $localMibDir = realpath(__DIR__ . '/engine/mibs');
        $targetPath = __DIR__ . '/engine/mibs/' . $rawFilename;
        $realTarget = realpath($targetPath);

        if (!$realTarget || !file_exists($realTarget) || !$localMibDir || !str_starts_with(str_replace('\\', '/', $realTarget), str_replace('\\', '/', $localMibDir))) {
            $pandoraMibDir = realpath(__DIR__ . '/../../../../attachment/mibs') ?: realpath(__DIR__ . '/../../../../../attachment/mibs');
            if ($pandoraMibDir) {
                $pPath = $pandoraMibDir . '/' . $rawFilename;
                $pReal = realpath($pPath);
                if ($pReal && file_exists($pReal) && str_starts_with(str_replace('\\', '/', $pReal), str_replace('\\', '/', $pandoraMibDir))) {
                    $realTarget = $pReal;
                } else {
                    $realTarget = false;
                }
            } else {
                $realTarget = false;
            }
        }

        if (!$realTarget || !file_exists($realTarget)) {
            echo json_encode(['ok' => false, 'error' => 'File not found.']);
            exit;
        }

        $content = @file_get_contents($realTarget, false, null, 0, 524288); // Limit to 512KB for UI performance
        echo json_encode([
            'ok' => true,
            'filename' => $rawFilename,
            'size' => filesize($realTarget),
            'content' => $content !== false ? $content : 'Unable to read file content.'
        ]);
        exit;
    }

    // API: Interactive OID Translation Tester
    if ($api === 'translate_oid') {
        $rawOid = trim((string)($_GET['oid'] ?? $_POST['oid'] ?? ''));
        if (empty($rawOid)) {
            echo json_encode(['ok' => false, 'error' => 'OID parameter is required.']);
            exit;
        }

        if ($oidTranslator) {
            try {
                $result = $oidTranslator->translate($rawOid);
                echo json_encode(['ok' => true, 'data' => $result]);
            } catch (\Throwable $e) {
                echo json_encode(['ok' => false, 'error' => 'Translation error: ' . $e->getMessage()]);
            }
        } else {
            echo json_encode(['ok' => false, 'error' => 'OidTranslator engine is not initialized.']);
        }
        exit;
    }

    // API: Search OIDs across Catalog, Discovered Inventory, and Loaded MIBs
    if ($api === 'search_oids') {
        $q = trim((string)($_GET['q'] ?? ''));
        $source = trim((string)($_GET['source'] ?? 'all'));
        $limit = max(10, min(100, (int)($_GET['limit'] ?? 60)));

        $catalog = [
            // MIB-II / System
            ['oid' => '1.3.6.1.2.1.1.1.0', 'name' => 'sysDescr', 'module' => 'SNMPv2-MIB', 'class' => 'system', 'type' => 'string', 'syntax' => 'OCTET STRING', 'unit' => '', 'desc' => 'Textual description of the entity including hardware, OS, and version.'],
            ['oid' => '1.3.6.1.2.1.1.2.0', 'name' => 'sysObjectID', 'module' => 'SNMPv2-MIB', 'class' => 'system', 'type' => 'string', 'syntax' => 'OBJECT IDENTIFIER', 'unit' => '', 'desc' => 'Vendor authoritative enterprise OID identifying network subsystem.'],
            ['oid' => '1.3.6.1.2.1.1.3.0', 'name' => 'sysUpTime', 'module' => 'SNMPv2-MIB', 'class' => 'system', 'type' => 'numeric', 'syntax' => 'TimeTicks', 'unit' => 'ticks', 'desc' => 'Time since the system was last booted / re-initialized.'],
            ['oid' => '1.3.6.1.2.1.1.5.0', 'name' => 'sysName', 'module' => 'SNMPv2-MIB', 'class' => 'system', 'type' => 'string', 'syntax' => 'OCTET STRING', 'unit' => '', 'desc' => 'Administratively-assigned hostname for this node.'],
            ['oid' => '1.3.6.1.2.1.1.6.0', 'name' => 'sysLocation', 'module' => 'SNMPv2-MIB', 'class' => 'system', 'type' => 'string', 'syntax' => 'OCTET STRING', 'unit' => '', 'desc' => 'Physical location of the device (datacenter, rack, room).'],

            // IF-MIB (RFC 2863)
            ['oid' => '1.3.6.1.2.1.2.2.1.1', 'name' => 'ifIndex', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'numeric', 'syntax' => 'INTEGER', 'unit' => '', 'desc' => 'Unique integer index identifying the interface.'],
            ['oid' => '1.3.6.1.2.1.2.2.1.2', 'name' => 'ifDescr', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'string', 'syntax' => 'DisplayString', 'unit' => '', 'desc' => 'Textual name or description of the interface.'],
            ['oid' => '1.3.6.1.2.1.2.2.1.3', 'name' => 'ifType', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'numeric', 'syntax' => 'IANAifType', 'unit' => '', 'desc' => 'Interface hardware type (ethernetCsmacd, gigabitEthernet, loopback, etc).'],
            ['oid' => '1.3.6.1.2.1.2.2.1.5', 'name' => 'ifSpeed', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'numeric', 'syntax' => 'Gauge32', 'unit' => 'bps', 'desc' => 'Interface theoretical bandwidth in bits per second.'],
            ['oid' => '1.3.6.1.2.1.2.2.1.6', 'name' => 'ifPhysAddress', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'string', 'syntax' => 'PhysAddress', 'unit' => '', 'desc' => 'Physical MAC address of the interface.'],
            ['oid' => '1.3.6.1.2.1.2.2.1.7', 'name' => 'ifAdminStatus', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'status', 'syntax' => 'INTEGER { up(1), down(2), testing(3) }', 'unit' => '', 'desc' => 'Configured administrative state of interface.'],
            ['oid' => '1.3.6.1.2.1.2.2.1.8', 'name' => 'ifOperStatus', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'status', 'syntax' => 'INTEGER { up(1), down(2), testing(3), unknown(4), dormant(5), notPresent(6), lowerLayerDown(7) }', 'unit' => '', 'desc' => 'Live operational link state of interface.'],
            ['oid' => '1.3.6.1.2.1.2.2.1.10', 'name' => 'ifInOctets', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'incremental', 'syntax' => 'Counter32', 'unit' => 'bytes', 'desc' => 'Total inbound octets received on interface.'],
            ['oid' => '1.3.6.1.2.1.2.2.1.14', 'name' => 'ifInErrors', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'incremental', 'syntax' => 'Counter32', 'unit' => 'packets', 'desc' => 'Inbound packets dropped due to errors.'],
            ['oid' => '1.3.6.1.2.1.2.2.1.16', 'name' => 'ifOutOctets', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'incremental', 'syntax' => 'Counter32', 'unit' => 'bytes', 'desc' => 'Total outbound octets transmitted on interface.'],
            ['oid' => '1.3.6.1.2.1.2.2.1.20', 'name' => 'ifOutErrors', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'incremental', 'syntax' => 'Counter32', 'unit' => 'packets', 'desc' => 'Outbound packets dropped due to errors.'],
            ['oid' => '1.3.6.1.2.1.31.1.1.1.1', 'name' => 'ifName', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'string', 'syntax' => 'DisplayString', 'unit' => '', 'desc' => 'Short textual name of interface (e.g. Gi0/0/1, Port1).'],
            ['oid' => '1.3.6.1.2.1.31.1.1.1.6', 'name' => 'ifHCInOctets', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'incremental', 'syntax' => 'Counter64', 'unit' => 'bytes', 'desc' => '64-bit high capacity inbound octet counter (for >1Gbps interfaces).'],
            ['oid' => '1.3.6.1.2.1.31.1.1.1.10', 'name' => 'ifHCOutOctets', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'incremental', 'syntax' => 'Counter64', 'unit' => 'bytes', 'desc' => '64-bit high capacity outbound octet counter (for >1Gbps interfaces).'],
            ['oid' => '1.3.6.1.2.1.31.1.1.1.15', 'name' => 'ifHighSpeed', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'numeric', 'syntax' => 'Gauge32', 'unit' => 'Mbps', 'desc' => 'Interface speed in units of 1,000,000 bits per second (Mbps).'],
            ['oid' => '1.3.6.1.2.1.31.1.1.1.18', 'name' => 'ifAlias', 'module' => 'IF-MIB', 'class' => 'interface', 'type' => 'string', 'syntax' => 'DisplayString', 'unit' => '', 'desc' => 'Interface description or alias string set by administrator.'],

            // HOST-RESOURCES-MIB (RFC 2790)
            ['oid' => '1.3.6.1.2.1.25.1.1.0', 'name' => 'hrSystemUptime', 'module' => 'HOST-RESOURCES-MIB', 'class' => 'system', 'type' => 'numeric', 'syntax' => 'TimeTicks', 'unit' => 'ticks', 'desc' => 'Host uptime since system initialization.'],
            ['oid' => '1.3.6.1.2.1.25.2.2.0', 'name' => 'hrMemorySize', 'module' => 'HOST-RESOURCES-MIB', 'class' => 'memory', 'type' => 'numeric', 'syntax' => 'KBytes', 'unit' => 'KB', 'desc' => 'Amount of physical RAM in kilobytes.'],
            ['oid' => '1.3.6.1.2.1.25.2.3.1.2', 'name' => 'hrStorageType', 'module' => 'HOST-RESOURCES-MIB', 'class' => 'storage', 'type' => 'string', 'syntax' => 'AutonomousType', 'unit' => '', 'desc' => 'Type of storage (FixedDisk, RamDisk, VirtualMemory, etc).'],
            ['oid' => '1.3.6.1.2.1.25.2.3.1.3', 'name' => 'hrStorageDescr', 'module' => 'HOST-RESOURCES-MIB', 'class' => 'storage', 'type' => 'string', 'syntax' => 'DisplayString', 'unit' => '', 'desc' => 'Storage mount point or drive letter description.'],
            ['oid' => '1.3.6.1.2.1.25.2.3.1.5', 'name' => 'hrStorageSize', 'module' => 'HOST-RESOURCES-MIB', 'class' => 'storage', 'type' => 'numeric', 'syntax' => 'INTEGER', 'unit' => 'blocks', 'desc' => 'Total capacity size in allocation units.'],
            ['oid' => '1.3.6.1.2.1.25.2.3.1.6', 'name' => 'hrStorageUsed', 'module' => 'HOST-RESOURCES-MIB', 'class' => 'storage', 'type' => 'numeric', 'syntax' => 'INTEGER', 'unit' => 'blocks', 'desc' => 'Used capacity in allocation units.'],
            ['oid' => '1.3.6.1.2.1.25.3.3.1.2', 'name' => 'hrProcessorLoad', 'module' => 'HOST-RESOURCES-MIB', 'class' => 'processor', 'type' => 'percentage', 'syntax' => 'INTEGER (0..100)', 'unit' => '%', 'desc' => '1-minute average CPU core utilization percentage.'],

            // ENTITY-MIB & ENTITY-SENSOR-MIB (RFC 3433 / RFC 6933)
            ['oid' => '1.3.6.1.2.1.47.1.1.1.1.2', 'name' => 'entPhysicalDescr', 'module' => 'ENTITY-MIB', 'class' => 'inventory', 'type' => 'string', 'syntax' => 'SnmpAdminString', 'unit' => '', 'desc' => 'Description of physical hardware entity (Chassis, Module, Fan, Sensor).'],
            ['oid' => '1.3.6.1.2.1.47.1.1.1.1.7', 'name' => 'entPhysicalName', 'module' => 'ENTITY-MIB', 'class' => 'inventory', 'type' => 'string', 'syntax' => 'SnmpAdminString', 'unit' => '', 'desc' => 'Hardware entity name.'],
            ['oid' => '1.3.6.1.2.1.99.1.1.1.1', 'name' => 'entPhySensorType', 'module' => 'ENTITY-SENSOR-MIB', 'class' => 'sensor', 'type' => 'numeric', 'syntax' => 'EntitySensorDataType', 'unit' => '', 'desc' => 'Physical sensor type (celsius, volts, amperes, watts, rpm).'],
            ['oid' => '1.3.6.1.2.1.99.1.1.1.4', 'name' => 'entPhySensorValue', 'module' => 'ENTITY-SENSOR-MIB', 'class' => 'sensor', 'type' => 'numeric', 'syntax' => 'EntitySensorValue', 'unit' => '', 'desc' => 'Measured live reading from physical sensor.'],
            ['oid' => '1.3.6.1.2.1.99.1.1.1.5', 'name' => 'entPhySensorOperStatus', 'module' => 'ENTITY-SENSOR-MIB', 'class' => 'sensor', 'type' => 'status', 'syntax' => 'EntitySensorStatus', 'unit' => '', 'desc' => 'Sensor health state (ok, unavailable, nonoperational).'],

            // Cisco Systems
            ['oid' => '1.3.6.1.4.1.9.9.109.1.1.1.1.8', 'name' => 'cpmCPUTotal5minRev', 'module' => 'CISCO-PROCESS-MIB', 'class' => 'processor', 'type' => 'percentage', 'syntax' => 'Gauge32 (0..100)', 'unit' => '%', 'desc' => 'Cisco IOS 5-minute CPU busy load percentage.'],
            ['oid' => '1.3.6.1.4.1.9.9.109.1.1.1.1.7', 'name' => 'cpmCPUTotal1minRev', 'module' => 'CISCO-PROCESS-MIB', 'class' => 'processor', 'type' => 'percentage', 'syntax' => 'Gauge32 (0..100)', 'unit' => '%', 'desc' => 'Cisco IOS 1-minute CPU busy load percentage.'],
            ['oid' => '1.3.6.1.4.1.9.9.48.1.1.1.5', 'name' => 'ciscoMemoryPoolUsed', 'module' => 'CISCO-MEMORY-POOL-MIB', 'class' => 'memory', 'type' => 'numeric', 'syntax' => 'Gauge32', 'unit' => 'bytes', 'desc' => 'Cisco memory pool bytes currently allocated.'],
            ['oid' => '1.3.6.1.4.1.9.9.48.1.1.1.6', 'name' => 'ciscoMemoryPoolFree', 'module' => 'CISCO-MEMORY-POOL-MIB', 'class' => 'memory', 'type' => 'numeric', 'syntax' => 'Gauge32', 'unit' => 'bytes', 'desc' => 'Cisco memory pool bytes free.'],
            ['oid' => '1.3.6.1.4.1.9.9.13.1.3.1.3', 'name' => 'ciscoEnvMonTemperatureValue', 'module' => 'CISCO-ENVMON-MIB', 'class' => 'temperature', 'type' => 'temperature', 'syntax' => 'Gauge32', 'unit' => 'C', 'desc' => 'Cisco hardware temperature sensor measurement in Celsius.'],
            ['oid' => '1.3.6.1.4.1.9.9.13.1.4.1.3', 'name' => 'ciscoEnvMonFanStatus', 'module' => 'CISCO-ENVMON-MIB', 'class' => 'fan', 'type' => 'status', 'syntax' => 'CiscoEnvMonState', 'unit' => '', 'desc' => 'Cisco fan operational status (normal, warning, critical).'],
            ['oid' => '1.3.6.1.4.1.9.9.13.1.5.1.3', 'name' => 'ciscoEnvMonSupplyStatus', 'module' => 'CISCO-ENVMON-MIB', 'class' => 'voltage', 'type' => 'status', 'syntax' => 'CiscoEnvMonState', 'unit' => '', 'desc' => 'Cisco power supply operational status.'],

            // Huawei Technologies
            ['oid' => '1.3.6.1.4.1.2011.5.25.31.1.1.1.1.5', 'name' => 'hwEntityCpuUsage', 'module' => 'HUAWEI-ENTITY-EXTENT-MIB', 'class' => 'processor', 'type' => 'percentage', 'syntax' => 'Integer32 (0..100)', 'unit' => '%', 'desc' => 'Huawei board CPU utilization percentage.'],
            ['oid' => '1.3.6.1.4.1.2011.5.25.31.1.1.1.1.7', 'name' => 'hwEntityMemUsage', 'module' => 'HUAWEI-ENTITY-EXTENT-MIB', 'class' => 'memory', 'type' => 'percentage', 'syntax' => 'Integer32 (0..100)', 'unit' => '%', 'desc' => 'Huawei board memory utilization percentage.'],
            ['oid' => '1.3.6.1.4.1.2011.5.25.31.1.1.1.1.11', 'name' => 'hwEntityTemperature', 'module' => 'HUAWEI-ENTITY-EXTENT-MIB', 'class' => 'temperature', 'type' => 'temperature', 'syntax' => 'Integer32', 'unit' => 'C', 'desc' => 'Huawei hardware board temperature in degrees Celsius.'],
            ['oid' => '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.8', 'name' => 'hwEntityOpticalRxPower', 'module' => 'HUAWEI-ENTITY-EXTENT-MIB', 'class' => 'optical_dom', 'type' => 'optical_power', 'syntax' => 'Integer32', 'unit' => 'dBm', 'desc' => 'Huawei optical transceiver RX power.'],
            ['oid' => '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.9', 'name' => 'hwEntityOpticalTxPower', 'module' => 'HUAWEI-ENTITY-EXTENT-MIB', 'class' => 'optical_dom', 'type' => 'optical_power', 'syntax' => 'Integer32', 'unit' => 'dBm', 'desc' => 'Huawei optical transceiver TX power.'],
            ['oid' => '1.3.6.1.4.1.2011.6.3.4.1.4', 'name' => 'hwOpticalRxPower', 'module' => 'HUAWEI-DEVICE-EXT-MIB', 'class' => 'optical_dom', 'type' => 'optical_power', 'syntax' => 'Integer32', 'unit' => 'dBm', 'desc' => 'Huawei device optical transceiver RX power.'],
            ['oid' => '1.3.6.1.4.1.2011.6.3.4.1.5', 'name' => 'hwOpticalTxPower', 'module' => 'HUAWEI-DEVICE-EXT-MIB', 'class' => 'optical_dom', 'type' => 'optical_power', 'syntax' => 'Integer32', 'unit' => 'dBm', 'desc' => 'Huawei device optical transceiver TX power.'],

            // MikroTik RouterOS
            ['oid' => '1.3.6.1.4.1.14988.1.1.3.10.0', 'name' => 'mtxrHlCpuTemperature', 'module' => 'MIKROTIK-MIB', 'class' => 'temperature', 'type' => 'temperature', 'syntax' => 'Integer32', 'unit' => 'C', 'desc' => 'MikroTik RouterOS CPU temperature.'],
            ['oid' => '1.3.6.1.4.1.14988.1.1.3.11.0', 'name' => 'mtxrHlProcessorTemperature', 'module' => 'MIKROTIK-MIB', 'class' => 'temperature', 'type' => 'temperature', 'syntax' => 'Integer32', 'unit' => 'C', 'desc' => 'MikroTik main board processor temperature.'],
            ['oid' => '1.3.6.1.4.1.14988.1.1.3.8.0', 'name' => 'mtxrHlVoltage', 'module' => 'MIKROTIK-MIB', 'class' => 'voltage', 'type' => 'voltage', 'syntax' => 'Integer32', 'unit' => 'V', 'desc' => 'MikroTik power supply voltage (in dV or V).'],
            ['oid' => '1.3.6.1.4.1.14988.1.1.3.12.0', 'name' => 'mtxrHlPower', 'module' => 'MIKROTIK-MIB', 'class' => 'power', 'type' => 'numeric', 'syntax' => 'Integer32', 'unit' => 'W', 'desc' => 'MikroTik power consumption in Watts.'],
            ['oid' => '1.3.6.1.4.1.14988.1.1.3.14.0', 'name' => 'mtxrHlCurrent', 'module' => 'MIKROTIK-MIB', 'class' => 'current', 'type' => 'current', 'syntax' => 'Integer32', 'unit' => 'mA', 'desc' => 'MikroTik current consumption in mA.'],
            ['oid' => '1.3.6.1.4.1.14988.1.1.19.1.1.6', 'name' => 'mtxrOpticalRxPower', 'module' => 'MIKROTIK-MIB', 'class' => 'optical_dom', 'type' => 'optical_power', 'syntax' => 'Integer32', 'unit' => 'dBm', 'desc' => 'MikroTik SFP/SFP+ optical RX power.'],
            ['oid' => '1.3.6.1.4.1.14988.1.1.19.1.1.7', 'name' => 'mtxrOpticalTxPower', 'module' => 'MIKROTIK-MIB', 'class' => 'optical_dom', 'type' => 'optical_power', 'syntax' => 'Integer32', 'unit' => 'dBm', 'desc' => 'MikroTik SFP/SFP+ optical TX power.'],

            // Juniper Networks
            ['oid' => '1.3.6.1.4.1.2636.3.1.13.1.8', 'name' => 'jnxOperatingCPU', 'module' => 'JUNIPER-MIB', 'class' => 'processor', 'type' => 'percentage', 'syntax' => 'Integer32 (0..100)', 'unit' => '%', 'desc' => 'Juniper JunOS operating CPU load percentage.'],
            ['oid' => '1.3.6.1.4.1.2636.3.1.13.1.7', 'name' => 'jnxOperatingTemp', 'module' => 'JUNIPER-MIB', 'class' => 'temperature', 'type' => 'temperature', 'syntax' => 'Integer32', 'unit' => 'C', 'desc' => 'Juniper hardware component operating temperature in Celsius.'],
            ['oid' => '1.3.6.1.4.1.2636.3.1.13.1.11', 'name' => 'jnxOperatingBuffer', 'module' => 'JUNIPER-MIB', 'class' => 'memory', 'type' => 'percentage', 'syntax' => 'Integer32 (0..100)', 'unit' => '%', 'desc' => 'Juniper memory buffer utilization percentage.'],

            // Linux Net-SNMP
            ['oid' => '1.3.6.1.4.1.2021.11.9.0', 'name' => 'ssCpuUser', 'module' => 'UCD-SNMP-MIB', 'class' => 'processor', 'type' => 'percentage', 'syntax' => 'Integer32', 'unit' => '%', 'desc' => 'Linux CPU percentage spent in user space.'],
            ['oid' => '1.3.6.1.4.1.2021.11.10.0', 'name' => 'ssCpuSystem', 'module' => 'UCD-SNMP-MIB', 'class' => 'processor', 'type' => 'percentage', 'syntax' => 'Integer32', 'unit' => '%', 'desc' => 'Linux CPU percentage spent in kernel/system.'],
            ['oid' => '1.3.6.1.4.1.2021.11.11.0', 'name' => 'ssCpuIdle', 'module' => 'UCD-SNMP-MIB', 'class' => 'processor', 'type' => 'percentage', 'syntax' => 'Integer32', 'unit' => '%', 'desc' => 'Linux CPU percentage idle.'],
            ['oid' => '1.3.6.1.4.1.2021.4.5.0', 'name' => 'memTotalReal', 'module' => 'UCD-SNMP-MIB', 'class' => 'memory', 'type' => 'numeric', 'syntax' => 'Integer32', 'unit' => 'KB', 'desc' => 'Total real memory (RAM) in KB.'],
            ['oid' => '1.3.6.1.4.1.2021.4.6.0', 'name' => 'memAvailReal', 'module' => 'UCD-SNMP-MIB', 'class' => 'memory', 'type' => 'numeric', 'syntax' => 'Integer32', 'unit' => 'KB', 'desc' => 'Total free/available memory (RAM) in KB.'],
            ['oid' => '1.3.6.1.4.1.2021.10.1.3.1', 'name' => 'laLoad.1', 'module' => 'UCD-SNMP-MIB', 'class' => 'processor', 'type' => 'string', 'syntax' => 'DisplayString', 'unit' => '', 'desc' => '1-minute system load average on UNIX/Linux hosts.'],

            // BGP4-MIB
            ['oid' => '1.3.6.1.2.1.15.3.1.2', 'name' => 'bgpPeerState', 'module' => 'BGP4-MIB', 'class' => 'routing', 'type' => 'status', 'syntax' => 'INTEGER { idle(1), connect(2), active(3), opensent(4), openconfirm(5), established(6) }', 'unit' => '', 'desc' => 'BGP peer connection state. 6 = established.'],
            ['oid' => '1.3.6.1.2.1.15.3.1.3', 'name' => 'bgpPeerAdminStatus', 'module' => 'BGP4-MIB', 'class' => 'routing', 'type' => 'status', 'syntax' => 'INTEGER { stop(1), start(2) }', 'unit' => '', 'desc' => 'Administrative state of BGP peer.'],

            // UPS-MIB (RFC 1628)
            ['oid' => '1.3.6.1.2.1.33.1.2.1.0', 'name' => 'upsBatteryStatus', 'module' => 'UPS-MIB', 'class' => 'power', 'type' => 'status', 'syntax' => 'INTEGER { unknown(1), normal(2), low(3), depleted(4) }', 'unit' => '', 'desc' => 'UPS battery operational status.'],
            ['oid' => '1.3.6.1.2.1.33.1.2.3.0', 'name' => 'upsEstimatedMinutesRemaining', 'module' => 'UPS-MIB', 'class' => 'power', 'type' => 'numeric', 'syntax' => 'PositiveInteger', 'unit' => 'min', 'desc' => 'Estimated UPS runtime remaining on battery in minutes.'],
            ['oid' => '1.3.6.1.2.1.33.1.2.4.0', 'name' => 'upsEstimatedChargeRemaining', 'module' => 'UPS-MIB', 'class' => 'power', 'type' => 'percentage', 'syntax' => 'INTEGER (0..100)', 'unit' => '%', 'desc' => 'UPS battery charge percentage remaining.'],
            ['oid' => '1.3.6.1.2.1.33.1.2.7.0', 'name' => 'upsBatteryTemperature', 'module' => 'UPS-MIB', 'class' => 'temperature', 'type' => 'temperature', 'syntax' => 'INTEGER', 'unit' => 'C', 'desc' => 'UPS battery casing temperature.'],
        ];

        $results = [];
        $seenOids = [];

        // 1. Direct OID Translator test if query looks like an OID or starts with dot
        $trimmedQ = ltrim($q, '.');
        if (!empty($q) && ($oidTranslator !== null) && (str_starts_with($q, '.') || preg_match('/^\d+(\.\d+)+/', $trimmedQ) || preg_match('/^[A-Za-z0-9_-]+::/', $q))) {
            try {
                $trans = $oidTranslator->translate($q);
                if (!empty($trans['numeric_oid'])) {
                    $normOid = ltrim($trans['numeric_oid'], '.');
                    $results[] = [
                        'oid' => $normOid,
                        'name' => $trans['object'] ?? $trans['display_name'] ?? $q,
                        'module' => $trans['mib'] ?? 'Translated',
                        'class' => $trans['suggested_class'] ?? 'misc',
                        'type' => $trans['suggested_type'] ?? 'numeric',
                        'syntax' => $trans['syntax'] ?? 'Unknown',
                        'unit' => $trans['units'] ?? $trans['suggested_unit'] ?? '',
                        'desc' => $trans['description'] ?? 'Resolved dynamically via Net-SNMP MIB engine.',
                        'source' => 'Net-SNMP Translation',
                        'source_type' => 'translator',
                        'in_inventory' => false,
                        'device_count' => 0,
                    ];
                    $seenOids[$normOid] = true;
                }
            } catch (\Throwable $e) {}
        }

        // 2. Search Discovered Sensors Inventory
        if ($source === 'all' || $source === 'inventory') {
            try {
                $whereClauses = [];
                $params = [];
                if (!empty($q)) {
                    $whereClauses[] = "(s.oid LIKE :q1 OR s.sensor_name LIKE :q2 OR s.sensor_class LIKE :q3 OR s.vendor LIKE :q4)";
                    $likeQ = '%' . $q . '%';
                    $params[':q1'] = $likeQ;
                    $params[':q2'] = $likeQ;
                    $params[':q3'] = $likeQ;
                    $params[':q4'] = $likeQ;
                }
                $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
                $invSql = "SELECT s.oid, s.sensor_name, s.sensor_class, s.sensor_type, s.vendor, s.unit, s.raw_value,
                                  COUNT(DISTINCT s.device_id) as device_count,
                                  GROUP_CONCAT(DISTINCT s.ip_address SEPARATOR ', ') as sample_ips
                           FROM sensor_inventory s
                           $whereSql
                           GROUP BY s.oid, s.sensor_name, s.sensor_class, s.sensor_type, s.vendor, s.unit
                           ORDER BY device_count DESC, s.id DESC
                           LIMIT 40";
                $st = $pdo->prepare($invSql);
                $st->execute($params);
                $invRows = $st->fetchAll(PDO::FETCH_ASSOC);

                foreach ($invRows as $row) {
                    $cleanOid = ltrim($row['oid'], '.');
                    if (isset($seenOids[$cleanOid])) {
                        foreach ($results as &$r) {
                            if ($r['oid'] === $cleanOid) {
                                $r['in_inventory'] = true;
                                $r['device_count'] = (int)$row['device_count'];
                                $r['sample_devices'] = $row['sample_ips'];
                                $r['last_value'] = $row['raw_value'];
                                break;
                            }
                        }
                        unset($r);
                        continue;
                    }

                    $seenOids[$cleanOid] = true;
                    $resolvedName = $row['sensor_name'];
                    $resolvedMib = $row['vendor'] ?: 'Discovered';
                    if ($oidTranslator) {
                        try {
                            $t = $oidTranslator->translate($cleanOid);
                            if (!empty($t['object'])) $resolvedName = $t['object'];
                            if (!empty($t['mib'])) $resolvedMib = $t['mib'];
                        } catch (\Throwable $e) {}
                    }

                    $results[] = [
                        'oid' => $cleanOid,
                        'name' => $row['sensor_name'],
                        'symbolic_name' => $resolvedName,
                        'module' => $resolvedMib,
                        'class' => $row['sensor_class'] ?: 'general',
                        'type' => $row['sensor_type'] ?: 'numeric',
                        'syntax' => $row['raw_value'] ? 'Observed value: ' . $row['raw_value'] : 'Discovered',
                        'unit' => $row['unit'] ?? '',
                        'desc' => sprintf('Discovered on %d network device(s): %s (Vendor: %s)', (int)$row['device_count'], $row['sample_ips'] ?: 'Local', $row['vendor'] ?: 'Generic'),
                        'source' => 'Discovered Inventory',
                        'source_type' => 'inventory',
                        'in_inventory' => true,
                        'device_count' => (int)$row['device_count'],
                        'sample_devices' => $row['sample_ips'],
                        'last_value' => $row['raw_value'],
                    ];
                }
            } catch (\Throwable $e) {}
        }

        // 3. Search Standard Catalog
        if ($source === 'all' || $source === 'catalog') {
            foreach ($catalog as $cat) {
                $cleanOid = ltrim($cat['oid'], '.');
                $match = empty($q)
                    || stripos($cleanOid, $q) !== false
                    || stripos($cat['name'], $q) !== false
                    || stripos($cat['module'], $q) !== false
                    || stripos($cat['desc'], $q) !== false
                    || stripos($cat['class'], $q) !== false;

                if ($match) {
                    if (isset($seenOids[$cleanOid])) {
                        continue;
                    }
                    $seenOids[$cleanOid] = true;
                    $results[] = [
                        'oid' => $cleanOid,
                        'name' => $cat['name'],
                        'module' => $cat['module'],
                        'class' => $cat['class'],
                        'type' => $cat['type'],
                        'syntax' => $cat['syntax'],
                        'unit' => $cat['unit'],
                        'desc' => $cat['desc'],
                        'source' => 'Standard RFC / Vendor Catalog',
                        'source_type' => 'catalog',
                        'in_inventory' => false,
                        'device_count' => 0,
                    ];
                }
            }
        }

        // 4. Search Installed MIB Files
        if (($source === 'all' || $source === 'mibs') && !empty($q) && strlen($q) >= 2) {
            foreach ($mibDirs as $dir) {
                if (!is_dir($dir)) continue;
                $files = @scandir($dir) ?: [];
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..' || is_dir($dir . '/' . $file)) continue;
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['mib', 'my', 'txt', '']) && !str_contains($file, '-MIB') && !str_contains($file, 'MIB')) continue;

                    $filePath = $dir . '/' . $file;
                    if (@filesize($filePath) > 2 * 1024 * 1024) continue;
                    $content = @file_get_contents($filePath);
                    if (!$content || stripos($content, $q) === false) continue;

                    $modName = $file;
                    if (preg_match('/^\s*([A-Za-z0-9_-]+)\s+DEFINITIONS/m', $content, $m)) {
                        $modName = $m[1];
                    }

                    if (preg_match_all('/([A-Za-z0-9_-]+)\s+OBJECT-TYPE\s+SYNTAX\s+([^;]+?)\s+(?:MAX-ACCESS|ACCESS)\s+[^\n]+\s+STATUS\s+[^\n]+\s+DESCRIPTION\s+"([^"]*?)"\s*::=\s*\{\s*([A-Za-z0-9_-]+)\s+([0-9]+)\s*\}/is', $content, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $match) {
                            $objName = $match[1];
                            $syntax = trim(preg_replace('/\s+/', ' ', $match[2]));
                            $desc = trim(preg_replace('/\s+/', ' ', $match[3]));

                            if (stripos($objName, $q) !== false || stripos($desc, $q) !== false || stripos($syntax, $q) !== false) {
                                $calcOid = '';
                                if ($oidTranslator) {
                                    try {
                                        $t = $oidTranslator->translate($modName . '::' . $objName);
                                        if (!empty($t['numeric_oid'])) $calcOid = ltrim($t['numeric_oid'], '.');
                                    } catch (\Throwable $e) {}
                                }
                                $key = $calcOid ?: ($modName . '::' . $objName);
                                if (!isset($seenOids[$key])) {
                                    $seenOids[$key] = true;
                                    $results[] = [
                                        'oid' => $calcOid ?: ($match[4] . '.' . $match[5]),
                                        'name' => $objName,
                                        'module' => $modName,
                                        'class' => 'mib_object',
                                        'type' => 'numeric',
                                        'syntax' => $syntax,
                                        'unit' => '',
                                        'desc' => substr($desc, 0, 240),
                                        'source' => 'MIB File (' . $file . ')',
                                        'source_type' => 'mib_file',
                                        'in_inventory' => false,
                                        'device_count' => 0,
                                    ];
                                }
                                if (count($results) >= $limit) break 3;
                            }
                        }
                    }
                }
            }
        }

        $sliced = array_slice($results, 0, $limit);

        echo json_encode([
            'ok' => true,
            'query' => $q,
            'source' => $source,
            'total' => count($sliced),
            'results' => $sliced
        ]);
        exit;
    }

    // API: Live SNMP GET test on specific device IP
    if ($api === 'test_snmp_get' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $verify_csrf();
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?: $_POST;

        $host = trim((string)($input['host'] ?? ''));
        $oid = trim((string)($input['oid'] ?? ''));
        $version = trim((string)($input['version'] ?? '2c'));
        $port = (int)($input['port'] ?? 161);
        $community = trim((string)($input['community'] ?? 'public'));
        $v3_user = trim((string)($input['v3_user'] ?? ''));
        $v3_sec_level = trim((string)($input['v3_sec_level'] ?? 'authPriv'));
        $v3_auth_proto = trim((string)($input['v3_auth_proto'] ?? 'SHA'));
        $v3_auth_pass = (string)($input['v3_auth_pass'] ?? '');
        $v3_priv_proto = trim((string)($input['v3_priv_proto'] ?? 'AES'));
        $v3_priv_pass = (string)($input['v3_priv_pass'] ?? '');
        $v3_context = trim((string)($input['v3_context'] ?? ''));

        if (empty($host) || empty($oid)) {
            echo json_encode(['ok' => false, 'error' => 'Target IP/Host and SNMP OID are required.']);
            exit;
        }

        $t0 = microtime(true);
        try {
            $session = new \SnmpBridge\Core\Snmp\SnmpSession(
                host: $host,
                community: $community,
                version: $version,
                port: $port,
                timeoutUsec: 2000000,
                retries: 1,
                secLevel: $v3_sec_level,
                authProtocol: $v3_auth_proto,
                authPassphrase: $v3_auth_pass,
                privProtocol: $v3_priv_proto,
                privPassphrase: $v3_priv_pass,
                contextName: $v3_context
            );

            $val = $session->get($oid);
            $durationMs = round((microtime(true) - $t0) * 1000, 1);

            $trans = null;
            if ($oidTranslator) {
                try {
                    $trans = $oidTranslator->translate($oid);
                } catch (\Throwable $e) {}
            }

            echo json_encode([
                'ok' => true,
                'host' => $host,
                'oid' => $oid,
                'value' => $val !== null ? $val : '(Null / No response)',
                'duration_ms' => $durationMs,
                'translation' => $trans,
            ]);
        } catch (\Throwable $e) {
            $durationMs = round((microtime(true) - $t0) * 1000, 1);
            echo json_encode([
                'ok' => false,
                'host' => $host,
                'oid' => $oid,
                'error' => $e->getMessage(),
                'duration_ms' => $durationMs
            ]);
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
        .active-mode-btn {
            background: var(--brand-green) !important;
            color: #ffffff !important;
            border-color: var(--brand-green) !important;
        }
        .subtab-btn {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 7px 14px;
            font-size: 12.5px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
        }
        .subtab-btn:hover {
            border-color: var(--brand-green);
            color: var(--brand-green);
            background: #ffffff;
        }
        .subtab-btn.active {
            background: var(--brand-green) !important;
            color: #ffffff !important;
            border-color: var(--brand-green) !important;
            box-shadow: 0 2px 4px rgba(0,77,64,0.15);
        }
        .subtab-btn.active .badge-pill {
            background: rgba(255, 255, 255, 0.25) !important;
            color: #ffffff !important;
        }
        .oid-chip {
            background: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            border-radius: 14px;
            padding: 3px 10px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .oid-chip:hover {
            background: #e2e8f0;
            color: var(--primary-navy);
            border-color: #94a3b8;
        }
        .oid-chip.active {
            background: #e0f2fe;
            color: #0369a1;
            border-color: #7dd3fc;
        }
        .btn-icon-tiny {
            background: transparent;
            border: 1px solid transparent;
            border-radius: 4px;
            padding: 2px 4px;
            cursor: pointer;
            color: #64748b;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
            line-height: 1;
        }
        .btn-icon-tiny:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: var(--primary-navy);
        }
        .oid-copyable {
            cursor: pointer;
            padding: 2px 4px;
            border-radius: 4px;
            transition: background 0.15s;
        }
        .oid-copyable:hover {
            background: #e2e8f0;
            color: var(--brand-green);
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
            <button class="btn-secondary-custom" onclick="openOidDictionarySearch('')" style="display:inline-flex; align-items:center; gap:6px;">
                <span class="material-symbols-outlined" style="font-size:16px;">search</span>
                Search OID
            </button>
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
            <button class="tab-btn" data-tab="tab-mibs" onclick="switchTab('tab-mibs')">
                MIBs & OID Dictionary
                <span class="badge-pill" id="mibs-tab-count">0</span>
            </button>
            <button class="tab-btn" data-tab="tab-provision" onclick="switchTab('tab-provision')">
                Pandora Provisioning
                <span class="badge-pill" id="selected-provision-count" style="background:#004d40; color:#fff;" title="Active provisioned sensors">0</span>
                <span class="badge-pill d-none" id="staged-tab-badge" style="background:#d97706; color:#fff; font-size:10.5px; margin-left:4px;" title="Sensors queued for deployment">+0 queued</span>
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
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:10px;">
                        <div>
                            <h4 style="font-size:13.5px; font-weight:700; color:var(--primary-navy); margin:0;">
                                Discovered Modules & Sensors Preview (<span id="scan-preview-count">0</span>)
                            </h4>
                            <div style="font-size:11.5px; color:#64748b; margin-top:2px;">
                                Filter by OID or metric name, copy OIDs, or test live query.
                            </div>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                            <button class="btn-secondary-custom" onclick="openOidDictionarySearch('')" style="font-size:12px; height:32px; padding:0 12px; display:inline-flex; align-items:center; gap:5px;">
                                <span class="material-symbols-outlined" style="font-size:15px;">menu_book</span>
                                Search in OID Dictionary
                            </button>
                            <button class="btn-apply" onclick="switchTab('tab-inventory')" style="font-size:12px; height:32px; padding:0 14px;">
                                Manage & Provision in Inventory &rarr;
                            </button>
                        </div>
                    </div>

                    <!-- Instant Live Filter Bar -->
                    <div style="display:flex; gap:10px; align-items:center; margin-bottom:12px; flex-wrap:wrap;">
                        <div style="position:relative; flex:1; min-width:280px; max-width:500px;">
                            <input type="text" id="scan-preview-search" class="form-control" placeholder="Search preview by OID or sensor name (e.g. 1.3.6.1... or ifOperStatus)..." oninput="filterScanPreview()" style="padding-left:34px; height:34px; font-size:12px;">
                            <span class="material-symbols-outlined" style="position:absolute; left:9px; top:50%; transform:translateY(-50%); font-size:17px; color:#94a3b8; pointer-events:none;">search</span>
                            <button type="button" id="btn-clear-scan-preview-search" onclick="clearScanPreviewSearch()" style="position:absolute; right:8px; top:50%; transform:translateY(-50%); border:none; background:transparent; color:#94a3b8; cursor:pointer; display:none; padding:0;" title="Clear search">
                                <span class="material-symbols-outlined" style="font-size:16px;">close</span>
                            </button>
                        </div>
                        <span id="scan-preview-filter-badge" class="badge badge-info" style="display:none; font-size:11.5px; padding:5px 9px;"></span>
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
                                    <th style="width:80px; text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="scan-preview-tbody">
                                <tr><td colspan="7" style="text-align:center; padding:20px; color:#94a3b8;">No modules scanned yet.</td></tr>
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
                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                        <button class="btn-secondary-custom" onclick="clearSelectedSensors()">
                            Deselect All
                        </button>
                        <button class="btn-secondary-custom" onclick="repairStorageModules()" title="Auto-fix existing modules in Pandora FMS where hrStorage raw allocation blocks appear as huge percentage numbers">
                            <span class="material-symbols-outlined" style="font-size:16px; vertical-align:middle;">build</span> Auto-Fix (%) Modules
                        </button>
                        <button class="btn-secondary-custom" onclick="repairEnvironmentalModules()" title="Auto-fix sensor values in Pandora FMS suffering from scaling issues (e.g. 360 C -> 36.0 C with 0.1, 690 A -> 0.69 A with 0.001)">
                            <span class="material-symbols-outlined" style="font-size:16px; vertical-align:middle; color:#ea580c;">device_thermostat</span> Auto-Fix Scales
                        </button>
                        <button type="button" class="btn-secondary-custom" id="btn-clean-duplicates" onclick="cleanDuplicateSensors()" title="Find and delete duplicate sensor rows sharing identical OID, keeping the newest entry">
                            <span class="material-symbols-outlined" style="font-size:15px; vertical-align:middle; color:#0284c7;">cleaning_services</span> Clean Duplicates
                        </button>
                        <button class="btn-apply" onclick="proceedToProvisioning()">
                            Provision Selected (<span id="inv-selected-count">0</span>)
                        </button>
                        <button type="button" class="btn-danger-custom" id="btn-bulk-delete-selected" onclick="bulkDeleteSelectedSensors()" style="opacity:0.6; cursor:not-allowed;" title="Permanently delete all currently selected/checked sensors from inventory">
                            <span class="material-symbols-outlined" style="font-size:15px; vertical-align:middle;">delete</span>
                            Delete Selected (<span id="inv-delete-count">0</span>)
                        </button>
                        <button type="button" class="btn-secondary-custom" id="btn-clear-filtered" onclick="clearFilteredSensors()" style="color:#b91c1c; border-color:#fecaca;" title="Permanently delete all sensors matching current filters, or wipe entire inventory">
                            <span class="material-symbols-outlined" style="font-size:15px; vertical-align:middle; color:#ef4444;">delete_sweep</span>
                            Clear Filtered / All
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom:14px;">
                    <div class="form-group">
                        <label class="form-label">Filter Device / IP</label>
                        <select id="filter-device" class="form-control" onchange="updateFilterButtons(); loadInventory(1);">
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
        <!-- ============================================================= -->
        <!-- TAB 3: MIBS & OID DICTIONARY                                  -->
        <!-- ============================================================= -->
        <div id="tab-mibs" class="tab-content d-none">
            <!-- Subtab Navigation Bar -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="subtab-btn active" id="btn-subtab-search" onclick="switchMibSubTab('search')">
                        <span class="material-symbols-outlined" style="font-size:16px;">search</span>
                        OID Search & Dictionary
                    </button>
                    <button type="button" class="subtab-btn" id="btn-subtab-upload" onclick="switchMibSubTab('upload')">
                        <span class="material-symbols-outlined" style="font-size:16px;">upload_file</span>
                        MIB Uploader & Importer
                    </button>
                    <button type="button" class="subtab-btn" id="btn-subtab-installed" onclick="switchMibSubTab('installed')">
                        <span class="material-symbols-outlined" style="font-size:16px;">folder</span>
                        Installed MIB Registry (<span id="installed-mibs-count">0</span>)
                    </button>
                    <button type="button" class="subtab-btn" id="btn-subtab-tester" onclick="switchMibSubTab('tester')">
                        <span class="material-symbols-outlined" style="font-size:16px;">sync_alt</span>
                        Interactive Translator
                    </button>
                </div>
                <div style="font-size:11.5px; color:#64748b;">
                    Net-SNMP MIBs: <strong id="mibs-summary-badge" style="color:var(--brand-green);">Active</strong>
                </div>
            </div>

            <!-- SUBPANEL 1: OID Search & Dictionary -->
            <div id="subpanel-search" class="subpanel-content">
                <div class="dashboard-card">
                    <div class="card-header-clean">
                        <div>
                            <h3 style="margin:0;">SNMP OID Search & Dictionary Engine</h3>
                            <div style="font-size:11.5px; color:#64748b; margin-top:2px;">
                                Search any OID number or metric name across loaded MIBs, discovered network sensors, and standard RFC/Enterprise catalogs.
                            </div>
                        </div>
                        <div style="display:flex; gap:8px;">
                            <button type="button" class="btn-secondary-custom" onclick="searchOids()" style="font-size:12px; height:32px; padding:0 12px;">
                                Refresh Search
                            </button>
                        </div>
                    </div>

                    <!-- Search Input Box -->
                    <div style="margin-bottom:16px;">
                        <div style="position:relative; margin-bottom:12px;">
                            <input type="text" id="oid-search-input" class="form-control mono" placeholder="Search by OID (e.g. 1.3.6.1.2.1.2.2.1.8 or .1.3.6.1.4.1.2011) or Metric Keyword (e.g. ifOperStatus, cpu, temp, memory, storage, optical, cisco, mikrotik)..." oninput="debounceOidSearch()" style="height:44px; font-size:13px; padding-left:42px; border-radius:8px; border:1.5px solid #cbd5e1; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                            <span class="material-symbols-outlined" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); font-size:22px; color:var(--brand-green); pointer-events:none;">search</span>
                            <button type="button" id="btn-clear-oid-search" onclick="clearOidSearch()" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); border:none; background:transparent; color:#94a3b8; cursor:pointer; display:none; padding:4px;" title="Clear search">
                                <span class="material-symbols-outlined" style="font-size:18px;">close</span>
                            </button>
                        </div>

                        <!-- Source Filters & Quick Category Chips -->
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px;">
                            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <span style="font-size:11.5px; font-weight:700; color:#475569; margin-right:4px;">Search Sources:</span>
                                <label style="display:inline-flex; align-items:center; gap:4px; font-size:12px; cursor:pointer; font-weight:600; margin-right:6px;">
                                    <input type="radio" name="oid_search_source" value="all" checked onchange="searchOids()">
                                    All Sources
                                </label>
                                <label style="display:inline-flex; align-items:center; gap:4px; font-size:12px; cursor:pointer; font-weight:600; margin-right:6px;">
                                    <input type="radio" name="oid_search_source" value="inventory" onchange="searchOids()">
                                    Discovered Sensors
                                </label>
                                <label style="display:inline-flex; align-items:center; gap:4px; font-size:12px; cursor:pointer; font-weight:600; margin-right:6px;">
                                    <input type="radio" name="oid_search_source" value="catalog" onchange="searchOids()">
                                    Standard Catalog
                                </label>
                                <label style="display:inline-flex; align-items:center; gap:4px; font-size:12px; cursor:pointer; font-weight:600;">
                                    <input type="radio" name="oid_search_source" value="mibs" onchange="searchOids()">
                                    Loaded MIB Files
                                </label>
                            </div>

                            <div style="display:flex; gap:5px; align-items:center; flex-wrap:wrap;">
                                <span style="font-size:11px; color:#94a3b8; margin-right:2px;">Quick Chips:</span>
                                <span class="oid-chip" onclick="setOidSearchQuery('cpu')">CPU</span>
                                <span class="oid-chip" onclick="setOidSearchQuery('interface')">Interface</span>
                                <span class="oid-chip" onclick="setOidSearchQuery('temperature')">Temp</span>
                                <span class="oid-chip" onclick="setOidSearchQuery('memory')">Memory</span>
                                <span class="oid-chip" onclick="setOidSearchQuery('storage')">Disk</span>
                                <span class="oid-chip" onclick="setOidSearchQuery('optical')">Optical DOM</span>
                                <span class="oid-chip" onclick="setOidSearchQuery('cisco')">Cisco</span>
                                <span class="oid-chip" onclick="setOidSearchQuery('huawei')">Huawei</span>
                                <span class="oid-chip" onclick="setOidSearchQuery('mikrotik')">MikroTik</span>
                            </div>
                        </div>
                    </div>

                    <!-- Search Results Header -->
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <div style="font-size:12.5px; font-weight:700; color:var(--primary-navy);" id="oid-search-status">
                            Showing standard network OIDs catalog
                        </div>
                        <div style="font-size:11.5px; color:#64748b;">
                            Found <strong id="oid-search-count" style="color:var(--brand-green);">0</strong> matching entries
                        </div>
                    </div>

                    <!-- Search Results Table -->
                    <div class="table-responsive" style="max-height: 520px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 6px; background:#fff;">
                        <table class="custom-table" id="oid-search-table">
                            <thead>
                                <tr>
                                    <th style="width:36px; text-align:center;">#</th>
                                    <th>SNMP OID (Numeric)</th>
                                    <th>Symbolic Metric Name</th>
                                    <th>MIB Module</th>
                                    <th>Class & Type</th>
                                    <th>Syntax / Value</th>
                                    <th>Source / Discovered In</th>
                                    <th style="width:110px; text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="oid-search-tbody">
                                <tr><td colspan="8" style="text-align:center; padding:30px; color:#94a3b8;">Loading OID dictionary...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SUBPANEL 2: MIB Uploader & Importer -->
            <div id="subpanel-upload" class="subpanel-content d-none">
                <div style="display:grid; grid-template-columns: 1.1fr 1fr; gap:20px; margin-bottom:20px;">
                    <!-- Left: MIB Importer & Uploader Form -->
                    <div class="dashboard-card" style="margin-bottom:0;">
                        <div class="card-header-clean">
                            <h3>MIB Uploader & Importer</h3>
                            <div style="display:flex; gap:6px;">
                                <button type="button" class="btn-secondary-custom active-mode-btn" id="btn-mode-file" onclick="setMibImportMode('file')">Upload File</button>
                                <button type="button" class="btn-secondary-custom" id="btn-mode-text" onclick="setMibImportMode('text')">Paste Content</button>
                                <button type="button" class="btn-secondary-custom" id="btn-mode-preset" onclick="setMibImportMode('preset')">Quick Presets</button>
                            </div>
                        </div>

                        <!-- Method A: File Upload -->
                        <form id="form-upload-file" onsubmit="submitMibFileUpload(event)">
                            <div style="border: 2px dashed #cbd5e1; border-radius: 8px; padding: 24px 20px; text-align: center; background: #f8fafc; margin-bottom: 14px; transition: 0.2s;" id="drop-area-mib">
                                <span class="material-symbols-outlined" style="font-size: 38px; color: var(--brand-green); margin-bottom: 6px;">upload_file</span>
                                <div style="font-weight: 700; font-size: 13px; color: var(--primary-navy); margin-bottom: 4px;">Choose MIB file or drag & drop here</div>
                                <div style="font-size: 11px; color: #64748b; margin-bottom: 12px;">Supported file types: <code>.mib</code>, <code>.my</code>, <code>.txt</code> or ASN.1 definition</div>
                                <input type="file" id="mib-file-input" name="mib_file" accept=".mib,.my,.txt" style="display:none;" onchange="handleMibFileSelect(this)">
                                <button type="button" class="btn-secondary-custom" onclick="document.getElementById('mib-file-input').click()">Browse Files</button>
                                <div id="selected-file-label" style="font-size:12px; font-weight:600; color:var(--brand-green); margin-top:10px; display:none;"></div>
                            </div>
                            <button type="submit" class="btn-apply" id="btn-submit-upload" style="width:100%; justify-content:center;" disabled>
                                Upload & Register MIB
                            </button>
                        </form>

                        <!-- Method B: Paste Text -->
                        <form id="form-upload-text" class="d-none" onsubmit="submitMibText(event)">
                            <div class="form-group" style="margin-bottom: 10px;">
                                <label class="form-label">MIB Module Name (e.g. HUAWEI-ENTITY-EXTENT-MIB)</label>
                                <input type="text" id="mib-text-name" class="form-control mono" placeholder="e.g. HOST-RESOURCES-MIB" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 12px;">
                                <label class="form-label">ASN.1 MIB Content</label>
                                <textarea id="mib-text-content" class="form-control mono" rows="7" placeholder="-- Paste ASN.1 MIB definitions here (e.g. DEFINITIONS ::= BEGIN...)" style="font-size: 11px; resize: vertical;" required></textarea>
                            </div>
                            <button type="submit" class="btn-apply" style="width:100%; justify-content:center;">
                                Save & Compile MIB
                            </button>
                        </form>

                        <!-- Method C: Presets -->
                        <div id="form-upload-preset" class="d-none">
                            <div style="font-size: 12px; color: #64748b; margin-bottom: 12px;">
                                Click to download and register standard enterprise MIB presets into your toolkit directory:
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;">
                                    <div>
                                        <strong style="font-size: 12.5px; color: var(--primary-navy);">HOST-RESOURCES-MIB</strong>
                                        <div style="font-size: 11px; color: #64748b;">Standard Host & Server resource OIDs (Storage, CPU, RAM)</div>
                                    </div>
                                    <button type="button" class="btn-secondary-custom" onclick="installPresetMib('HOST-RESOURCES-MIB')">Install</button>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;">
                                    <div>
                                        <strong style="font-size: 12.5px; color: var(--primary-navy);">ENTITY-MIB</strong>
                                        <div style="font-size: 11px; color: #64748b;">Standard RFC Entity physical sensor & inventory OIDs</div>
                                    </div>
                                    <button type="button" class="btn-secondary-custom" onclick="installPresetMib('ENTITY-MIB')">Install</button>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;">
                                    <div>
                                        <strong style="font-size: 12.5px; color: var(--primary-navy);">CISCO-PROCESS-MIB</strong>
                                        <div style="font-size: 11px; color: #64748b;">Cisco IOS CPU utilization & processes</div>
                                    </div>
                                    <button type="button" class="btn-secondary-custom" onclick="installPresetMib('CISCO-PROCESS-MIB')">Install</button>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;">
                                    <div>
                                        <strong style="font-size: 12.5px; color: var(--primary-navy);">HUAWEI-ENTITY-EXTENT-MIB</strong>
                                        <div style="font-size: 11px; color: #64748b;">Huawei switches/routers temperature, optical power, CPU</div>
                                    </div>
                                    <button type="button" class="btn-secondary-custom" onclick="installPresetMib('HUAWEI-ENTITY-EXTENT-MIB')">Install</button>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;">
                                    <div>
                                        <strong style="font-size: 12.5px; color: var(--primary-navy);">MIKROTIK-MIB</strong>
                                        <div style="font-size: 11px; color: #64748b;">MikroTik RouterOS health, voltage, temperature, SFP</div>
                                    </div>
                                    <button type="button" class="btn-secondary-custom" onclick="installPresetMib('MIKROTIK-MIB')">Install</button>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;">
                                    <div>
                                        <strong style="font-size: 12.5px; color: var(--primary-navy);">FORTINET-CORE-MIB</strong>
                                        <div style="font-size: 11px; color: #64748b;">Fortinet Core SMI enterprise root (.1.3.6.1.4.1.12356)</div>
                                    </div>
                                    <button type="button" class="btn-secondary-custom" onclick="installPresetMib('FORTINET-CORE-MIB')">Install</button>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;">
                                    <div>
                                        <strong style="font-size: 12.5px; color: var(--primary-navy);">FORTINET-FORTIGATE-MIB</strong>
                                        <div style="font-size: 11px; color: #64748b;">FortiGate VPN tunnels, sessions, HA, cluster, sensors (.12356.101)</div>
                                    </div>
                                    <button type="button" class="btn-secondary-custom" onclick="installPresetMib('FORTINET-FORTIGATE-MIB')">Install</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Info / Guide Card -->
                    <div class="dashboard-card" style="margin-bottom:0;">
                        <div class="card-header-clean">
                            <h3>About MIBs & OID Translation</h3>
                        </div>
                        <div style="font-size:12.5px; color:#475569; line-height:1.6;">
                            <p style="margin-bottom:12px;">
                                <strong>Management Information Base (MIB)</strong> files define the structure of the management data on your networking devices.
                            </p>
                            <p style="margin-bottom:12px;">
                                By registering enterprise MIBs (e.g. Cisco, Huawei, Mikrotik), raw numeric OIDs (like <code>.1.3.6.1.4.1.2011...</code>) are automatically parsed into human-friendly symbolic names (like <code>hwEntityTemperature</code>).
                            </p>
                            <ul style="padding-left:18px; margin-bottom:12px; color:#64748b; font-size:12px;">
                                <li>MIBs uploaded here are saved to <code>engine/mibs/</code>.</li>
                                <li>The toolkit also checks Pandora's <code>attachment/mibs/</code> and system MIBs in <code>/usr/share/snmp/mibs</code>.</li>
                                <li>Any uploaded MIB can immediately be translated and searched.</li>
                            </ul>
                            <button type="button" class="btn-secondary-custom" onclick="switchMibSubTab('search')" style="width:100%; justify-content:center;">
                                Go to OID Search & Dictionary &rarr;
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SUBPANEL 3: Installed MIB Registry Table -->
            <div id="subpanel-installed" class="subpanel-content d-none">
                <div class="dashboard-card">
                    <div class="card-header-clean">
                        <div>
                            <h3>Installed MIB Modules & Registry</h3>
                            <div style="font-size:11.5px; color:#64748b; margin-top:2px;">
                                These MIB definitions are actively loaded by Net-SNMP and OidTranslator for sensor identification.
                            </div>
                        </div>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <input type="text" id="search-mibs" class="form-control" placeholder="Search MIB module name..." style="width:240px;" onkeyup="filterMibsTable()">
                            <button class="btn-secondary-custom" onclick="loadMibsList()">Refresh List</button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="custom-table" id="mibs-table">
                            <thead>
                                <tr>
                                    <th>Module Name</th>
                                    <th>File Name</th>
                                    <th>File Size</th>
                                    <th>Source Directory</th>
                                    <th>Last Modified</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="mibs-tbody">
                                <tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">Loading MIB registry...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SUBPANEL 4: Interactive OID Translator Tester -->
            <div id="subpanel-tester" class="subpanel-content d-none">
                <div class="dashboard-card">
                    <div class="card-header-clean">
                        <h3>Interactive OID Translator Tester</h3>
                    </div>
                    <div style="font-size:12px; color:#64748b; margin-bottom:12px;">
                        Verify whether installed MIBs can successfully translate specific SNMP OIDs to symbolic names:
                    </div>
                    <form onsubmit="testTranslateOid(event)">
                        <div class="form-group" style="margin-bottom: 12px;">
                            <label class="form-label">Numeric or Symbolic OID *</label>
                            <div style="display:flex; gap:8px;">
                                <input type="text" id="test-oid-input" class="form-control mono" placeholder="e.g. .1.3.6.1.2.1.1.1.0 or .1.3.6.1.2.1.2.2.1.10.1" value=".1.3.6.1.2.1.1.1.0" required>
                                <button type="submit" class="btn-apply" style="white-space:nowrap;">Translate</button>
                            </div>
                        </div>
                    </form>

                    <!-- Translation Result Box -->
                    <div id="test-translate-result" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; min-height:160px; font-size:12px;">
                        <div style="color:#94a3b8; text-align:center; padding:30px 0;">Enter an OID above and click "Translate" to test resolution.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: Live SNMP Test on Target Device -->
        <div class="modal-overlay" id="modal-test-snmp" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9000; align-items:center; justify-content:center; backdrop-filter:blur(2px);">
            <div class="modal-card" style="width:580px; max-width:95vw; background:#fff; border-radius:10px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); display:flex; flex-direction:column; overflow:hidden;">
                <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e2e8f0;">
                    <h3 style="margin:0; font-size:15px; font-weight:700; color:var(--primary-navy); display:flex; align-items:center; gap:8px;">
                        <span class="material-symbols-outlined" style="font-size:18px; color:var(--brand-green);">bolt</span>
                        <span>Live SNMP Query Test</span>
                    </h3>
                    <button type="button" class="btn-secondary-custom" style="padding:2px 8px; font-size:16px; line-height:1;" onclick="closeLiveTestModal()">&times;</button>
                </div>
                <form onsubmit="submitLiveSnmpTest(event)">
                    <div class="modal-body" style="padding:18px 20px; overflow-y:auto; max-height:75vh;">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label class="form-label">SNMP OID *</label>
                            <input type="text" id="test-snmp-oid" class="form-control mono" required>
                        </div>
                        <div class="form-grid" style="grid-template-columns: 2fr 1fr; margin-bottom:12px;">
                            <div class="form-group">
                                <label class="form-label">Target Device IP / Hostname *</label>
                                <input type="text" id="test-snmp-host" class="form-control" placeholder="e.g. 172.24.254.117" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">SNMP Port</label>
                                <input type="number" id="test-snmp-port" class="form-control" value="161" required>
                            </div>
                        </div>
                        <div class="form-grid" style="grid-template-columns: 1fr 2fr; margin-bottom:14px;">
                            <div class="form-group">
                                <label class="form-label">SNMP Version</label>
                                <select id="test-snmp-version" class="form-control" onchange="toggleTestSnmpVersion()">
                                    <option value="2c" selected>SNMP v2c</option>
                                    <option value="1">SNMP v1</option>
                                    <option value="3">SNMP v3</option>
                                </select>
                            </div>
                            <div class="form-group" id="test-snmp-community-group">
                                <label class="form-label">Community String</label>
                                <input type="text" id="test-snmp-community" class="form-control" value="public">
                            </div>
                        </div>

                        <!-- V3 Credentials sub-box -->
                        <div id="test-snmp-v3-box" class="d-none" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; margin-bottom:14px;">
                            <div class="form-grid" style="grid-template-columns: 1fr 1fr; gap:10px; margin-bottom:10px;">
                                <div class="form-group">
                                    <label class="form-label">Security Level</label>
                                    <select id="test-v3-sec-level" class="form-control">
                                        <option value="noAuthNoPriv">noAuthNoPriv</option>
                                        <option value="authNoPriv">authNoPriv</option>
                                        <option value="authPriv" selected>authPriv</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Username</label>
                                    <input type="text" id="test-v3-user" class="form-control" placeholder="pandora">
                                </div>
                            </div>
                            <div class="form-grid" style="grid-template-columns: 1fr 2fr; gap:10px;">
                                <div class="form-group">
                                    <label class="form-label">Auth Proto</label>
                                    <select id="test-v3-auth-proto" class="form-control">
                                        <option value="SHA" selected>SHA</option>
                                        <option value="MD5">MD5</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Auth Passphrase</label>
                                    <input type="password" id="test-v3-auth-pass" class="form-control">
                                </div>
                            </div>
                        </div>

                        <div id="test-snmp-result-box" style="display:none; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; margin-top:12px; font-size:12px;"></div>
                    </div>
                    <div style="padding:12px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
                        <button type="button" class="btn-secondary-custom" onclick="closeLiveTestModal()">Cancel</button>
                        <button type="submit" class="btn-apply" id="btn-run-live-test">
                            <span class="material-symbols-outlined" style="font-size:16px;">play_arrow</span>
                            Execute SNMP GET
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal: View MIB Content -->
        <div class="modal-overlay" id="modal-view-mib" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); z-index:9000; align-items:center; justify-content:center; backdrop-filter:blur(2px);">
            <div class="modal-card" style="width:850px; max-width:95vw; max-height:90vh; background:#fff; border-radius:10px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); display:flex; flex-direction:column; overflow:hidden;">
                <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e2e8f0;">
                    <h3 style="margin:0; font-size:15px; font-weight:700; color:var(--primary-navy); display:flex; align-items:center; gap:8px;">
                        <span>MIB Viewer:</span>
                        <code id="view-mib-title" style="color:var(--brand-green); font-size:13px;">-</code>
                    </h3>
                    <button type="button" class="btn-secondary-custom" style="padding:2px 8px; font-size:16px; line-height:1;" onclick="closeViewMibModal()">&times;</button>
                </div>
                <div class="modal-body" style="padding:16px 20px; overflow-y:auto; flex:1; background:#f8fafc;">
                    <pre id="view-mib-code" style="margin:0; font-family:'Courier New',Courier,monospace; font-size:11.5px; line-height:1.5; color:#334155; white-space:pre-wrap; word-break:break-all;"></pre>
                </div>
                <div style="padding:12px 20px; border-top:1px solid #e2e8f0; display:flex; justify-content:flex-end;">
                    <button type="button" class="btn-secondary-custom" onclick="closeViewMibModal()">Close</button>
                </div>
            </div>
        </div>

        <!-- ============================================================= -->
        <!-- TAB 4: PANDORA PROVISIONING                                   -->
        <!-- ============================================================= -->
        <div id="tab-provision" class="tab-content d-none">
            <!-- Provisioning Subtabs & Global Actions -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
                <div class="prov-subtabs-bar" style="display:inline-flex; background:#f1f5f9; padding:4px; border-radius:8px; gap:4px; border:1px solid #e2e8f0;">
                    <button type="button" id="subtab-prov-active-btn" class="subtab-btn active" onclick="switchProvSubTab('active')" style="display:inline-flex; align-items:center; gap:8px; padding:7px 18px; border-radius:6px; font-weight:700; font-size:13px; border:none; cursor:pointer;">
                        <span class="material-symbols-outlined" style="font-size:18px;">cloud_done</span>
                        Active Provisioned in Pandora
                        <span class="badge-pill" id="prov-subtab-active-count" style="font-size:11px; padding:2px 7px;">0</span>
                    </button>
                    <button type="button" id="subtab-prov-deploy-btn" class="subtab-btn" onclick="switchProvSubTab('deploy')" style="display:inline-flex; align-items:center; gap:8px; padding:7px 18px; border-radius:6px; font-weight:600; font-size:13px; border:none; cursor:pointer;">
                        <span class="material-symbols-outlined" style="font-size:18px;">rocket_launch</span>
                        Deploy Staging Queue
                        <span class="badge-pill" id="prov-subtab-deploy-count" style="font-size:11px; padding:2px 7px;">0</span>
                    </button>
                </div>

                <div style="display:flex; gap:8px; align-items:center;">
                    <button class="btn-secondary-custom" onclick="syncProvisionedStatus()" title="Synchronize modules with Pandora FMS database" style="display:inline-flex; align-items:center; gap:5px; height:32px; font-size:12px;">
                        <span class="material-symbols-outlined" style="font-size:16px;">sync</span>
                        Sync with Pandora
                    </button>
                    <button class="btn-secondary-custom" onclick="openCreateAgentModal()" style="display:inline-flex; align-items:center; gap:5px; height:32px; font-size:12px;">
                        <span class="material-symbols-outlined" style="font-size:16px;">add_circle</span>
                        Create New Agent
                    </button>
                </div>
            </div>

            <!-- SUBVIEW 1: ACTIVE PROVISIONED MODULES IN PANDORA FMS -->
            <div id="prov-view-active">
                <div class="dashboard-card">
                    <div class="card-header-clean">
                        <h3>
                            <span class="material-symbols-outlined" style="color:#004d40;">inventory</span>
                            Active Provisioned Modules in Pandora FMS
                            <span class="badge-pill" id="prov-active-header-badge" style="background:#004d40; color:#fff; font-size:11px;">0</span>
                        </h3>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <button type="button" class="btn-danger-custom" id="btn-bulk-unprovision" onclick="unprovisionSelectedBatch()" disabled style="display:inline-flex; align-items:center; gap:4px; height:30px; font-size:12px; opacity:0.5; cursor:not-allowed;">
                                <span class="material-symbols-outlined" style="font-size:15px;">link_off</span>
                                Unprovision Selected (<span id="prov-bulk-selected-count">0</span>)
                            </button>
                            <button class="btn-secondary-custom" onclick="loadProvisionedList(1)" style="display:inline-flex; align-items:center; gap:4px; height:30px; font-size:12px;">
                                <span class="material-symbols-outlined" style="font-size:16px;">refresh</span>
                                Refresh
                            </button>
                        </div>
                    </div>

                    <!-- Filter Bar -->
                    <div class="form-grid" style="grid-template-columns: 2fr 3fr; margin-bottom:14px;">
                        <div class="form-group">
                            <label class="form-label">Filter by Target Pandora Agent</label>
                            <select id="filter-prov-agent" class="form-control" onchange="loadProvisionedList(1)">
                                <option value="">-- All Pandora Agents --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Search Query</label>
                            <input type="text" id="filter-prov-query" class="form-control" placeholder="Search Module Name, Sensor Name, IP Address, OID..." onkeyup="debounceLoadProvisioned()">
                        </div>
                    </div>

                    <!-- Active Provisioned Table -->
                    <div class="table-responsive">
                        <table class="custom-table" id="prov-active-table">
                            <thead>
                                <tr>
                                    <th style="width:36px; text-align:center;">
                                        <input type="checkbox" id="check-all-prov" onchange="toggleSelectAllProv(this)">
                                    </th>
                                    <th>Target IP</th>
                                    <th>Pandora Agent</th>
                                    <th>Module Name</th>
                                    <th>Sensor Name & Class</th>
                                    <th>SNMP OID</th>
                                    <th>Value</th>
                                    <th>Provisioned At</th>
                                    <th style="text-align:right; width:130px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="prov-active-tbody">
                                <tr><td colspan="9" style="text-align:center; padding:30px; color:#94a3b8;">Loading provisioned modules...</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:14px; padding-top:10px; border-top:1px solid #f1f5f9;">
                        <div id="prov-pagination-info" style="font-size:12px; color:#64748b;">Showing 0 of 0 entries</div>
                        <div id="prov-pagination-controls" style="display:flex; gap:6px; align-items:center;"></div>
                    </div>
                </div>
            </div>

            <!-- SUBVIEW 2: DEPLOY STAGING QUEUE -->
            <div id="prov-view-deploy" class="d-none">
                <div class="dashboard-card">
                    <div class="card-header-clean">
                        <h3>
                            <span class="material-symbols-outlined" style="color:#004d40;">rocket_launch</span>
                            Deploy Selected Sensors to Agent
                        </h3>
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
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <h4 style="font-size:13.5px; font-weight:700; color:var(--primary-navy); margin:0;">
                                Selected Sensors for Provisioning (<span id="prov-selected-count">0</span>)
                            </h4>
                            <button type="button" class="btn-secondary-custom" onclick="clearSelectedSensors()" style="font-size:11.5px; padding:3px 10px; display:inline-flex; align-items:center; gap:4px; height:26px;">
                                <span class="material-symbols-outlined" style="font-size:15px; color:#ef4444;">delete_sweep</span>
                                Clear All
                            </button>
                        </div>
                        <div class="table-responsive" style="max-height:350px;">
                            <table class="custom-table" id="prov-selected-table">
                                <thead>
                                    <tr>
                                        <th>IP Address</th>
                                        <th>Sensor Name</th>
                                        <th>Class</th>
                                        <th>OID</th>
                                        <th>Value</th>
                                        <th style="text-align:center; width:90px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="prov-selected-tbody">
                                    <tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">No sensors currently selected. Go to Sensor Inventory to check items.</td></tr>
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

                <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:14px 18px; margin: 18px 0; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <strong style="color:#166534; font-size:13.5px; display:block; margin-bottom:2px;">Enterprise MIB Files & Registry</strong>
                        <div style="font-size:12px; color:#15803d;">Upload custom enterprise MIB definitions (Cisco, Huawei, MikroTik, ZTE, etc.) to translate vendor-specific OIDs into readable metrics.</div>
                    </div>
                    <button type="button" class="btn-primary" style="white-space:nowrap;" onclick="switchTab('tab-mibs')">
                        Open MIB Uploader
                    </button>
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

        // Pandora Provisioning State
        let currentProvSubTab = 'active';
        let currentProvPage = 1;
        let selectedProvMap = {};
        let provSearchTimeout = null;

        document.addEventListener('DOMContentLoaded', () => {
            loadStats();
            loadDevicesList();
            loadAgentsList();
            loadInventory(1);
            loadMibsList();
            loadProvisionedList(1);
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
                if (currentProvSubTab === 'deploy') {
                    renderSelectedProvisionTable();
                } else {
                    loadProvisionedList(currentProvPage || 1);
                }
            } else if (tabId === 'tab-mibs') {
                loadMibsList();
                if (!window.hasLoadedOidSearch) {
                    searchOids();
                }
            }
        }

        function reloadCurrentTab() {
            loadStats();
            loadDevicesList();
            loadAgentsList();
            loadInventory(currentInventoryPage);
            loadMibsList();
            searchOids();
            loadProvisionedList(currentProvPage || 1);
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
            fetch(`?api=get_stats&_t=${Date.now()}`, { cache: 'no-store' })
                .then(r => r.json())
                .then(res => {
                    if (res.ok) {
                        document.getElementById('stat-devices').innerText = res.devices;
                        document.getElementById('stat-sensors').innerText = res.sensors;
                        document.getElementById('stat-provisioned').innerText = res.provisioned;
                        document.getElementById('inventory-tab-count').innerText = res.sensors;

                        // Synchronize provisioned counts across tab headers and badges
                        const selProv = document.getElementById('selected-provision-count');
                        if (selProv) selProv.innerText = res.provisioned;
                        const subtabActive = document.getElementById('prov-subtab-active-count');
                        if (subtabActive) subtabActive.innerText = res.provisioned;
                        const activeHeader = document.getElementById('prov-active-header-badge');
                        if (activeHeader) activeHeader.innerText = res.provisioned;
                    }
                })
                .catch(console.error);
        }

        // Load Devices Dropdown
        function loadDevicesList() {
            fetch(`?api=get_devices&_t=${Date.now()}`, { cache: 'no-store' })
                .then(r => r.json())
                .then(res => {
                    if (res.ok) {
                        const sel = document.getElementById('filter-device');
                        const currentVal = sel ? sel.value : '';
                        sel.innerHTML = '<option value="">-- All Devices --</option>';
                        res.devices.forEach(d => {
                            const opt = document.createElement('option');
                            opt.value = d.id;
                            const vLabel = d.snmp_version ? (d.snmp_version === '3' ? 'v3' : 'v' + d.snmp_version) : '';
                            opt.innerText = (d.hostname ? d.hostname + ' (' + d.ip_address + ')' : d.ip_address) + (vLabel ? ' [' + vLabel + ']' : '');
                            sel.appendChild(opt);
                        });
                        if (currentVal && Array.from(sel.options).some(o => o.value == currentVal)) {
                            sel.value = currentVal;
                        }
                        updateFilterButtons();
                    }
                });
        }

        function updateFilterButtons() {
            const deviceEl = document.getElementById('filter-device');
            const clearBtn = document.getElementById('btn-clear-filtered');
            if (!clearBtn) return;
            if (deviceEl && deviceEl.value !== '') {
                const optText = (deviceEl.selectedIndex >= 0 && deviceEl.options[deviceEl.selectedIndex]) ? deviceEl.options[deviceEl.selectedIndex].text : '';
                clearBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:15px; vertical-align:middle; color:#ef4444;">delete_sweep</span> Clear Device Sensors';
                clearBtn.title = `Permanently delete all discovered sensors belonging to ${optText}`;
            } else {
                clearBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size:15px; vertical-align:middle; color:#ef4444;">delete_sweep</span> Clear Filtered / All';
                clearBtn.title = 'Permanently delete all sensors matching current filters, or wipe entire inventory';
            }
        }

        // Load Agents Dropdown
        function loadAgentsList() {
            fetch(`?api=get_agents&_t=${Date.now()}`, { cache: 'no-store' })
                .then(r => r.json())
                .then(res => {
                    if (res.ok) {
                        const sel = document.getElementById('prov-agent-select');
                        if (sel) {
                            sel.innerHTML = '<option value="">-- Choose Agent from Pandora FMS --</option>';
                            res.agents.forEach(a => {
                                const opt = document.createElement('option');
                                opt.value = a.id_agente;
                                opt.innerText = a.nombre + (a.direccion ? ' [' + a.direccion + ']' : '');
                                sel.appendChild(opt);
                            });
                        }

                        // Also populate filter on Active Provisioned tab
                        const filterAgent = document.getElementById('filter-prov-agent');
                        if (filterAgent) {
                            const curVal = filterAgent.value;
                            filterAgent.innerHTML = '<option value="">-- All Pandora Agents --</option>';
                            res.agents.forEach(a => {
                                const opt = document.createElement('option');
                                opt.value = a.id_agente;
                                opt.innerText = a.nombre + (a.direccion ? ' [' + a.direccion + ']' : '');
                                filterAgent.appendChild(opt);
                            });
                            if (curVal && Array.from(filterAgent.options).some(o => o.value == curVal)) {
                                filterAgent.value = curVal;
                            }
                        }
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
            .then(async r => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error(`Server returned invalid response (${r.status}): ${text.substring(0, 300) || '(empty response)'}`);
                }
            })
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

            // Store scanned sensors and host for instant preview filtering and live OID queries
            window.lastScannedSensors = data.sensors || [];
            window.lastScannedHost = (data.device ? (data.device.ip_address || data.device.hostname) : '') 
                || (document.getElementById('target-ip') ? document.getElementById('target-ip').value.trim() : '');

            // Reset preview filter input if any
            const prevSearchInput = document.getElementById('scan-preview-search');
            if (prevSearchInput) prevSearchInput.value = '';
            const prevBadge = document.getElementById('scan-preview-filter-badge');
            if (prevBadge) prevBadge.style.display = 'none';
            const prevClear = document.getElementById('btn-clear-scan-preview-search');
            if (prevClear) prevClear.style.display = 'none';

            renderScanPreviewRows(window.lastScannedSensors);
        }

        // Render preview rows with copy, inspect, and test actions
        function renderScanPreviewRows(sensors, isFiltered = false) {
            const tbody = document.getElementById('scan-preview-tbody');
            const countEl = document.getElementById('scan-preview-count');
            if (countEl && !isFiltered) countEl.innerText = (sensors || []).length;
            if (!tbody) return;

            if (!sensors || sensors.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:24px; color:#94a3b8;">${isFiltered ? 'No discovered sensors match your search filter.' : 'No sensors discovered for this device.'}</td></tr>`;
                return;
            }

            let rowsHtml = '';
            sensors.forEach((s, idx) => {
                const val = (s.normalized_value !== null && s.normalized_value !== undefined)
                    ? `${s.normalized_value} ${s.unit || ''}`
                    : (s.raw_value || 'N/A');
                
                let classBadge = 'badge-neutral';
                const c = (s.sensor_class || '').toLowerCase();
                if (c.includes('interface')) classBadge = 'badge-info';
                else if (c.includes('optical') || c.includes('dom') || c.includes('gpon')) classBadge = 'badge-warning';
                else if (c.includes('env') || c.includes('temp') || c.includes('cpu') || c.includes('sys') || c.includes('proc')) classBadge = 'badge-success';

                const targetHost = window.lastScannedHost || s.ip_address || '';

                rowsHtml += `
                    <tr>
                        <td class="mono" style="color:#94a3b8; text-align:center;">${idx + 1}</td>
                        <td><strong style="color:#0f172a;">${escapeHtml(s.sensor_name || 'Unnamed')}</strong></td>
                        <td><span class="badge ${classBadge}">${escapeHtml(s.sensor_class || 'general')}</span></td>
                        <td class="mono" style="color:#004d40; font-weight:700;">${escapeHtml(val)}</td>
                        <td>
                            <span class="mono oid-copyable" onclick="copyOid('${escapeHtml(s.oid || '')}')" title="Click to copy OID">
                                ${escapeHtml(s.oid || '-')}
                            </span>
                        </td>
                        <td><span class="badge badge-success">Discovered</span></td>
                        <td style="text-align:center; white-space:nowrap;">
                            <button type="button" class="btn-icon-tiny" onclick="copyOid('${escapeHtml(s.oid || '')}')" title="Copy OID to Clipboard">
                                <span class="material-symbols-outlined" style="font-size:15px;">content_copy</span>
                            </button>
                            <button type="button" class="btn-icon-tiny" onclick="openOidDictionarySearch('${escapeHtml(s.oid || '')}')" title="Search in OID Dictionary">
                                <span class="material-symbols-outlined" style="font-size:15px; color:#0284c7;">menu_book</span>
                            </button>
                            <button type="button" class="btn-icon-tiny" onclick="openLiveTestModal('${escapeHtml(s.oid || '')}', '${escapeHtml(targetHost)}')" title="Live SNMP GET Test">
                                <span class="material-symbols-outlined" style="font-size:15px; color:#004d40;">bolt</span>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = rowsHtml;
        }

        // Live filter for Scan Preview Table
        function filterScanPreview() {
            const q = (document.getElementById('scan-preview-search').value || '').trim().toLowerCase();
            const clearBtn = document.getElementById('btn-clear-scan-preview-search');
            const badge = document.getElementById('scan-preview-filter-badge');
            if (clearBtn) clearBtn.style.display = q ? 'block' : 'none';

            if (!window.lastScannedSensors || !window.lastScannedSensors.length) return;

            if (!q) {
                renderScanPreviewRows(window.lastScannedSensors, false);
                if (badge) badge.style.display = 'none';
                return;
            }

            const filtered = window.lastScannedSensors.filter(s => {
                const oid = (s.oid || '').toLowerCase();
                const name = (s.sensor_name || '').toLowerCase();
                const cls = (s.sensor_class || '').toLowerCase();
                const val = String(s.raw_value || s.normalized_value || '').toLowerCase();
                return oid.includes(q) || name.includes(q) || cls.includes(q) || val.includes(q);
            });

            renderScanPreviewRows(filtered, true);
            if (badge) {
                badge.style.display = 'inline-block';
                badge.textContent = `Showing ${filtered.length} of ${window.lastScannedSensors.length} sensors`;
            }
        }

        function clearScanPreviewSearch() {
            const input = document.getElementById('scan-preview-search');
            if (input) input.value = '';
            filterScanPreview();
        }

        // Copy OID helper with toast
        function copyOid(oid) {
            if (!oid || oid === '-') return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(oid).then(() => {
                    showToast(`Copied OID: ${oid}`, 'success');
                }).catch(() => {
                    prompt('Copy OID manually:', oid);
                });
            } else {
                prompt('Copy OID manually:', oid);
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
                _t: Date.now()
            });

            fetch('?' + params.toString(), { cache: 'no-store' })
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
            window.currentInventoryMap = {};
            res.rows.forEach(r => {
                window.currentInventoryMap[r.id] = r;
                const isChecked = !!selectedSensorMap[r.id];
                const provBadge = (parseInt(r.provisioned) === 1)
                    ? `<span class="badge badge-success">Provisioned ${r.agent_name ? '(' + escapeHtml(r.agent_name) + ')' : ''}</span>`
                    : `<span class="badge badge-warning">Pending</span>`;

                const valDisplay = (r.normalized_value !== null && r.normalized_value !== undefined && r.normalized_value !== '')
                    ? `${r.normalized_value} ${r.unit || ''}`.trim()
                    : (r.raw_value !== null && r.raw_value !== undefined && r.raw_value !== '' ? r.raw_value : 'N/A');

                const vStr = r.snmp_version ? (r.snmp_version.toString().toLowerCase().includes('3') ? 'v3' : 'v' + r.snmp_version) : '';
                const vBadge = vStr ? `<span class="badge ${vStr === 'v3' ? 'badge-primary' : 'badge-neutral'}" style="margin-left:6px; font-size:10px; padding:2px 6px;">${vStr}</span>` : '';
                const idBadge = `<span style="display:inline-block; font-size:10px; font-family:monospace; background:#e2e8f0; color:#475569; padding:1px 5px; border-radius:3px; margin-right:6px;" title="Sensor ID #${r.id}">#${r.id}</span>`;

                html += `
                    <tr>
                        <td style="text-align:center;">
                            <input type="checkbox" class="sensor-chk" value="${r.id}" ${isChecked ? 'checked' : ''} onchange="toggleSensorSelect(${r.id}, this.checked, ${JSON.stringify(r).replace(/"/g, '&quot;')})">
                        </td>
                        <td class="mono"><strong>${escapeHtml(r.ip_address || '')}</strong>${vBadge}</td>
                        <td><span class="badge badge-info">${escapeHtml(r.vendor || '')}</span></td>
                        <td><span class="badge badge-neutral">${escapeHtml(r.sensor_class || '')}</span></td>
                        <td class="text-truncate-cell" title="[ID #${r.id}] ${escapeHtml(r.sensor_name || '')}">${idBadge}<strong>${escapeHtml(r.sensor_name || '')}</strong></td>
                        <td class="mono" style="color:#004d40; font-weight:700;">${escapeHtml(valDisplay)}</td>
                        <td class="mono text-truncate-cell" title="${escapeHtml(r.oid || '')}">${escapeHtml(r.oid || '')}</td>
                        <td>${provBadge}</td>
                        <td style="text-align:right;">
                            <button type="button" class="btn-danger-custom" style="padding:2px 8px; height:26px; font-size:11px;" onclick="deleteItem('sensor', ${r.id})">
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
            window.lastInventoryTotal = res.total;

            const masterChk = document.getElementById('check-all-sensors');
            if (masterChk) {
                masterChk.checked = res.rows.length > 0 && res.rows.every(r => !!selectedSensorMap[r.id]);
            }

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
                    selectedSensorMap[id] = (window.currentInventoryMap && window.currentInventoryMap[id]) ? window.currentInventoryMap[id] : { id: id };
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
            const invSel = document.getElementById('inv-selected-count');
            if (invSel) invSel.innerText = count;
            const invDel = document.getElementById('inv-delete-count');
            if (invDel) invDel.innerText = count;
            const provSel = document.getElementById('prov-selected-count');
            if (provSel) provSel.innerText = count;
            const provDeploySubtab = document.getElementById('prov-subtab-deploy-count');
            if (provDeploySubtab) provDeploySubtab.innerText = count;

            // Indicator for deployment queue on main tab
            const stagedBadge = document.getElementById('staged-tab-badge');
            if (stagedBadge) {
                if (count > 0) {
                    stagedBadge.classList.remove('d-none');
                    stagedBadge.innerText = `+${count} queued`;
                } else {
                    stagedBadge.classList.add('d-none');
                }
            }

            const btnDel = document.getElementById('btn-bulk-delete-selected');
            if (btnDel) {
                btnDel.disabled = count === 0;
                btnDel.style.opacity = count === 0 ? '0.6' : '1';
                btnDel.style.cursor = count === 0 ? 'not-allowed' : 'pointer';
            }
        }

        function proceedToProvisioning() {
            const count = Object.keys(selectedSensorMap).length;
            if (count === 0) {
                alert('Please select at least one sensor from the inventory table first.');
                return;
            }
            switchTab('tab-provision');
            switchProvSubTab('deploy');
        }

        // Subtab Navigation inside Pandora Provisioning Tab
        function switchProvSubTab(subTab) {
            currentProvSubTab = subTab;
            const btnActive = document.getElementById('subtab-prov-active-btn');
            const btnDeploy = document.getElementById('subtab-prov-deploy-btn');
            const viewActive = document.getElementById('prov-view-active');
            const viewDeploy = document.getElementById('prov-view-deploy');

            if (subTab === 'deploy') {
                if (btnActive) {
                    btnActive.classList.remove('active');
                    btnActive.style.background = 'transparent';
                    btnActive.style.color = '#64748b';
                    btnActive.style.boxShadow = 'none';
                }
                if (btnDeploy) {
                    btnDeploy.classList.add('active');
                    btnDeploy.style.background = '#004d40';
                    btnDeploy.style.color = '#fff';
                    btnDeploy.style.boxShadow = '0 2px 4px rgba(0,77,64,0.15)';
                }
                if (viewActive) viewActive.classList.add('d-none');
                if (viewDeploy) viewDeploy.classList.remove('d-none');
                renderSelectedProvisionTable();
            } else {
                if (btnActive) {
                    btnActive.classList.add('active');
                    btnActive.style.background = '#004d40';
                    btnActive.style.color = '#fff';
                    btnActive.style.boxShadow = '0 2px 4px rgba(0,77,64,0.15)';
                }
                if (btnDeploy) {
                    btnDeploy.classList.remove('active');
                    btnDeploy.style.background = 'transparent';
                    btnDeploy.style.color = '#64748b';
                    btnDeploy.style.boxShadow = 'none';
                }
                if (viewActive) viewActive.classList.remove('d-none');
                if (viewDeploy) viewDeploy.classList.add('d-none');
                loadProvisionedList(currentProvPage || 1);
            }
        }

        // Debounce search query for provisioned modules
        function debounceLoadProvisioned() {
            clearTimeout(provSearchTimeout);
            provSearchTimeout = setTimeout(() => {
                loadProvisionedList(1);
            }, 300);
        }

        // Load Active Provisioned Modules in Pandora FMS
        function loadProvisionedList(page = 1) {
            currentProvPage = page;
            const tbody = document.getElementById('prov-active-tbody');
            if (!tbody) return;

            const agentFilter = document.getElementById('filter-prov-agent') ? document.getElementById('filter-prov-agent').value : '';
            const queryFilter = document.getElementById('filter-prov-query') ? document.getElementById('filter-prov-query').value : '';

            tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:30px; color:#64748b;"><span class="material-symbols-outlined" style="vertical-align:middle; animation:spin 1s linear infinite;">sync</span> Loading provisioned modules...</td></tr>';

            const params = new URLSearchParams({
                api: 'get_provisioned_sensors',
                page: page,
                per_page: 50,
                agent_id: agentFilter,
                q: queryFilter,
                _t: Date.now()
            });

            fetch('?' + params.toString(), { cache: 'no-store' })
                .then(r => r.json())
                .then(res => {
                    if (res.ok && res.rows) {
                        renderProvisionedTable(res);
                    } else {
                        tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:20px; color:#ef4444;">${res.error || 'Failed to load provisioned modules.'}</td></tr>`;
                    }
                })
                .catch(err => {
                    tbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:20px; color:#ef4444;">Error: ${err.message}</td></tr>`;
                });
        }

        function renderProvisionedTable(res) {
            const tbody = document.getElementById('prov-active-tbody');
            if (!tbody) return;

            // Synchronize counters
            const activeHeader = document.getElementById('prov-active-header-badge');
            if (activeHeader) activeHeader.innerText = res.total;
            const subtabActive = document.getElementById('prov-subtab-active-count');
            if (subtabActive) subtabActive.innerText = res.total;
            const tabBadge = document.getElementById('selected-provision-count');
            if (tabBadge) tabBadge.innerText = res.total;
            const statBadge = document.getElementById('stat-provisioned');
            if (statBadge) statBadge.innerText = res.total;

            if (res.rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center; padding:30px; color:#94a3b8;">No provisioned modules found in Pandora FMS matching the filter.</td></tr>';
                document.getElementById('prov-pagination-info').innerText = 'Showing 0 of 0 entries';
                document.getElementById('prov-pagination-controls').innerHTML = '';
                return;
            }

            let html = '';
            window.currentProvRowsMap = {};
            res.rows.forEach(r => {
                window.currentProvRowsMap[r.id] = r;
                const isChecked = !!selectedProvMap[r.id];

                const valDisplay = (r.module_latest_data !== null && r.module_latest_data !== undefined && r.module_latest_data !== '')
                    ? `${r.module_latest_data} ${r.unit || ''}`.trim()
                    : ((r.normalized_value !== null && r.normalized_value !== undefined && r.normalized_value !== '')
                        ? `${r.normalized_value} ${r.unit || ''}`.trim()
                        : (r.raw_value || 'N/A'));

                const vStr = r.snmp_version ? (r.snmp_version.toString().toLowerCase().includes('3') ? 'v3' : 'v' + r.snmp_version) : '';
                const vBadge = vStr ? `<span class="badge ${vStr === 'v3' ? 'badge-primary' : 'badge-neutral'}" style="margin-left:6px; font-size:10px; padding:2px 6px;">${vStr}</span>` : '';
                const modIdBadge = r.resolved_module_id ? `<span style="display:inline-block; font-size:10px; font-family:monospace; background:#e0f2fe; color:#0369a1; padding:1px 5px; border-radius:3px; margin-right:4px;" title="Pandora Module ID #${r.resolved_module_id}">#${r.resolved_module_id}</span>` : '';

                // Module Status Dot
                let statusDot = '';
                if (r.module_status !== null && r.module_status !== undefined) {
                    const st = parseInt(r.module_status);
                    const colors = { 0: '#16a34a', 1: '#d97706', 2: '#dc2626', 3: '#94a3b8' };
                    const labels = { 0: 'Normal', 1: 'Warning', 2: 'Critical', 3: 'Unknown' };
                    statusDot = `<span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:${colors[st] || '#94a3b8'}; margin-right:5px; vertical-align:middle;" title="Module Status: ${labels[st] || 'Unknown'}"></span>`;
                }

                const provDate = r.provisioned_at ? escapeHtml(r.provisioned_at) : 'N/A';
                const agentDisplayName = r.agent_alias ? `${escapeHtml(r.agent_name)} (${escapeHtml(r.agent_alias)})` : escapeHtml(r.agent_name || 'Agent #' + r.pandora_agent_id);

                html += `
                    <tr>
                        <td style="text-align:center;">
                            <input type="checkbox" class="prov-chk" value="${r.id}" ${isChecked ? 'checked' : ''} onchange="toggleProvSelect(${r.id}, this.checked, ${JSON.stringify(r).replace(/"/g, '&quot;')})">
                        </td>
                        <td class="mono"><strong>${escapeHtml(r.ip_address || '')}</strong>${vBadge}</td>
                        <td>
                            <strong style="color:var(--primary-navy);">${agentDisplayName}</strong>
                        </td>
                        <td>
                            ${statusDot}${modIdBadge}<strong>${escapeHtml(r.module_name || '')}</strong>
                        </td>
                        <td>
                            <span class="badge badge-neutral" style="margin-right:4px;">${escapeHtml(r.sensor_class || '')}</span>
                            <span style="font-size:12px; color:#475569;" title="${escapeHtml(r.sensor_name || '')}">${escapeHtml(r.sensor_name || '')}</span>
                        </td>
                        <td class="mono text-truncate-cell" title="${escapeHtml(r.oid || '')}">${escapeHtml(r.oid || '')}</td>
                        <td class="mono" style="color:#004d40; font-weight:700;">${escapeHtml(valDisplay)}</td>
                        <td style="font-size:11.5px; color:#64748b;">${provDate}</td>
                        <td style="text-align:right;">
                            <div style="display:inline-flex; gap:4px; align-items:center;">
                                <button type="button" class="btn-danger-custom" style="padding:2px 8px; height:26px; font-size:11px; display:inline-flex; align-items:center; gap:3px;" onclick="unprovisionSingle(${r.id}, '${escapeHtml(r.module_name || r.sensor_name || '')}', '${escapeHtml(r.agent_name || '')}')" title="Unprovision and remove module from Pandora FMS">
                                    <span class="material-symbols-outlined" style="font-size:13px;">link_off</span>
                                    Unprovision
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;

            // Pagination Controls
            const start = (res.page - 1) * res.per_page + 1;
            const end = Math.min(res.total, res.page * res.per_page);
            document.getElementById('prov-pagination-info').innerText = `Showing ${start} to ${end} of ${res.total} entries`;

            const masterChk = document.getElementById('check-all-prov');
            if (masterChk) {
                masterChk.checked = res.rows.length > 0 && res.rows.every(r => !!selectedProvMap[r.id]);
            }

            let pagesHtml = '';
            if (res.page > 1) {
                pagesHtml += `<button class="btn-secondary-custom" style="height:28px; padding:0 10px;" onclick="loadProvisionedList(${res.page - 1})">Prev</button>`;
            }
            pagesHtml += `<span style="padding:4px 8px; font-weight:700;">Page ${res.page} / ${res.total_pages || 1}</span>`;
            if (res.page < res.total_pages) {
                pagesHtml += `<button class="btn-secondary-custom" style="height:28px; padding:0 10px;" onclick="loadProvisionedList(${res.page + 1})">Next</button>`;
            }
            document.getElementById('prov-pagination-controls').innerHTML = pagesHtml;
        }

        // Selection Management for Active Provisioned Modules
        function toggleProvSelect(id, isChecked, sensorObj) {
            if (isChecked) {
                selectedProvMap[id] = sensorObj;
            } else {
                delete selectedProvMap[id];
            }
            updateProvBulkBadge();
        }

        function toggleSelectAllProv(masterChk) {
            const chks = document.querySelectorAll('.prov-chk');
            chks.forEach(chk => {
                chk.checked = masterChk.checked;
                const id = parseInt(chk.value);
                if (masterChk.checked) {
                    selectedProvMap[id] = (window.currentProvRowsMap && window.currentProvRowsMap[id]) ? window.currentProvRowsMap[id] : { id: id };
                } else {
                    delete selectedProvMap[id];
                }
            });
            updateProvBulkBadge();
        }

        function updateProvBulkBadge() {
            const count = Object.keys(selectedProvMap).length;
            const badge = document.getElementById('prov-bulk-selected-count');
            if (badge) badge.innerText = count;

            const btn = document.getElementById('btn-bulk-unprovision');
            if (btn) {
                btn.disabled = count === 0;
                btn.style.opacity = count === 0 ? '0.5' : '1';
                btn.style.cursor = count === 0 ? 'not-allowed' : 'pointer';
            }
        }

        // Unprovision single module from Pandora FMS
        function unprovisionSingle(sensorId, moduleName, agentName) {
            if (!sensorId) return;

            const msg = `Are you sure you want to unprovision module "${moduleName}" from Pandora Agent "${agentName}"?\n\nThis will permanently delete the module and its collected metrics from Pandora FMS.\nThe sensor will remain in your inventory and its status will be reset to Pending.`;
            if (!confirm(msg)) {
                return;
            }

            fetch('?api=unprovision', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({
                    sensor_id: sensorId
                })
            })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    delete selectedProvMap[sensorId];
                    updateProvBulkBadge();
                    if (typeof showToast === 'function') {
                        showToast(res.message || 'Module unprovisioned successfully.', 'success');
                    } else {
                        alert(res.message || 'Module unprovisioned successfully.');
                    }
                    loadProvisionedList(currentProvPage || 1);
                    loadStats();
                    loadInventory(currentInventoryPage);
                } else {
                    alert('Error unprovisioning module: ' + (res.error || 'Unknown error'));
                }
            })
            .catch(err => {
                alert('Network error: ' + err.message);
            });
        }

        // Unprovision batch of selected modules from Pandora FMS
        function unprovisionSelectedBatch() {
            const ids = Object.keys(selectedProvMap).map(Number);
            if (ids.length === 0) {
                alert('Please select at least one provisioned sensor to unprovision.');
                return;
            }

            const msg = `Are you sure you want to unprovision ${ids.length} selected module(s) from Pandora FMS?\n\nThis will delete the modules and their metrics from Pandora FMS agents.\nThe sensors will remain in your inventory and their status will return to Pending.`;
            if (!confirm(msg)) {
                return;
            }

            fetch('?api=unprovision', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({
                    sensor_ids: ids
                })
            })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    selectedProvMap = {};
                    updateProvBulkBadge();
                    if (typeof showToast === 'function') {
                        showToast(res.message || `Successfully unprovisioned ${ids.length} modules.`, 'success');
                    } else {
                        alert(res.message || `Successfully unprovisioned ${ids.length} modules.`);
                    }
                    loadProvisionedList(1);
                    loadStats();
                    loadInventory(currentInventoryPage);
                } else {
                    alert('Error: ' + (res.error || 'Failed to unprovision modules.'));
                }
            })
            .catch(err => {
                alert('Network error: ' + err.message);
            });
        }

        // Synchronize provisioned modules status between Pandora FMS and toolkit
        function syncProvisionedStatus() {
            if (!confirm('Synchronize provisioned status with Pandora FMS database?\n\nThis will cross-check all modules in Pandora FMS table (tagente_modulo) with your sensor inventory to ensure counts and statuses match 100%.')) {
                return;
            }

            fetch('?api=sync_provisioned_status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({})
            })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    if (typeof showToast === 'function') {
                        showToast(res.message || 'Synchronization complete.', 'success');
                    } else {
                        alert(res.message || 'Synchronization complete.');
                    }
                    loadStats();
                    loadProvisionedList(1);
                    loadInventory(currentInventoryPage);
                } else {
                    alert('Sync error: ' + (res.error || 'Unknown error'));
                }
            })
            .catch(err => {
                alert('Network error: ' + err.message);
            });
        }

        function renderSelectedProvisionTable() {
            const tbody = document.getElementById('prov-selected-tbody');
            const items = Object.values(selectedSensorMap);

            if (items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#94a3b8;">No sensors currently selected. Go to Sensor Inventory to check items.</td></tr>';
                return;
            }

            let html = '';
            items.forEach(s => {
                const valDisplay = (s.normalized_value !== null && s.normalized_value !== undefined && s.normalized_value !== '')
                    ? `${s.normalized_value} ${s.unit || ''}`.trim()
                    : (s.raw_value !== null && s.raw_value !== undefined && s.raw_value !== '' ? s.raw_value : 'N/A');

                const vStr = s.snmp_version ? (s.snmp_version.toString().toLowerCase().includes('3') ? 'v3' : 'v' + s.snmp_version) : '';
                const vBadge = vStr ? `<span class="badge ${vStr === 'v3' ? 'badge-primary' : 'badge-neutral'}" style="margin-left:6px; font-size:10px; padding:2px 6px;">${vStr}</span>` : '';

                html += `
                    <tr>
                        <td class="mono"><strong>${escapeHtml(s.ip_address || '')}</strong>${vBadge}</td>
                        <td><strong>${escapeHtml(s.sensor_name || 'Sensor #' + s.id)}</strong></td>
                        <td><span class="badge badge-neutral">${escapeHtml(s.sensor_class || '')}</span></td>
                        <td class="mono text-truncate-cell">${escapeHtml(s.oid || '')}</td>
                        <td class="mono" style="color:#004d40; font-weight:700;">${escapeHtml(valDisplay)}</td>
                        <td style="text-align:center;">
                            <button type="button" class="btn-danger-custom" style="padding:2px 8px; height:26px; font-size:11px; display:inline-flex; align-items:center; gap:3px;" onclick="removeProvisionSensor('${s.id}')" title="Remove sensor from provisioning queue">
                                <span class="material-symbols-outlined" style="font-size:14px;">delete</span>
                                Delete
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        function removeProvisionSensor(id) {
            if (!id && id !== 0) return;
            delete selectedSensorMap[id];

            // Uncheck the checkbox in inventory if currently rendered in DOM
            const chk = document.querySelector(`.sensor-chk[value="${id}"]`);
            if (chk) chk.checked = false;

            // Check if master checkbox should be unchecked
            const allChks = document.querySelectorAll('.sensor-chk');
            const allChecked = allChks.length > 0 && Array.from(allChks).every(c => c.checked);
            const masterChk = document.getElementById('check-all-sensors');
            if (masterChk && !allChecked) masterChk.checked = false;

            updateSelectedBadge();
            renderSelectedProvisionTable();
            if (typeof showToast === 'function') {
                showToast('Sensor removed from provisioning queue.', 'info');
            }
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
                    clearSelectedSensors();
                    loadStats();
                    loadInventory(currentInventoryPage);
                    loadProvisionedList(1);
                    if (typeof showToast === 'function') {
                        showToast('Sensors successfully provisioned to Pandora FMS agent!', 'success');
                    }
                    setTimeout(() => {
                        switchProvSubTab('active');
                    }, 1200);
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

        // Auto-fix existing HR-STORAGE and Memory percentage modules in Pandora FMS
        function repairStorageModules() {
            if (!confirm('Auto-fix memory & storage percentage modules in Pandora FMS?\n\nThis will scan Pandora FMS modules for HOST-RESOURCES-MIB partitions (like /var, /config, /output, etc.), change OIDs from hrStorageSize (.5) to hrStorageUsed (.6), set the post_process multiplier (100 / total_blocks), and reset module cache so correct percentages (e.g. 13%) display immediately.')) {
                return;
            }

            fetch('?api=repair_storage_modules', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({})
            })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    alert(res.message || 'Modules repaired successfully!');
                    loadStats();
                    loadInventory(currentInventoryPage);
                } else {
                    alert('Repair error: ' + (res.error || 'Unknown error'));
                }
            })
            .catch(err => {
                alert('Network error: ' + err.message);
            });
        }

        // Auto-fix existing Environmental & Scaled (Temp deci-degrees, Current mA) modules in Pandora FMS
        function repairEnvironmentalModules() {
            if (!confirm('Auto-fix environmental sensor scales in Pandora FMS?\n\nThis will scan Pandora FMS modules for:\n- Temperature in deci-degrees (e.g. 360 C -> 36.0 C with post_process 0.1)\n- Current in milliAmperes (e.g. 690 A -> 0.69 A with post_process 0.001)\n\nIt will apply post_process multipliers and refresh cached module values immediately.')) {
                return;
            }

            fetch('?api=repair_environmental_modules', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({})
            })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    alert(res.message || 'Environmental modules repaired successfully!');
                    loadStats();
                    loadInventory(currentInventoryPage);
                } else {
                    alert('Repair error: ' + (res.error || 'Unknown error'));
                }
            })
            .catch(err => {
                alert('Network error: ' + err.message);
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
            let itemDesc = `${type} #${id}`;
            if (type === 'sensor' && window.currentInventoryMap && window.currentInventoryMap[id]) {
                const s = window.currentInventoryMap[id];
                itemDesc = `sensor #${id} "${s.sensor_name}"`;
            }
            if (!confirm(`Are you sure you want to permanently delete ${itemDesc}?`)) return;

            fetch(`?api=delete&_t=${Date.now()}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: JSON.stringify({ type: type, id: id, csrf_token: CSRF_TOKEN })
            })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    if (type === 'sensor' && selectedSensorMap[id]) {
                        delete selectedSensorMap[id];
                        updateSelectedBadge();
                    }
                    if (typeof showToast === 'function') {
                        showToast(res.message || `${itemDesc} deleted successfully.`, 'success');
                    }
                    loadStats();
                    loadInventory(currentInventoryPage);
                } else {
                    alert('Error: ' + res.error);
                }
            })
            .catch(err => alert('Network error: ' + err.message));
        }

        // Bulk Delete Selected Sensors
        async function bulkDeleteSelectedSensors() {
            const ids = Object.keys(selectedSensorMap).map(Number);
            if (ids.length === 0) {
                alert('No sensors selected. Please check at least one sensor checkbox first.');
                return;
            }

            if (!confirm(`Are you sure you want to permanently delete ${ids.length} selected sensor(s) from inventory?`)) {
                return;
            }

            try {
                const res = await fetch(`?api=delete&_t=${Date.now()}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        type: 'bulk_sensors',
                        ids: ids,
                        csrf_token: CSRF_TOKEN
                    })
                });
                const json = await res.json();
                if (json.ok) {
                    ids.forEach(id => delete selectedSensorMap[id]);
                    updateSelectedBadge();
                    if (typeof showToast === 'function') {
                        showToast(json.message || `${ids.length} sensor(s) deleted.`, 'success');
                    } else {
                        alert(json.message);
                    }
                    loadStats();
                    loadInventory(currentInventoryPage);
                } else {
                    alert('Error: ' + (json.error || 'Failed to delete selected sensors.'));
                }
            } catch (err) {
                alert('Network error: ' + err.message);
            }
        }

        // Clear All or Filtered Sensors
        async function clearFilteredSensors() {
            const deviceEl = document.getElementById('filter-device');
            const vendorEl = document.getElementById('filter-vendor');
            const classEl = document.getElementById('filter-class');
            const provEl = document.getElementById('filter-provisioned');
            const qEl = document.getElementById('filter-query');

            const deviceVal = deviceEl ? deviceEl.value : '';
            const vendorVal = vendorEl ? vendorEl.value : '';
            const classVal = classEl ? classEl.value : '';
            const provVal = provEl ? provEl.value : '';
            const qVal = qEl ? qEl.value.trim() : '';

            const hasFilters = deviceVal !== '' || vendorVal !== '' || classVal !== '' || provVal !== '' || qVal !== '';
            const totalCount = window.lastInventoryTotal !== undefined ? window.lastInventoryTotal : 'all matching';

            let msg = '';
            if (hasFilters) {
                const filterDesc = [];
                if (deviceVal && deviceEl.options[deviceEl.selectedIndex]) {
                    filterDesc.push(`Device: ${deviceEl.options[deviceEl.selectedIndex].text}`);
                }
                if (vendorVal) filterDesc.push(`Vendor: ${vendorVal}`);
                if (classVal) filterDesc.push(`Class: ${classVal}`);
                if (provVal !== '') filterDesc.push(`Status: ${provVal === '1' ? 'Provisioned' : 'Pending'}`);
                if (qVal) filterDesc.push(`Query: "${qVal}"`);

                msg = `Are you sure you want to permanently delete all ${totalCount} sensor(s) matching current filter?\n\nFilters: [ ${filterDesc.join(', ')} ]\n\nThis action cannot be undone!`;
            } else {
                msg = `WARNING: Are you sure you want to permanently delete ALL ${totalCount} sensors in the inventory?\n\nThis will completely clear the discovered sensors table. This action cannot be undone!`;
            }

            if (!confirm(msg)) return;

            try {
                const res = await fetch(`?api=delete&_t=${Date.now()}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        type: 'clear_sensors',
                        scope: hasFilters ? 'filtered' : 'all',
                        device_id: deviceVal,
                        vendor: vendorVal,
                        sensor_class: classVal,
                        provisioned: provVal,
                        q: qVal,
                        csrf_token: CSRF_TOKEN
                    })
                });
                const json = await res.json();
                if (json.ok) {
                    selectedSensorMap = {};
                    updateSelectedBadge();
                    if (typeof showToast === 'function') {
                        showToast(json.message || 'Sensors cleared successfully.', 'success');
                    } else {
                        alert(json.message);
                    }
                    loadStats();
                    loadInventory(1);
                } else {
                    alert('Error: ' + (json.error || 'Failed to clear sensors.'));
                }
            } catch (err) {
                alert('Network error: ' + err.message);
            }
        }

        // Clean duplicate sensors sharing identical OID
        async function cleanDuplicateSensors() {
            const deviceEl = document.getElementById('filter-device');
            const deviceVal = deviceEl ? deviceEl.value : '';
            const optText = (deviceEl && deviceEl.selectedIndex >= 0 && deviceEl.options[deviceEl.selectedIndex]) ? deviceEl.options[deviceEl.selectedIndex].text : '';
            const msg = deviceVal 
                ? `Clean up duplicate sensors (same OID) for device "${optText}"?\nOnly unprovisioned duplicate rows will be deleted, keeping the newest entry.`
                : `Clean up duplicate sensors (same OID) across ALL devices in inventory?\nOnly unprovisioned duplicate rows will be deleted, keeping the newest entry.`;

            if (!confirm(msg)) return;

            try {
                const res = await fetch(`?api=deduplicate_sensors&_t=${Date.now()}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        device_id: deviceVal,
                        csrf_token: CSRF_TOKEN
                    })
                });
                const json = await res.json();
                if (json.ok) {
                    selectedSensorMap = {};
                    updateSelectedBadge();
                    if (typeof showToast === 'function') {
                        showToast(json.message || `Cleaned duplicate sensors.`, 'success');
                    } else {
                        alert(json.message);
                    }
                    loadStats();
                    loadInventory(1);
                } else {
                    alert('Error: ' + (json.error || 'Failed to clean duplicates.'));
                }
            } catch (err) {
                alert('Network error: ' + err.message);
            }
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

        // =============================================================
        // 9. MIB UPLOADER & REGISTRY CONTROLLER
        // =============================================================
        let allMibsData = [];

        function showToast(msg, type = 'success') {
            let toast = document.getElementById('snmp-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'snmp-toast';
                toast.style.position = 'fixed';
                toast.style.bottom = '24px';
                toast.style.right = '24px';
                toast.style.padding = '12px 20px';
                toast.style.borderRadius = '8px';
                toast.style.color = '#fff';
                toast.style.fontSize = '13px';
                toast.style.fontWeight = '600';
                toast.style.boxShadow = '0 10px 25px rgba(0,0,0,0.18)';
                toast.style.zIndex = '99999';
                toast.style.transition = 'all 0.3s ease';
                toast.style.display = 'flex';
                toast.style.alignItems = 'center';
                toast.style.gap = '8px';
                document.body.appendChild(toast);
            }
            toast.style.background = type === 'error' ? '#991b1b' : '#065f46';
            toast.style.borderLeft = type === 'error' ? '4px solid #f87171' : '4px solid #34d399';
            toast.textContent = msg;
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(10px)';
            }, 4000);
        }

        function setMibImportMode(mode) {
            ['file', 'text', 'preset'].forEach(m => {
                const el = document.getElementById(`form-upload-${m}`);
                const btn = document.getElementById(`btn-mode-${m}`);
                if (el) el.classList.toggle('d-none', m !== mode);
                if (btn) btn.classList.toggle('active-mode-btn', m === mode);
            });
        }

        function handleMibFileSelect(input) {
            const file = input.files[0];
            const btn = document.getElementById('btn-submit-upload');
            const lbl = document.getElementById('selected-file-label');
            if (file) {
                lbl.textContent = `Selected: ${file.name} (${(file.size / 1024).toFixed(1)} KB)`;
                lbl.style.display = 'block';
                btn.disabled = false;
            } else {
                lbl.style.display = 'none';
                btn.disabled = true;
            }
        }

        // Drag & Drop Listener
        document.addEventListener('DOMContentLoaded', () => {
            const dropArea = document.getElementById('drop-area-mib');
            if (dropArea) {
                ['dragenter', 'dragover'].forEach(eventName => {
                    dropArea.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        dropArea.style.borderColor = 'var(--brand-green)';
                        dropArea.style.background = '#f0fdf4';
                    }, false);
                });
                ['dragleave', 'drop'].forEach(eventName => {
                    dropArea.addEventListener(eventName, (e) => {
                        e.preventDefault();
                        dropArea.style.borderColor = '#cbd5e1';
                        dropArea.style.background = '#f8fafc';
                    }, false);
                });
                dropArea.addEventListener('drop', (e) => {
                    const dt = e.dataTransfer;
                    const files = dt.files;
                    if (files.length > 0) {
                        const fileInput = document.getElementById('mib-file-input');
                        fileInput.files = files;
                        handleMibFileSelect(fileInput);
                    }
                }, false);
            }
        });

        async function submitMibFileUpload(e) {
            e.preventDefault();
            const fileInput = document.getElementById('mib-file-input');
            if (!fileInput.files.length) return;

            const btn = document.getElementById('btn-submit-upload');
            const origText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = 'Uploading & Registering...';

            const formData = new FormData();
            formData.append('mib_file', fileInput.files[0]);
            formData.append('csrf_token', CSRF_TOKEN);

            try {
                const res = await fetch('?api=upload_mib', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF_TOKEN },
                    body: formData
                });
                const json = await res.json();
                if (json.ok) {
                    showToast(json.message || 'MIB uploaded successfully!', 'success');
                    fileInput.value = '';
                    handleMibFileSelect(fileInput);
                    loadMibsList();
                } else {
                    showToast(json.error || 'Failed to upload MIB.', 'error');
                }
            } catch (err) {
                showToast('Network error during upload: ' + err.message, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = origText;
            }
        }

        async function submitMibText(e) {
            e.preventDefault();
            const mibName = document.getElementById('mib-text-name').value.trim();
            const content = document.getElementById('mib-text-content').value.trim();
            if (!content) return;

            try {
                const res = await fetch('?api=import_mib_text', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        method: 'text',
                        mib_name: mibName,
                        content: content,
                        csrf_token: CSRF_TOKEN
                    })
                });
                const json = await res.json();
                if (json.ok) {
                    showToast(json.message || 'MIB imported successfully!', 'success');
                    document.getElementById('mib-text-name').value = '';
                    document.getElementById('mib-text-content').value = '';
                    loadMibsList();
                } else {
                    showToast(json.error || 'Failed to import MIB.', 'error');
                }
            } catch (err) {
                showToast('Error: ' + err.message, 'error');
            }
        }

        async function installPresetMib(presetKey) {
            if (!confirm(`Download and install preset MIB '${presetKey}'?`)) return;
            try {
                showToast(`Downloading preset ${presetKey}...`, 'success');
                const res = await fetch('?api=import_mib_text', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        method: 'preset',
                        preset: presetKey,
                        csrf_token: CSRF_TOKEN
                    })
                });
                const json = await res.json();
                if (json.ok) {
                    showToast(json.message || 'Preset MIB installed!', 'success');
                    loadMibsList();
                } else {
                    showToast(json.error || 'Failed to install preset.', 'error');
                }
            } catch (err) {
                showToast('Error: ' + err.message, 'error');
            }
        }

        async function loadMibsList() {
            try {
                const res = await fetch('?api=list_mibs');
                const json = await res.json();
                if (json.ok) {
                    allMibsData = json.mibs || [];
                    const badge = document.getElementById('mibs-tab-count');
                    if (badge) badge.textContent = allMibsData.length;
                    renderMibsTable(allMibsData);
                }
            } catch (e) {
                console.error('Failed to load MIBs list:', e);
            }
        }

        function renderMibsTable(mibs) {
            const tbody = document.getElementById('mibs-tbody');
            if (!tbody) return;
            if (!mibs || !mibs.length) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:30px; color:#94a3b8;">No MIB modules found. Upload or install presets above.</td></tr>';
                return;
            }
            let html = '';
            mibs.forEach(mib => {
                const isLocal = mib.source.indexOf('Toolkit') !== -1;
                const sourceBadge = isLocal 
                    ? `<span style="background:#e0f2fe; color:#0369a1; font-size:11px; padding:2px 8px; border-radius:4px; font-weight:600;">Toolkit Local</span>`
                    : `<span style="background:#fef3c7; color:#92400e; font-size:11px; padding:2px 8px; border-radius:4px; font-weight:600;">Pandora Console</span>`;
                
                const deleteBtn = mib.can_delete 
                    ? `<button type="button" class="btn-secondary-custom" style="padding:4px 8px; font-size:11px; color:#b91c1c;" onclick="deleteMib('${encodeURIComponent(mib.filename)}')">Delete</button>`
                    : `<span style="font-size:11px; color:#94a3b8;">Protected</span>`;

                html += `<tr>
                    <td><strong style="color:var(--primary-navy);">${escapeHtml(mib.module_name)}</strong></td>
                    <td><code style="color:var(--brand-green); font-size:11.5px;">${escapeHtml(mib.filename)}</code></td>
                    <td>${escapeHtml(mib.size_formatted)}</td>
                    <td>${sourceBadge}</td>
                    <td style="font-size:11.5px; color:#64748b;">${escapeHtml(mib.modified_at)}</td>
                    <td style="text-align:right;">
                        <button type="button" class="btn-secondary-custom" style="padding:4px 8px; font-size:11px; margin-right:4px;" onclick="viewMibContent('${encodeURIComponent(mib.filename)}')">View</button>
                        ${deleteBtn}
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        function filterMibsTable() {
            const q = (document.getElementById('search-mibs').value || '').toLowerCase().trim();
            if (!q) {
                renderMibsTable(allMibsData);
                return;
            }
            const filtered = allMibsData.filter(m => 
                (m.module_name && m.module_name.toLowerCase().includes(q)) ||
                (m.filename && m.filename.toLowerCase().includes(q))
            );
            renderMibsTable(filtered);
        }

        async function viewMibContent(encodedFilename) {
            const filename = decodeURIComponent(encodedFilename);
            try {
                const res = await fetch(`?api=view_mib&filename=${encodeURIComponent(filename)}`);
                const json = await res.json();
                if (json.ok) {
                    document.getElementById('view-mib-title').textContent = json.filename;
                    document.getElementById('view-mib-code').textContent = json.content;
                    document.getElementById('modal-view-mib').style.display = 'flex';
                } else {
                    showToast(json.error || 'Failed to read MIB file.', 'error');
                }
            } catch (e) {
                showToast('Error reading MIB: ' + e.message, 'error');
            }
        }

        function closeViewMibModal() {
            document.getElementById('modal-view-mib').style.display = 'none';
        }

        async function deleteMib(encodedFilename) {
            const filename = decodeURIComponent(encodedFilename);
            if (!confirm(`Are you sure you want to delete MIB file '${filename}'?`)) return;

            try {
                const res = await fetch('?api=delete_mib', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF_TOKEN
                    },
                    body: JSON.stringify({ filename: filename, csrf_token: CSRF_TOKEN })
                });
                const json = await res.json();
                if (json.ok) {
                    showToast(json.message || 'MIB deleted.', 'success');
                    loadMibsList();
                } else {
                    showToast(json.error || 'Failed to delete MIB.', 'error');
                }
            } catch (e) {
                showToast('Error deleting MIB: ' + e.message, 'error');
            }
        }

        async function testTranslateOid(e) {
            e.preventDefault();
            const oid = document.getElementById('test-oid-input').value.trim();
            if (!oid) return;

            const resBox = document.getElementById('test-translate-result');
            resBox.innerHTML = '<div style="color:#64748b; padding:20px; text-align:center;">Translating OID with Net-SNMP...</div>';

            try {
                const res = await fetch(`?api=translate_oid&oid=${encodeURIComponent(oid)}`);
                const json = await res.json();
                if (json.ok && json.data) {
                    const d = json.data;
                    const translatedBadge = d.translated 
                        ? `<span style="background:#ecfdf5; color:#047857; font-size:11px; padding:2px 8px; border-radius:4px; font-weight:700;">TRANSLATED OK</span>`
                        : `<span style="background:#fef2f2; color:#b91c1c; font-size:11px; padding:2px 8px; border-radius:4px; font-weight:700;">RAW / UNTRANSLATED</span>`;

                    resBox.innerHTML = `
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; padding-bottom:8px; border-bottom:1px solid #e2e8f0;">
                            <div>
                                <span style="font-size:11px; color:#64748b;">Symbolic OID:</span>
                                <div style="font-size:13.5px; font-weight:700; color:var(--primary-navy); font-family:monospace;">${escapeHtml(d.symbolic_oid || 'None')}</div>
                            </div>
                            <div>${translatedBadge}</div>
                        </div>
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-bottom:10px;">
                            <div><span style="color:#64748b; font-size:11px;">Source MIB:</span> <strong style="color:var(--brand-green);">${escapeHtml(d.mib || 'Unknown')}</strong></div>
                            <div><span style="color:#64748b; font-size:11px;">Object Name:</span> <strong style="color:var(--primary-navy);">${escapeHtml(d.object || '-')}</strong></div>
                            <div><span style="color:#64748b; font-size:11px;">Display Name:</span> <strong>${escapeHtml(d.display_name || '-')}</strong></div>
                            <div><span style="color:#64748b; font-size:11px;">Syntax:</span> <code style="font-size:11px;">${escapeHtml(d.syntax || '-')}</code></div>
                            <div><span style="color:#64748b; font-size:11px;">Suggested Class:</span> <span class="badge badge-neutral">${escapeHtml(d.suggested_class || '-')}</span></div>
                            <div><span style="color:#64748b; font-size:11px;">Units:</span> <strong>${escapeHtml(d.units || d.suggested_unit || '-')}</strong></div>
                        </div>
                        ${d.description ? `<div style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:8px 10px; font-size:11px; color:#475569; max-height:80px; overflow-y:auto;"><strong>Description:</strong> ${escapeHtml(d.description)}</div>` : ''}
                    `;
                } else {
                    resBox.innerHTML = `<div style="color:#b91c1c; padding:20px; text-align:center;">${escapeHtml(json.error || 'Failed to translate OID.')}</div>`;
                }
            } catch (err) {
                resBox.innerHTML = `<div style="color:#b91c1c; padding:20px; text-align:center;">Translation error: ${escapeHtml(err.message)}</div>`;
            }
        }

        // =============================================================
        // OID DICTIONARY & SEARCH JAVASCRIPT LOGIC
        // =============================================================
        let oidSearchDebounceTimer = null;
        window.hasLoadedOidSearch = false;

        function switchMibSubTab(subTabId) {
            const tabs = ['search', 'upload', 'installed', 'tester'];
            tabs.forEach(t => {
                const btn = document.getElementById(`btn-subtab-${t}`);
                const panel = document.getElementById(`subpanel-${t}`);
                if (btn) btn.classList.toggle('active', t === subTabId);
                if (panel) panel.classList.toggle('d-none', t !== subTabId);
            });

            if (subTabId === 'installed') {
                loadMibsList();
            } else if (subTabId === 'search') {
                if (!window.hasLoadedOidSearch) {
                    searchOids();
                }
            }
        }

        function openOidDictionarySearch(query = '') {
            switchTab('tab-mibs');
            switchMibSubTab('search');
            const input = document.getElementById('oid-search-input');
            if (input) {
                if (query) {
                    input.value = query;
                    const clearBtn = document.getElementById('btn-clear-oid-search');
                    if (clearBtn) clearBtn.style.display = 'block';
                }
                searchOids();
                setTimeout(() => {
                    input.focus();
                    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 100);
            }
        }

        function debounceOidSearch() {
            clearTimeout(oidSearchDebounceTimer);
            const clearBtn = document.getElementById('btn-clear-oid-search');
            const q = (document.getElementById('oid-search-input').value || '').trim();
            if (clearBtn) clearBtn.style.display = q ? 'block' : 'none';

            oidSearchDebounceTimer = setTimeout(() => {
                searchOids();
            }, 300);
        }

        function setOidSearchQuery(q) {
            const input = document.getElementById('oid-search-input');
            if (input) {
                input.value = q;
                const clearBtn = document.getElementById('btn-clear-oid-search');
                if (clearBtn) clearBtn.style.display = 'block';
                searchOids();
                input.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        function clearOidSearch() {
            const input = document.getElementById('oid-search-input');
            if (input) input.value = '';
            const clearBtn = document.getElementById('btn-clear-oid-search');
            if (clearBtn) clearBtn.style.display = 'none';
            searchOids();
        }

        async function searchOids() {
            const input = document.getElementById('oid-search-input');
            const query = input ? input.value.trim() : '';
            const sourceRadio = document.querySelector('input[name="oid_search_source"]:checked');
            const source = sourceRadio ? sourceRadio.value : 'all';

            const tbody = document.getElementById('oid-search-tbody');
            const countEl = document.getElementById('oid-search-count');
            const statusEl = document.getElementById('oid-search-status');

            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:24px; color:#64748b;">Searching OID dictionary...</td></tr>';
            }

            try {
                const params = new URLSearchParams({
                    api: 'search_oids',
                    q: query,
                    source: source,
                    limit: 60
                });

                const res = await fetch(`?${params.toString()}`);
                const json = await res.json();

                if (json.ok) {
                    window.hasLoadedOidSearch = true;
                    renderOidSearchResults(json.results || [], query);
                    if (countEl) countEl.innerText = (json.results || []).length;
                    if (statusEl) {
                        statusEl.innerText = query 
                            ? `Search results for "${escapeHtml(query)}"` 
                            : 'Standard Network OIDs & Discovered Inventory Catalog';
                    }
                } else {
                    if (tbody) {
                        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:24px; color:#b91c1c;">${escapeHtml(json.error || 'Failed to search OIDs.')}</td></tr>`;
                    }
                }
            } catch (err) {
                if (tbody) {
                    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:24px; color:#b91c1c;">Network error: ${escapeHtml(err.message)}</td></tr>`;
                }
            }
        }

        function renderOidSearchResults(results, query) {
            const tbody = document.getElementById('oid-search-tbody');
            if (!tbody) return;

            if (!results || results.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" style="text-align:center; padding:32px; color:#94a3b8;">
                            No OID or metric definition found matching "${escapeHtml(query)}".
                            <div style="font-size:11.5px; margin-top:6px; color:#64748b;">
                                Tip: Try uploading the vendor MIB file or search by broad keywords like <code>cpu</code>, <code>temperature</code>, <code>interface</code>, <code>storage</code>.
                            </div>
                        </td>
                    </tr>`;
                return;
            }

            let html = '';
            results.forEach((item, idx) => {
                let classBadge = 'badge-neutral';
                const c = (item.class || '').toLowerCase();
                if (c.includes('interface')) classBadge = 'badge-info';
                else if (c.includes('optical') || c.includes('dom') || c.includes('power')) classBadge = 'badge-warning';
                else if (c.includes('env') || c.includes('temp') || c.includes('cpu') || c.includes('proc') || c.includes('sys')) classBadge = 'badge-success';

                let sourceBadge = '';
                if (item.in_inventory) {
                    sourceBadge = `<span class="badge badge-success" title="${escapeHtml(item.desc || '')}">Discovered (${item.device_count || 1} dev)</span>`;
                } else if (item.source_type === 'catalog') {
                    sourceBadge = `<span class="badge badge-info" title="Standard RFC/Vendor catalog">Catalog</span>`;
                } else if (item.source_type === 'mib_file') {
                    sourceBadge = `<span class="badge badge-warning" title="${escapeHtml(item.source)}">MIB File</span>`;
                } else {
                    sourceBadge = `<span class="badge badge-neutral">${escapeHtml(item.source || 'Known')}</span>`;
                }

                const syntaxDisplay = item.syntax ? escapeHtml(item.syntax) : (item.unit ? `Unit: ${escapeHtml(item.unit)}` : '-');
                const targetHost = window.lastScannedHost || (item.sample_devices ? item.sample_devices.split(',')[0].trim() : '');

                html += `
                    <tr>
                        <td class="mono" style="color:#94a3b8; text-align:center;">${idx + 1}</td>
                        <td>
                            <div style="display:flex; align-items:center; gap:6px;">
                                <span class="mono oid-copyable" onclick="copyOid('${escapeHtml(item.oid)}')" title="Click to copy OID">
                                    ${escapeHtml(item.oid)}
                                </span>
                            </div>
                        </td>
                        <td>
                            <div>
                                <strong style="color:var(--primary-navy); font-size:12.5px;">${escapeHtml(item.name || '-')}</strong>
                                ${item.symbolic_name && item.symbolic_name !== item.name ? `<div style="font-size:11px; color:#64748b; font-family:monospace;">${escapeHtml(item.symbolic_name)}</div>` : ''}
                            </div>
                        </td>
                        <td>
                            <span class="badge" style="background:#f1f5f9; color:#334155; border:1px solid #cbd5e1; font-weight:600;">
                                ${escapeHtml(item.module || 'Unknown')}
                            </span>
                        </td>
                        <td>
                            <span class="badge ${classBadge}">${escapeHtml(item.class || 'general')}</span>
                            ${item.unit ? `<span style="font-size:11px; color:#64748b; margin-left:4px;">(${escapeHtml(item.unit)})</span>` : ''}
                        </td>
                        <td class="mono" style="font-size:11px; max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="${escapeHtml(item.syntax || '')}">
                            ${syntaxDisplay}
                        </td>
                        <td>${sourceBadge}</td>
                        <td style="text-align:center; white-space:nowrap;">
                            <button type="button" class="btn-icon-tiny" onclick="copyOid('${escapeHtml(item.oid)}')" title="Copy OID to Clipboard">
                                <span class="material-symbols-outlined" style="font-size:15px;">content_copy</span>
                            </button>
                            <button type="button" class="btn-icon-tiny" onclick="openLiveTestModal('${escapeHtml(item.oid)}', '${escapeHtml(targetHost)}')" title="Test Live Query on Device">
                                <span class="material-symbols-outlined" style="font-size:15px; color:#004d40;">bolt</span>
                            </button>
                            <button type="button" class="btn-icon-tiny" onclick="openOidInspectorModal('${escapeHtml(item.oid)}', '${escapeHtml(item.name || '')}', '${escapeHtml(item.module || '')}', '${escapeHtml(item.syntax || '')}', '${escapeHtml(item.class || '')}', '${escapeHtml(item.desc || '')}')" title="Inspect Full MIB Details">
                                <span class="material-symbols-outlined" style="font-size:15px; color:#0284c7;">info</span>
                            </button>
                        </td>
                    </tr>
                `;
            });

            tbody.innerHTML = html;
        }

        // Live SNMP Query Modal Functions
        function openLiveTestModal(oid, host = '') {
            const modal = document.getElementById('modal-test-snmp');
            const oidInput = document.getElementById('test-snmp-oid');
            const hostInput = document.getElementById('test-snmp-host');
            const resBox = document.getElementById('test-snmp-result-box');

            if (oidInput) oidInput.value = oid || '';
            if (hostInput) {
                hostInput.value = host || window.lastScannedHost || (document.getElementById('target-ip') ? document.getElementById('target-ip').value.trim() : '');
            }
            if (resBox) {
                resBox.style.display = 'none';
                resBox.innerHTML = '';
            }

            if (modal) modal.style.display = 'flex';
        }

        function closeLiveTestModal() {
            const modal = document.getElementById('modal-test-snmp');
            if (modal) modal.style.display = 'none';
        }

        function toggleTestSnmpVersion() {
            const ver = document.getElementById('test-snmp-version').value;
            const commGroup = document.getElementById('test-snmp-community-group');
            const v3Box = document.getElementById('test-snmp-v3-box');

            if (commGroup) commGroup.style.display = ver === '3' ? 'none' : 'flex';
            if (v3Box) v3Box.classList.toggle('d-none', ver !== '3');
        }

        async function submitLiveSnmpTest(e) {
            e.preventDefault();
            const host = document.getElementById('test-snmp-host').value.trim();
            const oid = document.getElementById('test-snmp-oid').value.trim();
            const ver = document.getElementById('test-snmp-version').value;
            const port = parseInt(document.getElementById('test-snmp-port').value, 10) || 161;
            const comm = document.getElementById('test-snmp-community').value.trim();
            const v3User = document.getElementById('test-v3-user') ? document.getElementById('test-v3-user').value.trim() : '';
            const v3SecLevel = document.getElementById('test-v3-sec-level') ? document.getElementById('test-v3-sec-level').value : '';
            const v3AuthProto = document.getElementById('test-v3-auth-proto') ? document.getElementById('test-v3-auth-proto').value : '';
            const v3AuthPass = document.getElementById('test-v3-auth-pass') ? document.getElementById('test-v3-auth-pass').value : '';

            const resBox = document.getElementById('test-snmp-result-box');
            const submitBtn = document.getElementById('btn-run-live-test');

            if (resBox) {
                resBox.style.display = 'block';
                resBox.innerHTML = '<div style="color:#64748b; padding:10px; text-align:center;">Executing live SNMP GET query...</div>';
            }
            if (submitBtn) submitBtn.disabled = true;

            try {
                const res = await fetch('?api=test_snmp_get', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        host: host,
                        oid: oid,
                        version: ver,
                        port: port,
                        community: comm,
                        v3_user: v3User,
                        v3_sec_level: v3SecLevel,
                        v3_auth_proto: v3AuthProto,
                        v3_auth_pass: v3AuthPass,
                        csrf_token: CSRF_TOKEN
                    })
                });

                const json = await res.json();
                if (submitBtn) submitBtn.disabled = false;

                if (json.ok) {
                    const transName = json.translation ? (json.translation.object || json.translation.display_name || '-') : '-';
                    const transMib = json.translation ? (json.translation.mib || 'Unknown') : '-';

                    resBox.innerHTML = `
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">
                            <span style="font-weight:700; color:#047857; display:flex; align-items:center; gap:4px;">
                                <span class="material-symbols-outlined" style="font-size:16px;">check_circle</span>
                                Response Received (${json.duration_ms}ms)
                            </span>
                            <span class="mono" style="font-size:11px; color:#64748b;">${escapeHtml(json.host)}</span>
                        </div>
                        <div style="margin-bottom:8px;">
                            <span style="font-size:11px; color:#64748b;">Returned Raw Value:</span>
                            <div class="mono" style="font-size:13px; font-weight:700; color:#004d40; background:#fff; border:1px solid #cbd5e1; border-radius:4px; padding:6px 10px; word-break:break-all;">
                                ${escapeHtml(String(json.value))}
                            </div>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; font-size:11px;">
                            <div><span style="color:#64748b;">Resolved MIB:</span> <strong>${escapeHtml(transMib)}</strong></div>
                            <div><span style="color:#64748b;">Object Name:</span> <strong>${escapeHtml(transName)}</strong></div>
                        </div>
                    `;
                } else {
                    resBox.innerHTML = `
                        <div style="color:#b91c1c; font-weight:700; margin-bottom:4px; display:flex; align-items:center; gap:4px;">
                            <span class="material-symbols-outlined" style="font-size:16px;">error</span>
                            SNMP Query Failed (${json.duration_ms || 0}ms)
                        </div>
                        <div style="color:#475569; font-size:11.5px;">${escapeHtml(json.error || 'Device timed out or OID not found.')}</div>
                    `;
                }
            } catch (err) {
                if (submitBtn) submitBtn.disabled = false;
                if (resBox) {
                    resBox.innerHTML = `<div style="color:#b91c1c; padding:10px;">Query error: ${escapeHtml(err.message)}</div>`;
                }
            }
        }

        // Inspector Modal
        function openOidInspectorModal(oid, name, module, syntax, cls, desc) {
            let info = `OID: ${oid}\nName: ${name}\nMIB Module: ${module}\nClass: ${cls}\nSyntax: ${syntax}\n\nDescription:\n${desc || 'No description provided in definition.'}`;
            alert(info);
        }
    </script>
</body>
</html>
