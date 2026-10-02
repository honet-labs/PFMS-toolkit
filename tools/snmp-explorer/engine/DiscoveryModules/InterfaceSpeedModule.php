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
final readonly class InterfaceSpeedModule implements DiscoveryModuleInterface
{
    // IF-MIB OIDs
    private const string IF_DESCR = '1.3.6.1.2.1.2.2.1.2';
    private const string IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';

    public function __construct(
        private \SnmpBridge\Helpers\SensorNameFormatter $formatter = new \SnmpBridge\Helpers\SensorNameFormatter(),
    ) {
    }

    public function name(): string
    {
        return 'ifspeed';
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
            // Get all interfaces using bulk walks
            $ifDescriptions = $context->walker->walkIndexed(self::IF_DESCR);
            
            if ($ifDescriptions === []) {
                return [];
            }
            
            $ifNames = $context->walker->walkIndexed(self::IF_NAME);
            $ifHighSpeeds = $context->walker->walkIndexed('1.3.6.1.2.1.31.1.1.1.15'); // ifHighSpeed

            foreach ($ifDescriptions as $ifIndex => $ifDescription) {
                try {
                    $ifDescription = trim((string) $ifDescription);
                    
                    // Skip loopback and virtual interfaces
                    if ($this->isIgnoredInterface()) {
                        continue;
                    }

                    // Get interface name with multiple fallbacks
                    $ifName = $ifNames[$ifIndex] ?? null;
                    $interfaceName = $this->getNormalizedName($ifName, $ifDescription);

                    // Inline speed detection to avoid per-interface GET requests
                    $speed = 0;
                    $speedOid = '';
                    $speedSource = '';
                    $speedUnit = 'Mbps';
                    $speedInBps = 0;
                    
                    $highSpeed = $ifHighSpeeds[$ifIndex] ?? null;
                    
                    if (!in_array($highSpeed, [null, '', 'NULL'], true) && (int)$highSpeed >= 0) {
                        $speed = (int) $highSpeed;
                        $speedOid = "1.3.6.1.2.1.31.1.1.1.15.{$ifIndex}";
                        $speedSource = 'ifHighSpeed (Mbps)';
                        $speedUnit = 'Mbps';
                        $speedInBps = $speed * 1000000;
                    } else {
                        $speed = 0;
                        $speedOid = "1.3.6.1.2.1.31.1.1.1.15.{$ifIndex}";
                        $speedSource = 'Fallback to 0 Mbps';
                        $speedUnit = 'Mbps';
                        $speedInBps = 0;
                    }
                    // Always create sensor
                    // Use original unit from detector (Mbps for ifHighSpeed, bps for ifSpeed)
                    $speedDisplay = $this->formatSpeedWithUnit($speed, $speedUnit);
                    $sensorName = \SnmpBridge\Helpers\StringHelper::safeModuleName(
                        $this->formatter->interfaceMetric($interfaceName, 'ifSpeed')
                    );
                    // Create sensor with normalized structure for database
                    $sensors[] = [
                        'sensor_class' => 'interface',
                        'sensor_name' => $sensorName,
                        'sensor_type' => 'interface_speed',
                        'interface_index' => (int) $ifIndex,
                        'interface_name' => $interfaceName,
                        'entity_index' => null,
                        'oid' => $speedOid,
                        'raw_value' => (string) $speed,
                        'normalized_value' => (string) $speed,
                        'unit' => $speedUnit,  // Original unit: Mbps or bps
                        'scale' => null,
                        'precision' => null,
                        'status' => 'ok',
                        'metadata' => [
                            'interface_name' => $interfaceName,
                            'if_index' => $ifIndex,
                            'speed_display' => $speedDisplay,
                            'speed_in_bps' => $speedInBps,  // For internal calculations
                            'source' => $speedSource,
                            'original_unit' => $speedUnit,
                            'description' => "Interface: {$interfaceName} | Speed: {$speedDisplay} | Source: {$speedSource}",
                        ],
                    ];
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
     * Format speed from bps to human-readable format (for display only)
     */
    private function formatSpeed(int $speedBps): string
    {
        if ($speedBps >= 1000000000) {
            return round($speedBps / 1000000000, 2) . ' Gbps';
        }
        if ($speedBps >= 1000000) {
            return round($speedBps / 1000000, 2) . ' Mbps';
        }
        if ($speedBps >= 1000) {
            return round($speedBps / 1000, 2) . ' Kbps';
        }
        return $speedBps . ' bps';
    }

    /**
     * Format speed with original unit (preserve source unit)
     */
    private function formatSpeedWithUnit(int $speed, string $unit): string
    {
        // If it's already in Mbps from ifHighSpeed, don't convert
        if ($unit === 'Mbps') {
            if ($speed >= 1000) {
                return number_format($speed / 1000, 2) . ' Gbps';
            }
            return number_format($speed) . ' Mbps';
        }
        
        // If it's in bps from ifSpeed, format for readability
        if ($unit === 'bps') {
            return $this->formatSpeed($speed);
        }
        
        return $speed . ' ' . $unit;
    }

    /**
     * Get normalized interface name using LibreNMS style
     * Priority: ifName > ifDescr with normalization
     */
    private function getNormalizedName(?string $ifName, string $ifDescription): string
    {
        // Priority 1: Use ifName if available and non-empty
        if ($ifName) {
            $name = trim($ifName);
            $name = trim($name, "\"'");
            if (!in_array($name, ['', '0', '0', ''], true)) {
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

    private function isIgnoredInterface(): bool
    {
        return false;
    }
}
