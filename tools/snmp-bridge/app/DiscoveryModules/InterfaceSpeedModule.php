<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Normalize\SpeedDetector;

/**
 * Dedicated Interface Speed Detection Module
 * 
 * Focuses ONLY on interface speeds using dual OID detection:
 * - ifSpeed (OID 1.3.6.1.2.1.2.2.1.5) - bps
 * - ifHighSpeed (OID 1.3.6.1.2.1.31.1.1.1.15) - Mbps
 * 
 * Priority:
 * 1. ifHighSpeed (RFC 2096) - Preferred, Mbps → bps conversion
 * 2. ifSpeed (RFC 2863) - Fallback, direct bps
 * 
 * All vendors supported via standard IF-MIB
 * 
 * MIBs: IF-MIB (RFC 2863, RFC 2096)
 */
final class InterfaceSpeedModule implements DiscoveryModuleInterface
{
    // IF-MIB OIDs
    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';
    private const IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';
    private const IF_SPEED = '1.3.6.1.2.1.2.2.1.5';
    private const IF_HIGH_SPEED = '1.3.6.1.2.1.31.1.1.1.15';

    public function __construct(
        private readonly SpeedDetector $speedDetector = new SpeedDetector(),
    ) {
    }

    public function name(): string
    {
        return 'interface_speed_module';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // All vendors support standard IF-MIB
        return true;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            // Get all interfaces
            $ifDescriptions = $context->snmp()->walk(self::IF_DESCR);
            
            if (empty($ifDescriptions)) {
                return [];
            }

            foreach ($ifDescriptions as $oid => $ifDescription) {
                try {
                    $ifDescription = trim((string) $ifDescription);
                    
                    // Skip loopback and virtual interfaces
                    if ($this->isIgnoredInterface($ifDescription)) {
                        continue;
                    }

                    // Extract interface index from OID (e.g., ".1.3.6.1.2.1.2.2.1.2.1" -> "1")
                    $ifIndex = $this->extractIfIndex($oid);
                    if (empty($ifIndex)) {
                        continue;
                    }

                    // Get interface name with multiple fallbacks
                    $ifName = $context->snmp()->get(self::IF_NAME . '.' . $ifIndex);
                    $interfaceName = $this->getNormalizedName($ifName, $ifDescription);

                    // Detect speed using SpeedDetector
                    $speedResult = $this->speedDetector->detect($context, $ifIndex);
                    
                    // Log raw values for debugging complex scenarios
                    if (!empty($speedResult['raw_values'])) {
                        $debugLog = "Speed detection for ifIndex {$ifIndex} ({$interfaceName}): ";
                        if ($speedResult['raw_values']['ifHighSpeed_raw'] !== null) {
                            $debugLog .= "ifHighSpeed={$speedResult['raw_values']['ifHighSpeed_raw']} Mbps, ";
                        }
                        if ($speedResult['raw_values']['ifSpeed_raw'] !== null) {
                            $debugLog .= "ifSpeed={$speedResult['raw_values']['ifSpeed_raw']} bps";
                        }
                        if (!empty($speedResult['source'])) {
                            $debugLog .= ", source={$speedResult['source']}";
                        }
                        error_log($debugLog);
                    }

                    // Only create sensor if speed is available
                    if ($speedResult['speed'] > 0 && !empty($speedResult['oid'])) {
                        $speedDisplay = $this->formatSpeed($speedResult['speed']);
                        $sensorName = $this->buildSensorName($interfaceName, $speedDisplay);
                        
                        // Create sensor with normalized structure for database
                        $sensors[] = [
                            'sensor_class' => 'interface',
                            'sensor_name' => $sensorName,
                            'sensor_type' => 'interface_speed',
                            'interface_index' => (int) $ifIndex,
                            'interface_name' => $interfaceName,
                            'entity_index' => null,
                            'oid' => $speedResult['oid'],
                            'raw_value' => (string) $speedResult['speed'],
                            'normalized_value' => (string) $speedResult['speed'],
                            'unit' => 'bps',
                            'scale' => null,
                            'precision' => null,
                            'status' => 'ok',
                            'metadata' => [
                                'interface_name' => $interfaceName,
                                'if_index' => $ifIndex,
                                'speed_display' => $speedDisplay,
                                'source' => $speedResult['source'],
                                'description' => "Interface: {$interfaceName} | Speed: {$speedDisplay} | Source: {$speedResult['source']}",
                            ],
                        ];
                    }
                } catch (\Exception $e) {
                    // Log but continue to next interface
                    error_log("Error detecting speed for interface {$ifIndex}: " . $e->getMessage());
                    continue;
                }
            }
        } catch (\Exception $e) {
            error_log("Interface speed detection error: " . $e->getMessage());
            // Return empty array, continue with other modules
            return [];
        }

