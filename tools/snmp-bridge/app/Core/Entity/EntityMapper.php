<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Entity;

use SnmpBridge\Contracts\EntityMappingInterface;
use SnmpBridge\Core\Snmp\SnmpHelper;
use SnmpBridge\Core\Snmp\SnmpWalker;

abstract class EntityMapper implements EntityMappingInterface
{
    /**
     * @return array<int, string>
     */
    protected function ifNameMap(SnmpWalker $walker): array
    {
        $ifNames = $walker->walkIndexed(SnmpHelper::IF_NAME);

        if ($ifNames === []) {
            $ifNames = $walker->walkIndexed(SnmpHelper::IF_DESCR);
        }

        $map = [];
        foreach ($ifNames as $index => $name) {
            $map[(int) $index] = $name;
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    protected function entityLabels(SnmpWalker $walker): array
    {
        $names = $walker->walkIndexed(SnmpHelper::ENT_PHYSICAL_NAME);
        $descrs = $walker->walkIndexed(SnmpHelper::ENT_PHYSICAL_DESCR);
        $labels = [];

        foreach ($names + $descrs as $index => $_) {
            $name = trim($names[$index] ?? '');
            $descr = trim($descrs[$index] ?? '');
            $labels[(int) $index] = $name !== '' ? $name : $descr;
        }

        return $labels;
    }

    protected function firstIndex(int|string $index): int
    {
        $parts = explode('.', (string) $index);

        return (int) ($parts[0] ?? 0);
    }

    /**
     * @param array<int, array{ifIndex:int|null, ifName:string|null, label:string|null}> $entityMap
     */
    public function resolveInterfaceName(int|string|null $sensorIndex, array $entityMap): ?string
    {
        if ($sensorIndex === null || $sensorIndex === '') {
            return null;
        }

        $index = (int) $sensorIndex;

        return $entityMap[$index]['ifName'] ?? null;
    }

    /**
     * @param array<int, array{ifIndex:int|null, ifName:string|null, label:string|null}> $entityMap
     */
    public function resolveIfIndex(int|string|null $sensorIndex, array $entityMap): ?int
    {
        if ($sensorIndex === null || $sensorIndex === '') {
            return null;
        }

        $index = (int) $sensorIndex;

        return $entityMap[$index]['ifIndex'] ?? null;
    }
}
