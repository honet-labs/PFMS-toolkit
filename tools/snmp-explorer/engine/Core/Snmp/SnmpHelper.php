<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Snmp;

final class SnmpHelper
{
    public const string SYS_DESCR = '.1.3.6.1.2.1.1.1.0';
    public const string SYS_OBJECT_ID = '.1.3.6.1.2.1.1.2.0';
    public const string SYS_NAME = '.1.3.6.1.2.1.1.5.0';
    public const string IF_DESCR = '.1.3.6.1.2.1.2.2.1.2';
    public const string IF_NAME = '.1.3.6.1.2.1.31.1.1.1.1';
    public const string ENT_PHYSICAL_DESCR = '.1.3.6.1.2.1.47.1.1.1.1.2';
    public const string ENT_PHYSICAL_CONTAINED_IN = '.1.3.6.1.2.1.47.1.1.1.1.4';
    public const string ENT_PHYSICAL_CLASS = '.1.3.6.1.2.1.47.1.1.1.1.5';
    public const string ENT_PHYSICAL_PARENT_REL_POS = '.1.3.6.1.2.1.47.1.1.1.1.6';
    public const string ENT_PHYSICAL_NAME = '.1.3.6.1.2.1.47.1.1.1.1.7';
    public const string ENT_ALIAS_MAPPING_IDENTIFIER = '.1.3.6.1.2.1.47.1.3.2.1.2';
    public const string ENTITY_SENSOR_TYPE = '.1.3.6.1.2.1.99.1.1.1.1';
    public const string ENTITY_SENSOR_SCALE = '.1.3.6.1.2.1.99.1.1.1.2';
    public const string ENTITY_SENSOR_PRECISION = '.1.3.6.1.2.1.99.1.1.1.3';
    public const string ENTITY_SENSOR_VALUE = '.1.3.6.1.2.1.99.1.1.1.4';
    public const string ENTITY_SENSOR_STATUS = '.1.3.6.1.2.1.99.1.1.1.5';
    public const string ENTITY_SENSOR_UNITS_DISPLAY = '.1.3.6.1.2.1.99.1.1.1.6';

    public static function oidIndex(string $oid): string
    {
        $oid = trim($oid);
        $parts = explode('.', trim($oid, '.'));

        return end($parts);
    }

    public static function normalizeOid(string $oid): string
    {
        $oid = preg_replace('/^OID:\s*/i', '', trim($oid)) ?? $oid;
        
        // Map MIB names to numeric OID prefixes
        $mibMap = [
            'mib-2' => '1.3.6.1.2.1',
            'SNMPv2-SMI::mib-2' => '1.3.6.1.2.1',
            'HOST-RESOURCES-MIB::' => '',  // Handle separately with standard conversion
        ];
        
        foreach ($mibMap as $mibName => $numericPrefix) {
            if (str_starts_with($oid, $mibName)) {
                $remainder = substr($oid, strlen($mibName));
                $remainder = ltrim($remainder, ':');
                $oid = $numericPrefix . '.' . ltrim($remainder, '.');
                break;
            }
        }
        
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
