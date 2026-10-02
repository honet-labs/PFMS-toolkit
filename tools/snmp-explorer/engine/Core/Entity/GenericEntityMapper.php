<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Entity;

use SnmpBridge\Core\Snmp\SnmpHelper;
use SnmpBridge\Core\Snmp\SnmpWalker;

final class GenericEntityMapper extends EntityMapper
{
    public function buildMap(SnmpWalker $walker): array
    {
        $aliases = $walker->walkIndexed(SnmpHelper::ENT_ALIAS_MAPPING_IDENTIFIER);
        $ifNames = $this->ifNameMap($walker);
        $labels = $this->entityLabels($walker);
        $map = [];

        foreach ($labels as $entityIndex => $label) {
            $map[$entityIndex] = [
                'ifIndex' => null,
                'ifName' => null,
                'label' => $label,
            ];
        }

        foreach ($aliases as $entityIndex => $alias) {
            $ifIndex = SnmpHelper::extractIfIndexFromAlias($alias);
            $entityIndexInt = $this->firstIndex($entityIndex);

            $map[$entityIndexInt] = [
                'ifIndex' => $ifIndex,
                'ifName' => $ifIndex !== null ? ($ifNames[$ifIndex] ?? null) : null,
                'label' => $labels[$entityIndexInt] ?? null,
            ];
        }

        foreach ($ifNames as $ifIndex => $ifName) {
            if (isset($map[$ifIndex]) && $map[$ifIndex]['ifName'] !== null) {
                continue;
            }

            $map[$ifIndex] = [
                'ifIndex' => $ifIndex,
                'ifName' => $ifName,
                'label' => $labels[$ifIndex] ?? null,
            ];
        }

        return $map;
    }
}
