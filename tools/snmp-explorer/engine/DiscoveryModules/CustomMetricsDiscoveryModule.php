<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Helpers\SensorNameFormatter;

/**
 * Custom Metrics Discovery Module
 * 
 * Discovers custom enterprise-specific metrics
 * Demonstrates extensibility pattern for new metric types
 * 
 * OID base: 1.3.6.1.4.1.99999.1.1 (example)
 */
final readonly class CustomMetricsDiscoveryModule implements DiscoveryModuleInterface
{
    // Enterprise OID for custom metrics
    private const string CUSTOM_METRICS_BASE = '1.3.6.1.4.1.99999.1.1';
    private const string CUSTOM_METRIC_NAME = '1.3.6.1.4.1.99999.1.1.1.1';
    private const string CUSTOM_METRIC_VALUE = '1.3.6.1.4.1.99999.1.1.1.2';
    private const string CUSTOM_METRIC_UNIT = '1.3.6.1.4.1.99999.1.1.1.3';
    private const string CUSTOM_METRIC_THRESHOLD = '1.3.6.1.4.1.99999.1.1.1.4';

    public function name(): string
    {
        return 'custom_metrics';
    }

    /**
     * Check if device supports custom metrics
     * Query base OID to determine if device responds
     */
    public function supports(DiscoveryContext $context): bool
    {
        try {
            $result = $context->walker->get(self::CUSTOM_METRICS_BASE . '.0');
            return $result !== null && $result !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Discover all custom metrics from device
     */
    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        try {
            // Walk metric table
            $metricNames = $context->walker->walkIndexed(self::CUSTOM_METRIC_NAME);
            
            if ($metricNames === []) {
                return $sensors;
            }

            // Get corresponding values, units, thresholds
            $metricValues = $context->walker->walkIndexed(self::CUSTOM_METRIC_VALUE);
            $metricUnits = $context->walker->walkIndexed(self::CUSTOM_METRIC_UNIT);
            $metricThresholds = $context->walker->walkIndexed(self::CUSTOM_METRIC_THRESHOLD);

            // Build sensor for each metric
            foreach ($metricNames as $index => $name) {
                $name = trim((string)$name);
                if ($name === '') {
                    continue;
                }
                if ($name === '0') {
                    continue;
                }

                $value = $metricValues[$index] ?? null;
                $unit = trim((string)($metricUnits[$index] ?? 'units'));
                $threshold = (float)($metricThresholds[$index] ?? 0);

                $sensor = [
                    'oid' => self::CUSTOM_METRIC_VALUE . '.' . $index,
                    'sensor_name' => $this->formatName($name),
                    'sensor_class' => 'custom_metric',
                    'raw_value' => $value,
                    'unit' => $unit,
                    'entity_index' => $index,
                    'description' => 'Custom metric: ' . $name,
                    'threshold' => $threshold,
                ];

                // Only include numeric sensors
                if (is_numeric($value)) {
                    $sensors[] = $sensor;
                }
            }

        } catch (\Throwable $e) {
            // Silently fail if metrics not available
            // Other discovery modules will continue
        }

        return $sensors;
    }

    /**
     * Format sensor name for Pandora display
     */
    private function formatName(string $metricName): string
    {
        // Remove common prefixes
        $metricName = preg_replace(
            ['/^custom_/i', '/^metric_/i', '/^app_/i'],
            '',
            $metricName
        );

        // Convert underscore to space
        $metricName = str_replace('_', ' ', $metricName);

        // Capitalize properly
        $metricName = ucwords($metricName);

        return 'Custom - ' . $metricName;
    }
}
