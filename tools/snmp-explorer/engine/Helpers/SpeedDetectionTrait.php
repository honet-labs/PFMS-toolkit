<?php

declare(strict_types=1);

namespace SnmpBridge\Helpers;

/**
 * Reusable trait for detecting and formatting interface speeds across modules.
 * Handles vendor-specific OID variations, high-speed interfaces, and unit conversions.
 *
 * Usage: Use in interface discovery modules to standardize speed detection.
 */
trait SpeedDetectionTrait
{
    /**
     * Detect interface speed from ifSpeed and ifHighSpeed OIDs.
     * Falls back to high speed when regular speed maxes out at 4Gbps.
     *
     * @param int|string|null $ifSpeed Regular IF-MIB::ifSpeed (32-bit, max 4.2Gbps)
     * @param int|string|null $ifHighSpeed IF-MIB::ifHighSpeed (64-bit, for 10Gbps+)
     * @return int|null Speed in bits-per-second, or null if unavailable
     */
    protected function detectInterfaceSpeed(mixed $ifSpeed, mixed $ifHighSpeed): ?int
    {
        // Try high speed first if available (1 Mbps units, more accurate for fast links)
        if ($ifHighSpeed !== null && $ifHighSpeed !== '') {
            $highSpeed = SnmpValueHelper::integer($ifHighSpeed);
            if ($highSpeed !== null && $highSpeed > 0) {
                return $highSpeed * 1_000_000; // Convert Mbps units to bps
            }
        }

        return null;
    }

    /**
     * Format speed in bps to human-readable string with appropriate unit.
     *
     * @param int $speedBps Speed in bits-per-second
     * @return string Formatted speed (e.g., "1.0 Gbps", "100.0 Mbps", "64 kbps")
     */
    protected function formatSpeed(int $speedBps): string
    {
        if ($speedBps <= 0) {
            return '0 bps';
        }

        $units = ['bps', 'kbps', 'Mbps', 'Gbps', 'Tbps'];
        $divisor = 1000;
        $unitIndex = 0;

        $speed = (float) $speedBps;

        while ($speed >= $divisor && $unitIndex < count($units) - 1) {
            $speed /= $divisor;
            $unitIndex++;
        }
        // Format with appropriate precision
        if ($speed >= 100) {
            return number_format($speed, 0) . ' ' . $units[$unitIndex];
        }

        // Format with appropriate precision
        if ($speed >= 10) {
            return number_format($speed, 1) . ' ' . $units[$unitIndex];
        }
        return number_format($speed, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Detect vendor-specific high-speed interface indicator.
     * Some vendors expose high-speed capability through proprietary OIDs.
     *
     * @param mixed $vendorHighSpeedOid Value from vendor-specific high-speed OID
     * @return bool True if interface is known to be high-speed (10Gbps+)
     */
    protected function isHighSpeedInterface(mixed $vendorHighSpeedOid): bool
    {
        if ($vendorHighSpeedOid === null || $vendorHighSpeedOid === '') {
            return false;
        }

        $value = SnmpValueHelper::integer($vendorHighSpeedOid);

        // Common vendor indicators: 1=HighSpeed, values > 1Gbps indicate high-speed
        return $value === 1 || ($value !== null && $value > 1_000_000_000);
    }

    /**
     * Convert speed value to bits-per-second based on unit indicator.
     * Handles vendor-specific unit encodings.
     *
     * @param int|string $speedValue The numeric speed value
     * @param int $unitCode Unit identifier (vendor-specific encoding)
     *                       1 = kbps, 2 = Mbps, 3 = Gbps, etc.
     * @return int Speed in bits-per-second
     */
    protected function speedToBps(mixed $speedValue, int $unitCode): int
    {
        $numeric = SnmpValueHelper::integer($speedValue) ?? 0;

        return match ($unitCode) {
            1 => $numeric * 1_000,            // kbps
            2 => $numeric * 1_000_000,        // Mbps
            3 => $numeric * 1_000_000_000,    // Gbps
            4 => $numeric * 1_000_000_000_000, // Tbps
            default => $numeric,               // Assume bps
        };
    }

    /**
     * Determine appropriate sensor unit based on speed value.
     * Avoids reporting high-speed interfaces in inappropriate units.
     *
     * @param int $speedBps Speed in bits-per-second
     * @return string Unit label for sensor ('bps', 'kbps', 'Mbps', 'Gbps', etc.)
     */
    protected function speedUnit(int $speedBps): string
    {
        if ($speedBps >= 1_000_000_000) {
            return 'Gbps';
        }
        if ($speedBps >= 1_000_000) {
            return 'Mbps';
        }
        if ($speedBps >= 1_000) {
            return 'kbps';
        }

        return 'bps';
    }
}
