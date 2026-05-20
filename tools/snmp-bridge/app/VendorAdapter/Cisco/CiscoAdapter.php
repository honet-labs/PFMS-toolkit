<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\Cisco;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\GenericEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

final class CiscoAdapter implements VendorAdapterInterface
{
    public function name(): string
    {
        return 'Cisco';
    }

    public function enterpriseOid(): string
    {
        return '.1.3.6.1.4.1.9';
    }

    public function sysObjectIds(): array
    {
        return [
            '.1.3.6.1.4.1.9',
        ];
    }

    public function sysDescrPatterns(): array
    {
        return [
            '/\bCisco\b/i',
            '/IOS[- ]XE|NX-OS|Catalyst|Nexus/i',
        ];
    }

    public function capabilities(): VendorCapability
    {
        return new VendorCapability(
            supportsOpticalDom: true,
            supportsEnvironment: true,
            supportsGpon: false,
            requiresEntityMapping: true,
        );
    }

    public function entityMapper(): EntityMappingInterface
    {
        return new GenericEntityMapper();
    }

    public function discoveryOids(): array
    {
        return [
            'environmental' => [
                [
                    'name' => 'Cisco Temperature',
                    'oid' => '.1.3.6.1.4.1.9.9.13.1.3.1.3',
                    'unit' => 'C',
                    'scale' => 'units',
                    'sensor_type' => 'temperature',
                    'append_index' => true,
                    'source' => 'CISCO-ENVMON-MIB::ciscoEnvMonTemperatureStatusValue',
                ],
                [
                    'name' => 'Cisco Voltage',
                    'oid' => '.1.3.6.1.4.1.9.9.13.1.2.1.3',
                    'unit' => 'V',
                    'scale' => 'milli',
                    'sensor_type' => 'voltage',
                    'append_index' => true,
                    'source' => 'CISCO-ENVMON-MIB::ciscoEnvMonVoltageStatusValue',
                ],
            ],
        ];
    }
}
