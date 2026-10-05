<?php

declare(strict_types=1);

namespace SnmpBridge\Core\Vendor;

use RuntimeException;
use SnmpBridge\Contracts\VendorAdapterInterface;
use SnmpBridge\Core\Snmp\OidTranslator;
use SnmpBridge\VendorAdapter\DynamicVendorAdapter;
use SnmpBridge\VendorAdapter\GenericAdapter;

final readonly class ProfileMatcher
{
    /**
     * Comprehensive IANA Private Enterprise Numbers (PEN) lookup dictionary.
     * Maps 1.3.6.1.4.1.<PEN> to official manufacturer / vendor names worldwide.
     */
    private const array IANA_ENTERPRISE_REGISTRY = [
        9 => 'Cisco',
        11 => 'HP / Aruba',
        43 => '3Com',
        171 => 'D-Link',
        232 => 'Compaq / HPE',
        253 => 'Xerox',
        311 => 'Microsoft',
        318 => 'APC / Schneider Electric',
        367 => 'Ricoh',
        534 => 'Eaton',
        674 => 'Dell',
        800 => 'Allied Telesis',
        1211 => 'SonicWall',
        1588 => 'Brocade',
        1608 => 'Canon',
        1991 => 'Foundry Networks',
        2011 => 'Huawei',
        2021 => 'Net-SNMP',
        2435 => 'Brother',
        2569 => 'Alcatel-Lucent',
        2620 => 'Check Point',
        2636 => 'Juniper',
        2699 => 'Epson',
        3076 => 'Altiga / Cisco VPN',
        3375 => 'F5 Networks',
        3808 => 'CyberPower',
        3902 => 'ZTE',
        4355 => 'Fortinet Legacy',
        4526 => 'Netgear',
        4881 => 'Ruijie Networks',
        5842 => 'Raisecom',
        6428 => 'Pulse Secure',
        6574 => 'Synology',
        8072 => 'Net-SNMP',
        8888 => 'D-Link',
        9999 => 'Netgear',
        11863 => 'TP-Link',
        12356 => 'Fortinet',
        14988 => 'MikroTik',
        17163 => 'Sangfor',
        18334 => 'Kyocera',
        19112 => 'Supermicro',
        20044 => 'Zyxel',
        20858 => 'H3C',
        21067 => 'Sophos',
        24681 => 'QNAP',
        25461 => 'Palo Alto Networks',
        25506 => 'H3C / HPE',
        26543 => 'Arista Networks',
        29671 => 'Hikvision',
        30065 => 'Aruba Networks',
        34141 => 'Uniview',
        37130 => 'Ubiquiti Networks',
        41112 => 'Ubiquiti Networks',
        52055 => 'BDCOM',
        1004849 => 'Dahua',
    ];

    /**
     * Regex heuristics to identify vendor from sysDescr string.
     */
    private const array VENDOR_SYS_DESCR_HEURISTICS = [
        'Palo Alto Networks' => '/\bPalo\s*Alto\b/i',
        'Sophos' => '/\bSophos\b/i',
        'Ruijie Networks' => '/\bRuijie\b/i',
        'Check Point' => '/\bCheck\s*Point\b/i',
        'SonicWall' => '/\bSonicWALL\b/i',
        'Synology' => '/\bSynology\b/i',
        'QNAP' => '/\bQNAP\b/i',
        'Ubiquiti Networks' => '/\b(?:Ubiquiti|EdgeRouter|EdgeSwitch|UniFi|EdgeOS)\b/i',
        'Sangfor' => '/\bSangfor\b/i',
        'H3C' => '/\bH3C\b/i',
        'Aruba Networks' => '/\bAruba(?:OS)?\b/i',
        'BDCOM' => '/\bBDCOM\b/i',
        'D-Link' => '/\bD-Link\b/i',
        'TP-Link' => '/\bTP-Link\b/i',
        'Netgear' => '/\bNetgear\b/i',
        'Allied Telesis' => '/\bAllied\s*Telesis\b/i',
        'Supermicro' => '/\bSupermicro\b/i',
        'Arista Networks' => '/\bArista\b/i',
        'Zyxel' => '/\bZyxel\b/i',
        'pfSense' => '/\bpfSense\b/i',
        'OPNsense' => '/\bOPNsense\b/i',
        'Linux' => '/\bLinux\b/i',
        'Windows' => '/\bWindows\b/i',
        'APC / Schneider' => '/\bAPC\b/i',
        'Eaton' => '/\bEaton\b/i',
    ];

    public function __construct(
        private VendorRegistry $registry,
        private ?OidTranslator $oidTranslator = null,
    ) {
    }

    public function match(string $sysObjectId, string $sysDescr): VendorAdapterInterface
    {
        $normalizedSysObjectId = $this->normalizeOid($sysObjectId);

        // 1. Exact match in registered adapters
        foreach ($this->registry->all() as $adapter) {
            foreach ($adapter->sysObjectIds() as $exactOid) {
                if ($normalizedSysObjectId === $this->normalizeOid($exactOid)) {
                    return $adapter;
                }
            }
        }

        // 2. Prefix match on registered adapters enterprise OID
        foreach ($this->registry->all() as $adapter) {
            $enterpriseOid = $this->normalizeOid($adapter->enterpriseOid());

            if ($enterpriseOid !== '' && str_starts_with($normalizedSysObjectId . '.', $enterpriseOid . '.')) {
                return $adapter;
            }
        }

        // 3. SysDescr patterns on registered adapters
        foreach ($this->registry->all() as $adapter) {
            foreach ($adapter->sysDescrPatterns() as $pattern) {
                $result = @preg_match($pattern, $sysDescr);

                if ($result === false) {
                    throw new RuntimeException(sprintf('Invalid sysDescr regex for vendor %s: %s', $adapter->name(), $pattern));
                }

                if ($result === 1) {
                    return $adapter;
                }
            }
        }

        // 4. Dynamic IANA PEN lookup for ANY vendor/brand worldwide
        if (preg_match('/^\.1\.3\.6\.1\.4\.1\.(\d+)/', $normalizedSysObjectId, $matches) === 1) {
            $pen = (int) $matches[1];
            $enterpriseOid = '.1.3.6.1.4.1.' . $pen;

            // 4a. Check built-in IANA registry
            if (isset(self::IANA_ENTERPRISE_REGISTRY[$pen])) {
                $vendorName = self::IANA_ENTERPRISE_REGISTRY[$pen];
                return new DynamicVendorAdapter($vendorName, $enterpriseOid);
            }

            // 4b. Translate enterprise OID via Net-SNMP (loaded/uploaded MIBs)
            if ($this->oidTranslator !== null) {
                try {
                    $translation = $this->oidTranslator->translate($enterpriseOid);
                    if (!empty($translation['translated']) && !empty($translation['mib'])) {
                        $mibName = (string) $translation['mib'];
                        $cleanName = preg_replace('/-(?:SMI|TC|MIB|PRODUCTS|SYSTEM).*$/i', '', $mibName) ?? $mibName;
                        $cleanName = ucwords(strtolower(str_replace(['_', '-'], ' ', $cleanName)));
                        if ($cleanName !== '') {
                            return new DynamicVendorAdapter($cleanName, $enterpriseOid);
                        }
                    }
                } catch (\Throwable) {}
            }

            // 4c. Check sysDescr heuristics
            foreach (self::VENDOR_SYS_DESCR_HEURISTICS as $vendorName => $pattern) {
                if (@preg_match($pattern, $sysDescr) === 1) {
                    return new DynamicVendorAdapter($vendorName, $enterpriseOid);
                }
            }

            // 4d. Fallback for valid Enterprise PEN: Dynamic adapter with PEN ID
            return new DynamicVendorAdapter("Enterprise-{$pen}", $enterpriseOid);
        }

        // 5. Check sysDescr heuristics even if sysObjectId had no PEN
        foreach (self::VENDOR_SYS_DESCR_HEURISTICS as $vendorName => $pattern) {
            if (@preg_match($pattern, $sysDescr) === 1) {
                return new DynamicVendorAdapter($vendorName);
            }
        }

        return new GenericAdapter();
    }

    private function normalizeOid(string $oid): string
    {
        $oid = trim($oid);
        $oid = preg_replace('/^OID:\s*/i', '', $oid) ?? $oid;
        $oid = preg_replace('/[^0-9.].*$/', '', $oid) ?? $oid;

        return '.' . trim($oid, '.');
    }
}
