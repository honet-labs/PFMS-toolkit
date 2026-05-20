<?php

declare(strict_types=1);

namespace SnmpBridge\Contracts;

use SnmpBridge\Core\Vendor\VendorCapability;

interface VendorAdapterInterface
{
    public function name(): string;

    public function enterpriseOid(): string;

    /**
     * @return list<string>
     */
    public function sysObjectIds(): array;

    /**
     * @return list<string>
     */
    public function sysDescrPatterns(): array;

    public function capabilities(): VendorCapability;

    public function entityMapper(): EntityMappingInterface;

    /**
     * Vendor-specific discovery OID groups. Standards-based discovery modules can ignore this.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function discoveryOids(): array;
}
