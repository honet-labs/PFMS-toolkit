<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\GenericEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

/**
 * Dynamic vendor adapter for any arbitrary or dynamically identified vendor/brand.
 * Ensures the system is fully vendor-agnostic and never locked to hardcoded adapters.
 */
final class DynamicVendorAdapter implements VendorAdapterInterface
{
    /**
     * @param string $vendorName Friendly name of the detected vendor (e.g. 'Ruijie', 'Palo Alto', 'Sophos')
     * @param string $enterpriseOid Root enterprise OID (e.g. '.1.3.6.1.4.1.4881')
     * @param list<string> $sysObjectIds Known sysObjectIDs for this vendor
     * @param list<string> $sysDescrPatterns Regex patterns matching sysDescr
     * @param VendorCapability|null $capabilities
     */
    public function __construct(
        private string $vendorName,
        private string $enterpriseOid = '',
        private array $sysObjectIds = [],
        private array $sysDescrPatterns = [],
        private ?VendorCapability $capabilities = null,
    ) {
        $this->capabilities ??= new VendorCapability(
            supportsOpticalDom: true,
            supportsEnvironment: true,
            supportsGpon: false,
            requiresEntityMapping: false,
        );
    }

    public function name(): string
    {
        return $this->vendorName;
    }

    public function enterpriseOid(): string
    {
        return $this->enterpriseOid;
    }

    public function sysObjectIds(): array
    {
        return $this->sysObjectIds !== [] ? $this->sysObjectIds : ($this->enterpriseOid !== '' ? [$this->enterpriseOid] : []);
    }

    public function sysDescrPatterns(): array
    {
        return $this->sysDescrPatterns;
    }

    public function capabilities(): VendorCapability
    {
        return $this->capabilities;
    }

    public function entityMapper(): EntityMappingInterface
    {
        return new GenericEntityMapper();
    }

    public function discoveryOids(): array
    {
        return [];
    }
}
