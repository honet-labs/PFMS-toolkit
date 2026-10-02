<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Normalize\SensorNormalizer;
use SnmpBridge\Core\Normalize\SpeedDetector;
use SnmpBridge\Helpers\SnmpValueHelper;
use SnmpBridge\Helpers\StringHelper;

final readonly class BridgePortSpeedDiscoveryModule implements DiscoveryModuleInterface
{
    private const string DOT1D_BASE_BRIDGE_ADDRESS = '1.3.6.1.2.1.17.1.1';
    private const string DOT1D_BASE_PORT_IFINDEX = '1.3.6.1.2.1.17.1.4.1.2';
    private const string IF_DESCR = '1.3.6.1.2.1.2.2.1.2';
    private const string IF_SPEED = '1.3.6.1.2.1.2.2.1.5';
    private const string IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';
    private const string IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';
    private const string IF_HIGH_SPEED = '1.3.6.1.2.1.31.1.1.1.15';

    public function __construct(
        private SpeedDetector $speedDetector,
    ) {
    }

    public function name(): string
    {
        return 'bridge_port_speed';
    }

    public function supports(DiscoveryContext $context): bool
    {
        return $context->walker->get(self::DOT1D_BASE_BRIDGE_ADDRESS) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function discover(DiscoveryContext $context): array
    {
        $bridgePorts = $context->walker->walkIndexed(self::DOT1D_BASE_PORT_IFINDEX);

        if ($bridgePorts === []) {
            return [];
        }

        $ifNames = $context->walker->walkIndexed(self::IF_NAME);
        $ifDescriptions = $context->walker->walkIndexed(self::IF_DESCR);
        $ifHighSpeeds = $context->walker->walkIndexed(self::IF_HIGH_SPEED);
        $ifOperStatuses = $context->walker->walkIndexed(self::IF_OPER_STATUS);
        $sensors = [];

        foreach ($bridgePorts as $bridgePort => $ifIndexValue) {
            $ifIndex = SnmpValueHelper::integer($ifIndexValue);
            if ($ifIndex === null) {
                continue;
            }
            if ($ifIndex < 1) {
                continue;
            }

            $ifIndexKey = (string) $ifIndex;
            $interfaceName = trim((string) ($ifNames[$ifIndexKey] ?? $ifDescriptions[$ifIndexKey] ?? 'ifIndex ' . $ifIndex));
            $speedSensor = $this->speedSensor(
                $bridgePort,
                $ifIndex,
                $interfaceName,
                $ifHighSpeeds[$ifIndexKey] ?? null,
            );

            if ($speedSensor !== null) {
                $sensors[] = $speedSensor;
            }

            $statusSensor = $this->statusSensor(
                $bridgePort,
                $ifIndex,
                $interfaceName,
                $ifOperStatuses[$ifIndexKey] ?? null,
            );

            if ($statusSensor !== null) {
                $sensors[] = $statusSensor;
            }
        }

        return $sensors;
    }

    private function speedSensor(string|int $bridgePort, int $ifIndex, string $interfaceName, mixed $highSpeed): ?array
    {
        $highSpeedValue = SnmpValueHelper::integer($highSpeed);
        $rawValue = null;
        $unit = 'Mbps';
        $oid = self::IF_HIGH_SPEED . '.' . $ifIndex;
        $speedInBps = 0;

        if ($highSpeedValue !== null && $highSpeedValue > 0) {
            $rawValue = $highSpeedValue;
            $speedInBps = $highSpeedValue * 1000000;
        }

        if ($rawValue === null || $speedInBps < 1) {
            return null;
        }

        return [
            'sensor_class' => 'bridge_port_speed',
            'sensor_name' => StringHelper::safeModuleName(
                sprintf('%s - Bridge Port %s Speed (%s)', $interfaceName, (string) $bridgePort, $this->speedDetector->formatSpeed($speedInBps)),
            ),
            'sensor_type' => 'interface_speed',
            'interface_name' => $interfaceName,
            'interface_index' => $ifIndex,
            'entity_index' => $ifIndex,
            'oid' => $oid,
            'raw_value' => (string) $rawValue,
            'normalized_value' => $rawValue,
            'unit' => $unit,
            'scale' => 'units',
            'precision' => 0,
            'status' => 'ok',
            'metadata' => [
                'discovery_module' => 'BridgePortSpeedDiscoveryModule',
                'bridge_port_index' => (string) $bridgePort,
                'speed_in_bps' => $speedInBps,
            ],
        ];
    }

    private function statusSensor(string|int $bridgePort, int $ifIndex, string $interfaceName, mixed $status): ?array
    {
        $statusValue = SnmpValueHelper::integer($status);

        if ($statusValue === null) {
            return null;
        }

        return [
            'sensor_class' => 'bridge_port_status',
            'sensor_name' => StringHelper::safeModuleName(sprintf('%s - Bridge Port %s Oper Status', $interfaceName, (string) $bridgePort)),
            'sensor_type' => 'oper_status',
            'interface_name' => $interfaceName,
            'interface_index' => $ifIndex,
            'entity_index' => $ifIndex,
            'oid' => self::IF_OPER_STATUS . '.' . $ifIndex,
            'raw_value' => (string) $statusValue,
            'normalized_value' => $statusValue,
            'unit' => 'status',
            'scale' => 'units',
            'precision' => 0,
            'status' => $statusValue === 1 ? 'ok' : 'nonoperational',
            'metadata' => [
                'discovery_module' => 'BridgePortSpeedDiscoveryModule',
                'bridge_port_index' => (string) $bridgePort,
                'status_label' => $this->statusLabel($statusValue),
            ],
        ];
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            1 => 'up',
            2 => 'down',
            3 => 'testing',
            4 => 'unknown',
            5 => 'dormant',
            6 => 'notPresent',
            7 => 'lowerLayerDown',
            default => (string) $status,
        };
    }
}
