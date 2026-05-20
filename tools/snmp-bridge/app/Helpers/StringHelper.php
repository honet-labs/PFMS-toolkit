<?php

declare(strict_types=1);

namespace SnmpBridge\Helpers;

final class StringHelper
{
    public static function safeModuleName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        $name = preg_replace('/[^\w .:\/#()%-]+/u', '_', $name) ?? $name;

        return trim($name, ' _');
    }

    public static function containsAny(string $haystack, array $needles): bool
    {
        $haystack = strtolower($haystack);

        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }
}
