<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Snmp\SnmpHelper;
use SnmpBridge\Helpers\SnmpValueHelper;
use SnmpBridge\VendorAdapter\Huawei\HuaweiNameTranslator;

/**
 * Huawei-specific optical transceiver discovery
 * 
 * Discovers:
 * - 10GE/100GE/400GE optical transceiver RX/TX power, temperature
 * - GPON OLT optics DDM information
 * - GPON ONT optical DDM information
 * 
 * Uses vendor-specific OIDs from HUAWEI-MIB and HUAWEI-XPON-MIB
 */
final class HuaweiOpticalDiscoveryModule implements DiscoveryModuleInterface
{
    private const HUAWEI_EXT_OPTICAL_SERIAL = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.4';
    private const HUAWEI_EXT_OPTICAL_TEMP = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.5';
    private const HUAWEI_EXT_OPTICAL_VOLTAGE = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.6';
    private const HUAWEI_EXT_OPTICAL_TX_BIAS = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.7';
    private const HUAWEI_EXT_OPTICAL_TX_POWER_UW = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.8';
    private const HUAWEI_EXT_OPTICAL_RX_POWER_UW = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.9';
    private const HUAWEI_EXT_OPTICAL_TYPE = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.25';
    private const HUAWEI_EXT_OPTICAL_TX_POWER_DBM = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.32';
    private const HUAWEI_EXT_OPTICAL_RX_POWER_DBM = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.33';
    private const HUAWEI_EXT_OPTICAL_STATUS = '1.3.6.1.4.1.2011.5.25.31.1.1.3.1.57';

    private const HUAWEI_OPTICAL_RX_POWER = '1.3.6.1.4.1.2011.5.25.31.1.1.6';
    private const HUAWEI_OPTICAL_TX_POWER = '1.3.6.1.4.1.2011.5.25.31.1.1.7';
    private const HUAWEI_OPTICAL_TEMP = '1.3.6.1.4.1.2011.5.25.31.1.1.4';

    // GPON OLT sensors
    private const HUAWEI_GPON_OLT_TEMP = '1.3.6.1.4.1.2011.6.128.1.1.2.23.1.1';
    private const HUAWEI_GPON_OLT_VOLTAGE = '1.3.6.1.4.1.2011.6.128.1.1.2.23.1.2';
    private const HUAWEI_GPON_OLT_TX_BIAS = '1.3.6.1.4.1.2011.6.128.1.1.2.23.1.3';
    private const HUAWEI_GPON_OLT_TX_POWER = '1.3.6.1.4.1.2011.6.128.1.1.2.23.1.4';
    private const HUAWEI_GPON_OLT_RX_POWER = '1.3.6.1.4.1.2011.6.128.1.1.2.23.1.5';

    // GPON ONT sensors
    private const HUAWEI_GPON_ONT_TX_POWER = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.3';
    private const HUAWEI_GPON_ONT_RX_POWER = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.4';
    private const HUAWEI_GPON_ONT_OLT_RX = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.6';
    private const HUAWEI_GPON_ONT_TEMP = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.1';
    private const HUAWEI_GPON_ONT_VOLTAGE = '1.3.6.1.4.1.2011.6.128.1.1.2.51.1.5';

