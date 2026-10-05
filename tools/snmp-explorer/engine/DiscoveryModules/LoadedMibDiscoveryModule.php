<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Snmp\OidTranslator;
use SnmpBridge\Helpers\SnmpValueHelper;

/**
 * Loaded MIB Discovery Module
 * 
 * Automatically discovers sensors for ANY device brand by scanning installed
 * and uploaded MIB/TXT files in engine/mibs/ and Pandora attachment/mibs/.
 * Extracts OBJECT-TYPE definitions, translates them to numeric OIDs, and queries
 * the target device for matching tabular and scalar instances.
 */
final class LoadedMibDiscoveryModule implements DiscoveryModuleInterface
{
    /** @var array<string, list<array{name: string, module: string, oid: string, syntax: string, desc: string, file: string}>> */
    private static array $parsedMibCache = [];

    /**
     * @param list<string> $mibDirs
     */
    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly ?OidTranslator $oidTranslator = null,
        private readonly array $mibDirs = [],
    ) {
    }

    public function name(): string
    {
        return 'loaded_mibs';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // Always enabled for any target device when MIBs exist
        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];
        $maxSensors = max(1, (int) ($context->snmpConfig['scan_max_sensors'] ?? 1000));
        $seenOids = [];

        // Identify target device's enterprise number if present
        $sysObj = $context->sysObjectID();
        $deviceEnterprisePen = null;
        if ($sysObj !== '' && preg_match('/1\.3\.6\.1\.4\.1\.(\d+)/', $sysObj, $m)) {
            $deviceEnterprisePen = $m[1];
        }

        $vendorName = strtolower($context->vendor->name());
        $sysDescr = strtolower($context->device['sys_descr'] ?? '');

        // Resolve candidate MIB files across configured MIB directories
        $candidateObjects = $this->getCandidateObjects();

        foreach ($candidateObjects as $obj) {
            if ($this->deadlineReached($context) || count($sensors) >= $maxSensors) {
                break;
            }

            $numericOid = $obj['oid'];
            if ($numericOid === '') {
                continue;
            }

            // Check relevance: is this object relevant to the current device?
            // 1) Object OID matches device's Enterprise PEN (e.g. 1.3.6.1.4.1.2011...)
            // 2) MIB was uploaded to engine/mibs/ (user explicitly provided it for their network)
            // 3) Module name or file name mentions device vendor or device description
            $isDeviceEnterprise = ($deviceEnterprisePen !== null && str_starts_with($numericOid, '1.3.6.1.4.1.' . $deviceEnterprisePen));
            $isLocalToolkitMib = str_contains($obj['file'], 'engine/mibs') || str_contains($obj['file'], 'engine\\mibs');
            $isVendorMatch = ($vendorName !== '' && (stripos($obj['module'], $vendorName) !== false || stripos($obj['file'], $vendorName) !== false))
                || ($sysDescr !== '' && stripos($sysDescr, $obj['module']) !== false);

            if (!$isDeviceEnterprise && !$isLocalToolkitMib && !$isVendorMatch) {
                continue;
            }

            try {
                // First: Try walking as tabular/indexed metric
                $entries = $context->walker->walkIndexed($numericOid);

                if (!empty($entries)) {
                    foreach ($entries as $index => $val) {
                        if ($this->deadlineReached($context) || count($sensors) >= $maxSensors) {
                            break 2;
                        }

                        if ($val === null || $val === '') {
                            continue;
                        }

                        $fullOid = $numericOid . '.' . ltrim((string) $index, '.');
                        if (isset($seenOids[$fullOid])) {
                            continue;
                        }
                        $seenOids[$fullOid] = true;

                        $sensor = $this->buildSensor($fullOid, $val, $obj, (string) $index, $context);
                        if ($sensor !== null) {
                            $sensors[] = $sensor;
                        }
                    }
                    continue;
                }

                // Second: Try scalar with .0
                $val = $context->walker->get($numericOid . '.0');
                if ($val !== null && $val !== '') {
                    $fullOid = $numericOid . '.0';
                    if (!isset($seenOids[$fullOid])) {
                        $seenOids[$fullOid] = true;
                        $sensor = $this->buildSensor($fullOid, $val, $obj, null, $context);
                        if ($sensor !== null) {
                            $sensors[] = $sensor;
                        }
                    }
                    continue;
                }

                // Third: Try direct OID (without .0)
                $val = $context->walker->get($numericOid);
                if ($val !== null && $val !== '') {
                    $fullOid = $numericOid;
                    if (!isset($seenOids[$fullOid])) {
                        $seenOids[$fullOid] = true;
                        $sensor = $this->buildSensor($fullOid, $val, $obj, null, $context);
                        if ($sensor !== null) {
                            $sensors[] = $sensor;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Non-existent OID or timeout on this branch, continue cleanly
                continue;
            }
        }

        return $sensors;
    }

    /**
     * Parse MIB files and extract OBJECT-TYPE candidate definitions
     * 
     * @return list<array{name: string, module: string, oid: string, syntax: string, desc: string, file: string}>
     */
    private function getCandidateObjects(): array
    {
        $dirs = !empty($this->mibDirs) ? $this->mibDirs : [__DIR__ . '/../mibs'];
        $cacheKey = implode('|', $dirs);

        if (isset(self::$parsedMibCache[$cacheKey])) {
            return self::$parsedMibCache[$cacheKey];
        }

        $objects = [];
        $seen = [];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = @scandir($dir) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || is_dir($dir . '/' . $file)) {
                    continue;
                }

                $filePath = $dir . '/' . $file;
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

                // Support .mib, .my, .txt, or non-extension MIB files
                if (!in_array($ext, ['mib', 'my', 'txt', ''], true) && !str_contains($file, '-MIB') && !str_contains($file, 'MIB')) {
                    continue;
                }

                // Skip extremely large archive files (> 3MB) to maintain fast scan performance
                if (@filesize($filePath) > 3 * 1024 * 1024) {
                    continue;
                }

                $content = @file_get_contents($filePath);
                if (!$content || !str_contains($content, 'OBJECT-TYPE')) {
                    continue;
                }

                $modName = pathinfo($file, PATHINFO_FILENAME);
                if (preg_match('/^\s*([A-Za-z0-9_-]+)\s+DEFINITIONS/m', $content, $m)) {
                    $modName = $m[1];
                }

                // Extract all leaf OBJECT-TYPE definitions
                if (preg_match_all('/([A-Za-z0-9_-]+)\s+OBJECT-TYPE\s+SYNTAX\s+([^;]+?)\s+(?:MAX-ACCESS|ACCESS)\s+[^\n]+\s+STATUS\s+[^\n]+\s+DESCRIPTION\s+"([^"]*?)"\s*::=\s*\{\s*([A-Za-z0-9_-]+)\s+([0-9]+)\s*\}/is', $content, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $objName = $match[1];
                        $syntax = trim(preg_replace('/\s+/', ' ', $match[2]));
                        $desc = trim(preg_replace('/\s+/', ' ', $match[3]));

                        // Resolve numeric OID using OidTranslator
                        $calcOid = '';
                        if ($this->oidTranslator !== null) {
                            try {
                                $t = $this->oidTranslator->translate($modName . '::' . $objName);
                                if (!empty($t['numeric_oid'])) {
                                    $calcOid = ltrim($t['numeric_oid'], '.');
                                }
                            } catch (\Throwable $e) {}

                            if ($calcOid === '') {
                                try {
                                    $t2 = $this->oidTranslator->translate($objName);
                                    if (!empty($t2['numeric_oid'])) {
                                        $calcOid = ltrim($t2['numeric_oid'], '.');
                                    }
                                } catch (\Throwable $e) {}
                            }
                        }

                        if ($calcOid === '') {
                            continue;
                        }

                        $dedupKey = $calcOid . ':' . $objName;
                        if (isset($seen[$dedupKey])) {
                            continue;
                        }
                        $seen[$dedupKey] = true;

                        $objects[] = [
                            'name' => $objName,
                            'module' => $modName,
                            'oid' => $calcOid,
                            'syntax' => $syntax,
                            'desc' => substr($desc, 0, 300),
                            'file' => $filePath,
                        ];
                    }
                }
            }
        }

        return self::$parsedMibCache[$cacheKey] = $objects;
    }

    /**
     * @param array{name: string, module: string, oid: string, syntax: string, desc: string, file: string} $obj
     * @return array<string, mixed>|null
     */
    private function buildSensor(string $fullOid, mixed $value, array $obj, ?string $index, DiscoveryContext $context): ?array
    {
        $numeric = SnmpValueHelper::numeric($value);
        $objName = $obj['name'];
        $syntax = $obj['syntax'];
        $desc = $obj['desc'];

        // Humanize object name (e.g. hwEntityTemperature -> Hw Entity Temperature)
        $cleanName = preg_replace('/(?<!^)(?=[A-Z])/', ' ', $objName);
        $cleanName = ucwords((string) $cleanName);
        if ($index !== null && $index !== '' && $index !== '0') {
            $cleanName .= ' #' . str_replace('.', '/', $index);
        }

        // Infer sensor class and unit from syntax and object name/description
        $class = $this->inferClass($objName, $syntax, $desc);
        $unit = $this->inferUnit($objName, $syntax, $desc, $class);

        if ($numeric !== null && abs($numeric) < 999999999999) {
            $normalized = $this->normalizer->normalize($value, 'units', 0, $unit);

            return [
                'sensor_class' => $class,
                'sensor_name' => $cleanName,
                'sensor_type' => 'numeric',
                'oid' => $fullOid,
                'raw_value' => (string) $value,
                'normalized_value' => $normalized !== null ? $normalized['value'] : (string) $numeric,
                'unit' => $normalized !== null && $normalized['unit'] !== '' ? $normalized['unit'] : $unit,
                'scale' => 'units',
                'precision' => 0,
                'status' => 'ok',
                'metadata' => [
                    'discovery_module' => 'LoadedMibDiscoveryModule',
                    'vendor' => $context->vendor->name(),
                    'source' => $obj['module'] . '::' . $obj['name'],
                    'category' => 'LOADED_MIB',
                    'syntax' => $syntax,
                    'description' => $desc,
                ],
            ];
        }

        // String / IP / DisplayString metric
        $strValue = trim((string) $value, " \t\n\r\0\x0B\"");
        if ($strValue === '') {
            return null;
        }

        return [
            'sensor_class' => $class,
            'sensor_name' => $cleanName,
            'sensor_type' => 'string',
            'oid' => $fullOid,
            'raw_value' => $strValue,
            'normalized_value' => $strValue,
            'unit' => '',
            'scale' => 'none',
            'precision' => 0,
            'status' => 'ok',
            'metadata' => [
                'discovery_module' => 'LoadedMibDiscoveryModule',
                'vendor' => $context->vendor->name(),
                'source' => $obj['module'] . '::' . $obj['name'],
                'category' => 'LOADED_MIB',
                'syntax' => $syntax,
                'description' => $desc,
            ],
        ];
    }

    private function inferClass(string $name, string $syntax, string $desc): string
    {
        $text = strtolower($name . ' ' . $syntax . ' ' . $desc);

        return match (true) {
            str_contains($text, 'temp') || str_contains($text, 'celsius') => 'temperature',
            str_contains($text, 'volt') || str_contains($text, 'vlot') => 'voltage',
            str_contains($text, 'current') || str_contains($text, 'ampere') => 'current',
            str_contains($text, 'power') || str_contains($text, 'watt') => 'power',
            str_contains($text, 'fan') || str_contains($text, 'rpm') => 'fan',
            str_contains($text, 'cpu') || str_contains($text, 'processor') => 'processor',
            str_contains($text, 'mem') || str_contains($text, 'ram') => 'memory',
            str_contains($text, 'traffic') || str_contains($text, 'octet') || str_contains($text, 'byte') => 'traffic',
            str_contains($text, 'counter') => 'counter',
            str_contains($text, 'gauge') => 'gauge',
            str_contains($text, 'percent') || str_contains($text, 'ratio') => 'percentage',
            str_contains($text, 'optical') || str_contains($text, 'laser') || str_contains($text, 'dbm') => 'optical',
            default => 'enterprise',
        };
    }

    private function inferUnit(string $name, string $syntax, string $desc, string $class): string
    {
        $text = strtolower($name . ' ' . $syntax . ' ' . $desc);

        return match (true) {
            str_contains($text, 'celsius') || $class === 'temperature' => 'Celsius',
            str_contains($text, 'millivolt') => 'mV',
            str_contains($text, 'volt') || $class === 'voltage' => 'V',
            str_contains($text, 'milliamp') => 'mA',
            str_contains($text, 'ampere') || $class === 'current' => 'A',
            str_contains($text, 'dbm') => 'dBm',
            str_contains($text, 'watt') => 'W',
            str_contains($text, 'rpm') || $class === 'fan' => 'RPM',
            str_contains($text, 'percent') || str_contains($text, '%') || $class === 'percentage' => '%',
            str_contains($text, 'kbit') || str_contains($text, 'kbps') => 'Kbps',
            str_contains($text, 'mbit') || str_contains($text, 'mbps') => 'Mbps',
            str_contains($text, 'octet') || str_contains($text, 'byte') => 'bytes',
            default => '',
        };
    }

    private function deadlineReached(DiscoveryContext $context): bool
    {
        $deadline = $context->snmpConfig['scan_deadline'] ?? null;

        return is_numeric($deadline) && microtime(true) >= (float) $deadline;
    }
}
