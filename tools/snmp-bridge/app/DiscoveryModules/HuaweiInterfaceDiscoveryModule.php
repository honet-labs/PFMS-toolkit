<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Normalize\SpeedDetector;

/**
 * Huawei-specific Interface Discovery Module
 * 
 * Extends IF-MIB with Huawei enterprise OIDs for:
 * - Optical interfaces
 * - GPON interfaces
 * - L2/L3 counters
 * - Queue statistics
 * - Advanced interface metrics
 * 
 * Huawei uses HUAWEI-MIB (1.3.6.1.4.1.2011)
 * 
 * Key features:
 * - Entity MIB mapping for optical sensors
 * - Native ifIndex mapping for standard interfaces
 * - Queue statistics for traffic analysis
 * - Advanced error counters
 * 
 * MIBs: IF-MIB, HUAWEI-MIB, HUAWEI-GPON-MIB, ENTITY-MIB
 */
final class HuaweiInterfaceDiscoveryModule implements DiscoveryModuleInterface
{
    // Standard IF-MIB
    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';
    private const IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';
    private const IF_SPEED = '1.3.6.1.2.1.2.2.1.5';
    private const IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';
    private const IF_IN_OCTETS = '1.3.6.1.2.1.2.2.1.10';
    private const IF_OUT_OCTETS = '1.3.6.1.2.1.2.2.1.16';
    private const IF_IN_ERRORS = '1.3.6.1.2.1.2.2.1.14';
    private const IF_OUT_ERRORS = '1.3.6.1.2.1.2.2.1.20';
    
