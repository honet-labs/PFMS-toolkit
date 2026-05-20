<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Discovery;

use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Snmp\SnmpWalker;
use SnmpBridge\Core\Vendor\VendorCapability;

final class DiscoveryContext
{
    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed> $snmpConfig
     * @param array<int, array{ifIndex:int|null, ifName:string|null, label:string|null}> $entityMap
     */
    public function __construct(
        public readonly SnmpWalker $walker,
        public readonly VendorAdapterInterface $vendor,
        public readonly VendorCapability $capabilities,
        public readonly array $device,
        public readonly array $snmpConfig,
        public readonly array $entityMap = [],
    ) {
    }

    /**
     * @param array<int, array{ifIndex:int|null, ifName:string|null, label:string|null}> $entityMap
     */
    public function withEntityMap(array $entityMap): self
    {
        return new self(
            $this->walker,
            $this->vendor,
            $this->capabilities,
            $this->device,
            $this->snmpConfig,
            $entityMap,
        );
    }

    public function sysObjectID(): string
    {
        return (string) ($this->device['sys_object_id'] ?? '');
    }

    public function snmp(): SnmpWalker
    {
        return $this->walker;
    }
}
