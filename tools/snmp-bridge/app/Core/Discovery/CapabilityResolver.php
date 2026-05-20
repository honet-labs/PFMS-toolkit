<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Discovery;

use SnmpBridge\Contracts\DiscoveryModuleInterface;

final class CapabilityResolver
{
    /**
     * @param iterable<DiscoveryModuleInterface> $modules
     * @return list<DiscoveryModuleInterface>
     */
    public function resolve(DiscoveryContext $context, iterable $modules): array
    {
        $resolved = [];

        foreach ($modules as $module) {
            if ($module->supports($context)) {
                $resolved[] = $module;
            }
        }

        return $resolved;
    }
}
