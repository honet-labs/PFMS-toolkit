<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\Mikrotik;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\GenericEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

final class MikrotikAdapter implements VendorAdapterInterface
{
    public function name(): string
    {
        return 'MikroTik';
    }

    public function enterpriseOid(): string
    {
        return '.1.3.6.1.4.1.14988';
    }

    public function sysObjectIds(): array
    {
        return [
            '.1.3.6.1.4.1.14988',
            '.1.3.6.1.4.1.14988.1',
        ];
    }

    public function sysDescrPatterns(): array
    {
        return [
            '/\bMikroTik\b/i',
            '/\bRouterOS\b/i',
        ];
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
