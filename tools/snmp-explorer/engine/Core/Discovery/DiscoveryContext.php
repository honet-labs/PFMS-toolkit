<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Discovery;

use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Snmp\SnmpWalker;
use SnmpBridge\Core\Vendor\VendorCapability;

final readonly class DiscoveryContext
{
    /**
     * @param array<string, mixed> $device
     * @param array<string, mixed> $snmpConfig
     * @param array<int, array{ifIndex:int|null, ifName:string|null, label:string|null}> $entityMap
     */
    public function __construct(
        public SnmpWalker $walker,
        public VendorAdapterInterface $vendor,
        public VendorCapability $capabilities,
        public array $device,
        public array $snmpConfig,
        public array $entityMap = [],
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
