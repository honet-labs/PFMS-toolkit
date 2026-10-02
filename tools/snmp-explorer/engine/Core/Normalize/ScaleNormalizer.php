<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Normalize;

final class ScaleNormalizer
{
    /** @var array<string, float> */
    private array $scaleMap = [
        '1' => 1.0E-24,
        '2' => 1.0E-21,
        '3' => 1.0E-18,
        '4' => 1.0E-15,
        '5' => 1.0E-12,
        '6' => 1.0E-9,
        '7' => 1.0E-6,
        '8' => 1.0E-3,
        '9' => 1.0,
        '10' => 1.0E3,
        '11' => 1.0E6,
        '12' => 1.0E9,
        '13' => 1.0E12,
        '14' => 1.0E15,
        '15' => 1.0E18,
        '16' => 1.0E21,
        '17' => 1.0E24,
        'yocto' => 1.0E-24,
        'zepto' => 1.0E-21,
        'atto' => 1.0E-18,
        'femto' => 1.0E-15,
        'pico' => 1.0E-12,
        'nano' => 1.0E-9,
        'micro' => 1.0E-6,
        'milli' => 1.0E-3,
        'units' => 1.0,
        'unit' => 1.0,
        'kilo' => 1.0E3,
        'mega' => 1.0E6,
        'giga' => 1.0E9,
        'tera' => 1.0E12,
        'peta' => 1.0E15,
        'exa' => 1.0E18,
        'zetta' => 1.0E21,
        'yotta' => 1.0E24,
    ];

    public function normalize(float $value, mixed $scale, mixed $precision): float
    {
        $scaleKey = strtolower(trim((string) ($scale ?? '9')));
        $factor = $this->scaleMap[$scaleKey] ?? (is_numeric($scaleKey) ? (float) $scaleKey : 1.0);
        $precisionInt = (int) ($precision ?? 0);

        return $value * $factor / (10 ** $precisionInt);
    }
}
