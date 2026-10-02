<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Normalize\OpticalPowerThresholdDetector;

/**
 * Optical Power Discovery with Auto-Applied Thresholds
 * 
 * Discovers optical power (Rx/Tx) sensors and automatically applies
 * warning/critical thresholds from Huawei/ZTE MIBs.
 * 
 * Supports:
 * - Huawei (1.3.6.1.4.1.2011.5.25.31.1.1.3.1)
 * - ZTE (similar OIDs)
 * 
 * Threshold OIDs:
 * - Rx Low: 1.3.6.1.4.1.2011.5.25.31.1.1.3.1.13
 * - Rx High: 1.3.6.1.4.1.2011.5.25.31.1.1.3.1.14
 * - Tx Low: 1.3.6.1.4.1.2011.5.25.31.1.1.3.1.15
 * - Tx High: 1.3.6.1.4.1.2011.5.25.31.1.1.3.1.16
 * 
 * MIBs: ENTITY-SENSOR-MIB, HUAWEI-OPT-MIB, ZTE-OPT-MIB
 */
final readonly class OpticalPowerWithThresholdsModule implements DiscoveryModuleInterface
{
    // Entity-MIB for base optical power
    private const string ENTITY_SENSOR_VALUE = '1.3.6.1.2.1.99.1.1.1.4';
    private const string ENTITY_SENSOR_TYPE = '1.3.6.1.2.1.99.1.1.1.1';
    private const string ENTITY_SENSOR_SCALE = '1.3.6.1.2.1.99.1.1.1.2';
    private const string ENTITY_SENSOR_STATUS = '1.3.6.1.2.1.99.1.1.1.5';
    private const string ENT_PHYSICAL_NAME = '1.3.6.1.2.1.47.1.1.1.1.7';
    private const string ENT_PHYSICAL_DESCR = '1.3.6.1.2.1.47.1.1.1.1.2';

    // Sensor type for optical power (dBm = 14, dB = 15)
    private const array OPTICAL_POWER_TYPES = ['14', '15'];

    public function __construct(
        private OpticalPowerThresholdDetector $thresholdDetector = new OpticalPowerThresholdDetector(),
    ) {
    }

    public function name(): string
    {
        return 'optical_power_with_thresholds';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // All vendors can have optical power
        return true;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            // Walk optical power sensors from ENTITY-MIB
            $powerValues = $context->snmp()->walk(self::ENTITY_SENSOR_VALUE);
            if ($powerValues === []) {
                return [];
            }

            $types = $context->snmp()->walk(self::ENTITY_SENSOR_TYPE);
            $scales = $context->snmp()->walk(self::ENTITY_SENSOR_SCALE);
            $status = $context->snmp()->walk(self::ENTITY_SENSOR_STATUS);
            $names = $context->snmp()->walk(self::ENT_PHYSICAL_NAME);
            $descriptions = $context->snmp()->walk(self::ENT_PHYSICAL_DESCR);

            foreach ($powerValues as $oid => $value) {
                try {
                    $entityIndex = $this->extractEntityIndex($oid);
                    if ($entityIndex === null) {
                        continue;
                    }
                    if ($entityIndex === '') {
                        continue;
                    }
                    if ($entityIndex === '0') {
                        continue;
                    }

                    // Check if this is optical power (type 14=dBm or 15=dB)
                    $sensorType = $types[$oid] ?? null;
                    if (!$sensorType) {
                        continue;
                    }
                    if (!in_array($sensorType, self::OPTICAL_POWER_TYPES, true)) {
                        continue;
                    }
                    // Skip invalid values
                    if ($value === null) {
                        continue;
                    }
                    if ($value === '') {
                        continue;
                    }
                    if ($value === 'NULL') {
                        continue;
                    }

                    $powerValue = (float) $value;
                    $scale = (int) ($scales[$oid] ?? 1);
                    $sensorStatus = $status[$oid] ?? '1';  // 1 = ok

                    // Get sensor name/description
                    $sensorName = trim((string) ($names[$oid] ?? 'Optical Power ' . $entityIndex));
                    if ($sensorName === '' || $sensorName === '0') {
                        $sensorName = trim((string) ($descriptions[$oid] ?? 'Optical Power ' . $entityIndex));
                    }

                    // Get interface index from sensor name (if available)
                    $ifIndex = $this->extractIfIndexFromName($sensorName);

                    // Try to get optical power thresholds if interface index available
                    $thresholds = null;
                    $metadata = [
                        'entity_index' => $entityIndex,
                        'sensor_type' => $sensorType === '14' ? 'dBm' : 'dB',
                        'sensor_description' => "Optical power sensor detected. Auto-threshold detection enabled.",
                    ];

                    if ($ifIndex) {
                        $thresholds = $this->thresholdDetector->detect($context, $ifIndex);
                        if ($thresholds['available']) {
                            $metadata['thresholds'] = [
                                'rx_low_warning' => $thresholds['rx_low'],
                                'rx_high_critical' => $thresholds['rx_high'],
                                'tx_low_warning' => $thresholds['tx_low'],
                                'tx_high_critical' => $thresholds['tx_high'],
                            ];
                        }
                    }

                    // Create sensor entry
                    $sensors[] = [
                        'sensor_class' => 'optical_power',
                        'sensor_name' => $sensorName,
                        'sensor_type' => $sensorType === '14' ? 'optical_power_dbm' : 'optical_power_db',
                        'interface_index' => $ifIndex,
                        'entity_index' => $entityIndex,
                        'oid' => $oid,
                        'raw_value' => (string) $powerValue,
                        'normalized_value' => (string) ($powerValue / $scale),
                        'unit' => $sensorType === '14' ? 'dBm' : 'dB',
                        'scale' => $scale,
                        'precision' => 2,
                        'status' => $sensorStatus === '1' ? 'ok' : 'failed',
                        'metadata' => $metadata,
                    ];

                } catch (\Exception $e) {
                    error_log("Error processing optical power sensor {$oid}: " . $e->getMessage());
                    continue;
                }
            }

        } catch (\Exception $e) {
            error_log("Optical power threshold discovery error: " . $e->getMessage());
            return [];
        }

        return $sensors;
    }

    /**
     * Extract entity index from OID
     * e.g., ".1.3.6.1.2.1.99.1.1.1.4.1" -> "1"
     */
    private function extractEntityIndex(string $oid): ?string
    {
        $oid = ltrim($oid, '.');
        $parts = explode('.', $oid);
        $index = end($parts);
        return !empty($index) && is_numeric($index) ? $index : null;
    }

    /**
     * Try to extract interface index from sensor name
     * e.g., "Transceiver 1 Rx Power" -> 1
     */
    private function extractIfIndexFromName(string $sensorName): ?int
    {
        // Common patterns: "XFP1", "SFP2", "Port 3", "Interface 5", etc.
        if (preg_match('/(?:Port|Interface|SFP|XFP|QSFP)[\s\-]*(\d+)/i', $sensorName, $matches)) {
            return (int) $matches[1];
        }

        // Just numeric: "1", "2", "10", etc.
        if (preg_match('/^(\d+)$/', trim($sensorName), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