    // Huawei enterprise OIDs
    private const HUAWEI_INTERFACE = '1.3.6.1.4.1.2011.5.2.1.1';
    private const HUAWEI_GPON = '1.3.6.1.4.1.2011.6.3.1';
    private const HUAWEI_OPTICAL = '1.3.6.1.4.1.2011.5.4';
    private const HUAWEI_QUEUE_STATS = '1.3.6.1.4.1.2011.5.2.1.1.2.1.1';

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SpeedDetector $speedDetector = new SpeedDetector(),
    ) {
    }

    public function name(): string
    {
        return 'huawei_interface_discovery';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // Support Huawei devices
        return strpos($context->sysObjectID(), '1.3.6.1.4.1.2011') === 0;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            // Discover standard interfaces
            $interfaces = $context->snmp()->walk(self::IF_DESCR);
            
            foreach ($interfaces as $ifIndex => $ifDescription) {
                $ifDescription = trim((string) $ifDescription);
                
                // Skip loopback
                if (stripos($ifDescription, 'loopback') === 0) {
                    continue;
                }

                // Get interface details
                $ifName = $context->snmp()->get(self::IF_NAME . '.' . $ifIndex);
                $ifStatus = $context->snmp()->get(self::IF_OPER_STATUS . '.' . $ifIndex);

                $name = !empty($ifName) ? trim($ifName) : $ifDescription;
                $name = $this->normalizeInterfaceName($name);

                // Speed detection using reusable SpeedDetector
                $speedResult = $this->speedDetector->detect($context, $ifIndex);
                $speed = $speedResult['speed'];
                $speedOid = $speedResult['oid'];
                $speedSource = $speedResult['source'];
                
                // Speed with interface name and value
                if ($speed && $speed > 0) {
                    $speedDisplay = $this->formatSpeed($speed);
                    $sensors[] = [
                        'type' => 'huawei_interface_speed',
                        'name' => "{$name} - Speed ({$speedDisplay})",
                        'oid' => $speedOid,
                        'unit' => 'bps',
                        'value' => $speed,
                        'description' => "Speed of {$name} - {$speedDisplay} ({$speedSource})",
                    ];
                }

                // Input octets
                $ifInOctets = $context->snmp()->get(self::IF_IN_OCTETS . '.' . $ifIndex);
                if ($ifInOctets) {
                    $sensors[] = [
                        'type' => 'huawei_interface_input_bytes',
                        'name' => "{$name} - Input Octets",
                        'oid' => self::IF_IN_OCTETS . '.' . $ifIndex,
                        'unit' => 'bytes',
                        'value' => $ifInOctets,
                        'description' => "Input bytes on {$name}",
                    ];
                }

                // Output octets
                $ifOutOctets = $context->snmp()->get(self::IF_OUT_OCTETS . '.' . $ifIndex);
                if ($ifOutOctets) {
                    $sensors[] = [
                        'type' => 'huawei_interface_output_bytes',
                        'name' => "{$name} - Output Octets",
                        'oid' => self::IF_OUT_OCTETS . '.' . $ifIndex,
                        'unit' => 'bytes',
                        'value' => $ifOutOctets,
                        'description' => "Output bytes on {$name}",
                    ];
                }

                // Input errors
                $ifInErrors = $context->snmp()->get(self::IF_IN_ERRORS . '.' . $ifIndex);
                if ($ifInErrors && $ifInErrors > 0) {
                    $sensors[] = [
                        'type' => 'huawei_interface_input_errors',
                        'name' => "{$name} - Input Errors",
                        'oid' => self::IF_IN_ERRORS . '.' . $ifIndex,
                        'unit' => 'errors',
                        'value' => $ifInErrors,
                    ];
                }

                // Output errors
                $ifOutErrors = $context->snmp()->get(self::IF_OUT_ERRORS . '.' . $ifIndex);
                if ($ifOutErrors && $ifOutErrors > 0) {
                    $sensors[] = [
                        'type' => 'huawei_interface_output_errors',
                        'name' => "{$name} - Output Errors",
                        'oid' => self::IF_OUT_ERRORS . '.' . $ifIndex,
                        'unit' => 'errors',
                        'value' => $ifOutErrors,
                    ];
                }

                // GPON-specific sensors
                if (stripos($name, 'gpon') !== false) {
                    $this->discoverGponSensors($context, $ifIndex, $name, $sensors);
                }
            }

            // Discover optical sensors using entity mapping
            $this->discoverOpticalSensors($context, $sensors);
        } catch (\Exception $e) {
            error_log("Huawei interface discovery error: " . $e->getMessage());
        }

        return $sensors;
    }

    private function discoverGponSensors(DiscoveryContext $context, string $ifIndex, string $name, array &$sensors): void
    {
        try {
            // GPON-specific OID walks
            $gponStats = $context->snmp()->walk(self::HUAWEI_GPON);
            
            foreach ($gponStats as $oid => $value) {
                if (strpos($oid, $ifIndex) !== false) {
                    $sensors[] = [
                        'type' => 'gpon_stat',
                        'name' => "{$name} - GPON Stat",
                        'oid' => self::HUAWEI_GPON . '.' . $oid,
                        'unit' => 'count',
                        'value' => $value,
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log("Huawei GPON sensor discovery error: " . $e->getMessage());
        }
    }

    private function discoverOpticalSensors(DiscoveryContext $context, array &$sensors): void
    {
        try {
            // Walk optical entity sensors
            $opticalData = $context->snmp()->walk(self::HUAWEI_OPTICAL);
            
            foreach ($opticalData as $oid => $value) {
                $sensors[] = [
                    'type' => 'huawei_optical',
                    'name' => "Optical Sensor - {$oid}",
                    'oid' => self::HUAWEI_OPTICAL . '.' . $oid,
                    'unit' => 'dbm',
                    'value' => $value,
                ];
            }
        } catch (\Exception $e) {
            error_log("Huawei optical sensor discovery error: " . $e->getMessage());
        }
    }

    private function normalizeInterfaceName(string $name): string
    {
        $name = trim($name);
        
        // Remove quotes
        $name = trim($name, "\"'");
        
        // Common patterns
        $patterns = [
            '/GigabitEthernet/i' => 'ge',
            '/FastEthernet/i' => 'fe',
            '/Ethernet/i' => 'eth',
            '/\s+/i' => '',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $name = preg_replace($pattern, $replacement, $name);
        }

        return $name;
    }

    /**
     * Format speed from bps to human-readable format
     */
    private function formatSpeed(int $speedBps): string
    {
        if ($speedBps >= 1000000000) {
            return round($speedBps / 1000000000, 2) . ' Gbps';
        } elseif ($speedBps >= 1000000) {
            return round($speedBps / 1000000, 2) . ' Mbps';
        } elseif ($speedBps >= 1000) {
            return round($speedBps / 1000, 2) . ' Kbps';
        }
        return $speedBps . ' bps';
    }
}