    // If name mapping for entity indices
    private const HUAWEI_IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';
    private const HUAWEI_ENT_ALIAS_MAPPING = '1.3.6.1.2.1.47.1.3.2.1.2';

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly HuaweiNameTranslator $names = new HuaweiNameTranslator(),
    ) {
    }

    public function name(): string
    {
        return 'huawei_optical';
    }

    public function supports(DiscoveryContext $context): bool
    {
        return str_starts_with($context->vendor->name(), 'Huawei') && $context->capabilities->supportsOpticalDom;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        $transceiverSensors = $this->discoverExtendedOpticalTransceivers($context);
        if ($transceiverSensors === []) {
            $transceiverSensors = $this->discoverOpticalTransceivers($context);
        }

        $sensors = array_merge($sensors, $transceiverSensors);

        // Discover GPON OLT sensors
        $sensors = array_merge($sensors, $this->discoverGponOlt($context));

        // Discover GPON ONT sensors
        $sensors = array_merge($sensors, $this->discoverGponOnt($context));

        return $sensors;
    }

    /**
     * Discover Huawei extended optical transceiver sensors used by VRP switches
     * such as S57xx/S67xx/CloudEngine. This table indexes DOM values by
     * entPhysicalIndex, then ENTITY-MIB maps that index back to ifIndex/ifName.
     */
    private function discoverExtendedOpticalTransceivers(DiscoveryContext $context): array
    {
        $ifNames = $context->walker->walkIndexed(self::HUAWEI_IF_NAME);
        if ($ifNames === []) {
            $ifNames = $context->walker->walkIndexed(SnmpHelper::IF_DESCR);
        }

        $entityAliases = $context->walker->walkIndexed(self::HUAWEI_ENT_ALIAS_MAPPING);
        $entityToIfIndex = $this->buildEntityToIfIndexMapping($entityAliases);
        $tempValues = $context->walker->walkIndexed(self::HUAWEI_EXT_OPTICAL_TEMP);
        $voltageValues = $context->walker->walkIndexed(self::HUAWEI_EXT_OPTICAL_VOLTAGE);
        $biasValues = $context->walker->walkIndexed(self::HUAWEI_EXT_OPTICAL_TX_BIAS);
        $txPowerDbmValues = $context->walker->walkIndexed(self::HUAWEI_EXT_OPTICAL_TX_POWER_DBM);
        $txPowerMicrowattValues = $context->walker->walkIndexed(self::HUAWEI_EXT_OPTICAL_TX_POWER_UW);
        $rxPowerDbmValues = $context->walker->walkIndexed(self::HUAWEI_EXT_OPTICAL_RX_POWER_DBM);
        $rxPowerMicrowattValues = $context->walker->walkIndexed(self::HUAWEI_EXT_OPTICAL_RX_POWER_UW);
        $metricIndexes = array_merge(
            array_keys($tempValues),
            array_keys($voltageValues),
            array_keys($biasValues),
            array_keys($txPowerDbmValues),
            array_keys($txPowerMicrowattValues),
            array_keys($rxPowerDbmValues),
            array_keys($rxPowerMicrowattValues),
        );
        $metadata = $this->extendedOpticalMetadata($context, $ifNames, $entityToIfIndex, $metricIndexes);

        if ($metadata === []) {
            return [];
        }

        $sensors = [];

        $sensors = array_merge($sensors, $this->extendedScalarSensors(
            $tempValues,
            $metadata,
            self::HUAWEI_EXT_OPTICAL_TEMP,
            'temperature',
            'Transceiver',
            'C',
            'units',
            [-255.0],
            'HUAWEI-ENTITY-EXTENT-MIB::hwEntityOpticalTemperature',
        ));

        $sensors = array_merge($sensors, $this->extendedScalarSensors(
            $voltageValues,
            $metadata,
            self::HUAWEI_EXT_OPTICAL_VOLTAGE,
            'voltage',
            'Transceiver Supply',
            'V',
            0.001,
            [-255.0, 0.0],
            'HUAWEI-ENTITY-EXTENT-MIB::hwEntityOpticalVoltage',
        ));

        $sensors = array_merge($sensors, $this->extendedScalarSensors(
            $biasValues,
            $metadata,
            self::HUAWEI_EXT_OPTICAL_TX_BIAS,
            'bias_current',
            'TX Bias',
            'mA',
            0.001,
            [-255.0],
            'HUAWEI-ENTITY-EXTENT-MIB::hwEntityOpticalBiasCurrent',
        ));

        $sensors = array_merge($sensors, $this->extendedPowerSensors(
            $txPowerDbmValues,
            $txPowerMicrowattValues,
            $metadata,
            self::HUAWEI_EXT_OPTICAL_TX_POWER_DBM,
            self::HUAWEI_EXT_OPTICAL_TX_POWER_UW,
            'tx_power',
            'TX Power',
            'HUAWEI-ENTITY-EXTENT-MIB::hwEntityOpticalTxPower',
        ));

        $sensors = array_merge($sensors, $this->extendedPowerSensors(
            $rxPowerDbmValues,
            $rxPowerMicrowattValues,
            $metadata,
            self::HUAWEI_EXT_OPTICAL_RX_POWER_DBM,
            self::HUAWEI_EXT_OPTICAL_RX_POWER_UW,
            'rx_power',
            'RX Power',
            'HUAWEI-ENTITY-EXTENT-MIB::hwEntityOpticalRxPower',
        ));

        return $sensors;
    }

    /**
     * Discover generic optical transceiver sensors (10GE, 100GE, 400GE)
     */
    private function discoverOpticalTransceivers(DiscoveryContext $context): array
    {
        $sensors = [];
        
        // Get interface mappings
        $ifNames = $context->walker->walkIndexed(self::HUAWEI_IF_NAME);
        $entityAliases = $context->walker->walkIndexed(self::HUAWEI_ENT_ALIAS_MAPPING);
        
        // Build entity to ifIndex mapping
        $entityToIfIndex = $this->buildEntityToIfIndexMapping($entityAliases);

        // Discover RX Power
        $rxPowers = $context->walker->walkIndexed(self::HUAWEI_OPTICAL_RX_POWER);
        foreach ($rxPowers as $index => $value) {
            $ifIndex = $this->resolveIfIndex($index, $entityToIfIndex, $context->entityMap);
            $label = $this->entityLabel($context, $index);
            $ifName = $this->displayInterfaceName($context, $index, $ifNames[(string) $ifIndex] ?? null, $label);

            $normalized = $this->normalizer->normalize(
                (float) $value,
                scale: 0.01,
                unit: 'dBm'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'optical_dom',
                'sensor_name' => $this->names->opticalName($ifName, $label, 'RX Power', 'dBm', (string) $index),
                'sensor_type' => 'rx_power',
                'interface_index' => $ifIndex,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_OPTICAL_RX_POWER . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'rx_power',
                    'entity_label' => $label,
                    'source' => 'HUAWEI-MIB::hwOpticalInterfaceRxPower',
                ]),
            ];
        }

        // Discover TX Power
        $txPowers = $context->walker->walkIndexed(self::HUAWEI_OPTICAL_TX_POWER);
        foreach ($txPowers as $index => $value) {
            $ifIndex = $this->resolveIfIndex($index, $entityToIfIndex, $context->entityMap);
            $label = $this->entityLabel($context, $index);
            $ifName = $this->displayInterfaceName($context, $index, $ifNames[(string) $ifIndex] ?? null, $label);

            $normalized = $this->normalizer->normalize(
                (float) $value,
                scale: 0.01,
                unit: 'dBm'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'optical_dom',
                'sensor_name' => $this->names->opticalName($ifName, $label, 'TX Power', 'dBm', (string) $index),
                'sensor_type' => 'tx_power',
                'interface_index' => $ifIndex,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_OPTICAL_TX_POWER . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'tx_power',
                    'entity_label' => $label,
                    'source' => 'HUAWEI-MIB::hwOpticalInterfaceTxPower',
                ]),
            ];
        }

        // Discover Temperature
        $temps = $context->walker->walkIndexed(self::HUAWEI_OPTICAL_TEMP);
        foreach ($temps as $index => $value) {
            $ifIndex = $this->resolveIfIndex($index, $entityToIfIndex, $context->entityMap);
            $label = $this->entityLabel($context, $index);
            $ifName = $this->displayInterfaceName($context, $index, $ifNames[(string) $ifIndex] ?? null, $label);

            $normalized = $this->normalizer->normalize(
                (float) $value,
                unit: 'C'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'optical_dom',
                'sensor_name' => $this->names->temperatureName($ifName, $label, 'Transceiver', 'C', (string) $index),
                'sensor_type' => 'temperature',
                'interface_index' => $ifIndex,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_OPTICAL_TEMP . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'temperature',
                    'entity_label' => $label,
                    'source' => 'HUAWEI-MIB::hwOpticalInterfaceTemperature',
                ]),
            ];
        }

        return $sensors;
    }

    /**
     * Discover GPON OLT optical sensors
     */
    private function discoverGponOlt(DiscoveryContext $context): array
    {
        $sensors = [];
        $ifNames = $context->walker->walkIndexed(self::HUAWEI_IF_NAME);

        // OLT Temperature
        $temps = $context->walker->walkIndexed(self::HUAWEI_GPON_OLT_TEMP);
        foreach ($temps as $index => $value) {
            $ifName = $this->names->interfaceName($ifNames[$index] ?? null) ?? 'GPON ' . str_replace('.', '/', (string) $index);

            $normalized = $this->normalizer->normalize(
                (float) $value,
                unit: 'C'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'gpon',
                'sensor_name' => $this->names->temperatureName($ifName, null, 'OLT Optics', 'C', (string) $index),
                'sensor_type' => 'temperature',
                'interface_index' => $index,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_GPON_OLT_TEMP . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'temperature',
                    'source' => 'HUAWEI-XPON-MIB::hwGponOltOpticsDdmInfoTemperature',
                ]),
            ];
        }

        // OLT Supply Voltage
        $voltages = $context->walker->walkIndexed(self::HUAWEI_GPON_OLT_VOLTAGE);
        foreach ($voltages as $index => $value) {
            $ifName = $this->names->interfaceName($ifNames[$index] ?? null) ?? 'GPON ' . str_replace('.', '/', (string) $index);

            $normalized = $this->normalizer->normalize(
                (float) $value,
                scale: 0.01,
                unit: 'V'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'gpon',
                'sensor_name' => $this->names->voltageName($ifName, null, 'OLT Supply', 'V', (string) $index),
                'sensor_type' => 'voltage',
                'interface_index' => $index,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_GPON_OLT_VOLTAGE . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'voltage',
                    'source' => 'HUAWEI-XPON-MIB::hwGponOltOpticsDdmInfoSupplyVoltage',
                ]),
            ];
        }

        // OLT TX Bias Current
        $biases = $context->walker->walkIndexed(self::HUAWEI_GPON_OLT_TX_BIAS);
        foreach ($biases as $index => $value) {
            $ifName = $this->names->interfaceName($ifNames[$index] ?? null) ?? 'GPON ' . str_replace('.', '/', (string) $index);

            $normalized = $this->normalizer->normalize(
                (float) $value,
                unit: 'mA'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'gpon',
                'sensor_name' => $this->names->currentName($ifName, null, 'OLT TX Bias', 'mA', (string) $index),
                'sensor_type' => 'bias_current',
                'interface_index' => $index,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_GPON_OLT_TX_BIAS . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'bias_current',
                    'source' => 'HUAWEI-XPON-MIB::hwGponOltOpticsDdmInfoTxBiasCurrent',
                ]),
            ];
        }

        // OLT TX Power
        $txPowers = $context->walker->walkIndexed(self::HUAWEI_GPON_OLT_TX_POWER);
        foreach ($txPowers as $index => $value) {
            $ifName = $this->names->interfaceName($ifNames[$index] ?? null) ?? 'GPON ' . str_replace('.', '/', (string) $index);

            $normalized = $this->normalizer->normalize(
                (float) $value,
                scale: 0.01,
                unit: 'dBm'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'gpon',
                'sensor_name' => $this->names->opticalName($ifName, null, 'OLT TX Power', 'dBm', (string) $index),
                'sensor_type' => 'tx_power',
                'interface_index' => $index,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_GPON_OLT_TX_POWER . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'tx_power',
                    'source' => 'HUAWEI-XPON-MIB::hwGponOltOpticsDdmInfoTxPower',
                ]),
            ];
        }

        // OLT RX Power
        $rxPowers = $context->walker->walkIndexed(self::HUAWEI_GPON_OLT_RX_POWER);
        foreach ($rxPowers as $index => $value) {
            $ifName = $this->names->interfaceName($ifNames[$index] ?? null) ?? 'GPON ' . str_replace('.', '/', (string) $index);

            $normalized = $this->normalizer->normalize(
                (float) $value,
                scale: 0.01,
                unit: 'dBm'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'gpon',
                'sensor_name' => $this->names->opticalName($ifName, null, 'OLT RX Power', 'dBm', (string) $index),
                'sensor_type' => 'rx_power',
                'interface_index' => $index,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_GPON_OLT_RX_POWER . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'rx_power',
                    'source' => 'HUAWEI-XPON-MIB::hwGponOltOpticsDdmInfoRxPower',
                ]),
            ];
        }

        return $sensors;
    }

    /**
     * Discover GPON ONT optical sensors
     */
    private function discoverGponOnt(DiscoveryContext $context): array
    {
        $sensors = [];
        $ifNames = $context->walker->walkIndexed(self::HUAWEI_IF_NAME);

        // ONT TX Power
        $txPowers = $context->walker->walkIndexed(self::HUAWEI_GPON_ONT_TX_POWER);
        foreach ($txPowers as $index => $value) {
            [$ifIndex, $ifName] = $this->names->gponOntComponent($ifNames, (string) $index, 'Huawei ONT');

            $normalized = $this->normalizer->normalize(
                (float) $value,
                scale: 0.01,
                unit: 'dBm'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'gpon',
                'sensor_name' => $this->names->opticalName($ifName, null, 'TX Power', 'dBm', (string) $index),
                'sensor_type' => 'tx_power',
                'interface_index' => $ifIndex,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_GPON_ONT_TX_POWER . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'tx_power',
                    'source' => 'HUAWEI-XPON-MIB::hwGponOntOpticalDdmTxPower',
                ]),
            ];
        }

        // ONT RX Power
        $rxPowers = $context->walker->walkIndexed(self::HUAWEI_GPON_ONT_RX_POWER);
        foreach ($rxPowers as $index => $value) {
            [$ifIndex, $ifName] = $this->names->gponOntComponent($ifNames, (string) $index, 'Huawei ONT');

            $normalized = $this->normalizer->normalize(
                (float) $value,
                scale: 0.01,
                unit: 'dBm'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'gpon',
                'sensor_name' => $this->names->opticalName($ifName, null, 'RX Power', 'dBm', (string) $index),
                'sensor_type' => 'rx_power',
                'interface_index' => $ifIndex,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_GPON_ONT_RX_POWER . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'rx_power',
                    'source' => 'HUAWEI-XPON-MIB::hwGponOntOpticalDdmRxPower',
                ]),
            ];
        }

        // ONT RX at OLT
        $oltRx = $context->walker->walkIndexed(self::HUAWEI_GPON_ONT_OLT_RX);
        foreach ($oltRx as $index => $value) {
            [$ifIndex, $ifName] = $this->names->gponOntComponent($ifNames, (string) $index, 'Huawei ONT');

            $normalized = $this->normalizer->normalize(
                (float) $value - 10000,
                scale: 0.01,
                unit: 'dBm'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'gpon',
                'sensor_name' => $this->names->opticalName($ifName, null, 'RX at OLT', 'dBm', (string) $index),
                'sensor_type' => 'olt_rx_ont_power',
                'interface_index' => $ifIndex,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_GPON_ONT_OLT_RX . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'olt_rx_ont_power',
                    'source' => 'HUAWEI-XPON-MIB::hwGponOntOpticalDdmOltRxOntPower',
                ]),
            ];
        }

        // ONT Temperature
        $temps = $context->walker->walkIndexed(self::HUAWEI_GPON_ONT_TEMP);
        foreach ($temps as $index => $value) {
            [$ifIndex, $ifName] = $this->names->gponOntComponent($ifNames, (string) $index, 'Huawei ONT');

            $normalized = $this->normalizer->normalize(
                (float) $value,
                unit: 'C'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'gpon',
                'sensor_name' => $this->names->temperatureName($ifName, null, '', 'C', (string) $index),
                'sensor_type' => 'temperature',
                'interface_index' => $ifIndex,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_GPON_ONT_TEMP . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'temperature',
                    'source' => 'HUAWEI-XPON-MIB::hwGponOntOpticalDdmTemperature',
                ]),
            ];
        }

        // ONT Voltage
        $voltages = $context->walker->walkIndexed(self::HUAWEI_GPON_ONT_VOLTAGE);
        foreach ($voltages as $index => $value) {
            [$ifIndex, $ifName] = $this->names->gponOntComponent($ifNames, (string) $index, 'Huawei ONT');

            $normalized = $this->normalizer->normalize(
                (float) $value,
                scale: 0.001,
                unit: 'V'
            );

            if ($normalized === null) {
                continue;
            }

            $sensors[] = [
                'sensor_class' => 'gpon',
                'sensor_name' => $this->names->voltageName($ifName, null, '', 'V', (string) $index),
                'sensor_type' => 'voltage',
                'interface_index' => $ifIndex,
                'interface_name' => $ifName,
                'entity_index' => (int) $index,
                'oid' => self::HUAWEI_GPON_ONT_VOLTAGE . '.' . $index,
                'raw_value' => $value,
                'normalized_value' => $normalized['value'],
                'unit' => $normalized['unit'],
                'metadata_json' => json_encode([
                    'discovery_module' => 'huawei_optical',
                    'vendor' => 'Huawei',
                    'sensor_type' => 'voltage',
                    'source' => 'HUAWEI-XPON-MIB::hwGponOntOpticalDdmVoltage',
                ]),
            ];
        }

        return $sensors;
    }

    /**
     * Build mapping from entity index to ifIndex using entAliasMappingIdentifier
     */
    private function buildEntityToIfIndexMapping(array $aliases): array
    {
        $mapping = [];
        foreach ($aliases as $index => $value) {
            $entityIndex = (int) explode('.', (string) $index)[0];
            $ifIndex = SnmpHelper::extractIfIndexFromAlias((string) $value);

            if ($entityIndex > 0 && $ifIndex !== null) {
                $mapping[$entityIndex] = $ifIndex;
            }
        }

        return $mapping;
    }

    /**
     * Resolve ifIndex from entity index
     */
    private function resolveIfIndex(int|string $entityIndex, array $entityToIfIndex, array $entityMap): ?int
    {
        $entityIndex = (int) $entityIndex;

        // Try entity to ifIndex mapping first
        if (isset($entityToIfIndex[$entityIndex])) {
            return $entityToIfIndex[$entityIndex];
        }

        // Try entity map
        if (isset($entityMap[$entityIndex]['ifIndex'])) {
            return (int) $entityMap[$entityIndex]['ifIndex'];
        }

        // Fallback: try direct index
        return $entityIndex;
    }

    private function entityLabel(DiscoveryContext $context, int|string $index): ?string
    {
        $label = $context->entityMap[(int) $index]['label'] ?? null;

        if ($label === null || trim((string) $label) === '') {
            return null;
        }

        return (string) $label;
    }

    private function displayInterfaceName(
        DiscoveryContext $context,
        int|string $index,
        mixed $ifName,
        ?string $label,
    ): ?string {
        $direct = $this->names->interfaceName(is_string($ifName) ? $ifName : null);

        if ($direct !== null) {
            return $direct;
        }

        $mapped = $context->vendor->entityMapper()->resolveInterfaceName($index, $context->entityMap);

        if ($mapped !== null) {
            return $this->names->interfaceName($mapped) ?? $mapped;
        }

        return $label !== null ? $this->names->interfaceFromLabel($label) : null;
    }

    /**
     * @param array<string, string> $ifNames
     * @param array<int, int> $entityToIfIndex
     * @param list<string|int> $metricIndexes
     * @return array<int, array<string, mixed>>
     */
    private function extendedOpticalMetadata(
        DiscoveryContext $context,
        array $ifNames,
        array $entityToIfIndex,
        array $metricIndexes = [],
    ): array
    {
        $types = $context->walker->walkIndexed(self::HUAWEI_EXT_OPTICAL_TYPE);
        $serials = $context->walker->walkIndexed(self::HUAWEI_EXT_OPTICAL_SERIAL);
        $statuses = $context->walker->walkIndexed(self::HUAWEI_EXT_OPTICAL_STATUS);
        $metadata = [];

        $indexes = array_unique(array_merge(array_keys($types), array_keys($serials), array_keys($statuses), $metricIndexes));

        foreach ($indexes as $index) {
            $entityIndex = (int) $index;
            if ($entityIndex <= 0) {
                continue;
            }

            $ifIndex = $this->resolveIfIndex($entityIndex, $entityToIfIndex, $context->entityMap);
            $label = $this->entityLabel($context, $entityIndex);
            $ifName = $this->displayInterfaceName($context, $entityIndex, $ifNames[(string) $ifIndex] ?? null, $label);

            $metadata[$entityIndex] = [
                'entity_index' => $entityIndex,
                'interface_index' => $ifIndex,
                'interface_name' => $ifName,
                'entity_label' => $label,
                'optic_type' => $this->cleanText($types[(string) $index] ?? null),
                'serial_number' => $this->cleanText($serials[(string) $index] ?? null),
                'oper_status' => SnmpValueHelper::integer($statuses[(string) $index] ?? null),
            ];
        }

        return $metadata;
    }

    /**
     * @param array<string, string> $values
     * @param array<int, array<string, mixed>> $metadata
     * @param list<float> $invalidValues
     * @return list<array<string, mixed>>
     */
    private function extendedScalarSensors(
        array $values,
        array $metadata,
        string $baseOid,
        string $sensorType,
        string $metricContext,
        string $unit,
        mixed $scale,
        array $invalidValues,
        string $source,
    ): array {
        $sensors = [];

        foreach ($values as $index => $value) {
            $entityIndex = (int) $index;
            $info = $metadata[$entityIndex] ?? null;

            if ($info === null) {
                continue;
            }

            $rawValue = SnmpValueHelper::numeric($value);
            if ($rawValue === null || $this->isInvalidHuaweiValue($rawValue, $invalidValues)) {
                continue;
            }

            $normalized = $this->normalizer->normalize($rawValue, scale: $scale, unit: $unit);
            if ($normalized === null) {
                continue;
            }

            $sensors[] = $this->extendedSensorRow(
                $info,
                $sensorType,
                $metricContext,
                $baseOid . '.' . $index,
                $value,
                $normalized['value'],
                $normalized['unit'],
                $source,
            );
        }

        return $sensors;
    }

    /**
     * @param array<string, string> $dbmValues
     * @param array<string, string> $microwattValues
     * @param array<int, array<string, mixed>> $metadata
     * @return list<array<string, mixed>>
     */
    private function extendedPowerSensors(
        array $dbmValues,
        array $microwattValues,
        array $metadata,
        string $dbmBaseOid,
        string $microwattBaseOid,
        string $sensorType,
        string $metric,
        string $source,
    ): array {
        $sensors = [];

        foreach ($metadata as $entityIndex => $info) {
            $rawValue = $dbmValues[(string) $entityIndex] ?? null;
            $oid = $dbmBaseOid . '.' . $entityIndex;
            $normalized = null;
            $laneValues = [];

            if ($rawValue !== null && $this->cleanText($rawValue) !== null) {
                $rawNumbers = $this->numericList($rawValue);
                if ($rawNumbers !== []) {
                    $laneValues = array_map(
                        static fn (float $value): float => round($value * 0.01, 6),
                        $rawNumbers,
                    );
                    $normalized = $this->normalizer->normalize($rawNumbers[0], scale: 0.01, unit: 'dBm');
                }
            }

            if ($normalized === null && isset($microwattValues[(string) $entityIndex])) {
                $rawValue = $microwattValues[(string) $entityIndex];
                $oid = $microwattBaseOid . '.' . $entityIndex;
                $normalized = $this->normalizeMicrowattPower($rawValue);
            }

            if ($normalized === null) {
                continue;
            }

            $sensors[] = $this->extendedSensorRow(
                $info,
                $sensorType,
                $metric,
                $oid,
                $rawValue,
                $normalized['value'],
                $normalized['unit'],
                $source,
                $laneValues,
            );
        }

        return $sensors;
    }

    /**
     * @param array<string, mixed> $info
     * @param list<float> $laneValues
     * @return array<string, mixed>
     */
    private function extendedSensorRow(
        array $info,
        string $sensorType,
        string $metric,
        string $oid,
        mixed $rawValue,
        float $normalizedValue,
        string $unit,
        string $source,
        array $laneValues = [],
    ): array {
        $ifName = is_string($info['interface_name'] ?? null) ? $info['interface_name'] : null;
        $label = is_string($info['entity_label'] ?? null) ? $info['entity_label'] : null;
        $index = (string) $info['entity_index'];
        $sensorName = match ($sensorType) {
            'temperature' => $this->names->temperatureName($ifName, $label, $metric, $unit, $index),
            'voltage' => $this->names->voltageName($ifName, $label, $metric, $unit, $index),
            'bias_current' => $this->names->currentName($ifName, $label, $metric, $unit, $index),
            default => $this->names->opticalName($ifName, $label, $metric, $unit, $index),
        };

        $metadata = [
            'discovery_module' => 'huawei_optical',
            'vendor' => 'Huawei',
            'sensor_type' => $sensorType,
            'entity_label' => $label,
            'optic_type' => $info['optic_type'] ?? null,
            'serial_number' => $info['serial_number'] ?? null,
            'oper_status' => $info['oper_status'] ?? null,
            'source' => $source,
        ];

        if ($laneValues !== []) {
            $metadata['lane_values'] = $laneValues;
            $metadata['lane_value_note'] = 'Huawei reports multi-lane values in one SNMP object; normalized_value uses lane 1.';
        }

        return [
            'sensor_class' => 'optical_dom',
            'sensor_name' => $sensorName,
            'sensor_type' => $sensorType,
            'interface_index' => $info['interface_index'] ?? null,
            'interface_name' => $ifName,
            'entity_index' => $info['entity_index'],
            'oid' => $oid,
            'raw_value' => $rawValue,
            'normalized_value' => $normalizedValue,
            'unit' => $unit,
            'status' => (int) ($info['oper_status'] ?? 0) === 0 ? 'ok' : 'unknown',
            'metadata_json' => json_encode($metadata),
        ];
    }

    /**
     * @return array{value:float, unit:string}|null
     */
    private function normalizeMicrowattPower(mixed $value): ?array
    {
        $microwatts = SnmpValueHelper::numeric($value);

        if ($microwatts === null || $microwatts <= 0.0 || $this->isInvalidHuaweiValue($microwatts, [-1.0])) {
            return null;
        }

        return [
            'value' => round(10.0 * log10($microwatts / 1000.0), 6),
            'unit' => 'dBm',
        ];
    }

    private function isInvalidHuaweiValue(float $value, array $invalidValues): bool
    {
        foreach ($invalidValues as $invalidValue) {
            if (abs($value - $invalidValue) < 0.000001) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<float>
     */
    private function numericList(mixed $value): array
    {
        $text = $this->cleanText($value);
        if ($text === null) {
            return [];
        }

        if (preg_match_all('/-?\d+(?:\.\d+)?/', $text, $matches) === 0) {
            return [];
        }

        return array_map(static fn (string $number): float => (float) $number, $matches[0]);
    }

    private function cleanText(mixed $value): ?string
    {
        $text = trim((string) $value);
        $text = preg_replace('/^(?:STRING|INTEGER|OID|Gauge32|Counter32|Counter64|Hex-STRING):\s*/i', '', $text) ?? $text;
        $text = trim($text, "\" \t\n\r\0\x0B");

        return $text === '' || $text === '--' ? null : $text;
    }
}
