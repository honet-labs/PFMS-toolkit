<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Helpers\SnmpValueHelper;

final readonly class OltDiscoveryModule implements DiscoveryModuleInterface
{
    private const string SYS_UPTIME = '1.3.6.1.2.1.1.3.0';

    public function __construct(
        private NormalizerInterface $normalizer,
    ) {
    }

    public function name(): string
    {
        return 'olt';
    }

    public function supports(DiscoveryContext $context): bool
    {
        $sysDescr = strtolower((string) ($context->device['sys_descr'] ?? $context->device['sysDescr'] ?? ''));
        return str_contains($sysDescr, 'olt') || str_contains($sysDescr, 'gpon') || str_contains($sysDescr, 'epon') || str_contains($sysDescr, 'smartax');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        // Host Alive availability check
        $uptimeVal = $context->walker->get(self::SYS_UPTIME);
        $isAlive = $uptimeVal !== null ? 1 : 0;
        $uptimeSec = $uptimeVal !== null ? ((float) (SnmpValueHelper::cleanNumber((string) $uptimeVal) ?? 0.0)) / 100 : 0.0;

        $sensors[] = $this->normalizer->normalize([
            'name' => 'Host Alive',
            'oid' => self::SYS_UPTIME,
            'value' => (float) $isAlive,
            'type' => 'status',
            'unit' => '',
            'description' => 'OLT Host availability status (1=Alive, 0=Down)',
        ], 'olt');

        if ($uptimeVal !== null) {
            $sensors[] = $this->normalizer->normalize([
                'name' => 'System Uptime',
                'oid' => self::SYS_UPTIME,
                'value' => $uptimeSec,
                'type' => 'gauge',
                'unit' => 'seconds',
                'description' => 'OLT System Uptime in seconds',
            ], 'olt');
        }

        return array_values(array_filter($sensors));
    }
}
