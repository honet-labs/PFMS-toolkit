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
    header("Location: " . ($pandora_base ?: '') . "/index.php");
    exit;
}

$DASHBOARD_FILE = __DIR__ . '/topology_dashboards.json';

// Helper: load dashboards
function load_topology_dashboards(string $file): array {
    if (!file_exists($file)) return [];
    $raw = @file_get_contents($file);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

// Helper: save dashboards
function save_topology_dashboards(string $file, array $data): bool {
    return @file_put_contents($file, json_encode(array_values($data), JSON_PRETTY_PRINT)) !== false;
}

// =====================================================================
// DEVICE CLASSIFIER & SDDC REFERENCE DEMO ENGINE
// =====================================================================
class TopologyDeviceClassifier {
    const ROLE_VM         = 'vm';
    const ROLE_HYPERVISOR = 'hypervisor';
    const ROLE_CLUSTER    = 'cluster';
    const ROLE_DATACENTER = 'datacenter';
    const ROLE_STORAGE    = 'storage';
    const ROLE_VCENTER    = 'vcenter';
    const ROLE_SWITCH     = 'switch';
    const ROLE_ROUTER     = 'router';
    const ROLE_FIREWALL   = 'firewall';
    const ROLE_SERVER     = 'server';

    public static function getRoles(): array {
        return [
            self::ROLE_VM => [
                'id' => self::ROLE_VM,
                'title' => 'VMware vSphere VM',
                'short_title' => 'VM',
                'category' => 'compute',
                'badge_color' => '#3b82f6',
                'rank' => 1
            ],
            self::ROLE_HYPERVISOR => [
                'id' => self::ROLE_HYPERVISOR,
                'title' => 'VMware vSphere Hypervisor',
                'short_title' => 'Hypervisor',
                'category' => 'compute',
                'badge_color' => '#1e293b',
                'rank' => 2
            ],
            self::ROLE_CLUSTER => [
                'id' => self::ROLE_CLUSTER,
                'title' => 'VMware vSphere Cluster',
                'short_title' => 'Cluster',
                'category' => 'compute',
                'badge_color' => '#475569',
                'rank' => 3
            ],
            self::ROLE_DATACENTER => [
                'id' => self::ROLE_DATACENTER,
                'title' => 'VMware vSphere Datacenter',
                'short_title' => 'Datacenter',
                'category' => 'compute',
                'badge_color' => '#0f172a',
                'rank' => 4
            ],
            self::ROLE_STORAGE => [
                'id' => self::ROLE_STORAGE,
                'title' => 'VMware vSphere Datastore',
                'short_title' => 'Datastore',
                'category' => 'storage',
                'badge_color' => '#0284c7',
                'rank' => 2
            ],
            self::ROLE_VCENTER => [
                'id' => self::ROLE_VCENTER,
                'title' => 'VMware vSphere vCenter',
                'short_title' => 'vCenter',
                'category' => 'management',
                'badge_color' => '#64748b',
                'rank' => 3
            ],
            self::ROLE_SWITCH => [
                'id' => self::ROLE_SWITCH,
                'title' => 'Network Switch',
                'short_title' => 'Switch',
                'category' => 'network',
                'badge_color' => '#0d9488',
                'rank' => 3
            ],
            self::ROLE_ROUTER => [
                'id' => self::ROLE_ROUTER,
                'title' => 'Network Router',
                'short_title' => 'Router',
                'category' => 'network',
                'badge_color' => '#059669',
                'rank' => 4
            ],
            self::ROLE_FIREWALL => [
                'id' => self::ROLE_FIREWALL,
                'title' => 'Security Firewall',
                'short_title' => 'Firewall',
                'category' => 'network',
                'badge_color' => '#dc2626',
                'rank' => 5
            ],
            self::ROLE_SERVER => [
                'id' => self::ROLE_SERVER,
                'title' => 'Host Server',
                'short_title' => 'Server',
                'category' => 'compute',
                'badge_color' => '#334155',
                'rank' => 2
            ]
        ];
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
        if (preg_match('/(datastore|vsan|san|nas|nfs|purestorage|storage|lun)/i', $haystack)) {
            return self::ROLE_STORAGE;
        }
        if (preg_match('/(esxi|hypervisor|vsphere host|proxmox|kvm host)/i', $haystack)) {
            return self::ROLE_HYPERVISOR;
        }
        if (preg_match('/(vm-|srv-vm|vhost|virtual machine|debian|ubuntu|centos|rhel|windows server|win-vm|docker|k8s)/i', $haystack)) {
            return self::ROLE_VM;
        }
        if (preg_match('/(firewall|fortinet|palo alto|pfsense|opnsense|checkpoint)/i', $haystack)) {
            return self::ROLE_FIREWALL;
        }
        if (preg_match('/(router|gateway|mikrotik|cisco crs|edge-router)/i', $haystack)) {
            return self::ROLE_ROUTER;
        }
        if (preg_match('/(switch|sw-|leaf|spine|cisco catalyst|nexus|arista)/i', $haystack)) {
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
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    http_response_code(200);

    // 1. API: LIST DASHBOARDS
    if ($api === 'list_dashboards') {
        $dashboards = load_topology_dashboards($DASHBOARD_FILE);
        echo json_encode(['ok' => true, 'dashboards' => $dashboards]);
        exit;
    }

    // 2. API: SAVE DASHBOARD (Create / Update)
    if ($api === 'save_dashboard') {
        $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($client_token !== $csrf_token) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token. Refresh page.']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = trim((string)($input['id'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $group_id = (int)($input['group_id'] ?? 0);
        $group_name = trim((string)($input['group_name'] ?? 'All Agent Groups'));
        $layout = trim((string)($input['layout'] ?? 'dagre'));
        $is_demo = !empty($input['is_demo']);

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
                'group_id' => $group_id,
                'group_name' => pretty_text($group_name),
                'layout' => $layout,
                'is_demo' => $is_demo,
                'node_count' => $is_demo ? 14 : 0,
                'created_at' => $now,
                'updated_at' => $now
            ];
        } else {
            $found = false;
            foreach ($dashboards as &$d) {
                if ($d['id'] === $id) {
                    $d['name'] = pretty_text($name);
                    $d['description'] = pretty_text($description);
                    $d['group_id'] = $group_id;
                    $d['group_name'] = pretty_text($group_name);
                    $d['layout'] = $layout;
                    $d['is_demo'] = $is_demo;
                    $d['updated_at'] = $now;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $dashboards[] = [
                    'id' => $id,
                    'name' => pretty_text($name),
                    'description' => pretty_text($description),
                    'group_id' => $group_id,
                    'group_name' => pretty_text($group_name),
                    'layout' => $layout,
                    'is_demo' => $is_demo,
                    'node_count' => $is_demo ? 14 : 0,
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }
        }

        $saved = save_topology_dashboards($DASHBOARD_FILE, $dashboards);
        echo json_encode(['ok' => $saved, 'id' => $id, 'error' => $saved ? null : 'Failed to write dashboards file.']);
        exit;
    }

    // 3. API: DELETE DASHBOARD
    if ($api === 'delete_dashboard') {
        $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if ($client_token !== $csrf_token) {
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token.']);
            exit;
        }

        $id = trim((string)($_GET['id'] ?? ''));
        $dashboards = load_topology_dashboards($DASHBOARD_FILE);
        $dashboards = array_filter($dashboards, fn($d) => $d['id'] !== $id);
        $saved = save_topology_dashboards($DASHBOARD_FILE, $dashboards);
        echo json_encode(['ok' => $saved]);
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
        $group_id = isset($_GET['group_id']) && $_GET['group_id'] !== '' ? (int)$_GET['group_id'] : null;
        $search = trim((string)($_GET['search'] ?? ''));

        // Check if dashboard specifies demo mode
        $current_dash = null;
        if (!empty($dash_id)) {
            $dashboards = load_topology_dashboards($DASHBOARD_FILE);
            foreach ($dashboards as $d) {
                if ($d['id'] === $dash_id) {
                    $current_dash = $d;
                    if ($group_id === null && !empty($d['group_id'])) {
                        $group_id = (int)$d['group_id'];
                    }
                    break;
                }
            }
        }

        if ($current_dash && !empty($current_dash['is_demo'])) {
            $demo = TopologyDeviceClassifier::getReferenceDemoTopology();
            echo json_encode([
                'ok' => true,
                'mode' => 'demo',
                'title' => pretty_text($current_dash['name']),
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

        try {
            // Check IP Column dynamically
            $ipCol = 'direccion';
            try {
                $checkIp = $pdo->query("SHOW COLUMNS FROM tagente LIKE 'ip_address'");
                if ($checkIp && $checkIp->rowCount() > 0) $ipCol = 'ip_address';
            } catch (Throwable $e) {}

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

            // Safe Query on tagente without invalid column a.so
            $sql = "SELECT a.id_agente, a.nombre, a.alias, a.$ipCol AS ip, a.comentarios, 
                           os.name AS os, os.icon_name AS os_icon, a.id_grupo, a.id_parent, 
                           COALESCE(g.nombre, 'Unknown') AS group_name
                    FROM tagente a
                    LEFT JOIN tconfig_os os ON a.id_os = os.id_os
                    LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                    WHERE a.disabled = 0";
            $params = [];

            if (!empty($target_group_ids)) {
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

            $sql .= " ORDER BY a.id_parent ASC, a.nombre ASC LIMIT 200";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // If database has few or no agents, fallback automatically to reference demo
            if (empty($agents)) {
                $demo = TopologyDeviceClassifier::getReferenceDemoTopology();
                echo json_encode([
                    'ok' => true,
                    'mode' => 'demo',
                    'notice' => 'No active agents found in selected scope. Displaying reference SDDC demo topology.',
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

            // Fetch alert statuses from tagente_estado
            $agent_ids = array_map(fn($a) => (int)$a['id_agente'], $agents);
            $statuses = [];
            if (!empty($agent_ids)) {
                $in_ids = implode(',', $agent_ids);
                $st_alerts = $pdo->query("SELECT id_agente, MAX(estado) as max_state, COUNT(*) as mod_count
                                          FROM tagente_estado
                                          WHERE id_agente IN ($in_ids)
                                          GROUP BY id_agente");
                if ($st_alerts) {
                    while ($row = $st_alerts->fetch(PDO::FETCH_ASSOC)) {
                        $statuses[(int)$row['id_agente']] = [
                            'state' => (int)$row['max_state'],
                            'count' => (int)$row['mod_count']
                        ];
                    }
                }
            }

            $nodes = [];
            $agent_map = [];
            $role_meta = TopologyDeviceClassifier::getRoles();

            foreach ($agents as $a) {
                $aid = (int)$a['id_agente'];
                $node_id = 'agent-' . $aid;
                $role = TopologyDeviceClassifier::classifyAgent($a);
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

            // Derive edges from agent parent hierarchy & group clusters
            $edges = [];
            $edge_keys = [];

            foreach ($nodes as $n) {
                $pid = $n['parent_id'];
                if ($pid > 0 && isset($agent_map[$pid])) {
                    $src = $agent_map[$pid];
                    $tgt = $n['id'];
                    $k = $src . '->' . $tgt;
                    if (!isset($edge_keys[$k])) {
                        $edge_keys[$k] = true;
                        $edges[] = [
                            'source' => $src,
                            'target' => $tgt,
                            'status' => $n['status'] === 'critical' ? 'critical' : ($n['status'] === 'warning' ? 'warning' : 'active')
                        ];
                    }
                }
            }

            // If few parent edges exist, link nodes logically by tier / infrastructure backbone
            if (count($edges) < count($nodes) - 1) {
                $by_category = ['network' => [], 'management' => [], 'compute' => [], 'storage' => []];
                foreach ($nodes as $n) {
                    $by_category[$n['category']][] = $n['id'];
                }

                $backbone = array_merge($by_category['network'], $by_category['management']);
                if (empty($backbone) && !empty($nodes)) {
                    $backbone[] = $nodes[0]['id'];
                }

                $bIdx = 0;
                foreach ($nodes as $n) {
                    if (in_array($n['id'], $backbone)) continue;
                    $src = $backbone[$bIdx % count($backbone)];
                    $tgt = $n['id'];
                    $k = $src . '->' . $tgt;
                    if (!isset($edge_keys[$k])) {
                        $edge_keys[$k] = true;
                        $edges[] = [
                            'source' => $src,
                            'target' => $tgt,
                            'status' => $n['status'] === 'critical' ? 'critical' : ($n['status'] === 'warning' ? 'warning' : 'active')
                        ];
                    }
                    $bIdx++;
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
    
    <!-- Unified Fonts & Icons matching Dynamic Dashboard and Route Parser -->
    <link rel="stylesheet" href="<?= htmlspecialchars($vendor_url) ?>/fonts/fonts.css">
    <link rel="stylesheet" href="../../vendor/fonts/fonts.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">

    <!-- Offline Cytoscape & Dagre Layout Engines -->
    <script src="../../vendor/cytoscape/cytoscape.min.js"></script>
    <script src="../../vendor/cytoscape/dagre.min.js"></script>
    <script src="../../vendor/cytoscape/cytoscape-dagre.min.js"></script>

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

        .loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(2px);
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
                <h3 class="inspector-title">
                    <span class="material-symbols-outlined" style="color:var(--brand-green);">info</span>
                    Device Inspector
                </h3>
                <button class="inspector-close" onclick="closeInspector()">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="inspector-body">
                <div class="node-hero">
                    <div class="node-hero-icon" id="drawerHeroIcon">
                        <span class="material-symbols-outlined" style="font-size:28px; color:var(--brand-green);">computer</span>
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
                    <label class="form-label">Target Agent Group</label>
                    <select id="dashModalGroup" class="form-control-custom">
                        <option value="0">All Agent Groups</option>
                    </select>
                </div>

                <div>
                    <label class="form-label">Default Graph Layout</label>
                    <select id="dashModalLayout" class="form-control-custom">
                        <option value="dagre">Hierarchical (Multi-Tier vSphere)</option>
                        <option value="cose">Force-Directed (Mesh)</option>
                        <option value="circle">Circular Ring</option>
                    </select>
                </div>

                <div style="margin-top:6px; display:flex; align-items:center; gap:8px;">
                    <input type="checkbox" id="dashModalDemo" style="width:16px; height:16px; cursor:pointer;">
                    <label for="dashModalDemo" style="font-size:13px; color:var(--text-dark); cursor:pointer; margin:0;">
                        Use VMware vSphere SDDC Reference Topology Demo
                    </label>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn-secondary-custom" onclick="closeModal()">Cancel</button>
                <button class="btn-apply" onclick="submitDashboardForm()">
                    <span class="material-symbols-outlined">save</span>
                    Save Dashboard
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- JAVASCRIPT APPLICATION LOGIC                                              -->
    <!-- ========================================================================= -->
    <script>
        const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
        const API_URL = 'topology-network.php';
        let allDashboards = <?= json_encode($dashboards) ?>;
        let activeDashId = <?= json_encode($selected_dash_id) ?>;
        let cy = null;
        let rawTopologyData = { nodes: [], edges: [] };
        let activeCategory = 'all';

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
                            <div style="font-size:13px; color:#94a3b8; margin-top:4px;">Create your first topology dashboard to visualize agent networks or load the reference demo.</div>
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
                const isDemo = !!d.is_demo;
                const nodeCount = d.node_count || (isDemo ? 14 : 'Live');
                const badge = isDemo ? '<span class="badge-demo">Reference Demo</span>' : '<span class="badge-count">Auto-Discovery</span>';
                const cleanName = cleanText(d.name);
                const cleanDesc = cleanText(d.description || '');
                const cleanGroup = cleanText(d.group_name || 'All Agent Groups');

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
                            <div style="margin-top:4px;">${badge}</div>
                        </td>
                        <td>
                            <span class="badge-count">${nodeCount} devices</span>
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
        function openCreateModal() {
            document.getElementById('modalTitle').innerHTML = '<span class="material-symbols-outlined">add_chart</span> Create Topology Dashboard';
            document.getElementById('dashModalId').value = '';
            document.getElementById('dashModalName').value = '';
            document.getElementById('dashModalDesc').value = '';
            document.getElementById('dashModalGroup').value = '0';
            document.getElementById('dashModalLayout').value = 'dagre';
            document.getElementById('dashModalDemo').checked = false;
            document.getElementById('dashboardModal').style.display = 'flex';
        }

        function editDashboard(id) {
            const d = allDashboards.find(x => x.id === id);
            if (!d) return;

            document.getElementById('modalTitle').innerHTML = '<span class="material-symbols-outlined">edit</span> Edit Topology Dashboard';
            document.getElementById('dashModalId').value = d.id;
            document.getElementById('dashModalName').value = cleanText(d.name);
            document.getElementById('dashModalDesc').value = cleanText(d.description || '');
            document.getElementById('dashModalGroup').value = String(d.group_id || '0');
            document.getElementById('dashModalLayout').value = d.layout || 'dagre';
            document.getElementById('dashModalDemo').checked = !!d.is_demo;
            document.getElementById('dashboardModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('dashboardModal').style.display = 'none';
        }

        async function submitDashboardForm() {
            const id = document.getElementById('dashModalId').value.trim();
            const name = document.getElementById('dashModalName').value.trim();
            const desc = document.getElementById('dashModalDesc').value.trim();
            const groupId = parseInt(document.getElementById('dashModalGroup').value) || 0;
            const layout = document.getElementById('dashModalLayout').value;
            const isDemo = document.getElementById('dashModalDemo').checked;

            if (!name) {
                alert('Please enter a Dashboard Name.');
                return;
            }

            const groupSelect = document.getElementById('dashModalGroup');
            let groupName = 'All Agent Groups';
            if (groupId > 0 && groupSelect.selectedIndex >= 0) {
                groupName = cleanText(groupSelect.options[groupSelect.selectedIndex].text);
            }

            try {
                const res = await fetch(`${API_URL}?api=save_dashboard`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({
                        id: id,
                        name: cleanText(name),
                        description: cleanText(desc),
                        group_id: groupId,
                        group_name: groupName,
                        layout: layout,
                        is_demo: isDemo
                    })
                });
                const data = await res.json();
                if (data.ok) {
                    closeModal();
                    // Reload Dashboards
                    const listRes = await fetch(`${API_URL}?api=list_dashboards`);
                    const listData = await listRes.json();
                    if (listData.ok) {
                        allDashboards = listData.dashboards;
                        renderDashboardTable(allDashboards);
                        // If created new, optionally open immediately
                        if (!id && data.id) {
                            openDashboard(data.id);
                        }
                    }
                } else {
                    alert('Error saving dashboard: ' + (data.error || 'Unknown error'));
                }
            } catch (err) {
                alert('Network error while saving: ' + err.message);
            }
        }

        async function deleteDashboard(id) {
            const d = allDashboards.find(x => x.id === id);
            const name = d ? cleanText(d.name) : id;
            if (!confirm(`Are you sure you want to delete dashboard "${name}"?`)) {
                return;
            }

            try {
                const res = await fetch(`${API_URL}?api=delete_dashboard&id=${encodeURIComponent(id)}`, {
                    headers: { 'X-CSRF-Token': CSRF_TOKEN }
                });
                const data = await res.json();
                if (data.ok) {
                    allDashboards = allDashboards.filter(x => x.id !== id);
                    renderDashboardTable(allDashboards);
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
                const res = await fetch(`${API_URL}?api=get_groups`);
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
                let url = `${API_URL}?api=get_topology_data&dashboard_id=${encodeURIComponent(dashId || '')}`;
                if (customGroupId !== null) {
                    url += `&group_id=${customGroupId}`;
                }
                const res = await fetch(url);
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
            if (!container) return;

            const elements = [];

            // Node elements
            data.nodes.forEach(n => {
                if (activeCategory !== 'all' && n.category !== activeCategory) {
                    return; // filter
                }

                elements.push({
                    group: 'nodes',
                    data: {
                        id: n.id,
                        label: cleanText(n.name),
                        role: n.role,
                        category: n.category,
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

            // Edge elements
            data.edges.forEach((e, idx) => {
                if (activeNodeIds.has(e.source) && activeNodeIds.has(e.target)) {
                    elements.push({
                        group: 'edges',
                        data: {
                            id: 'edge-' + idx,
                            source: e.source,
                            target: e.target,
                            status: e.status || 'active'
                        }
                    });
                }
            });

            // Initialize or replace Cytoscape
            if (cy) {
                cy.destroy();
            }

            cy = cytoscape({
                container: container,
                elements: elements,
                style: [
                    {
                        selector: 'node',
                        style: {
                            'label': 'data(label)',
                            'color': '#1e293b',
                            'font-family': 'Inter, sans-serif',
                            'font-size': 11,
                            'font-weight': 600,
                            'text-valign': 'bottom',
                            'text-margin-y': 8,
                            'text-wrap': 'ellipsis',
                            'text-max-width': '120px',
                            'width': 48,
                            'height': 48,
                            'background-color': '#ffffff',
                            'border-width': 2,
                            'border-color': function(ele) {
                                const s = ele.data('status');
                                if (s === 'critical') return '#ef4444';
                                if (s === 'warning') return '#f59e0b';
                                return '#10b981';
                            },
                            'background-image': function(ele) {
                                return getNodeSvgIcon(ele.data('role'));
                            },
                            'background-fit': 'contain',
                            'background-clip': 'none',
                            'background-width': '65%',
                            'background-height': '65%'
                        }
                    },
                    {
                        selector: 'node[status = "critical"]',
                        style: {
                            'border-width': 3,
                            'border-color': '#ef4444',
                            'shadow-blur': 12,
                            'shadow-color': 'rgba(239, 68, 68, 0.4)',
                            'shadow-opacity': 0.8
                        }
                    },
                    {
                        selector: 'node[status = "warning"]',
                        style: {
                            'border-width': 2.5,
                            'border-color': '#f59e0b'
                        }
                    },
                    {
                        selector: 'node:selected',
                        style: {
                            'border-color': '#2563eb',
                            'border-width': 4,
                            'shadow-blur': 16,
                            'shadow-color': 'rgba(37, 99, 235, 0.5)'
                        }
                    },
                    {
                        selector: 'edge',
                        style: {
                            'width': 2,
                            'line-color': '#cbd5e1',
                            'curve-style': 'bezier',
                            'target-arrow-shape': 'triangle',
                            'target-arrow-color': '#cbd5e1',
                            'arrow-scale': 0.8
                        }
                    },
                    {
                        selector: 'edge[status = "critical"]',
                        style: {
                            'line-color': '#ef4444',
                            'target-arrow-color': '#ef4444',
                            'line-style': 'dashed',
                            'width': 2.5
                        }
                    },
                    {
                        selector: 'edge[status = "warning"]',
                        style: {
                            'line-color': '#f59e0b',
                            'target-arrow-color': '#f59e0b'
                        }
                    }
                ],
                layout: {
                    name: 'dagre',
                    rankDir: 'TB',
                    nodeSep: 60,
                    rankSep: 80,
                    animate: true,
                    animationDuration: 400
                }
            });

            // Node Click Event -> Open Inspector Drawer
            cy.on('tap', 'node', function(evt) {
                const node = evt.target;
                openInspector(node.data());
            });

            // Canvas Click Event -> Close Inspector if background tapped
            cy.on('tap', function(evt) {
                if (evt.target === cy) {
                    closeInspector();
                }
            });
        }

        // --- 6. SVG VECTOR DEVICE ICONS (Data URIs for Zero Missing Assets) ---
        function getNodeSvgIcon(role) {
            const icons = {
                vm: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#3b82f6"><path d="M4 6h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2zm0 2v8h16V8H4zm4 11h8v1H8v-1z"/></svg>`,
                hypervisor: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#1e293b"><path d="M2 4h20v4H2V4zm0 6h20v4H2v-4zm0 6h20v4H2v-4zm3-10h2V6H5v2zm0 6h2v-2H5v2zm0 6h2v-2H5v2z"/></svg>`,
                cluster: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#475569"><path d="M4 2h6v6H4V2zm10 0h6v6h-6V2zM4 16h6v6H4v-6zm10 0h6v6h-6v-6zM11 5h2v14h-2V5zM5 11h14v2H5v-2z"/></svg>`,
                datacenter: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#0f172a"><path d="M12 2L2 7v13h20V7L12 2zm8 16H4V8.5l8-4 8 4V18zM7 10h3v2H7v-2zm7 0h3v2h-3v-2zm-7 4h3v2H7v-2zm7 0h3v2h-3v-2z"/></svg>`,
                storage: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#0284c7"><path d="M2 4c0-1.1.9-2 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4zm0 7c0-1.1.9-2 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-3zm0 7c0-1.1.9-2 2-2h16a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-3zm4-13a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm0 7a1 1 0 1 0 0-2 1 1 0 0 0 0 2zm0 7a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/></svg>`,
                vcenter: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#64748b"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8zm1-13h-2v4H7v2h4v4h2v-4h4v-2h-4z"/></svg>`,
                switch: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#0d9488"><path d="M3 6h18a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1zm1 2v8h16V8H4zm3 3h2v2H7v-2zm4 0h2v2h-2v-2zm4 0h2v2h-2v-2z"/></svg>`,
                router: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#059669"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm1 14.5v-2h3l-4-4-4 4h3v2zm-1-9a1.5 1.5 0 1 1-1.5 1.5A1.5 1.5 0 0 1 12 7.5z"/></svg>`,
                firewall: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#dc2626"><path d="M12 2L4 5v6.09c0 5.05 3.41 9.76 8 10.91 4.59-1.15 8-5.86 8-10.91V5l-8-3zm6 9.09c0 4-2.55 7.7-6 8.83-3.45-1.13-6-4.82-6-8.83V6.31l6-2.25 6 2.25v4.78zM7 9h10v2H7V9zm0 4h7v2H7v-2z"/></svg>`,
                server: `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#334155"><path d="M3 4h18a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zm0 8h18a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1zm3-6a1 1 0 1 0 0 2 1 1 0 0 0 0-2zm0 8a1 1 0 1 0 0 2 1 1 0 0 0 0-2z"/></svg>`
            };
            const svg = icons[role] || icons['server'];
            return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
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

        function openInspector(data) {
            document.getElementById('drawerNodeName').innerText = cleanText(data.label);
            document.getElementById('drawerRoleTitle').innerText = cleanText(data.role.toUpperCase());
            document.getElementById('drawerIp').innerText = cleanText(data.ip || '0.0.0.0');
            document.getElementById('drawerGroup').innerText = cleanText(data.group || 'Infrastructure');
            document.getElementById('drawerOs').innerText = cleanText(data.os || 'Unknown OS');
            document.getElementById('drawerAlertCount').innerText = data.alert_count || 0;

            const stBadge = document.getElementById('drawerStatusBadge');
            if (data.status === 'critical') {
                stBadge.innerHTML = '<span class="badge-pill badge-crit">Critical Alert</span>';
            } else if (data.status === 'warning') {
                stBadge.innerHTML = '<span class="badge-pill badge-warn">Warning</span>';
            } else {
                stBadge.innerHTML = '<span class="badge-pill badge-ok">Normal</span>';
            }

            document.getElementById('inspectorDrawer').classList.add('open');
        }

        function closeInspector() {
            document.getElementById('inspectorDrawer').classList.remove('open');
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

        // --- 8. INITIALIZATION ON READY ---
        document.addEventListener('DOMContentLoaded', () => {
            renderDashboardTable(allDashboards);
            loadAgentGroups();

            if (activeDashId) {
                openDashboard(activeDashId);
            }
        });
    </script>
</body>
</html>
