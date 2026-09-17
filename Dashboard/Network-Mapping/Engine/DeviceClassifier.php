<?php
namespace NetworkMapping\Engine;

/**
 * DeviceClassifier
 * Intelligent device role and classification engine for Pandora FMS agents.
 * Identifies Datacenter, Cluster, Hypervisor, VM, Storage/Datastore, vCenter, Switch, Router, Firewall, Server.
 */
class DeviceClassifier {

    public static function getAllRoles(): array {
        return [
            'vm' => [
                'name' => 'VMware vSphere VM',
                'category' => 'compute',
                'icon' => 'laptop',
                'description' => 'Virtual Machine / Guest OS'
            ],
            'hypervisor' => [
                'name' => 'VMware vSphere Hypervisor',
                'category' => 'compute',
                'icon' => 'server_rack',
                'description' => 'ESXi / Proxmox / KVM Host'
            ],
            'cluster' => [
                'name' => 'VMware vSphere Cluster',
                'category' => 'compute',
                'icon' => 'cluster',
                'description' => 'vSphere / Kubernetes / vSAN Cluster'
            ],
            'datacenter' => [
                'name' => 'VMware vSphere Datacenter',
                'category' => 'core',
                'icon' => 'building',
                'description' => 'Software-Defined Datacenter (SDDC) / Site'
            ],
            'storage' => [
                'name' => 'VMware vSphere Datastore',
                'category' => 'storage',
                'icon' => 'database_disks',
                'description' => 'Datastore / SAN / NAS / NFS'
            ],
            'vcenter' => [
                'name' => 'VMware vSphere vCenter',
                'category' => 'management',
                'icon' => 'monitor_app',
                'description' => 'vCenter Server / Cloud Manager'
            ],
            'switch' => [
                'name' => 'Network Switch',
                'category' => 'network',
                'icon' => 'switch',
                'description' => 'L2/L3 Network Switch'
            ],
            'router' => [
                'name' => 'Network Router',
                'category' => 'network',
                'icon' => 'router',
                'description' => 'Edge Router / Gateway'
            ],
            'firewall' => [
                'name' => 'Security Firewall',
                'category' => 'network',
                'icon' => 'firewall',
                'description' => 'Next-Gen Firewall / Security Gateway'
            ],
            'server' => [
                'name' => 'Physical Server',
                'category' => 'compute',
                'icon' => 'server',
                'description' => 'Bare Metal / Physical Host'
            ]
        ];
    }

    public static function getRoleTitle(string $role): string {
        $roles = self::getAllRoles();
        return $roles[$role]['name'] ?? 'Generic Device';
    }

