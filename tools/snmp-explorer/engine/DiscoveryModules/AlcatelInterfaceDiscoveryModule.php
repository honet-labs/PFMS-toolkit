<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Normalize\SpeedDetector;

/**
 * Alcatel/Nokia-specific Interface Discovery Module
 * 
 * Extends IF-MIB with Alcatel enterprise OIDs
 * Supports:
 * - FastEthernet/GigabitEthernet interfaces
 * - ATM interfaces
 * - Frame relay interfaces
 * - Virtual LANs
 * - Port-channels
 * 
 * MIBs: IF-MIB, ALCATEL-IND1-INTERFACE-MIB
 */
final readonly class AlcatelInterfaceDiscoveryModule implements DiscoveryModuleInterface
{
    // Standard IF-MIB
    private const string IF_DESCR = '1.3.6.1.2.1.2.2.1.2';
    private const string IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';
    private const string IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';

    public function __construct(
        private SpeedDetector $speedDetector = new SpeedDetector(),
    ) {
    }

    public function name(): string
    {
        return 'alcatel_interface_discovery';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // Support Alcatel/Nokia devices
        return str_starts_with($context->sysObjectID(), '1.3.6.1.4.1.6486');
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

                $name = in_array($ifName, [null, '', '0'], true) ? $ifDescription : trim($ifName);
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
                        'type' => 'alcatel_interface_speed',
                        'name' => "{$name} - Speed ({$speedDisplay})",
                        'oid' => $speedOid,
                        'unit' => 'bps',
                        'value' => $speed,
                        'description' => "Speed of {$name} - {$speedDisplay} ({$speedSource})",
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log("Alcatel interface discovery error: " . $e->getMessage());
        }

        return $sensors;
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
            $name = preg_replace($pattern, $replacement, (string) $name);
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
        }
        if ($speedBps >= 1000000) {
            return round($speedBps / 1000000, 2) . ' Mbps';
        }
        if ($speedBps >= 1000) {
            return round($speedBps / 1000, 2) . ' Kbps';
        }
        return $speedBps . ' bps';
    }
}
