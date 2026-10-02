<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Entity;

use SnmpBridge\Core\Snmp\SnmpHelper;
use SnmpBridge\Core\Snmp\SnmpWalker;

final class RaisecomEntityMapper extends EntityMapper
{
    public function buildMap(SnmpWalker $walker): array
    {
        $aliases = $walker->walkIndexed(SnmpHelper::ENT_ALIAS_MAPPING_IDENTIFIER);
        $ifNames = $this->ifNameMap($walker);
        $labels = $this->entityLabels($walker);
        $map = [];

        foreach ($labels as $entityIndex => $label) {
            $entityIndexInt = (int) $entityIndex;
            $directIfName = $ifNames[$entityIndexInt] ?? null;

            $map[$entityIndexInt] = [
                'ifIndex' => $directIfName !== null ? $entityIndexInt : null,
                'ifName' => $directIfName,
                'label' => $label,
            ];
        }

        foreach ($aliases as $entityIndex => $alias) {
            $ifIndex = SnmpHelper::extractIfIndexFromAlias($alias);
            $entityIndexInt = $this->firstIndex($entityIndex);

            if ($ifIndex !== null) {
                $map[$entityIndexInt] = [
                    'ifIndex' => $ifIndex,
                    'ifName' => $ifNames[$ifIndex] ?? null,
                    'label' => $labels[$entityIndexInt] ?? null,
                ];
            }
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

            $resolved = $this->resolveFromLabel((string) ($entry['label'] ?? ''), $ifNames);

            if ($resolved !== null) {
                $map[$entityIndex]['ifIndex'] = $resolved['ifIndex'];
                $map[$entityIndex]['ifName'] = $resolved['ifName'];
            }
        }

        return $map;
    }

    /**
     * @param array<int, string> $ifNames
     * @return array{ifIndex:int, ifName:string}|null
     */
    private function resolveFromLabel(string $label, array $ifNames): ?array
    {
        $label = strtolower($label);

        foreach ($ifNames as $ifIndex => $ifName) {
            if ($ifName !== '' && str_contains($label, strtolower($ifName))) {
                return ['ifIndex' => $ifIndex, 'ifName' => $ifName];
            }
        }

        if (preg_match('/(?:ge|xge|pon|gpon|eth|port)[\s-]*((?:\d+\/){1,3}\d+)/i', $label, $match) === 1) {
            $suffix = $match[1];

            foreach ($ifNames as $ifIndex => $ifName) {
                if (str_ends_with(strtolower($ifName), strtolower($suffix))) {
                    return ['ifIndex' => $ifIndex, 'ifName' => $ifName];
                }
            }
        }

        return null;
    }
}
