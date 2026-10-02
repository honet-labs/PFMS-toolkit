<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Entity;

use SnmpBridge\Core\Snmp\SnmpHelper;
use SnmpBridge\Core\Snmp\SnmpWalker;
use SnmpBridge\VendorAdapter\Huawei\HuaweiNameTranslator;

final class HuaweiEntityMapper extends EntityMapper
{
    public function __construct(private readonly HuaweiNameTranslator $names = new HuaweiNameTranslator())
    {
    }

    public function buildMap(SnmpWalker $walker): array
    {
        $aliases = $walker->walkIndexed(SnmpHelper::ENT_ALIAS_MAPPING_IDENTIFIER);
        $ifNames = $this->normalizedIfNames($walker);
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

        foreach ($map as $entityIndex => $entry) {
            if ($entry['ifName'] !== null) {
                continue;
            }

            $labelInterface = $this->names->interfaceFromLabel((string) ($entry['label'] ?? ''));

            if ($labelInterface !== null) {
                $map[$entityIndex]['ifName'] = $labelInterface;
            }
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function normalizedIfNames(SnmpWalker $walker): array
    {
        $ifNames = [];

        foreach ($this->ifNameMap($walker) as $ifIndex => $ifName) {
            $ifNames[$ifIndex] = $this->names->interfaceName($ifName) ?? $ifName;
        }

        return $ifNames;
    }
}
