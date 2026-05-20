<?php

declare(strict_types=1);

namespace SnmpBridge\Helpers;

final class SensorNameFormatter
{
    public function optical(string $interface, string $metric, string $unit = 'dBm'): string
    {
        return $this->withUnit($this->joinComponentMetric($interface, $metric), $unit);
    }

    public function temperature(string $location, string $contextOrUnit = 'C', ?string $unit = null): string
    {
        return $this->typedMetric($location, $contextOrUnit, $unit, 'Temperature', 'C');
    }

    public function voltage(string $component, string $contextOrUnit = 'V', ?string $unit = null): string
    {
        return $this->typedMetric($component, $contextOrUnit, $unit, 'Voltage', 'V');
    }

    public function current(string $component, string $contextOrUnit = 'A', ?string $unit = null): string
    {
        return $this->typedMetric($component, $contextOrUnit, $unit, 'Current', 'A');
    }

    public function fan(string $location, string $unit = 'rpm'): string
    {
        return $this->withUnit($this->appendMetric($location, 'Speed'), $unit);
    }

    public function humidity(string $location, string $unit = '%'): string
    {
        return $this->withUnit($this->appendMetric($location, 'Humidity'), $unit);
    }

    public function power(string $component, string $contextOrUnit = 'W', ?string $unit = null): string
    {
        return $this->typedMetric($component, $contextOrUnit, $unit, 'Power', 'W');
    }

    public function interfaceMetric(string $interface, string $metric, string $unit = ''): string
    {
        return $this->withUnit($this->joinComponentMetric($interface, $metric), $unit);
    }

    public function systemMetric(string $component, string $metricOrUnit = '', ?string $unit = null): string
    {
        if ($unit !== null) {
            return $this->generic($component, $metricOrUnit, $unit);
        }

        return $this->withUnit($component, $metricOrUnit);
    }

    public function memory(string $type, string $unit = '%'): string
    {
        return $this->generic('Memory', $type, $unit);
    }

    public function cpu(string $type = 'Usage', string $unit = '%'): string
    {
        return $this->generic('CPU', $type, $unit);
    }

    public function cardTemperature(string $cardName, string $unit = 'C'): string
    {
        return $this->temperature($cardName, $unit);
    }

    public function transceiverDom(string $transceiver, string $parameter, string $unit = ''): string
    {
        return $this->withUnit($this->joinComponentMetric($transceiver, $parameter), $unit);
    }

    public function gponPower(string $gponPort, string $direction, string $unit = 'dBm'): string
    {
        return $this->optical($gponPort, $this->normalizeMetric($direction . ' Power'), $unit);
    }

    public function generic(string $component, string $metric, string $unit = ''): string
    {
        return $this->withUnit($this->joinComponentMetric($component, $metric), $unit);
    }

    public function normalizeInterfaceName(string $vendorName, string $vendor = 'generic'): string
    {
        $name = trim($vendorName);
        $name = preg_replace('/^(?:ifName|ifDescr|interface|port)\s*[:=-]\s*/i', '', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = str_replace(['Ethernet ', 'GigabitEthernet '], ['Ethernet', 'GigabitEthernet'], $name);

        return trim($name);
    }

    public function normalizeLocationName(string $location): string
    {
        $name = $this->humanize($location);
        $name = preg_replace('/\b(?:temperature|temp|thermal|sensor|value|current value)\b/i', '', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name) !== '' ? trim($name) : 'Sensor';
    }

    public function validate(string $sensorName): bool
    {
        if (preg_match('/\(.+\)$/', $sensorName) === 1) {
            return true;
        }

        foreach (['Status', 'State', 'Enabled', 'Disabled', 'Up', 'Down'] as $simple) {
            if (str_contains($sensorName, $simple)) {
                return true;
            }
        }

        return false;
    }

    public function humanize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^[A-Z0-9-]+::/i', '', $value) ?? $value;
        $value = preg_replace('/(?:^|\.)[a-zA-Z][\w-]*\.(\d+(?:\.\d+)*)$/', '$1', $value) ?? $value;
        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B:.");

        if ($value === '') {
            return '';
        }

        $upperTokens = [
            'cpu' => 'CPU',
            'ram' => 'RAM',
            'psu' => 'PSU',
            'rx' => 'RX',
            'tx' => 'TX',
            'olt' => 'OLT',
            'ont' => 'ONT',
            'onu' => 'ONU',
            'gpon' => 'GPON',
            'sfp' => 'SFP',
            'sfp+' => 'SFP+',
            'xfp' => 'XFP',
            'qsfp' => 'QSFP',
            'dom' => 'DOM',
            'ddm' => 'DDM',
            'ip' => 'IP',
            'icmp' => 'ICMP',
            'tcp' => 'TCP',
            'udp' => 'UDP',
        ];

        $words = explode(' ', strtolower($value));
        $words = array_map(
            static fn (string $word): string => $upperTokens[$word] ?? ucfirst($word),
            $words,
        );

        return implode(' ', $words);
    }

