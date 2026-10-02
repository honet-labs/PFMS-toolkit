<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\Epson;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\GenericEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

final class EpsonAdapter implements VendorAdapterInterface
{
    public function name(): string
    {
        return 'Epson';
    }

    public function enterpriseOid(): string
    {
        return '.1.3.6.1.4.1.1248';
    }

    public function sysObjectIds(): array
    {
        return [
            '.1.3.6.1.4.1.1248',
        ];
    }

    public function sysDescrPatterns(): array
    {
        return [
            '/\bEPSON\b/i',
            '/EpsonNet|Epson Built-in/i',
        ];
    }

    public function capabilities(): VendorCapability
    {
        return new VendorCapability(
            supportsOpticalDom: false,
            supportsEnvironment: false,
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
