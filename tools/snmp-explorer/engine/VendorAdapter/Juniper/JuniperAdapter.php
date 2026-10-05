<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\Juniper;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\GenericEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

final class JuniperAdapter implements VendorAdapterInterface
{
    public function name(): string
    {
        return 'Juniper';
    }

    public function enterpriseOid(): string
    {
        return '.1.3.6.1.4.1.2636';
    }

    public function sysObjectIds(): array
    {
        return [
            '.1.3.6.1.4.1.2636',
            '.1.3.6.1.4.1.2636.1',
        ];
    }

    public function sysDescrPatterns(): array
    {
        return [
            '/\bJuniper\b/i',
            '/\bJUNOS\b/i',
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
