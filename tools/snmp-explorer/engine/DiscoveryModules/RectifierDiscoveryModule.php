<?php

declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Helpers\SnmpValueHelper;

final readonly class RectifierDiscoveryModule implements DiscoveryModuleInterface
{
    // Management Interface OIDs (e0 / eth0)
    private const string IF_IN_OCTETS = '1.3.6.1.2.1.2.2.1.10.1';
    private const string IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8.1';
    private const string IF_OUT_OCTETS = '1.3.6.1.2.1.2.2.1.16.1';

    // Standard Power & Environmental OID Maps (Emerson/NetSure/Vertiv/Eltek/Huawei Power MIBs)
    private const array RECTIFIER_OIDS = [
        'AC Fail' => [
            '1.3.6.1.4.1.318.1.1.1.3.2.1',     // APC/Vertiv AC Fail
            '1.3.6.1.4.1.6302.2.1.2.10',     // Eltek AC Fail
            '1.3.6.1.4.1.2011.6.128.1.1.1',  // Huawei Power AC Fail
        ],
        'AC Phase Volt' => [
            '1.3.6.1.4.1.318.1.1.1.3.2.1',     // APC/Vertiv AC Voltage
            '1.3.6.1.4.1.6302.2.1.2.1',      // Eltek AC Input Phase Voltage
            '1.3.6.1.4.1.2011.6.128.1.1.2',  // Huawei AC Phase Voltage
        ],
        'Batt Current' => [
            '1.3.6.1.4.1.318.1.1.1.2.2.3',     // APC/Vertiv Battery Current
            '1.3.6.1.4.1.6302.2.1.1.3',      // Eltek Battery Current
            '1.3.6.1.4.1.2011.6.128.1.2.2',  // Huawei Battery Current
        ],
        'Batt Fuse Fail' => [
            '1.3.6.1.4.1.6302.2.1.1.8',      // Eltek Battery Fuse Alarm
            '1.3.6.1.4.1.2011.6.128.1.2.10', // Huawei Battery Fuse Fail
        ],
        'Batt Voltage' => [
            '1.3.6.1.4.1.318.1.1.1.2.2.1',     // APC/Vertiv Battery Voltage
            '1.3.6.1.4.1.6302.2.1.1.2',      // Eltek Battery Voltage
            '1.3.6.1.4.1.2011.6.128.1.2.1',  // Huawei Battery Voltage
        ],
        'Door Open' => [
            '1.3.6.1.4.1.6302.2.1.5.1',      // Door Contact Alarm
            '1.3.6.1.4.1.2011.6.128.1.5.1',  // Huawei Door Sensor
        ],
        'System Power' => [
            '1.3.6.1.4.1.318.1.1.1.4.2.3',     // APC Output Power
            '1.3.6.1.4.1.6302.2.1.3.4',      // Eltek Total Rectifier System Power
            '1.3.6.1.4.1.2011.6.128.1.3.4',  // Huawei Power System Output Power
        ],
        'Load Current' => [
            '1.3.6.1.4.1.318.1.1.1.4.2.4',     // Output Load Current
            '1.3.6.1.4.1.6302.2.1.3.3',      // Eltek Load Current
            '1.3.6.1.4.1.2011.6.128.1.3.3',  // Huawei Load Current
        ],
        'Load Fuse Fail' => [
            '1.3.6.1.4.1.6302.2.1.3.8',      // Load Fuse Alarm
            '1.3.6.1.4.1.2011.6.128.1.3.8',  // Huawei Load Fuse Fail
        ],
        'Rectifail' => [
            '1.3.6.1.4.1.6302.2.1.4.10',     // Rectifier Module Alarm
            '1.3.6.1.4.1.2011.6.128.1.4.10', // Huawei Rectifier Fail
        ],
    ];

    public function __construct(
        private NormalizerInterface $normalizer,
    ) {
    }

    public function name(): string
    {
        return 'rectifier';
    }

    public function supports(DiscoveryContext $context): bool
    {
        $sysDescr = strtolower((string) ($context->device['sys_descr'] ?? $context->device['sysDescr'] ?? ''));
        $vendorName = strtolower($context->vendor->name());

        if (str_contains($sysDescr, 'rectifier') || str_contains($sysDescr, 'power plant') || str_contains($sysDescr, 'netsure') || str_contains($sysDescr, 'eltek') || str_contains($sysDescr, 'vertiv')) {
            return true;
        }

        // Fast bail: do not probe power rectifier OIDs on switches, routers, firewalls, or standard servers
        if (str_contains($sysDescr, 'switch') || str_contains($sysDescr, 'router') || str_contains($sysDescr, 'firewall') || str_contains($sysDescr, 'software')
            || in_array($vendorName, ['cisco', 'huawei', 'h3c', 'h3c / hpe', 'mikrotik', 'juniper', 'fortinet', 'arista', 'hp', 'dell', 'linux', 'windows'], true)) {
            return false;
        }

        foreach (self::RECTIFIER_OIDS as $oidList) {
            foreach ($oidList as $oid) {
                if ($context->walker->get($oid) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];

        // 1. Management interface metrics (e0)
        $e0In = $context->walker->get(self::IF_IN_OCTETS);
        if ($e0In !== null) {
            $val = SnmpValueHelper::cleanNumber((string) $e0In);
            if ($val !== null) {
                $sensors[] = $this->normalizer->normalize([
                    'name' => 'e0_ifInOctets',
                    'oid' => self::IF_IN_OCTETS,
                    'value' => (float) $val,
                    'type' => 'counter',
                    'unit' => 'bytes',
                    'description' => 'Rectifier management interface e0 inbound octets',
                ], 'rectifier');
            }
        }

        $e0Oper = $context->walker->get(self::IF_OPER_STATUS);
        if ($e0Oper !== null) {
            $val = SnmpValueHelper::cleanNumber((string) $e0Oper);
            if ($val !== null) {
                $sensors[] = $this->normalizer->normalize([
                    'name' => 'e0_ifOperStatus',
                    'oid' => self::IF_OPER_STATUS,
                    'value' => (int) $val,
                    'type' => 'status',
                    'unit' => '',
                    'description' => 'Rectifier management interface e0 operational status (1=up, 2=down)',
                ], 'rectifier');
            }
        }

        $e0Out = $context->walker->get(self::IF_OUT_OCTETS);
        if ($e0Out !== null) {
            $val = SnmpValueHelper::cleanNumber((string) $e0Out);
            if ($val !== null) {
                $sensors[] = $this->normalizer->normalize([
                    'name' => 'e0_ifOutOctets',
                    'oid' => self::IF_OUT_OCTETS,
                    'value' => (float) $val,
                    'type' => 'counter',
                    'unit' => 'bytes',
                    'description' => 'Rectifier management interface e0 outbound octets',
                ], 'rectifier');
            }
        }

        // 2. Rectifier power & environmental metrics
        foreach (self::RECTIFIER_OIDS as $metricName => $oidList) {
            foreach ($oidList as $oid) {
                $rawVal = $context->walker->get($oid);
                if ($rawVal !== null) {
                    $numVal = SnmpValueHelper::cleanNumber((string) $rawVal);
                    if ($numVal !== null) {
                        $unit = match ($metricName) {
                            'AC Phase Volt', 'Batt Voltage' => 'V',
                            'Batt Current', 'Load Current' => 'A',
                            'System Power' => 'W',
                            default => '',
                        };
                        $type = match ($metricName) {
                            'AC Fail', 'Batt Fuse Fail', 'Door Open', 'Load Fuse Fail', 'Rectifail' => 'alarm',
                            default => 'gauge',
                        };

                        $sensors[] = $this->normalizer->normalize([
                            'name' => $metricName,
                            'oid' => $oid,
                            'value' => (float) $numVal,
                            'type' => $type,
                            'unit' => $unit,
                            'description' => sprintf('Rectifier %s metric', $metricName),
                        ], 'rectifier');

                        break; // Stop at first matching OID for this metric
                    }
                }
            }
        }

        return array_values(array_filter($sensors));
    }
}
