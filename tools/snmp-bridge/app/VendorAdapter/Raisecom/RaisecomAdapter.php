<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\Raisecom;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\RaisecomEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

final class RaisecomAdapter implements VendorAdapterInterface
{
    public function name(): string
    {
        return 'Raisecom';
    }

    public function enterpriseOid(): string
    {
        return '.1.3.6.1.4.1.8886';
    }

    public function sysObjectIds(): array
    {
        return [
            '.1.3.6.1.4.1.8886',
        ];
    }

    public function sysDescrPatterns(): array
    {
        return [
            '/\bRaisecom\b/i',
            '/ISCOM|MSG\d+/i',
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
        return new RaisecomEntityMapper();
    }

    public function discoveryOids(): array
    {
        return [
            'optical_dom' => [
                [
                    'oid' => '.1.3.6.1.4.1.8886.1.18.2.2.1.1.2',
                    'index_strategy' => 'parameter_last',
                    'source' => 'RAISECOM-OPTICAL-TRANSCEIVER-MIB',
                    'parameter_map' => $this->opticalParameterMap(),
                ],
                [
                    'oid' => '.1.3.6.1.4.1.8886.60.18.1.2.2.1.1.2',
                    'index_strategy' => 'parameter_last',
                    'source' => 'ROSMGMT-OPTICAL-TRANSCEIVER-MIB',
                    'parameter_map' => $this->opticalParameterMap(),
                ],
            ],
            'gpon' => [
                [
                    'name' => 'ONU Rx Power',
                    'oid' => '.1.3.6.1.4.1.8886.18.3.4.33.1.7',
                    'unit' => 'dBm',
                    'scale' => '0.01',
                    'precision' => 0,
                    'sensor_type' => 'rx_power',
                    'index_strategy' => 'first_ifindex',
                    'source' => 'RAISECOM-PON-DEVICE-MIB',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function opticalParameterMap(): array
    {
        return [
            '1' => [
                'name' => 'Transceiver Temperature',
                'unit' => 'C',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'temperature',
            ],
            '2' => [
                'name' => 'TX Bias',
                'unit' => 'mA',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'bias_current',
            ],
            '3' => [
                'name' => 'TX Power',
                'unit' => 'dBm',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'tx_power',
            ],
            '4' => [
                'name' => 'RX Power',
                'unit' => 'dBm',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'rx_power',
            ],
            '5' => [
                'name' => 'Laser Temperature',
                'unit' => 'C',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'temperature',
            ],
            '6' => [
                'name' => 'Supply Voltage',
                'unit' => 'V',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'voltage',
            ],
            '7' => [
                'name' => 'TX Voltage',
                'unit' => 'V',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'voltage',
            ],
            '8' => [
                'name' => 'RX Voltage',
                'unit' => 'V',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'voltage',
            ],
            '9' => [
                'name' => 'APD Voltage',
                'unit' => 'V',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'voltage',
            ],
            '10' => [
                'name' => 'Laser Voltage',
                'unit' => 'V',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'voltage',
            ],
            '11' => [
                'name' => 'TX Current',
                'unit' => 'mA',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'current',
            ],
            '12' => [
                'name' => 'RX Current',
                'unit' => 'mA',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'current',
            ],
            '13' => [
                'name' => 'Laser Current',
                'unit' => 'mA',
                'scale' => 'milli',
                'precision' => 0,
                'sensor_type' => 'current',
            ],
        ];
    }
}
