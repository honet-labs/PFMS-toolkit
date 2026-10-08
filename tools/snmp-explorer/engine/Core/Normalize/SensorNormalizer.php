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

        $sensor['raw_value'] = $rawValue;
        $sensor['normalized_value'] = round($normalizedValue, 6);
        $sensor['unit'] = $this->unitNormalizer->normalize(
            isset($sensor['unit']) ? (string) $sensor['unit'] : null,
            isset($sensor['sensor_type']) ? (string) $sensor['sensor_type'] : null,
        );

        if ($adjustedValue != 0.0 && abs($adjustedValue - $normalizedValue) > 0.00001) {
            $sensor['post_process'] = round($normalizedValue / $adjustedValue, 9);
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

        return [
            'value' => round($normalizedValue, 6),
            'unit' => $this->unitNormalizer->normalize($unit),
        ];
    }
}
