<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Snmp\SnmpHelper;
use SnmpBridge\Services\SnmpNamingService;
use SnmpBridge\Helpers\StringHelper;

final class OpticalDomDiscoveryModule implements DiscoveryModuleInterface
{
    private const TYPE_MAP = [
        '14' => 'dBm',
        '15' => 'dB',
    ];

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SnmpNamingService $namingService = new SnmpNamingService(),
    ) {
    }

    public function name(): string
    {
        return 'optical_dom';
    }

    public function supports(DiscoveryContext $context): bool
    {
        return $context->capabilities->supportsOpticalDom;
    }

    public function discover(DiscoveryContext $context): array
    {
        $values = $context->walker->walkIndexed(SnmpHelper::ENTITY_SENSOR_VALUE);
        $types = $context->walker->walkIndexed(SnmpHelper::ENTITY_SENSOR_TYPE);
        $scales = $context->walker->walkIndexed(SnmpHelper::ENTITY_SENSOR_SCALE);
        $precision = $context->walker->walkIndexed(SnmpHelper::ENTITY_SENSOR_PRECISION);
        $status = $context->walker->walkIndexed(SnmpHelper::ENTITY_SENSOR_STATUS);
        $units = $context->walker->walkIndexed(SnmpHelper::ENTITY_SENSOR_UNITS_DISPLAY);
        $labels = $context->walker->walkIndexed(SnmpHelper::ENT_PHYSICAL_NAME)
            + $context->walker->walkIndexed(SnmpHelper::ENT_PHYSICAL_DESCR);
        $sensors = [];

        foreach ($values as $index => $value) {
            $label = trim((string) ($labels[$index] ?? 'Entity ' . $index));
            $type = $this->sensorType((string) ($types[$index] ?? ''));
            $unit = trim((string) ($units[$index] ?? $type));

            if (!$this->looksOptical($label, $type, $unit)) {
                continue;
            }

            $interfaceName = $context->vendor->entityMapper()->resolveInterfaceName(
                $index,
                $context->entityMap,
            );

            $direction = $this->directionFromOpticalLabel($label, $type);

            $sensor = [
                'sensor_class' => 'optical_dom',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->namingService->formatOpticalDomSensorName(
                        $interfaceName,
                        $direction,
                        $unit,
                        (string) $index,
                        $label,
                    ),
                ),
                'sensor_type' => $type,
                'interface_index' => $context->vendor->entityMapper()->resolveIfIndex($index, $context->entityMap),
                'interface_name' => $interfaceName,
                'entity_index' => (int) $index,
                'oid' => SnmpHelper::ENTITY_SENSOR_VALUE . '.' . $index,
                'raw_value' => $value,
                'unit' => $this->namingService->normalizeUnit($unit, $type),
                'scale' => $scales[$index] ?? '9',
                'precision' => $precision[$index] ?? 0,
                'status' => $this->statusLabel($status[$index] ?? null),
                'metadata' => [
                    'entity_label' => $label,
                    'source' => 'ENTITY-SENSOR-MIB',
                ],
            ];

            $normalized = $this->normalizer->normalize($sensor);

            if ($normalized !== null) {
                $sensors[] = $normalized;
            }
        }

        foreach ($this->discoverVendorOids($context) as $sensor) {
            $sensors[] = $sensor;
        }

        return $sensors;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discoverVendorOids(DiscoveryContext $context): array
    {
        $definitions = $context->vendor->discoveryOids()['optical_dom'] ?? [];
        $sensors = [];

        foreach ($definitions as $definition) {
            $oid = (string) $definition['oid'];

            foreach ($context->walker->walkIndexed($oid) as $index => $value) {
                $sensorDefinition = $this->sensorDefinitionForIndex($definition, (string) $index);

                if ($sensorDefinition === null) {
                    continue;
                }

                $interfaceIndex = $this->interfaceIndex($sensorDefinition, (string) $index, $context);
                $interfaceName = $this->interfaceName($sensorDefinition, (string) $index, $interfaceIndex, $context);

                $sensor = [
                    'sensor_class' => 'optical_dom',
                    'sensor_name' => StringHelper::safeModuleName(
                        $this->vendorSensorName($sensorDefinition, (string) $index, $interfaceName),
                    ),
                    'sensor_type' => (string) ($sensorDefinition['sensor_type'] ?? 'optical'),
                    'interface_index' => $interfaceIndex,
                    'interface_name' => $interfaceName,
                    'entity_index' => null,
                    'oid' => rtrim($oid, '.') . '.' . $index,
                    'raw_value' => $value,
                    'unit' => $this->namingService->normalizeUnit(
                        (string) ($sensorDefinition['unit'] ?? ''),
                        (string) ($sensorDefinition['sensor_type'] ?? ''),
                    ),
                    'scale' => $sensorDefinition['scale'] ?? 'units',
                    'precision' => $sensorDefinition['precision'] ?? 0,
                    'offset' => $sensorDefinition['offset'] ?? 0,
                    'status' => 'ok',
                    'metadata' => [
                        'source' => (string) ($sensorDefinition['source'] ?? ($context->vendor->name() . ' vendor optical MIB')),
                        'vendor_oid_index' => (string) $index,
                        'index_strategy' => (string) ($sensorDefinition['index_strategy'] ?? 'none'),
                    ],
                ];

                if (isset($sensorDefinition['parameter_type'])) {
                    $sensor['metadata']['parameter_type'] = (string) $sensorDefinition['parameter_type'];
                }

                $normalized = $this->normalizer->normalize($sensor);

                if ($normalized !== null) {
                    $sensors[] = $normalized;
                }
            }
        }

        return $sensors;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>|null
     */
    private function sensorDefinitionForIndex(array $definition, string $index): ?array
    {
        if (!isset($definition['parameter_map']) || !is_array($definition['parameter_map'])) {
            return $definition;
        }

        $parts = $this->indexParts($index);
        $parameterType = end($parts);
        $parameterDefinition = $definition['parameter_map'][(string) $parameterType] ?? null;

        if (!is_array($parameterDefinition)) {
            return null;
        }

        unset($definition['parameter_map']);

        return array_replace($definition, $parameterDefinition, [
            'parameter_type' => (string) $parameterType,
        ]);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function interfaceIndex(array $definition, string $index, DiscoveryContext $context): ?int
    {
        $strategy = (string) ($definition['index_strategy'] ?? 'none');
        $parts = $this->indexParts($index);

        $candidate = match ($strategy) {
            'ifindex' => count($parts) === 1 ? $parts[0] : null,
            'first_ifindex', 'parameter_last' => $parts[0] ?? null,
            'entity_to_ifindex' => $context->vendor->entityMapper()->resolveIfIndex($index, $context->entityMap),
            default => null,
        };

        if (is_int($candidate)) {
            return $candidate;
        }

        return $candidate !== null && ctype_digit((string) $candidate) ? (int) $candidate : null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function interfaceName(array $definition, string $index, ?int $interfaceIndex, DiscoveryContext $context): ?string
    {
        if (($definition['index_strategy'] ?? '') === 'entity_to_ifindex') {
            $entityName = $context->vendor->entityMapper()->resolveInterfaceName($index, $context->entityMap);

            if ($entityName !== null) {
                return $entityName;
            }
        }

        return $interfaceIndex !== null
            ? $context->vendor->entityMapper()->resolveInterfaceName($interfaceIndex, $context->entityMap)
            : null;
    }

    /**
     * @return list<string>
     */
    private function indexParts(string $index): array
    {
        return array_values(array_filter(explode('.', trim($index, '.')), static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function vendorSensorName(array $definition, string $index, ?string $interfaceName): string
    {
        return $this->namingService->formatOpticalDomSensorName(
            $interfaceName,
            (string) ($definition['sensor_type'] ?? $definition['name'] ?? 'Optical Sensor'),
            (string) ($definition['unit'] ?? 'dBm'),
            $index,
            $interfaceName === null
                ? trim((string) ($definition['name'] ?? 'Optical Sensor') . ' ' . $index)
                : (string) ($definition['name'] ?? ''),
        );
    }

    private function directionFromOpticalLabel(string $label, string $type): string
    {
        $l = strtolower($label);

        if (str_contains($l, 'rx') || str_contains($l, 'receive') || str_contains($l, 'receive power')) {
            return 'RX';
        }
        if (str_contains($l, 'tx') || str_contains($l, 'transmit') || str_contains($l, 'transmit power')) {
            return 'TX';
        }

        // fallback: if type indicates power-like reading, keep original label-ish direction
        $t = strtolower($type);
        if (str_contains($t, 'dBm') || str_contains($t, 'db')) {
            return 'Power';
        }

        return $label !== '' ? $label : $type;
    }

    private function looksOptical(string $label, string $type, string $unit): bool
    {
        if (in_array($type, ['dBm', 'dB'], true) || str_contains(strtolower($unit), 'dbm')) {
            return true;
        }

        return StringHelper::containsAny($label, [
            'rx power',
            'tx power',
            'receive power',
            'transmit power',
            'optical',
            'laser',
            'bias',
            'sfp',
            'xfp',
            'qsfp',
            'dom',
        ]);
    }

    private function sensorType(string $type): string
    {
        return self::TYPE_MAP[trim($type)] ?? trim($type);
    }

    private function nameForSensor(string $label, string $type, string $index): string
    {
        $name = $label !== '' ? $label : 'Optical Sensor ' . $index;

        return str_contains(strtolower($name), strtolower($type)) ? $name : trim($name . ' ' . $type);
    }

    private function statusLabel(mixed $status): string
    {
        return match ((string) $status) {
            '1' => 'ok',
            '2' => 'unavailable',
            '3' => 'nonoperational',
            default => 'unknown',
        };
    }
}