    /**
     * Classify an agent into a specific role based on alias, name, OS, comments, group, or custom override.
     */
    public static function classify(array $agent, array $customRoles = []): array {
        $agentId = (string)($agent['id'] ?? $agent['id_agente'] ?? '');
        
        // 1. Check custom user override in layout config
        if (!empty($agentId) && isset($customRoles[$agentId])) {
            $roleKey = $customRoles[$agentId];
            $allRoles = self::getAllRoles();
            if (isset($allRoles[$roleKey])) {
                return [
                    'role' => $roleKey,
                    'title' => $allRoles[$roleKey]['name'],
                    'icon' => $allRoles[$roleKey]['icon'],
                    'is_custom' => true
                ];
            }
        }

        $alias = strtolower(trim((string)($agent['alias'] ?? '')));
        $nombre = strtolower(trim((string)($agent['nombre'] ?? '')));
        $so = strtolower(trim((string)($agent['so'] ?? '')));
        $comments = strtolower(trim((string)($agent['comentarios'] ?? '')));
        $group = strtolower(trim((string)($agent['group_name'] ?? '')));
        $parentId = (int)($agent['id_parent'] ?? 0);

        $textSearch = " {$alias} {$nombre} {$so} {$comments} {$group} ";

        // 2. Datacenter / SDDC
        if (preg_match('/(datacenter|data-center|data center|sddc|ca-east|hq-site|core-site)/i', $textSearch)) {
            return [
                'role' => 'datacenter',
                'title' => 'VMware vSphere Datacenter',
                'icon' => 'building',
                'is_custom' => false
            ];
        }

        // 3. Cluster
        if (preg_match('/(cluster|mgmt-cluster|management-cluster|workload-cluster|vsan-cluster|k8s-cluster)/i', $textSearch)) {
            return [
                'role' => 'cluster',
                'title' => 'VMware vSphere Cluster',
                'icon' => 'cluster',
                'is_custom' => false
            ];
        }

        // 4. vCenter / Cloud Manager
        if (preg_match('/(vcenter|vcsa|cloud-manager|vcentre|horizon-conn)/i', $textSearch) && !preg_match('/(vm|virtual|clone)/i', $alias)) {
            return [
                'role' => 'vcenter',
                'title' => 'VMware vSphere vCenter',
                'icon' => 'monitor_app',
                'is_custom' => false
            ];
        }

        // 5. Storage / Datastore
        if (preg_match('/(datastore|storage|nfs|iscsi|ssd|nvme|vsandatastore|san|nas|store|diskarray|synology|qnap)/i', $textSearch)) {
            return [
                'role' => 'storage',
                'title' => 'VMware vSphere Datastore',
                'icon' => 'database_disks',
                'is_custom' => false
            ];
        }

        // 6. Hypervisor / ESXi Host
        if (preg_match('/(esxi|esx|hypervisor|proxmox|pve|kvm-host|hyper-v|xen-server)/i', $textSearch)) {
            return [
                'role' => 'hypervisor',
                'title' => 'VMware vSphere Hypervisor',
                'icon' => 'server_rack',
                'is_custom' => false
            ];
        }

        // 7. Virtual Machine (VM)
        if (preg_match('/( vm|vm |vmware|virtual|guest|clone|witness|rke2|rke|instance|guest-os)/i', $textSearch) || $parentId > 0) {
            return [
                'role' => 'vm',
                'title' => 'VMware vSphere VM',
                'icon' => 'laptop',
                'is_custom' => false
            ];
        }

        // 8. Network Switch
        if (preg_match('/(switch|sw-|sw_|catalyst|nexus|aruba|huawei-sw|juniper-ex)/i', $textSearch)) {
            return [
                'role' => 'switch',
                'title' => 'Network Switch',
                'icon' => 'switch',
                'is_custom' => false
            ];
        }

        // 9. Network Router
        if (preg_match('/(router|rt-|rt_|gateway|mikrotik|routeros|cisco-asr|cisco-isr|vyos)/i', $textSearch)) {
            return [
                'role' => 'router',
                'title' => 'Network Router',
                'icon' => 'router',
                'is_custom' => false
            ];
        }

        // 10. Firewall
        if (preg_match('/(firewall|fortigate|paloalto|pfsense|opnsense|fw-|fw_|pan-os)/i', $textSearch)) {
            return [
                'role' => 'firewall',
                'title' => 'Security Firewall',
                'icon' => 'firewall',
                'is_custom' => false
            ];
        }

        // Default Server
        return [
            'role' => 'server',
            'title' => 'Physical Server',
            'icon' => 'server',
            'is_custom' => false
        ];
    }

