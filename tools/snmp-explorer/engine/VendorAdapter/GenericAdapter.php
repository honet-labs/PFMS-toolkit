<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\GenericEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

final class GenericAdapter implements VendorAdapterInterface
{
    public function name(): string
    {
        return 'Generic';
    }

    public function enterpriseOid(): string
    {
        return '';
    }

    public function sysObjectIds(): array
    {
        return [];
    }

    public function sysDescrPatterns(): array
    {
        return [];
    }

    public function capabilities(): VendorCapability
    {
        return new VendorCapability(
            supportsOpticalDom: true,
            supportsEnvironment: true,
            supportsGpon: false,
            requiresEntityMapping: false,
        );
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
