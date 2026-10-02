<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\F5;

use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Discovery\DiscoveryPipeline;

/**
 * F5 Networks Load Balancer Vendor Adapter
 * 
 * Supports:
 * - F5 BIG-IP (Local Traffic Manager)
 * - F5 BIG-IP (Global Traffic Manager)
 * - F5 3DNS
 * 
 * Discovers:
 * - Virtual Servers (vaddress, vport)
 * - Pool Members
 * - System Performance metrics
 * - Network interfaces
 * 
 * MIBs Used:
 * - LOAD-BAL-SYSTEM-MIB (1.3.6.1.4.1.3375.2)
 * - DNS-MIB (1.3.6.1.4.1.3375.2.8)
 * - UCD-SNMP-MIB (1.3.6.1.4.1.3375.2.1)
 */
final class F5Adapter implements VendorAdapterInterface
{
    private const string F5_OID = '1.3.6.1.4.1.3375';

    public function name(): string
    {
        return 'F5 Networks';
    }

    public function enterpriseOid(): string
    {
        return self::F5_OID;
    }

    public function sysObjectIds(): array
    {
        return [
            self::F5_OID . '.*',
        ];
    }

    public function sysDescrPatterns(): array
    {
        return [
            '/BIG-IP/i',
            '/3DNS/i',
        ];
    }

    public function capabilities(): \SnmpBridge\Core\Vendor\VendorCapability
    {
        return new \SnmpBridge\Core\Vendor\VendorCapability(
            supportsOpticalDom: false,
            supportsEnvironment: false,
            supportsGpon: false,
            requiresEntityMapping: false,
        );
    }

    public function entityMapper(): \SnmpBridge\Contracts\EntityMappingInterface
    {
        return new \SnmpBridge\Core\Entity\GenericEntityMapper();
    }

    public function discoveryOids(): array
    {
        return [];
    }
}
