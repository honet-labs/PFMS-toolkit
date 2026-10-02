<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;

/**
 * F5 BIG-IP Interface Discovery Module
 *
 * Discovers network interfaces on F5 BIG-IP devices using F5-specific OIDs.
 * Interfaces provide traffic metrics, errors, and status information.
 *
 * MIB: f5networks-LOAD-BAL-SYSTEM.mib
 * OID: 1.3.6.1.4.1.3375.2.1.1 (ltmConfigModule)
 *
 * Discovered Metrics per Interface:
 * - Traffic: Input/Output octets (bytes)
 * - Errors: Input/Output errors
 * - Status: Up/Down state
 * - Collisions (if applicable)
 *
 * Discovery Strategy:
 * 1. Verify device is F5 (sysObjectID contains "3375")
 * 2. Query IF-MIB interface table (standard)
 * 3. Enumerate all interfaces and their statistics
 * 4. Filter out virtual/loopback interfaces
 * 5. Create sensors for each physical interface
 */
final readonly class F5InterfaceDiscoveryModule implements DiscoveryModuleInterface
{
    private const string IF_DESCR = '1.3.6.1.2.1.2.2.1.2';
    private const string IF_TYPE = '1.3.6.1.2.1.2.2.1.3';
    private const string IF_SPEED = '1.3.6.1.2.1.2.2.1.5';
    private const string IF_ADMIN_STATUS = '1.3.6.1.2.1.2.2.1.7';
    private const string IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';
    private const string IF_IN_OCTETS = '1.3.6.1.2.1.2.2.1.10';
    private const string IF_IN_ERRORS = '1.3.6.1.2.1.2.2.1.14';
    private const string IF_OUT_OCTETS = '1.3.6.1.2.1.2.2.1.16';
    private const string IF_OUT_ERRORS = '1.3.6.1.2.1.2.2.1.20';

    // IF-MIB extensions (64-bit counters)
    private const string IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';
    private const string IF_HIGH_SPEED = '1.3.6.1.2.1.31.1.1.1.15';
    private const string IF_HIGH_IN_OCTETS = '1.3.6.1.2.1.31.1.1.1.6';
    private const string IF_HIGH_OUT_OCTETS = '1.3.6.1.2.1.31.1.1.1.10';

    // Interface status constants
    private const array OPER_STATUS = [
        1 => 'up',
        2 => 'down',
        3 => 'testing',
    ];

    private const array ADMIN_STATUS = [
        1 => 'up',
        2 => 'down',
        3 => 'testing',
    ];

    public function __construct(
        private NormalizerInterface $normalizer,
    ) {
    }

    public function name(): string
    {
        return 'F5InterfaceDiscovery';
    }

    /**
     * Check if this is an F5 device with interface tables
     */
    public function supports(DiscoveryContext $context): bool
    {
        // Verify device is F5
        if (!$this->isF5Device($context)) {
            return false;
        }

        // Verify interface table exists (try to get count)
        try {
            $ifCount = $context->snmp()->get('1.3.6.1.2.1.2.1');
            return !in_array($ifCount, [null, '', '0'], true) && (int) $ifCount > 0;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Discover interfaces on F5 device
     */
    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            // Walk all interface descriptions
            $ifDescriptions = $context->snmp()->walk(self::IF_DESCR);

            if ($ifDescriptions === []) {
                return [];
            }

            foreach ($ifDescriptions as $ifIndex => $ifDescription) {
                $ifDescription = trim((string) $ifDescription);

                // Skip ignored interfaces (loopback, tunnel, etc.)
                if ($this->isIgnoredInterface($ifDescription)) {
                    continue;
                }

                try {
                    // Get interface details
                    $ifName = $context->snmp()->get(self::IF_NAME . '.' . $ifIndex);
                    $ifType = $context->snmp()->get(self::IF_TYPE . '.' . $ifIndex);
                    $ifAdminStatus = $context->snmp()->get(self::IF_ADMIN_STATUS . '.' . $ifIndex);
                    $ifOperStatus = $context->snmp()->get(self::IF_OPER_STATUS . '.' . $ifIndex);

                    // Normalize status
                    $operStatus = self::OPER_STATUS[$ifOperStatus] ?? 'unknown';
                    $adminStatus = self::ADMIN_STATUS[$ifAdminStatus] ?? 'unknown';

                    // Get interface label
                    $ifLabel = $this->getNormalizedName($ifName, $ifDescription);

                    // Interface Status Sensor
                    $statusSensor = [
                        'sensor_type' => 'f5_interface_status',
                        'sensor_class' => 'interface_status',
                        'sensor_name' => "{$ifLabel} - Status",
                        'oid' => self::IF_OPER_STATUS . ".{$ifIndex}",
                        'unit' => 'status',
                        'raw_value' => $operStatus,
                        'description' => "F5 Interface {$ifLabel} - Admin: {$adminStatus}, Oper: {$operStatus}",
                    ];
                    $normalizedStatus = $this->normalizer->normalize($statusSensor);
                    if ($normalizedStatus) {
                        $sensors[] = $normalizedStatus;
                    }

                    // Get speed
                    $speed = $this->getSpeed($context, $ifIndex);
                    if ($speed && $speed > 0) {
                        $speedDisplay = $this->formatSpeed($speed);
                        $speedSensor = [
                            'sensor_type' => 'f5_interface_speed',
                            'sensor_class' => 'interface_speed',
                            'sensor_name' => "{$ifLabel} - Speed ({$speedDisplay})",
                            'oid' => self::IF_HIGH_SPEED . ".{$ifIndex}",
                            'unit' => 'bps',
                            'raw_value' => $speed,
                            'description' => "Speed of F5 Interface {$ifLabel}: {$speedDisplay}",
                        ];
                        $normalizedSpeed = $this->normalizer->normalize($speedSensor);
                        if ($normalizedSpeed) {
                            $sensors[] = $normalizedSpeed;
                        }
                    }

                    // Input Octets (64-bit preferred)
                    $ifInOctets = $context->snmp()->get(self::IF_HIGH_IN_OCTETS . '.' . $ifIndex);
                    if (in_array($ifInOctets, [null, '', '0'], true)) {
                        $ifInOctets = $context->snmp()->get(self::IF_IN_OCTETS . '.' . $ifIndex);
                    }

                    if ($ifInOctets) {
                        $inOctetsSensor = [
                            'sensor_type' => 'f5_interface_in_octets',
                            'sensor_class' => 'interface_traffic',
                            'sensor_name' => "{$ifLabel} - Input Octets",
                            'oid' => self::IF_IN_OCTETS . ".{$ifIndex}",
                            'unit' => 'bytes',
                            'raw_value' => $ifInOctets,
                            'description' => "Incoming traffic on F5 Interface {$ifLabel}",
                        ];
                        $normalizedInOctets = $this->normalizer->normalize($inOctetsSensor);
                        if ($normalizedInOctets) {
                            $sensors[] = $normalizedInOctets;
                        }
                    }

                    // Output Octets (64-bit preferred)
                    $ifOutOctets = $context->snmp()->get(self::IF_HIGH_OUT_OCTETS . '.' . $ifIndex);
                    if (in_array($ifOutOctets, [null, '', '0'], true)) {
                        $ifOutOctets = $context->snmp()->get(self::IF_OUT_OCTETS . '.' . $ifIndex);
                    }

                    if ($ifOutOctets) {
                        $outOctetsSensor = [
                            'sensor_type' => 'f5_interface_out_octets',
                            'sensor_class' => 'interface_traffic',
                            'sensor_name' => "{$ifLabel} - Output Octets",
                            'oid' => self::IF_OUT_OCTETS . ".{$ifIndex}",
                            'unit' => 'bytes',
                            'raw_value' => $ifOutOctets,
                            'description' => "Outgoing traffic on F5 Interface {$ifLabel}",
                        ];
                        $normalizedOutOctets = $this->normalizer->normalize($outOctetsSensor);
                        if ($normalizedOutOctets) {
                            $sensors[] = $normalizedOutOctets;
                        }
                    }

                    // Input Errors
                    $ifInErrors = $context->snmp()->get(self::IF_IN_ERRORS . '.' . $ifIndex);
                    if ($ifInErrors !== null && $ifInErrors !== '') {
                        $inErrorsSensor = [
                            'sensor_type' => 'f5_interface_in_errors',
                            'sensor_class' => 'interface_errors',
                            'sensor_name' => "{$ifLabel} - Input Errors",
                            'oid' => self::IF_IN_ERRORS . ".{$ifIndex}",
                            'unit' => 'errors',
                            'raw_value' => $ifInErrors,
                            'description' => "Input errors on F5 Interface {$ifLabel}",
                        ];
                        $normalizedInErrors = $this->normalizer->normalize($inErrorsSensor);
                        if ($normalizedInErrors) {
                            $sensors[] = $normalizedInErrors;
                        }
                    }

                    // Output Errors
                    $ifOutErrors = $context->snmp()->get(self::IF_OUT_ERRORS . '.' . $ifIndex);
                    if ($ifOutErrors !== null && $ifOutErrors !== '') {
                        $outErrorsSensor = [
                            'sensor_type' => 'f5_interface_out_errors',
                            'sensor_class' => 'interface_errors',
                            'sensor_name' => "{$ifLabel} - Output Errors",
                            'oid' => self::IF_OUT_ERRORS . ".{$ifIndex}",
                            'unit' => 'errors',
                            'raw_value' => $ifOutErrors,
                            'description' => "Output errors on F5 Interface {$ifLabel}",
                        ];
                        $normalizedOutErrors = $this->normalizer->normalize($outErrorsSensor);
                        if ($normalizedOutErrors) {
                            $sensors[] = $normalizedOutErrors;
                        }
                    }
                } catch (\Exception) {
                    // Skip this interface on error, continue with next
                    continue;
                }
            }
        } catch (\Exception) {
            // Return empty array on SNMP error
            return [];
        }

        return $sensors;
    }

    /**
     * Check if device is F5
     */
    private function isF5Device(DiscoveryContext $context): bool
    {
        try {
            $sysObjectID = $context->snmp()->get('1.3.6.1.2.1.1.2.0');
            return !in_array($sysObjectID, [null, '', '0'], true) && str_contains($sysObjectID, '3375');
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Get speed for interface (prefers 64-bit ifHighSpeed)
     */
    private function getSpeed(DiscoveryContext $context, string $ifIndex): ?int
    {
        try {
            // Try 64-bit speed first (more accurate for high-speed links)
            $highSpeed = $context->snmp()->get(self::IF_HIGH_SPEED . '.' . $ifIndex);
            if ($highSpeed) {
                // ifHighSpeed is in Mbps, convert to bps
                return (int) $highSpeed * 1_000_000;
            }

            // Fallback to 32-bit speed
            $speed = $context->snmp()->get(self::IF_SPEED . '.' . $ifIndex);
            if ($speed) {
                return (int) $speed;
            }
        } catch (\Exception) {
            return null;
        }

        return null;
    }

    /**
     * Check if interface should be ignored
     */
    private function isIgnoredInterface(string $ifDescription): bool
    {
        $patterns = [
            '/^lo\d*$/',                    // loopback
            '/loopback/i',
            '/tunnel/i',
            '/docker/i',
            '/vlan/i',
            '/virtual/i',
            '/async/i',
            '/ppp/i',
            '/frame relay/i',
            '/^management/i',
            '/^internal/i',
            '/^nullroute/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $ifDescription)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get normalized interface name
     */
    private function getNormalizedName(?string $ifName, string $ifDescription): string
    {
        if (!in_array($ifName, [null, '', '0'], true)) {
            return trim($ifName);
        }

        return trim($ifDescription);
    }

    /**
     * Format speed for display
     */
    private function formatSpeed(int $bps): string
    {
        if ($bps >= 1_000_000_000) {
            return sprintf('%.1f Tbps', $bps / 1_000_000_000_000);
        }
        if ($bps >= 1_000_000) {
            return sprintf('%.1f Gbps', $bps / 1_000_000_000);
        }
        if ($bps >= 1_000) {
            return sprintf('%.1f Mbps', $bps / 1_000_000);
        }

        return sprintf('%d bps', $bps);
    }
}
