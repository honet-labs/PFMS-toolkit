<?php
declare(strict_types=1);

namespace TopologyNetwork\Engine;

/**
 * DeviceClassifier
 * Intelligently classifies Pandora FMS agents and infrastructure nodes into SDDC/vSphere network topology roles.
 */
class DeviceClassifier
{
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

    /**
     * Role definitions, labels, and visual icon identifiers.
     */
    public static function getRoles(): array
    {
        return [
            self::ROLE_VM => [
                'id' => self::ROLE_VM,
                'title' => 'VMware vSphere VM',
                'short_title' => 'VM',
                'icon' => 'laptop',
                'category' => 'compute',
                'badge_color' => '#3b82f6',
                'rank' => 1
            ],
            self::ROLE_HYPERVISOR => [
                'id' => self::ROLE_HYPERVISOR,
                'title' => 'VMware vSphere Hypervisor',
                'short_title' => 'Hypervisor',
                'icon' => 'server_chassis',
                'category' => 'compute',
                'badge_color' => '#1e293b',
                'rank' => 2
            ],
            self::ROLE_CLUSTER => [
                'id' => self::ROLE_CLUSTER,
                'title' => 'VMware vSphere Cluster',
                'short_title' => 'Cluster',
                'icon' => 'cluster_grid',
                'category' => 'compute',
                'badge_color' => '#475569',
                'rank' => 3
            ],
            self::ROLE_DATACENTER => [
                'id' => self::ROLE_DATACENTER,
                'title' => 'VMware vSphere Datacenter',
                'short_title' => 'Datacenter',
                'icon' => 'datacenter_building',
                'category' => 'compute',
                'badge_color' => '#0f172a',
                'rank' => 4
            ],
            self::ROLE_STORAGE => [
                'id' => self::ROLE_STORAGE,
                'title' => 'VMware vSphere Datastore',
                'short_title' => 'Datastore',
                'icon' => 'datastore_stack',
                'category' => 'storage',
                'badge_color' => '#0284c7',
                'rank' => 2
            ],
            self::ROLE_VCENTER => [
                'id' => self::ROLE_VCENTER,
                'title' => 'VMware vSphere vCenter',
                'short_title' => 'vCenter',
                'icon' => 'vcenter_console',
                'category' => 'management',
                'badge_color' => '#64748b',
                'rank' => 3
            ],
            self::ROLE_SWITCH => [
                'id' => self::ROLE_SWITCH,
                'title' => 'Network Switch',
                'short_title' => 'Switch',
                'icon' => 'switch',
                'category' => 'network',
                'badge_color' => '#0d9488',
                'rank' => 3
            ],
            self::ROLE_ROUTER => [
                'id' => self::ROLE_ROUTER,
                'title' => 'Network Router',
                'short_title' => 'Router',
                'icon' => 'router',
                'category' => 'network',
                'badge_color' => '#059669',
                'rank' => 4
            ],
            self::ROLE_FIREWALL => [
                'id' => self::ROLE_FIREWALL,
                'title' => 'Security Firewall',
                'short_title' => 'Firewall',
                'icon' => 'firewall',
                'category' => 'network',
                'badge_color' => '#dc2626',
                'rank' => 5
            ],
            self::ROLE_SERVER => [
                'id' => self::ROLE_SERVER,
                'title' => 'Host Server',
                'short_title' => 'Server',
                'icon' => 'server_chassis',
                'category' => 'compute',
                'badge_color' => '#334155',
                'rank' => 2
            ]
        ];
    }

