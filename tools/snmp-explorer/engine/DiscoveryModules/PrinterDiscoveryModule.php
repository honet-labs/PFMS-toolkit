<?php
declare(strict_types=1);

namespace SnmpBridge\DiscoveryModules;

use SnmpBridge\Contracts\DiscoveryModuleInterface;
use SnmpBridge\Contracts\NormalizerInterface;
use SnmpBridge\Core\Discovery\DiscoveryContext;
use SnmpBridge\Core\Snmp\SnmpHelper;
use SnmpBridge\Helpers\SnmpValueHelper;
use SnmpBridge\Helpers\StringHelper;

/**
 * Discover Printer metrics (Status, Total Pages, Ink/Toner Levels, Alerts)
 */
final readonly class PrinterDiscoveryModule implements DiscoveryModuleInterface
{
    // HOST-RESOURCES-MIB
    private const string HR_DEVICE_TYPE     = '.1.3.6.1.2.1.25.3.2.1.2';
    private const string HR_DEVICE_DESCR    = '.1.3.6.1.2.1.25.3.2.1.3';
    private const string HR_DEVICE_STATUS   = '.1.3.6.1.2.1.25.3.2.1.5';
    private const string HR_DEVICE_ERRORS   = '.1.3.6.1.2.1.25.3.2.1.6';
    private const string HR_PRINTER_STATUS              = '.1.3.6.1.2.1.25.3.5.1.1';
    private const string HR_PRINTER_DETECTED_ERROR_STATE = '.1.3.6.1.2.1.25.3.5.1.2';

    // Network
    private const string IP_ADDRESS      = '.1.3.6.1.2.1.4.20.1.1';
    private const string IF_PHYS_ADDRESS = '.1.3.6.1.2.1.2.2.1.6';

    // Printer-MIB (mib-2.43)
    private const string PRT_GENERAL_PRINTER_NAME        = '.1.3.6.1.2.1.43.5.1.1.16';
    private const string PRT_GENERAL_SERIAL_NUMBER       = '.1.3.6.1.2.1.43.5.1.1.17';
    private const string PRT_CONSOLE_DISPLAY_TEXT        = '.1.3.6.1.2.1.43.16.5.1.2';
    private const string PRT_MARKER_LIFE_COUNT           = '.1.3.6.1.2.1.43.10.2.1.4';
    private const string PRT_MARKER_SUPPLIES_TYPE        = '.1.3.6.1.2.1.43.11.1.1.5';
    private const string PRT_MARKER_SUPPLIES_DESCRIPTION = '.1.3.6.1.2.1.43.11.1.1.6';
    private const string PRT_MARKER_SUPPLIES_UNIT        = '.1.3.6.1.2.1.43.11.1.1.7';
    private const string PRT_MARKER_SUPPLIES_MAX_CAPACITY = '.1.3.6.1.2.1.43.11.1.1.8';
    private const string PRT_MARKER_SUPPLIES_LEVEL       = '.1.3.6.1.2.1.43.11.1.1.9';
    private const string PRT_MARKER_COLORANT_VALUE       = '.1.3.6.1.2.1.43.12.1.1.4';
    private const string PRT_ALERT_SEVERITY              = '.1.3.6.1.2.1.43.18.1.1.2';
    
    // Additional Printer-MIB groups
    private const string PRT_COVER_DESCRIPTION           = '.1.3.6.1.2.1.43.6.1.1.2';
    private const string PRT_COVER_STATUS                = '.1.3.6.1.2.1.43.6.1.1.3';
    private const string PRT_INPUT_CAPACITY_UNIT         = '.1.3.6.1.2.1.43.8.2.1.7';
    private const string PRT_INPUT_MAX_CAPACITY          = '.1.3.6.1.2.1.43.8.2.1.8';
    private const string PRT_INPUT_CURRENT_LEVEL         = '.1.3.6.1.2.1.43.8.2.1.9';
    private const string PRT_INPUT_NAME                  = '.1.3.6.1.2.1.43.8.2.1.13';
    private const string PRT_OUTPUT_MAX_CAPACITY         = '.1.3.6.1.2.1.43.9.2.1.3';
    private const string PRT_OUTPUT_REMAINING_CAPACITY   = '.1.3.6.1.2.1.43.9.2.1.4';
    private const string PRT_OUTPUT_NAME                 = '.1.3.6.1.2.1.43.9.2.1.8';

    /** @var array<int, string> */
    private const array HR_DEVICE_STATUS_LABELS = [
        1 => 'unknown',
        2 => 'running',
        3 => 'warning',
        4 => 'testing',
        5 => 'down',
    ];

    /** @var array<int, string> */
    private const array HR_PRINTER_STATUS_LABELS = [
        1 => 'other',
        2 => 'unknown',
        3 => 'idle',
        4 => 'printing',
        5 => 'warmup',
    ];

    public function __construct(private NormalizerInterface $normalizer)
    {
    }

    public function name(): string
    {
        return 'printer';
    }

    public function supports(DiscoveryContext $context): bool
    {
        // Epson printers are always supported – they expose supply OIDs directly.
        if ($context->vendor->name() === 'Epson') {
            return true;
        }
        // Original detection logic for other devices.
        if ($context->walker->get(self::HR_PRINTER_STATUS . '.1') !== null) {
            return true;
        }
        if ($context->walker->get(self::PRT_MARKER_LIFE_COUNT . '.1.1') !== null) {
            return true;
        }
        if ($context->walker->get(self::PRT_GENERAL_SERIAL_NUMBER . '.1') !== null) {
            return true;
        }
        return $context->walker->get(self::PRT_MARKER_SUPPLIES_DESCRIPTION . '.1') !== null;
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];
        $printerIndex = $this->printerDeviceIndex($context);

        $this->addIdentitySensors($context, $printerIndex, $sensors);
        $this->addHealthSensors($context, $printerIndex, $sensors);
        $this->addTotalPageCount($context, $sensors);
        $this->addMarkerSupplies($context, $sensors);
        $this->addInputTrays($context, $sensors);
        $this->addOutputTrays($context, $sensors);
        $this->addCovers($context, $sensors);
        $this->addNetworkSensors($context, $sensors);
        $this->addAlertCount($context, $sensors);

        return $sensors;
    }

    /**
     * @param list<array<string, mixed>> $sensors
     */
    private function addIdentitySensors(DiscoveryContext $context, string $printerIndex, array &$sensors): void
    {
        $sensors[] = $this->stringSensor(
            'DEVICE IDENTITY',
            'System Description',
            SnmpHelper::SYS_DESCR,
            (string) ($context->device['sys_descr'] ?? ''),
            'SNMPv2-MIB::sysDescr.0',
        );
        $sensors[] = $this->stringSensor(
            'DEVICE IDENTITY',
            'Hostname / System Name',
            SnmpHelper::SYS_NAME,
            (string) ($context->device['hostname'] ?? ''),
            'SNMPv2-MIB::sysName.0',
        );

        if ($context->vendor->name() === 'Epson') {
            $deviceDescription = $context->walker->get(self::HR_DEVICE_DESCR . '.' . $printerIndex);
        } else {
            $deviceDescription = $context->walker->get(self::HR_DEVICE_DESCR . '.' . $printerIndex);
            if ($deviceDescription === null || $deviceDescription === '') {
                $deviceDescription = $this->firstNonEmptyValue($context->walker->walkIndexed(self::HR_DEVICE_DESCR));
            }
        }

        if ($deviceDescription !== null && $deviceDescription !== '') {
            $sensors[] = $this->stringSensor(
                'DEVICE IDENTITY',
                'Product Model / Device Description',
                self::HR_DEVICE_DESCR . '.' . $printerIndex,
                $deviceDescription,
                'HOST-RESOURCES-MIB::hrDeviceDescr.' . $printerIndex,
            );
        }

        if ($context->vendor->name() === 'Epson') {
            $pName = $context->walker->get(self::PRT_GENERAL_PRINTER_NAME . '.1');
            $printerName = $pName !== null && $pName !== '' ? ['index' => 1, 'value' => $pName] : null;
        } else {
            $printerName = $this->firstNonEmpty($context->walker->walkIndexed(self::PRT_GENERAL_PRINTER_NAME));
        }

        if ($printerName !== null && $printerName['value'] !== $deviceDescription) {
            $sensors[] = $this->stringSensor(
                'DEVICE IDENTITY',
                'Printer Model Name',
                self::PRT_GENERAL_PRINTER_NAME . '.' . $printerName['index'],
                $printerName['value'],
                'Printer-MIB::prtGeneralPrinterName.' . $printerName['index'],
            );
        }

        if ($context->vendor->name() === 'Epson') {
            $sName = $context->walker->get(self::PRT_GENERAL_SERIAL_NUMBER . '.1');
            $serial = $sName !== null && $sName !== '' ? ['index' => 1, 'value' => $sName] : null;
        } else {
            $serial = $this->firstNonEmpty($context->walker->walkIndexed(self::PRT_GENERAL_SERIAL_NUMBER));
        }

        if ($serial !== null) {
            $sensors[] = $this->stringSensor(
                'DEVICE IDENTITY',
                'Printer Serial Number',
                self::PRT_GENERAL_SERIAL_NUMBER . '.' . $serial['index'],
                $serial['value'],
                'Printer-MIB::prtGeneralSerialNumber.' . $serial['index'],
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $sensors
     */
    private function addHealthSensors(DiscoveryContext $context, string $printerIndex, array &$sensors): void
    {
        $uptime = $context->walker->get('.1.3.6.1.2.1.1.3.0');
        if ($uptime !== null && $uptime !== '') {
            $this->addNumericSensor(
                $sensors,
                'DEVICE STATUS & HEALTH',
                'System Uptime',
                '.1.3.6.1.2.1.1.3.0',
                SnmpValueHelper::numeric($uptime) !== null ? (string) ((int) SnmpValueHelper::numeric($uptime) / 100) : $uptime,
                'secs',
                'uptime',
                [
                    'source' => 'SNMPv2-MIB::sysUpTimeInstance',
                    'raw_timeticks' => $uptime,
                ],
            );
        }

        $deviceStatus = $context->walker->get(self::HR_DEVICE_STATUS . '.' . $printerIndex);
        if ($deviceStatus !== null && $deviceStatus !== '') {
            $code = $this->statusCode($deviceStatus);
            $this->addNumericSensor(
                $sensors,
                'DEVICE STATUS & HEALTH',
                'Device Status',
                self::HR_DEVICE_STATUS . '.' . $printerIndex,
                (string) ($code ?? $deviceStatus),
                '',
                'status',
                [
                    'source' => 'HOST-RESOURCES-MIB::hrDeviceStatus.' . $printerIndex,
                    'label' => $code !== null ? (self::HR_DEVICE_STATUS_LABELS[$code] ?? 'unknown') : $deviceStatus,
                    'raw_status' => $deviceStatus,
                ],
            );
        }

        $printerStatus = $context->walker->get(self::HR_PRINTER_STATUS . '.' . $printerIndex);
        if ($printerStatus !== null && $printerStatus !== '') {
            $code = $this->statusCode($printerStatus);
            $this->addNumericSensor(
                $sensors,
                'DEVICE STATUS & HEALTH',
                'Printer Status',
                self::HR_PRINTER_STATUS . '.' . $printerIndex,
                (string) ($code ?? $printerStatus),
                '',
                'status',
                [
                    'source' => 'HOST-RESOURCES-MIB::hrPrinterStatus.' . $printerIndex,
                    'label' => $code !== null ? (self::HR_PRINTER_STATUS_LABELS[$code] ?? 'unknown') : $printerStatus,
                    'raw_status' => $printerStatus,
                ],
            );
        }

        $errorState = $context->walker->get(self::HR_PRINTER_DETECTED_ERROR_STATE . '.' . $printerIndex);
        if ($errorState !== null && $errorState !== '') {
            $this->addNumericSensor(
                $sensors,
                'DEVICE STATUS & HEALTH',
                'Detected Error State',
                self::HR_PRINTER_DETECTED_ERROR_STATE . '.' . $printerIndex,
                (string) ($this->hexStateToInteger($errorState) ?? 0),
                '',
                'error_state',
                [
                    'source' => 'HOST-RESOURCES-MIB::hrPrinterDetectedErrorState.' . $printerIndex,
                    'raw_error_state' => $errorState,
                    'description' => $this->detectedErrorDescription($errorState),
                ],
            );
        }

        $deviceErrors = $context->walker->get(self::HR_DEVICE_ERRORS . '.' . $printerIndex);
        if ($deviceErrors !== null && $deviceErrors !== '') {
            $this->addNumericSensor(
                $sensors,
                'DEVICE STATUS & HEALTH',
                'Device Error Counter',
                self::HR_DEVICE_ERRORS . '.' . $printerIndex,
                $deviceErrors,
                'errors',
                'counter',
                [
                    'source' => 'HOST-RESOURCES-MIB::hrDeviceErrors.' . $printerIndex,
                ],
            );
        }

        if ($context->vendor->name() === 'Epson') {
            $dispText = $context->walker->get(self::PRT_CONSOLE_DISPLAY_TEXT . '.1.1');
            $display = $dispText !== null && $dispText !== '' ? ['index' => '1.1', 'value' => $dispText] : null;
        } else {
            $display = $this->firstNonEmpty($context->walker->walkIndexed(self::PRT_CONSOLE_DISPLAY_TEXT));
        }

        if ($display !== null && $display['value'] !== '') {
            $sensors[] = $this->stringSensor(
                'DEVICE STATUS & HEALTH',
                'Printer Display Message',
                self::PRT_CONSOLE_DISPLAY_TEXT . '.' . $display['index'],
                $display['value'],
                'Printer-MIB::prtConsoleDisplayText.' . $display['index'],
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $sensors
     */
    private function addNetworkSensors(DiscoveryContext $context, array &$sensors): void
    {
        if ($context->vendor->name() === 'Epson') {
            return;
        }

        foreach ($context->walker->walkIndexed(self::IP_ADDRESS) as $index => $ipAddress) {
            if (!$this->isUsableIpAddress((string) $ipAddress)) {
                continue;
            }

            $suffix = str_replace('.', '_', (string) $ipAddress);
            $sensors[] = $this->stringSensor(
                'NETWORK METRICS',
                'Active IP Address ' . $suffix,
                self::IP_ADDRESS . '.' . $index,
                (string) $ipAddress,
                'IP-MIB::ipAdEntAddr.' . $index,
            );
        }

        foreach ($context->walker->walkIndexed(self::IF_PHYS_ADDRESS) as $index => $macAddress) {
            $formatted = $this->formatMacAddress((string) $macAddress);

            if ($formatted === null) {
                continue;
            }

            $sensors[] = $this->stringSensor(
                'NETWORK METRICS',
                'MAC Address ifIndex ' . $index,
                self::IF_PHYS_ADDRESS . '.' . $index,
                $formatted,
                'IF-MIB::ifPhysAddress.' . $index,
            );
        }
    }


    /**
     * Discover ink/toner supplies.
     *
     * Epson printers expose the standard Printer‑MIB tables:
     *   - .1.3.6.1.2.1.43.11.1.1.6  → prtMarkerSuppliesDescription
     *   - .1.3.6.1.2.1.43.11.1.1.9  → prtMarkerSuppliesLevel (percentage)
     *
     * The walkIndexed calls retrieve the description (e.g. "Black Ink Bottle")
     * and the corresponding level (e.g. 78).  The helper `supplyName()` normalises
     * the description by removing generic words like "bottle" so the sensor name
     * becomes "Black Ink".  Negative SNMP values are handled elsewhere.
     */
    private function addMarkerSupplies(DiscoveryContext $context, array &$sensors): void
    {
        $descriptions = [];
        $types = [];
        $units = [];
        $maxCapacities = [];
        $levels = [];
        $colorants = [];

        // Epson printers lock up their SNMP agents when walked, so we must use direct GETs
        if ($context->vendor->name() === 'Epson') {
            foreach (['1.1', '1.2', '1.3', '1.4'] as $idx) {
                $desc = $context->walker->get(self::PRT_MARKER_SUPPLIES_DESCRIPTION . '.' . $idx);
                if ($desc !== null && $desc !== '') {
                    $descriptions[$idx] = $desc;
                    $types[$idx] = $context->walker->get(self::PRT_MARKER_SUPPLIES_TYPE . '.' . $idx);
                    $units[$idx] = $context->walker->get(self::PRT_MARKER_SUPPLIES_UNIT . '.' . $idx);
                    $maxCapacities[$idx] = $context->walker->get(self::PRT_MARKER_SUPPLIES_MAX_CAPACITY . '.' . $idx);
                    $levels[$idx] = $context->walker->get(self::PRT_MARKER_SUPPLIES_LEVEL . '.' . $idx);
                    $colorants[$idx] = $context->walker->get(self::PRT_MARKER_COLORANT_VALUE . '.' . $idx);
                }
            }
        } else {
            $descriptions = $context->walker->walkIndexed(self::PRT_MARKER_SUPPLIES_DESCRIPTION);
            $types = $context->walker->walkIndexed(self::PRT_MARKER_SUPPLIES_TYPE);
            $units = $context->walker->walkIndexed(self::PRT_MARKER_SUPPLIES_UNIT);
            $maxCapacities = $context->walker->walkIndexed(self::PRT_MARKER_SUPPLIES_MAX_CAPACITY);
            $levels = $context->walker->walkIndexed(self::PRT_MARKER_SUPPLIES_LEVEL);
            $colorants = $context->walker->walkIndexed(self::PRT_MARKER_COLORANT_VALUE);
        }

        foreach ($descriptions as $index => $description) {
            $level = SnmpValueHelper::numeric($levels[$index] ?? null);
            $maxCapacity = SnmpValueHelper::numeric($maxCapacities[$index] ?? null);

            if ($level === null) {
                continue;
            }

            // Add a string sensor for the supply description (optional display)
            $sensors[] = $this->stringSensor(
                'PRINTER CONSUMABLES & COUNTERS',
                $description . ' Description',
                self::PRT_MARKER_SUPPLIES_DESCRIPTION . '.' . $index,
                $description,
                'Printer-MIB::prtMarkerSuppliesDescription.' . $index,
            );

            $supplyName = $this->supplyName((string) $description, $colorants[$index] ?? null, (string) $index);
            $unit = $this->supplyUnit($units[$index] ?? null, $maxCapacity);

            // Handle special SNMP negative values for ink/toner levels:
            //  -1 = other/unavailable → skip
            //  -2 = unknown (supply present but level unknown) → report as 0
            //  -3 = OK / supply is full → report as 100%
            $displayLevel = $level;
            $levelPercent = null;
            $levelStatus = 'ok';

            if ($level == -1) {
                continue; // truly unavailable, skip
            } elseif ($level == -3) {
                // -3 means "OK" / supply is at full capacity
                $displayLevel = $maxCapacity !== null && $maxCapacity > 0 ? $maxCapacity : 100;
                $levelPercent = 100.0;
                $unit = '%';
            } elseif ($level == -2) {
                // -2 means "unknown" — supply exists but level can't be determined
                $displayLevel = 0;
                $levelPercent = null;
                $levelStatus = 'unknown';
            } elseif ($level >= 0 && $maxCapacity !== null && $maxCapacity > 0) {
                $levelPercent = round(($level / $maxCapacity) * 100, 2);
            }

            // Ink/Toner current level
            $this->addNumericSensor(
                $sensors,
                'PRINTER CONSUMABLES & COUNTERS',
                $supplyName,
                self::PRT_MARKER_SUPPLIES_LEVEL . '.' . $index,
                (string) $displayLevel,
                $unit,
                'supply_level',
                [
                    'source' => 'Printer-MIB::prtMarkerSuppliesLevel.' . $index,
                    'supply_name' => $supplyName,
                    'supply_description' => $description,
                    'supply_type' => $types[$index] ?? null,
                    'max_capacity' => $maxCapacity,
                    'level_percent' => $levelPercent,
                    'raw_snmp_level' => $level,
                    'status' => $levelStatus,
                    'description_oid' => self::PRT_MARKER_SUPPLIES_DESCRIPTION . '.' . $index,
                ],
            );

            // Ink/Toner percentage (only when calculable)
            if ($levelPercent !== null) {
                $this->addNumericSensor(
                    $sensors,
                    'PRINTER CONSUMABLES & COUNTERS',
                    $supplyName . ' Usage %',
                    self::PRT_MARKER_SUPPLIES_LEVEL . '.' . $index . '.pct',
                    (string) $levelPercent,
                    '%',
                    'supply_percent',
                    [
                        'source' => 'Printer-MIB (calculated)',
                        'supply_name' => $supplyName,
                        'level' => $displayLevel,
                        'max_capacity' => $maxCapacity,
                    ],
                );
            }

            // Ink/Toner max capacity
            if ($maxCapacity !== null && $maxCapacity > 0) {
                $this->addNumericSensor(
                    $sensors,
                    'PRINTER CONSUMABLES & COUNTERS',
                    $supplyName . ' Capacity',
                    self::PRT_MARKER_SUPPLIES_MAX_CAPACITY . '.' . $index,
                    (string) $maxCapacity,
                    $unit !== '%' ? $unit : 'units',
                    'supply_capacity',
                    [
                        'source' => 'Printer-MIB::prtMarkerSuppliesMaxCapacity.' . $index,
                        'supply_name' => $supplyName,
                        'supply_description' => $description,
                    ],
                );
            }
        }
    }

    private function addTotalPageCount(DiscoveryContext $context, array &$sensors): void
    {
        $lifeCounts = [];
        
        // Epson printers lock up their SNMP agents when walked, so we must use direct GETs
        if ($context->vendor->name() === 'Epson') {
            // Try multiple indices for Epson
            $val = $context->walker->get(self::PRT_MARKER_LIFE_COUNT . '.1.1');
            if ($val !== null && $val !== '') {
                $lifeCounts['1.1'] = $val;
            }
            $val2 = $context->walker->get(self::PRT_MARKER_LIFE_COUNT . '.1');
            if ($val2 !== null && $val2 !== '') {
                $lifeCounts['1'] = $val2;
            }
        } else {
            // For other vendors, walk all indices (including .1.1, .1.2, etc.)
            $lifeCounts = $context->walker->walkIndexed(self::PRT_MARKER_LIFE_COUNT);
        }

        // Add all discovered page counts, not just the first
        foreach ($lifeCounts as $idx => $value) {
            // Validate the value is numeric
            if (!is_numeric($value)) {
                continue;
            }
            if ((int)$value < 0) {
                continue;
            }
            $sensorName = 'Pages Count Used';
            // Add index notation if multiple indices
            if (count($lifeCounts) > 1) {
                $sensorName .= " [{$idx}]";
            }
            
            $this->addNumericSensor(
                $sensors,
                'PRINTER METRICS',
                $sensorName,
                self::PRT_MARKER_LIFE_COUNT . '.' . $idx,
                $value,
                'pages',
                'remote_snmp',
                ['source' => 'Printer-MIB::prtMarkerLifeCount.' . $idx],
            );
        }
    }

    /**
     * Add a sensor counting active printer alerts.
     */
    private function addAlertCount(DiscoveryContext $context, array &$sensors): void
    {
        if ($context->vendor->name() === 'Epson') {
            // Epson devices crash on bulk walks and alert walking is dangerous for them
            return;
        }

        $alerts = $context->walker->walkIndexed(self::PRT_ALERT_SEVERITY);
        if ($alerts !== []) {
            $this->addNumericSensor(
                $sensors,
                'DEVICE STATUS & HEALTH',
                'Active Printer Alerts',
                '.1.3.6.1.2.1.43.18.1',
                (string) count($alerts),
                'alerts',
                'counter',
                ['source' => 'Printer-MIB::prtAlertTable'],
            );
        }
    }

    private function addInputTrays(DiscoveryContext $context, array &$sensors): void
    {
        $names = [];
        $levels = [];
        $maxCapacities = [];
        $units = [];

        if ($context->vendor->name() === 'Epson') {
            foreach (['1.1', '1.2', '1.3', '1.4', '1.5'] as $idx) {
                $name = $context->walker->get(self::PRT_INPUT_NAME . '.' . $idx);
                if ($name !== null && $name !== '') {
                    $names[$idx] = $name;
                    $levels[$idx] = $context->walker->get(self::PRT_INPUT_CURRENT_LEVEL . '.' . $idx);
                    $maxCapacities[$idx] = $context->walker->get(self::PRT_INPUT_MAX_CAPACITY . '.' . $idx);
                    $units[$idx] = $context->walker->get(self::PRT_INPUT_CAPACITY_UNIT . '.' . $idx);
                }
            }
        } else {
            $names = $context->walker->walkIndexed(self::PRT_INPUT_NAME);
            $levels = $context->walker->walkIndexed(self::PRT_INPUT_CURRENT_LEVEL);
            $maxCapacities = $context->walker->walkIndexed(self::PRT_INPUT_MAX_CAPACITY);
            $units = $context->walker->walkIndexed(self::PRT_INPUT_CAPACITY_UNIT);
        }

        foreach ($names as $index => $name) {
            $level = SnmpValueHelper::numeric($levels[$index] ?? null);
            $maxCapacity = SnmpValueHelper::numeric($maxCapacities[$index] ?? null);
            $unit = $this->supplyUnit($units[$index] ?? null, $maxCapacity);

            if ($level === null) {
                continue;
            }

            $displayLevel = $level;
            $levelPercent = null;

            if ($level == -1) {
                continue; // unavailable
            } elseif ($level == -3) {
                $displayLevel = $maxCapacity !== null && $maxCapacity > 0 ? $maxCapacity : 100;
                $levelPercent = 100.0;
                $unit = '%';
            } elseif ($level == -2) {
                $displayLevel = 0;
            } elseif ($level >= 0 && $maxCapacity !== null && $maxCapacity > 0) {
                $levelPercent = $level <= 100 && $maxCapacity < $level ? $level : round(($level / $maxCapacity) * 100, 2);
                if ($levelPercent > 100) {
                    $levelPercent = 100.0;
                }
            }

            $trayName = trim((string) $name);
            if ($trayName === '') {
                $trayName = 'Input Tray ' . $index;
            }

            $this->addNumericSensor(
                $sensors,
                'PRINTER TRAYS & COVERS',
                $trayName . ' Level',
                self::PRT_INPUT_CURRENT_LEVEL . '.' . $index,
                (string) $displayLevel,
                $unit,
                'supply_level',
                ['source' => 'Printer-MIB::prtInputCurrentLevel.' . $index, 'max_capacity' => $maxCapacity]
            );

            if ($levelPercent !== null) {
                $this->addNumericSensor(
                    $sensors,
                    'PRINTER TRAYS & COVERS',
                    $trayName . ' %',
                    self::PRT_INPUT_CURRENT_LEVEL . '.' . $index . '.pct',
                    (string) $levelPercent,
                    '%',
                    'supply_percent',
                    ['source' => 'Printer-MIB (calculated)', 'max_capacity' => $maxCapacity]
                );
            }
        }
    }

    private function addOutputTrays(DiscoveryContext $context, array &$sensors): void
    {
        $names = [];
        $levels = [];
        $maxCapacities = [];

        if ($context->vendor->name() === 'Epson') {
            foreach (['1.1', '1.2', '1.3', '1.4'] as $idx) {
                $name = $context->walker->get(self::PRT_OUTPUT_NAME . '.' . $idx);
                if ($name !== null && $name !== '') {
                    $names[$idx] = $name;
                    $levels[$idx] = $context->walker->get(self::PRT_OUTPUT_REMAINING_CAPACITY . '.' . $idx);
                    $maxCapacities[$idx] = $context->walker->get(self::PRT_OUTPUT_MAX_CAPACITY . '.' . $idx);
                }
            }
        } else {
            $names = $context->walker->walkIndexed(self::PRT_OUTPUT_NAME);
            $levels = $context->walker->walkIndexed(self::PRT_OUTPUT_REMAINING_CAPACITY);
            $maxCapacities = $context->walker->walkIndexed(self::PRT_OUTPUT_MAX_CAPACITY);
        }

        foreach ($names as $index => $name) {
            $level = SnmpValueHelper::numeric($levels[$index] ?? null);
            $maxCapacity = SnmpValueHelper::numeric($maxCapacities[$index] ?? null);

            if ($level === null) {
                continue;
            }

            $displayLevel = $level;
            $levelPercent = null;

            if ($level == -1) {
                continue; // unavailable
            } elseif ($level == -3) {
                $displayLevel = $maxCapacity !== null && $maxCapacity > 0 ? $maxCapacity : 100;
                $levelPercent = 100.0;
            } elseif ($level == -2) {
                $displayLevel = 0;
            } elseif ($level >= 0 && $maxCapacity !== null && $maxCapacity > 0) {
                $levelPercent = $level <= 100 && $maxCapacity < $level ? $level : round(($level / $maxCapacity) * 100, 2);
                if ($levelPercent > 100) {
                    $levelPercent = 100.0;
                }
            }

            $trayName = trim((string) $name);
            if ($trayName === '') {
                $trayName = 'Output Tray ' . $index;
            }

            $this->addNumericSensor(
                $sensors,
                'PRINTER TRAYS & COVERS',
                $trayName . ' Remaining',
                self::PRT_OUTPUT_REMAINING_CAPACITY . '.' . $index,
                (string) $displayLevel,
                'units',
                'supply_level',
                ['source' => 'Printer-MIB::prtOutputRemainingCapacity.' . $index, 'max_capacity' => $maxCapacity]
            );

            if ($levelPercent !== null) {
                $this->addNumericSensor(
                    $sensors,
                    'PRINTER TRAYS & COVERS',
                    $trayName . ' %',
                    self::PRT_OUTPUT_REMAINING_CAPACITY . '.' . $index . '.pct',
                    (string) $levelPercent,
                    '%',
                    'supply_percent',
                    ['source' => 'Printer-MIB (calculated)', 'max_capacity' => $maxCapacity]
                );
            }
        }
    }

    private function addCovers(DiscoveryContext $context, array &$sensors): void
    {
        $descriptions = [];
        $statuses = [];

        if ($context->vendor->name() === 'Epson') {
            foreach (['1.1', '1.2', '1.3', '1.4', '1.5', '1.6'] as $idx) {
                $desc = $context->walker->get(self::PRT_COVER_DESCRIPTION . '.' . $idx);
                if ($desc !== null && $desc !== '') {
                    $descriptions[$idx] = $desc;
                    $statuses[$idx] = $context->walker->get(self::PRT_COVER_STATUS . '.' . $idx);
                }
            }
        } else {
            $descriptions = $context->walker->walkIndexed(self::PRT_COVER_DESCRIPTION);
            $statuses = $context->walker->walkIndexed(self::PRT_COVER_STATUS);
        }

        foreach ($descriptions as $index => $desc) {
            $status = SnmpValueHelper::numeric($statuses[$index] ?? null);
            if ($status === null) {
                continue;
            }

            $coverName = trim((string) $desc);
            if ($coverName === '') {
                $coverName = 'Cover ' . $index;
            }

            $statusLabels = [
                1 => 'other',
                3 => 'doorOpen',
                4 => 'doorClosed',
                5 => 'interlockOpen',
                6 => 'interlockClosed'
            ];
            
            $statusLabel = $statusLabels[(int) $status] ?? 'unknown';

            $this->addNumericSensor(
                $sensors,
                'PRINTER TRAYS & COVERS',
                $coverName . ' Status',
                self::PRT_COVER_STATUS . '.' . $index,
                (string) $status,
                '',
                'status',
                ['source' => 'Printer-MIB::prtCoverStatus.' . $index, 'label' => $statusLabel]
            );
        }
    }

    /**

    /**
     * @param list<array<string, mixed>> $sensors
     */
    /**
     * @param list<array<string, mixed>> $sensors
     * @param array<string, mixed> $metadata
     */
    private function addNumericSensor(
        array &$sensors,
        string $category,
        string $name,
        string $oid,
        mixed $value,
        string $unit,
        string $type,
        array $metadata = [],
    ): void {
        $sensor = [
            'sensor_class' => 'printer',
            'sensor_name' => StringHelper::safeModuleName('Printer - ' . $name),
            'sensor_type' => $type,
            'interface_index' => null,
            'interface_name' => null,
            'entity_index' => null,
            'oid' => $oid,
            'raw_value' => $value,
            'unit' => $unit,
            'scale' => 'units',
            'precision' => 0,
            'status' => 'ok',
            'metadata' => [
                'discovery_module' => 'PrinterDiscoveryModule',
                'category' => $category,
            ] + $metadata,
        ];

        $normalized = $this->normalizer->normalize($sensor);

        if ($normalized !== null) {
            $sensors[] = $normalized;
        }
    }

    private function stringSensor(string $category, string $name, string $oid, string $value, string $source): array
    {
        return [
            'sensor_class' => 'printer',
            'sensor_name' => StringHelper::safeModuleName('Printer - ' . $name),
            'sensor_type' => 'string',
            'interface_index' => null,
            'interface_name' => null,
            'entity_index' => null,
            'oid' => $oid,
            'raw_value' => $value,
            'normalized_value' => null,
            'unit' => '',
            'scale' => null,
            'precision' => null,
            'status' => 'ok',
            'metadata' => [
                'discovery_module' => 'PrinterDiscoveryModule',
                'category' => $category,
                'source' => $source,
            ],
        ];
    }

    private function printerDeviceIndex(DiscoveryContext $context): string
    {
        if ($context->vendor->name() === 'Epson') {
            return '1';
        }

        foreach ($context->walker->walkIndexed(self::HR_DEVICE_TYPE) as $index => $type) {
            $normalized = strtolower((string) $type);

            if (str_contains($normalized, 'hrdeviceprinter') || str_ends_with(trim($normalized, '.'), '25.3.1.5')) {
                return (string) $index;
            }
        }

        foreach (array_keys($context->walker->walkIndexed(self::HR_PRINTER_STATUS)) as $index) {
            return (string) $index;
        }

        return '1';
    }

    /**
     * @param array<string, string> $values
     * @return array{index:string, value:string}|null
     */
    private function firstNonEmpty(array $values): ?array
    {
        foreach ($values as $index => $value) {
            $value = trim((string) $value);

            if ($value !== '') {
                return ['index' => (string) $index, 'value' => $value];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $values
     */
    private function firstNonEmptyValue(array $values): ?string
    {
        $entry = $this->firstNonEmpty($values);

        return $entry['value'] ?? null;
    }

    private function statusCode(string $value): ?int
    {
        $numeric = SnmpValueHelper::numeric($value);

        return $numeric === null ? null : (int) $numeric;
    }

    private function detectedErrorDescription(string $raw): string
    {
        $integer = $this->hexStateToInteger($raw);

        if ($integer === null || $integer === 0) {
            return 'No detected printer errors';
        }

        return 'Printer error bitmask ' . $integer;
    }

    private function hexStateToInteger(string $raw): ?int
    {
        $hex = preg_replace('/[^0-9a-f]/i', '', $raw) ?? '';

        if ($hex === '') {
            return SnmpValueHelper::integer($raw);
        }

        return hexdec($hex);
    }

    private function isUsableIpAddress(string $ipAddress): bool
    {
        return filter_var($ipAddress, FILTER_VALIDATE_IP) !== false
            && !str_starts_with($ipAddress, '127.')
            && $ipAddress !== '0.0.0.0'
            && $ipAddress !== '::1';
    }

    private function formatMacAddress(string $macAddress): ?string
    {
        $macAddress = trim($macAddress);

        if ($macAddress === '' || preg_match('/^(?:00:?){6}$/', str_replace(':', '', $macAddress)) === 1) {
            return null;
        }

        if (preg_match('/^([0-9a-f]{1,2}:){5}[0-9a-f]{1,2}$/i', $macAddress) === 1) {
            $parts = array_map(
                static fn (string $part): string => str_pad(strtolower($part), 2, '0', STR_PAD_LEFT),
                explode(':', $macAddress),
            );

            return implode(':', $parts);
        }

        $hex = ctype_xdigit($macAddress) && strlen($macAddress) === 12
            ? strtolower($macAddress)
            : bin2hex($macAddress);

        if (strlen($hex) !== 12) {
            return $macAddress;
        }

        return implode(':', str_split($hex, 2));
    }

    private function supplyName(string $description, mixed $colorant, string $index): string
    {
        $description = trim($description, "\" \t\n\r\0\x0B");
        $colorant = trim((string) $colorant, "\" \t\n\r\0\x0B");
        $name = $description !== '' ? $description : ($colorant !== '' ? $colorant : 'Supply ' . $index);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name) !== '' ? trim($name) : 'Supply ' . $index;
    }

    private function supplyUnit(mixed $unit, ?float $maxCapacity): string
    {
        $code = SnmpValueHelper::integer($unit);

        if ($code === 19 || $maxCapacity === 100.0) {
            return '%';
        }

        return match ($code) {
            8 => 'sheets',
            16 => 'feet',
            17 => 'meters',
            18 => 'items',
            default => 'units',
        };
    }
}
