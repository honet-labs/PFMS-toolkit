<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Helpers\SensorNameFormatter;
use SnmpBridge\Helpers\SnmpValueHelper;
use SnmpBridge\Helpers\StringHelper;

final readonly class InterfaceStatsDiscoveryModule implements DiscoveryModuleInterface
{
    private const string IF_DESCR = '1.3.6.1.2.1.2.2.1.2';
    private const string IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';

    private const string IF_IN_OCTETS = '1.3.6.1.2.1.2.2.1.10';
    private const string IF_IN_UCAST_PKTS = '1.3.6.1.2.1.2.2.1.11';
    private const string IF_IN_NUCAST_PKTS = '1.3.6.1.2.1.2.2.1.12';
    private const string IF_IN_DISCARDS = '1.3.6.1.2.1.2.2.1.13';
    private const string IF_IN_ERRORS = '1.3.6.1.2.1.2.2.1.14';
    private const string IF_IN_UNKNOWN_PROTOS = '1.3.6.1.2.1.2.2.1.15';
    private const string IF_OUT_OCTETS = '1.3.6.1.2.1.2.2.1.16';
    private const string IF_OUT_UCAST_PKTS = '1.3.6.1.2.1.2.2.1.17';
    private const string IF_OUT_NUCAST_PKTS = '1.3.6.1.2.1.2.2.1.18';
    private const string IF_OUT_DISCARDS = '1.3.6.1.2.1.2.2.1.19';
    private const string IF_OUT_ERRORS = '1.3.6.1.2.1.2.2.1.20';
    private const string IF_OUT_QLEN = '1.3.6.1.2.1.2.2.1.21';

    private const string IF_HC_IN_OCTETS = '1.3.6.1.2.1.31.1.1.1.6';
    private const string IF_HC_IN_UCAST_PKTS = '1.3.6.1.2.1.31.1.1.1.7';
    private const string IF_HC_IN_MULTICAST_PKTS = '1.3.6.1.2.1.31.1.1.1.8';
    private const string IF_HC_IN_BROADCAST_PKTS = '1.3.6.1.2.1.31.1.1.1.9';
    private const string IF_HC_OUT_OCTETS = '1.3.6.1.2.1.31.1.1.1.10';
    private const string IF_HC_OUT_UCAST_PKTS = '1.3.6.1.2.1.31.1.1.1.11';
    private const string IF_HC_OUT_MULTICAST_PKTS = '1.3.6.1.2.1.31.1.1.1.12';
    private const string IF_HC_OUT_BROADCAST_PKTS = '1.3.6.1.2.1.31.1.1.1.13';

    public function __construct(
        private NormalizerInterface $normalizer,
        private SensorNameFormatter $formatter = new SensorNameFormatter(),
    ) {
    }

    public function name(): string
    {
        return 'interface_stats';
    }

    public function supports(DiscoveryContext $context): bool
    {
        if ($context->walker->walkIndexed(self::IF_NAME) !== []) {
            return true;
        }
        return $context->walker->walkIndexed(self::IF_DESCR) !== [];
    }

    public function discover(DiscoveryContext $context): array
    {
        $names = $context->walker->walkIndexed(self::IF_NAME);
        $descriptions = $context->walker->walkIndexed(self::IF_DESCR);

        if ($names === [] && $descriptions === []) {
            return [];
        }

        // Fast, targeted walks: prefer 64-bit counters, fallback to 32-bit only if empty
        $ifHCInOctets = $context->walker->walkIndexed(self::IF_HC_IN_OCTETS);
        $ifHCOutOctets = $context->walker->walkIndexed(self::IF_HC_OUT_OCTETS);
        $ifInOctets = !empty($ifHCInOctets) ? [] : $context->walker->walkIndexed(self::IF_IN_OCTETS);
        $ifOutOctets = !empty($ifHCOutOctets) ? [] : $context->walker->walkIndexed(self::IF_OUT_OCTETS);

        $ifHCInUcastPkts = $context->walker->walkIndexed(self::IF_HC_IN_UCAST_PKTS);
        $ifHCOutUcastPkts = $context->walker->walkIndexed(self::IF_HC_OUT_UCAST_PKTS);
        $ifInUcastPkts = !empty($ifHCInUcastPkts) ? [] : $context->walker->walkIndexed(self::IF_IN_UCAST_PKTS);
        $ifOutUcastPkts = !empty($ifHCOutUcastPkts) ? [] : $context->walker->walkIndexed(self::IF_OUT_UCAST_PKTS);

        $ifInErrors = $context->walker->walkIndexed(self::IF_IN_ERRORS);
        $ifOutErrors = $context->walker->walkIndexed(self::IF_OUT_ERRORS);

        $tables = [
            'ifHCInOctets' => $ifHCInOctets,
            'ifHCOutOctets' => $ifHCOutOctets,
            'ifInOctets' => $ifInOctets,
            'ifOutOctets' => $ifOutOctets,
            'ifHCInUcastPkts' => $ifHCInUcastPkts,
            'ifHCOutUcastPkts' => $ifHCOutUcastPkts,
            'ifInUcastPkts' => $ifInUcastPkts,
            'ifOutUcastPkts' => $ifOutUcastPkts,
            'ifInErrors' => $ifInErrors,
            'ifOutErrors' => $ifOutErrors,
        ];

        $sensors = [];

        foreach ($this->interfaceIndexes($names, $descriptions) as $index) {
            $interfaceName = $this->interfaceName($index, $names[$index] ?? null, $descriptions[$index] ?? null);
            $metadata = [
                'discovery_module' => 'InterfaceStatsDiscoveryModule',
                'interface_index' => (int) $index,
                'if_descr' => $this->cleanText($descriptions[$index] ?? ''),
                'is_counter' => true,
            ];

            $this->appendBestCounter(
                $sensors,
                $index,
                $interfaceName,
                'ifHCInOctets',
                'interface_in_octets',
                'bytes',
                [
                    [self::IF_HC_IN_OCTETS, $tables['ifHCInOctets'], 'IF-MIB::ifHCInOctets'],
                    [self::IF_IN_OCTETS, $tables['ifInOctets'], 'IF-MIB::ifInOctets'],
                ],
                $metadata + ['direction' => 'in', 'metric_type' => 'octets'],
            );

            $this->appendBestCounter(
                $sensors,
                $index,
                $interfaceName,
                'ifHCOutOctets',
                'interface_out_octets',
                'bytes',
                [
                    [self::IF_HC_OUT_OCTETS, $tables['ifHCOutOctets'], 'IF-MIB::ifHCOutOctets'],
                    [self::IF_OUT_OCTETS, $tables['ifOutOctets'], 'IF-MIB::ifOutOctets'],
                ],
                $metadata + ['direction' => 'out', 'metric_type' => 'octets'],
            );

            // In / Out Packets
            $this->appendBestCounter(
                $sensors,
                $index,
                $interfaceName,
                'ifHCInUcastPkts',
                'interface_in_packets',
                'packets',
                [
                    [self::IF_HC_IN_UCAST_PKTS, $tables['ifHCInUcastPkts'], 'IF-MIB::ifHCInUcastPkts'],
                    [self::IF_IN_UCAST_PKTS, $tables['ifInUcastPkts'], 'IF-MIB::ifInUcastPkts'],
                ],
                $metadata + ['direction' => 'in', 'metric_type' => 'packets'],
            );

            $this->appendBestCounter(
                $sensors,
                $index,
                $interfaceName,
                'ifHCOutUcastPkts',
                'interface_out_packets',
                'packets',
                [
                    [self::IF_HC_OUT_UCAST_PKTS, $tables['ifHCOutUcastPkts'], 'IF-MIB::ifHCOutUcastPkts'],
                    [self::IF_OUT_UCAST_PKTS, $tables['ifOutUcastPkts'], 'IF-MIB::ifOutUcastPkts'],
                ],
                $metadata + ['direction' => 'out', 'metric_type' => 'packets'],
            );

            // In / Out Errors
            $this->appendBestCounter(
                $sensors,
                $index,
                $interfaceName,
                'ifInErrors',
                'interface_in_errors',
                'errors',
                [
                    [self::IF_IN_ERRORS, $tables['ifInErrors'], 'IF-MIB::ifInErrors'],
                ],
                $metadata + ['direction' => 'in', 'metric_type' => 'errors'],
            );

            $this->appendBestCounter(
                $sensors,
                $index,
                $interfaceName,
                'ifOutErrors',
                'interface_out_errors',
                'errors',
                [
                    [self::IF_OUT_ERRORS, $tables['ifOutErrors'], 'IF-MIB::ifOutErrors'],
                ],
                $metadata + ['direction' => 'out', 'metric_type' => 'errors'],
            );
        }

        return $sensors;
    }

    /**
     * @param array<string, string> ...$tables
     * @return list<int|string>
     */
    private function interfaceIndexes(array ...$tables): array
    {
        $indexes = [];

        foreach ($tables as $table) {
            foreach (array_keys($table) as $index) {
                if ($index !== '') {
                    $indexes[(string) $index] = true;
                }
            }
        }

        $indexes = array_keys($indexes);
        usort(
            $indexes,
            static fn (int|string $left, int|string $right): int => (int) $left <=> (int) $right ?: strcmp((string) $left, (string) $right),
        );

        return $indexes;
    }

    private function interfaceName(int|string $index, mixed $ifName, mixed $ifDescription): string
    {
        foreach ([$ifName, $ifDescription] as $candidate) {
            $name = $this->cleanText($candidate);

            if ($name !== '' && $name !== '0') {
                return $this->formatter->normalizeInterfaceName($name);
            }
        }

        return 'ifIndex ' . $index;
    }

    /**
     * @param list<array<string, mixed>> $sensors
     * @param list<array{0:string,1:array<string, string>,2:string}> $candidates
     * @param array<string, mixed> $metadata
     */
    private function appendBestCounter(
        array &$sensors,
        int|string $index,
        string $interfaceName,
        string $metric,
        string $sensorType,
        string $unit,
        array $candidates,
        array $metadata,
    ): void {
        foreach ($candidates as [$oid, $table, $source]) {
            if (array_key_exists($index, $table) && SnmpValueHelper::numeric($table[$index]) !== null) {
                $this->appendCounter(
                    $sensors,
                    $index,
                    $interfaceName,
                    $metric,
                    $sensorType,
                    $oid,
                    $table[$index],
                    $unit,
                    $metadata + ['source' => $source],
                );
                return;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $sensors
     * @param array<string, mixed> $metadata
     */
    private function appendCounter(
        array &$sensors,
        int|string $index,
        string $interfaceName,
        string $metric,
        string $sensorType,
        string $oid,
        mixed $value,
        string $unit,
        array $metadata,
    ): void {
        $indexInt = (int) $index;
        $numeric = SnmpValueHelper::numeric($value);

        if ($numeric === null) {
            return;
        }

        $sensor = [
            'sensor_class' => 'interface',
            'sensor_name' => StringHelper::safeModuleName($this->formatter->interfaceMetric($interfaceName, $metric, $unit)),
            'sensor_type' => $sensorType,
            'interface_index' => $indexInt,
            'interface_name' => $interfaceName,
            'entity_index' => $indexInt,
            'oid' => $oid . '.' . $index,
            'raw_value' => (string) $numeric,
            'unit' => $unit,
            'scale' => 'units',
            'precision' => 0,
            'status' => 'ok',
            'metadata' => $metadata,
        ];

        $normalized = $this->normalizer->normalize($sensor);

        if ($normalized !== null) {
            $sensors[] = $normalized;
        }
    }

    private function cleanText(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        if (in_array(strtoupper($text), ['NULL', 'NOSUCHOBJECT', 'NOSUCHINSTANCE'], true)) {
            return '';
        }

        return trim($text, "\"'");
    }
}
