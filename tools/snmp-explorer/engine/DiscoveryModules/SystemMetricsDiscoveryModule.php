<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Snmp\SnmpHelper;
use SnmpBridge\Helpers\SensorNameFormatter;
use SnmpBridge\Helpers\SnmpValueHelper;
use SnmpBridge\Helpers\StringHelper;

final readonly class SystemMetricsDiscoveryModule implements DiscoveryModuleInterface
{
    private const string SYS_UPTIME = '1.3.6.1.2.1.1.3.0';
    private const string SYS_CONTACT = '1.3.6.1.2.1.1.4.0';
    private const string SYS_NAME = '1.3.6.1.2.1.1.5.0';
    private const string SYS_LOCATION = '1.3.6.1.2.1.1.6.0';
    private const string SYS_SERVICES = '1.3.6.1.2.1.1.7.0';

    public function __construct(
        private NormalizerInterface $normalizer,
        private SensorNameFormatter $formatter = new SensorNameFormatter(),
    ) {
    }

    public function name(): string
    {
        return 'system_metrics';
    }

    public function supports(DiscoveryContext $context): bool
    {
        return true;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        $this->appendNumericSensor(
            $sensors,
            'System Uptime',
            'uptime',
            self::SYS_UPTIME,
            $this->uptimeSeconds($context->walker->get(self::SYS_UPTIME)),
            'secs',
            ['source' => 'SNMPv2-MIB::sysUpTime'],
        );

        $sysServices = SnmpValueHelper::integer($context->walker->get(self::SYS_SERVICES));
        $this->appendNumericSensor(
            $sensors,
            'System Services',
            'sys_services',
            self::SYS_SERVICES,
            $sysServices,
            '',
            [
                'source' => 'SNMPv2-MIB::sysServices',
                'layers' => $this->sysServicesLayers($sysServices),
            ],
        );

        $this->appendTextSensor(
            $sensors,
            'System Name',
            'sys_name',
            self::SYS_NAME,
            $context->walker->get(SnmpHelper::SYS_NAME) ?? $context->walker->get(self::SYS_NAME),
            ['source' => 'SNMPv2-MIB::sysName'],
        );

        $this->appendTextSensor(
            $sensors,
            'System Object ID',
            'sys_object_id',
            SnmpHelper::SYS_OBJECT_ID,
            $context->walker->get(SnmpHelper::SYS_OBJECT_ID),
            ['source' => 'SNMPv2-MIB::sysObjectID'],
        );

        $this->appendTextSensor(
            $sensors,
            'System Description',
            'sys_descr',
            SnmpHelper::SYS_DESCR,
            $context->walker->get(SnmpHelper::SYS_DESCR),
            ['source' => 'SNMPv2-MIB::sysDescr'],
        );

        $this->appendTextSensor(
            $sensors,
            'System Contact',
            'sys_contact',
            self::SYS_CONTACT,
            $context->walker->get(self::SYS_CONTACT),
            ['source' => 'SNMPv2-MIB::sysContact'],
            skipValues: ['(No Contact String)', 'No Contact String'],
        );

        $this->appendTextSensor(
            $sensors,
            'System Location',
            'sys_location',
            self::SYS_LOCATION,
            $context->walker->get(self::SYS_LOCATION),
            ['source' => 'SNMPv2-MIB::sysLocation'],
            skipValues: ['(No Location String)', 'No Location String'],
        );

        return $sensors;
    }

    private function uptimeSeconds(mixed $uptime): ?int
    {
        $ticks = SnmpValueHelper::integer($uptime);

        return $ticks === null ? null : (int) floor($ticks / 100);
    }

    /**
     * @return list<string>
     */
    private function sysServicesLayers(?int $value): array
    {
        if ($value === null) {
            return [];
        }

        $layers = [
            1 => 'physical',
            2 => 'datalink',
            4 => 'internet',
            8 => 'end-to-end',
            16 => 'applications',
        ];
        $enabled = [];

        foreach ($layers as $bit => $name) {
            if (($value & $bit) === $bit) {
                $enabled[] = $name;
            }
        }

        return $enabled;
    }

    /**
     * @param list<array<string, mixed>> $sensors
     * @param array<string, mixed> $metadata
     */
    private function appendNumericSensor(
        array &$sensors,
        string $metric,
        string $sensorType,
        string $oid,
        mixed $value,
        string $unit,
        array $metadata,
    ): void {
        $numeric = SnmpValueHelper::numeric($value);

        if ($numeric === null) {
            return;
        }

        $sensor = [
            'sensor_class' => 'system',
            'sensor_name' => StringHelper::safeModuleName($this->formatter->systemMetric($metric, $unit)),
            'sensor_type' => $sensorType,
            'interface_index' => null,
            'interface_name' => null,
            'entity_index' => null,
            'oid' => $oid,
            'raw_value' => (string) $numeric,
            'unit' => $unit,
            'scale' => 'units',
            'precision' => 0,
            'status' => 'ok',
            'metadata' => ['discovery_module' => 'SystemMetricsDiscoveryModule'] + $metadata,
        ];

        $normalized = $this->normalizer->normalize($sensor);

        if ($normalized !== null) {
            $sensors[] = $normalized;
        }
    }

    /**
     * @param list<array<string, mixed>> $sensors
     * @param array<string, mixed> $metadata
     * @param list<string> $skipValues
     */
    private function appendTextSensor(
        array &$sensors,
        string $metric,
        string $sensorType,
        string $oid,
        mixed $value,
        array $metadata,
        array $skipValues = [],
    ): void {
        $text = $this->cleanText($value);

        if ($text === '' || in_array($text, $skipValues, true)) {
            return;
        }

        $sensors[] = [
            'sensor_class' => 'system',
            'sensor_name' => StringHelper::safeModuleName($this->formatter->generic('System', $metric)),
            'sensor_type' => $sensorType,
            'interface_index' => null,
            'interface_name' => null,
            'entity_index' => null,
            'oid' => $oid,
            'raw_value' => substr($text, 0, 255),
            'normalized_value' => null,
            'unit' => '',
            'scale' => null,
            'precision' => null,
            'status' => 'ok',
            'metadata' => ['discovery_module' => 'SystemMetricsDiscoveryModule', 'full_value' => $text] + $metadata,
        ];
    }

    private function cleanText(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        if (in_array(strtoupper($text), ['NULL', 'NOSUCHOBJECT', 'NOSUCHINSTANCE'], true)) {
            return '';
        }

        return trim($text, "\"'");
    }
}
