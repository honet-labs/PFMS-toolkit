<?php

declare(strict_types=1);

namespace SnmpBridge\Contracts;

use SnmpBridge\Core\Discovery\DiscoveryContext;

interface DiscoveryModuleInterface
{
    public function name(): string;

    public function supports(DiscoveryContext $context): bool;

    /**
     * @return list<array<string, mixed>>
     */
    public function discover(DiscoveryContext $context): array;
}
