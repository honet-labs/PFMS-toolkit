<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Snmp\SnmpHelper;
use SnmpBridge\Helpers\StringHelper;
use SnmpBridge\Services\SnmpNamingService;

final readonly class EnvironmentalDiscoveryModule implements DiscoveryModuleInterface
{
    private const array TYPE_MAP = [
        '3' => 'voltsAC',
        '4' => 'voltsDC',
        '5' => 'amperes',
        '6' => 'watts',
        '8' => 'celsius',
        '9' => 'percentRH',
        '10' => 'rpm',
    ];

    public function __construct(
        private NormalizerInterface $normalizer,
        private SnmpNamingService $namingService = new SnmpNamingService(),
    ) {
    }

    public function name(): string
    {
        return 'environmental';
    }

    public function supports(DiscoveryContext $context): bool
    {
        return $context->capabilities->supportsEnvironment;
    }

    public function discover(DiscoveryContext $context): array
    {
        $values = $context->walker->walkIndexed(SnmpHelper::ENTITY_SENSOR_VALUE);
        $types = $context->walker->walkIndexed(SnmpHelper::ENTITY_SENSOR_TYPE);
        $scales = $context->walker->walkIndexed(SnmpHelper::ENTITY_SENSOR_SCALE);
        $precision = $context->walker->walkIndexed(SnmpHelper::ENTITY_SENSOR_PRECISION);
        $status = $context->walker->walkIndexed(SnmpHelper::ENTITY_SENSOR_STATUS);
        $units = $context->walker->walkIndexed(SnmpHelper::ENTITY_SENSOR_UNITS_DISPLAY);
        $names = $context->walker->walkIndexed(SnmpHelper::ENT_PHYSICAL_NAME);
        $descrs = $context->walker->walkIndexed(SnmpHelper::ENT_PHYSICAL_DESCR);
        $containedIn = $context->walker->walkIndexed(SnmpHelper::ENT_PHYSICAL_CONTAINED_IN);
        $parentRelPos = $context->walker->walkIndexed(SnmpHelper::ENT_PHYSICAL_PARENT_REL_POS);
        $physicalClasses = $context->walker->walkIndexed(SnmpHelper::ENT_PHYSICAL_CLASS);
        $sensors = [];

        $classNames = [
            '3' => 'Chassis',
            '4' => 'Backplane',
            '5' => 'Container',
            '6' => 'Power Supply',
            '7' => 'Fan Tray',
            '8' => 'Sensor',
            '9' => 'Module',
            '10' => 'Port',
            '11' => 'Stack',
            '12' => 'CPU',
        ];

        $resolveParentHierarchy = static function (
            string|int $targetIndex,
            array $containedInMap,
            array $nameMap,
            array $descrMap,
            array $relPosMap,
            array $classMap,
            array $classNames
        ): string {
            $curr = (string) $targetIndex;
            $visited = [$curr => true];
            $parentLabels = [];
            $depth = 0;

            while (isset($containedInMap[$curr]) && $depth < 5) {
                $depth++;
                $parentId = trim((string) $containedInMap[$curr]);
                if ($parentId === '' || $parentId === '0' || isset($visited[$parentId])) {
                    break;
                }
                $visited[$parentId] = true;

                $pName = trim((string) ($nameMap[$parentId] ?? ''));
                $pDescr = trim((string) ($descrMap[$parentId] ?? ''));
                $pLabel = $pName !== '' ? $pName : $pDescr;

                $pClass = trim((string) ($classMap[$parentId] ?? ''));
                if ($pLabel === '' && isset($classNames[$pClass])) {
                    $pLabel = $classNames[$pClass];
                }

                if ($pLabel !== '') {
                    $relPos = trim((string) ($relPosMap[$parentId] ?? ''));
                    if ($relPos !== '' && ctype_digit($relPos) && (int) $relPos > 0 && preg_match('/\d/', $pLabel) === 0) {
                        $pLabel .= ' ' . $relPos;
                    }

                    $isRootLike = preg_match('/^(chassis|system|device|rack|box|shelf|root)$/i', $pLabel) === 1;
                    if (!$isRootLike || empty($parentLabels)) {
                        $parentLabels[] = $pLabel;
                    }
                }

                $curr = $parentId;
            }

            return $parentLabels[0] ?? '';
        };

        foreach ($values as $index => $value) {
            $nameLabel = trim((string) ($names[$index] ?? ''));
            $descrLabel = trim((string) ($descrs[$index] ?? ''));

            if ($nameLabel !== '' && $descrLabel !== '') {
                $nameHasDigits = preg_match('/\d/', $nameLabel) === 1;
                $descrHasDigits = preg_match('/\d/', $descrLabel) === 1;
                if ($descrHasDigits && !$nameHasDigits) {
                    $label = $descrLabel;
                } elseif ($nameHasDigits && !$descrHasDigits) {
                    $label = $nameLabel;
                } else {
                    $label = strlen($descrLabel) > strlen($nameLabel) ? $descrLabel : $nameLabel;
                }
            } else {
                $label = $nameLabel !== '' ? $nameLabel : ($descrLabel !== '' ? $descrLabel : '');
            }

            $type = $this->sensorType((string) ($types[$index] ?? ''));
            $unit = trim((string) ($units[$index] ?? $type));

            if (!$this->looksEnvironmental($label !== '' ? $label : 'Entity ' . $index, $type, $unit)) {
                continue;
            }

            $parentLabel = $resolveParentHierarchy(
                $index,
                $containedIn,
                $names,
                $descrs,
                $parentRelPos,
                $physicalClasses,
                $classNames
            );

            $baseLabel = $label !== '' ? $label : $type;
            if ($parentLabel !== '') {
                if (!str_contains(strtolower($baseLabel), strtolower($parentLabel))) {
                    $effectiveLabel = $parentLabel . ' - ' . $baseLabel;
                } else {
                    $effectiveLabel = $baseLabel;
                }
            } else {
                $effectiveLabel = $baseLabel;
                if (preg_match('/\d/', $effectiveLabel) === 0) {
                    $relPos = trim((string) ($parentRelPos[$index] ?? ''));
                    if ($relPos !== '' && ctype_digit($relPos) && (int) $relPos > 0) {
                        $effectiveLabel .= ' #' . $relPos;
                    }
                }
            }

            $sensor = [
                'sensor_class' => 'environmental',
                'sensor_name' => StringHelper::safeModuleName(
                    $this->namingService->formatEnvironmentalSensorName(
                        $effectiveLabel,
                        $type,
                        $unit,
                        (string) $index,
                        $context->vendor->entityMapper()->resolveInterfaceName($index, $context->entityMap),
                    ),
                ),
                'sensor_type' => $type,
                'interface_index' => $context->vendor->entityMapper()->resolveIfIndex($index, $context->entityMap),
                'interface_name' => $context->vendor->entityMapper()->resolveInterfaceName($index, $context->entityMap),
                'entity_index' => (int) $index,
                'oid' => SnmpHelper::ENTITY_SENSOR_VALUE . '.' . $index,
                'raw_value' => $value,
                'unit' => $this->namingService->normalizeUnit($unit, $type),
                'scale' => $scales[$index] ?? '9',
                'precision' => $precision[$index] ?? 0,
                'status' => $this->statusLabel($status[$index] ?? null),
                'metadata' => [
                    'entity_label' => $label,
                    'parent_entity' => $parentLabel !== '' ? $parentLabel : null,
                    'contained_in' => $containedIn[$index] ?? null,
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
        $definitions = $context->vendor->discoveryOids()['environmental'] ?? [];
        $sensors = [];

        foreach ($definitions as $definition) {
            $oid = (string) $definition['oid'];

            foreach ($context->walker->walkIndexed($oid) as $index => $value) {
                $interfaceIndex = $this->interfaceIndex($definition, (string) $index);
                $interfaceName = $interfaceIndex !== null
                    ? $context->vendor->entityMapper()->resolveInterfaceName($interfaceIndex, $context->entityMap)
                    : null;

                $sensor = [
                    'sensor_class' => 'environmental',
                    'sensor_name' => StringHelper::safeModuleName(
                        $this->vendorSensorName($definition, (string) $index, $interfaceName),
                    ),
                    'sensor_type' => (string) ($definition['sensor_type'] ?? 'environmental'),
                    'interface_index' => $interfaceIndex,
                    'interface_name' => $interfaceName,
                    'entity_index' => null,
                    'oid' => rtrim($oid, '.') . '.' . $index,
                    'raw_value' => $value,
                    'unit' => $this->namingService->normalizeUnit(
                        (string) ($definition['unit'] ?? ''),
                        (string) ($definition['sensor_type'] ?? ''),
                    ),
                    'scale' => $definition['scale'] ?? 'units',
                    'precision' => $definition['precision'] ?? 0,
                    'offset' => $definition['offset'] ?? 0,
                    'status' => 'ok',
                    'metadata' => [
                        'source' => (string) ($definition['source'] ?? ($context->vendor->name() . ' vendor environmental MIB')),
                        'vendor_oid_index' => (string) $index,
                        'index_strategy' => (string) ($definition['index_strategy'] ?? 'none'),
                    ],
                ];

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
     */
    private function interfaceIndex(array $definition, string $index): ?int
    {
        $strategy = (string) ($definition['index_strategy'] ?? 'none');
        $parts = array_values(array_filter(explode('.', trim($index, '.')), static fn (string $part): bool => $part !== ''));

        $candidate = match ($strategy) {
            'ifindex' => count($parts) === 1 ? $parts[0] : null,
            'first_ifindex' => $parts[0] ?? null,
            default => null,
        };

        return $candidate !== null && ctype_digit($candidate) ? (int) $candidate : null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function vendorSensorName(array $definition, string $index, ?string $interfaceName): string
    {
        return $this->namingService->formatEnvironmentalSensorName(
            $interfaceName === null
                ? trim(($definition['name'] ?? 'Environmental Sensor') . ' ' . $index)
                : (string) ($definition['name'] ?? 'Environmental Sensor'),
            (string) ($definition['sensor_type'] ?? 'environmental'),
            (string) ($definition['unit'] ?? ''),
            $index,
            $interfaceName,
        );
    }

    private function looksEnvironmental(string $label, string $type, string $unit): bool
    {
        $combined = strtolower($label . ' ' . $type . ' ' . $unit);

        if (StringHelper::containsAny($combined, ['dbm', 'optical power', 'rx power', 'tx power'])) {
            return false;
        }

        return in_array($type, self::TYPE_MAP, true)
            || StringHelper::containsAny($combined, [
                'temp',
                'celsius',
                'fan',
                'rpm',
                'volt',
                'current',
                'amp',
                'watt',
                'power supply',
                'psu',
                'humidity',
                'percent',
            ]);
    }

    private function sensorType(string $type): string
    {
        return self::TYPE_MAP[trim($type)] ?? trim($type);
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
