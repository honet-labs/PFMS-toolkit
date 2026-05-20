<?php

declare(strict_types=1);

namespace SnmpBridge\Services;

use SnmpBridge\Helpers\ModuleNameFormatter;
use SnmpBridge\Helpers\SensorNameFormatter;
use SnmpBridge\Helpers\StringHelper;

final class SnmpNamingService
{
    public function __construct(
        private readonly SensorNameFormatter $sensorFormatter = new SensorNameFormatter(),
        private readonly ModuleNameFormatter $moduleFormatter = new ModuleNameFormatter(),
    ) {
    }

    public function formatOpticalDomSensorName(
        ?string $interfaceName,
        string $metric,
        string $unit,
        string $fallbackIndex,
        ?string $label = null,
    ): string {
        $metric = $this->normalizeMetric($metric, $label);
        $unit = $this->normalizeUnit($unit, $metric);
        $component = $this->componentName($interfaceName, $label, 'Optical Sensor ' . $fallbackIndex);

        return $this->sensorFormatter->transceiverDom($component, $metric, $unit);
    }

    public function formatEnvironmentalSensorName(
        string $label,
        string $sensorType,
        string $unit,
        string $fallbackIndex,
        ?string $interfaceName = null,
    ): string {
        $metric = $this->normalizeMetric($sensorType, $label);
        $unit = $this->normalizeUnit($unit, $metric);
        $component = $this->componentName($interfaceName, $label, 'Sensor ' . $fallbackIndex);

        return match ($metric) {
            'Temperature' => $this->sensorFormatter->temperature($component, $unit),
            'Humidity' => $this->sensorFormatter->humidity($component, $unit),
            'Speed' => $this->sensorFormatter->fan($component, $unit),
            'Voltage' => $this->sensorFormatter->voltage($component, $unit),
            'Current', 'TX Bias' => $this->sensorFormatter->current($component, $unit),
            'Power' => $this->sensorFormatter->power($component, $unit),
            default => $this->sensorFormatter->generic($component, $metric, $unit),
        };
    }

    public function formatVendorSensorName(
        ?string $interfaceName,
        string $definitionName,
        string $sensorType,
        string $unit,
        string $index,
        string $fallbackPrefix = 'Sensor',
    ): string {
        $metric = $this->normalizeMetric($sensorType !== '' ? $sensorType : $definitionName, $definitionName);
        $unit = $this->normalizeUnit($unit, $metric);
        $label = $interfaceName === null ? trim($definitionName . ' ' . $index) : $definitionName;
        $component = $this->componentName($interfaceName, $label, $fallbackPrefix . ' ' . $index);

        return $this->sensorFormatter->generic($component, $metric, $unit);
    }

    public function formatGponSensorName(
        ?string $interfaceName,
        string $definitionName,
        string $sensorType,
        string $unit,
        string $index,
    ): string {
        if ($interfaceName !== null && trim($interfaceName) !== '') {
            $metric = $this->normalizeMetric($sensorType !== '' ? $sensorType : $definitionName, $definitionName);
            $unit = $this->normalizeUnit($unit, $metric);
            $parts = array_values(array_filter(explode('.', trim($index, '.')), static fn (string $part): bool => $part !== ''));
            $component = $interfaceName;

            if (count($parts) > 1) {
                $component .= ' ONT ' . implode('.', array_slice($parts, 1));
            }

            return $this->sensorFormatter->generic($component, $metric, $unit);
        }

        $fallback = 'GPON ' . str_replace('.', '/', trim($index, '.'));

        return $this->formatVendorSensorName(
            $interfaceName,
            $definitionName,
            $sensorType,
            $unit,
            $index,
            $fallback,
        );
    }

    public function formatInterfaceMetricName(string $interfaceName, string $metric, string $unit = ''): string
    {
        return $this->sensorFormatter->interfaceMetric(
            $this->sensorFormatter->normalizeInterfaceName($interfaceName),
            $this->normalizeMetric($metric),
            $unit,
        );
    }

    public function formatSystemMetricName(string $component, string $metric, string $unit = ''): string
    {
        return $this->sensorFormatter->generic(
            $this->sensorFormatter->humanize($component),
            $this->normalizeMetric($metric),
            $this->normalizeUnit($unit, $metric),
        );
    }

