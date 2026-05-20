<?php

declare(strict_types=1);

namespace SnmpBridge\VendorAdapter\Huawei;

use SnmpBridge\Helpers\SensorNameFormatter;
use SnmpBridge\Helpers\StringHelper;

final class HuaweiNameTranslator
{
    public function __construct(private readonly SensorNameFormatter $formatter = new SensorNameFormatter())
    {
    }

    public function opticalName(?string $ifName, ?string $label, string $metric, string $unit, string $index): string
    {
        $component = $this->component($ifName, $label, 'Huawei Optical ' . $index);

        return StringHelper::safeModuleName($this->formatter->optical($component, $this->metric($metric), $unit));
    }

    public function temperatureName(?string $ifName, ?string $label, string $context, string $unit, string $index): string
    {
        $component = $this->component($ifName, $label, 'Huawei Sensor ' . $index);

        return StringHelper::safeModuleName($this->formatter->temperature($component, $context, $unit));
    }

    public function voltageName(?string $ifName, ?string $label, string $context, string $unit, string $index): string
    {
        $component = $this->component($ifName, $label, 'Huawei Sensor ' . $index);

        return StringHelper::safeModuleName($this->formatter->voltage($component, $context, $unit));
    }

    public function currentName(?string $ifName, ?string $label, string $context, string $unit, string $index): string
    {
        $component = $this->component($ifName, $label, 'Huawei Sensor ' . $index);

        return StringHelper::safeModuleName($this->formatter->current($component, $context, $unit));
    }

    public function gponOntComponent(array $ifNames, string $index, string $fallbackPrefix): array
    {
        $parts = $this->indexParts($index);
        $ifIndex = isset($parts[0]) && ctype_digit($parts[0]) ? (int) $parts[0] : null;
        $baseName = $ifIndex !== null && isset($ifNames[(string) $ifIndex])
            ? $this->interfaceName((string) $ifNames[(string) $ifIndex])
            : $fallbackPrefix . ' ' . str_replace('.', '/', $index);

        if (count($parts) > 1) {
            $baseName .= ' ONT ' . implode('.', array_slice($parts, 1));
        }

        return [$ifIndex, $baseName];
    }

    public function interfaceName(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $name = preg_replace('/^(?:ifName|ifDescr|interface|port)\s*[:=-]\s*/i', '', $name) ?? $name;
        $name = preg_replace('/\s+/', '', $name) ?? $name;

        $replacements = [
            '/^GigabitEthernet/i' => 'GE',
            '/^XGigabitEthernet/i' => 'XGE',
            '/^TenGigabitEthernet/i' => '10GE',
            '/^TwentyFiveGigE/i' => '25GE',
            '/^FortyGigabitEthernet/i' => '40GE',
            '/^HundredGigE/i' => '100GE',
            '/^Ethernet/i' => 'ETH',
            '/^Eth-Trunk/i' => 'Eth-Trunk',
            '/^Port-channel/i' => 'Port-channel',
        ];

        foreach ($replacements as $pattern => $replacement) {
            if (preg_match($pattern, $name) === 1) {
                return preg_replace($pattern, $replacement, $name) ?? $name;
            }
        }

        return $name;
    }

    public function interfaceFromLabel(string $label): ?string
    {
        $patterns = [
            '/\b(?:GigabitEthernet|XGigabitEthernet|TenGigabitEthernet|TwentyFiveGigE|FortyGigabitEthernet|HundredGigE)\d+(?:\/\d+){1,4}\b/i',
            '/\b(?:100GE|50GE|40GE|25GE|10GE|XGE|GE|FE|ETH|GPON|EPON|PON)\d+(?:\/\d+){1,5}\b/i',
            '/\b(?:Eth-Trunk|Port-channel)\d+(?:\.\d+)?\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $label, $match) === 1) {
                return $this->interfaceName($match[0]);
            }
        }

        return null;
    }

    public function component(?string $ifName, ?string $label, string $fallback): string
    {
        $ifNameText = trim((string) $ifName);

        if (preg_match('/\b(?:ONT|ONU)\b/i', $ifNameText) === 1) {
            return preg_replace('/\s+/', ' ', $ifNameText) ?? $ifNameText;
        }

        $interface = $this->interfaceName($ifName);

        if ($interface !== null) {
            return $interface;
        }

        if ($label !== null) {
            $fromLabel = $this->interfaceFromLabel($label);

            if ($fromLabel !== null) {
                return $fromLabel;
            }

            $cleanLabel = $this->cleanLabel($label);

            if ($cleanLabel !== '') {
                return $cleanLabel;
            }
        }

        return $fallback;
    }

    public function cleanLabel(string $label): string
    {
        $label = preg_replace('/^[A-Z0-9-]+::/i', '', trim($label)) ?? $label;
        $label = str_replace(['_', '-'], ' ', $label);
        $label = preg_replace(
            '/\b(?:hw|huawei|entity|sensor|current|value|optical|module|transceiver|temperature|temp|rx|tx|power|bias|voltage)\b/i',
            '',
            $label,
        ) ?? $label;
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;
        $label = trim($label, " \t\n\r\0\x0B:.");

        return $label !== '' ? $this->formatter->humanize($label) : '';
    }

    public function metric(string $metric): string
    {
        $metric = strtolower(str_replace(['_', '-'], ' ', trim($metric)));

        return match (true) {
            str_contains($metric, 'rx at olt') || str_contains($metric, 'olt rx') => 'RX at OLT',
            str_contains($metric, 'rx') => 'RX Power',
            str_contains($metric, 'tx') && str_contains($metric, 'bias') => 'TX Bias',
            str_contains($metric, 'tx') => 'TX Power',
            str_contains($metric, 'temp') => 'Temperature',
            str_contains($metric, 'volt') => 'Voltage',
            str_contains($metric, 'bias') => 'TX Bias',
            str_contains($metric, 'current') => 'Current',
            default => $this->formatter->humanize($metric),
        };
    }

    private function indexParts(string $index): array
    {
        return array_values(array_filter(explode('.', trim($index, '.')), static fn (string $part): bool => $part !== ''));
    }
}
