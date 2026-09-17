<?php
declare(strict_types=1);

/**
 * topology-network.php
 * Enterprise SDDC & Network Topology Map Dashboard
 * PFMS-Toolkit
 * 
 * Features:
 * - 2-View Dynamic Dashboard Workflow (View 1: Dashboard Manager List, View 2: Interactive Topology Canvas)
 * - Self-contained Backend API & Classification Engine
 * - Stunning visual topology with high-res vector device icons (VM, Hypervisor, Cluster, Datacenter, Datastore, vCenter, Switch, Router)
 * - Double-ring status alert indicators with warning/critical badging
 * - Native Pandora FMS Agent & Group auto-discovery with recursive tree hierarchy
 * - Universal pretty_text() sanitization (zero &#x20; artifacts)
 * - Interactive Cytoscape.js & Dagre hierarchical graph engine (Air-gapped ready)
 * - Segmented device filtering, live device search with auto pan/zoom
 * - Slide-in node inspector with live metrics and device role switcher
 * - 1-Click Reference SDDC/vSphere Demo topology loader matching user reference design
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Database & Core includes
$dir = __DIR__;
while ($dir !== '/' && $dir !== '.' && !file_exists($dir . '/includes/db-connection.php')) {
    $parent = dirname($dir);
    if ($parent === $dir) break;
    $dir = $parent;
}
if (file_exists($dir . '/includes/db-connection.php')) {
    require_once $dir . '/includes/db-connection.php';
} else {
    die("Central database connection library not found.");
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$user_id = $_SESSION['id_usuario'] ?? 0;
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';
if (empty($csrf_token)) {
    $csrf_token = bin2hex(random_bytes(32));
    $_SESSION['pfms_csrf_token'] = $csrf_token;
}
// Immediately release session lock to allow parallel non-blocking AJAX requests
session_write_close();

$script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
if (preg_match('#^(/.*?)/(custom|customize)/panel#', $script_dir, $matches)) {
    $PANDORA_BASE_URL = rtrim($matches[1], '/');
    $vendor_url = $PANDORA_BASE_URL . '/' . $matches[2] . '/panel/vendor';
} else if (preg_match('#^/(custom|customize)/panel#', $script_dir, $matches)) {
    $PANDORA_BASE_URL = '';
    $vendor_url = '/' . $matches[1] . '/panel/vendor';
} else {
    $PANDORA_BASE_URL = "/pandora_console"; 
    $vendor_url = "/pandora_console/custom/panel/vendor";
}
$pandora_base = $PANDORA_BASE_URL;

$is_standalone = isset($_GET['standalone']) || isset($_GET['embed']);
if (empty($user_id) && !$is_standalone) {
    if (!empty($_GET['api'])) {
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Session expired. Please refresh the page.']);
        exit;
    }
    header("Location: " . ($pandora_base ?: '') . "/index.php");
    exit;
}

$portal_page_param = $_GET['page'] ?? 'Dashboard/Topology-Network/topology-network.php';
$DASHBOARD_FILE = __DIR__ . '/topology_dashboards.json';

// Performance Helper: get agent ip column with static caching to prevent repeated DDL queries
function get_agent_ip_col(PDO $pdo): string {
    static $ipCol = null;
    if ($ipCol !== null) return $ipCol;
    $ipCol = 'direccion';
    try {
        $checkIp = $pdo->query("SHOW COLUMNS FROM tagente LIKE 'ip_address'");
        if ($checkIp && $checkIp->rowCount() > 0) $ipCol = 'ip_address';
    } catch (Throwable $e) {}
    return $ipCol;
}

// Helper to get all candidate storage paths
function get_topology_storage_candidates(): array {
    $temp_dir = dirname(__DIR__, 2) . '/temp';
    $sys_temp = sys_get_temp_dir();
    return array_values(array_unique([
        __DIR__ . '/topology_dashboards.json',
        $temp_dir . '/topology_dashboards.json',
        $sys_temp . '/pfms_topology_dashboards.json'
    ]));
}

// Helper: load dashboards (always load the NEWEST file with valid data, prioritizing files that contain user-created dashboards)
function load_topology_dashboards(string $file = ''): array {
    $candidates = get_topology_storage_candidates();
    if (!empty($file) && !in_array($file, $candidates)) {
        array_unshift($candidates, $file);
    }

    $best_score = -1;
    $best_data = null;

    foreach ($candidates as $f) {
        if (file_exists($f) && is_readable($f)) {
            $raw = @file_get_contents($f);
            if ($raw) {
                $data = json_decode((string)$raw, true);
                if (is_array($data) && !empty($data)) {
                    $mtime = @filemtime($f) ?: 0;
                    $has_custom = false;
                    if (count($data) > 1) {
                        $has_custom = true;
                    } else {
                        foreach ($data as $item) {
                            if (isset($item['id']) && $item['id'] !== 'core-infra-01') {
                                $has_custom = true;
                                break;
                            }
                        }
                    }
                    // Candidates with custom dashboards get high priority so stale git files never shadow them
                    $score = ($has_custom ? 10000000000 : 0) + $mtime;
                    if ($score > $best_score) {
                        $best_score = $score;
                        $best_data = $data;
                    }
                }
            }
        }
    }

    if ($best_data !== null) {
        return $best_data;
    }

    // Fallback: if no non-empty data found, return whatever is valid
    foreach ($candidates as $f) {
        if (file_exists($f) && is_readable($f)) {
            $raw = @file_get_contents($f);
            if ($raw) {
                $data = json_decode((string)$raw, true);
                if (is_array($data)) return $data;
            }
        }
    }

    return [];
}

// Helper: save dashboards (write to all writable locations so permissions never lose data)
function save_topology_dashboards(string $file, array $data): bool {
    $json = json_encode(array_values($data), JSON_PRETTY_PRINT);
    $candidates = get_topology_storage_candidates();
    if (!empty($file) && !in_array($file, $candidates)) {
        array_unshift($candidates, $file);
    }

    $saved_count = 0;

    foreach ($candidates as $f) {
        $dir = dirname($f);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if (file_exists($f) && !is_writable($f)) {
            @chmod($f, 0666);
        }

        $res = @file_put_contents($f, $json);
        if ($res !== false) {
            @touch($f); // Guarantee latest timestamp
            $saved_count++;
        }
    }

    return $saved_count > 0;
}

// =====================================================================
// DEVICE CLASSIFIER & SDDC REFERENCE DEMO ENGINE
// =====================================================================
class TopologyDeviceClassifier {
    const ROLE_VM          = 'vm';
    const ROLE_HYPERVISOR  = 'hypervisor';
    const ROLE_CLUSTER     = 'cluster';
    const ROLE_DATACENTER  = 'datacenter';
    const ROLE_STORAGE     = 'storage';
    const ROLE_VCENTER     = 'vcenter';
    const ROLE_SWITCH      = 'switch';
    const ROLE_ROUTER      = 'router';
    const ROLE_FIREWALL    = 'firewall';
    const ROLE_SERVER      = 'server';
    const ROLE_DATABASE    = 'database';
    const ROLE_WORKSTATION = 'workstation';

    public static function getRoles(): array {
        return [
            self::ROLE_VM => [
                'id' => self::ROLE_VM,
                'title' => 'Virtual Machine',
                'short_title' => 'VM',
                'category' => 'compute',
                'badge_color' => '#3b82f6',
                'rank' => 1,
                'icon_file' => 'vmware@os.svg'
            ],
            self::ROLE_HYPERVISOR => [
                'id' => self::ROLE_HYPERVISOR,
                'title' => 'Hypervisor Host',
                'short_title' => 'Host',
                'category' => 'compute',
                'badge_color' => '#1e293b',
                'rank' => 2,
                'icon_file' => 'vmware@os.svg'
            ],
            self::ROLE_CLUSTER => [
                'id' => self::ROLE_CLUSTER,
                'title' => 'Compute Cluster',
                'short_title' => 'Cluster',
                'category' => 'compute',
                'badge_color' => '#475569',
                'rank' => 3,
                'icon_file' => 'cluster@os.svg'
            ],
            self::ROLE_DATACENTER => [
                'id' => self::ROLE_DATACENTER,
                'title' => 'Datacenter',
                'short_title' => 'Datacenter',
                'category' => 'compute',
                'badge_color' => '#0f172a',
                'rank' => 4,
                'icon_file' => 'network-server@os.svg'
            ],
            self::ROLE_STORAGE => [
                'id' => self::ROLE_STORAGE,
                'title' => 'Storage Datastore',
                'short_title' => 'Storage',
                'category' => 'storage',
                'badge_color' => '#0284c7',
                'rank' => 2,
                'icon_file' => 'storage.svg'
            ],
            self::ROLE_VCENTER => [
                'id' => self::ROLE_VCENTER,
                'title' => 'Management Controller',
                'short_title' => 'Mgmt',
                'category' => 'management',
                'badge_color' => '#64748b',
                'rank' => 3,
                'icon_file' => 'vmware@os.svg'
            ],
            self::ROLE_SWITCH => [
                'id' => self::ROLE_SWITCH,
                'title' => 'Network Switch',
                'short_title' => 'Switch',
                'category' => 'network',
                'badge_color' => '#0d9488',
                'rank' => 3,
                'icon_file' => 'switch@os.svg'
            ],
            self::ROLE_ROUTER => [
                'id' => self::ROLE_ROUTER,
                'title' => 'Network Router',
                'short_title' => 'Router',
                'category' => 'network',
                'badge_color' => '#059669',
                'rank' => 4,
                'icon_file' => 'routers@os.svg'
            ],
            self::ROLE_FIREWALL => [
                'id' => self::ROLE_FIREWALL,
                'title' => 'Security Firewall',
                'short_title' => 'Firewall',
                'category' => 'network',
                'badge_color' => '#dc2626',
                'rank' => 5,
                'icon_file' => 'firewall@groups.svg'
            ],
            self::ROLE_DATABASE => [
                'id' => self::ROLE_DATABASE,
                'title' => 'Database Server',
                'short_title' => 'Database',
                'category' => 'compute',
                'badge_color' => '#7c3aed',
                'rank' => 2,
                'icon_file' => 'database@groups.svg'
            ],
            self::ROLE_WORKSTATION => [
                'id' => self::ROLE_WORKSTATION,
                'title' => 'Workstation / Laptop',
                'short_title' => 'PC',
                'category' => 'compute',
                'badge_color' => '#0284c7',
                'rank' => 1,
                'icon_file' => 'workstation@groups.svg'
            ],
            self::ROLE_SERVER => [
                'id' => self::ROLE_SERVER,
                'title' => 'Host Server',
                'short_title' => 'Server',
                'category' => 'compute',
                'badge_color' => '#334155',
                'rank' => 2,
                'icon_file' => 'network-server@os.svg'
            ]
        ];
    }

    public static function getIconUrl(string $base_url, array $agent, string $role): string {
        $imgDir = rtrim($base_url, '/') . '/images/';
        $roles = self::getRoles();

        // 1. Dedicated network infrastructure and hardware roles MUST always use their designated official icon.
        // This prevents SNMP routers and switches from showing generic equalizer/sound-wave icons (other-OS@os.svg).
        $infraRoles = [
            self::ROLE_ROUTER,
            self::ROLE_SWITCH,
            self::ROLE_FIREWALL,
            self::ROLE_STORAGE,
            self::ROLE_DATABASE,
            self::ROLE_VCENTER,
            self::ROLE_CLUSTER,
            self::ROLE_DATACENTER
        ];

        if (in_array($role, $infraRoles, true)) {
            $iconFile = $roles[$role]['icon_file'] ?? 'devices.svg';
            return $imgDir . $iconFile;
        }

        // 2. For compute/server/workstation/VM agents, check if there is a specific, known OS icon
        if (!empty($agent['os_icon'])) {
            $rawIcon = trim((string)$agent['os_icon']);
            // Exclude generic "Other" / placeholder icons that look like sound waves or generic devices
            $isGenericOs = preg_match('/(other|unknown|generic|default|sound)/i', $rawIcon);
            if (!$isGenericOs && preg_match('/^[a-zA-Z0-9_\-\.@]+\.(svg|png|gif|jpg)$/i', $rawIcon)) {
                return $imgDir . $rawIcon;
            }
        }

        // 3. Fallback to role icon
        $iconFile = $roles[$role]['icon_file'] ?? 'devices.svg';
        return $imgDir . $iconFile;
    }

    public static function classifyAgent(array $agent, ?string $manualOverride = null): string {
        if (!empty($manualOverride) && isset(self::getRoles()[$manualOverride])) {
            return $manualOverride;
        }

        $haystack = strtolower(implode(' ', [
            $agent['nombre'] ?? '',
            $agent['alias'] ?? '',
            $agent['comentarios'] ?? '',
            $agent['os'] ?? '',
            $agent['group_name'] ?? ''
        ]));

        if (preg_match('/(vcenter|vcsa|vmware vcenter|vcenter-server)/i', $haystack)) {
            return self::ROLE_VCENTER;
        }
        if (preg_match('/(cluster|mgmt-cluster|workload-cluster|esx-cluster)/i', $haystack)) {
            return self::ROLE_CLUSTER;
        }
        if (preg_match('/(datacenter|sddc|dc-|data-center)/i', $haystack)) {
            return self::ROLE_DATACENTER;
        }
        if (preg_match('/(datastore|vsan|san|nas|nfs|purestorage|storage|lun|truenas)/i', $haystack)) {
            return self::ROLE_STORAGE;
        }
        if (preg_match('/(db_|db-|database|mysql|postgres|mariadb|oracle|sql|mongodb|redis|onyxbdp|employeecase)/i', $haystack)) {
            return self::ROLE_DATABASE;
        }
        if (preg_match('/(laptop|notebook|dell 3450|desktop|workstation|pc|thinkpad|client-pc)/i', $haystack)) {
            return self::ROLE_WORKSTATION;
        }
        if (preg_match('/(esxi|hypervisor|vsphere host|proxmox|kvm host)/i', $haystack)) {
            return self::ROLE_HYPERVISOR;
        }
        if (preg_match('/(vm-|srv-vm|vhost|virtual machine|win-vm|docker|k8s)/i', $haystack)) {
            return self::ROLE_VM;
        }
        if (preg_match('/(^|[\-_\s])(firewall|forti|fortigate|palo|paloalto|palo-alto|pfsense|opnsense|checkpoint|sophos|asa|fw)([\-_\s0-9]|$)/i', $haystack)) {
            return self::ROLE_FIREWALL;
        }
        if (preg_match('/(^|[\-_\s])(rtr|router|gateway|gw|edge-router)([\-_\s0-9]|$)/i', $haystack) || preg_match('/(routeros|mikrotik.*router|cisco crs)/i', $haystack)) {
            return self::ROLE_ROUTER;
        }
        if (preg_match('/(^|[\-_\s])(sw|switch|mtk-sw|csw|dsw|asw|dist-sw|core-sw|acc-sw|leaf|spine)([\-_\s0-9]|$)/i', $haystack) || preg_match('/(cisco catalyst|nexus|arista|switchos|mikrotik.*sw)/i', $haystack)) {
            return self::ROLE_SWITCH;
        }

        return self::ROLE_SERVER;
    }

    public static function getReferenceDemoTopology(): array {
        $nodes = [
            [
                'id' => 'dc-ca-east-02',
                'name' => 'CA-EAST-02-SDDC',
                'role' => self::ROLE_DATACENTER,
                'category' => 'compute',
                'ip' => '10.200.0.1',
                'status' => 'normal',
                'alert_count' => 0,
                'os' => 'VMware vSphere Datacenter',
                'group' => 'Datacenter Infrastructure',
                'tier' => 1
            ],
            [
                'id' => 'vcenter-mgmt-01',
                'name' => 'sddc-vcenter-01',
                'role' => self::ROLE_VCENTER,
                'category' => 'management',
                'ip' => '10.200.0.10',
                'status' => 'normal',
                'alert_count' => 0,
                'os' => 'VMware vCenter Server 8.0',
                'group' => 'Management',
                'tier' => 2
            ],
            [
                'id' => 'cluster-prd-01',
                'name' => 'PRD-SDDC-MGMT-01',
                'role' => self::ROLE_CLUSTER,
                'category' => 'compute',
                'ip' => '10.200.0.15',
                'status' => 'normal',
                'alert_count' => 0,
                'os' => 'vSphere HA/DRS Cluster',
                'group' => 'Compute Clusters',
                'tier' => 2
            ],
            [
                'id' => 'esxi-blade-01',
                'name' => 'esxi-blade-01.corp',
                'role' => self::ROLE_HYPERVISOR,
                'category' => 'compute',
                'ip' => '10.200.1.11',
                'status' => 'normal',
                'alert_count' => 0,
                'os' => 'VMware ESXi 8.0u2',
                'group' => 'Compute Hosts',
                'tier' => 3
            ],
            [
                'id' => 'esxi-blade-02',
                'name' => 'esxi-blade-02.corp',
                'role' => self::ROLE_HYPERVISOR,
                'category' => 'compute',
                'ip' => '10.200.1.12',
                'status' => 'warning',
                'alert_count' => 1,
                'os' => 'VMware ESXi 8.0u2',
                'group' => 'Compute Hosts',
                'tier' => 3
            ],
            [
                'id' => 'esxi-blade-03',
                'name' => 'esxi-blade-03.corp',
                'role' => self::ROLE_HYPERVISOR,
                'category' => 'compute',
                'ip' => '10.200.1.13',
                'status' => 'critical',
                'alert_count' => 3,
                'os' => 'VMware ESXi 8.0u2',
                'group' => 'Compute Hosts',
                'tier' => 3
            ],
            [
                'id' => 'esxi-blade-04',
                'name' => 'esxi-blade-04.corp',
                'role' => self::ROLE_HYPERVISOR,
                'category' => 'compute',
                'ip' => '10.200.1.14',
                'status' => 'normal',
                'alert_count' => 0,
                'os' => 'VMware ESXi 8.0u2',
                'group' => 'Compute Hosts',
                'tier' => 3
            ],
            [
                'id' => 'ds-vsan-01',
                'name' => 'vsanDatastore-01',
                'role' => self::ROLE_STORAGE,
                'category' => 'storage',
                'ip' => '10.200.3.5',
                'status' => 'normal',
                'alert_count' => 0,
                'os' => 'VMware vSAN Express Architecture',
                'group' => 'Datastores',
                'tier' => 3
            ],
            [
                'id' => 'ds-nvme-01',
                'name' => 'pure-nvme-lun01',
                'role' => self::ROLE_STORAGE,
                'category' => 'storage',
                'ip' => '10.200.3.10',
                'status' => 'normal',
                'alert_count' => 0,
                'os' => 'Pure Storage FlashArray NVMe-oF',
                'group' => 'Datastores',
                'tier' => 3
            ],
            [
                'id' => 'vm-app-01',
                'name' => 'prd-app-srv01',
                'role' => self::ROLE_VM,
                'category' => 'compute',
                'ip' => '10.200.10.21',
                'status' => 'normal',
                'alert_count' => 0,
                'os' => 'Red Hat Enterprise Linux 9.2',
                'group' => 'Workload VMs',
                'tier' => 4
            ],
            [
                'id' => 'vm-db-01',
                'name' => 'prd-db-primary',
                'role' => self::ROLE_VM,
                'category' => 'compute',
                'ip' => '10.200.10.25',
                'status' => 'critical',
                'alert_count' => 2,
                'os' => 'Ubuntu Linux 22.04 LTS',
                'group' => 'Workload VMs',
                'tier' => 4
            ],
            [
                'id' => 'edge-gateway-01',
                'name' => 'core-edge-gateway',
                'role' => self::ROLE_ROUTER,
                'category' => 'network',
                'ip' => '10.200.0.254',
                'status' => 'normal',
                'alert_count' => 0,
                'os' => 'VMware NSX Edge Router',
                'group' => 'Network Infrastructure',
                'tier' => 1
            ],
            [
                'id' => 'tor-leaf-01',
                'name' => 'tor-switch-leaf01',
                'role' => self::ROLE_SWITCH,
                'category' => 'network',
                'ip' => '10.200.2.1',
                'status' => 'normal',
                'alert_count' => 0,
                'os' => 'Cisco Nexus 9300',
                'group' => 'Network Infrastructure',
                'tier' => 2
            ],
            [
                'id' => 'tor-leaf-02',
                'name' => 'tor-switch-leaf02',
                'role' => self::ROLE_SWITCH,
                'category' => 'network',
                'ip' => '10.200.2.2',
                'status' => 'normal',
                'alert_count' => 0,
                'os' => 'Cisco Nexus 9300',
                'group' => 'Network Infrastructure',
                'tier' => 2
            ]
        ];

        $edges = [
            ['source' => 'edge-gateway-01', 'target' => 'dc-ca-east-02', 'status' => 'active'],
            ['source' => 'dc-ca-east-02', 'target' => 'vcenter-mgmt-01', 'status' => 'active'],
            ['source' => 'dc-ca-east-02', 'target' => 'cluster-prd-01', 'status' => 'active'],
            ['source' => 'cluster-prd-01', 'target' => 'esxi-blade-01', 'status' => 'active'],
            ['source' => 'cluster-prd-01', 'target' => 'esxi-blade-02', 'status' => 'active'],
            ['source' => 'cluster-prd-01', 'target' => 'esxi-blade-03', 'status' => 'critical'],
            ['source' => 'cluster-prd-01', 'target' => 'esxi-blade-04', 'status' => 'active'],
            ['source' => 'esxi-blade-01', 'target' => 'ds-vsan-01', 'status' => 'active'],
            ['source' => 'esxi-blade-02', 'target' => 'ds-vsan-01', 'status' => 'active'],
            ['source' => 'esxi-blade-03', 'target' => 'ds-vsan-01', 'status' => 'warning'],
            ['source' => 'esxi-blade-04', 'target' => 'ds-nvme-01', 'status' => 'active'],
            ['source' => 'esxi-blade-01', 'target' => 'vm-app-01', 'status' => 'active'],
            ['source' => 'esxi-blade-03', 'target' => 'vm-db-01', 'status' => 'critical'],
            ['source' => 'edge-gateway-01', 'target' => 'tor-leaf-01', 'status' => 'active'],
            ['source' => 'edge-gateway-01', 'target' => 'tor-leaf-02', 'status' => 'active'],
            ['source' => 'tor-leaf-01', 'target' => 'esxi-blade-01', 'status' => 'active'],
            ['source' => 'tor-leaf-02', 'target' => 'esxi-blade-02', 'status' => 'active']
        ];

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}

// =====================================================================
// AJAX BACKEND API DISPATCHER
// =====================================================================
$api = $_GET['api'] ?? '';

if (!empty($api)) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    http_response_code(200);

    // 1. API: LIST DASHBOARDS
    if ($api === 'list_dashboards') {
        $dashboards = load_topology_dashboards($DASHBOARD_FILE);
        echo json_encode(['ok' => true, 'dashboards' => array_values($dashboards)]);
        exit;
    }

    // 2. API: SAVE DASHBOARD (Create / Update)
    if ($api === 'save_dashboard') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
        if (!empty($csrf_token) && !empty($client_token) && $client_token !== $csrf_token) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh page.']);
            exit;
        }

        $id = trim((string)($input['id'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $map_type = trim((string)($input['map_type'] ?? 'blank')); // 'blank' or 'group'
        $group_id = (int)($input['group_id'] ?? 0);
        $group_name = trim((string)($input['group_name'] ?? ($map_type === 'blank' ? 'Custom Devices' : 'All Agent Groups')));
        $layout = trim((string)($input['layout'] ?? 'dagre'));
        $device_ids = isset($input['device_ids']) && is_array($input['device_ids']) ? array_values(array_unique(array_map('intval', $input['device_ids']))) : [];

        if (empty($name)) {
            echo json_encode(['ok' => false, 'error' => 'Dashboard Name is required.']);
            exit;
        }

        $dashboards = load_topology_dashboards($DASHBOARD_FILE);
        $now = date('Y-m-d H:i:s');

        if (empty($id)) {
            $id = 'topo-' . bin2hex(random_bytes(6));
            $dashboards[] = [
                'id' => $id,
                'name' => pretty_text($name),
                'description' => pretty_text($description),
                'map_type' => $map_type,
                'group_id' => $group_id,
                'group_name' => pretty_text($group_name),
                'layout' => $layout,
                'device_ids' => $device_ids,
                'is_demo' => false,
                'node_count' => count($device_ids),
                'created_at' => $now,
                'updated_at' => $now
            ];
        } else {
            $found = false;
            foreach ($dashboards as &$d) {
                if ($d['id'] === $id) {
                    $d['name'] = pretty_text($name);
                    $d['description'] = pretty_text($description);
                    if (isset($input['map_type'])) $d['map_type'] = $map_type;
                    $d['group_id'] = $group_id;
                    $d['group_name'] = pretty_text($group_name);
                    $d['layout'] = $layout;
                    if (isset($input['device_ids'])) {
                        $d['device_ids'] = $device_ids;
                        $d['node_count'] = count($device_ids);
                    } else {
                        $d['node_count'] = isset($d['device_ids']) && is_array($d['device_ids']) ? count($d['device_ids']) : 0;
                    }
                    $d['is_demo'] = false;
                    $d['updated_at'] = $now;
                    $found = true;
                    break;
                }
            }
            unset($d); // Prevent PHP reference retention
            if (!$found) {
                $dashboards[] = [
                    'id' => $id,
                    'name' => pretty_text($name),
                    'description' => pretty_text($description),
                    'map_type' => $map_type,
                    'group_id' => $group_id,
                    'group_name' => pretty_text($group_name),
                    'layout' => $layout,
                    'device_ids' => $device_ids,
                    'is_demo' => false,
                    'node_count' => count($device_ids),
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }
        }

        $saved = save_topology_dashboards($DASHBOARD_FILE, $dashboards);
        if (!$saved) {
            $last_err = error_get_last();
            $err_msg = $last_err ? $last_err['message'] : 'Permission denied writing to ' . basename($DASHBOARD_FILE);
            echo json_encode(['ok' => false, 'error' => 'Failed to save dashboard: ' . $err_msg]);
            exit;
        }
        echo json_encode(['ok' => true, 'id' => $id, 'dashboards' => array_values($dashboards)]);
        exit;
    }

    // 3. API: SAVE DASHBOARD DEVICES (Add / Remove devices on canvas)
    if ($api === 'save_dashboard_devices') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
        if (!empty($csrf_token) && !empty($client_token) && $client_token !== $csrf_token) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token.']);
            exit;
        }

        $id = trim((string)($input['dashboard_id'] ?? ''));
        $device_ids = isset($input['device_ids']) && is_array($input['device_ids']) ? array_values(array_unique(array_map('intval', $input['device_ids']))) : [];

        $dashboards = load_topology_dashboards($DASHBOARD_FILE);
        $found = false;
        foreach ($dashboards as &$d) {
            if ($d['id'] === $id) {
                $d['map_type'] = 'blank';
                $d['device_ids'] = $device_ids;
                $d['node_count'] = count($device_ids);
                $d['updated_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        unset($d); // Prevent PHP reference retention
        if ($found) {
            save_topology_dashboards($DASHBOARD_FILE, $dashboards);
            echo json_encode(['ok' => true, 'device_ids' => $device_ids, 'count' => count($device_ids)]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Dashboard not found']);
        }
        exit;
    }

    // API: SAVE TOPOLOGY EDGE (Connect 2 devices with interface link)
    if ($api === 'save_topology_edge') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
        if (!empty($csrf_token) && !empty($client_token) && $client_token !== $csrf_token) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token.']);
            exit;
        }

        $dash_id = trim((string)($input['dashboard_id'] ?? ''));
        $source = trim((string)($input['source'] ?? ''));
        $target = trim((string)($input['target'] ?? ''));
        $label = trim((string)($input['label'] ?? ''));
        $status = trim((string)($input['status'] ?? 'active'));
        
        $source_interface = trim((string)($input['source_interface'] ?? ''));
        $source_module_id = (int)($input['source_module_id'] ?? 0);
        $source_status = isset($input['source_status']) ? (int)$input['source_status'] : 0;
        
        $target_interface = trim((string)($input['target_interface'] ?? ''));
        $target_module_id = (int)($input['target_module_id'] ?? 0);
        $target_status = isset($input['target_status']) ? (int)$input['target_status'] : 0;

        if (empty($dash_id) || empty($source) || empty($target) || $source === $target) {
            echo json_encode(['ok' => false, 'error' => 'Invalid source or target node.']);
            exit;
        }

        $dashboards = load_topology_dashboards($DASHBOARD_FILE);
        $found = false;
        $new_edge = null;

        foreach ($dashboards as &$d) {
            if ($d['id'] === $dash_id) {
                if (!isset($d['custom_edges']) || !is_array($d['custom_edges'])) {
                    $d['custom_edges'] = [];
                }
                
                $edge_id = 'custom-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $source) . '-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $target);
                
                // If edge already exists in either direction, update it
                $existing_idx = -1;
                foreach ($d['custom_edges'] as $idx => $ce) {
                    if (($ce['source'] === $source && $ce['target'] === $target) ||
                        ($ce['source'] === $target && $ce['target'] === $source)) {
                        $existing_idx = $idx;
                        $edge_id = $ce['id'] ?? $edge_id;
                        break;
                    }
                }

                $new_edge = [
                    'id' => $edge_id,
                    'source' => $source,
                    'target' => $target,
                    'label' => pretty_text($label),
                    'status' => in_array($status, ['active', 'warning', 'critical']) ? $status : 'active',
                    'source_interface' => pretty_text($source_interface),
                    'source_module_id' => $source_module_id,
                    'source_status' => $source_status,
                    'target_interface' => pretty_text($target_interface),
                    'target_module_id' => $target_module_id,
                    'target_status' => $target_status,
                    'is_custom' => true,
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                if ($existing_idx >= 0) {
                    $d['custom_edges'][$existing_idx] = $new_edge;
                } else {
                    $new_edge['created_at'] = date('Y-m-d H:i:s');
                    $d['custom_edges'][] = $new_edge;
                }

                // If this edge was previously recorded in deleted_edges, un-delete it
                if (!empty($d['deleted_edges']) && is_array($d['deleted_edges'])) {
                    $d['deleted_edges'] = array_values(array_filter($d['deleted_edges'], function($k) use ($source, $target) {
                        return $k !== "$source->$target" && $k !== "$target->$source";
                    }));
                }

                $d['updated_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        unset($d);

        if ($found) {
            save_topology_dashboards($DASHBOARD_FILE, $dashboards);
            echo json_encode(['ok' => true, 'edge' => $new_edge]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Dashboard not found']);
        }
        exit;
    }

    // API: DELETE TOPOLOGY EDGE (Disconnect 2 devices)
    if ($api === 'delete_topology_edge') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
        if (!empty($csrf_token) && !empty($client_token) && $client_token !== $csrf_token) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token.']);
            exit;
        }

        $dash_id = trim((string)($input['dashboard_id'] ?? ''));
        $source = trim((string)($input['source'] ?? ''));
        $target = trim((string)($input['target'] ?? ''));
        $edge_id = trim((string)($input['edge_id'] ?? ''));

        if (empty($dash_id)) {
            echo json_encode(['ok' => false, 'error' => 'Dashboard ID required']);
            exit;
        }

        $dashboards = load_topology_dashboards($DASHBOARD_FILE);
        $found = false;

        foreach ($dashboards as &$d) {
            if ($d['id'] === $dash_id) {
                if (!isset($d['custom_edges']) || !is_array($d['custom_edges'])) {
                    $d['custom_edges'] = [];
                }
                if (!isset($d['deleted_edges']) || !is_array($d['deleted_edges'])) {
                    $d['deleted_edges'] = [];
                }

                // Filter out matching custom edge
                $d['custom_edges'] = array_values(array_filter($d['custom_edges'], function($ce) use ($source, $target, $edge_id) {
                    if (!empty($edge_id) && ($ce['id'] ?? '') === $edge_id) return false;
                    if (!empty($source) && !empty($target)) {
                        if (($ce['source'] === $source && $ce['target'] === $target) ||
                            ($ce['source'] === $target && $ce['target'] === $source)) {
                            return false;
                        }
                    }
                    return true;
                }));

                // Record in deleted_edges in case it was a parent hierarchy link
                if (!empty($source) && !empty($target)) {
                    $d['deleted_edges'][] = "$source->$target";
                    $d['deleted_edges'][] = "$target->$source";
                    $d['deleted_edges'] = array_values(array_unique($d['deleted_edges']));
                }

                $d['updated_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        unset($d);

        if ($found) {
            save_topology_dashboards($DASHBOARD_FILE, $dashboards);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Dashboard not found']);
        }
        exit;
    }

    // API: SAVE DEVICE ROLE OVERRIDE (Customize icon/role per device)
    if ($api === 'save_device_role') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
        if (!empty($csrf_token) && !empty($client_token) && $client_token !== $csrf_token) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token.']);
            exit;
        }

        $dash_id = trim((string)($input['dashboard_id'] ?? ''));
        $agent_id = (int)($input['agent_id'] ?? 0);
        $role = trim((string)($input['role'] ?? ''));

        if (empty($dash_id) || $agent_id <= 0 || empty($role)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
            exit;
        }

        $roles = TopologyDeviceClassifier::getRoles();
        if (!isset($roles[$role])) {
            echo json_encode(['ok' => false, 'error' => 'Unknown role']);
            exit;
        }

        $dashboards = load_topology_dashboards($DASHBOARD_FILE);
        $found = false;

        foreach ($dashboards as &$d) {
            if ($d['id'] === $dash_id) {
                if (!isset($d['role_overrides']) || !is_array($d['role_overrides'])) {
                    $d['role_overrides'] = [];
                }
                $d['role_overrides'][$agent_id] = $role;
                $d['updated_at'] = date('Y-m-d H:i:s');
                $found = true;
                break;
            }
        }
        unset($d);

        if ($found) {
            save_topology_dashboards($DASHBOARD_FILE, $dashboards);
            $dummyAgent = ['id_agente' => $agent_id, 'os_icon' => ''];
            $newIconUrl = TopologyDeviceClassifier::getIconUrl($PANDORA_BASE_URL, $dummyAgent, $role);
            echo json_encode([
                'ok' => true,
                'agent_id' => $agent_id,
                'role' => $role,
                'category' => $roles[$role]['category'],
                'icon_url' => $newIconUrl
            ]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Dashboard not found']);
        }
        exit;
    }

    // API: GET AGENT INTERFACE MODULES (Specifically ifOperStatus, ifAdmin, and network interfaces)
    if ($api === 'get_agent_interfaces') {
        $id_agent_raw = trim((string)($_GET['id_agent'] ?? $_POST['id_agent'] ?? ''));
        $parsed = parse_node_id($id_agent_raw);
        $node = $parsed['node'] ?: 'primary';
        $id_agent = (int)$parsed['id'];

        if ($id_agent <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid Agent ID']);
            exit;
        }

        global $custom_pdos;
        $active_pdo = ($node === 'primary') ? $pdo : ($custom_pdos[$node] ?? $pdo);

        try {
            // First priority: Query modules matching network interface patterns (ifOperStatus, ifAdminStatus, ifAdmin)
            $sql = "SELECT m.id_agente_modulo AS id, m.nombre AS name, e.estado, e.datos, m.unit
                    FROM tagente_modulo m
                    LEFT JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                    WHERE m.id_agente = ? 
                      AND (m.nombre LIKE '%ifOperStatus%' OR m.nombre LIKE '%ifAdminStatus%' OR m.nombre LIKE '%ifAdmin%' OR m.nombre LIKE '%ifOper%')
                      AND m.disabled = 0
                    ORDER BY m.nombre ASC";
            $stmt = $active_pdo->prepare($sql);
            $stmt->execute([$id_agent]);
            $mods = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fallback: If no modules with ifOperStatus/ifAdminStatus found, fetch active status/network modules
            if (empty($mods)) {
                $sqlFallback = "SELECT m.id_agente_modulo AS id, m.nombre AS name, e.estado, e.datos, m.unit
                                FROM tagente_modulo m
                                LEFT JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                                WHERE m.id_agente = ? AND m.disabled = 0
                                ORDER BY m.nombre ASC LIMIT 100";
                $stmtFb = $active_pdo->prepare($sqlFallback);
                $stmtFb->execute([$id_agent]);
                $mods = $stmtFb->fetchAll(PDO::FETCH_ASSOC);
            }

            $interfaces = [];
            foreach ($mods as $m) {
                $rawName = $m['name'];
                $cleanTextName = pretty_text($rawName);
                $cleanPortName = str_ireplace(
                    ['ifOperStatus_', '_ifOperStatus', 'ifOperStatus', 'ifAdminStatus_', '_ifAdminStatus', 'ifAdminStatus', 'ifAdmin_', '_ifAdmin', 'ifAdmin'],
                    '',
                    $cleanTextName
                );
                $cleanPortName = trim($cleanPortName);
                if (empty($cleanPortName)) $cleanPortName = $cleanTextName;

                $estadoVal = isset($m['estado']) ? (int)$m['estado'] : 0;
                // estado: 0 = Normal/UP (green), 1 = Critical/DOWN (red), 2 = Warning (yellow), 3 = Unknown
                $statusStr = ($estadoVal === 0) ? 'up' : (($estadoVal === 1) ? 'down' : 'warning');

                $interfaces[] = [
                    'id' => (int)$m['id'],
                    'name' => $cleanTextName,
                    'clean_port' => $cleanPortName,
                    'estado' => $estadoVal,
                    'status_str' => $statusStr,
                    'datos' => pretty_text($m['datos'] ?? '')
                ];
            }

            echo json_encode(['ok' => true, 'interfaces' => $interfaces]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 4. API: GET AVAILABLE AGENTS FOR DEVICE PICKER
    if ($api === 'get_available_agents') {
        try {
            $ipCol = get_agent_ip_col($pdo);

            $sql = "SELECT a.id_agente, a.nombre, a.alias, a.$ipCol AS ip, 
                           os.name AS os, os.icon_name AS os_icon, a.id_grupo, a.id_parent, 
                           COALESCE(g.nombre, 'Unknown') AS group_name
                    FROM tagente a
                    LEFT JOIN tconfig_os os ON a.id_os = os.id_os
                    LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                    WHERE a.disabled = 0
                    ORDER BY a.nombre ASC";
            $stmt = $pdo->query($sql);
            $agents = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $list = [];
            foreach ($agents as $a) {
                $role = TopologyDeviceClassifier::classifyAgent($a);
                $icon_url = TopologyDeviceClassifier::getIconUrl($PANDORA_BASE_URL, $a, $role);
                $list[] = [
                    'id' => (int)$a['id_agente'],
                    'name' => pretty_text($a['alias'] ?: $a['nombre']),
                    'raw_name' => pretty_text($a['nombre']),
                    'ip' => pretty_text($a['ip'] ?: '-'),
                    'role' => $role,
                    'group_id' => (int)$a['id_grupo'],
                    'group_name' => pretty_text($a['group_name']),
                    'os' => pretty_text($a['os'] ?: 'Unknown'),
                    'icon_url' => $icon_url
                ];
            }
            echo json_encode(['ok' => true, 'agents' => $list]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 5. API: DELETE DASHBOARD
    if ($api === 'delete_dashboard') {
        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
        if (!empty($csrf_token) && !empty($client_token) && $client_token !== $csrf_token) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token.']);
            exit;
        }

        $id = trim((string)($_GET['id'] ?? $input['id'] ?? ''));
        $dashboards = load_topology_dashboards($DASHBOARD_FILE);
        $dashboards = array_values(array_filter($dashboards, fn($d) => $d['id'] !== $id));
        $saved = save_topology_dashboards($DASHBOARD_FILE, $dashboards);
        echo json_encode(['ok' => $saved, 'dashboards' => $dashboards]);
        exit;
    }

    // 4. API: GET AGENT GROUPS (Hierarchical Tree)
    if ($api === 'get_groups') {
        try {
            $stmt = $pdo->query("SELECT id_grupo AS id, nombre AS name, parent FROM tgrupo ORDER BY nombre ASC");
            $all_groups = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            // Count agents per group
            $stmt_cnt = $pdo->query("SELECT id_grupo, COUNT(*) as cnt FROM tagente WHERE disabled = 0 GROUP BY id_grupo");
            $counts = [];
            if ($stmt_cnt) {
                while ($r = $stmt_cnt->fetch(PDO::FETCH_ASSOC)) {
                    $counts[(int)$r['id_grupo']] = (int)$r['cnt'];
                }
            }

            $by_parent = [];
            foreach ($all_groups as $g) {
                $p = (int)($g['parent'] ?? 0);
                $by_parent[$p][] = $g;
            }

            $formatted = [];
            $buildTree = function($parentId, $depth) use (&$buildTree, &$by_parent, &$formatted, &$counts) {
                if (empty($by_parent[$parentId])) return;
                foreach ($by_parent[$parentId] as $g) {
                    $gid = (int)$g['id'];
                    $cleanName = pretty_text($g['name']);
                    $prefix = $depth > 0 ? str_repeat('── ', $depth) . '└─ ' : '';
                    $agent_cnt = $counts[$gid] ?? 0;
                    $formatted[] = [
                        'id' => $gid,
                        'name' => $cleanName,
                        'display_name' => $prefix . $cleanName . ' (' . $agent_cnt . ' agents)',
                        'parent' => (int)$g['parent'],
                        'agent_count' => $agent_cnt,
                        'depth' => $depth
                    ];
                    $buildTree($gid, $depth + 1);
                }
            };

            $buildTree(0, 0);

            if (empty($formatted) && !empty($all_groups)) {
                foreach ($all_groups as $g) {
                    $gid = (int)$g['id'];
                    $cleanName = pretty_text($g['name']);
                    $formatted[] = [
                        'id' => $gid,
                        'name' => $cleanName,
                        'display_name' => $cleanName . ' (' . ($counts[$gid] ?? 0) . ' agents)',
                        'parent' => 0,
                        'agent_count' => $counts[$gid] ?? 0,
                        'depth' => 0
                    ];
                }
            }

            echo json_encode(['ok' => true, 'groups' => $formatted]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 5. API: LOAD REFERENCE DEMO
    if ($api === 'load_demo') {
        $demo = TopologyDeviceClassifier::getReferenceDemoTopology();
        echo json_encode([
            'ok' => true,
            'mode' => 'demo',
            'title' => 'CA-EAST-02-SDDC Reference Topology',
            'nodes' => $demo['nodes'],
            'edges' => $demo['edges'],
            'stats' => [
                'total_nodes' => count($demo['nodes']),
                'total_edges' => count($demo['edges']),
                'critical' => count(array_filter($demo['nodes'], fn($n) => $n['status'] === 'critical')),
                'warning' => count(array_filter($demo['nodes'], fn($n) => $n['status'] === 'warning')),
                'normal' => count(array_filter($demo['nodes'], fn($n) => $n['status'] === 'normal'))
            ]
        ]);
        exit;
    }

    // 6. API: GET TOPOLOGY DATA (Main Engine)
    if ($api === 'get_topology_data') {
        $dash_id = trim((string)($_GET['dashboard_id'] ?? ''));
        $req_group_id = isset($_GET['group_id']) && $_GET['group_id'] !== '' ? (int)$_GET['group_id'] : null;
        $search = trim((string)($_GET['search'] ?? ''));

        $current_dash = null;
        $map_type = 'blank';
        $device_ids = [];
        $dash_group_id = null;

        if (!empty($dash_id)) {
            $dashboards = load_topology_dashboards($DASHBOARD_FILE);
            foreach ($dashboards as $d) {
                if ($d['id'] === $dash_id) {
                    $current_dash = $d;
                    $map_type = $d['map_type'] ?? 'blank';
                    $device_ids = isset($d['device_ids']) && is_array($d['device_ids']) ? array_map('intval', $d['device_ids']) : [];
                    if (!empty($d['group_id'])) {
                        $dash_group_id = (int)$d['group_id'];
                    }
                    break;
                }
            }
        }

        // Active group filter: if user requested a specific group from canvas dropdown, use it; else fallback to dashboard's default
        $group_id = $req_group_id !== null ? $req_group_id : $dash_group_id;

        // If this is a blank-canvas dashboard with no devices yet, return clean empty canvas
        if ($map_type === 'blank' && empty($device_ids) && ($req_group_id === null || $req_group_id === 0)) {
            echo json_encode([
                'ok' => true,
                'mode' => 'blank',
                'title' => $current_dash ? pretty_text($current_dash['name']) : 'Infrastructure Topology',
                'notice' => 'Canvas is currently empty. Click [+ Add Devices] to add devices to this topology.',
                'nodes' => [],
                'edges' => [],
                'stats' => [
                    'total_nodes' => 0,
                    'total_edges' => 0,
                    'critical' => 0,
                    'warning' => 0,
                    'normal' => 0
                ]
            ]);
            exit;
        }

        try {
            // Check IP Column dynamically using static cache
            $ipCol = get_agent_ip_col($pdo);

            // Expand sub-groups recursively if group_id is given
            $target_group_ids = [];
            if ($group_id !== null && $group_id > 0) {
                $st_all_g = $pdo->query("SELECT id_grupo, parent FROM tgrupo");
                $g_rows = $st_all_g ? $st_all_g->fetchAll(PDO::FETCH_ASSOC) : [];
                $g_map = [];
                foreach ($g_rows as $r) {
                    $g_map[(int)$r['parent']][] = (int)$r['id_grupo'];
                }
                $expandGroups = function($gid) use (&$expandGroups, &$g_map, &$target_group_ids) {
                    $target_group_ids[] = $gid;
                    if (!empty($g_map[$gid])) {
                        foreach ($g_map[$gid] as $child) {
                            $expandGroups($child);
                        }
                    }
                };
                $expandGroups($group_id);
            }

            // Safe Query on tagente
            $sql = "SELECT a.id_agente, a.nombre, a.alias, a.$ipCol AS ip, a.comentarios, 
                           os.name AS os, os.icon_name AS os_icon, a.id_grupo, a.id_parent, 
                           COALESCE(g.nombre, 'Unknown') AS group_name
                    FROM tagente a
                    LEFT JOIN tconfig_os os ON a.id_os = os.id_os
                    LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                    WHERE a.disabled = 0";
            $params = [];

            // If blank canvas with specific picked devices (and no canvas-level group dropdown override)
            if ($map_type === 'blank' && !empty($device_ids) && ($req_group_id === null || $req_group_id === 0)) {
                $in_placeholders = implode(',', array_fill(0, count($device_ids), '?'));
                $sql .= " AND a.id_agente IN ($in_placeholders)";
                $params = array_merge($params, $device_ids);
            } elseif (!empty($target_group_ids)) {
                $in_placeholders = implode(',', array_fill(0, count($target_group_ids), '?'));
                $sql .= " AND a.id_grupo IN ($in_placeholders)";
                $params = array_merge($params, $target_group_ids);
            }

            if (!empty($search)) {
                $sql .= " AND (a.nombre LIKE ? OR a.alias LIKE ? OR a.$ipCol LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $sql .= " ORDER BY a.id_parent ASC, a.nombre ASC LIMIT 300";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // If database has no active agents in this group/scope
            if (empty($agents)) {
                echo json_encode([
                    'ok' => true,
                    'mode' => 'real',
                    'notice' => 'No active agents found in selected scope.',
                    'nodes' => [],
                    'edges' => [],
                    'stats' => [
                        'total_nodes' => 0,
                        'total_edges' => 0,
                        'critical' => 0,
                        'warning' => 0,
                        'normal' => 0
                    ]
                ]);
                exit;
            }

            // Fetch alert statuses efficiently using index on tagente_modulo + PRIMARY KEY on tagente_estado
            $agent_ids = array_map(fn($a) => (int)$a['id_agente'], $agents);
            $statuses = [];
            if (!empty($agent_ids)) {
                $in_ids = implode(',', $agent_ids);
                try {
                    $sql_status = "SELECT m.id_agente, MAX(e.estado) as max_state, COUNT(*) as mod_count
                                   FROM tagente_modulo m
                                   INNER JOIN tagente_estado e ON m.id_agente_modulo = e.id_agente_modulo
                                   WHERE m.id_agente IN ($in_ids) AND m.disabled = 0
                                   GROUP BY m.id_agente";
                    $st_alerts = $pdo->query($sql_status);
                    if ($st_alerts) {
                        while ($row = $st_alerts->fetch(PDO::FETCH_ASSOC)) {
                            $statuses[(int)$row['id_agente']] = [
                                'state' => (int)$row['max_state'],
                                'count' => (int)$row['mod_count']
                            ];
                        }
                    }
                } catch (Throwable $e) {
                    try {
                        $st_alerts = $pdo->query("SELECT id_agente, MAX(estado) as max_state, COUNT(*) as mod_count FROM tagente_estado WHERE id_agente IN ($in_ids) GROUP BY id_agente");
                        if ($st_alerts) {
                            while ($row = $st_alerts->fetch(PDO::FETCH_ASSOC)) {
                                $statuses[(int)$row['id_agente']] = [
                                    'state' => (int)$row['max_state'],
                                    'count' => (int)$row['mod_count']
                                ];
                            }
                        }
                    } catch (Throwable $e2) {}
                }
            }

            $nodes = [];
            $agent_map = [];
            $role_meta = TopologyDeviceClassifier::getRoles();
            $role_overrides = isset($current_dash['role_overrides']) && is_array($current_dash['role_overrides']) ? $current_dash['role_overrides'] : [];
            $deleted_edges = isset($current_dash['deleted_edges']) && is_array($current_dash['deleted_edges']) ? array_flip($current_dash['deleted_edges']) : [];
            $custom_edges = isset($current_dash['custom_edges']) && is_array($current_dash['custom_edges']) ? $current_dash['custom_edges'] : [];

            foreach ($agents as $a) {
                $aid = (int)$a['id_agente'];
                $node_id = 'agent-' . $aid;
                $manualRole = $role_overrides[$aid] ?? null;
                $role = TopologyDeviceClassifier::classifyAgent($a, $manualRole);
                $icon_url = TopologyDeviceClassifier::getIconUrl($PANDORA_BASE_URL, $a, $role);
                $stInfo = $statuses[$aid] ?? ['state' => 0, 'count' => 0];

                $statusText = 'normal';
                $alertCount = 0;
                if ($stInfo['state'] == 2) {
                    $statusText = 'critical';
                    $alertCount = 2;
                } elseif ($stInfo['state'] == 1) {
                    $statusText = 'warning';
                    $alertCount = 1;
                }

                $meta = $role_meta[$role] ?? $role_meta[TopologyDeviceClassifier::ROLE_SERVER];

                $nodes[] = [
                    'id' => $node_id,
                    'agent_id' => $aid,
                    'name' => pretty_text($a['alias'] ?: $a['nombre']),
                    'raw_name' => pretty_text($a['nombre']),
                    'role' => $role,
                    'category' => $meta['category'],
                    'icon_url' => $icon_url,
                    'ip' => pretty_text($a['ip'] ?: '0.0.0.0'),
                    'status' => $statusText,
                    'alert_count' => $alertCount,
                    'os' => pretty_text($a['os'] ?: 'Unknown OS'),
                    'group' => pretty_text($a['group_name']),
                    'parent_id' => (int)$a['id_parent'],
                    'tier' => $meta['rank']
                ];
                $agent_map[$aid] = $node_id;
            }

            $node_id_set = array_fill_keys(array_column($nodes, 'id'), true);

            // Derive edges from real Pandora FMS agent parent hierarchy + user custom connections
            $edges = [];
            $edge_keys = [];

            // 1. Real parent hierarchy links from tagente.id_parent (unless user explicitly deleted)
            foreach ($nodes as $n) {
                $pid = $n['parent_id'];
                if ($pid > 0 && isset($agent_map[$pid])) {
                    $src = $agent_map[$pid];
                    $tgt = $n['id'];
                    $k1 = $src . '->' . $tgt;
                    $k2 = $tgt . '->' . $src;
                    if (!isset($deleted_edges[$k1]) && !isset($deleted_edges[$k2])) {
                        if (!isset($edge_keys[$k1]) && !isset($edge_keys[$k2])) {
                            $edge_keys[$k1] = true;
                            $edges[] = [
                                'id' => 'parent-' . $src . '-' . $tgt,
                                'source' => $src,
                                'target' => $tgt,
                                'label' => '',
                                'status' => $n['status'] === 'critical' ? 'critical' : ($n['status'] === 'warning' ? 'warning' : 'active'),
                                'is_custom' => false
                            ];
                        }
                    }
                }
            }

            // Look up live module statuses for interface links
            $mod_ids_to_check = [];
            foreach ($custom_edges as $ce) {
                if (!empty($ce['source_module_id'])) $mod_ids_to_check[] = (int)$ce['source_module_id'];
                if (!empty($ce['target_module_id'])) $mod_ids_to_check[] = (int)$ce['target_module_id'];
            }
            $live_mod_states = [];
            if (!empty($mod_ids_to_check) && $pdo) {
                $mod_ids_to_check = array_values(array_unique($mod_ids_to_check));
                $inQuery = implode(',', array_fill(0, count($mod_ids_to_check), '?'));
                try {
                    $stMod = $pdo->prepare("SELECT id_agente_modulo, estado FROM tagente_estado WHERE id_agente_modulo IN ($inQuery)");
                    $stMod->execute($mod_ids_to_check);
                    while ($r = $stMod->fetch(PDO::FETCH_ASSOC)) {
                        $live_mod_states[(int)$r['id_agente_modulo']] = (int)$r['estado'];
                    }
                } catch (Throwable $e) {}
            }

            // 2. Custom user-defined edges connecting agents
            foreach ($custom_edges as $ce) {
                $cSrc = (string)($ce['source'] ?? '');
                $cTgt = (string)($ce['target'] ?? '');
                if (is_numeric($cSrc)) $cSrc = 'agent-' . $cSrc;
                if (is_numeric($cTgt)) $cTgt = 'agent-' . $cTgt;

                if (isset($node_id_set[$cSrc]) && isset($node_id_set[$cTgt]) && $cSrc !== $cTgt) {
                    $k1 = $cSrc . '->' . $cTgt;
                    $k2 = $cTgt . '->' . $cSrc;
                    if (!isset($edge_keys[$k1]) && !isset($edge_keys[$k2])) {
                        $edge_keys[$k1] = true;
                        $edgeId = !empty($ce['id']) ? $ce['id'] : ('custom-' . $cSrc . '-' . $cTgt);
                        
                        $srcModId = (int)($ce['source_module_id'] ?? 0);
                        $tgtModId = (int)($ce['target_module_id'] ?? 0);
                        $srcStatus = isset($live_mod_states[$srcModId]) ? $live_mod_states[$srcModId] : (int)($ce['source_status'] ?? 0);
                        $tgtStatus = isset($live_mod_states[$tgtModId]) ? $live_mod_states[$tgtModId] : (int)($ce['target_status'] ?? 0);

                        $edges[] = [
                            'id' => $edgeId,
                            'source' => $cSrc,
                            'target' => $cTgt,
                            'label' => pretty_text($ce['label'] ?? ''),
                            'status' => $ce['status'] ?? 'active',
                            'source_interface' => pretty_text($ce['source_interface'] ?? ''),
                            'source_module_id' => $srcModId,
                            'source_status' => $srcStatus,
                            'target_interface' => pretty_text($ce['target_interface'] ?? ''),
                            'target_module_id' => $tgtModId,
                            'target_status' => $tgtStatus,
                            'is_custom' => true
                        ];
                    }
                }
            }

            echo json_encode([
                'ok' => true,
                'mode' => 'live',
                'title' => $current_dash ? pretty_text($current_dash['name']) : 'Infrastructure Topology',
                'nodes' => $nodes,
                'edges' => $edges,
                'stats' => [
                    'total_nodes' => count($nodes),
                    'total_edges' => count($edges),
                    'critical' => count(array_filter($nodes, fn($n) => $n['status'] === 'critical')),
                    'warning' => count(array_filter($nodes, fn($n) => $n['status'] === 'warning')),
                    'normal' => count(array_filter($nodes, fn($n) => $n['status'] === 'normal'))
                ]
            ]);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Invalid API action']);
    exit;
}

// =====================================================================
// UI LOGIC: 2-VIEW SYSTEM
// =====================================================================
$dashboards = load_topology_dashboards($DASHBOARD_FILE);
$selected_dash_id = trim((string)($_GET['dashboard_id'] ?? $_GET['id'] ?? ''));
$current_dashboard = null;

if (!empty($selected_dash_id)) {
    foreach ($dashboards as $d) {
        if ($d['id'] === $selected_dash_id) {
            $current_dashboard = $d;
            break;
        }
    }
}

$dynamic_breadcrumb = "PANDORA CONSOLE / CUSTOM / PANEL / DASHBOARD / TOPOLOGY NETWORK";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Topology Network | PFMS-Toolkit</title>
    
    <!-- Unified Local Fonts & Icons (Instant Offline Load) -->
    <link rel="stylesheet" href="<?= htmlspecialchars($vendor_url) ?>/fonts/fonts.css">
    <link rel="stylesheet" href="../../vendor/fonts/fonts.css">

    <!-- Offline Cytoscape & Dagre Layout Engines -->
    <script src="<?= htmlspecialchars($vendor_url) ?>/cytoscape/cytoscape.min.js"></script>
    <script src="<?= htmlspecialchars($vendor_url) ?>/cytoscape/dagre.min.js"></script>
    <script src="<?= htmlspecialchars($vendor_url) ?>/cytoscape/cytoscape-dagre.min.js"></script>
    <script>
        if (typeof cytoscape === 'undefined') {
            document.write('<script src="../../vendor/cytoscape/cytoscape.min.js"><\/script>');
            document.write('<script src="../../vendor/cytoscape/dagre.min.js"><\/script>');
            document.write('<script src="../../vendor/cytoscape/cytoscape-dagre.min.js"><\/script>');
        }
    </script>

    <style>
        :root {
            --brand-green: #004d40;
            --brand-green-hover: #00695c;
            --primary-navy: #0b1a26;
            --bg-page: #f4f6f8;
            --card-bg: #ffffff;
            --border-color: #e0e4e8;
            --border-light: #f0f3f5;
            --text-dark: #334155;
            --text-muted: #64748b;
            --status-crit: #ef4444;
            --status-warn: #f59e0b;
            --status-ok: #10b981;
        }

        * { box-sizing: border-box; }
        html, body, input, button, select, textarea, table, th, td, h1, h2, h3, h4, h5, h6, span, a, p, div {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif !important;
        }
        body {
            margin: 0;
            padding: 0;
            background-color: var(--bg-page);
            color: var(--text-dark);
            font-size: 14px;
            -webkit-font-smoothing: antialiased;
        }

        .material-symbols-outlined {
            font-family: 'Material Symbols Outlined' !important;
            font-weight: normal !important;
            font-style: normal !important;
            font-size: 18px !important;
            line-height: 1 !important;
            display: inline-block;
            vertical-align: middle;
            color: inherit !important;
        }

        /* Top Header Bar matching Dynamic Dashboard */
        .pandora-header-bottom {
            background-color: #f4f6f8;
            padding: 15px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }

        .breadcrumb-box {
            display: flex;
            flex-direction: column;
        }
        .page-breadcrumb {
            font-size: 11px !important;
            color: var(--text-muted) !important;
            margin-bottom: 4px;
            font-weight: normal !important;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .page-title {
            font-size: 18px !important;
            color: var(--primary-navy) !important;
            margin: 0;
            font-weight: 600 !important;
            line-height: 1.1;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .top-controls {
            display: flex;
            flex-direction: row;
            gap: 10px;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .list-search-box {
            padding: 0 15px 0 35px !important;
            height: 36px !important;
            margin: 0 !important;
            box-sizing: border-box !important;
            width: 300px;
            border: 1px solid #dce1e5;
            border-radius: 4px;
            font-size: 13px !important;
            font-weight: normal !important;
            outline: none;
            background: #fff url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="%237f8c8d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>') no-repeat 10px center;
            transition: 0.2s;
        }
        .list-search-box:focus {
            border-color: var(--brand-green);
            box-shadow: 0 0 0 2px rgba(0,77,64,0.1);
        }

        .btn-apply {
            height: 36px !important;
            margin: 0 !important;
            box-sizing: border-box !important;
            background: var(--brand-green);
            color: #fff !important;
            border: none;
            padding: 0 18px;
            border-radius: 4px;
            font-size: 13px !important;
            font-weight: normal !important;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: 0.2s;
            white-space: nowrap;
            text-decoration: none;
        }
        .btn-apply:hover {
            background: var(--brand-green-hover);
            box-shadow: 0 2px 6px rgba(0,77,64,0.25);
            color: #fff !important;
        }

        .btn-secondary-custom {
            height: 36px !important;
            margin: 0 !important;
            box-sizing: border-box !important;
            background: #ffffff;
            color: #4a5568 !important;
            border: 1px solid #dce1e5;
            padding: 0 16px;
            border-radius: 4px;
            font-size: 13px !important;
            font-weight: normal !important;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: 0.2s;
            text-decoration: none;
        }
        .btn-secondary-custom:hover {
            background: #f4f6f8;
            color: var(--primary-navy) !important;
            border-color: #cbd5e1;
        }

        /* VIEW 1: TABLE LIST VIEW */
        .main-content {
            padding: 24px 30px 40px 30px;
            max-width: 1800px;
            margin: 0 auto;
        }

        .list-table-wrap {
            background: #fff;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        table.list-table {
            border-collapse: collapse !important;
            width: 100% !important;
            margin: 0 !important;
        }
        table.list-table thead th {
            background-color: #fafbfc !important;
            border-bottom: 1px solid var(--border-color) !important;
            text-transform: uppercase;
            padding: 15px 20px !important;
            font-weight: normal !important;
            color: #7f8c8d !important;
            font-size: 11px !important;
            text-align: left;
            letter-spacing: 0.5px;
        }
        table.list-table tbody td {
            padding: 15px 20px !important;
            border-bottom: 1px solid var(--border-light) !important;
            color: var(--text-dark) !important;
            vertical-align: middle;
            transition: 0.2s;
        }
        table.list-table tbody tr:hover td {
            background-color: #f8f9fa !important;
        }
        table.list-table tbody tr:last-child td {
            border-bottom: none !important;
        }

        .dash-name-link {
            font-size: 14px !important;
            font-weight: normal !important;
            color: #1976d2 !important;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        .dash-name-link:hover {
            text-decoration: underline !important;
            color: #0d47a1 !important;
        }

        .dash-desc-sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 3px;
            line-height: 1.4;
            font-weight: normal !important;
        }

        .badge-count {
            background: #e2e8f0;
            color: #475569;
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: normal;
            display: inline-block;
        }
        .badge-demo {
            background: #fef3c7;
            color: #92400e;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .btn-action-text {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 5px 10px;
            font-size: 12px;
            font-weight: normal;
            color: #4a5568;
            background: #ffffff;
            border: 1px solid #dce1e5;
            border-radius: 4px;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s;
            margin-left: 4px;
        }
        .btn-action-text:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: var(--primary-navy);
        }
        .btn-action-text.btn-delete-text {
            color: #dc2626;
            border-color: #fee2e2;
        }
        .btn-action-text.btn-delete-text:hover {
            background: #fef2f2;
            border-color: #fca5a5;
        }

        /* VIEW 2: CANVAS & TOOLBAR */
        #view_canvas {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 70px);
            position: relative;
            overflow: hidden;
        }

        .canvas-toolbar {
            height: 52px;
            background: #ffffff;
            border-bottom: 1px solid var(--border-color);
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            z-index: 15;
            gap: 12px;
        }

        .toolbar-left, .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-segmented {
            display: flex;
            background: #f1f5f9;
            padding: 3px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
        }

        .filter-btn {
            border: none;
            background: transparent;
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .filter-btn:hover {
            color: var(--text-dark);
        }
        .filter-btn.active {
            background: #ffffff;
            color: var(--brand-green);
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
        }
        .badge-crit { background: #fee2e2; color: #991b1b; }
        .badge-warn { background: #fef3c7; color: #92400e; }
        .badge-ok { background: #d1fae5; color: #065f46; }

        .canvas-wrapper {
            position: relative;
            flex-grow: 1;
            background: #f8fafc;
            overflow: hidden;
        }

        #cyCanvas {
            width: 100%;
            height: 100%;
            display: block;
            background-color: #ffffff;
            background-size: 28px 28px;
            background-image: 
                linear-gradient(to right, rgba(226, 232, 240, 0.6) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(226, 232, 240, 0.6) 1px, transparent 1px);
        }

        .canvas-controls {
            position: absolute;
            bottom: 24px;
            left: 24px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            background: #ffffff;
            border-radius: 8px;
            padding: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border: 1px solid var(--border-color);
            z-index: 10;
        }

        .ctrl-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--text-dark);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
        }
        .ctrl-btn:hover {
            background: #f1f5f9;
            color: var(--brand-green);
        }

        /* INSPECTOR DRAWER */
        .inspector-drawer {
            position: absolute;
            top: 0;
            right: -380px;
            width: 360px;
            height: 100%;
            background: #ffffff;
            border-left: 1px solid var(--border-color);
            box-shadow: -4px 0 16px rgba(0,0,0,0.05);
            transition: right 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 25;
            display: flex;
            flex-direction: column;
        }
        .inspector-drawer.open { right: 0; }

        .inspector-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fafbfc;
        }
        .inspector-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--primary-navy);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .inspector-close {
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
        }
        .inspector-close:hover { background: #f1f5f9; color: var(--text-dark); }

        .inspector-body {
            padding: 20px;
            overflow-y: auto;
            flex-grow: 1;
        }

        .node-hero {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }
        .node-hero-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .node-hero-info h4 {
            font-size: 15px;
            font-weight: 700;
            margin: 0 0 4px 0;
            word-break: break-all;
        }
        .node-hero-info p {
            font-size: 12px;
            color: var(--text-muted);
            margin: 0;
        }

        .metric-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 9px 0;
            border-bottom: 1px dashed #e2e8f0;
            font-size: 13px;
        }
        .metric-label { color: var(--text-muted); font-weight: 500; }
        .metric-val { font-weight: 600; color: var(--text-dark); }

        /* CONNECT MODE & LINK MANAGEMENT */
        .btn-secondary-custom.active-connect {
            background: #059669 !important;
            color: #ffffff !important;
            border-color: #047857 !important;
            box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.35) !important;
        }
        .connect-banner {
            position: absolute;
            top: 14px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 20;
            background: #0f172a;
            color: #ffffff;
            padding: 8px 18px;
            border-radius: 30px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
            font-size: 13px;
            border: 1px solid rgba(255,255,255,0.15);
            animation: modalPop 0.2s ease-out;
        }
        .connect-banner-content {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .connect-pulse {
            color: #22c55e;
            animation: pulseGlow 1.5s infinite;
        }
        @keyframes pulseGlow {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.15); }
        }
        .btn-cancel-connect {
            background: rgba(255,255,255,0.15);
            color: #ffffff;
            border: none;
            border-radius: 20px;
            padding: 3px 12px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: background 0.15s;
        }
        .btn-cancel-connect:hover {
            background: rgba(255,255,255,0.3);
        }
        .link-peer-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 10px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 12px;
        }
        .link-peer-info {
            display: flex;
            align-items: center;
            gap: 6px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .btn-del-link {
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 2px 4px;
            border-radius: 4px;
            display: flex;
            align-items: center;
        }
        .btn-del-link:hover {
            color: #ef4444;
            background: #fee2e2;
        }

        /* MODAL STYLES */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(11, 26, 38, 0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            backdrop-filter: blur(2px);
        }
        .modal-card {
            background: #ffffff;
            width: 520px;
            max-width: 90vw;
            border-radius: 8px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
            overflow: hidden;
            animation: modalPop 0.2s ease-out;
        }
        @keyframes modalPop {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .modal-head {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fafbfc;
        }
        .modal-head h3 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
            color: var(--primary-navy);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-body {
            padding: 22px 24px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .modal-foot {
            padding: 14px 24px;
            background: #fafbfc;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .form-control-custom {
            width: 100%;
            height: 36px;
            padding: 0 12px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
            font-size: 13px;
            outline: none;
            box-sizing: border-box;
            background: #ffffff;
            transition: border-color 0.2s;
        }
        .form-control-custom:focus {
            border-color: var(--brand-green);
        }

        .btn-apply:disabled, .btn-secondary-custom:disabled {
            opacity: 0.6 !important;
            cursor: not-allowed !important;
            pointer-events: none !important;
        }

        .spin-icon {
            display: inline-block !important;
            animation: spin 0.8s linear infinite !important;
            vertical-align: middle;
        }

        .modal-alert {
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            line-height: 1.4;
        }
        .modal-alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        .modal-alert-success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        .modal-alert-loading {
            background: #f0fdfa;
            border: 1px solid #99f6e4;
            color: #0f766e;
        }

        .toast-notification {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #1e293b;
            color: #ffffff;
            padding: 10px 18px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            box-shadow: 0 10px 25px rgba(0,0,0,0.18);
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 2000;
            opacity: 0;
            transform: translateY(12px);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            pointer-events: none;
        }
        .toast-notification.show {
            opacity: 1;
            transform: translateY(0);
        }
        .toast-success { background: #065f46 !important; color: #ffffff !important; }
        .toast-error { background: #991b1b !important; color: #ffffff !important; }

        /* Teal Modal Headers & Outlines (Screenshot 3 & 4) */
        .modal-head-teal {
            background: #094d4a !important;
            color: #ffffff !important;
            padding: 14px 20px !important;
            border-bottom: none !important;
        }
        .modal-head-teal h3 {
            color: #ffffff !important;
            font-size: 15px !important;
            font-weight: 700 !important;
            letter-spacing: 0.3px;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-head-teal .modal-close-btn {
            color: #ffffff !important;
            cursor: pointer;
            font-size: 20px;
            opacity: 0.85;
            transition: opacity 0.15s;
        }
        .modal-head-teal .modal-close-btn:hover {
            opacity: 1;
        }

        .btn-outline-teal {
            background: #ffffff;
            border: 1.5px solid #094d4a;
            color: #094d4a;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 18px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        .btn-outline-teal:hover {
            background: #094d4a;
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(9, 77, 74, 0.25);
        }

        /* Interface Link Box (Screenshot 4) */
        .interface-link-box {
            background: #d5eae6;
            border: 1px solid #b2dfdb;
            border-radius: 6px;
            padding: 18px 20px;
            display: grid;
            grid-template-columns: 1fr 1.3fr 1.3fr 1fr;
            gap: 16px;
            align-items: center;
        }
        .interface-link-col {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .interface-link-col-title {
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
        }
        .interface-link-node-name {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            word-break: break-word;
            padding: 7px 0;
        }

        /* Add Node Accordions & Pagination (Screenshot 3) */
        .add-node-section {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            background: #ffffff;
            overflow: hidden;
            margin-bottom: 14px;
        }
        .add-node-section-head {
            padding: 12px 18px;
            background: #ffffff;
            font-weight: 700;
            font-size: 13px;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #f1f5f9;
        }
        .add-node-section-body {
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .agent-paginate-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
        }
        .agent-paginate-table th {
            background: #f8fafc;
            padding: 9px 12px;
            text-align: left;
            font-weight: 600;
            color: #64748b;
            border-bottom: 1px solid #e2e8f0;
            font-size: 11px;
            text-transform: uppercase;
        }
        .agent-paginate-table td {
            padding: 9px 12px;
            border-bottom: 1px solid #f1f5f9;
            color: #1e293b;
            vertical-align: middle;
        }
        .agent-paginate-table tr:hover td {
            background: #f0fdfa;
            cursor: pointer;
        }
        .agent-paginate-table tr.selected td {
            background: #ccfbf1;
        }
        .paginate-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            color: #64748b;
            padding: 6px 2px;
            flex-wrap: wrap;
            gap: 8px;
        }
        .page-btn-group {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .page-btn {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            color: #334155;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .page-btn:hover:not(:disabled) {
            background: #094d4a;
            color: #ffffff;
            border-color: #094d4a;
        }
        .page-btn.active {
            background: #094d4a;
            color: #ffffff;
            border-color: #094d4a;
            font-weight: 700;
        }
        .page-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 38px;
            height: 20px;
            vertical-align: middle;
        }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background-color: #cbd5e1;
            transition: .2s;
            border-radius: 20px;
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .2s;
            border-radius: 50%;
        }
        .toggle-switch input:checked + .toggle-slider {
            background-color: #094d4a;
        }
        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(18px);
        }

        /* Canvas Context Menu */
        .canvas-context-menu {
            position: fixed;
            background: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.14);
            min-width: 175px;
            padding: 5px 0;
            z-index: 99999;
            animation: modalPop 0.12s ease-out;
        }
        .context-menu-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 16px;
            font-size: 13px;
            color: #334155;
            cursor: pointer;
            transition: background 0.15s;
        }
        .context-menu-item:hover {
            background: #f1f5f9;
            color: #094d4a;
            font-weight: 600;
        }
        .context-menu-item .material-symbols-outlined {
            font-size: 18px !important;
            color: #64748b;
        }
        .context-menu-item:hover .material-symbols-outlined {
            color: #094d4a;
        }

        .loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(3px);
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 20;
        }
        .spinner {
            width: 36px;
            height: 36px;
            border: 3px solid #e2e8f0;
            border-top-color: var(--brand-green);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* CANVAS EMPTY STATE */
        .canvas-empty-state {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            z-index: 10;
        }
        .empty-state-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 40px 36px 36px 36px;
            max-width: 460px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .empty-state-card .empty-icon {
            font-size: 52px;
            color: var(--brand-green);
            margin-bottom: 14px;
        }
        .empty-state-card h3 {
            font-size: 17px;
            font-weight: 700;
            color: var(--primary-navy);
            margin: 0 0 10px 0;
        }
        .empty-state-card p {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.6;
            margin: 0 0 20px 0;
        }
        .empty-state-card .btn-apply {
            margin-top: 8px !important;
            padding: 0 22px !important;
            height: 38px !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            border-radius: 6px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
        }

        /* AGENT PICKER MODAL STYLES */
        .agent-picker-list {
            background: #ffffff;
        }
        .agent-picker-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-bottom: 1px solid #f1f5f9;
            cursor: pointer;
            transition: background 0.15s;
        }
        .agent-picker-item:last-child {
            border-bottom: none;
        }
        .agent-picker-item:hover {
            background: #f8fafc;
        }
        .agent-picker-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: var(--brand-green);
        }
        .agent-picker-icon {
            width: 28px;
            height: 28px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .agent-picker-details {
            flex: 1;
            min-width: 0;
        }
        .agent-picker-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--primary-navy);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .agent-picker-meta {
            font-size: 11px;
            color: var(--text-muted);
            display: flex;
            gap: 10px;
            margin-top: 2px;
        }

        .d-none { display: none !important; }
    </style>
