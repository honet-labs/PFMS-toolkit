<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\Fortinet;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\GenericEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

final class FortinetAdapter implements VendorAdapterInterface
{
    public function name(): string
    {
        return 'Fortinet';
    }

    public function enterpriseOid(): string
    {
        return '.1.3.6.1.4.1.12356';
    }

    public function sysObjectIds(): array
    {
        return [
            '.1.3.6.1.4.1.12356',
            '.1.3.6.1.4.1.12356.101',
            '.1.3.6.1.4.1.12356.101.1',
        ];
    }

    public function sysDescrPatterns(): array
    {
        return [
            '/\bFortinet\b/i',
            '/\bFortiGate\b/i',
            '/\bFortiOS\b/i',
            '/\bFortiWiFi\b/i',
            '/\bFortiProxy\b/i',
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
