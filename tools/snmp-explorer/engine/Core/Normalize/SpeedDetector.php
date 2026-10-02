<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Normalize;

use SnmpBridge\Core\Discovery\DiscoveryContext;

/**
 * Reusable Interface Speed Detection Module with Smart Unit Detection
 * 
 * Handles dual OID detection with intelligent unit preservation:
 * - ifSpeed (1.3.6.1.2.1.2.2.1.5): Returns speed in bps (kept as bps)
 * - ifHighSpeed (1.3.6.1.2.1.31.1.1.1.15): Returns speed in Mbps (kept as Mbps)
 * 
 * Priority:
 * 1. Use ifHighSpeed if available (returns value in Mbps with Mbps unit)
 * 2. Fall back to ifSpeed if ifHighSpeed unavailable (returns value in bps with bps unit)
 * 3. Smart unit detection - returns original units, not converted
 * 
 * Usage:
 *   $detector = new SpeedDetector();
 *   $result = $detector->detect($context, $ifIndex);
 *   // Returns: {speed: 100000, unit: 'Mbps'} or {speed: 1000000000, unit: 'bps'}
 * 
 * Benefits:
 * - User can choose which unit to use in PandoraFMS
 * - No data loss from conversion
 * - Original OID values preserved
 */
final class SpeedDetector
{
    // Standard IF-MIB OIDs
    private const string IF_SPEED = '1.3.6.1.2.1.2.2.1.5';
    private const string IF_HIGH_SPEED = '1.3.6.1.2.1.31.1.1.1.15';
    
    // Speed validation: accept any value > 0
    private const int MIN_VALID_SPEED = 0;

    /**
     * Detect interface speed from dual OIDs with smart unit preservation
     * 
     * @return array{
     *   speed: int,
     *   unit: string,
     *   oid: string,
     *   source: string,
     *   raw_values: array,
     *   speed_in_bps: int
     * }
     *   speed: Raw value in original unit (not converted)
     *   unit: Original unit (Mbps for ifHighSpeed, bps for ifSpeed)
     *   speed_in_bps: Converted to bps for internal calculations
     *   oid: The OID used to get the speed
     *   source: Description of speed source
     *   raw_values: Debug info with both OID values
     */
    public function detect(DiscoveryContext $context, int|string $ifIndex): array
    {
        $ifIndex = (int) $ifIndex;
        $debugInfo = [
            'if_index' => $ifIndex,
            'ifHighSpeed_raw' => null,
            'ifSpeed_raw' => null,
        ];
        
        try {
            // Try high-speed first (RFC 2096) - returns Mbps
            $ifHighSpeed = $context->snmp()->get(self::IF_HIGH_SPEED . ".{$ifIndex}");
            $debugInfo['ifHighSpeed_raw'] = $ifHighSpeed;
            
            if ($this->isValidSpeed($ifHighSpeed)) {
                $ifHighSpeedInt = (int) $ifHighSpeed;
                $speedInBps = $ifHighSpeedInt * 1000000;  // Convert to bps for internal use
                
                return [
                    'speed' => $ifHighSpeedInt,
                    'unit' => 'Mbps',  // Keep original unit
                    'oid' => self::IF_HIGH_SPEED . ".{$ifIndex}",
                    'source' => 'ifHighSpeed (Mbps)',
                    'raw_values' => $debugInfo,
                    'speed_in_bps' => $speedInBps,  // For internal calculations
                ];
            }
            
            // Fallback to standard ifSpeed (bps) if ifHighSpeed is unavailable
            $ifSpeed = $context->snmp()->get(self::IF_SPEED . ".{$ifIndex}");
            $debugInfo['ifSpeed_raw'] = $ifSpeed;

            if ($this->isValidSpeed($ifSpeed)) {
                $ifSpeedInt = (int) $ifSpeed;
                return [
                    'speed' => $ifSpeedInt,
                    'unit' => 'bps',
                    'oid' => self::IF_SPEED . ".{$ifIndex}",
                    'source' => 'ifSpeed (bps)',
                    'raw_values' => $debugInfo,
                    'speed_in_bps' => $ifSpeedInt,
                ];
            }

            // No speed data available
            return [
                'speed' => 0,
                'unit' => 'Mbps',
                'oid' => '',
                'source' => 'No speed data available',
                'raw_values' => $debugInfo,
                'speed_in_bps' => 0,
            ];
            
        } catch (\Exception $e) {
            error_log("SpeedDetector error for ifIndex {$ifIndex}: " . $e->getMessage());
            
            return [
                'speed' => 0,
                'unit' => 'bps',
                'oid' => '',
                'source' => 'Error: ' . $e->getMessage(),
                'raw_values' => $debugInfo,
                'speed_in_bps' => 0,
            ];
        }
    }

    /**
     * Batch detect speeds for multiple interfaces
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
     * Get only the speed value in original unit
     * 
     * @return int Speed in original unit
     */
    public function getSpeed(DiscoveryContext $context, int|string $ifIndex): int
    {
        return $this->detect($context, $ifIndex)['speed'];
    }

    /**
     * Get speed converted to bps for calculations
     * 
     * @return int Speed in bps
     */
    public function getSpeedInBps(DiscoveryContext $context, int|string $ifIndex): int
    {
        return $this->detect($context, $ifIndex)['speed_in_bps'];
    }

    /**
     * Get unit
     * 
     * @return string Unit (Mbps or bps)
     */
    public function getUnit(DiscoveryContext $context, int|string $ifIndex): string
    {
        return $this->detect($context, $ifIndex)['unit'];
    }

    /**
     * Format speed for display
     */
    public function formatSpeed(int $speedInBps): string
    {
        if ($speedInBps >= 1000000000) {
            return round($speedInBps / 1000000000, 2) . ' Gbps';
        }
        if ($speedInBps >= 1000000) {
            return round($speedInBps / 1000000, 2) . ' Mbps';
        }
        if ($speedInBps >= 1000) {
            return round($speedInBps / 1000, 2) . ' Kbps';
        }
        return $speedInBps . ' bps';
    }
    
    /**
     * Check if a speed value is valid and non-zero
     */
    private function isValidSpeed(mixed $value): bool
    {
        if (in_array($value, [null, '', 'NULL'], true)) {
            return false;
        }
        
        $intVal = (int) $value;
        return $intVal > self::MIN_VALID_SPEED;
    }
}
