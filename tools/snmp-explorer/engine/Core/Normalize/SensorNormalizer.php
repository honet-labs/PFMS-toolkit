<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Normalize;

use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Helpers\SnmpValueHelper;

final readonly class SensorNormalizer implements NormalizerInterface
{
    public function __construct(
        private InvalidValueFilter $invalidValueFilter,
        private ScaleNormalizer $scaleNormalizer,
        private UnitNormalizer $unitNormalizer,
    ) {
    }

    public function normalize(
        array|int|float|string|null $sensor,
        mixed $scale = 'units',
        mixed $precision = 0,
        string $unit = '',
        mixed $offset = 0,
    ): ?array
    {
        if (!is_array($sensor)) {
            return $this->normalizeValue($sensor, $scale, $precision, $unit, $offset);
        }

        $rawValue = SnmpValueHelper::numeric($sensor['raw_value'] ?? null);

        if (!$this->invalidValueFilter->isValid($rawValue)) {
            return null;
        }

        $adjustedValue = $rawValue + (SnmpValueHelper::numeric($sensor['offset'] ?? 0.0) ?? 0.0);

        $normalizedValue = $this->scaleNormalizer->normalize(
            $adjustedValue,
            $sensor['scale'] ?? 'units',
            $sensor['precision'] ?? 0,
        );

        $normalizedUnit = $this->unitNormalizer->normalize(
            isset($sensor['unit']) ? (string) $sensor['unit'] : null,
            isset($sensor['sensor_type']) ? (string) $sensor['sensor_type'] : null,
        );

        // Adjust SI base unit to prefixed display unit (ScaleNormalizer outputs in SI Base Units W/A/V, while ENTITY-SENSOR-MIB displays mW/mA/mV)
        $scaledValue = match ($normalizedUnit) {
            'mW', 'mA', 'mV' => $normalizedValue * 1000.0,
            'µW', 'uW', 'µA', 'uA', 'µV', 'uV' => $normalizedValue * 1000000.0,
            'kW', 'kA', 'kV' => $normalizedValue / 1000.0,
            default => $normalizedValue,
        };

        $sensor['raw_value'] = $rawValue;
        $sensor['normalized_value'] = round($scaledValue, 6);
        $sensor['unit'] = $normalizedUnit;

        if ($adjustedValue != 0.0 && abs($adjustedValue - $scaledValue) > 0.00001) {
            $sensor['post_process'] = round($scaledValue / $adjustedValue, 9);
            if (!isset($sensor['metadata']) || !is_array($sensor['metadata'])) {
                $sensor['metadata'] = [];
            }
            $sensor['metadata']['post_process'] = $sensor['post_process'];
        }

        return $sensor;
    }

    /**
     * @return array{value:float, unit:string}|null
     */
    private function normalizeValue(
        int|float|string|null $value,
        mixed $scale,
        mixed $precision,
        string $unit,
        mixed $offset,
    ): ?array {
        $rawValue = SnmpValueHelper::numeric($value);

        if (!$this->invalidValueFilter->isValid($rawValue)) {
            return null;
        }

        $adjustedValue = $rawValue + (SnmpValueHelper::numeric($offset) ?? 0.0);
        $normalizedValue = $this->scaleNormalizer->normalize($adjustedValue, $scale, $precision);
        $normalizedUnit = $this->unitNormalizer->normalize($unit);

        $scaledValue = match ($normalizedUnit) {
            'mW', 'mA', 'mV' => $normalizedValue * 1000.0,
            'µW', 'uW', 'µA', 'uA', 'µV', 'uV' => $normalizedValue * 1000000.0,
            'kW', 'kA', 'kV' => $normalizedValue / 1000.0,
            default => $normalizedValue,
        };

        return [
            'value' => round($scaledValue, 6),
            'unit' => $normalizedUnit,
        ];
    }
}
