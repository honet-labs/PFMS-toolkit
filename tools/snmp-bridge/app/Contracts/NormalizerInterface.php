<?php

declare(strict_types=1);

namespace SnmpBridge\Contracts;

interface NormalizerInterface
{
    /**
     * @param array<string, mixed>|int|float|string|null $sensor
     * @return array<string, mixed>|null
     */
    public function normalize(
        array|int|float|string|null $sensor,
        mixed $scale = 'units',
        mixed $precision = 0,
        string $unit = '',
        mixed $offset = 0,
    ): ?array;
}
