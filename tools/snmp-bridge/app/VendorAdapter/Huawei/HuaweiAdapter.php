<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\Huawei;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\HuaweiEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

final class HuaweiAdapter implements VendorAdapterInterface
{
    public function name(): string
    {
        return 'Huawei';
    }

    public function enterpriseOid(): string
    {
        return '.1.3.6.1.4.1.2011';
    }

    public function sysObjectIds(): array
    {
        return [
            '.1.3.6.1.4.1.2011',
        ];
    }

    public function sysDescrPatterns(): array
    {
        return [
            '/\bHuawei\b/i',
            '/\bVRP\b/i',
            '/Quidway|CloudEngine|NetEngine|SmartAX|EchoLife|OptiXstar/i',
            '/\b(?:CE|S|NE|AR|USG|ME|MA|EA)\d{2,}[A-Z0-9-]*/i',
        ];
    }

    public function capabilities(): VendorCapability
    {
        return new VendorCapability(
            supportsOpticalDom: true,
            supportsEnvironment: true,
            supportsGpon: true,
            requiresEntityMapping: true,
        );
    }

    public function entityMapper(): EntityMappingInterface
    {
        return new HuaweiEntityMapper();
    }

    public function discoveryOids(): array
    {
        // Huawei vendor OIDs are handled by HuaweiOpticalDiscoveryModule to keep
        // names consistent and avoid duplicate rows in the scan result.
        return [];
    }
}
