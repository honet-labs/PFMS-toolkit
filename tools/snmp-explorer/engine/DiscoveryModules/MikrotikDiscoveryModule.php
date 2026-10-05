<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Helpers\SnmpValueHelper;
use SnmpBridge\Helpers\StringHelper;

final class MikrotikDiscoveryModule implements DiscoveryModuleInterface
{
    private const string MIKROTIK_ROOT = '1.3.6.1.4.1.14988';

    // Health metrics
    private const string MTXR_CPU = '1.3.6.1.4.1.14988.1.1.1.8.0';
    private const string MTXR_VOLTAGE = '1.3.6.1.4.1.14988.1.1.3.8.0';
    private const string MTXR_TEMPERATURE = '1.3.6.1.4.1.14988.1.1.3.10.0';
    private const string MTXR_BOARD_NAME = '1.3.6.1.4.1.14988.1.1.7.4.0';
    private const string MTXR_VERSION = '1.3.6.1.4.1.14988.1.1.7.7.0';
    private const string MTXR_FIRMWARE = '1.3.6.1.4.1.14988.1.1.7.8.0';

    public function __construct(
        private NormalizerInterface $normalizer,
    ) {
    }

    public function name(): string
    {
        return 'mikrotik_discovery';
    }

    public function supports(DiscoveryContext $context): bool
    {
        if ($context->vendor->name() === 'MikroTik') {
            return true;
        }

        $sysObj = $context->device->sysObjectId();
        if ($sysObj !== null && str_contains($sysObj, '14988')) {
            return true;
        }

        $sysDescr = $context->device->sysDescr();
        if ($sysDescr !== null && preg_match('/MikroTik|RouterOS/i', $sysDescr) === 1) {
            return true;
        }

        return false;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        // CPU
        $cpu = SnmpValueHelper::numeric($context->walker->get(self::MTXR_CPU));
        if ($cpu !== null) {
            $this->addSensor($sensors, 'MikroTik CPU Load', 'cpu_usage', 'processor', self::MTXR_CPU, $cpu, '%', [
                'source' => 'MIKROTIK-MIB::mtxrProcessorLoad',
            ]);
        }

        // Temperature (divide by 10 if > 100)
        $temp = SnmpValueHelper::numeric($context->walker->get(self::MTXR_TEMPERATURE));
        if ($temp !== null) {
            $val = $temp > 100 ? round($temp / 10.0, 1) : $temp;
            $this->addSensor($sensors, 'MikroTik Temperature', 'temperature', 'environmental', self::MTXR_TEMPERATURE, $val, 'C', [
                'source' => 'MIKROTIK-MIB::mtxrHlTemperature',
            ]);
        }

        // Voltage (divide by 10)
        $volt = SnmpValueHelper::numeric($context->walker->get(self::MTXR_VOLTAGE));
        if ($volt !== null) {
            $val = round($volt / 10.0, 1);
            $this->addSensor($sensors, 'MikroTik Voltage', 'voltage', 'environmental', self::MTXR_VOLTAGE, $val, 'V', [
                'source' => 'MIKROTIK-MIB::mtxrHlVoltage',
            ]);
        }

        return $sensors;
    }

    private function addSensor(
        array &$sensors,
        string $metricName,
        string $sensorType,
        string $sensorClass,
        string $oid,
        int|float $numericValue,
        string $unit,
        array $metadata,
    ): void {
        $sensor = [
            'sensor_class' => $sensorClass,
            'sensor_name' => StringHelper::safeModuleName($metricName),
            'sensor_type' => $sensorType,
            'interface_index' => null,
            'interface_name' => null,
            'entity_index' => null,
            'oid' => $oid,
            'raw_value' => (string) $numericValue,
            'unit' => $unit,
            'scale' => 'units',
            'precision' => 0,
            'status' => 'ok',
            'metadata' => array_merge(['discovery_module' => 'MikrotikDiscoveryModule'], $metadata),
        ];

        $normalized = $this->normalizer->normalize($sensor);
        if ($normalized !== null) {
            $sensors[] = $normalized;
        }
    }
}
