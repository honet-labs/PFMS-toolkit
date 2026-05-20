<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Normalize;

final class UnitNormalizer
{
    public function normalize(?string $unit, ?string $sensorType = null): string
    {
        $candidate = strtolower(trim((string) ($unit ?: $sensorType ?: '')));
        $candidate = str_replace(['degrees ', 'degree '], '', $candidate);

        return match (true) {
            str_contains($candidate, 'dbm') => 'dBm',
            str_contains($candidate, 'celsius'), str_contains($candidate, 'centigrade'), $candidate === 'c' => 'C',
            str_contains($candidate, 'fahrenheit'), $candidate === 'f' => 'F',
            str_contains($candidate, 'millivolt'), $candidate === 'mv' => 'mV',
            str_contains($candidate, 'volt'), $candidate === 'v' => 'V',
            str_contains($candidate, 'milliamp'), $candidate === 'ma' => 'mA',
            str_contains($candidate, 'amp'), $candidate === 'a' => 'A',
            str_contains($candidate, 'watt') => 'W',
            str_contains($candidate, 'percent'), str_contains($candidate, '%') => '%',
            str_contains($candidate, 'rpm') => 'rpm',
            str_contains($candidate, 'byte') => 'bytes',
            str_contains($candidate, 'packet') => 'packets',
            str_contains($candidate, 'meter') => 'm',
            default => trim((string) ($unit ?: $sensorType ?: '')),
        };
    }
}
