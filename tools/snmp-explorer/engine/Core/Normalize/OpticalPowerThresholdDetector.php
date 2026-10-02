<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Normalize;

use SnmpBridge\Core\Discovery\DiscoveryContext;

/**
 * Optical Power Threshold Auto-Detection
 * 
 * Automatically discovers and applies optical power thresholds
 * from Huawei/ZTE optical interfaces.
 * 
 * Huawei OIDs (RFC 3636 - IF-MIB Extensions for Optical Interfaces):
 * - Rx Low Threshold: 1.3.6.1.4.1.2011.5.25.31.1.1.3.1.13
 * - Rx High Threshold: 1.3.6.1.4.1.2011.5.25.31.1.1.3.1.14
 * - Tx Low Threshold: 1.3.6.1.4.1.2011.5.25.31.1.1.3.1.15
 * - Tx High Threshold: 1.3.6.1.4.1.2011.5.25.31.1.1.3.1.16
 * 
 * Unit: Typically in dBm (decibels per milliwatt)
 * Range: Typically -40 to +10 dBm for optical interfaces
 */
final class OpticalPowerThresholdDetector
{
    // Huawei Optical Power Threshold OIDs
    private const string RX_LOW_THRESHOLD = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.13';
    private const string RX_HIGH_THRESHOLD = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.14';
    private const string TX_LOW_THRESHOLD = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.15';
    private const string TX_HIGH_THRESHOLD = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.16';

    /**
     * Detect optical power thresholds for an interface
     * 
     * @return array{
     *   rx_low: float|null,
     *   rx_high: float|null,
     *   tx_low: float|null,
     *   tx_high: float|null,
     *   unit: string,
     *   available: bool
     * }
     */
    public function detect(DiscoveryContext $context, int|string $ifIndex): array
    {
        $ifIndex = (int) $ifIndex;
        
        try {
            $rxLow = $this->getThreshold($context, self::RX_LOW_THRESHOLD, $ifIndex);
            $rxHigh = $this->getThreshold($context, self::RX_HIGH_THRESHOLD, $ifIndex);
            $txLow = $this->getThreshold($context, self::TX_LOW_THRESHOLD, $ifIndex);
            $txHigh = $this->getThreshold($context, self::TX_HIGH_THRESHOLD, $ifIndex);
            
            $hasThresholds = $rxLow !== null || $rxHigh !== null || $txLow !== null || $txHigh !== null;
            
            return [
                'rx_low' => $rxLow,
                'rx_high' => $rxHigh,
                'tx_low' => $txLow,
                'tx_high' => $txHigh,
                'unit' => 'dBm',  // Standard optical power unit
                'available' => $hasThresholds,
            ];
            
        } catch (\Exception $e) {
            error_log("OpticalPowerThresholdDetector error for ifIndex {$ifIndex}: " . $e->getMessage());
            
            return [
                'rx_low' => null,
                'rx_high' => null,
                'tx_low' => null,
                'tx_high' => null,
                'unit' => 'dBm',
                'available' => false,
            ];
        }
    }

    /**
     * Get single threshold value
     */
    private function getThreshold(DiscoveryContext $context, string $oid, int $ifIndex): ?float
    {
        try {
            $value = $context->snmp()->get("{$oid}.{$ifIndex}");
            
            if (in_array($value, [null, '', 'NULL'], true)) {
                return null;
            }
            
            $floatValue = (float) $value;
            
            // Validate reasonable optical power range (-50 to +10 dBm)
            if ($floatValue < -50 || $floatValue > 10) {
                error_log("Threshold value {$floatValue} dBm out of reasonable range for OID {$oid}.{$ifIndex}");
                return null;
            }
            
            return $floatValue;
            
        } catch (\Exception $e) {
            error_log("Error querying threshold OID {$oid}.{$ifIndex}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Batch detect thresholds for multiple interfaces
     * 
     * @param array<int|string> $ifIndexes
     * @return array<int|string, array>
     */
    public function detectBatch(DiscoveryContext $context, array $ifIndexes): array
    {
        $results = [];
        foreach ($ifIndexes as $ifIndex) {
            $results[$ifIndex] = $this->detect($context, $ifIndex);
        }
        return $results;
    }

    /**
     * Format threshold for display
     */
    public function formatThreshold(float $value, string $unit = 'dBm'): string
    {
        return number_format($value, 2) . ' ' . $unit;
    }
}
