<?php

declare(strict_types=1);

namespace SnmpBridge\Helpers;

final class SnmpValueHelper
{
    public static function numeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $clean = trim((string) $value);
        $clean = str_replace(',', '.', $clean);

        if (preg_match('/-?\d+(?:\.\d+)?/', $clean, $match) !== 1) {
            return null;
        }

        return (float) $match[0];
    }

    public static function integer(mixed $value): ?int
    {
        $numeric = self::numeric($value);

        return $numeric === null ? null : (int) $numeric;
    }
}