    /**
     * Classify an agent into a role based on metadata.
     */
    public static function classifyAgent(array $agent, ?string $manualOverride = null): string
    {
        if (!empty($manualOverride) && isset(self::getRoles()[$manualOverride])) {
            return $manualOverride;
        }

        $haystack = strtolower(implode(' ', [
            $agent['nombre'] ?? '',
            $agent['alias'] ?? '',
            $agent['comentarios'] ?? '',
            $agent['so'] ?? '',
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

        if (preg_match('/(datastore|vsan|san|nas|nfs|nvme|storage|iscsi|lun|disk-array)/i', $haystack)) {
            return self::ROLE_STORAGE;
        }

        if (preg_match('/(esxi|hypervisor|vmkernel|vsphere host|proxmox|kvm host|xen host)/i', $haystack)) {
            return self::ROLE_HYPERVISOR;
        }

        if (preg_match('/(firewall|pfsense|fortinet|fortigate|paloalto|checkpoint|opnsense|asa)/i', $haystack)) {
            return self::ROLE_FIREWALL;
        }

        if (preg_match('/(router|mikrotik|cisco.*router|bgl|edge-router|vyos|juniper.*mx)/i', $haystack)) {
            return self::ROLE_ROUTER;
        }

        if (preg_match('/(switch|cisco.*catalyst|nexus|huawei.*switch|hp.*procurve|aruba.*switch)/i', $haystack)) {
            return self::ROLE_SWITCH;
        }

        if (preg_match('/(vm|v-machine|guest|virtual machine|qemu|kvm|container|docker|lxc|rke|witness|clone|bkp server)/i', $haystack)) {
            return self::ROLE_VM;
        }

        $so = strtolower($agent['so'] ?? '');
        if (str_contains($so, 'vmware') || str_contains($so, 'esx')) {
            return self::ROLE_HYPERVISOR;
        }
        if (str_contains($so, 'linux') || str_contains($so, 'windows') || str_contains($so, 'ubuntu') || str_contains($so, 'debian') || str_contains($so, 'centos') || str_contains($so, 'redhat')) {
            return self::ROLE_VM;
        }

        return self::ROLE_SERVER;
    }

    /**
     * Provide exact 21-node reference demo topology matching user screenshot.
     */
    public static function getReferenceDemoTopology(): array
    {
        $nodes = [
            // Datacenter Root
            [
                'id' => 'sddc_dc',
                'name' => 'CA-EAST-02-SDDC',
                'role' => self::ROLE_DATACENTER,
                'role_title' => 'VMware vSphere Datacenter',
                'ip' => '10.128.8.1',
                'status' => 'warning',
                'alert_count' => 1,
                'group' => 'SDDC Datacenter'
            ],
            // Clusters
            [
                'id' => 'mgmt_cluster',
                'name' => 'Management-Cluster',
                'role' => self::ROLE_CLUSTER,
                'role_title' => 'VMware vSphere Cluster',
                'ip' => '10.128.8.10',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Management'
            ],
            [
                'id' => 'workload_cluster',
                'name' => 'Workload-Cluster',
                'role' => self::ROLE_CLUSTER,
                'role_title' => 'VMware vSphere Cluster',
                'ip' => '10.128.8.20',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Workload'
            ],
            // Hypervisors
            [
                'id' => 'esx_211',
                'name' => '10.128.8.211',
                'role' => self::ROLE_HYPERVISOR,
                'role_title' => 'VMware vSphere Hypervisor',
                'ip' => '10.128.8.211',
                'status' => 'critical',
                'alert_count' => 1,
                'group' => 'Compute Hosts'
            ],
            [
                'id' => 'esx_213',
                'name' => '10.128.8.213',
                'role' => self::ROLE_HYPERVISOR,
                'role_title' => 'VMware vSphere Hypervisor',
                'ip' => '10.128.8.213',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Compute Hosts'
            ],
            // vCenter Management
            [
                'id' => 'vcenter_core',
                'name' => '10.128.8.6',
                'role' => self::ROLE_VCENTER,
                'role_title' => 'VMware vSphere vCenter',
                'ip' => '10.128.8.6',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Management'
            ],
            // Virtual Machines (Connected to 10.128.8.211)
            [
                'id' => 'vm_bkp',
                'name' => 'BKP Server 192.168.2.33',
                'role' => self::ROLE_VM,
                'role_title' => 'VMware vSphere VM',
                'ip' => '192.168.2.33',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Backup'
            ],
            [
                'id' => 'vm_weekly_vc',
                'name' => 'Weekly Clone vCenter',
                'role' => self::ROLE_VM,
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.45',
                'status' => 'critical',
                'alert_count' => 1,
                'group' => 'Management VMs'
            ],
            [
                'id' => 'vm_vsan_witness',
                'name' => 'vSAN8U1 ESA Witness',
                'role' => self::ROLE_VM,
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.46',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'vSAN'
            ],
            [
                'id' => 'vm_pro2_esx211',
                'name' => 'pro2-esx211-fadfdf-8lvpt',
                'role' => self::ROLE_VM,
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.101',
                'status' => 'critical',
                'alert_count' => 1,
                'group' => 'Kubernetes Nodes'
            ],
            [
                'id' => 'vm_rke2_esx211',
                'name' => 'rke2-esx211-6ff591c8-2kn...',
                'role' => self::ROLE_VM,
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.102',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Kubernetes Nodes'
            ],
            [
                'id' => 'vm_weekly_clone_a',
                'name' => '20230520-Weekly Clone ...',
                'role' => self::ROLE_VM,
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.103',
                'status' => 'critical',
                'alert_count' => 1,
                'group' => 'Snapshots'
            ],
            [
                'id' => 'vm_weekly_clone_b',
                'name' => '20230527-Weekly Clone ...',
                'role' => self::ROLE_VM,
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.104',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Snapshots'
            ],
            // Additional VMs
            [
                'id' => 'vm_vcenter_sub',
                'name' => 'vCenter - 10.128.8.6',
                'role' => self::ROLE_VM,
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.6',
                'status' => 'warning',
                'alert_count' => 1,
                'group' => 'Management VMs'
            ],
            [
                'id' => 'vm_cloud_manager',
                'name' => 'cloud-manager - 10.128.8.7',
                'role' => self::ROLE_VM,
                'role_title' => 'VMware vSphere VM',
                'ip' => '10.128.8.7',
                'status' => 'warning',
                'alert_count' => 1,
                'group' => 'Management VMs'
            ],
            // Datastores / Storage Units
            [
                'id' => 'ds_internal_1tb',
                'name' => 'Internal 1TB SSD - ESX211',
                'role' => self::ROLE_STORAGE,
                'role_title' => 'VMware vSphere Datastore',
                'ip' => 'Storage-LUN-01',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Datastores'
            ],
            [
                'id' => 'ds_nvme_ai',
                'name' => 'NVMe AI Store',
                'role' => self::ROLE_STORAGE,
                'role_title' => 'VMware vSphere Datastore',
                'ip' => 'Storage-LUN-02',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Datastores'
            ],
            [
                'id' => 'ds_vsan',
                'name' => 'vsanDatastore',
                'role' => self::ROLE_STORAGE,
                'role_title' => 'VMware vSphere Datastore',
                'ip' => 'vSAN-Shared',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Datastores'
            ],
            [
                'id' => 'ds_nfs_bronze',
                'name' => 'NFS Bronze',
                'role' => self::ROLE_STORAGE,
                'role_title' => 'VMware vSphere Datastore',
                'ip' => '10.128.8.250',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Datastores'
            ],
            [
                'id' => 'ds_internal_500gb_a',
                'name' => 'Internal 500GB SSD - ES...',
                'role' => self::ROLE_STORAGE,
                'role_title' => 'VMware vSphere Datastore',
                'ip' => 'Storage-LUN-04',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Datastores'
            ],
            [
                'id' => 'ds_internal_500gb_b',
                'name' => 'Internal 500GB SSD - ES...',
                'role' => self::ROLE_STORAGE,
                'role_title' => 'VMware vSphere Datastore',
                'ip' => 'Storage-LUN-05',
                'status' => 'normal',
                'alert_count' => 0,
                'group' => 'Datastores'
            ]
        ];

        $edges = [
            ['id' => 'e_dc_mgmt', 'source' => 'sddc_dc', 'target' => 'mgmt_cluster'],
            ['id' => 'e_dc_workload', 'source' => 'sddc_dc', 'target' => 'workload_cluster'],
            ['id' => 'e_dc_vc', 'source' => 'sddc_dc', 'target' => 'vcenter_core'],
            ['id' => 'e_dc_esx213', 'source' => 'sddc_dc', 'target' => 'esx_213'],
            ['id' => 'e_mgmt_esx211', 'source' => 'mgmt_cluster', 'target' => 'esx_211'],
            
            // ESXi 211 Virtual Machines
            ['id' => 'e_211_bkp', 'source' => 'esx_211', 'target' => 'vm_bkp'],
            ['id' => 'e_211_weekly_vc', 'source' => 'esx_211', 'target' => 'vm_weekly_vc'],
            ['id' => 'e_211_witness', 'source' => 'esx_211', 'target' => 'vm_vsan_witness'],
            ['id' => 'e_211_pro2', 'source' => 'esx_211', 'target' => 'vm_pro2_esx211'],
            ['id' => 'e_211_rke2', 'source' => 'esx_211', 'target' => 'vm_rke2_esx211'],
            ['id' => 'e_211_clone_a', 'source' => 'esx_211', 'target' => 'vm_weekly_clone_a'],
            ['id' => 'e_211_clone_b', 'source' => 'esx_211', 'target' => 'vm_weekly_clone_b'],
            
            // Sub-VM links
            ['id' => 'e_clone_vcsub', 'source' => 'vm_weekly_clone_a', 'target' => 'vm_vcenter_sub'],
            ['id' => 'e_clone_cloudmgr', 'source' => 'vm_weekly_clone_a', 'target' => 'vm_cloud_manager'],

            // Storage connections to Datacenter
            ['id' => 'e_dc_ds1', 'source' => 'sddc_dc', 'target' => 'ds_internal_1tb'],
            ['id' => 'e_dc_ds2', 'source' => 'sddc_dc', 'target' => 'ds_nvme_ai'],
            ['id' => 'e_dc_ds3', 'source' => 'sddc_dc', 'target' => 'ds_vsan'],
            ['id' => 'e_dc_ds4', 'source' => 'sddc_dc', 'target' => 'ds_nfs_bronze'],
            ['id' => 'e_dc_ds5', 'source' => 'sddc_dc', 'target' => 'ds_internal_500gb_a'],
            ['id' => 'e_dc_ds6', 'source' => 'sddc_dc', 'target' => 'ds_internal_500gb_b']
        ];

        return ['nodes' => $nodes, 'edges' => $edges];
    }
}