</head>
<body>

    <!-- ========================================================================= -->
    <!-- VIEW 1: MASTER DASHBOARD LIST (Identical flow to Dynamic Dashboard)       -->
    <!-- ========================================================================= -->
    <div id="view_list" class="<?= $current_dashboard ? 'd-none' : '' ?>">
        <div class="pandora-header-bottom">
            <div class="breadcrumb-box">
                <span class="page-breadcrumb"><?= htmlspecialchars($dynamic_breadcrumb) ?></span>
                <h1 class="page-title" id="pageMainTitle">Topology Network</h1>
            </div>

            <div class="top-controls">
                <input type="text" id="listSearch" class="list-search-box" placeholder="Search topology dashboards..." oninput="filterDashboardTable(this.value)">
                <button class="btn-apply" onclick="openCreateModal()">
                    <span class="material-symbols-outlined">add</span>
                    Create Topology Dashboard
                </button>
            </div>
        </div>

        <div class="main-content">
            <div class="list-table-wrap">
                <table class="list-table" id="dashListTable">
                    <thead>
                        <tr>
                            <th style="width: 35%;">Dashboard Name</th>
                            <th style="width: 25%;">Agent Scope / Target Group</th>
                            <th style="width: 15%;">Node Count</th>
                            <th style="width: 13%;">Last Updated</th>
                            <th style="width: 12%; text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="dashTableBody">
                        <!-- Rendered by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- VIEW 2: INTERACTIVE TOPOLOGY CANVAS DASHBOARD                             -->
    <!-- ========================================================================= -->
    <div id="view_canvas" class="<?= $current_dashboard ? '' : 'd-none' ?>">
        <div class="pandora-header-bottom" style="padding: 10px 24px;">
            <div style="display:flex; align-items:center; gap:16px;">
                <button class="btn-secondary-custom" onclick="closeDashboard()" title="Back to Dashboard List">
                    <span class="material-symbols-outlined">arrow_back</span>
                    Dashboard List
                </button>
                <div class="breadcrumb-box">
                    <span class="page-breadcrumb" id="canvasBreadcrumb"><?= htmlspecialchars($dynamic_breadcrumb) ?></span>
                    <h1 class="page-title" id="canvasDashTitle">
                        <span id="canvasTitleText"><?= htmlspecialchars($current_dashboard['name'] ?? 'Topology Canvas') ?></span>
                    </h1>
                </div>
            </div>

            <div class="top-controls">
                <div id="statusBadges" style="display:flex; gap:8px;">
                    <span class="badge-pill badge-crit" title="Critical Nodes"><span class="material-symbols-outlined" style="font-size:14px;">error</span> <span id="cntCrit">0</span></span>
                    <span class="badge-pill badge-warn" title="Warning Nodes"><span class="material-symbols-outlined" style="font-size:14px;">warning</span> <span id="cntWarn">0</span></span>
                    <span class="badge-pill badge-ok" title="Normal Nodes"><span class="material-symbols-outlined" style="font-size:14px;">check_circle</span> <span id="cntOk">0</span></span>
                </div>
                <button class="btn-secondary-custom" onclick="fitTopologyView()" title="Fit View">
                    <span class="material-symbols-outlined">fit_screen</span>
                    Fit View
                </button>
                <button class="btn-secondary-custom" onclick="refreshCurrentTopology()" title="Refresh Data">
                    <span class="material-symbols-outlined">refresh</span>
                    Refresh
                </button>
                <button class="btn-secondary-custom" onclick="exportTopologyImage()" title="Export PNG">
                    <span class="material-symbols-outlined">download</span>
                    Export PNG
                </button>
            </div>
        </div>

        <div class="canvas-toolbar">
            <div class="toolbar-left">
                <!-- Add Node Button -->
                <button class="btn-apply" id="btnAddDevicesToolbar" onclick="openAddNodeModal()" style="height:34px; padding:0 14px; font-size:13px;" title="Add node">
                    <span class="material-symbols-outlined" style="font-size:18px;">add_circle</span>
                    Add node
                </button>

                <!-- Interface Link Button (Interactive Connection) -->
                <button class="btn-secondary-custom" id="btnConnectMode" onclick="toggleConnectMode()" style="height:34px; padding:0 14px; font-size:13px;" title="Connect two devices by interface link">
                    <span class="material-symbols-outlined" id="connectModeIcon" style="font-size:18px;">cable</span>
                    <span id="connectModeLabel">Interface link</span>
                </button>

                <!-- Group Filter dropdown (cleaned of &#x20;) -->
                <select id="canvasGroupSelect" class="form-control-custom" style="width:240px;" onchange="onCanvasGroupChange(this.value)">
                    <option value="0">All Agent Groups</option>
                </select>

                <!-- Filter Segmented by Category -->
                <div class="filter-segmented">
                    <button class="filter-btn active" onclick="setCategoryFilter('all', this)">All Devices</button>
                    <button class="filter-btn" onclick="setCategoryFilter('compute', this)">Compute</button>
                    <button class="filter-btn" onclick="setCategoryFilter('storage', this)">Storage</button>
                    <button class="filter-btn" onclick="setCategoryFilter('network', this)">Network</button>
                </div>

                <!-- Device Search -->
                <div style="position:relative; display:inline-block;">
                    <input type="text" id="deviceSearch" class="form-control-custom" style="width:220px; padding-left:32px;" placeholder="Search node or IP..." oninput="onDeviceSearch(this.value)">
                    <span class="material-symbols-outlined" style="position:absolute; left:8px; top:9px; color:#94a3b8;">search</span>
                </div>
            </div>

            <div class="toolbar-right">
                <select id="layoutSelect" class="form-control-custom" style="width:160px;" onchange="changeLayout(this.value)">
                    <option value="dagre">Hierarchical (Multi-Tier)</option>
                    <option value="cose">Force-Directed (Mesh)</option>
                    <option value="circle">Circular Ring</option>
                    <option value="concentric">Concentric Tiers</option>
                </select>
            </div>
        </div>

        <main class="canvas-wrapper">
            <div id="cyCanvas"></div>

            <!-- Connect Mode Floating Banner -->
            <div id="connectModeBanner" class="connect-banner d-none">
                <div class="connect-banner-content">
                    <span class="material-symbols-outlined connect-pulse">cable</span>
                    <span id="connectBannerText"><strong>Interface Link:</strong> Click the first device (Source)...</span>
                </div>
                <button type="button" class="btn-cancel-connect" onclick="exitConnectMode()">
                    <span class="material-symbols-outlined" style="font-size:14px;">close</span> Cancel
                </button>
            </div>

            <div id="canvasEmptyState" class="canvas-empty-state d-none">
                <div class="empty-state-card">
                    <span class="material-symbols-outlined empty-icon">hub</span>
                    <h3>Topology Canvas is Empty</h3>
                    <p>This topology dashboard does not have any devices yet. Pick agents from your Pandora FMS inventory to visualize.</p>
                    <button class="btn-apply" onclick="openAddNodeModal()" style="margin-top: 8px !important;">
                        <span class="material-symbols-outlined">add_circle</span>
                        Add node
                    </button>
                </div>
            </div>

            <div class="canvas-controls">
                <button class="ctrl-btn" onclick="zoomIn()" title="Zoom In"><span class="material-symbols-outlined">add</span></button>
                <button class="ctrl-btn" onclick="zoomOut()" title="Zoom Out"><span class="material-symbols-outlined">remove</span></button>
                <button class="ctrl-btn" onclick="fitTopologyView()" title="Fit View"><span class="material-symbols-outlined">crop_free</span></button>
            </div>

            <div id="loadingOverlay" class="loading-overlay">
                <div class="spinner"></div>
                <p style="margin-top:14px; font-size:13px; font-weight:600; color:var(--text-muted);">Rendering Network Topology...</p>
            </div>
        </main>

        <aside id="inspectorDrawer" class="inspector-drawer">
            <div class="inspector-header">
                <h3 class="inspector-title" id="drawerHeaderTitle">
                    <span class="material-symbols-outlined" style="color:var(--brand-green);">info</span>
                    Device Inspector
                </h3>
                <button class="inspector-close" onclick="closeInspector()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            
            <div class="inspector-body">
                <!-- 1. NODE / AGENT INSPECTOR VIEW -->
                <div id="drawerNodeContent">
                    <div class="node-hero">
                        <div class="node-hero-icon" id="drawerHeroIcon" style="background:#ffffff; border:1px solid var(--border-color); padding:4px;">
                            <img id="drawerIconImg" src="" style="width:32px; height:32px; object-fit:contain;" alt="Device Icon">
                        </div>
                        <div class="node-hero-info">
                            <h4 id="drawerNodeName">Node Name</h4>
                            <p id="drawerRoleTitle">Role</p>
                        </div>
                    </div>

                    <div class="metric-row">
                        <span class="metric-label">Status Health</span>
                        <span class="metric-val" id="drawerStatusBadge"><span class="badge-pill badge-ok">Normal</span></span>
                    </div>

                    <!-- Role Switcher -->
                    <div class="metric-row">
                        <span class="metric-label">Device Role</span>
                        <select id="drawerRoleSelect" class="form-control-custom" style="width:145px; height:28px; padding:2px 8px; font-size:12px;" onchange="changeInspectedDeviceRole(this.value)">
                            <option value="router">Router</option>
                            <option value="switch">Switch</option>
                            <option value="firewall">Firewall</option>
                            <option value="server">Host Server</option>
                            <option value="workstation">Workstation/PC</option>
                            <option value="storage">Storage</option>
                            <option value="database">Database</option>
                            <option value="vm">Virtual Machine</option>
                            <option value="hypervisor">Hypervisor</option>
                            <option value="cluster">Cluster</option>
                            <option value="datacenter">Datacenter</option>
                        </select>
                    </div>

                    <div class="metric-row">
                        <span class="metric-label">IP Address</span>
                        <span class="metric-val" id="drawerIp">0.0.0.0</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-label">Agent Group</span>
                        <span class="metric-val" id="drawerGroup">Infrastructure</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-label">Operating System</span>
                        <span class="metric-val" id="drawerOs">Unknown</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-label">Active Alerts</span>
                        <span class="metric-val" id="drawerAlertCount">0</span>
                    </div>

                    <!-- Connected Links Section -->
                    <div style="margin-top:16px; padding-top:14px; border-top:1px solid var(--border-color);">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                            <span style="font-size:12px; font-weight:700; color:var(--text-dark); text-transform:uppercase; letter-spacing:0.5px;">Connected Links</span>
                            <span id="drawerConnectedLinksBadge" class="badge-pill badge-ok" style="font-size:10px; padding:2px 8px;">0 links</span>
                        </div>
                        <div id="drawerConnectedLinksList" style="display:flex; flex-direction:column; gap:6px; max-height:160px; overflow-y:auto; margin-bottom:10px;">
                            <!-- Populated dynamically with connected link pills and delete buttons -->
                        </div>
                        
                        <button type="button" class="btn-secondary-custom" id="btnOpenConnectSection" onclick="toggleDrawerConnectSection()" style="width:100%; justify-content:center; font-size:12px; height:30px;">
                            <span class="material-symbols-outlined" style="font-size:16px;">add_link</span>
                            Connect to Device...
                        </button>
                        
                        <div id="drawerConnectBox" style="display:none; margin-top:8px; padding:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;">
                            <label style="display:block; font-size:11px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">Target Device</label>
                            <select id="drawerTargetDeviceSelect" class="form-control-custom" style="width:100%; height:30px; font-size:12px; margin-bottom:8px;">
                                <!-- Populated with other devices in canvas -->
                            </select>
                            <label style="display:block; font-size:11px; font-weight:600; color:var(--text-muted); margin-bottom:4px;">Link Label (Optional)</label>
                            <input type="text" id="drawerLinkLabelInput" class="form-control-custom" placeholder="e.g. 10G Trunk, eth0, Uplink" style="width:100%; height:30px; font-size:12px; margin-bottom:10px;">
                            <button type="button" class="btn-apply" onclick="connectFromDrawer()" style="width:100%; justify-content:center; height:30px; font-size:12px;">
                                <span class="material-symbols-outlined" style="font-size:16px;">cable</span>
                                Establish Link
                            </button>
                        </div>
                    </div>

                    <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border-color);">
                        <button type="button" class="btn-secondary-custom" id="btnRemoveFromDashboard" onclick="removeCurrentDeviceFromDashboard()" style="width:100%; justify-content:center; color:#dc2626; border-color:#fecaca;">
                            <span class="material-symbols-outlined" style="font-size:18px;">delete</span>
                            Remove from Dashboard
                        </button>
                    </div>
                </div>

                <!-- 2. EDGE / CONNECTION INSPECTOR VIEW -->
                <div id="drawerEdgeContent" style="display:none;">
                    <div style="padding:14px; background:#f8fafc; border-radius:8px; border:1px solid var(--border-color); margin-bottom:16px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                            <span style="font-size:11px; font-weight:700; color:var(--brand-green); text-transform:uppercase;">Topology Link</span>
                            <span id="drawerEdgeTypeBadge" class="badge-pill badge-ok">Active Link</span>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                            <div style="flex:1; text-align:center; padding:8px; background:#ffffff; border:1px solid var(--border-color); border-radius:6px;">
                                <span class="material-symbols-outlined" style="color:var(--primary-navy); font-size:22px; display:block;">hub</span>
                                <strong id="drawerEdgeSrcName" style="font-size:11px; display:block; word-break:break-all;">Source</strong>
                            </div>
                            <span class="material-symbols-outlined" style="color:#94a3b8; font-size:18px;">sync_alt</span>
                            <div style="flex:1; text-align:center; padding:8px; background:#ffffff; border:1px solid var(--border-color); border-radius:6px;">
                                <span class="material-symbols-outlined" style="color:var(--primary-navy); font-size:22px; display:block;">dns</span>
                                <strong id="drawerEdgeTgtName" style="font-size:11px; display:block; word-break:break-all;">Target</strong>
                            </div>
                        </div>
                    </div>

                    <div class="metric-row">
                        <span class="metric-label">Link Label</span>
                        <span class="metric-val" id="drawerEdgeLabel">-</span>
                    </div>
                    <div class="metric-row">
                        <span class="metric-label">Link Status</span>
                        <span class="metric-val" id="drawerEdgeStatus"><span class="badge-pill badge-ok">Active</span></span>
                    </div>

                    <div style="margin-top:24px; padding-top:16px; border-top:1px solid var(--border-color);">
                        <button type="button" class="btn-secondary-custom" id="btnDeleteSelectedEdge" onclick="deleteCurrentInspectedEdge()" style="width:100%; justify-content:center; color:#dc2626; border-color:#fecaca;">
                            <span class="material-symbols-outlined" style="font-size:18px;">link_off</span>
                            Delete Connection Link
                        </button>
                    </div>
                </div>
            </div>
        </aside>
    </div>

    <!-- ========================================================================= -->
    <!-- CREATE / EDIT DASHBOARD MODAL                                             -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="dashboardModal">
        <div class="modal-card">
            <div class="modal-head">
                <h3 id="modalTitle"><span class="material-symbols-outlined">add_chart</span> Create Topology Dashboard</h3>
                <span class="material-symbols-outlined" style="cursor:pointer; color:#7f8c8d;" onclick="closeModal()">close</span>
            </div>
            <div class="modal-body">
                <input type="hidden" id="dashModalId" value="">
                
                <div>
                    <label class="form-label">Dashboard Name *</label>
                    <input type="text" id="dashModalName" class="form-control-custom" placeholder="e.g. Core Datacenter Topology">
                </div>

                <div>
                    <label class="form-label">Description (Optional)</label>
                    <input type="text" id="dashModalDesc" class="form-control-custom" placeholder="Brief notes about scope or purpose">
                </div>

                <div>
                    <label class="form-label">Initial Topology Scope</label>
                    <div style="display:flex; flex-direction:column; gap:8px; margin-bottom:6px;">
                        <label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer; color:var(--text-dark);">
                            <input type="radio" name="dashModalMapType" id="dashModalTypeBlank" value="blank" checked onchange="toggleModalScopeSelection()">
                            <span><strong>Blank Canvas</strong> (Start empty, add specific devices manually)</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer; color:var(--text-dark);">
                            <input type="radio" name="dashModalMapType" id="dashModalTypeGroup" value="group" onchange="toggleModalScopeSelection()">
                            <span><strong>Agent Group</strong> (Auto-populate agents from a specific group)</span>
                        </label>
                    </div>
                </div>

                <div id="dashModalGroupWrapper" style="display:none;">
                    <label class="form-label">Target Agent Group</label>
                    <select id="dashModalGroup" class="form-control-custom">
                        <option value="0">All Agent Groups</option>
                    </select>
                </div>

                <div>
                    <label class="form-label">Default Graph Layout</label>
                    <select id="dashModalLayout" class="form-control-custom">
                        <option value="dagre">Hierarchical (Top-Down)</option>
                        <option value="breadthfirst">Tree Hierarchy</option>
                        <option value="cose">Force-Directed (Mesh)</option>
                        <option value="circle">Circular Ring</option>
                    </select>
                </div>

                <div id="modalAlertBox" class="modal-alert modal-alert-error d-none"></div>
            </div>
            <div class="modal-foot">
                <button class="btn-secondary-custom" id="btnCancelDashboard" onclick="closeModal()">Cancel</button>
                <button class="btn-apply" id="btnSaveDashboard" onclick="submitDashboardForm()">
                    <span class="material-symbols-outlined">save</span>
                    Save Dashboard
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- ADD NODE MODAL (Screenshot 3 - Preloaded 10 agents with pagination & search) -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="addNodeModal">
        <div class="modal-card" style="max-width: 760px; width: 94%; max-height: 90vh; display: flex; flex-direction: column;">
            <div class="modal-head modal-head-teal">
                <h3>Add node</h3>
                <span class="material-symbols-outlined modal-close-btn" onclick="closeAddNodeModal()">close</span>
            </div>
            <div class="modal-body" style="overflow-y: auto; padding: 20px; gap: 16px;">
                <!-- Section 1: Add agent node -->
                <div class="add-node-section">
                    <div class="add-node-section-head">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span class="material-symbols-outlined" style="font-size:18px; color:#64748b;">expand_less</span>
                            <span>Add agent node</span>
                        </div>
                    </div>
                    <div class="add-node-section-body">
                        <div>
                            <label class="form-label" style="font-size:13px; color:#0f172a; margin-bottom:6px;">Agent</label>
                            <div style="position:relative;">
                                <input type="text" id="addNodeAgentSearch" class="form-control-custom" placeholder="Search agent by name, IP, group, OS..." oninput="onAddNodeSearch(this.value)">
                                <span class="material-symbols-outlined" style="position:absolute; right:10px; top:9px; color:#94a3b8;">search</span>
                            </div>
                            <div style="font-size:11px; color:#64748b; margin-top:4px;">Type at least two characters to search.</div>
                        </div>

                        <!-- Preloaded Agent List (10 agents per page with pagination) -->
                        <div style="margin-top:6px;">
                            <table class="agent-paginate-table">
                                <thead>
                                    <tr>
                                        <th style="width:40px; text-align:center;">Select</th>
                                        <th>Agent Name / Alias</th>
                                        <th>IP Address</th>
                                        <th>Group</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody id="addNodeTableBody">
                                    <!-- Rendered dynamically with 10 agents -->
                                </tbody>
                            </table>

                            <!-- Pagination Controls -->
                            <div class="paginate-controls" id="addNodePaginationControls">
                                <span id="addNodePageInfo">Showing 1-10 of 0 agents</span>
                                <div class="page-btn-group" id="addNodePageButtons">
                                    <!-- Rendered dynamically -->
                                </div>
                            </div>
                        </div>

                        <div style="display:flex; justify-content:flex-end; margin-top:6px;">
                            <button type="button" class="btn-outline-teal" id="btnAddAgentNodeSubmit" onclick="submitAddAgentNode()">
                                Add agent node
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Add agent node (filter by group) -->
                <div class="add-node-section">
                    <div class="add-node-section-head">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span class="material-symbols-outlined" style="font-size:18px; color:#64748b;">expand_less</span>
                            <span>Add agent node (filter by group)</span>
                        </div>
                    </div>
                    <div class="add-node-section-body">
                        <div style="display:grid; grid-template-columns: 1fr auto; gap: 16px; align-items: flex-end;">
                            <div>
                                <label class="form-label" style="font-size:13px; color:#0f172a; margin-bottom:6px;">Group</label>
                                <select id="addNodeGroupSelect" class="form-control-custom" onchange="onAddNodeGroupSelect(this.value)">
                                    <option value="">None</option>
                                </select>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
                                <label class="form-label" style="font-size:13px; color:#0f172a; margin-bottom:0;">Recursion</label>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="addNodeRecursionToggle" checked onchange="onAddNodeRecursionChange()">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- INTERFACE LINK MODAL (Screenshot 4)                                       -->
    <!-- ========================================================================= -->
    <div class="modal-overlay" id="interfaceLinkModal">
        <div class="modal-card" style="max-width: 720px; width: 94%; border-radius: 8px; overflow:hidden;">
            <div class="modal-head modal-head-teal">
                <h3>Interface link</h3>
                <span class="material-symbols-outlined modal-close-btn" onclick="closeInterfaceLinkModal()">close</span>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <input type="hidden" id="linkSourceNodeId" value="">
                <input type="hidden" id="linkTargetNodeId" value="">
                
                <!-- Light teal box matching Screenshot 4 -->
                <div class="interface-link-box">
                    <div class="interface-link-col">
                        <div class="interface-link-col-title">Node source</div>
                        <div class="interface-link-node-name" id="linkSourceNodeName">-</div>
                    </div>

                    <div class="interface-link-col">
                        <div class="interface-link-col-title">Interface source</div>
                        <select id="linkSourceInterfaceSelect" class="form-control-custom" style="background:#ffffff; height:36px;">
                            <option value="">None</option>
                        </select>
                    </div>

                    <div class="interface-link-col">
                        <div class="interface-link-col-title">Interface target</div>
                        <select id="linkTargetInterfaceSelect" class="form-control-custom" style="background:#ffffff; height:36px;">
                            <option value="">None</option>
                        </select>
                    </div>

                    <div class="interface-link-col">
                        <div class="interface-link-col-title">Node target</div>
                        <div class="interface-link-node-name" id="linkTargetNodeName">-</div>
                    </div>
                </div>

                <div id="interfaceLinkLoading" style="display:none; text-align:center; padding:12px; color:#094d4a; font-size:12px;">
                    <span class="material-symbols-outlined spin-icon" style="font-size:16px;">progress_activity</span> Loading interface modules (ifOperStatus/ifAdmin)...
                </div>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-top: 18px;">
                    <button type="button" class="btn-outline-teal" id="btnAddInterfaceLinkSubmit" onclick="submitInterfaceLink()">
                        Add interface link
                    </button>
                    <button type="button" class="btn-secondary-custom" id="btnDeleteInterfaceLink" onclick="deleteCurrentInterfaceLink()" style="display:none; color:#dc2626; border-color:#fecaca;">
                        <span class="material-symbols-outlined" style="font-size:16px;">delete</span> Delete Link
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- CANVAS CONTEXT MENU (Screenshot 1)                                        -->
    <!-- ========================================================================= -->
    <div id="canvasContextMenu" class="canvas-context-menu" style="display:none;">
        <div class="context-menu-item" onclick="openAddNodeModalFromContext()">
            <span class="material-symbols-outlined">add_circle</span>
            <span>Add node</span>
        </div>
        <div class="context-menu-item" onclick="loadNewNodesFromContext()">
            <span class="material-symbols-outlined">refresh</span>
            <span>Load new nodes</span>
        </div>
        <div class="context-menu-item" onclick="redrawMapFromContext()">
            <span class="material-symbols-outlined">sync</span>
            <span>Redraw map</span>
        </div>
    </div>

    <div id="nodeContextMenu" class="canvas-context-menu" style="display:none;">
        <div class="context-menu-item" onclick="connectFromNodeContext()">
            <span class="material-symbols-outlined">cable</span>
            <span>Connect interface link...</span>
        </div>
        <div class="context-menu-item" onclick="inspectFromNodeContext()">
            <span class="material-symbols-outlined">info</span>
            <span>Inspect node</span>
        </div>
        <div class="context-menu-item" onclick="removeFromNodeContext()" style="color:#dc2626;">
            <span class="material-symbols-outlined" style="color:#dc2626;">delete</span>
            <span>Remove node</span>
        </div>
    </div>

    <div id="edgeContextMenu" class="canvas-context-menu" style="display:none;">
        <div class="context-menu-item" onclick="editEdgeFromContext()">
            <span class="material-symbols-outlined">edit</span>
            <span>Edit interface link</span>
        </div>
        <div class="context-menu-item" onclick="deleteEdgeFromContext()" style="color:#dc2626;">
            <span class="material-symbols-outlined" style="color:#dc2626;">link_off</span>
            <span>Delete Link</span>
        </div>
    </div>

    <!-- TOAST NOTIFICATION CONTAINER -->
    <div id="toastNotification" class="toast-notification"></div>

    <!-- ========================================================================= -->
    <!-- JAVASCRIPT APPLICATION LOGIC                                              -->
    <!-- ========================================================================= -->
    <script>
        const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
        const IMAGES_URL = <?= json_encode(rtrim($PANDORA_BASE_URL, '/') . '/images/') ?>;
        let allDashboards = <?= json_encode($dashboards) ?>;
        let activeDashId = <?= json_encode($selected_dash_id) ?>;
        let cy = null;
        let rawTopologyData = { nodes: [], edges: [] };
        let activeCategory = 'all';
        let availableAgents = [];
        let selectedAgentIds = new Set();
        let currentInspectedAgent = null;

        // Dynamic API URL resolver for Pandora FMS portal router or standalone mode
        function getApiUrl(apiName, extraParams = {}) {
            const url = new URL(window.location.href);
            url.searchParams.set('api', apiName);
            if (apiName !== 'get_topology_data' && apiName !== 'save_dashboard_devices') {
                url.searchParams.delete('dashboard_id');
                url.searchParams.delete('group_id');
            }
            url.hash = '';
            for (const [k, v] of Object.entries(extraParams)) {
                if (v !== null && v !== undefined && v !== '') {
                    url.searchParams.set(k, String(v));
                } else {
                    url.searchParams.delete(k);
                }
            }
            return url.toString();
        }

        // Universal Client-Side Text Sanitizer: strips any raw entity remnants
        function cleanText(str) {
            if (!str) return '';
            return String(str)
                .replace(/&#x20;/gi, ' ')
                .replace(/&amp;#x20;/gi, ' ')
                .replace(/&#32;/gi, ' ')
                .replace(/&nbsp;/gi, ' ')
                .replace(/#@20;/gi, ' ')
                .trim();
        }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toastNotification');
            if (!toast) return;
            const icon = type === 'success' ? 'check_circle' : 'error';
            toast.className = 'toast-notification show toast-' + (type === 'success' ? 'success' : 'error');
            toast.innerHTML = `<span class="material-symbols-outlined" style="font-size:18px;">${icon}</span> <span>${escapeHtml(message)}</span>`;
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3500);
        }

        function showModalAlert(msg) {
            const el = document.getElementById('modalAlertBox');
            if (el) {
                el.innerText = msg;
                el.classList.remove('d-none');
            }
        }

        function clearModalAlert() {
            const el = document.getElementById('modalAlertBox');
            if (el) {
                el.innerText = '';
                el.classList.add('d-none');
            }
        }

        // --- 1. RENDER DASHBOARD LIST ---
        function renderDashboardTable(dashboardsToRender) {
            const tbody = document.getElementById('dashTableBody');
            if (!tbody) return;

            if (!dashboardsToRender || dashboardsToRender.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align:center; padding:45px 20px; color:#64748b;">
                            <span class="material-symbols-outlined" style="font-size:48px; color:#94a3b8; margin-bottom:8px;">hub</span>
                            <div style="font-size:15px; font-weight:600; color:#334155;">No Topology Dashboards Configured</div>
                            <div style="font-size:13px; color:#94a3b8; margin-top:4px;">Create your first topology dashboard to visualize agent networks.</div>
                            <button class="btn-apply" style="margin-top:16px;" onclick="openCreateModal()">
                                <span class="material-symbols-outlined">add</span> Create Topology Dashboard
                            </button>
                        </td>
                    </tr>
                `;
                return;
            }

            let html = '';
            dashboardsToRender.forEach(d => {
                const cleanName = cleanText(d.name);
                const cleanDesc = cleanText(d.description || '');
                const isBlank = (d.map_type === 'blank');
                let cleanGroup = cleanText(d.group_name || 'All Agent Groups');
                if (isBlank && (!d.group_id || d.group_id === 0)) {
                    cleanGroup = 'Custom Devices (Manual)';
                }
                const nodeCount = (d.node_count !== undefined && d.node_count > 0) ? `${d.node_count} devices` : (isBlank ? '0 devices (Empty)' : 'Active Scope');

                html += `
                    <tr>
                        <td>
                            <a class="dash-name-link" onclick="openDashboard('${escapeHtml(d.id)}')">
                                ${escapeHtml(cleanName)}
                            </a>
                            ${cleanDesc ? `<div class="dash-desc-sub">${escapeHtml(cleanDesc)}</div>` : ''}
                        </td>
                        <td>
                            <span style="font-weight:500; color:#334155;">${escapeHtml(cleanGroup)}</span>
                        </td>
                        <td>
                            <span class="badge-count">${escapeHtml(nodeCount)}</span>
                        </td>
                        <td style="color:#64748b; font-size:12px;">
                            ${escapeHtml(d.updated_at || d.created_at || '-')}
                        </td>
                        <td style="text-align:right; white-space:nowrap;">
                            <button class="btn-apply" style="height:30px; padding:0 12px; font-size:12px;" onclick="openDashboard('${escapeHtml(d.id)}')">
                                <span class="material-symbols-outlined" style="font-size:16px;">visibility</span>
                                View
                            </button>
                            <button class="btn-action-text" onclick="editDashboard('${escapeHtml(d.id)}')">
                                <span class="material-symbols-outlined" style="font-size:15px;">edit</span>
                                Edit
                            </button>
                            <button class="btn-action-text btn-delete-text" onclick="deleteDashboard('${escapeHtml(d.id)}')">
                                <span class="material-symbols-outlined" style="font-size:15px;">delete</span>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        function filterDashboardTable(keyword) {
            keyword = (keyword || '').toLowerCase().trim();
            if (!keyword) {
                renderDashboardTable(allDashboards);
                return;
            }
            const filtered = allDashboards.filter(d => 
                (d.name && cleanText(d.name).toLowerCase().includes(keyword)) ||
                (d.description && cleanText(d.description).toLowerCase().includes(keyword)) ||
                (d.group_name && cleanText(d.group_name).toLowerCase().includes(keyword))
            );
            renderDashboardTable(filtered);
        }

        // --- 2. SWITCHING BETWEEN VIEW 1 & VIEW 2 ---
        function openDashboard(dashId) {
            const dash = allDashboards.find(d => d.id === dashId);
            if (!dash) return;

            activeDashId = dashId;
            document.getElementById('view_list').classList.add('d-none');
            document.getElementById('view_canvas').classList.remove('d-none');
            document.getElementById('canvasTitleText').innerText = cleanText(dash.name);

            // Update URL without reloading page
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.set('dashboard_id', dashId);
            window.history.pushState({ dashboard_id: dashId }, '', newUrl.toString());

            // Load Topology Data
            loadTopologyData(dashId);
        }

        function closeDashboard() {
            activeDashId = null;
            document.getElementById('view_canvas').classList.add('d-none');
            document.getElementById('view_list').classList.remove('d-none');
            closeInspector();

            const newUrl = new URL(window.location.href);
            newUrl.searchParams.delete('dashboard_id');
            newUrl.searchParams.delete('id');
            window.history.pushState({}, '', newUrl.toString());
        }

        function refreshCurrentTopology() {
            if (activeDashId) {
                loadTopologyData(activeDashId);
            }
        }

        // --- 3. MODAL CRUD ACTIONS ---
        function toggleModalScopeSelection() {
            const isGroup = document.getElementById('dashModalTypeGroup').checked;
            document.getElementById('dashModalGroupWrapper').style.display = isGroup ? 'block' : 'none';
        }

        function openCreateModal() {
            document.getElementById('modalTitle').innerHTML = '<span class="material-symbols-outlined">add_chart</span> Create Topology Dashboard';
            document.getElementById('dashModalId').value = '';
            document.getElementById('dashModalName').value = '';
            document.getElementById('dashModalDesc').value = '';
            document.getElementById('dashModalTypeBlank').checked = true;
            document.getElementById('dashModalTypeGroup').checked = false;
            document.getElementById('dashModalGroupWrapper').style.display = 'none';
            document.getElementById('dashModalGroup').value = '0';
            document.getElementById('dashModalLayout').value = 'dagre';
            clearModalAlert();
            document.getElementById('dashboardModal').style.display = 'flex';
        }

        function editDashboard(id) {
            const d = allDashboards.find(x => x.id === id);
            if (!d) return;

            document.getElementById('modalTitle').innerHTML = '<span class="material-symbols-outlined">edit</span> Edit Topology Dashboard';
            document.getElementById('dashModalId').value = d.id;
            document.getElementById('dashModalName').value = cleanText(d.name);
            document.getElementById('dashModalDesc').value = cleanText(d.description || '');
            const isGroup = (d.map_type === 'group');
            document.getElementById('dashModalTypeBlank').checked = !isGroup;
            document.getElementById('dashModalTypeGroup').checked = isGroup;
            document.getElementById('dashModalGroupWrapper').style.display = isGroup ? 'block' : 'none';
            document.getElementById('dashModalGroup').value = String(d.group_id || '0');
            document.getElementById('dashModalLayout').value = d.layout || 'dagre';
            clearModalAlert();
            document.getElementById('dashboardModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('dashboardModal').style.display = 'none';
            clearModalAlert();
        }

        async function submitDashboardForm() {
            const btn = document.getElementById('btnSaveDashboard');
            const id = document.getElementById('dashModalId').value.trim();
            const name = document.getElementById('dashModalName').value.trim();
            const desc = document.getElementById('dashModalDesc').value.trim();
            const isGroup = document.getElementById('dashModalTypeGroup').checked;
            const mapType = isGroup ? 'group' : 'blank';
            const groupId = isGroup ? (parseInt(document.getElementById('dashModalGroup').value) || 0) : 0;
            const layout = document.getElementById('dashModalLayout').value;

            if (!name) {
                showModalAlert('Please enter a Dashboard Name.');
                return;
            }

            const groupSelect = document.getElementById('dashModalGroup');
            let groupName = 'Custom Devices';
            if (isGroup) {
                groupName = 'All Agent Groups';
                if (groupId > 0 && groupSelect.selectedIndex >= 0) {
                    groupName = cleanText(groupSelect.options[groupSelect.selectedIndex].text);
                }
            }

            // Show active loading state on save button
            btn.disabled = true;
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<span class="material-symbols-outlined spin-icon">progress_activity</span> Saving Dashboard...';
            clearModalAlert();

            try {
                const res = await fetch(getApiUrl('save_dashboard'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        csrf_token: CSRF_TOKEN,
                        id: id,
                        name: cleanText(name),
                        description: cleanText(desc),
                        map_type: mapType,
                        group_id: groupId,
                        group_name: groupName,
                        layout: layout,
                        is_demo: false
                    })
                });

                if (!res.ok && res.status !== 200) {
                    throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                }

                const data = await res.json();
                if (data.ok) {
                    closeModal();
                    showToast(id ? 'Dashboard updated successfully' : 'Dashboard created successfully', 'success');

                    // Directly update dashboard list from backend response
                    if (Array.isArray(data.dashboards)) {
                        allDashboards = data.dashboards;
                        renderDashboardTable(allDashboards);
                    }

                    // If updating current active dashboard, update title
                    if (id && activeDashId === id) {
                        const titleEl = document.getElementById('canvasTitleText');
                        if (titleEl) titleEl.innerText = cleanText(name);
                    }

                    // If created new, open immediately
                    if (!id && data.id) {
                        openDashboard(data.id);
                    }
                } else {
                    showModalAlert('Error saving dashboard: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                showModalAlert('Network error while saving: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }

        async function deleteDashboard(id) {
            const d = allDashboards.find(x => x.id === id);
            const name = d ? cleanText(d.name) : id;
            if (!confirm(`Are you sure you want to delete dashboard "${name}"?`)) {
                return;
            }

            try {
                const res = await fetch(getApiUrl('delete_dashboard', { id: id }), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        csrf_token: CSRF_TOKEN,
                        id: id
                    })
                });
                if (!res.ok && res.status !== 200) {
                    throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                }
                const data = await res.json();
                if (data.ok) {
                    if (Array.isArray(data.dashboards)) {
                        allDashboards = data.dashboards;
                    } else {
                        allDashboards = allDashboards.filter(x => x.id !== id);
                    }
                    renderDashboardTable(allDashboards);
                    showToast(`Dashboard "${name}" deleted`, 'success');
                    if (activeDashId === id) {
                        closeDashboard();
                    }
                } else {
                    alert('Failed to delete: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Network error: ' + err.message);
            }
        }

        // --- 4. LOAD AGENT GROUPS ---
        async function loadAgentGroups() {
            try {
                const res = await fetch(getApiUrl('get_groups'));
                if (!res.ok && res.status !== 200) return;
                const data = await res.json();
                if (data.ok && Array.isArray(data.groups)) {
                    const groupOptions = data.groups.map(g => {
                        const displayName = cleanText(g.display_name);
                        return `<option value="${g.id}">${escapeHtml(displayName)}</option>`;
                    }).join('');

                    // Populate modal dropdown
                    const modalSelect = document.getElementById('dashModalGroup');
                    if (modalSelect) {
                        modalSelect.innerHTML = '<option value="0">All Agent Groups</option>' + groupOptions;
                    }

                    // Populate canvas toolbar dropdown
                    const canvasSelect = document.getElementById('canvasGroupSelect');
                    if (canvasSelect) {
                        canvasSelect.innerHTML = '<option value="0">All Agent Groups</option>' + groupOptions;
                    }

                    // Populate agent picker group filter
                    const pickerGroupSelect = document.getElementById('agentPickerGroupFilter');
                    if (pickerGroupSelect) {
                        pickerGroupSelect.innerHTML = '<option value="">All Groups</option>' + groupOptions;
                    }
                }
            } catch (e) {
                console.error('Failed to load groups:', e);
            }
        }

        function onCanvasGroupChange(val) {
            loadTopologyData(activeDashId, parseInt(val) || 0);
        }

        // --- 5. TOPOLOGY VISUALIZATION (CYTOSCAPE) ---
        async function loadTopologyData(dashId, customGroupId = null) {
            showLoading(true);
            try {
                const extra = { dashboard_id: dashId || '' };
                if (customGroupId !== null) {
                    extra.group_id = customGroupId;
                }
                const res = await fetch(getApiUrl('get_topology_data', extra));
                if (!res.ok && res.status !== 200) {
                    throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                }
                const data = await res.json();
                showLoading(false);

                if (data.ok) {
                    rawTopologyData = { nodes: data.nodes || [], edges: data.edges || [] };
                    updateStatusCounters(data.stats);
                    renderCytoscapeGraph(rawTopologyData);
                } else {
                    console.warn('Topology error:', data.error);
                }
            } catch (err) {
                showLoading(false);
                console.error('Network load error:', err);
            }
        }

        function updateStatusCounters(stats) {
            if (!stats) return;
            document.getElementById('cntCrit').innerText = stats.critical || 0;
            document.getElementById('cntWarn').innerText = stats.warning || 0;
            document.getElementById('cntOk').innerText = stats.normal || 0;
        }

        function renderCytoscapeGraph(data) {
            const container = document.getElementById('cyCanvas');
            const emptyState = document.getElementById('canvasEmptyState');
            if (!container) return;

            const visibleNodes = (data.nodes || []).filter(n => {
                if (activeCategory !== 'all' && n.category !== activeCategory) return false;
                return true;
            });

            // If zero nodes exist, show clean empty state
            if (visibleNodes.length === 0) {
                if (emptyState) emptyState.classList.remove('d-none');
                container.style.display = 'none';
                if (cy) cy.elements().remove();
                return;
            }

            if (emptyState) emptyState.classList.add('d-none');
            container.style.display = 'block';

            const elements = [];

            // Node elements with official Pandora FMS icon URL
            visibleNodes.forEach(n => {
                elements.push({
                    group: 'nodes',
                    data: {
                        id: n.id,
                        agent_id: n.agent_id,
                        label: cleanText(n.name),
                        role: n.role,
                        category: n.category,
                        icon_url: n.icon_url || (IMAGES_URL + 'devices.svg'),
                        ip: cleanText(n.ip),
                        status: n.status,
                        alert_count: n.alert_count,
                        os: cleanText(n.os),
                        group: cleanText(n.group),
                        tier: n.tier || 3
                    }
                });
            });

            // Active node ids set
            const activeNodeIds = new Set(elements.map(e => e.data.id));

            // Edge elements strictly from real parent links & user custom links
            (data.edges || []).forEach((e, idx) => {
                if (activeNodeIds.has(e.source) && activeNodeIds.has(e.target)) {
                    elements.push({
                        group: 'edges',
                        data: {
                            id: e.id || ('edge-' + idx),
                            source: e.source,
                            target: e.target,
                            label: cleanText(e.label || ''),
                            status: e.status || 'active',
                            is_custom: e.is_custom !== undefined ? e.is_custom : true,
                            source_interface: cleanText(e.source_interface || ''),
                            source_module_id: e.source_module_id || 0,
                            source_status: e.source_status !== undefined ? e.source_status : 0,
                            target_interface: cleanText(e.target_interface || ''),
                            target_module_id: e.target_module_id || 0,
                            target_status: e.target_status !== undefined ? e.target_status : 0
                        }
                    });
                }
            });

            // Initialize or replace Cytoscape
            if (cy) {
                cy.destroy();
            }

            const currentDash = allDashboards.find(d => d.id === activeDashId);
            const preferredLayout = (currentDash && currentDash.layout) ? currentDash.layout : 'dagre';
            const nodeCount = visibleNodes.length;

            cy = cytoscape({
                container: container,
                elements: elements,
                wheelSensitivity: 0.25,
                boxSelectionEnabled: false,
                textureOnViewport: true,
                hideEdgesOnViewport: false,
                pixelRatio: 'auto',
                style: [
                    {
                        selector: 'node',
                        style: {
                            'label': 'data(label)',
                            'color': '#0f172a',
                            'font-family': 'Inter, sans-serif',
                            'font-size': 12,
                            'font-weight': 700,
                            'text-valign': 'bottom',
                            'text-margin-y': 10,
                            'text-wrap': 'ellipsis',
                            'text-max-width': '140px',
                            'width': 56,
                            'height': 56,
                            'background-color': 'transparent',
                            'background-image': function(ele) {
                                return WAVEFORM_NODE_SVG;
                            },
                            'background-fit': 'contain',
                            'background-clip': 'none',
                            'border-width': 0
                        }
                    },
                    {
                        selector: 'node:selected',
                        style: {
                            'border-color': '#094d4a',
                            'border-width': 3,
                            'shadow-blur': 16,
                            'shadow-color': 'rgba(9, 77, 74, 0.45)'
                        }
                    },
                    {
                        selector: 'node.connect-source',
                        style: {
                            'border-color': '#0284c7',
                            'border-width': 4,
                            'shadow-blur': 22,
                            'shadow-color': 'rgba(2, 132, 199, 0.7)',
                            'shadow-opacity': 1
                        }
                    },
                    {
                        selector: 'edge',
                        style: {
                            'width': 2,
                            'line-color': '#cbd5e1',
                            'curve-style': 'bezier',
                            'source-arrow-shape': function(ele) {
                                return ele.data('source_interface') ? 'circle' : 'none';
                            },
                            'source-arrow-color': function(ele) {
                                const s = ele.data('source_status');
                                return (s === 1 || s === 'critical' || s === '1') ? '#ef4444' : '#10b981';
                            },
                            'source-arrow-fill': 'filled',
                            'target-arrow-shape': function(ele) {
                                return ele.data('target_interface') ? 'circle' : 'none';
                            },
                            'target-arrow-color': function(ele) {
                                const s = ele.data('target_status');
                                return (s === 1 || s === 'critical' || s === '1') ? '#ef4444' : '#10b981';
                            },
                            'target-arrow-fill': 'filled',
                            'arrow-scale': 1.15,
                            'source-label': function(ele) {
                                return cleanText(ele.data('source_interface') || '');
                            },
                            'source-text-offset': 38,
                            'source-text-margin-y': -12,
                            'source-text-rotation': 0,
                            'target-label': function(ele) {
                                return cleanText(ele.data('target_interface') || '');
                            },
                            'target-text-offset': 38,
                            'target-text-margin-y': -12,
                            'target-text-rotation': 0,
                            'label': function(ele) {
                                if (ele.data('source_interface') || ele.data('target_interface')) return '';
                                return cleanText(ele.data('label') || '');
                            },
                            'font-family': 'Inter, sans-serif',
                            'font-size': 11,
                            'font-weight': 600,
                            'color': '#1e293b',
                            'text-background-opacity': 0.85,
                            'text-background-color': '#ffffff',
                            'text-background-padding': 2,
                            'text-background-shape': 'roundrectangle'
                        }
                    },
                    {
                        selector: 'edge:selected',
                        style: {
                            'width': 3.5,
                            'line-color': '#094d4a'
                        }
                    }
                ],
                layout: {
                    name: preferredLayout,
                    rankDir: 'TB',
                    nodeSep: 60,
                    rankSep: 80,
                    animate: nodeCount <= 60,
                    animationDuration: 300
                }
            });

            // Node Click Event
            cy.on('tap', 'node', function(evt) {
                hideAllContextMenus();
                const node = evt.target;
                if (isConnectMode) {
                    handleConnectNodeClick(node);
                    return;
                }
                openInspector(node.data());
            });

            // Edge Click Event -> Open Interface Link Modal
            cy.on('tap', 'edge', function(evt) {
                hideAllContextMenus();
                const edge = evt.target;
                if (isConnectMode) return;
                const srcNode = cy.getElementById(edge.data('source'));
                const tgtNode = cy.getElementById(edge.data('target'));
                if (srcNode.length > 0 && tgtNode.length > 0) {
                    openInterfaceLinkModal(srcNode, tgtNode, edge);
                } else {
                    openEdgeInspector(edge);
                }
            });

            // Canvas Click Event -> Close Context Menu & Inspector if background tapped
            cy.on('tap', function(evt) {
                hideAllContextMenus();
                if (evt.target === cy) {
                    if (isConnectMode && connectSourceNode) {
                        connectSourceNode.removeClass('connect-source');
                        connectSourceNode = null;
                        updateConnectBanner();
                        return;
                    }
                    closeInspector();
                }
            });

            // Right-Click Context Menu on Canvas, Node, Edge (Screenshot 1)
            cy.on('cxttap', function(evt) {
                evt.originalEvent.preventDefault();
                hideAllContextMenus();
                const x = evt.originalEvent.clientX;
                const y = evt.originalEvent.clientY;
                if (evt.target === cy) {
                    showCanvasContextMenu(x, y);
                } else if (evt.target.isNode()) {
                    showNodeContextMenu(evt.target, x, y);
                } else if (evt.target.isEdge()) {
                    showEdgeContextMenu(evt.target, x, y);
                }
            });
        }

        // =========================================================================
        // ADD NODE MODAL & 10-AGENT PAGINATION ENGINE (Screenshot 3)
        // =========================================================================
        let addNodePage = 1;
        const ADD_NODE_PAGE_SIZE = 10;
        let addNodeSearchQuery = '';
        let addNodeSelectedGroupId = '';
        let addNodeRecursion = true;
        let addNodePickedAgentId = null;
        let contextActiveNode = null;
        let contextActiveEdge = null;

        const WAVEFORM_NODE_SVG = 'data:image/svg+xml;utf8,' + encodeURIComponent(`
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">
            <circle cx="50" cy="50" r="48" fill="#e11d48"/>
            <rect x="24" y="40" width="6" height="20" rx="3" fill="#ffffff"/>
            <rect x="36" y="28" width="6" height="44" rx="3" fill="#ffffff"/>
            <rect x="47" y="16" width="6" height="68" rx="3" fill="#ffffff"/>
            <rect x="58" y="24" width="6" height="52" rx="3" fill="#ffffff"/>
            <rect x="70" y="40" width="6" height="20" rx="3" fill="#ffffff"/>
        </svg>`);

        async function ensureAgentsLoaded() {
            if (availableAgents.length > 0) return true;
            try {
                const res = await fetch(getApiUrl('get_available_agents'));
                const data = await res.json();
                if (data.ok && Array.isArray(data.agents)) {
                    availableAgents = data.agents;
                    return true;
                }
            } catch (e) {
                console.error("Failed to load agents:", e);
            }
            return false;
        }

        async function openAddNodeModal() {
            if (!activeDashId) return;
            showLoading(true);
            await ensureAgentsLoaded();
            await loadGroupsForAddNode();
            showLoading(false);

            addNodePage = 1;
            addNodeSearchQuery = '';
            addNodeSelectedGroupId = '';
            addNodePickedAgentId = null;
            
            const searchInput = document.getElementById('addNodeAgentSearch');
            if (searchInput) searchInput.value = '';
            const groupSelect = document.getElementById('addNodeGroupSelect');
            if (groupSelect) groupSelect.value = '';

            renderAddNodePage();
            document.getElementById('addNodeModal').style.display = 'flex';
        }

        function closeAddNodeModal() {
            document.getElementById('addNodeModal').style.display = 'none';
        }

        // Backward compatibility alias
        const openAddDevicesModal = openAddNodeModal;
        const closeAddDevicesModal = closeAddNodeModal;

        async function loadGroupsForAddNode() {
            const selectEl = document.getElementById('addNodeGroupSelect');
            if (!selectEl || selectEl.options.length > 1) return;
            try {
                const res = await fetch(getApiUrl('get_groups'));
                const data = await res.json();
                if (data.ok && Array.isArray(data.groups)) {
                    let html = '<option value="">None</option>';
                    data.groups.forEach(g => {
                        html += `<option value="${g.id}">${escapeHtml(g.display_name || g.name)}</option>`;
                    });
                    selectEl.innerHTML = html;
                }
            } catch (e) {}
        }

        function onAddNodeSearch(val) {
            addNodeSearchQuery = (val || '').toLowerCase().trim();
            addNodePage = 1;
            renderAddNodePage();
        }

        function onAddNodeGroupSelect(val) {
            addNodeSelectedGroupId = val;
            addNodePage = 1;
            renderAddNodePage();
        }

        function onAddNodeRecursionChange() {
            const toggle = document.getElementById('addNodeRecursionToggle');
            addNodeRecursion = toggle ? toggle.checked : true;
            addNodePage = 1;
            renderAddNodePage();
        }

        function renderAddNodePage() {
            const tbody = document.getElementById('addNodeTableBody');
            const pageInfo = document.getElementById('addNodePageInfo');
            const pageBtns = document.getElementById('addNodePageButtons');
            if (!tbody) return;

            // Filter agents
            const filtered = availableAgents.filter(a => {
                if (addNodeSelectedGroupId) {
                    if (String(a.group_id) !== String(addNodeSelectedGroupId)) return false;
                }
                if (addNodeSearchQuery) {
                    const matchName = (a.name || '').toLowerCase().includes(addNodeSearchQuery);
                    const matchRaw = (a.raw_name || '').toLowerCase().includes(addNodeSearchQuery);
                    const matchIp = (a.ip || '').toLowerCase().includes(addNodeSearchQuery);
                    const matchGroup = (a.group_name || '').toLowerCase().includes(addNodeSearchQuery);
                    const matchOs = (a.os || '').toLowerCase().includes(addNodeSearchQuery);
                    if (!matchName && !matchRaw && !matchIp && !matchGroup && !matchOs) return false;
                }
                return true;
            });

            const total = filtered.length;
            const totalPages = Math.max(1, Math.ceil(total / ADD_NODE_PAGE_SIZE));
            if (addNodePage > totalPages) addNodePage = totalPages;
            if (addNodePage < 1) addNodePage = 1;

            const startIndex = (addNodePage - 1) * ADD_NODE_PAGE_SIZE;
            const endIndex = Math.min(startIndex + ADD_NODE_PAGE_SIZE, total);
            const pageItems = filtered.slice(startIndex, endIndex);

            // Update Page Info
            if (pageInfo) {
                if (total === 0) {
                    pageInfo.innerText = '0 agents found';
                } else {
                    pageInfo.innerText = `Showing ${startIndex + 1}-${endIndex} of ${total} agents`;
                }
            }

            // Render Table Rows
            if (pageItems.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" style="text-align:center; padding:24px; color:#94a3b8;">
                            No agents match the search criteria.
                        </td>
                    </tr>
                `;
            } else {
                let html = '';
                pageItems.forEach(a => {
                    const isSelected = (addNodePickedAgentId === a.id);
                    const statusBadge = a.status === 'critical' ? '<span style="color:#ef4444; font-weight:600;">Critical</span>' : (a.status === 'warning' ? '<span style="color:#f59e0b; font-weight:600;">Warning</span>' : '<span style="color:#10b981; font-weight:600;">Normal</span>');
                    html += `
                        <tr class="${isSelected ? 'selected' : ''}" onclick="selectAddNodeAgent(${a.id})">
                            <td style="text-align:center;">
                                <input type="radio" name="add_node_agent_choice" value="${a.id}" ${isSelected ? 'checked' : ''} onclick="event.stopPropagation(); selectAddNodeAgent(${a.id});">
                            </td>
                            <td>
                                <strong style="color:#0f172a;">${escapeHtml(cleanText(a.name))}</strong>
                            </td>
                            <td>${escapeHtml(cleanText(a.ip))}</td>
                            <td>${escapeHtml(cleanText(a.group_name))}</td>
                            <td>${statusBadge}</td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            }

            // Render Pagination Buttons
            if (pageBtns) {
                let btnHtml = '';
                btnHtml += `<button type="button" class="page-btn" ${addNodePage <= 1 ? 'disabled' : ''} onclick="changeAddNodePage(${addNodePage - 1})">&laquo; Prev</button>`;
                
                let startPage = Math.max(1, addNodePage - 2);
                let endPage = Math.min(totalPages, startPage + 4);
                if (endPage - startPage < 4) {
                    startPage = Math.max(1, endPage - 4);
                }

                for (let p = startPage; p <= endPage; p++) {
                    btnHtml += `<button type="button" class="page-btn ${p === addNodePage ? 'active' : ''}" onclick="changeAddNodePage(${p})">${p}</button>`;
                }

                btnHtml += `<button type="button" class="page-btn" ${addNodePage >= totalPages ? 'disabled' : ''} onclick="changeAddNodePage(${addNodePage + 1})">Next &raquo;</button>`;
                pageBtns.innerHTML = btnHtml;
            }
        }

        function changeAddNodePage(p) {
            addNodePage = p;
            renderAddNodePage();
        }

        function selectAddNodeAgent(agentId) {
            addNodePickedAgentId = agentId;
            renderAddNodePage();
        }

        async function submitAddAgentNode() {
            if (!activeDashId) return;
            if (!addNodePickedAgentId) {
                alert('Please select an agent to add.');
                return;
            }

            const currentDash = allDashboards.find(d => d.id === activeDashId);
            let currentList = [];
            if (currentDash && Array.isArray(currentDash.device_ids)) {
                currentList = currentDash.device_ids.map(x => parseInt(x));
            } else if (rawTopologyData && Array.isArray(rawTopologyData.nodes)) {
                currentList = rawTopologyData.nodes.map(n => parseInt(n.agent_id)).filter(x => x > 0);
            }

            if (currentList.includes(addNodePickedAgentId)) {
                showToast('Agent is already on this topology map', 'warning');
                closeAddNodeModal();
                return;
            }

            currentList.push(addNodePickedAgentId);
            const btn = document.getElementById('btnAddAgentNodeSubmit');
            btn.disabled = true;
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<span class="material-symbols-outlined spin-icon">progress_activity</span> Adding...';

            try {
                const res = await fetch(getApiUrl('save_dashboard_devices'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        csrf_token: CSRF_TOKEN,
                        dashboard_id: activeDashId,
                        device_ids: currentList
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    if (currentDash) {
                        currentDash.device_ids = currentList;
                        currentDash.node_count = currentList.length;
                    }
                    closeAddNodeModal();
                    showToast('Agent added to topology map', 'success');
                    loadTopologyData(activeDashId);
                } else {
                    alert('Failed to add agent: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Network error: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }

        // =========================================================================
        // INTERFACE LINK MODAL ENGINE (Screenshot 4)
        // =========================================================================
        let currentLinkSourceNode = null;
        let currentLinkTargetNode = null;
        let currentEditingEdgeId = null;

        async function openInterfaceLinkModal(sourceNode, targetNode, existingEdge = null) {
            currentLinkSourceNode = sourceNode;
            currentLinkTargetNode = targetNode;
            currentEditingEdgeId = existingEdge ? existingEdge.id() : null;

            const srcName = cleanText(sourceNode.data('label') || sourceNode.id());
            const tgtName = cleanText(targetNode.data('label') || targetNode.id());
            const srcAgentId = sourceNode.data('agent_id') || sourceNode.id().replace('agent-', '');
            const tgtAgentId = targetNode.data('agent_id') || targetNode.id().replace('agent-', '');

            document.getElementById('linkSourceNodeId').value = sourceNode.id();
            document.getElementById('linkTargetNodeId').value = targetNode.id();
            document.getElementById('linkSourceNodeName').innerText = srcName;
            document.getElementById('linkTargetNodeName').innerText = tgtName;

            const srcSelect = document.getElementById('linkSourceInterfaceSelect');
            const tgtSelect = document.getElementById('linkTargetInterfaceSelect');
            srcSelect.innerHTML = '<option value="">Loading...</option>';
            tgtSelect.innerHTML = '<option value="">Loading...</option>';

            const deleteBtn = document.getElementById('btnDeleteInterfaceLink');
            if (deleteBtn) deleteBtn.style.display = existingEdge ? 'inline-flex' : 'none';

            const loadingEl = document.getElementById('interfaceLinkLoading');
            if (loadingEl) loadingEl.style.display = 'block';

            document.getElementById('interfaceLinkModal').style.display = 'flex';

            // Fetch interfaces for both nodes in parallel
            try {
                const [resSrc, resTgt] = await Promise.all([
                    fetch(getApiUrl('get_agent_interfaces', { id_agent: srcAgentId })),
                    fetch(getApiUrl('get_agent_interfaces', { id_agent: tgtAgentId }))
                ]);
                const dataSrc = await resSrc.json();
                const dataTgt = await resTgt.json();

                if (loadingEl) loadingEl.style.display = 'none';

                populateInterfaceSelect(srcSelect, dataSrc.interfaces || []);
                populateInterfaceSelect(tgtSelect, dataTgt.interfaces || []);

                // If editing existing edge, preselect
                if (existingEdge) {
                    const eData = existingEdge.data();
                    if (eData.source_interface) {
                        selectMatchingOption(srcSelect, eData.source_interface, eData.source_module_id);
                    }
                    if (eData.target_interface) {
                        selectMatchingOption(tgtSelect, eData.target_interface, eData.target_module_id);
                    }
                }
            } catch (err) {
                if (loadingEl) loadingEl.style.display = 'none';
                srcSelect.innerHTML = '<option value="">None</option>';
                tgtSelect.innerHTML = '<option value="">None</option>';
                console.error("Error loading interfaces:", err);
            }
        }

        function populateInterfaceSelect(selectEl, interfaces) {
            let html = '<option value="" data-id="0" data-status="0">None</option>';
            interfaces.forEach(itf => {
                const cleanName = cleanText(itf.clean_port || itf.name);
                const fullName = cleanText(itf.name);
                const statusDot = (itf.estado === 1) ? '● (Down)' : (itf.estado === 0 ? '● (Up)' : '●');
                html += `<option value="${escapeHtml(cleanName)}" data-id="${itf.id}" data-status="${itf.estado}" data-fullname="${escapeHtml(fullName)}">
                    ${escapeHtml(fullName)} ${statusDot}
                </option>`;
            });
            selectEl.innerHTML = html;
        }

        function selectMatchingOption(selectEl, ifaceName, modId) {
            for (let i = 0; i < selectEl.options.length; i++) {
                const opt = selectEl.options[i];
                if (modId && opt.getAttribute('data-id') == modId) {
                    selectEl.selectedIndex = i;
                    return;
                }
                if (opt.value === ifaceName || opt.getAttribute('data-fullname') === ifaceName) {
                    selectEl.selectedIndex = i;
                    return;
                }
            }
        }

        function closeInterfaceLinkModal() {
            document.getElementById('interfaceLinkModal').style.display = 'none';
            currentLinkSourceNode = null;
            currentLinkTargetNode = null;
            currentEditingEdgeId = null;
        }

        async function submitInterfaceLink() {
            if (!currentLinkSourceNode || !currentLinkTargetNode || !activeDashId) return;

            const srcSelect = document.getElementById('linkSourceInterfaceSelect');
            const tgtSelect = document.getElementById('linkTargetInterfaceSelect');
            const srcOpt = srcSelect.options[srcSelect.selectedIndex];
            const tgtOpt = tgtSelect.options[tgtSelect.selectedIndex];

            const srcIface = srcOpt ? srcOpt.value : '';
            const srcModId = srcOpt ? parseInt(srcOpt.getAttribute('data-id') || 0) : 0;
            const srcStatus = srcOpt ? parseInt(srcOpt.getAttribute('data-status') || 0) : 0;

            const tgtIface = tgtOpt ? tgtOpt.value : '';
            const tgtModId = tgtOpt ? parseInt(tgtOpt.getAttribute('data-id') || 0) : 0;
            const tgtStatus = tgtOpt ? parseInt(tgtOpt.getAttribute('data-status') || 0) : 0;

            const sourceId = currentLinkSourceNode.id();
            const targetId = currentLinkTargetNode.id();
            const edgeId = currentEditingEdgeId || ('custom-' + sourceId.replace(/[^a-zA-Z0-9_\-]/g, '') + '-' + targetId.replace(/[^a-zA-Z0-9_\-]/g, ''));

            const btn = document.getElementById('btnAddInterfaceLinkSubmit');
            btn.disabled = true;
            const origHtml = btn.innerHTML;
            btn.innerHTML = '<span class="material-symbols-outlined spin-icon">progress_activity</span> Saving link...';

            try {
                const res = await fetch(getApiUrl('save_topology_edge'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        csrf_token: CSRF_TOKEN,
                        dashboard_id: activeDashId,
                        source: sourceId,
                        target: targetId,
                        source_interface: srcIface,
                        source_module_id: srcModId,
                        source_status: srcStatus,
                        target_interface: tgtIface,
                        target_module_id: tgtModId,
                        target_status: tgtStatus
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    const existing = cy.getElementById(edgeId);
                    const edgeData = {
                        id: edgeId,
                        source: sourceId,
                        target: targetId,
                        source_interface: srcIface,
                        source_module_id: srcModId,
                        source_status: srcStatus,
                        target_interface: tgtIface,
                        target_module_id: tgtModId,
                        target_status: tgtStatus,
                        status: (srcStatus === 1 || tgtStatus === 1) ? 'critical' : 'active',
                        is_custom: true
                    };

                    if (existing && existing.length > 0) {
                        existing.data(edgeData);
                    } else {
                        cy.add({ group: 'edges', data: edgeData });
                    }

                    closeInterfaceLinkModal();
                    exitConnectMode();
                    showToast(`Interface link connected between ${cleanText(currentLinkSourceNode.data('label'))} and ${cleanText(currentLinkTargetNode.data('label'))}`, 'success');
                } else {
                    alert('Failed to save interface link: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Network error while saving interface link: ' + err.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = origHtml;
            }
        }

        async function deleteCurrentInterfaceLink() {
            if (!currentEditingEdgeId) return;
            const edgeId = currentEditingEdgeId;
            closeInterfaceLinkModal();
            await deleteEdgeById(edgeId);
        }

        // =========================================================================
        // CONTEXT MENU ACTIONS (Screenshot 1)
        // =========================================================================
        function showCanvasContextMenu(x, y) {
            const menu = document.getElementById('canvasContextMenu');
            if (!menu) return;
            menu.style.left = Math.min(x, window.innerWidth - 190) + 'px';
            menu.style.top = Math.min(y, window.innerHeight - 160) + 'px';
            menu.style.display = 'block';
        }

        function showNodeContextMenu(node, x, y) {
            contextActiveNode = node;
            const menu = document.getElementById('nodeContextMenu');
            if (!menu) return;
            menu.style.left = Math.min(x, window.innerWidth - 200) + 'px';
            menu.style.top = Math.min(y, window.innerHeight - 160) + 'px';
            menu.style.display = 'block';
        }

        function showEdgeContextMenu(edge, x, y) {
            contextActiveEdge = edge;
            const menu = document.getElementById('edgeContextMenu');
            if (!menu) return;
            menu.style.left = Math.min(x, window.innerWidth - 190) + 'px';
            menu.style.top = Math.min(y, window.innerHeight - 140) + 'px';
            menu.style.display = 'block';
        }

        function hideAllContextMenus() {
            ['canvasContextMenu', 'nodeContextMenu', 'edgeContextMenu'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = 'none';
            });
        }
        document.addEventListener('click', hideAllContextMenus);

        function openAddNodeModalFromContext() {
            hideAllContextMenus();
            openAddNodeModal();
        }

        function loadNewNodesFromContext() {
            hideAllContextMenus();
            showToast('Scanning and loading new nodes from Pandora FMS...', 'info');
            refreshCurrentTopology();
        }

        function redrawMapFromContext() {
            hideAllContextMenus();
            const currentDash = allDashboards.find(d => d.id === activeDashId);
            const prefLayout = (currentDash && currentDash.layout) ? currentDash.layout : 'dagre';
            changeLayout(prefLayout);
            showToast('Redrawn map layout', 'success');
        }

        function connectFromNodeContext() {
            hideAllContextMenus();
            if (!contextActiveNode) return;
            enterConnectMode();
            handleConnectNodeClick(contextActiveNode);
        }

        function inspectFromNodeContext() {
            hideAllContextMenus();
            if (!contextActiveNode) return;
            openInspector(contextActiveNode.data());
        }

        function removeFromNodeContext() {
            hideAllContextMenus();
            if (!contextActiveNode) return;
            currentInspectedAgent = contextActiveNode.data();
            removeCurrentDeviceFromDashboard();
        }

        function editEdgeFromContext() {
            hideAllContextMenus();
            if (!contextActiveEdge) return;
            const srcNode = cy.getElementById(contextActiveEdge.data('source'));
            const tgtNode = cy.getElementById(contextActiveEdge.data('target'));
            if (srcNode.length > 0 && tgtNode.length > 0) {
                openInterfaceLinkModal(srcNode, tgtNode, contextActiveEdge);
            }
        }

        function deleteEdgeFromContext() {
            hideAllContextMenus();
            if (!contextActiveEdge) return;
            deleteEdgeById(contextActiveEdge.id());
        }

        async function removeCurrentDeviceFromDashboard() {
            if (!activeDashId || !currentInspectedAgent || !currentInspectedAgent.agent_id) return;
            const aid = parseInt(currentInspectedAgent.agent_id);
            const name = currentInspectedAgent.label || aid;

            if (!confirm(`Remove "${name}" from this topology dashboard?`)) return;

            const currentDash = allDashboards.find(d => d.id === activeDashId);
            let currentList = [];
            if (currentDash && Array.isArray(currentDash.device_ids) && currentDash.device_ids.length > 0) {
                currentList = currentDash.device_ids.map(x => parseInt(x));
            } else if (rawTopologyData && Array.isArray(rawTopologyData.nodes)) {
                currentList = rawTopologyData.nodes.map(n => parseInt(n.agent_id)).filter(x => x > 0);
            }

            const updatedList = currentList.filter(id => id !== aid);

            try {
                const res = await fetch(getApiUrl('save_dashboard_devices'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        csrf_token: CSRF_TOKEN,
                        dashboard_id: activeDashId,
                        device_ids: updatedList
                    })
                });
                if (!res.ok && res.status !== 200) {
                    throw new Error(`HTTP ${res.status}: ${res.statusText}`);
                }
                const data = await res.json();
                if (data.ok) {
                    if (currentDash) {
                        currentDash.device_ids = updatedList;
                        currentDash.node_count = updatedList.length;
                    }
                    closeInspector();
                    showToast(`Removed device "${name}"`, 'success');
                    loadTopologyData(activeDashId);
                } else {
                    alert('Failed to remove device: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Network error: ' + err.message);
            }
        }

        // --- 7. CONTROLS & INSPECTOR ---
        function setCategoryFilter(category, btn) {
            activeCategory = category;
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
            renderCytoscapeGraph(rawTopologyData);
        }

        function changeLayout(layoutName) {
            if (!cy) return;
            let layoutOptions = { name: layoutName, animate: true, animationDuration: 400 };
            if (layoutName === 'dagre') {
                layoutOptions.rankDir = 'TB';
                layoutOptions.nodeSep = 60;
                layoutOptions.rankSep = 80;
            }
            cy.layout(layoutOptions).run();
        }

        function fitTopologyView() {
            if (cy) {
                cy.fit(null, 40);
            }
        }

        function zoomIn() { if (cy) cy.zoom({ level: cy.zoom() * 1.25, renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } }); }
        function zoomOut() { if (cy) cy.zoom({ level: cy.zoom() * 0.8, renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } }); }

        function onDeviceSearch(query) {
            query = (query || '').toLowerCase().trim();
            if (!cy) return;

            if (!query) {
                cy.nodes().style('opacity', 1);
                cy.edges().style('opacity', 1);
                return;
            }

            const matched = cy.nodes().filter(node => {
                const name = (node.data('label') || '').toLowerCase();
                const ip = (node.data('ip') || '').toLowerCase();
                return name.includes(query) || ip.includes(query);
            });

            if (matched.length > 0) {
                cy.nodes().style('opacity', 0.25);
                cy.edges().style('opacity', 0.1);
                matched.style('opacity', 1);
                matched.connectedEdges().style('opacity', 0.8);
                cy.animate({
                    center: { eles: matched },
                    zoom: 1.2,
                    duration: 400
                });
            } else {
                cy.nodes().style('opacity', 0.25);
                cy.edges().style('opacity', 0.1);
            }
        }

        // --- CONNECT MODE & LINK MANAGEMENT ---
        let isConnectMode = false;
        let connectSourceNode = null;
        let currentInspectedEdge = null;

        function toggleConnectMode() {
            if (isConnectMode) {
                exitConnectMode();
            } else {
                enterConnectMode();
            }
        }

        function enterConnectMode() {
            if (!cy) return;
            isConnectMode = true;
            connectSourceNode = null;
            const btn = document.getElementById('btnConnectMode');
            if (btn) btn.classList.add('active-connect');
            const lbl = document.getElementById('connectModeLabel');
            if (lbl) lbl.innerText = 'Connecting...';
            const banner = document.getElementById('connectModeBanner');
            if (banner) banner.classList.remove('d-none');
            updateConnectBanner();
            closeInspector();
        }

        function exitConnectMode() {
            isConnectMode = false;
            if (connectSourceNode) {
                connectSourceNode.removeClass('connect-source');
                connectSourceNode = null;
            }
            const btn = document.getElementById('btnConnectMode');
            if (btn) btn.classList.remove('active-connect');
            const lbl = document.getElementById('connectModeLabel');
            if (lbl) lbl.innerText = 'Connect Devices';
            const banner = document.getElementById('connectModeBanner');
            if (banner) banner.classList.add('d-none');
        }

        function updateConnectBanner() {
            const textEl = document.getElementById('connectBannerText');
            if (!textEl) return;
            if (!connectSourceNode) {
                textEl.innerHTML = '<strong>Connect Mode:</strong> Click the first device (Source)...';
            } else {
                const srcName = escapeHtml(connectSourceNode.data('label') || 'Device');
                textEl.innerHTML = `<strong>Source: ${srcName}</strong>. Now click the second device (Target)...`;
            }
        }

        function handleConnectNodeClick(node) {
            if (!connectSourceNode) {
                connectSourceNode = node;
                node.addClass('connect-source');
                updateConnectBanner();
                showToast(`Source selected: ${node.data('label')}. Now click target device.`, 'info');
            } else if (connectSourceNode.id() === node.id()) {
                node.removeClass('connect-source');
                connectSourceNode = null;
                updateConnectBanner();
                showToast('Deselected source device', 'info');
            } else {
                const srcNode = connectSourceNode;
                const tgtNode = node;
                srcNode.removeClass('connect-source');
                connectSourceNode = null;
                updateConnectBanner();
                openInterfaceLinkModal(srcNode, tgtNode);
            }
        }

        async function establishDeviceConnection(sourceId, targetId, srcName = '', tgtName = '', label = '') {
            if (!cy || !activeDashId) return;
            if (sourceId === targetId) return;

            // Check if link already exists in cy
            const existing = cy.edges().filter(e => 
                (e.data('source') === sourceId && e.data('target') === targetId) ||
                (e.data('source') === targetId && e.data('target') === sourceId)
            );
            if (existing.length > 0) {
                showToast(`Already connected: ${srcName || sourceId} ── ${tgtName || targetId}`, 'warning');
                return;
            }

            const edgeId = 'custom-' + sourceId.replace(/[^a-zA-Z0-9_\-]/g, '') + '-' + targetId.replace(/[^a-zA-Z0-9_\-]/g, '');

            // Add instantly to Cytoscape for snappy UX
            cy.add({
                group: 'edges',
                data: {
                    id: edgeId,
                    source: sourceId,
                    target: targetId,
                    label: cleanText(label),
                    status: 'active',
                    is_custom: true
                }
            });

            showToast(`Connected ${srcName || sourceId} ─── ${tgtName || targetId}`, 'success');

            // Persist to backend
            try {
                const res = await fetch(getApiUrl('save_topology_edge'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        csrf_token: CSRF_TOKEN,
                        dashboard_id: activeDashId,
                        source: sourceId,
                        target: targetId,
                        label: label
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    if (rawTopologyData && Array.isArray(rawTopologyData.edges)) {
                        rawTopologyData.edges.push({
                            id: edgeId,
                            source: sourceId,
                            target: targetId,
                            label: label,
                            status: 'active',
                            is_custom: true
                        });
                    }
                    // If node inspector is open for one of the endpoints, refresh links list
                    if (currentInspectedAgent && (currentInspectedAgent.id === sourceId || currentInspectedAgent.id === targetId)) {
                        renderDrawerConnectedLinks(currentInspectedAgent.id);
                    }
                } else {
                    showToast('Warning: Link could not be saved to server: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                showToast('Network error while saving link: ' + err.message, 'error');
            }
        }

        async function deleteEdgeById(edgeId) {
            if (!cy || !activeDashId) return;
            const edge = cy.getElementById(edgeId);
            if (!edge || edge.length === 0) return;

            const edgeData = edge.data();
            const srcId = edgeData.source;
            const tgtId = edgeData.target;
            const srcNode = cy.getElementById(srcId);
            const tgtNode = cy.getElementById(tgtId);
            const srcName = srcNode.length > 0 ? srcNode.data('label') : srcId;
            const tgtName = tgtNode.length > 0 ? tgtNode.data('label') : tgtId;

            if (!confirm(`Delete connection link between "${srcName}" and "${tgtName}"?`)) return;

            try {
                const res = await fetch(getApiUrl('delete_topology_edge'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        csrf_token: CSRF_TOKEN,
                        dashboard_id: activeDashId,
                        edge_id: edgeId,
                        source: srcId,
                        target: tgtId
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    cy.remove(edge);
                    showToast(`Deleted link between ${srcName} and ${tgtName}`, 'success');
                    if (rawTopologyData && Array.isArray(rawTopologyData.edges)) {
                        rawTopologyData.edges = rawTopologyData.edges.filter(e => 
                            e.id !== edgeId && !(e.source === srcId && e.target === tgtId) && !(e.source === tgtId && e.target === srcId)
                        );
                    }
                    if (currentInspectedAgent) {
                        renderDrawerConnectedLinks(currentInspectedAgent.id);
                    } else {
                        closeInspector();
                    }
                } else {
                    alert('Failed to delete link: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Network error while deleting link: ' + err.message);
            }
        }

        async function deleteCurrentInspectedEdge() {
            if (!currentInspectedEdge) return;
            const edgeId = currentInspectedEdge.id();
            await deleteEdgeById(edgeId);
        }

        function renderDrawerConnectedLinks(nodeId) {
            const listEl = document.getElementById('drawerConnectedLinksList');
            const badgeEl = document.getElementById('drawerConnectedLinksBadge');
            if (!listEl || !cy) return;

            const node = cy.getElementById(nodeId);
            if (!node || node.length === 0) return;

            const connectedEdges = node.connectedEdges();
            if (badgeEl) badgeEl.innerText = `${connectedEdges.length} link${connectedEdges.length === 1 ? '' : 's'}`;

            if (connectedEdges.length === 0) {
                listEl.innerHTML = '<div style="font-size:12px; color:var(--text-muted); font-style:italic; padding:4px 0;">No active links connected to this device.</div>';
                return;
            }

            let html = '';
            connectedEdges.forEach(edge => {
                const isSource = edge.data('source') === nodeId;
                const peerId = isSource ? edge.data('target') : edge.data('source');
                const peerNode = cy.getElementById(peerId);
                const peerName = peerNode.length > 0 ? escapeHtml(peerNode.data('label')) : peerId;
                const peerIcon = peerNode.length > 0 ? peerNode.data('icon_url') : (IMAGES_URL + 'devices.svg');
                const edgeLabel = escapeHtml(edge.data('label') || '');
                const edgeStatus = edge.data('status') || 'active';
                const statusColor = edgeStatus === 'critical' ? '#ef4444' : (edgeStatus === 'warning' ? '#f59e0b' : '#10b981');

                html += `
                    <div class="link-peer-item">
                        <div class="link-peer-info">
                            <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:${statusColor}; flex-shrink:0;"></span>
                            <img src="${peerIcon}" style="width:16px; height:16px; object-fit:contain; flex-shrink:0;" alt="">
                            <strong style="color:var(--text-dark);">${peerName}</strong>
                            ${edgeLabel ? `<span style="color:var(--text-muted); font-size:11px;">(${edgeLabel})</span>` : ''}
                        </div>
                        <button type="button" class="btn-del-link" onclick="deleteEdgeById('${edge.id()}')" title="Delete this connection">
                            <span class="material-symbols-outlined" style="font-size:16px;">delete</span>
                        </button>
                    </div>
                `;
            });

            listEl.innerHTML = html;
        }

        function toggleDrawerConnectSection() {
            const box = document.getElementById('drawerConnectBox');
            if (!box) return;
            const isHidden = box.style.display === 'none';
            box.style.display = isHidden ? 'block' : 'none';

            if (isHidden && currentInspectedAgent && cy) {
                // Populate target dropdown with all other nodes in canvas
                const select = document.getElementById('drawerTargetDeviceSelect');
                if (select) {
                    const otherNodes = cy.nodes().filter(n => n.id() !== currentInspectedAgent.id);
                    select.innerHTML = otherNodes.map(n => {
                        return `<option value="${n.id()}">${escapeHtml(n.data('label'))} (${n.data('ip') || '-'})</option>`;
                    }).join('');
                }
            }
        }

        function connectFromDrawer() {
            if (!currentInspectedAgent || !cy) return;
            const select = document.getElementById('drawerTargetDeviceSelect');
            if (!select || !select.value) return;

            const targetId = select.value;
            const srcNode = cy.getElementById(currentInspectedAgent.id);
            const tgtNode = cy.getElementById(targetId);

            const box = document.getElementById('drawerConnectBox');
            if (box) box.style.display = 'none';

            if (srcNode.length > 0 && tgtNode.length > 0) {
                openInterfaceLinkModal(srcNode, tgtNode);
            }
        }

        async function changeInspectedDeviceRole(newRole) {
            if (!currentInspectedAgent || !currentInspectedAgent.agent_id || !activeDashId || !cy) return;
            const aid = parseInt(currentInspectedAgent.agent_id);
            const nodeEle = cy.getElementById(currentInspectedAgent.id);

            try {
                const res = await fetch(getApiUrl('save_device_role'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        csrf_token: CSRF_TOKEN,
                        dashboard_id: activeDashId,
                        agent_id: aid,
                        role: newRole
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    showToast(`Role updated to ${newRole.toUpperCase()}`, 'success');
                    if (nodeEle && nodeEle.length > 0) {
                        nodeEle.data('role', newRole);
                        nodeEle.data('category', data.category);
                        nodeEle.data('icon_url', data.icon_url);
                    }
                    currentInspectedAgent.role = newRole;
                    currentInspectedAgent.icon_url = data.icon_url;
                    currentInspectedAgent.category = data.category;
                    document.getElementById('drawerRoleTitle').innerText = newRole.toUpperCase();
                    const iconImg = document.getElementById('drawerIconImg');
                    if (iconImg) iconImg.src = data.icon_url;
                } else {
                    alert('Failed to update role: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Network error while updating role: ' + err.message);
            }
        }

        function openInspector(data) {
            currentInspectedAgent = data;
            currentInspectedEdge = null;

            document.getElementById('drawerHeaderTitle').innerHTML = '<span class="material-symbols-outlined" style="color:var(--brand-green);">info</span> Device Inspector';
            document.getElementById('drawerNodeContent').style.display = 'block';
            document.getElementById('drawerEdgeContent').style.display = 'none';

            document.getElementById('drawerNodeName').innerText = cleanText(data.label);
            document.getElementById('drawerRoleTitle').innerText = cleanText(data.role.toUpperCase());
            document.getElementById('drawerIp').innerText = cleanText(data.ip || '0.0.0.0');
            document.getElementById('drawerGroup').innerText = cleanText(data.group || 'Infrastructure');
            document.getElementById('drawerOs').innerText = cleanText(data.os || 'Unknown OS');
            document.getElementById('drawerAlertCount').innerText = data.alert_count || 0;

            const roleSelect = document.getElementById('drawerRoleSelect');
            if (roleSelect) {
                roleSelect.value = data.role;
            }

            const iconImg = document.getElementById('drawerIconImg');
            if (iconImg) {
                iconImg.src = data.icon_url || (IMAGES_URL + 'devices.svg');
            }

            const stBadge = document.getElementById('drawerStatusBadge');
            if (data.status === 'critical') {
                stBadge.innerHTML = '<span class="badge-pill badge-crit">Critical Alert</span>';
            } else if (data.status === 'warning') {
                stBadge.innerHTML = '<span class="badge-pill badge-warn">Warning</span>';
            } else {
                stBadge.innerHTML = '<span class="badge-pill badge-ok">Normal</span>';
            }

            // Close inline connect box if open
            const connectBox = document.getElementById('drawerConnectBox');
            if (connectBox) connectBox.style.display = 'none';

            // Populate connected links
            renderDrawerConnectedLinks(data.id);

            document.getElementById('inspectorDrawer').classList.add('open');
        }

        function openEdgeInspector(edge) {
            currentInspectedEdge = edge;
            currentInspectedAgent = null;

            document.getElementById('drawerHeaderTitle').innerHTML = '<span class="material-symbols-outlined" style="color:#2563eb;">share</span> Connection Link';
            document.getElementById('drawerNodeContent').style.display = 'none';
            document.getElementById('drawerEdgeContent').style.display = 'block';

            const edgeData = edge.data();
            const srcId = edgeData.source;
            const tgtId = edgeData.target;
            const srcNode = cy.getElementById(srcId);
            const tgtNode = cy.getElementById(tgtId);

            document.getElementById('drawerEdgeSrcName').innerText = srcNode.length > 0 ? srcNode.data('label') : srcId;
            document.getElementById('drawerEdgeTgtName').innerText = tgtNode.length > 0 ? tgtNode.data('label') : tgtId;
            document.getElementById('drawerEdgeLabel').innerText = edgeData.label || 'None';

            const badgeEl = document.getElementById('drawerEdgeTypeBadge');
            if (badgeEl) {
                badgeEl.innerText = edgeData.is_custom ? 'Custom Link' : 'Parent Hierarchy';
            }

            const statusEl = document.getElementById('drawerEdgeStatus');
            if (statusEl) {
                const s = edgeData.status || 'active';
                if (s === 'critical') {
                    statusEl.innerHTML = '<span class="badge-pill badge-crit">Critical</span>';
                } else if (s === 'warning') {
                    statusEl.innerHTML = '<span class="badge-pill badge-warn">Warning</span>';
                } else {
                    statusEl.innerHTML = '<span class="badge-pill badge-ok">Active</span>';
                }
            }

            document.getElementById('inspectorDrawer').classList.add('open');
        }

        function closeInspector() {
            currentInspectedAgent = null;
            currentInspectedEdge = null;
            const drawer = document.getElementById('inspectorDrawer');
            if (drawer) drawer.classList.remove('open');
            if (cy) {
                cy.edges().unselect();
            }
        }

        function exportTopologyImage() {
            if (!cy) return;
            const pngData = cy.png({ full: true, scale: 2, bg: '#ffffff' });
            const link = document.createElement('a');
            link.download = 'network-topology-' + Date.now() + '.png';
            link.href = pngData;
            link.click();
        }

        function showLoading(show) {
            const el = document.getElementById('loadingOverlay');
            if (el) el.style.display = show ? 'flex' : 'none';
        }

        function escapeHtml(text) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text || '').replace(/[&<>"']/g, m => map[m]);
        }

        // Keyboard shortcuts (Escape exits connect mode & closes drawer)
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                if (isConnectMode) {
                    exitConnectMode();
                }
                closeInspector();
            }
        });

        // --- 8. INITIALIZATION ON READY ---
        document.addEventListener('DOMContentLoaded', () => {
            renderDashboardTable(allDashboards);

            if (activeDashId) {
                openDashboard(activeDashId);
            }

            // Lazy load agent groups in background so it doesn't block initial render
            setTimeout(loadAgentGroups, 150);
        });
    </script>
</body>
</html>
