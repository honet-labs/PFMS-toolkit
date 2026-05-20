<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Discovery;

use SnmpBridge\Contracts\DiscoveryModuleInterface;

final class DiscoveryPipeline
{
    /**
     * @param list<DiscoveryModuleInterface> $modules
     */
    public function __construct(
        private readonly CapabilityResolver $capabilityResolver,
        private readonly array $modules,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function discover(DiscoveryContext $context): array
    {
        $entityMap = $context->vendor->entityMapper()->buildMap($context->walker);
        $context = $context->withEntityMap($entityMap);
        $sensors = [];

        foreach ($this->capabilityResolver->resolve($context, $this->modules) as $module) {
            foreach ($module->discover($context) as $sensor) {
                $sensors[] = $sensor + [
                    'vendor' => $context->vendor->name(),
                    'ip_address' => $context->device['ip_address'],
                ];
            }
        }

        return $sensors;
    }
}
