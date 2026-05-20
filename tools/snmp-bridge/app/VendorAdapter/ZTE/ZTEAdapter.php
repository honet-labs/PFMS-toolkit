<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\ZTE;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\GenericEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

final class ZTEAdapter implements VendorAdapterInterface
{
    public function name(): string
    {
        return 'ZTE';
    }

    public function enterpriseOid(): string
    {
        return '.1.3.6.1.4.1.3902';
    }

    public function sysObjectIds(): array
    {
        return [
            '.1.3.6.1.4.1.3902',
        ];
    }

    public function sysDescrPatterns(): array
    {
        return [
            '/\bZTE\b/i',
            '/ZXA10|ZXHN|C3[0-9]{2}/i',
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
                    'name' => 'RX Power',
                    'oid' => '.1.3.6.1.4.1.3902.1082.30.40.2.4.1.2',
                    'unit' => 'dBm',
                    'scale' => 'milli',
                    'sensor_type' => 'rx_power',
                    'index_strategy' => 'ifindex',
                    'source' => 'ZTE-AN-OPTICAL-MODULE-MIB::zxAnOpticalIfRxPwrCurrValue',
                ],
                [
                    'name' => 'TX Power',
                    'oid' => '.1.3.6.1.4.1.3902.1082.30.40.2.4.1.3',
                    'unit' => 'dBm',
                    'scale' => 'milli',
                    'sensor_type' => 'tx_power',
                    'index_strategy' => 'ifindex',
                    'source' => 'ZTE-AN-OPTICAL-MODULE-MIB::zxAnOpticalIfTxPwrCurrValue',
                ],
                [
                    'name' => 'TX Bias',
                    'oid' => '.1.3.6.1.4.1.3902.1082.30.40.2.4.1.5',
                    'unit' => 'mA',
                    'scale' => 'milli',
                    'sensor_type' => 'bias_current',
                    'index_strategy' => 'ifindex',
                    'source' => 'ZTE-AN-OPTICAL-MODULE-MIB::zxAnOpticalBiasCurrent',
                ],
                [
                    'name' => 'Supply Voltage',
                    'oid' => '.1.3.6.1.4.1.3902.1082.30.40.2.4.1.6',
                    'unit' => 'V',
                    'scale' => 'milli',
                    'sensor_type' => 'voltage',
                    'index_strategy' => 'ifindex',
                    'source' => 'ZTE-AN-OPTICAL-MODULE-MIB::zxAnOpticalSupplyVoltage',
                ],
                [
                    'name' => 'Temperature',
                    'oid' => '.1.3.6.1.4.1.3902.1082.30.40.2.4.1.8',
                    'unit' => 'C',
                    'scale' => 'milli',
                    'sensor_type' => 'temperature',
                    'index_strategy' => 'ifindex',
                    'source' => 'ZTE-AN-OPTICAL-MODULE-MIB::zxAnOpticalTemperature',
                ],
            ],
            'gpon' => [
                [
                    'name' => 'ONU Rx Power',
                    'oid' => '.1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.1.5',
                    'unit' => 'dBm',
                    'scale' => '0.01',
                    'sensor_type' => 'rx_power',
                    'index_strategy' => 'first_ifindex',
                ],
                [
                    'name' => 'ONU Tx Power',
                    'oid' => '.1.3.6.1.4.1.3902.1082.500.10.2.3.3.1.1.6',
                    'unit' => 'dBm',
                    'scale' => '0.01',
                    'sensor_type' => 'tx_power',
                    'index_strategy' => 'first_ifindex',
                ],
            ],
        ];
    }
}
