<?php

declare(strict_types=1);

namespace SnmpBridge\Helpers;

final class ArrayHelper
{
    public static function get(array $array, string|int $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $array) ? $array[$key] : $default;
    }
}