    private function typedMetric(
        string $component,
        string $contextOrUnit,
        ?string $unit,
        string $metric,
        string $defaultUnit,
    ): string {
        $component = $this->cleanComponent($component);

        if ($unit === null) {
            return $this->withUnit($this->appendMetric($component, $metric), $contextOrUnit ?: $defaultUnit);
        }

        $context = $this->cleanMetric($contextOrUnit);
        $metricName = $context === '' || str_contains(strtolower($context), strtolower($metric))
            ? $metric
            : trim($context . ' ' . $metric);

        return $this->withUnit($this->joinComponentMetric($component, $metricName), $unit ?: $defaultUnit);
    }

    private function joinComponentMetric(string $component, string $metric): string
    {
        $component = $this->cleanComponent($component);
        $metric = $this->cleanMetric($metric);

        if ($component === '') {
            return $metric;
        }

        if ($metric === '') {
            return $component;
        }

        if (str_contains(strtolower($component), strtolower($metric))) {
            return $component;
        }

        return $component . ' - ' . $metric;
    }

    private function appendMetric(string $component, string $metric): string
    {
        $component = $this->cleanComponent($component);
        $metric = $this->cleanMetric($metric);

        if ($component === '') {
            return $metric;
        }

        if (str_contains(strtolower($component), strtolower($metric))) {
            return $component;
        }

        return trim($component . ' ' . $metric);
    }

    private function withUnit(string $name, string $unit): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        $unit = trim($unit);

        if ($unit === '') {
            return $name;
        }

        if (preg_match('/\(' . preg_quote($unit, '/') . '\)$/i', $name) === 1) {
            return $name;
        }

        return sprintf('%s (%s)', $name, $unit);
    }

    private function cleanComponent(string $component): string
    {
        $component = trim($component);

        if ($this->looksLikeInterfaceName($component)) {
            return $this->normalizeInterfaceName($component);
        }

        $component = $this->humanize($component);
        $component = preg_replace('/\b(?:current value|value|sensor)\b/i', '', $component) ?? $component;
        $component = preg_replace('/\s+/', ' ', $component) ?? $component;

        return trim($component);
    }

    private function looksLikeInterfaceName(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        if (preg_match(
            '/^(?:GigabitEthernet|TenGigabitEthernet|TwentyFiveGigE|FortyGigabitEthernet|HundredGigE|'
            . 'Ethernet|FastEthernet|XGigabitEthernet|Eth-Trunk|Port-channel|GPON|EPON|PON|Vlan)\S*/i',
            $value,
        ) === 1) {
            return true;
        }

        return preg_match('/^(?:Po|Gi|Te|Fa|Eth|GE|XGE|FE|100GE|50GE|40GE|25GE|10GE|Lo)\d/i', $value) === 1;
    }

    private function cleanMetric(string $metric): string
    {
        return $this->normalizeMetric($this->humanize($metric));
    }

    private function normalizeMetric(string $metric): string
    {
        $metric = trim(preg_replace('/\s+/', ' ', $metric) ?? $metric);
        $lower = strtolower($metric);

        return match (true) {
            in_array($lower, ['rx', 'receive', 'received', 'rx optical'], true) => 'RX Power',
            in_array($lower, ['tx', 'transmit', 'transmitted', 'tx optical'], true) => 'TX Power',
            in_array($lower, ['rx power', 'receive power', 'received power'], true) => 'RX Power',
            in_array($lower, ['tx power', 'transmit power', 'transmitted power'], true) => 'TX Power',
            in_array($lower, ['rx at olt', 'olt rx', 'olt rx ont power', 'rx power at olt'], true) => 'RX at OLT',
            in_array($lower, ['bias', 'bias current', 'tx bias current'], true) => 'TX Bias',
            in_array($lower, ['temp', 'thermal', 'celsius'], true) => 'Temperature',
            in_array($lower, ['volt', 'volts', 'supply voltage'], true) => 'Voltage',
            in_array($lower, ['amp', 'amps', 'amperes'], true) => 'Current',
            in_array($lower, ['rpm'], true) => 'Speed',
            default => $metric,
        };
    }
}
