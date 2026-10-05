<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Normalize\SpeedDetector;
use SnmpBridge\Helpers\SensorNameFormatter;
use SnmpBridge\Helpers\SnmpValueHelper;
use SnmpBridge\Helpers\StringHelper;

final readonly class InterfaceDiscoveryModule implements DiscoveryModuleInterface
{
    private const string IF_NUMBER = '1.3.6.1.2.1.2.1.0';
    private const string IF_DESCR = '1.3.6.1.2.1.2.2.1.2';
    private const string IF_TYPE = '1.3.6.1.2.1.2.2.1.3';
    private const string IF_MTU = '1.3.6.1.2.1.2.2.1.4';
    private const string IF_SPEED = '1.3.6.1.2.1.2.2.1.5';
    private const string IF_PHYS_ADDRESS = '1.3.6.1.2.1.2.2.1.6';
    private const string IF_ADMIN_STATUS = '1.3.6.1.2.1.2.2.1.7';
    private const string IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';
    private const string IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';
    private const string IF_ALIAS = '1.3.6.1.2.1.31.1.1.1.18';
    private const string IF_HIGH_SPEED = '1.3.6.1.2.1.31.1.1.1.15';

    private const array IF_TYPES = [
        1 => 'other',
        6 => 'ethernetCsmacd',
        24 => 'softwareLoopback',
        37 => 'atm',
        53 => 'propVirtual',
        54 => 'ppp',
        117 => 'gigabitEthernet',
        131 => 'tunnel',
        135 => 'l2vlan',
        136 => 'l3ipvlan',
        161 => 'ieee8023adLag',
        166 => 'mplsTunnel',
    ];

    private const array ADMIN_STATUS = [
        1 => 'up',
        2 => 'down',
        3 => 'testing',
    ];

    private const array OPER_STATUS = [
        1 => 'up',
        2 => 'down',
        3 => 'testing',
        4 => 'unknown',
        5 => 'dormant',
        6 => 'notPresent',
        7 => 'lowerLayerDown',
    ];

    public function __construct(
        private NormalizerInterface $normalizer,
        private SensorNameFormatter $formatter = new SensorNameFormatter(),
    ) {
    }

    public function name(): string
    {
        return 'interface_discovery';
    }

    public function supports(DiscoveryContext $context): bool
    {
        $ifNumber = SnmpValueHelper::integer($context->walker->get(self::IF_NUMBER));

        if ($ifNumber !== null && $ifNumber >= 0) {
            return true;
        }
        if ($context->walker->walkIndexed(self::IF_DESCR) !== []) {
            return true;
        }
        return $context->walker->walkIndexed(self::IF_NAME) !== [];
    }

    public function discover(DiscoveryContext $context): array
    {
        $ifDescriptions = $context->walker->walkIndexed(self::IF_DESCR);
        $ifNames = $context->walker->walkIndexed(self::IF_NAME);

        if ($ifDescriptions === [] && $ifNames === []) {
            return [];
        }

        $ifAliases = $context->walker->walkIndexed(self::IF_ALIAS);
        $ifTypes = $context->walker->walkIndexed(self::IF_TYPE);
        $ifMtus = $context->walker->walkIndexed(self::IF_MTU);
        $ifAdminStatuses = $context->walker->walkIndexed(self::IF_ADMIN_STATUS);
        $ifOperStatuses = $context->walker->walkIndexed(self::IF_OPER_STATUS);
        $ifHighSpeeds = $context->walker->walkIndexed(self::IF_HIGH_SPEED);
        $ifSpeeds = !empty($ifHighSpeeds) ? [] : $context->walker->walkIndexed(self::IF_SPEED);
        $sensors = [];

        foreach ($this->interfaceIndexes($ifDescriptions, $ifNames, $ifTypes) as $index) {
            $interfaceName = $this->interfaceName(
                $index,
                $ifNames[$index] ?? null,
                $ifDescriptions[$index] ?? null,
                $ifAliases[$index] ?? null,
            );
            $ifType = SnmpValueHelper::integer($ifTypes[$index] ?? null);
            $metadata = [
                'discovery_module' => 'InterfaceDiscoveryModule',
                'interface_index' => (int) $index,
                'if_type' => $ifType,
                'if_type_label' => $this->ifTypeLabel($ifType),
                'if_descr' => $this->cleanText($ifDescriptions[$index] ?? ''),
                'if_alias' => $this->cleanText($ifAliases[$index] ?? ''),
            ];

            // 1. Operational Status
            $operStatus = SnmpValueHelper::integer($ifOperStatuses[$index] ?? null);
            if ($operStatus !== null) {
                $this->appendNumericSensor(
                    $sensors,
                    $index,
                    $interfaceName,
                    'ifOperStatus',
                    'oper_status',
                    self::IF_OPER_STATUS . '.' . $index,
                    $operStatus,
                    'status',
                    $metadata + [
                        'source' => 'IF-MIB::ifOperStatus',
                        'status_label' => self::OPER_STATUS[$operStatus] ?? 'unknown',
                    ],
                    $operStatus === 1 ? 'ok' : 'nonoperational',
                );
            }

            // 2. Admin Status
            $adminStatus = SnmpValueHelper::integer($ifAdminStatuses[$index] ?? null);
            if ($adminStatus !== null) {
                $this->appendNumericSensor(
                    $sensors,
                    $index,
                    $interfaceName,
                    'ifAdminStatus',
                    'admin_status',
                    self::IF_ADMIN_STATUS . '.' . $index,
                    $adminStatus,
                    'status',
                    $metadata + [
                        'source' => 'IF-MIB::ifAdminStatus',
                        'status_label' => self::ADMIN_STATUS[$adminStatus] ?? 'unknown',
                    ],
                    $adminStatus === 1 ? 'ok' : 'disabled',
                );
            }

            // 3. Speed / Bandwidth
            $highSpeed = SnmpValueHelper::integer($ifHighSpeeds[$index] ?? null);
            $speed = SnmpValueHelper::integer($ifSpeeds[$index] ?? null);
            if ($highSpeed !== null && $highSpeed > 0) {
                $this->appendNumericSensor(
                    $sensors,
                    $index,
                    $interfaceName,
                    'ifHighSpeed',
                    'bandwidth',
                    self::IF_HIGH_SPEED . '.' . $index,
                    $highSpeed,
                    'Mbps',
                    $metadata + ['source' => 'IF-MIB::ifHighSpeed'],
                    'ok',
                );
            } elseif ($speed !== null && $speed > 0) {
                $this->appendNumericSensor(
                    $sensors,
                    $index,
                    $interfaceName,
                    'ifSpeed',
                    'bandwidth',
                    self::IF_SPEED . '.' . $index,
                    $speed,
                    'bps',
                    $metadata + ['source' => 'IF-MIB::ifSpeed'],
                    'ok',
                );
            }

            // 4. MTU
            $mtu = SnmpValueHelper::integer($ifMtus[$index] ?? null);
            if ($mtu !== null && $mtu > 0) {
                $this->appendNumericSensor(
                    $sensors,
                    $index,
                    $interfaceName,
                    'ifMtu',
                    'mtu',
                    self::IF_MTU . '.' . $index,
                    $mtu,
                    'bytes',
                    $metadata + ['source' => 'IF-MIB::ifMtu'],
                    'ok',
                );
            }
        }

        return $sensors;
    }

    /**
     * @param array<string, string> ...$tables
     * @return list<int|string>
     */
    private function interfaceIndexes(array ...$tables): array
    {
        $indexes = [];

        foreach ($tables as $table) {
            foreach (array_keys($table) as $index) {
                if ($index !== '') {
                    $indexes[(string) $index] = true;
                }
            }
        }

        $indexes = array_keys($indexes);
        usort(
            $indexes,
            static fn (int|string $left, int|string $right): int => (int) $left <=> (int) $right ?: strcmp((string) $left, (string) $right),
        );

        return $indexes;
    }

    private function interfaceName(int|string $index, mixed $ifName, mixed $ifDescription, mixed $ifAlias): string
    {
        foreach ([$ifName, $ifDescription, $ifAlias] as $candidate) {
            $name = $this->cleanText($candidate);

            if ($name !== '' && $name !== '0') {
                return $this->formatter->normalizeInterfaceName($name);
            }
        }

        return 'ifIndex ' . $index;
    }

    private function ifTypeLabel(?int $ifType): string
    {
        return $ifType === null ? 'unknown' : (self::IF_TYPES[$ifType] ?? 'type-' . $ifType);
    }

    /**
     * @param list<array<string, mixed>> $sensors
     * @param array<string, mixed> $metadata
     */
    private function appendNumericSensor(
        array &$sensors,
        int|string $index,
        string $interfaceName,
        string $metric,
        string $sensorType,
        string $oid,
        mixed $value,
        string $unit,
        array $metadata,
        string $status = 'ok',
    ): void {
        $indexInt = (int) $index;
        $numeric = SnmpValueHelper::numeric($value);

        if ($numeric === null) {
            return;
        }

        $sensor = [
            'sensor_class' => 'interface',
            'sensor_name' => StringHelper::safeModuleName($this->formatter->interfaceMetric($interfaceName, $metric, $unit)),
            'sensor_type' => $sensorType,
            'interface_index' => $indexInt,
            'interface_name' => $interfaceName,
            'entity_index' => $indexInt,
            'oid' => $oid,
            'raw_value' => (string) $numeric,
            'unit' => $unit,
            'scale' => 'units',
            'precision' => 0,
            'status' => $status,
            'metadata' => $metadata,
        ];

        $normalized = $this->normalizer->normalize($sensor);

        if ($normalized !== null) {
            $sensors[] = $normalized;
        }
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
