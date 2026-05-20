<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Snmp\SnmpHelper;

final class InventoryDiscoveryModule implements DiscoveryModuleInterface
{
    public function name(): string
    {
        return 'inventory';
    }

    public function supports(DiscoveryContext $context): bool
    {
        return true;
    }

    public function discover(DiscoveryContext $context): array
    {
        return [
            [
                'sensor_class' => 'inventory',
                'sensor_name' => 'System Name',
                'sensor_type' => 'string',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => null,
                'oid' => SnmpHelper::SYS_NAME,
                'raw_value' => $context->device['hostname'] ?? '',
                'normalized_value' => null,
                'unit' => '',
                'scale' => null,
                'precision' => null,
                'status' => 'ok',
                'metadata' => [
                    'source' => 'SNMPv2-MIB::sysName.0',
                ],
            ],
            [
                'sensor_class' => 'inventory',
                'sensor_name' => 'System Object ID',
                'sensor_type' => 'oid',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => null,
                'oid' => SnmpHelper::SYS_OBJECT_ID,
                'raw_value' => $context->device['sys_object_id'] ?? '',
                'normalized_value' => null,
                'unit' => '',
                'scale' => null,
                'precision' => null,
                'status' => 'ok',
                'metadata' => [
                    'source' => 'SNMPv2-MIB::sysObjectID.0',
                ],
            ],
        ];
    }
}
