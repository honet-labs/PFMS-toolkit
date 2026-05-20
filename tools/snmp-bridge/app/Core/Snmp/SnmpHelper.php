<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Snmp;

final class SnmpHelper
{
    public const SYS_DESCR = '.1.3.6.1.2.1.1.1.0';
    public const SYS_OBJECT_ID = '.1.3.6.1.2.1.1.2.0';
    public const SYS_NAME = '.1.3.6.1.2.1.1.5.0';
    public const IF_DESCR = '.1.3.6.1.2.1.2.2.1.2';
    public const IF_NAME = '.1.3.6.1.2.1.31.1.1.1.1';
    public const ENT_PHYSICAL_DESCR = '.1.3.6.1.2.1.47.1.1.1.1.2';
    public const ENT_PHYSICAL_CLASS = '.1.3.6.1.2.1.47.1.1.1.1.5';
    public const ENT_PHYSICAL_NAME = '.1.3.6.1.2.1.47.1.1.1.1.7';
    public const ENT_ALIAS_MAPPING_IDENTIFIER = '.1.3.6.1.2.1.47.1.3.2.1.2';
    public const ENTITY_SENSOR_TYPE = '.1.3.6.1.2.1.99.1.1.1.1';
    public const ENTITY_SENSOR_SCALE = '.1.3.6.1.2.1.99.1.1.1.2';
    public const ENTITY_SENSOR_PRECISION = '.1.3.6.1.2.1.99.1.1.1.3';
    public const ENTITY_SENSOR_VALUE = '.1.3.6.1.2.1.99.1.1.1.4';
    public const ENTITY_SENSOR_STATUS = '.1.3.6.1.2.1.99.1.1.1.5';
    public const ENTITY_SENSOR_UNITS_DISPLAY = '.1.3.6.1.2.1.99.1.1.1.6';

    public static function oidIndex(string $oid): string
    {
        $oid = trim($oid);
        $parts = explode('.', trim($oid, '.'));

        return (string) end($parts);
    }

    public static function normalizeOid(string $oid): string
    {
        $oid = preg_replace('/^OID:\s*/i', '', trim($oid)) ?? $oid;
        $oid = preg_replace('/[^0-9.].*$/', '', $oid) ?? $oid;

        return '.' . trim($oid, '.');
    }

    public static function extractIfIndexFromAlias(string $alias): ?int
    {
        if (preg_match('/(?:ifIndex|ifEntry|ifName|ifDescr)?\.?(\d+)$/i', trim($alias), $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }
}
