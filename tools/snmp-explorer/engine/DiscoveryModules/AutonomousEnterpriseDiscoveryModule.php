<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Snmp\OidTranslator;
use SnmpBridge\Helpers\SnmpValueHelper;

/**
 * Autonomous Enterprise Discovery Module.
 * Fully vendor-agnostic: dynamically discovers and translates enterprise OIDs
 * for ANY manufacturer/brand worldwide using loaded/uploaded MIBs and Net-SNMP.
 */
final class AutonomousEnterpriseDiscoveryModule implements DiscoveryModuleInterface
{
    public function __construct(
        private NormalizerInterface $normalizer,
        private ?OidTranslator $oidTranslator = null,
    ) {
    }

    public function name(): string
    {
        return 'autonomous_enterprise';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // Allow disabling via environment variable if desired
        if (env_bool('DISCOVERY_DISABLE_AUTONOMOUS_ENTERPRISE', false)) {
            return false;
        }

        $enterpriseOid = trim($context->vendor->enterpriseOid(), '. ');
        if ($enterpriseOid !== '' && $enterpriseOid !== '1.3.6.1.4.1') {
            return true;
        }

        $sysObj = $context->sysObjectID();
        if ($sysObj !== '' && str_contains($sysObj, '1.3.6.1.4.1.')) {
            return true;
        }

        return false;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];
        $enterpriseRoot = $this->resolveEnterpriseRoot($context);

        if ($enterpriseRoot === '') {
            return [];
        }

        $maxItems = max(1, env_int('DISCOVERY_AUTONOMOUS_MAX_SENSORS', 150));
        $seenOids = [];

        try {
            // Perform bounded walk on the device's private enterprise tree
            $entries = $context->walker->walkIndexedUncached($enterpriseRoot);
            $scannedCount = 0;

            foreach ($entries as $index => $value) {
                if ($scannedCount >= $maxItems || $this->deadlineReached($context)) {
                    break;
                }

                if ($value === null || $value === '') {
                    continue;
                }

                $fullOid = trim($enterpriseRoot, '.');
                $index = trim((string) $index, '.');
                if ($index !== '') {
                    $fullOid .= '.' . $index;
                }

                if (isset($seenOids[$fullOid])) {
                    continue;
                }
                $seenOids[$fullOid] = true;
                $scannedCount++;

                $sensor = $this->createSensor($fullOid, $value, $context);
                if ($sensor !== null) {
                    $sensors[] = $sensor;
                }
            }
        } catch (\Throwable $e) {
            error_log(sprintf('Autonomous enterprise discovery error for %s (%s): %s', 
                $context->vendor->name(), 
                $enterpriseRoot, 
                $e->getMessage()
            ));
        }

        return $sensors;
    }

    private function resolveEnterpriseRoot(DiscoveryContext $context): string
    {
        $enterpriseOid = trim($context->vendor->enterpriseOid(), '. ');
        if ($enterpriseOid !== '') {
            return $enterpriseOid;
        }

        $sysObj = $context->sysObjectID();
        if ($sysObj !== '' && preg_match('/1\.3\.6\.1\.4\.1\.(\d+)/', $sysObj, $matches) === 1) {
            return '1.3.6.1.4.1.' . $matches[1];
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function createSensor(string $fullOid, mixed $value, DiscoveryContext $context): ?array
    {
        $translation = $this->oidTranslator?->translate($fullOid) ?? [];
        $numeric = SnmpValueHelper::numeric($value);
        $displayName = trim((string) ($translation['display_name'] ?? ''));
        $translationIndex = trim((string) ($translation['index'] ?? ''));

        $sensorName = $displayName !== '' ? $displayName : 'Enterprise OID ' . $fullOid;
        if ($translationIndex !== '' && ($translation['translated'] ?? false)) {
            $sensorName .= ' #' . str_replace('.', '/', $translationIndex);
        }

        // Numeric metric (Gauge, Counter, Integer)
        if ($numeric !== null && abs($numeric) < 999999999999) {
            $unit = (string) ($translation['suggested_unit'] ?? '');
            $normalized = $this->normalizer->normalize($value, 'units', 0, $unit);

            return [
                'sensor_class' => $translation['suggested_class'] ?? 'enterprise',
                'sensor_name' => $sensorName,
                'sensor_type' => $translation['suggested_type'] ?? 'numeric',
                'oid' => $fullOid,
                'raw_value' => (string) $value,
                'normalized_value' => $normalized !== null ? $normalized['value'] : (string) $numeric,
                'unit' => $normalized !== null && $normalized['unit'] !== '' ? $normalized['unit'] : $unit,
                'scale' => 'units',
                'precision' => 0,
                'status' => 'ok',
                'metadata' => [
                    'discovery_module' => 'AutonomousEnterpriseDiscoveryModule',
                    'vendor' => $context->vendor->name(),
                    'source' => !empty($translation['mib']) ? ($translation['mib'] . '::' . ($translation['object'] ?? '')) : 'Enterprise Walk',
                    'category' => 'AUTODISCOVERED',
                    'value_type' => 'numeric',
                    'syntax' => $translation['syntax'] ?? 'Gauge32',
                ],
            ];
        }

        // Text / String / IP Address metric
        $strValue = trim((string) $value, " \t\n\r\0\x0B\"");
        if ($strValue === '') {
            return null;
        }

        return [
            'sensor_class' => $translation['suggested_class'] ?? 'enterprise',
            'sensor_name' => $sensorName,
            'sensor_type' => $translation['suggested_type'] ?? 'string',
            'oid' => $fullOid,
            'raw_value' => $strValue,
            'normalized_value' => $strValue,
            'unit' => '',
            'scale' => 'none',
            'precision' => 0,
            'status' => 'ok',
            'metadata' => [
                'discovery_module' => 'AutonomousEnterpriseDiscoveryModule',
                'vendor' => $context->vendor->name(),
                'source' => !empty($translation['mib']) ? ($translation['mib'] . '::' . ($translation['object'] ?? '')) : 'Enterprise Walk',
                'category' => 'AUTODISCOVERED',
                'value_type' => 'string',
                'syntax' => $translation['syntax'] ?? 'OCTET STRING',
            ],
        ];
    }

    private function deadlineReached(DiscoveryContext $context): bool
    {
        $deadline = $context->snmpConfig['scan_deadline'] ?? null;

        return is_numeric($deadline) && microtime(true) >= (float) $deadline;
    }
}
