<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\F5;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\GenericEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

/**
 * F5 Networks BIG-IP Load Balancer Vendor Adapter
 *
 * Detects and configures discovery for F5 BIG-IP devices.
 * This adapter identifies F5 devices by their enterprise OID signature
 * and establishes capabilities for specialized discovery modules.
 *
 * Enterprise OID: 1.3.6.1.4.1.3375 (F5 Networks)
 *
 * Capabilities:
 * - LTM (Local Traffic Manager) discovery via dedicated modules
 * - Virtual server, pool, and pool member enumeration
 * - Interface statistics collection
 */
final class F5VendorAdapter implements VendorAdapterInterface
{
    /**
     * F5 Networks Enterprise OID root
     */
    private const string F5_OID = '1.3.6.1.4.1.3375';

    /**
     * Get human-readable vendor name
     */
    public function name(): string
    {
        return 'F5 Networks';
    }

    /**
     * Get F5 enterprise OID
     */
    public function enterpriseOid(): string
    {
        return self::F5_OID;
    }

    /**
     * Get sysObjectID patterns that identify F5 devices
     *
     * F5 BIG-IP devices use sysObjectID values that start with
     * the F5 enterprise OID (1.3.6.1.4.1.3375)
     *
     * @return array<string>
     */
    public function sysObjectIds(): array
    {
        return [
            // F5 BIG-IP LTM (most common)
            '.1.3.6.1.4.1.3375.2.1.3.4',
            // Generic F5 catchall - match any sysObjectID containing "3375"
            // This will be validated by discovery context
        ];
    }

    /**
     * Get sysDescr patterns that identify F5 devices
     *
     * @return array<string>
     */
    public function sysDescrPatterns(): array
    {
        return [
            '/\bF5\b/i',
            '/\bBIG-IP\b/i',
            '/\bBIG-IQ\b/i',
            '/\bAFM\b.*\bF5/i',
            '/\bLTM\b.*\bF5/i',
            '/BIG-IP.*Local Traffic Manager/i',
        ];
    }

    /**
     * Get vendor capabilities
     *
     * F5 BIG-IP supports LTM discovery which is implemented
     * via dedicated discovery modules (not vendor-specific OIDs)
     */
    public function capabilities(): VendorCapability
    {
        return new VendorCapability(
            supportsOpticalDom: false,
            supportsEnvironment: false,
            supportsGpon: false,
            requiresEntityMapping: false,
        );
    }

    /**
     * Get entity mapper (not needed for F5)
     */
    public function entityMapper(): EntityMappingInterface
    {
        return new GenericEntityMapper();
    }

    /**
     * Get vendor-specific discovery OIDs
     *
     * F5 discovery is handled by dedicated modules
     * (F5InterfaceDiscoveryModule, F5VirtualServerDiscoveryModule, etc.)
     * This method returns an empty array since all discovery
     * is module-based rather than OID-based.
     *
     * @return array<string, array<array<string, mixed>>>
     */
    public function discoveryOids(): array
    {
        return [];
    }
}