    public function formatStorageSensorName(string $description, string $metric, string $unit = '%'): string
    {
        $component = trim($description) !== '' ? $description : 'Storage';

        return $this->formatSystemMetricName($component, $metric, $unit);
    }

    public function formatPandoraModuleName(array $sensor): string
    {
        $name = trim((string) ($sensor['sensor_name'] ?? ''));

        if ($name === '') {
            $name = trim((string) ($sensor['interface_name'] ?? ''));
        }

        if ($name === '') {
            $name = trim((string) ($sensor['oid'] ?? 'SNMP Sensor'));
        }

        return StringHelper::safeModuleName($name);
    }

    public function formatOpticalDomModuleName(string $interfaceName, string $metric): string
    {
        return $this->moduleFormatter->optical(
            $this->sensorFormatter->normalizeInterfaceName($interfaceName),
            $this->normalizeMetric($metric),
        );
    }

    public function normalizeUnit(string $unit, string $metric = ''): string
    {
        $candidate = strtolower(trim($unit));

        if ($candidate === '') {
            return match ($this->normalizeMetric($metric)) {
                'RX Power', 'TX Power', 'RX at OLT' => 'dBm',
                'Temperature' => 'C',
                'Voltage' => 'V',
                'Current', 'TX Bias' => 'mA',
                'Power' => 'W',
                'Humidity' => '%',
                'Speed' => 'rpm',
                default => '',
            };
        }

        return match (true) {
            str_contains($candidate, 'dbm') => 'dBm',
            $candidate === 'db' || str_contains($candidate, 'decibel') => 'dB',
            str_contains($candidate, 'celsius'), str_contains($candidate, 'centigrade'), $candidate === 'c' => 'C',
            str_contains($candidate, 'fahrenheit'), $candidate === 'f' => 'F',
            str_contains($candidate, 'milliam') || $candidate === 'ma' => 'mA',
            str_contains($candidate, 'amp') || $candidate === 'a' => 'A',
            str_contains($candidate, 'millivolt') || $candidate === 'mv' => 'mV',
            str_contains($candidate, 'volt') || $candidate === 'v' => 'V',
            str_contains($candidate, 'watt') || $candidate === 'w' => 'W',
            str_contains($candidate, 'percent') || $candidate === '%' => '%',
            str_contains($candidate, 'rpm') => 'rpm',
            str_contains($candidate, 'byte') => 'bytes',
            default => trim($unit),
        };
    }

    public function normalizeMetric(string $metric, ?string $label = null): string
    {
        $source = strtolower(str_replace(['_', '-'], ' ', trim($metric . ' ' . (string) $label)));

        return match (true) {
            str_contains($source, 'rx at olt') || str_contains($source, 'olt rx') => 'RX at OLT',
            str_contains($source, 'rx power') || preg_match('/\brx\b/', $source) === 1 || str_contains($source, 'receive power') => 'RX Power',
            str_contains($source, 'tx power') || preg_match('/\btx\b/', $source) === 1 || str_contains($source, 'transmit power') => 'TX Power',
            str_contains($source, 'bias') => 'TX Bias',
            str_contains($source, 'temp') || str_contains($source, 'celsius') || str_contains($source, 'thermal') => 'Temperature',
            str_contains($source, 'humidity') || str_contains($source, 'percentrh') => 'Humidity',
            str_contains($source, 'fan') || str_contains($source, 'rpm') => 'Speed',
            str_contains($source, 'volt') => 'Voltage',
            str_contains($source, 'current') || str_contains($source, 'ampere') => 'Current',
            str_contains($source, 'watt') || preg_match('/\bpower\b/', $source) === 1 => 'Power',
            default => $this->sensorFormatter->humanize($metric),
        };
    }

    private function componentName(?string $interfaceName, ?string $label, string $fallback): string
    {
        $interfaceName = $interfaceName !== null ? $this->sensorFormatter->normalizeInterfaceName($interfaceName) : '';

        if ($interfaceName !== '') {
            return $interfaceName;
        }

        $label = trim((string) $label);

        if ($label !== '') {
            return $this->sensorFormatter->normalizeLocationName($label);
        }

        return $fallback;
    }
}
