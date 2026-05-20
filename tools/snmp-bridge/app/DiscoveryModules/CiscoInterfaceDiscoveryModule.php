<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Normalize\SpeedDetector;

/**
 * Cisco-specific Interface Discovery Module
 * 
 * Extends IF-MIB with Cisco enterprise OIDs:
 * - CISC-INTERFACE-MIB (1.3.6.1.4.1.9.2.1)
 * - CISCO-QUEUE-MIB (1.3.6.1.4.1.9.9.75)
 * - CISCO-PROCESS-MIB (1.3.6.1.4.1.9.9.46)
 * - CISCO-SYSTEM-MIB (1.3.6.1.4.1.9.9.1)
 * 
 * Supports:
 * - FastEthernet/GigabitEthernet interfaces
 * - Port-channels (EtherChannel)
 * - VLAN interfaces
 * - ATM interfaces
 * - Serial interfaces
 * - Advanced queue statistics
 * - CRC errors, collisions, runts
 * 
 * MIBs: IF-MIB, CISCO-INTERFACE-MIB, CISCO-QUEUE-MIB
 */
final class CiscoInterfaceDiscoveryModule implements DiscoveryModuleInterface
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
    
    // Cisco-specific extensions
    private const CISCO_INTERFACE = '1.3.6.1.4.1.9.2.1.1';
    private const CISCO_QUEUE = '1.3.6.1.4.1.9.9.75';
    private const CISCO_CRC_ERRORS = '1.3.6.1.4.1.9.2.1.58.1.1.5';

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SpeedDetector $speedDetector = new SpeedDetector(),
    ) {
    }

    public function name(): string
    {
        return 'cisco_interface_discovery';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // Support Cisco devices
        return strpos($context->sysObjectID(), '1.3.6.1.4.1.9') === 0;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            // Discover interfaces
            $interfaces = $context->snmp()->walk(self::IF_DESCR);
            
            foreach ($interfaces as $ifIndex => $ifDescription) {
                $ifDescription = trim((string) $ifDescription);
                
                // Skip loopback and virtual interfaces
                if (stripos($ifDescription, 'loopback') === 0 || 
                    stripos($ifDescription, 'vlan') === 0) {
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
                        'type' => 'cisco_interface_speed',
                        'name' => "{$name} - Speed ({$speedDisplay})",
                        'oid' => $speedOid,
                        'unit' => 'bps',
                        'value' => $speed,
                        'description' => "Speed of {$name} - {$speedDisplay} ({$speedSource})",
                    ];
                }

                // Input bytes
                $ifInOctets = $context->snmp()->get(self::IF_IN_OCTETS . '.' . $ifIndex);
                if ($ifInOctets) {
                    $sensors[] = [
                        'type' => 'cisco_interface_input_bytes',
                        'name' => "{$name} - Input Octets",
                        'oid' => self::IF_IN_OCTETS . '.' . $ifIndex,
                        'unit' => 'bytes',
                        'value' => $ifInOctets,
                        'description' => "Input bytes on {$name}",
                    ];
                }

                // Output bytes
                $ifOutOctets = $context->snmp()->get(self::IF_OUT_OCTETS . '.' . $ifIndex);
                if ($ifOutOctets) {
                    $sensors[] = [
                        'type' => 'cisco_interface_output_bytes',
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
                        'type' => 'cisco_interface_input_errors',
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
                        'type' => 'cisco_interface_output_errors',
                        'name' => "{$name} - Output Errors",
                        'oid' => self::IF_OUT_ERRORS . '.' . $ifIndex,
                        'unit' => 'errors',
                        'value' => $ifOutErrors,
                    ];
                }

                // CRC errors (Cisco-specific)
                $crcErrors = $context->snmp()->get(self::CISCO_CRC_ERRORS . '.' . $ifIndex);
                if ($crcErrors && $crcErrors > 0) {
                    $sensors[] = [
                        'type' => 'cisco_interface_crc_errors',
                        'name' => "{$name} - CRC Errors",
                        'oid' => self::CISCO_CRC_ERRORS . '.' . $ifIndex,
                        'unit' => 'errors',
                        'value' => $crcErrors,
                        'description' => "CRC errors on {$name}",
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log("Cisco interface discovery error: " . $e->getMessage());
        }

        return $sensors;
    }

    private function normalizeInterfaceName(string $name): string
    {
        $name = trim($name);
        
        // Remove quotes
        $name = trim($name, "\"'");
        
        // Common Cisco patterns
        $patterns = [
            '/GigabitEthernet/i' => 'Gi',
            '/FastEthernet/i' => 'Fa',
            '/Ethernet/i' => 'Eth',
            '/Ten\s?GigabitEthernet/i' => 'Te',
            '/PortChannel/i' => 'Po',
            '/VLAN/i' => 'Vlan',
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
