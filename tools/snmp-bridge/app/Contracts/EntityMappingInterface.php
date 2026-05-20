<?php

declare(strict_types=1);

namespace SnmpBridge\Contracts;

use SnmpBridge\Core\Snmp\SnmpWalker;

interface EntityMappingInterface
{
    /**
     * @return array<int, array{ifIndex:int|null, ifName:string|null, label:string|null}>
     */
    public function buildMap(SnmpWalker $walker): array;

    /**
     * @param array<int, array{ifIndex:int|null, ifName:string|null, label:string|null}> $entityMap
     */
    public function resolveInterfaceName(int|string|null $sensorIndex, array $entityMap): ?string;

    /**
     * @param array<int, array{ifIndex:int|null, ifName:string|null, label:string|null}> $entityMap
     */
    public function resolveIfIndex(int|string|null $sensorIndex, array $entityMap): ?int;
}
