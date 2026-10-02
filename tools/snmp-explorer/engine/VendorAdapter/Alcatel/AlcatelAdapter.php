<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\Alcatel;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\GenericEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

final class AlcatelAdapter implements VendorAdapterInterface
{
    public function name(): string
    {
        return 'Alcatel/Nokia';
    }

    public function enterpriseOid(): string
    {
        return '.1.3.6.1.4.1.637';
    }

    public function sysObjectIds(): array
    {
        return [
            '.1.3.6.1.4.1.637',
            '.1.3.6.1.4.1.6527',
        ];
    }

    public function sysDescrPatterns(): array
    {
        return [
            '/Alcatel|Nokia|TiMOS|ISAM/i',
        ];
    }

    public function capabilities(): VendorCapability
    {
        return new VendorCapability(
            supportsOpticalDom: true,
            supportsEnvironment: true,
            supportsGpon: true,
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
            'optical_dom' => [
                [
                    'name' => 'SFP TX Power',
                    'oid' => '.1.3.6.1.4.1.637.61.1.56.5.1.6',
                    'unit' => 'dBm',
                    'scale' => 'units',
                    'sensor_type' => 'tx_power',
                    'append_index' => true,
                    'source' => 'SFP-MIB::sfpDiagTxPower',
                ],
                [
                    'name' => 'SFP RX Power',
                    'oid' => '.1.3.6.1.4.1.637.61.1.56.5.1.7',
                    'unit' => 'dBm',
                    'scale' => 'units',
                    'sensor_type' => 'rx_power',
                    'append_index' => true,
                    'source' => 'SFP-MIB::sfpDiagRxPower',
                ],
                [
                    'name' => 'SFP TX Bias',
                    'oid' => '.1.3.6.1.4.1.637.61.1.56.5.1.8',
                    'unit' => 'mA',
                    'scale' => 'units',
                    'sensor_type' => 'bias_current',
                    'append_index' => true,
                    'source' => 'SFP-MIB::sfpDiagTxBiasCurrent',
                ],
                [
                    'name' => 'SFP Supply Voltage',
                    'oid' => '.1.3.6.1.4.1.637.61.1.56.5.1.9',
                    'unit' => 'V',
                    'scale' => 'units',
                    'sensor_type' => 'voltage',
                    'append_index' => true,
                    'source' => 'SFP-MIB::sfpDiagSupplyVoltage',
                ],
                [
                    'name' => 'SFP Temperature',
                    'oid' => '.1.3.6.1.4.1.637.61.1.56.5.1.10',
                    'unit' => 'C',
                    'scale' => 'units',
                    'sensor_type' => 'temperature',
                    'append_index' => true,
                    'source' => 'SFP-MIB::sfpDiagTemperature',
                ],
            ],
        ];
    }
}
