<?php

declare(strict_types=1);

namespace SnmpBridge\Helpers;

final class SnmpValueHelper
{
    private const array SNMP_TYPE_PREFIXES = [
        '/^(?:STRING|INTEGER|Gauge32|Counter32|Counter64|OID|Timeticks|TimeTicks|IpAddress|NetworkAddress|Opaque|PhysAddress|Hex-STRING):\s*/i',
    ];

    private const array SPECIAL_SNMP_ERRORS = [
        'NULL',
        'NOSUCHOBJECT',
        'NOSUCHINSTANCE',
        'NO SUCH OBJECT',
        'NO SUCH INSTANCE',
        'ENDOFMIBVIEW',
        'END OF MIB VIEW',
    ];

    public static function numeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $clean = trim((string) $value);
        $upper = strtoupper($clean);

        foreach (self::SPECIAL_SNMP_ERRORS as $errorToken) {
            if ($upper === $errorToken || str_contains($upper, $errorToken)) {
                return null;
            }
        }

        // Strip SNMP type prefixes
        foreach (self::SNMP_TYPE_PREFIXES as $pattern) {
            $clean = preg_replace($pattern, '', $clean) ?? $clean;
        }

        $clean = trim($clean, "\" \t\n\r\0\x0B");
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

    /**
     * Clean and return sanitized numeric value (float or int).
     */
    public static function cleanNumber(mixed $value): float|int|null
    {
        return self::numeric($value);
    }

    /**
     * Validate and extract numeric value with optional min/max bounds.
     * Handles special SNMP values: 'NULL', 'NOSUCHOBJECT', 'NOSUCHINSTANCE'
     *
     * @return int|null The validated numeric value, or null if invalid/out of bounds
     */
    public static function validateNumericInRange(
        mixed $value,
        ?int $minInclusive = null,
        ?int $maxInclusive = null,
    ): ?int {
        $numeric = self::integer($value);
        if ($numeric === null) {
            return null;
        }

        if ($minInclusive !== null && $numeric < $minInclusive) {
            return null;
        }

        if ($maxInclusive !== null && $numeric > $maxInclusive) {
            return null;
        }

        return $numeric;
    }

    /**
     * Validate percentage values (0-100 range).
     */
    public static function validatePercentage(mixed $value): ?int
    {
        return self::validateNumericInRange($value, 0, 100);
    }

    /**
     * Validate positive non-zero values (common for counters, sizes, etc).
     */
    public static function validatePositive(mixed $value): ?int
    {
        return self::validateNumericInRange($value, 1);
    }

    /**
     * Validate non-negative values (0 or greater).
     */
    public static function validateNonNegative(mixed $value): ?int
    {
        return self::validateNumericInRange($value, 0);
    }
}
