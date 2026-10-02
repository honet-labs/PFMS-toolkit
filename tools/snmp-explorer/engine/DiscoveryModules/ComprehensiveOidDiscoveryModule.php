<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Snmp\OidTranslator;
use SnmpBridge\Helpers\SnmpValueHelper;

/**
 * Comprehensive OID Discovery Module
 *
 * Performs aggressive OID scanning to discover metrics not covered by standard modules.
 * Walks common enterprise OID trees to find vendor-specific metrics.
 *
 * Scans:
 * - All standard MIB roots (SNMPv2-MIB, IF-MIB, etc.)
 * - Common vendor OIDs (Cisco, Huawei, Dell, HP, NetApp, etc.)
 * - Enterprise-specific OIDs (1.3.6.1.4.1.*)
 *
 * This module is SLOW but ensures no metrics are missed.
 * Best used with 'all' discovery profile for complete audits.
 */
final readonly class ComprehensiveOidDiscoveryModule implements DiscoveryModuleInterface
{
    // Common MIB roots to scan
    private const array MIB_ROOTS = [
        '1.3.6.1.2.1.25',        // HOST-RESOURCES-MIB
        '1.3.6.1.2.1.31',        // IF-MIB extensions
        '1.3.6.1.2.1.43',        // Printer-MIB
        '1.3.6.1.2.1.47',        // ENTITY-MIB
        '1.3.6.1.2.1.99',        // ENTITY-SENSOR-MIB
        '1.3.6.1.3.1',           // transmission
        '1.3.6.1.4.1.2578',      // Compaq
        '1.3.6.1.4.1.9',         // Cisco
        '1.3.6.1.4.1.2011',      // Huawei
        '1.3.6.1.4.1.3375',      // F5 Networks
        '1.3.6.1.4.1.232',       // HP
        '1.3.6.1.4.1.25623',     // Greenbone/Openvas
        '1.3.6.1.4.1.11',        // HP ProLiant
        '1.3.6.1.4.1.2636',      // Juniper
        '1.3.6.1.4.1.674',       // Dell
        '1.3.6.1.4.1.1588',      // EMC
        '1.3.6.1.4.1.8072',      // Net-SNMP
    ];

    // OIDs that commonly expose important metrics
    private const array COMMON_METRIC_OIDS = [
        // Temperature sensors
        '1.3.6.1.2.1.99.1.1',
        '1.3.6.1.4.1.9.9.13.1.3',      // Cisco temperature
        '1.3.6.1.4.1.2011.2.23.1.9.1', // Huawei temperature
        // Fan status
        '1.3.6.1.2.1.99.1.2',
        '1.3.6.1.4.1.9.9.13.1.4',      // Cisco fan
        // Power supply
        '1.3.6.1.4.1.9.9.13.1.5',      // Cisco power supply
        // Voltage
        '1.3.6.1.2.1.99.1.1.1',
        '1.3.6.1.4.1.232.1.3.1',       // HP voltage
    ];

    public function __construct(
        private NormalizerInterface $normalizer,
        private ?OidTranslator $oidTranslator = null,
    ) {
    }

    public function name(): string
    {
        return 'comprehensive_oid';
    }

    /**
     * Only enable this module explicitly or in 'all' profile
     */
    public function supports(DiscoveryContext $context): bool
    {
        // Always supported, but typically disabled by default
        return true;
    }

    /**
     * Discover metrics by walking common MIB roots
     */
    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            $this->scanCommonMetricOids($context, $sensors);

            // Scan broader MIB roots if enabled (slower, comprehensive)
            if ($this->fullWalkEnabled() && !$this->limitReached($context, $sensors)) {
                $this->scanMibRoots($context, $sensors);
            }
        } catch (\Throwable $e) {
            trigger_error(
                'Comprehensive OID discovery error: ' . $e->getMessage(),
                E_USER_WARNING
            );
        }

        return $sensors;
    }

    /**
     * Scan common metric OIDs that are likely to exist
     *
     * @param list<array<string, mixed>> $sensors
     */
    private function scanCommonMetricOids(DiscoveryContext $context, array &$sensors): void
    {
        foreach (self::COMMON_METRIC_OIDS as $oid) {
            if ($this->limitReached($context, $sensors)) {
                break;
            }

            try {
                $values = $context->walker->walkIndexed($oid);

                foreach ($values as $index => $value) {
                    if ($this->limitReached($context, $sensors)) {
                        break;
                    }
                    if ($value === null) {
                        continue;
                    }
                    if ($value === '') {
                        continue;
                    }

                    $numeric = SnmpValueHelper::numeric($value);

                    if ($numeric !== null) {
                        $sensor = $this->numericSensor($oid, $index, $value, [
                            'oid_root' => $oid,
                            'source' => 'common_metric_oid',
                        ], count($sensors) < $this->translationLimit($context));

                        if ($sensor !== null) {
                            $sensors[] = $sensor;
                        }
                    }
                }
            } catch (\Throwable) {
                // Skip OIDs that don't exist or timeout
                continue;
            }
        }
    }

    /**
     * Scan entire MIB roots for all available metrics
     * WARNING: This is slow and should only be used for diagnostics
     *
     * @param list<array<string, mixed>> $sensors
     */
    private function scanMibRoots(DiscoveryContext $context, array &$sensors): void
    {
        $scannedCount = 0;
        $maxScans = max(1, env_int('DISCOVERY_COMPREHENSIVE_MAX_SCANS', 12));
        $maxValuesPerRoot = max(1, env_int('DISCOVERY_COMPREHENSIVE_MAX_VALUES_PER_ROOT', 10000));

        foreach ($this->mibRoots($context) as $root) {
            if ($scannedCount >= $maxScans || $this->limitReached($context, $sensors)) {
                break;
            }

            try {
                $values = $context->walker->walkIndexedUncached($root);
                $valuesScanned = 0;

                foreach ($values as $index => $value) {
                    if ($valuesScanned >= $maxValuesPerRoot || $this->limitReached($context, $sensors)) {
                        break;
                    }

                    $valuesScanned++;
                    if ($value === null) {
                        continue;
                    }
                    if ($value === '') {
                        continue;
                    }

                    $numeric = SnmpValueHelper::numeric($value);

                    if ($numeric !== null && abs($numeric) < 999999999) {
                        $sensor = $this->numericSensor($root, $index, $value, [
                            'mib_root' => $root,
                            'source' => 'mib_root_walk',
                        ], count($sensors) < $this->translationLimit($context));

                        if ($sensor !== null) {
                            $sensors[] = $sensor;
                        }
                    }
                }

                $scannedCount++;
            } catch (\Throwable) {
                // Skip MIB roots that timeout or don't exist
                continue;
            }
        }
    }

    private function fullWalkEnabled(): bool
    {
        return env_bool('DISCOVERY_COMPREHENSIVE_FULL_WALK', false);
    }

    /**
     * @return list<string>
     */
    private function mibRoots(DiscoveryContext $context): array
    {
        $roots = env_list('DISCOVERY_COMPREHENSIVE_ROOTS', self::MIB_ROOTS);
        $enterpriseOid = trim($context->vendor->enterpriseOid(), '.');

        if ($enterpriseOid !== '') {
            array_unshift($roots, $enterpriseOid);
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (string $root): string => trim($root, '. '), $roots),
            static fn (string $root): bool => $root !== '',
        )));
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>|null
     */
    private function numericSensor(string $root, string $index, mixed $value, array $metadata, bool $translate): ?array
    {
        $fullOid = trim($root, '.');
        $index = trim($index, '.');

        if ($index !== '') {
            $fullOid .= '.' . $index;
        }

        $translation = $translate ? ($this->oidTranslator?->translate($fullOid) ?? []) : [];
        $unit = (string) ($translation['suggested_unit'] ?? '');
        $normalized = $this->normalizer->normalize($value, 'units', 0, $unit);

        if ($normalized === null) {
            return null;
        }

        $displayName = trim((string) ($translation['display_name'] ?? ''));
        $sensorName = $displayName !== '' ? $displayName : 'SNMP OID ' . $fullOid;
        $translationIndex = trim((string) ($translation['index'] ?? ''));

        if ($translationIndex !== '' && ($translation['translated'] ?? false)) {
            $sensorName .= ' #' . str_replace('.', '/', $translationIndex);
        }

        return [
            'sensor_class' => $translation['suggested_class'] ?? 'misc',
            'sensor_name' => $sensorName,
            'sensor_type' => $translation['suggested_type'] ?? 'numeric',
            'oid' => $fullOid,
            'raw_value' => (string) $value,
            'normalized_value' => $normalized['value'],
            'unit' => $normalized['unit'] !== '' ? $normalized['unit'] : $unit,
            'scale' => 'units',
            'precision' => 0,
            'status' => 'ok',
            'metadata' => array_replace($metadata, [
                'discovery_module' => 'ComprehensiveOidDiscoveryModule',
                'category' => 'AUTODISCOVERED',
                'value_type' => 'numeric',
                'oid_translation_skipped' => !$translate,
                'oid_translation' => $this->translationMetadata($translation),
            ]),
        ];
    }

    /**
     * @param list<array<string, mixed>> $sensors
     */
    private function limitReached(DiscoveryContext $context, array $sensors): bool
    {
        if (count($sensors) >= $this->maxSensors($context)) {
            return true;
        }
        return $this->deadlineReached($context);
    }

    private function maxSensors(DiscoveryContext $context): int
    {
        $globalMax = max(1, (int) ($context->snmpConfig['scan_max_sensors'] ?? 10000));
        $configured = env('DISCOVERY_COMPREHENSIVE_MAX_SENSORS');

        if ($configured === null) {
            return $globalMax;
        }

        return max(1, min($globalMax, (int) $configured));
    }

    private function translationLimit(DiscoveryContext $context): int
    {
        return max(0, env_int(
            'DISCOVERY_COMPREHENSIVE_TRANSLATE_LIMIT',
            min(500, (int) ($context->snmpConfig['translate_max_sensors'] ?? 1500)),
        ));
    }

    private function deadlineReached(DiscoveryContext $context): bool
    {
        $deadline = $context->snmpConfig['scan_deadline'] ?? null;

        return is_numeric($deadline) && microtime(true) >= (float) $deadline;
    }

    /**
     * @param array<string, mixed> $translation
     * @return array<string, mixed>
     */
    private function translationMetadata(array $translation): array
    {
        return array_filter(
            [
                'numeric_oid' => $translation['numeric_oid'] ?? null,
                'symbolic_oid' => $translation['symbolic_oid'] ?? null,
                'mib' => $translation['mib'] ?? null,
                'object' => $translation['object'] ?? null,
                'index' => $translation['index'] ?? null,
                'display_name' => $translation['display_name'] ?? null,
                'description' => $translation['description'] ?? null,
                'syntax' => $translation['syntax'] ?? null,
                'units' => $translation['units'] ?? null,
                'translated' => $translation['translated'] ?? false,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }
}
