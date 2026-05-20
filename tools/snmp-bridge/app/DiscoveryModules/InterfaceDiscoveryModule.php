<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Normalize\SpeedDetector;

/**
 * Universal IF-MIB Interface Discovery Module
 * 
 * Implements RFC 2863 Interface MIB (IF-MIB)
 * 
 * Discovered metrics:
 * - Interface speed (ifSpeed, ifHighSpeed)
 * - Interface status (ifOperStatus, ifAdminStatus)
 * - Input/Output bytes (ifInOctets, ifOutOctets)
 * - Input/Output packets (ifInUcastPkts, ifOutUcastPkts)
 * - Errors and discards (ifInErrors, ifOutErrors, ifInDiscards, ifOutDiscards)
 * - MTU and description
 * 
 * LibreNMS-style naming:
 * - "eth0 - Speed" -> Display interface speed
 * - "eth0 - Input Octets" -> Input traffic
 * - "eth0 - Output Octets" -> Output traffic
 * - "eth0 - Errors" -> Error rate
 * 
 * All standard interfaces supported: Ethernet, GigabitEthernet, FastEthernet, etc.
 * 
 * MIBs: IF-MIB (RFC 2863), IF-MIB (RFC 2096)
 */
final class InterfaceDiscoveryModule implements DiscoveryModuleInterface
{
    // Interface group OIDs (IF-MIB RFC 2863)
    private const IF_NUMBER = '1.3.6.1.2.1.2.1';
    private const IF_TABLE = '1.3.6.1.2.1.2.2.1';
    private const IF_INDEX = '1.3.6.1.2.1.2.2.1.1';
    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';
    private const IF_TYPE = '1.3.6.1.2.1.2.2.1.3';
    private const IF_MTU = '1.3.6.1.2.1.2.2.1.4';
    private const IF_SPEED = '1.3.6.1.2.1.2.2.1.5';
    private const IF_PHYS_ADDRESS = '1.3.6.1.2.1.2.2.1.6';
    private const IF_ADMIN_STATUS = '1.3.6.1.2.1.2.2.1.7';
    private const IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';
    private const IF_IN_OCTETS = '1.3.6.1.2.1.2.2.1.10';
    private const IF_IN_UCAST_PKTS = '1.3.6.1.2.1.2.2.1.11';
    private const IF_IN_NUCAST_PKTS = '1.3.6.1.2.1.2.2.1.12';
    private const IF_IN_DISCARDS = '1.3.6.1.2.1.2.2.1.13';
    private const IF_IN_ERRORS = '1.3.6.1.2.1.2.2.1.14';
    private const IF_IN_UNKNOWN_PROTOS = '1.3.6.1.2.1.2.2.1.15';
    private const IF_OUT_OCTETS = '1.3.6.1.2.1.2.2.1.16';
    private const IF_OUT_UCAST_PKTS = '1.3.6.1.2.1.2.2.1.17';
    private const IF_OUT_NUCAST_PKTS = '1.3.6.1.2.1.2.2.1.18';
    private const IF_OUT_DISCARDS = '1.3.6.1.2.1.2.2.1.19';
    private const IF_OUT_ERRORS = '1.3.6.1.2.1.2.2.1.20';
    private const IF_OUT_QLEN = '1.3.6.1.2.1.2.2.1.21';
    
    // Interface extensions (IF-MIB RFC 2096)
    private const IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';
    private const IF_HIGH_SPEED = '1.3.6.1.2.1.31.1.1.1.15';
    private const IF_HIGH_IN_OCTETS = '1.3.6.1.2.1.31.1.1.1.6';
    private const IF_HIGH_OUT_OCTETS = '1.3.6.1.2.1.31.1.1.1.10';
    
    // Interface types
    private const INTERFACE_TYPES = [
        1 => 'other',
        6 => 'ethernetCsmacd',
        24 => 'softwareLoopback',
        37 => 'atm',
        53 => 'propVirtual',
        54 => 'ppp',
        55 => 'softwareLoopback',
        117 => 'gigabitEthernet',
        131 => 'tunnel',
        161 => 'vlan',
        200 => 'gige',
        201 => 'bgx',
    ];
    
