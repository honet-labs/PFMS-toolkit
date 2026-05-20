<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Entity\GenericEntityMapper;
use SnmpBridge\Core\Entity\HuaweiEntityMapper;
use SnmpBridge\Core\Entity\RaisecomEntityMapper;
use SnmpBridge\Core\Vendor\VendorCapability;

final readonly class DatabaseVendorAdapter implements VendorAdapterInterface
{
    /**
     * @param list<string> $sysObjectIds
     * @param list<string> $sysDescrPatterns
     * @param array<string, array<int, array<string, mixed>>> $discoveryOids
     */
    public function __construct(
        private string $name,
        private string $enterpriseOid,
        private array $sysObjectIds,
        private array $sysDescrPatterns,
        private VendorCapability $capabilities,
        private string $entityMappingStrategy,
        private array $discoveryOids,
        private ?string $modelName = null,
    ) {
    }

    public function name(): string
    {
        return $this->modelName === null
            ? $this->name
            : sprintf('%s %s', $this->name, $this->modelName);
    }

    public function enterpriseOid(): string
    {
        return $this->enterpriseOid;
    }

    public function sysObjectIds(): array
    {
        return $this->sysObjectIds;
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
        return match ($this->entityMappingStrategy) {
            'huawei' => new HuaweiEntityMapper(),
            'raisecom' => new RaisecomEntityMapper(),
            default => new GenericEntityMapper(),
        };
    }

    public function discoveryOids(): array
    {
        return $this->discoveryOids;
    }
}