    /**
     * Get reference demo topology data matching the user's screenshot.
     */
    public static function getReferenceDemoTopology(): array {
        $nodes = [
            // Core Datacenter (Warning status, center anchor)
            [
                'id' => 'demo:ca_east_02_sddc',
                'label' => 'CA-EAST-02-SDDC',
                'role' => 'datacenter',
                'role_title' => 'VMware vSphere Datacenter',
                'ip' => '10.128.8.1',
                'status' => 'warning',
                'alert_count' => 1,
                'x' => 110,
                'y' => 200
            ],
            // Clusters
            [
                'id' => 'demo:mgmt_cluster',
                'label' => 'Management-Cluster',
                'role' => 'cluster',
                'role_title' => 'VMware vSphere Cluster',
                'ip' => '10.128.8.10',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => -110,
                'y' => -100
            ],
            [
                'id' => 'demo:workload_cluster',
                'label' => 'Workload-Cluster',
                'role' => 'cluster',
                'role_title' => 'VMware vSphere Cluster',
                'ip' => '10.128.8.20',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => -120,
                'y' => 110
            ],
            // Hypervisors & vCenter host
            [
                'id' => 'demo:esx_211',
                'label' => '10.128.8.211',
                'role' => 'hypervisor',
                'role_title' => 'VMware vSphere Hypervisor',
                'ip' => '10.128.8.211',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => -260,
                'y' => -40
            ],
            [
                'id' => 'demo:esx_213',
                'label' => '10.128.8.213',
                'role' => 'hypervisor',
                'role_title' => 'VMware vSphere Hypervisor',
                'ip' => '10.128.8.213',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => 380,
                'y' => 290
            ],
            [
                'id' => 'demo:vc_host',
                'label' => '10.128.8.6',
                'role' => 'vcenter',
                'role_title' => 'VMware vSphere vCenter',
                'ip' => '10.128.8.6',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => -170,
                'y' => 350
            ],
            // VMs linked to Management Cluster / ESX 211
            [
                'id' => 'demo:vm_weekly_vcenter',
                'label' => 'Weekly Clone vCenter',
                'role' => 'vm',
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.40',
                'status' => 'critical',
                'alert_count' => 1,
                'x' => -580,
                'y' => -190
            ],
            [
                'id' => 'demo:vm_pro2',
                'label' => 'pro2-esx211-fadfd8df-8lvpt',
                'role' => 'vm',
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.41',
                'status' => 'critical',
                'alert_count' => 1,
                'x' => -680,
                'y' => -80
            ],
            [
                'id' => 'demo:vm_clone20',
                'label' => '20230520-Weekly Clone ...',
                'role' => 'vm',
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.42',
                'status' => 'critical',
                'alert_count' => 1,
                'x' => -390,
                'y' => 50
            ],
            [
                'id' => 'demo:vm_bkp',
                'label' => 'BKP Server 192.168.2.33',
                'role' => 'vm',
                'role_title' => 'VMware vSphere VM',
                'ip' => '192.168.2.33',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => -790,
                'y' => -200
            ],
            [
                'id' => 'demo:vm_rke2',
                'label' => 'rke2-esx211-6ff591c8-2kn...',
                'role' => 'vm',
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.44',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => -530,
                'y' => -80
            ],
            [
                'id' => 'demo:vm_witness',
                'label' => 'vSAN8U1 ESA Witness',
                'role' => 'vm',
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.45',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => -430,
                'y' => -150
            ],
            [
                'id' => 'demo:vm_clone27',
                'label' => '20230527-Weekly Clone ...',
                'role' => 'vm',
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.46',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => -510,
                'y' => 120
            ],
            // VMs linked to Workload Cluster
            [
                'id' => 'demo:vm_vc_guest',
                'label' => 'vCenter - 10.128.8.6',
                'role' => 'vm',
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.6',
                'status' => 'warning',
                'alert_count' => 1,
                'x' => -850,
                'y' => 70
            ],
            [
                'id' => 'demo:vm_cloud_mgr',
                'label' => 'cloud-manager - 10.128.8.7',
                'role' => 'vm',
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.7',
                'status' => 'warning',
                'alert_count' => 1,
                'x' => -700,
                'y' => 70
            ],
            // Datastores / Storage Disks (Right & Bottom Fan-out)
            [
                'id' => 'demo:ds_1tb',
                'label' => 'Internal 1TB SSD - ESX211',
                'role' => 'storage',
                'role_title' => 'VMware vSphere Datastore',
                'ip' => '10.128.8.50',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => 600,
                'y' => 80
            ],
            [
                'id' => 'demo:ds_nvme',
                'label' => 'NVMe AI Store',
                'role' => 'storage',
                'role_title' => 'VMware vSphere Datastore',
                'ip' => '10.128.8.51',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => 450,
                'y' => 170
            ],
            [
                'id' => 'demo:ds_vsan',
                'label' => 'vsanDatastore',
                'role' => 'storage',
                'role_title' => 'VMware vSphere Datastore',
                'ip' => '10.128.8.52',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => 670,
                'y' => 180
            ],
            [
                'id' => 'demo:ds_nfs',
                'label' => 'NFS Bronze',
                'role' => 'storage',
                'role_title' => 'VMware vSphere Datastore',
                'ip' => '10.128.8.53',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => 590,
                'y' => 290
            ],
            [
                'id' => 'demo:ds_500gb',
                'label' => 'Internal 500GB SSD',
                'role' => 'storage',
                'role_title' => 'VMware vSphere Datastore',
                'ip' => '10.128.8.54',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => -470,
                'y' => 290
            ],
            [
                'id' => 'demo:ds_500gb_es',
                'label' => 'Internal 500GB SSD - ES...',
                'role' => 'storage',
                'role_title' => 'VMware vSphere Datastore',
                'ip' => '10.128.8.55',
                'status' => 'normal',
                'alert_count' => 0,
                'x' => -320,
                'y' => 330
            ]
        ];

        $edges = [
            // Core DC connections
            ['source' => 'demo:ca_east_02_sddc', 'target' => 'demo:mgmt_cluster', 'label' => 'Cluster Member'],
            ['source' => 'demo:ca_east_02_sddc', 'target' => 'demo:workload_cluster', 'label' => 'Cluster Member'],
            ['source' => 'demo:ca_east_02_sddc', 'target' => 'demo:esx_213', 'label' => 'Host Attach'],
            ['source' => 'demo:ca_east_02_sddc', 'target' => 'demo:vc_host', 'label' => 'Management'],
            ['source' => 'demo:ca_east_02_sddc', 'target' => 'demo:ds_1tb', 'label' => 'vSAN Attached'],
            ['source' => 'demo:ca_east_02_sddc', 'target' => 'demo:ds_nvme', 'label' => 'Direct Storage'],
            ['source' => 'demo:ca_east_02_sddc', 'target' => 'demo:ds_vsan', 'label' => 'Pool Attached'],
            ['source' => 'demo:ca_east_02_sddc', 'target' => 'demo:ds_500gb', 'label' => 'Host Storage'],
            ['source' => 'demo:ca_east_02_sddc', 'target' => 'demo:ds_500gb_es', 'label' => 'Local SSD'],

            // Management Cluster to ESX 211
            ['source' => 'demo:mgmt_cluster', 'target' => 'demo:esx_211', 'label' => 'Node Link'],

            // ESX 211 to its hosted VMs
            ['source' => 'demo:esx_211', 'target' => 'demo:vm_weekly_vcenter', 'label' => 'VM Guest'],
            ['source' => 'demo:esx_211', 'target' => 'demo:vm_pro2', 'label' => 'VM Guest'],
            ['source' => 'demo:esx_211', 'target' => 'demo:vm_clone20', 'label' => 'VM Guest'],
            ['source' => 'demo:esx_211', 'target' => 'demo:vm_rke2', 'label' => 'VM Guest'],
            ['source' => 'demo:esx_211', 'target' => 'demo:vm_witness', 'label' => 'VM Guest'],
            ['source' => 'demo:esx_211', 'target' => 'demo:vm_clone27', 'label' => 'VM Guest'],
            ['source' => 'demo:esx_211', 'target' => 'demo:vm_bkp', 'label' => 'Backup VM'],

            // Workload Cluster to its hosted VMs
            ['source' => 'demo:workload_cluster', 'target' => 'demo:vm_vc_guest', 'label' => 'Cluster VM'],
            ['source' => 'demo:workload_cluster', 'target' => 'demo:vm_cloud_mgr', 'label' => 'Cloud Appliance'],

            // ESX 213 to NFS Bronze
            ['source' => 'demo:esx_213', 'target' => 'demo:ds_nfs', 'label' => 'NFS Mount']
        ];

        return [
            'nodes' => $nodes,
            'edges' => $edges
        ];
    }
}
