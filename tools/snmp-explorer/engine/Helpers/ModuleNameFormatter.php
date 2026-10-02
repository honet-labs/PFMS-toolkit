<?php

declare(strict_types=1);

namespace SnmpBridge\Helpers;

/**
 * Format module names following LibreNMS conventions
 * Used for provisioning to PandoraFMS
 * 
 * Naming pattern mirrors SensorNameFormatter but optimized for module names
 */
final class ModuleNameFormatter
{
    /**
     * Format optical module name
     * Example: "Gi0/0/1 RX Power"
     */
    public function optical(string $interface, string $direction): string
    {
        $direction = trim($direction);

        if (!str_contains(strtolower($direction), 'power')) {
            $direction .= ' Power';
        }

        return trim("{$interface} {$direction}");
    }

    /**
     * Format thermal module name
     * Example: "Inlet Temperature"
     */
    public function thermal(string $location): string
    {
        return "{$location} Temperature";
    }

    /**
     * Format voltage module name
     * Example: "PSU 1 Voltage"
     */
    public function voltage(string $component): string
    {
        return "{$component} Voltage";
    }

    /**
     * Format current/bias module name
     * Example: "TX Bias"
     */
    public function current(string $component): string
    {
        return "{$component} Current";
    }

    /**
     * Format fan module name
     * Example: "Fan 1 Speed"
     */
    public function fan(string $location): string
    {
        return "{$location} Speed";
    }

    /**
     * Format interface traffic module name
     * Example: "Gi0/0/1 Octets"
     */
    public function interfaceTraffic(string $interface): string
    {
        return "{$interface} Traffic";
    }

    /**
     * Format interface errors module name
     * Example: "Gi0/0/1 Errors"
     */
    public function interfaceErrors(string $interface): string
    {
        return "{$interface} Errors";
    }

    /**
     * Format interface discards module name
     * Example: "Gi0/0/1 Discards"
     */
    public function interfaceDiscards(string $interface): string
    {
        return "{$interface} Discards";
    }

    /**
     * Format CPU module name
     * Example: "CPU Usage"
     */
    public function cpu(): string
    {
        return 'CPU Usage';
    }

    /**
     * Format memory module name
     * Example: "Memory Usage"
     */
    public function memory(): string
    {
        return 'Memory Usage';
    }

    /**
     * Format system uptime module name
     * Example: "System Uptime"
     */
    public function uptime(): string
    {
        return 'System Uptime';
    }

    /**
     * Format storage module name
     * Example: "/var Disk Usage"
     */
    public function storage(string $partition): string
    {
        return "{$partition} Disk Usage";
    }

    /**
     * Format GPON module name
     * Example: "GPON 1/3/1 Power"
     */
    public function gpon(string $gponPort): string
    {
        return "{$gponPort} Power";
    }

    /**
     * Generic module name formatter
     */
    public function generic(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }
}
