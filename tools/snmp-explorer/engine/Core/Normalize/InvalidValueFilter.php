<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Normalize;

final class InvalidValueFilter
{
    /** @var list<float> */
    private array $invalidValues = [
        2147483647.0,
        -2147483648.0,
        4294967295.0,
        -65535.0,
        999999.0,
    ];

    public function isValid(?float $value): bool
    {
        if ($value === null || is_nan($value) || is_infinite($value)) {
            return false;
        }

        foreach ($this->invalidValues as $invalidValue) {
            if (abs($value - $invalidValue) < 0.000001) {
                return false;
            }
        }

        return true;
    }
}
