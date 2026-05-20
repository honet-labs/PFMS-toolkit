<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Normalize;

use SnmpBridge\Core\Discovery\DiscoveryContext;

/**
 * Reusable Interface Speed Detection Module
 * 
 * Handles dual OID detection and conversion for consistent speed values:
 * - ifSpeed (1.3.6.1.2.1.2.2.1.5): Returns speed in bps
 * - ifHighSpeed (1.3.6.1.2.1.31.1.1.1.15): Returns speed in Mbps
 * 
 * Priority:
 * 1. Use ifHighSpeed if available (convert Mbps → bps)
 * 2. Fall back to ifSpeed if ifHighSpeed unavailable
 * 3. Always return speed in bps units
 * 
 * Speed Ranges:
 * - Minimum: 1 bps (considered valid, though unlikely)
 * - Maximum: Unlimited (no artificial caps, supports future high-speed interfaces)
 * 
 * Usage:
 *   $detector = new SpeedDetector();
 *   $result = $detector->detect($context, $ifIndex);
 *   if ($result['speed'] > 0) {
 *       echo "Speed: " . $result['speed'] . " bps from OID: " . $result['oid'];
 *   }
 */
final class SpeedDetector
{
    // Standard IF-MIB OIDs
    private const IF_SPEED = '1.3.6.1.2.1.2.2.1.5';
    private const IF_HIGH_SPEED = '1.3.6.1.2.1.31.1.1.1.15';
    
    // Speed validation: accept any value > 0
    // No artificial upper limit to support future technologies
    private const MIN_VALID_SPEED = 0;

    /**
     * Detect interface speed from dual OIDs
     * 
     * @return array{speed: int, oid: string, source: string, raw_values: array}
     *   speed: Detected speed in bps (0 if not available)
     *   oid: The OID used to get the speed
     *   source: Description of speed source (for sensor description)
     *   raw_values: Debug info with both OID values (for logging)
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
            // Try high-speed first (RFC 2096)
            $ifHighSpeed = $context->snmp()->get(self::IF_HIGH_SPEED . ".{$ifIndex}");
            $debugInfo['ifHighSpeed_raw'] = $ifHighSpeed;
            
            if ($this->isValidSpeed($ifHighSpeed)) {
                $ifHighSpeedInt = (int) $ifHighSpeed;
                
                // ifHighSpeed is in Mbps, convert to bps
                $speedBps = $ifHighSpeedInt * 1000000;
                
                return [
                    'speed' => $speedBps,
                    'oid' => self::IF_HIGH_SPEED . ".{$ifIndex}",
                    'source' => 'ifHighSpeed (Mbps → bps)',
                    'raw_values' => $debugInfo,
                ];
            }
            
            // Fall back to standard speed (RFC 2863)
            $ifSpeed = $context->snmp()->get(self::IF_SPEED . ".{$ifIndex}");
            $debugInfo['ifSpeed_raw'] = $ifSpeed;
            
            if ($this->isValidSpeed($ifSpeed)) {
                $speedBps = (int) $ifSpeed;
                
                return [
                    'speed' => $speedBps,
                    'oid' => self::IF_SPEED . ".{$ifIndex}",
                    'source' => 'ifSpeed (bps)',
                    'raw_values' => $debugInfo,
                ];
            }
            
            // No speed available
            return [
                'speed' => 0,
                'oid' => '',
                'source' => 'No speed data available',
                'raw_values' => $debugInfo,
            ];
            
        } catch (\Exception $e) {
            error_log("SpeedDetector error for ifIndex {$ifIndex}: " . $e->getMessage());
            
            return [
                'speed' => 0,
                'oid' => '',
                'source' => 'Error: ' . $e->getMessage(),
                'raw_values' => $debugInfo,
            ];
        }
    }

    /**
     * Batch detect speeds for multiple interfaces
     * 
     * @param array<int|string> $ifIndexes
     * @return array<int|string, array{speed: int, oid: string, source: string, raw_values: array}>
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
     * Get only the speed value
     * 
     * @return int Speed in bps (0 if not available)
     */
    public function getSpeed(DiscoveryContext $context, int|string $ifIndex): int
    {
        return $this->detect($context, $ifIndex)['speed'];
    }

    /**
     * Get only the OID that was used
     * 
     * @return string OID (empty string if not available)
     */
    public function getOid(DiscoveryContext $context, int|string $ifIndex): string
    {
        return $this->detect($context, $ifIndex)['oid'];
    }

    /**
     * Get only the source description
     * 
     * @return string Source description
     */
    public function getSource(DiscoveryContext $context, int|string $ifIndex): string
    {
        return $this->detect($context, $ifIndex)['source'];
    }
    
    /**
     * Check if a speed value is valid and non-zero
     */
    private function isValidSpeed(mixed $value): bool
    {
        // Must be not null
        if ($value === null || $value === '') {
            return false;
        }
        
        // Convert to int and check if > 0
        $intVal = (int) $value;
        return $intVal > self::MIN_VALID_SPEED;
    }
}
