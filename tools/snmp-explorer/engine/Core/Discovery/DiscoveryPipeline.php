<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Discovery;

use Throwable;
use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Core\Snmp\OidTranslator;

final readonly class DiscoveryPipeline
{
    private const array SAFE_MODULES = ['printer', 'inventory', 'system_metrics', 'cpu_usage', 'memory_usage', 'dahua_discovery'];

    /**
     * @param list<DiscoveryModuleInterface> $modules
     * @param array<string, mixed> $options
     */
    public function __construct(
        private CapabilityResolver $capabilityResolver,
        private array $modules,
        private array $options = [],
        private ?OidTranslator $oidTranslator = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function discover(DiscoveryContext $context): array
    {
        if ($context->capabilities->requiresEntityMapping) {
            $context = $context->withEntityMap(
                $context->vendor->entityMapper()->buildMap($context->walker),
            );
        }

        $sensors = [];
        $maxSensors = $this->maxSensors($context);
        $deadline = $this->deadline($context);
        $translateMaxSensors = $this->translationLimit($context);
        $limitReached = false;
        $translationLimitLogged = false;

        foreach ($this->configuredModules($context) as $module) {
            $startedAt = microtime(true);

            if ($this->deadlineReached($deadline)) {
                $limitReached = true;
                error_log(sprintf(
                    'Discovery stopped before module %s for %s because the scan deadline was reached',
                    $module->name(),
                    (string) ($context->device['ip_address'] ?? 'unknown'),
                ));
                break;
            }

            if (count($sensors) >= $maxSensors) {
                $limitReached = true;
                break;
            }

            if ($this->shouldSkipForVendor($context, $module)) {
                continue;
            }

            try {
                foreach ($module->discover($context) as $sensor) {
                    if (count($sensors) >= $maxSensors || $this->deadlineReached($deadline)) {
                        $limitReached = true;
                        break;
                    }

                    $normalizedSensor = $this->normalizeSensorShape($sensor, $module);

                    if ($normalizedSensor === null) {
                        error_log(sprintf(
                            'Discovery module %s returned an invalid sensor for %s',
                            $module->name(),
                            (string) ($context->device['ip_address'] ?? 'unknown'),
                        ));
                        continue;
                    }

                    if (count($sensors) < $translateMaxSensors) {
                        $normalizedSensor = $this->enrichOidTranslation($normalizedSensor);
                    } elseif (!$translationLimitLogged && $this->oidTranslator instanceof \SnmpBridge\Core\Snmp\OidTranslator) {
                        $translationLimitLogged = true;
                        error_log(sprintf(
                            'OID translation skipped after %d sensors for %s to keep scan runtime bounded',
                            $translateMaxSensors,
                            (string) ($context->device['ip_address'] ?? 'unknown'),
                        ));
                    }

                    $sensors[] = $normalizedSensor + [
                        'vendor' => $context->vendor->name(),
                        'ip_address' => $context->device['ip_address'],
                    ];
                }
            } catch (Throwable $throwable) {
                error_log(sprintf(
                    'Discovery module %s failed for %s: %s',
                    $module->name(),
                    (string) ($context->device['ip_address'] ?? 'unknown'),
                    $throwable->getMessage(),
                ));
            } finally {
                if ((bool) ($this->options['log_module_timings'] ?? false)) {
                    error_log(sprintf(
                        'Discovery module %s finished for %s in %.3fs',
                        $module->name(),
                        (string) ($context->device['ip_address'] ?? 'unknown'),
                        microtime(true) - $startedAt,
                    ));
                }
            }

            if ($limitReached) {
                error_log(sprintf(
                    'Discovery stopped after module %s for %s: sensors=%d, max=%d, deadline_reached=%s',
                    $module->name(),
                    (string) ($context->device['ip_address'] ?? 'unknown'),
                    count($sensors),
                    $maxSensors,
                    $this->deadlineReached($deadline) ? 'yes' : 'no',
                ));
                break;
            }
        }

        return $sensors;
    }

    private function maxSensors(DiscoveryContext $context): int
    {
        return max(1, (int) ($context->snmpConfig['scan_max_sensors'] ?? 10000));
    }

    private function translationLimit(DiscoveryContext $context): int
    {
        return max(0, (int) ($context->snmpConfig['translate_max_sensors'] ?? 1500));
    }

    private function deadline(DiscoveryContext $context): ?float
    {
        $deadline = $context->snmpConfig['scan_deadline'] ?? null;

        return is_numeric($deadline) ? (float) $deadline : null;
    }

    private function deadlineReached(?float $deadline): bool
    {
        return $deadline !== null && microtime(true) >= $deadline;
    }

    /**
     * @return list<DiscoveryModuleInterface>
     */
    private function configuredModules(DiscoveryContext $context): array
    {
        $enabled = $this->configuredModuleNames($context);
        $disabled = $this->moduleList('disabled_modules');

        if ($this->isSafeOnlyVendor($context)) {
            // Fragile devices crash on bulk walks. We intersect the chosen profile's modules
            // with a list of known safe modules so we don't scan anything dangerous,
            // while still respecting what profile the user actually chose.
            $enabled = array_values(array_intersect($enabled, self::SAFE_MODULES));
        }

        $enabled = $this->moduleNameSet($enabled);
        $disabled = $this->moduleNameSet($disabled);
        $candidates = [];

        foreach ($this->modules as $module) {
            $name = $module->name();
            $key = $this->moduleKey($name);

            if ($enabled !== [] && !isset($enabled[$key])) {
                continue;
            }

            if (isset($disabled[$key])) {
                continue;
            }

            $candidates[] = $module;
        }

        return $this->capabilityResolver->resolve($context, $candidates);
    }

    /**
     * @return list<string>
     */
    private function configuredModuleNames(DiscoveryContext $context): array
    {
        $enabled = $this->moduleList('enabled_modules');

        if ($enabled !== []) {
            return $enabled;
        }

        $profiles = is_array($this->options['profiles'] ?? null) ? $this->options['profiles'] : [];
        $profileName = $this->profileName($context, $profiles);

        return array_values(array_filter(
            array_map(strval(...), $profiles[$profileName] ?? []),
            static fn (string $name): bool => trim($name) !== '',
        ));
    }

    /**
     * @param array<string, mixed> $profiles
     */
    private function profileName(DiscoveryContext $context, array $profiles): string
    {
        $requested = trim((string) ($context->snmpConfig['discovery_profile'] ?? ''));
        $fallback = trim((string) ($this->options['profile'] ?? 'provisioning')) ?: 'provisioning';

        foreach ([$requested, $fallback, 'provisioning'] as $candidate) {
            $candidate = strtolower(trim($candidate));

            if ($candidate !== '' && isset($profiles[$candidate])) {
                return $candidate;
            }
        }

        return 'provisioning';
    }

    /**
     * @return list<string>
     */
    private function moduleList(string $key): array
    {
        $value = $this->options[$key] ?? [];

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $name): string => trim((string) $name), $value),
            static fn (string $name): bool => $name !== '',
        )));
    }

    private function shouldSkipForVendor(DiscoveryContext $context, DiscoveryModuleInterface $module): bool
    {
        return $this->isSafeOnlyVendor($context)
            && !isset($this->moduleNameSet(self::SAFE_MODULES)[$this->moduleKey($module->name())]);
    }

    private function isSafeOnlyVendor(DiscoveryContext $context): bool
    {
        $vendor = strtolower($context->vendor->name());
        return in_array($vendor, ['epson', 'dahua']);
    }

    /**
     * @param list<string> $names
     * @return array<string, true>
     */
    private function moduleNameSet(array $names): array
    {
        $set = [];

        foreach ($names as $name) {
            $key = $this->moduleKey($name);

            if ($key !== '') {
                $set[$key] = true;
            }
        }

        return $set;
    }

    private function moduleKey(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/(?<!^)([A-Z])/', '_$1', $name) ?? $name;
        $name = preg_replace('/[_\-\s]+/', '_', $name) ?? $name;

        return strtolower(trim($name, '_'));
    }

    /**
     * @param array<string, mixed> $sensor
     * @return array<string, mixed>|null
     */
    private function normalizeSensorShape(array $sensor, DiscoveryModuleInterface $module): ?array
    {
        if (isset($sensor['sensor_class'], $sensor['sensor_name'], $sensor['oid'])) {
            return $sensor + [
                'sensor_type' => $sensor['sensor_type'] ?? null,
                'raw_value' => $sensor['raw_value'] ?? null,
                'normalized_value' => $sensor['normalized_value'] ?? null,
                'unit' => $sensor['unit'] ?? '',
                'status' => $sensor['status'] ?? 'ok',
                'metadata' => $sensor['metadata'] ?? [],
            ];
        }

        if (!isset($sensor['oid']) || (!isset($sensor['name']) && !isset($sensor['type']))) {
            return null;
        }

        $rawValue = $sensor['raw_value'] ?? $sensor['value'] ?? null;
        $normalizedValue = $sensor['normalized_value'] ?? (is_numeric($rawValue) ? (float) $rawValue : null);
        $sensorType = (string) ($sensor['sensor_type'] ?? $sensor['type'] ?? 'generic');
        $sensorClass = (string) ($sensor['sensor_class'] ?? $sensor['class'] ?? $this->classFromType($sensorType));
        $sensorName = (string) ($sensor['sensor_name'] ?? $sensor['name'] ?? $sensorType);

        return [
            'sensor_class' => $sensorClass !== '' ? $sensorClass : 'misc',
            'sensor_name' => $sensorName !== '' ? $sensorName : $module->name(),
            'sensor_type' => $sensorType,
            'interface_index' => $sensor['interface_index'] ?? null,
            'interface_name' => $sensor['interface_name'] ?? $sensor['group'] ?? $this->interfaceFromName($sensorName),
            'entity_index' => $sensor['entity_index'] ?? null,
            'oid' => (string) $sensor['oid'],
            'raw_value' => $rawValue === null ? null : (string) $rawValue,
            'normalized_value' => $normalizedValue,
            'unit' => $sensor['unit'] ?? '',
            'scale' => $sensor['scale'] ?? null,
            'precision' => $sensor['precision'] ?? null,
            'status' => $sensor['status'] ?? 'ok',
            'metadata' => [
                'discovery_module' => $module->name(),
                'legacy_shape' => true,
                'description' => $sensor['description'] ?? null,
            ],
        ];
    }

    private function classFromType(string $type): string
    {
        $type = strtolower($type);

        return match (true) {
            str_contains($type, 'interface') => 'interface',
            str_contains($type, 'cpu') => 'processor',
            str_contains($type, 'memory') => 'memory',
            str_contains($type, 'temperature') || str_contains($type, 'thermal') => 'temperature',
            str_contains($type, 'voltage') => 'voltage',
            str_contains($type, 'fan') => 'fan',
            str_contains($type, 'power') => 'power',
            default => 'misc',
        };
    }

    private function interfaceFromName(string $name): ?string
    {
        if (preg_match('/^(.+?)\s+-\s+/', $name, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $sensor
     * @return array<string, mixed>
     */
    private function enrichOidTranslation(array $sensor): array
    {
        if (!$this->oidTranslator instanceof \SnmpBridge\Core\Snmp\OidTranslator || empty($sensor['oid'])) {
            return $sensor;
        }

        $translation = $this->oidTranslator->translate((string) $sensor['oid']);
        $metadata = $this->sensorMetadata($sensor);
        $metadata['oid_translation'] = $this->compactArray([
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
        ]);

        $sensorName = trim((string) ($sensor['sensor_name'] ?? ''));

        if ($this->looksAutoOidName($sensorName) && !empty($translation['display_name'])) {
            $sensor['sensor_name'] = $this->translatedSensorName($translation);
        }

        $suggestedUnit = (string) ($translation['suggested_unit'] ?? '');
        $unit = trim((string) ($sensor['unit'] ?? ''));

        if ($suggestedUnit !== '' && ($unit === '' || strtolower($unit) === 'unknown')) {
            $sensor['unit'] = $suggestedUnit;
        }

        $suggestedClass = (string) ($translation['suggested_class'] ?? '');
        $sensorClass = strtolower(trim((string) ($sensor['sensor_class'] ?? '')));

        if ($suggestedClass !== '' && $suggestedClass !== 'misc' && in_array($sensorClass, ['', 'misc', 'generic'], true)) {
            $sensor['sensor_class'] = $suggestedClass;
        }

        $suggestedType = (string) ($translation['suggested_type'] ?? '');
        $sensorType = strtolower(trim((string) ($sensor['sensor_type'] ?? '')));

        if ($suggestedType !== '' && $suggestedType !== 'numeric' && in_array($sensorType, ['', 'numeric', 'generic'], true)) {
            $sensor['sensor_type'] = $suggestedType;
        }

        $sensor['metadata'] = $metadata;
        unset($sensor['metadata_json']);

        return $sensor;
    }

    /**
     * @param array<string, mixed> $translation
     */
    private function translatedSensorName(array $translation): string
    {
        $name = trim((string) ($translation['display_name'] ?? ''));
        $index = trim((string) ($translation['index'] ?? ''));

        if ($index === '' || !($translation['translated'] ?? false)) {
            return $name;
        }

        return trim($name . ' #' . str_replace('.', '/', $index));
    }

    private function looksAutoOidName(string $name): bool
    {
        return $name === ''
            || preg_match('/^(?:OID-|SNMP OID|Enterprise \d+ OID)/i', $name) === 1;
    }

    /**
     * @param array<string, mixed> $sensor
     * @return array<string, mixed>
     */
    private function sensorMetadata(array $sensor): array
    {
        $metadata = [];

        if (isset($sensor['metadata_json']) && is_string($sensor['metadata_json']) && $sensor['metadata_json'] !== '') {
            $decoded = json_decode($sensor['metadata_json'], true);

            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        if (isset($sensor['metadata']) && is_array($sensor['metadata'])) {
            return array_replace_recursive($metadata, $sensor['metadata']);
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function compactArray(array $values): array
    {
        return array_filter(
            $values,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }
}