        return $sensors;
    }

    /**
     * Extract interface index from OID path
     * e.g., ".1.3.6.1.2.1.2.2.1.2.1" -> "1"
     */
    private function extractIfIndex(string $oid): ?string
    {
        // Remove leading dot if present
        $oid = ltrim($oid, '.');
        
        // Split by dots and get the last part (the interface index)
        $parts = explode('.', $oid);
        $index = end($parts);
        
        return !empty($index) && is_numeric($index) ? $index : null;
    }

    /**
     * Build sensor name based on interface type and speed
     */
    private function buildSensorName(string $interfaceName, string $speedDisplay): string
    {
        // Clean up interface name for better display
        $displayName = $this->cleanInterfaceName($interfaceName);
        
        // Format: "InterfaceName - Speed (Gbps)" or similar
        return "{$displayName} - Speed ({$speedDisplay})";
    }

    /**
     * Clean interface name for display (remove prefixes, normalize)
     */
    private function cleanInterfaceName(string $name): string
    {
        // Already pretty short names - just ensure they're clean
        $name = trim($name);
        $name = trim($name, "'\"");
        
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

    /**
     * Get normalized interface name using LibreNMS style
     * Priority: ifName > ifDescr with normalization
     */
    private function getNormalizedName(?string $ifName, string $ifDescription): string
    {
        // Priority 1: Use ifName if available and non-empty
        if ($ifName) {
            $name = trim((string) $ifName);
            $name = trim($name, "\"'");
            if (!empty($name) && $name !== '0' && strlen($name) > 0) {
                return $this->normalizeInterfaceName($name);
            }
        }

        // Priority 2: Use ifDescription with normalization
        $name = trim($ifDescription);
        return $this->normalizeInterfaceName($name);
    }

    /**
     * Normalize interface name to standard format
     */
    private function normalizeInterfaceName(string $name): string
    {
        $name = trim($name);
        
        // Pattern matching for common vendors and formats
        $patterns = [
            // Cisco GigabitEthernet
            '/^GigabitEthernet[\s\-]*(\d+(?:\/\d+)*)/i' => 'gi$1',
            '/^FastEthernet[\s\-]*(\d+(?:\/\d+)*)/i' => 'fa$1',
            '/^Ethernet[\s\-]*(\d+(?:\/\d+)*)/i' => 'eth$1',
            
            // Huawei/ZTE Ethernet
            '/^Eth[\s\-]*(\d+(?:\/\d+)*)/i' => 'eth$1',
            
            // Linux eth, ge, etc (already short form)
            '/^(eth|ge|enp|ens|em)[\s\-]*(\d+(?:\/\d+)*)/i' => '$1$2',
            
            // VLAN
            '/^(VLAN|vlan)[\s\-]*(\d+)/i' => 'vlan$2',
            
            // Loopback (shouldn't reach here but just in case)
            '/^(LoopBack|Loopback|Loop|lo)[\s\-]*(\d+)?/i' => 'lo',
            
            // Management
            '/^(Management|Mgmt)[\s\-]*(\d+)?/i' => 'mgmt',
            
            // Serial
            '/^(Serial|ser)[\s\-]*(\d+)/i' => 'ser$2',
            
            // ATM
            '/^(ATM|atm)[\s\-]*(\d+)/i' => 'atm$2',
            
            // Tunnel
            '/^(Tunnel|tun)[\s\-]*(\d+)?/i' => 'tun',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $name, $matches)) {
                return preg_replace($pattern, $replacement, $name);
            }
        }

        // If no pattern matches, return as-is but trimmed
        return $name;
    }

    /**
     * Check if interface should be ignored
     */
    private function isIgnoredInterface(string $description): bool
    {
        $ignored = [
            'loopback',
            'lo0',
            'null',
            'virtual',
            'tunnel',
            'ppp',
            'async',
            'group-async',
            'ipsec',
            'virbr',
            'vnet',
            'br-',
            'docker',
        ];

        $lowerDesc = strtolower($description);

        foreach ($ignored as $pattern) {
            if (stripos($lowerDesc, $pattern) === 0) {
                return true;
            }
        }

        return false;
    }
}
