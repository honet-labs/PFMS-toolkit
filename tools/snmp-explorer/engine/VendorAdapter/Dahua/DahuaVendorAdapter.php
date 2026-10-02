<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\Dahua;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\GenericEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

/**
 * Dahua CCTV Device Vendor Adapter
 * 
 * Detects Dahua DVR/NVR/IPC devices via SNMP
 * Supports both sysObjectID and sysDescr detection methods
 * 
 * Enterprise OID: 1.3.6.1.4.1.1004849 (Dahua Technologies)
 */
final class DahuaVendorAdapter implements VendorAdapterInterface
{
    /**
     * Dahua Enterprise OID root
     */
    private const string DAHUA_OID = '1.3.6.1.4.1.1004849';

    /**
     * Get vendor name
     */
    public function name(): string
    {
        return 'Dahua';
    }

    /**
     * Get enterprise OID for Dahua
     */
    public function enterpriseOid(): string
    {
        return self::DAHUA_OID;
    }

    /**
     * Get sysObjectID patterns for detection
     */
    public function sysObjectIds(): array
    {
        return [
            '1.3.6.1.4.1.1004849',
            '1.3.6.1.4.1.1004849.2',
            '1.3.6.1.4.1.1004849.2.10.1',
            '1.3.6.1.4.1.1004849.2.10.2',
            '1.3.6.1.4.1.1004849.2.10.3',
        ];
    }

    /**
     * Get sysDescr patterns for detection
     */
    public function sysDescrPatterns(): array
    {
        return [
            '/\bDahua\b/i',
            '/\bDHI-(?:NVR|XVR|DVR|IPC)[A-Z0-9-\/]*/i',
            '/\bDH-(?:NVR|XVR|DVR|IPC)[A-Z0-9-\/]*/i',
            '/\bHCVR\d+/i',
            '/\bHCNVR\d+/i',
            '/\bN\d+D\d+XS/i',
        ];
    }

    /**
     * Get vendor capabilities
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
     * Get entity mapper (generic for Dahua)
     */
    public function entityMapper(): EntityMappingInterface
    {
        return new GenericEntityMapper();
    }

    /**
     * Get vendor-specific discovery OIDs
     *
     * Dahua discovery is handled by dedicated modules
     * Returns empty array since all discovery is module-based
     */
    public function discoveryOids(): array
    {
        return [];
    }
}
