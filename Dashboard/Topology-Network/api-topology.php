<?php
declare(strict_types=1);

/**
 * api-topology.php
 * High-performance backend API for Topology Network.
 * Handles Pandora FMS agents, group tree hierarchies, active alert aggregation, and Cytoscape graph modeling.
 */

// Output JSON header immediately
ob_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
http_response_code(200);

require_once __DIR__ . '/../../includes/db-connection.php';
require_once __DIR__ . '/Engine/DeviceClassifier.php';

use TopologyNetwork\Engine\DeviceClassifier;

// Authentication check
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$user_id = $_SESSION['id_usuario'] ?? 0;
if (empty($user_id)) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized. Active Pandora FMS session required.']);
    exit;
}

$api = $_GET['api'] ?? 'topology';
$config_file = __DIR__ . '/topology_config.json';

// Helper: load local overrides
function load_topology_overrides(string $file): array {
    if (!file_exists($file)) return [];
    $data = json_decode(@file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

// Helper: save local overrides
function save_topology_overrides(string $file, array $data): bool {
    return @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

// -------------------------------------------------------------
// 1. API: LIST ROLES
// -------------------------------------------------------------
if ($api === 'roles') {
    echo json_encode(['ok' => true, 'roles' => DeviceClassifier::getRoles()]);
    exit;
}

// -------------------------------------------------------------
// 2. API: LIST AGENT GROUPS (Hierarchical Tree)
// -------------------------------------------------------------
if ($api === 'groups') {
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

        // Build tree
        $by_parent = [];
        foreach ($all_groups as $g) {
            $p = (int)$g['parent'];
            $by_parent[$p][] = $g;
        }

        $formatted = [];
        $buildTree = function($parentId, $depth) use (&$buildTree, &$by_parent, &$formatted, &$counts) {
            if (empty($by_parent[$parentId])) return;
            foreach ($by_parent[$parentId] as $g) {
                $gid = (int)$g['id'];
                $prefix = $depth > 0 ? str_repeat('── ', $depth) . '└─ ' : '';
                $agent_cnt = $counts[$gid] ?? 0;
                $formatted[] = [
                    'id' => $gid,
                    'name' => $g['name'],
                    'display_name' => $prefix . $g['name'] . ' (' . $agent_cnt . ' agents)',
                    'parent' => (int)$g['parent'],
                    'agent_count' => $agent_cnt,
                    'depth' => $depth
                ];
                $buildTree($gid, $depth + 1);
            }
        };

        $buildTree(0, 0);

        // Fallback for flat structure if no parent links
        if (empty($formatted) && !empty($all_groups)) {
            foreach ($all_groups as $g) {
                $gid = (int)$g['id'];
                $formatted[] = [
                    'id' => $gid,
                    'name' => $g['name'],
                    'display_name' => $g['name'] . ' (' . ($counts[$gid] ?? 0) . ' agents)',
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

// -------------------------------------------------------------
// 3. API: LOAD REFERENCE DEMO
// -------------------------------------------------------------
if ($api === 'load_demo') {
    $demo = DeviceClassifier::getReferenceDemoTopology();
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

// -------------------------------------------------------------
// 4. API: SAVE MANUAL NODE ROLE OVERRIDE
// -------------------------------------------------------------
if ($api === 'save_role') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $node_id = trim((string)($input['node_id'] ?? ''));
    $role = trim((string)($input['role'] ?? ''));

    if (empty($node_id) || empty($role)) {
        echo json_encode(['ok' => false, 'error' => 'node_id and role are required.']);
        exit;
    }

    $overrides = load_topology_overrides($config_file);
    if (!isset($overrides['role_overrides'])) {
        $overrides['role_overrides'] = [];
    }
    $overrides['role_overrides'][$node_id] = $role;
    $saved = save_topology_overrides($config_file, $overrides);

    echo json_encode(['ok' => $saved, 'error' => $saved ? '' : 'Failed to save role override.']);
    exit;
}

// -------------------------------------------------------------
// 5. API: GET TOPOLOGY (Real Pandora FMS Agents)
// -------------------------------------------------------------
if ($api === 'topology') {
    try {
        $group_id = isset($_GET['group_id']) && is_numeric($_GET['group_id']) ? (int)$_GET['group_id'] : null;
        $search = trim((string)($_GET['search'] ?? ''));
        $force_demo = isset($_GET['demo']) && $_GET['demo'] === '1';

        if ($force_demo) {
            $demo = DeviceClassifier::getReferenceDemoTopology();
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

        // Sub-group recursive expansion
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

        // Build SQL for agents
        $sql = "SELECT a.id_agente, a.nombre, a.alias, a.direccion, a.comentarios, a.so, a.id_grupo, a.id_parent, 
                       COALESCE(g.nombre, 'Unknown') AS group_name
                FROM tagente a
                LEFT JOIN tgrupo g ON a.id_grupo = g.id_grupo
                WHERE a.disabled = 0";
        $params = [];

        if (!empty($target_group_ids)) {
            $in_placeholders = implode(',', array_fill(0, count($target_group_ids), '?'));
            $sql .= " AND a.id_grupo IN ($in_placeholders)";
            $params = array_merge($params, $target_group_ids);
        }

        if (!empty($search)) {
            $sql .= " AND (a.nombre LIKE ? OR a.alias LIKE ? OR a.direccion LIKE ?)";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY a.id_parent ASC, a.nombre ASC LIMIT 150";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // If no agents found, fallback to reference demo automatically
        if (empty($agents)) {
            $demo = DeviceClassifier::getReferenceDemoTopology();
            echo json_encode([
                'ok' => true,
                'mode' => 'demo',
                'notice' => 'No active agents matched filter. Loaded reference SDDC demo topology.',
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

        // Fetch Agent Health & Alert Counts
        $agent_ids = array_map(fn($a) => (int)$a['id_agente'], $agents);
        $in_agents = implode(',', $agent_ids);

        // Fetch active incidents/alerts
        $alert_counts = [];
        try {
            $st_inc = $pdo->query("SELECT id_agente, COUNT(*) as cnt FROM tagente_datos_incidencia WHERE id_agente IN ($in_agents) GROUP BY id_agente");
            if ($st_inc) {
                while ($r = $st_inc->fetch(PDO::FETCH_ASSOC)) {
                    $alert_counts[(int)$r['id_agente']] = (int)$r['cnt'];
                }
            }
        } catch (Throwable $e) {}

        // Fetch agent module worst status (0=normal, 1=critical, 2=warning, 3=unknown)
        $agent_statuses = [];
        try {
            $st_st = $pdo->query("SELECT am.id_agente, MAX(CASE WHEN ae.estado = 1 THEN 3 WHEN ae.estado = 2 THEN 2 WHEN ae.estado = 0 THEN 1 ELSE 0 END) as max_severity
                                  FROM tagente_modulo am
                                  JOIN tagente_estado ae ON am.id_agente_modulo = ae.id_agente_modulo
                                  WHERE am.id_agente IN ($in_agents) AND am.disabled = 0
                                  GROUP BY am.id_agente");
            if ($st_st) {
                while ($r = $st_st->fetch(PDO::FETCH_ASSOC)) {
                    $sev = (int)$r['max_severity'];
                    if ($sev === 3) {
                        $agent_statuses[(int)$r['id_agente']] = 'critical';
                    } elseif ($sev === 2) {
                        $agent_statuses[(int)$r['id_agente']] = 'warning';
                    } else {
                        $agent_statuses[(int)$r['id_agente']] = 'normal';
                    }
                }
            }
        } catch (Throwable $e) {}

        $overrides = load_topology_overrides($config_file);
        $role_overrides = $overrides['role_overrides'] ?? [];

        $nodes = [];
        $edges = [];
        $node_ids_map = [];

        // Hub groups for root clustering
        $groups_seen = [];

        foreach ($agents as $a) {
            $aid = (int)$a['id_agente'];
            $node_key = 'agent_' . $aid;
            $node_ids_map[$aid] = $node_key;

            $override = $role_overrides[$node_key] ?? null;
            $role = DeviceClassifier::classifyAgent($a, $override);
            $roles_meta = DeviceClassifier::getRoles();
            $role_title = $roles_meta[$role]['title'] ?? 'Device';

            $alert_cnt = $alert_counts[$aid] ?? 0;
            $st = $agent_statuses[$aid] ?? 'normal';
            if ($alert_cnt > 0 && $st === 'normal') {
                $st = 'warning';
            }

            $display_name = !empty($a['alias']) ? $a['alias'] : $a['nombre'];

            $nodes[] = [
                'id' => $node_key,
                'agent_id' => $aid,
                'name' => $display_name,
                'raw_name' => $a['nombre'],
                'role' => $role,
                'role_title' => $role_title,
                'ip' => !empty($a['direccion']) ? $a['direccion'] : 'N/A',
                'status' => $st,
                'alert_count' => $alert_cnt,
                'group' => $a['group_name'],
                'group_id' => (int)$a['id_grupo'],
                'os' => !empty($a['so']) ? $a['so'] : 'Unknown',
                'comments' => $a['comentarios'] ?? '',
                'parent_agent_id' => (int)$a['id_parent']
            ];

            $groups_seen[$a['group_name']] = (int)$a['id_grupo'];
        }

        // Build edges based on native parent-child
        $has_parent_links = false;
        foreach ($nodes as $n) {
            $p_aid = $n['parent_agent_id'];
            if ($p_aid > 0 && isset($node_ids_map[$p_aid])) {
                $p_key = $node_ids_map[$p_aid];
                $edge_id = 'e_' . $p_key . '_' . $n['id'];
                $edges[] = [
                    'id' => $edge_id,
                    'source' => $p_key,
                    'target' => $n['id']
                ];
                $has_parent_links = true;
            }
        }

        // If agents do not have id_parent configured, cluster agents under their Group / Datacenter node
        if (!$has_parent_links) {
            // Create a Datacenter / SDDC Hub Node
            $hub_id = 'hub_sddc_dc';
            $hub_name = !empty($search) ? "Search: {$search}" : (!empty($group_id) ? "Group: " . ($agents[0]['group_name'] ?? 'Topology') : "CA-EAST-02-SDDC");
            
            $nodes[] = [
                'id' => $hub_id,
                'name' => $hub_name,
                'role' => DeviceClassifier::ROLE_DATACENTER,
                'role_title' => 'VMware vSphere Datacenter',
                'ip' => '10.128.8.1',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Core'
            ];

            // Separate into hypervisors/servers and other nodes
            $servers = [];
            $leafs = [];
            foreach ($nodes as $n) {
                if ($n['id'] === $hub_id) continue;
                if (in_array($n['role'], [DeviceClassifier::ROLE_HYPERVISOR, DeviceClassifier::ROLE_CLUSTER, DeviceClassifier::ROLE_SWITCH, DeviceClassifier::ROLE_ROUTER])) {
                    $servers[] = $n['id'];
                    $edges[] = ['id' => 'e_' . $hub_id . '_' . $n['id'], 'source' => $hub_id, 'target' => $n['id']];
                } else {
                    $leafs[] = $n['id'];
                }
            }

            // Connect remaining leafs to servers or hub
            if (!empty($servers)) {
                $idx = 0;
                $srv_count = count($servers);
                foreach ($leafs as $lid) {
                    $target_srv = $servers[$idx % $srv_count];
                    $edges[] = ['id' => 'e_' . $target_srv . '_' . $lid, 'source' => $target_srv, 'target' => $lid];
                    $idx++;
                }
            } else {
                foreach ($leafs as $lid) {
                    $edges[] = ['id' => 'e_' . $hub_id . '_' . $lid, 'source' => $hub_id, 'target' => $lid];
                }
            }
        }

        $stats = [
            'total_nodes' => count($nodes),
            'total_edges' => count($edges),
            'critical' => count(array_filter($nodes, fn($n) => $n['status'] === 'critical')),
            'warning' => count(array_filter($nodes, fn($n) => $n['status'] === 'warning')),
            'normal' => count(array_filter($nodes, fn($n) => $n['status'] === 'normal'))
        ];

        echo json_encode([
            'ok' => true,
            'mode' => 'live',
            'nodes' => $nodes,
            'edges' => $edges,
            'stats' => $stats
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown API action.']);