    // Operational status
    private const OPER_STATUS = [
        1 => 'up',
        2 => 'down',
        3 => 'testing',
    ];

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SpeedDetector $speedDetector = new SpeedDetector(),
    ) {
    }

    public function name(): string
    {
        return 'interface_discovery';
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
            // Get total number of interfaces
            $ifCount = $context->snmp()->get(self::IF_NUMBER);
            
            if (!$ifCount || $ifCount <= 0) {
                return [];
            }

            // Walk interface descriptions
            $ifDescriptions = $context->snmp()->walk(self::IF_DESCR);
            
            foreach ($ifDescriptions as $ifIndex => $ifDescription) {
                $ifDescription = trim((string) $ifDescription);
                
                // Skip loopback and virtual interfaces
                if ($this->isIgnoredInterface($ifDescription)) {
                    continue;
                }

                // Get interface details
                $ifName = $context->snmp()->get(self::IF_NAME . '.' . $ifIndex);
                $ifType = $context->snmp()->get(self::IF_TYPE . '.' . $ifIndex);
                $ifMtu = $context->snmp()->get(self::IF_MTU . '.' . $ifIndex);
                $ifAdminStatus = $context->snmp()->get(self::IF_ADMIN_STATUS . '.' . $ifIndex);
                $ifOperStatus = $context->snmp()->get(self::IF_OPER_STATUS . '.' . $ifIndex);
                
                // Speed detection using reusable SpeedDetector
                $speedResult = $this->speedDetector->detect($context, $ifIndex);
                $speed = $speedResult['speed'];
                $speedOid = $speedResult['oid'];
                $speedSource = $speedResult['source'];
                
                // Interface naming (LibreNMS style)
                $ifLabel = $this->getNormalizedName($ifName, $ifDescription);
                $operStatus = self::OPER_STATUS[$ifOperStatus] ?? 'unknown';

                // Speed sensor with interface name and speed value
                if ($speed && $speed > 0) {
                    // Format speed for display (convert bps to Mbps if > 1M)
                    $speedDisplay = $this->formatSpeed($speed);
                    
                    $sensors[] = [
                        'type' => 'interface_speed',
                        'name' => "{$ifLabel} - Speed ({$speedDisplay})",
                        'oid' => $speedOid,
                        'unit' => 'bps',
                        'value' => $speed,
                        'description' => "Speed of {$ifLabel} - {$speedDisplay} ({$speedSource})",
                    ];
                }


                // Input bytes
                $ifInOctets = $context->snmp()->get(self::IF_HIGH_IN_OCTETS . '.' . $ifIndex);
                if (!$ifInOctets) {
                    $ifInOctets = $context->snmp()->get(self::IF_IN_OCTETS . '.' . $ifIndex);
                }
                
                if ($ifInOctets) {
                    $sensors[] = [
                        'type' => 'interface_input_bytes',
                        'name' => "{$ifLabel} - Input Octets",
                        'oid' => self::IF_IN_OCTETS . ".{$ifIndex}",
                        'unit' => 'bytes',
                        'value' => $ifInOctets,
                        'description' => "Input bytes on {$ifLabel}",
                    ];
                }

                // Output bytes
                $ifOutOctets = $context->snmp()->get(self::IF_HIGH_OUT_OCTETS . '.' . $ifIndex);
                if (!$ifOutOctets) {
                    $ifOutOctets = $context->snmp()->get(self::IF_OUT_OCTETS . '.' . $ifIndex);
                }
                
                if ($ifOutOctets) {
                    $sensors[] = [
                        'type' => 'interface_output_bytes',
                        'name' => "{$ifLabel} - Output Octets",
                        'oid' => self::IF_OUT_OCTETS . ".{$ifIndex}",
                        'unit' => 'bytes',
                        'value' => $ifOutOctets,
                        'description' => "Output bytes on {$ifLabel}",
                    ];
                }

                // Input errors
                $ifInErrors = $context->snmp()->get(self::IF_IN_ERRORS . '.' . $ifIndex);
                if ($ifInErrors && $ifInErrors > 0) {
                    $sensors[] = [
                        'type' => 'interface_input_errors',
                        'name' => "{$ifLabel} - Input Errors",
                        'oid' => self::IF_IN_ERRORS . ".{$ifIndex}",
                        'unit' => 'errors',
                        'value' => $ifInErrors,
                        'description' => "Input errors on {$ifLabel}",
                    ];
                }

                // Output errors
                $ifOutErrors = $context->snmp()->get(self::IF_OUT_ERRORS . '.' . $ifIndex);
                if ($ifOutErrors && $ifOutErrors > 0) {
                    $sensors[] = [
                        'type' => 'interface_output_errors',
                        'name' => "{$ifLabel} - Output Errors",
                        'oid' => self::IF_OUT_ERRORS . ".{$ifIndex}",
                        'unit' => 'errors',
                        'value' => $ifOutErrors,
                        'description' => "Output errors on {$ifLabel}",
                    ];
                }

                // Input discards
                $ifInDiscards = $context->snmp()->get(self::IF_IN_DISCARDS . '.' . $ifIndex);
                if ($ifInDiscards && $ifInDiscards > 0) {
                    $sensors[] = [
                        'type' => 'interface_input_discards',
                        'name' => "{$ifLabel} - Input Discards",
                        'oid' => self::IF_IN_DISCARDS . ".{$ifIndex}",
                        'unit' => 'packets',
                        'value' => $ifInDiscards,
                        'description' => "Input discards on {$ifLabel}",
                    ];
                }

                // Output discards
                $ifOutDiscards = $context->snmp()->get(self::IF_OUT_DISCARDS . '.' . $ifIndex);
                if ($ifOutDiscards && $ifOutDiscards > 0) {
                    $sensors[] = [
                        'type' => 'interface_output_discards',
                        'name' => "{$ifLabel} - Output Discards",
                        'oid' => self::IF_OUT_DISCARDS . ".{$ifIndex}",
                        'unit' => 'packets',
                        'value' => $ifOutDiscards,
                        'description' => "Output discards on {$ifLabel}",
                    ];
                }
            }
        } catch (\Exception $e) {
            error_log("Interface discovery error: " . $e->getMessage());
        }

        return $sensors;
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
     * Converts descriptions to readable names
     */
    private function getNormalizedName(?string $ifName, string $ifDescription): string
    {
        // Prefer ifName if available
        if ($ifName) {
            $name = trim((string) $ifName);
            // Remove quotes if present
            $name = trim($name, "\"'");
            if (!empty($name) && $name !== '0') {
                return $name;
            }
        }

        // Clean up description
        $name = trim($ifDescription);
        
        // Common patterns
        $patterns = [
            '/^.*?\s*(\d+(?:/\d+)?)\s*$/' => '$1',
            '/^(GigabitEthernet|Ethernet|FastEthernet|eth|ge|gig)[\s\-]*(\d+(?:/\d+)?)/i' => 'ge$2',
            '/^(Management|Mgmt)[\s\-]*(\d+)?/i' => 'mgmt',
            '/^(LoopBack|Loopback|Loop|lo)[\s\-]*(\d+)?/i' => 'lo',
            '/^(VLAN|vlan)[\s\-]*(\d+)/i' => 'vlan$2',
            '/^(Tunnel|tun)[\s\-]*(\d+)?/i' => 'tun',
            '/^(Serial|ser)[\s\-]*(\d+)/i' => 'ser$2',
            '/^(ATM)[\s\-]*(\d+)/i' => 'atm$2',
        ];

        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $name, $matches)) {
                return preg_replace($pattern, $replacement, $name);
            }
        }

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
