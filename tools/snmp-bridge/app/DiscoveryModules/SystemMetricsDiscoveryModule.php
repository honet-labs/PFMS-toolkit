<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Snmp\SnmpHelper;
use SnmpBridge\Helpers\SensorNameFormatter;

/**
 * Discover system-level metrics from standard SNMP MIBs
 * 
 * Discovers:
 * - sysUptime (system running time in 100ths of seconds)
 * - sysDescr (system description)
 * - sysServices (services available)
 * - sysContact (system contact)
 * - sysLocation (system location)
 */
final class SystemMetricsDiscoveryModule implements DiscoveryModuleInterface
{
    private const SNMP_UPTIME_OID = '1.3.6.1.2.1.1.3.0';
    private const SNMP_DESCR_OID = '1.3.6.1.2.1.1.1.0';
    private const SNMP_CONTACT_OID = '1.3.6.1.2.1.1.4.0';
    private const SNMP_LOCATION_OID = '1.3.6.1.2.1.1.6.0';
    private const SNMP_SERVICES_OID = '1.3.6.1.2.1.1.7.0';

    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SensorNameFormatter $formatter = new SensorNameFormatter(),
    ) {
    }

    public function name(): string
    {
        return 'system_metrics';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // System metrics are available on all devices (standard MIB)
        return true;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        // Discover sysUptime
        $uptime = $context->walker->get(self::SNMP_UPTIME_OID);
        if ($uptime !== null && $uptime !== '') {
            $uptimeSeconds = (int) $uptime / 100;  // Convert from 100ths of seconds
            
            $sensor = [
                'sensor_class' => 'system',
                'sensor_name' => $this->formatter->systemMetric('System - Uptime', 'secs'),
                'sensor_type' => 'uptime',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => null,
                'oid' => self::SNMP_UPTIME_OID,
                'raw_value' => (string) $uptimeSeconds,
                'unit' => 'secs',
                'scale' => 'units',
                'precision' => 0,
                'status' => 'ok',
                'metadata' => [
                    'discovery_module' => 'SystemMetricsDiscoveryModule',
                    'source' => 'SNMPv2-MIB sysUptime',
                ],
            ];

            $normalized = $this->normalizer->normalize($sensor);
            if ($normalized !== null) {
                $sensors[] = $normalized;
            }
        }

        // Discover sysDescription
        $descr = $context->walker->get(self::SNMP_DESCR_OID);
        if ($descr !== null && $descr !== '') {
            $sensor = [
                'sensor_class' => 'system',
                'sensor_name' => $this->formatter->generic('System', 'Description'),
                'sensor_type' => 'text',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => null,
                'oid' => self::SNMP_DESCR_OID,
                'raw_value' => (string) $descr,
                'unit' => '',
                'scale' => 'units',
                'precision' => 0,
                'status' => 'ok',
                'metadata' => [
                    'discovery_module' => 'SystemMetricsDiscoveryModule',
                    'source' => 'SNMPv2-MIB sysDescr',
                    'is_text' => true,
                ],
            ];

            $normalized = $this->normalizer->normalize($sensor);
            if ($normalized !== null) {
                $sensors[] = $normalized;
            }
        }

        // Discover sysContact
        $contact = $context->walker->get(self::SNMP_CONTACT_OID);
        if ($contact !== null && $contact !== '' && $contact !== '(No Contact String)') {
            $sensor = [
                'sensor_class' => 'system',
                'sensor_name' => $this->formatter->generic('System', 'Contact'),
                'sensor_type' => 'text',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => null,
                'oid' => self::SNMP_CONTACT_OID,
                'raw_value' => (string) $contact,
                'unit' => '',
                'scale' => 'units',
                'precision' => 0,
                'status' => 'ok',
                'metadata' => [
                    'discovery_module' => 'SystemMetricsDiscoveryModule',
                    'source' => 'SNMPv2-MIB sysContact',
                    'is_text' => true,
                ],
            ];

            $normalized = $this->normalizer->normalize($sensor);
            if ($normalized !== null) {
                $sensors[] = $normalized;
            }
        }

        // Discover sysLocation
        $location = $context->walker->get(self::SNMP_LOCATION_OID);
        if ($location !== null && $location !== '' && $location !== '(No Location String)') {
            $sensor = [
                'sensor_class' => 'system',
                'sensor_name' => $this->formatter->generic('System', 'Location'),
                'sensor_type' => 'text',
                'interface_index' => null,
                'interface_name' => null,
                'entity_index' => null,
                'oid' => self::SNMP_LOCATION_OID,
                'raw_value' => (string) $location,
                'unit' => '',
                'scale' => 'units',
                'precision' => 0,
                'status' => 'ok',
                'metadata' => [
                    'discovery_module' => 'SystemMetricsDiscoveryModule',
                    'source' => 'SNMPv2-MIB sysLocation',
                    'is_text' => true,
                ],
            ];

            $normalized = $this->normalizer->normalize($sensor);
            if ($normalized !== null) {
                $sensors[] = $normalized;
            }
        }

        return $sensors;
    }
}
