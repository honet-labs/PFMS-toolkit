<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Vendor;

use SnmpBridge\Contracts\VendorAdapterInterface;

final class VendorRegistry
{
    /** @var array<string, VendorAdapterInterface> */
    private array $adapters = [];

    /**
     * @param iterable<VendorAdapterInterface> $adapters
     */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(VendorAdapterInterface $adapter): void
    {
        $this->adapters[strtolower($adapter->name())] = $adapter;
    }

    /**
     * @return list<VendorAdapterInterface>
     */
    public function all(): array
    {
        return array_values($this->adapters);
    }
}
