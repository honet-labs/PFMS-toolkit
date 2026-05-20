<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Helpers\StringHelper;
use SnmpBridge\Services\SnmpNamingService;

final class GponDiscoveryModule implements DiscoveryModuleInterface
{
    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SnmpNamingService $namingService = new SnmpNamingService(),
    ) {
    }

    public function name(): string
    {
        return 'gpon';
    }

    public function supports(DiscoveryContext $context): bool
    {
        return $context->capabilities->supportsGpon
            && isset($context->vendor->discoveryOids()['gpon']);
    }

    public function discover(DiscoveryContext $context): array
    {
        $definitions = $context->vendor->discoveryOids()['gpon'] ?? [];
        $sensors = [];

        foreach ($definitions as $definition) {
            $oid = (string) $definition['oid'];
            $values = $context->walker->walkIndexed($oid);

            foreach ($values as $index => $value) {
                $interfaceIndex = $this->interfaceIndex($definition, (string) $index);
                $interfaceName = $interfaceIndex !== null
                    ? $context->vendor->entityMapper()->resolveInterfaceName($interfaceIndex, $context->entityMap)
                    : null;

                $sensor = [
                    'sensor_class' => 'gpon',
                    'sensor_name' => StringHelper::safeModuleName(
                        $this->sensorName($definition, (string) $index, $interfaceName),
                    ),
                    'sensor_type' => (string) ($definition['sensor_type'] ?? 'gpon'),
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
                        'gpon_index' => $index,
                        'source' => (string) ($definition['source'] ?? ($context->vendor->name() . ' vendor GPON MIB')),
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
    private function sensorName(array $definition, string $index, ?string $interfaceName): string
    {
        return $this->namingService->formatGponSensorName(
            $interfaceName,
            (string) ($definition['name'] ?? 'GPON Sensor'),
            (string) ($definition['sensor_type'] ?? 'gpon'),
            (string) ($definition['unit'] ?? ''),
            $index,
        );
    }
}
