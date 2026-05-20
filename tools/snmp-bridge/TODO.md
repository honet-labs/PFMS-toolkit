# SNMP-Bridge Enhancements TODO (LibreNMS-like Naming + Thorough SNMP Scanning)

## 1. LibreNMS-like SNMP name translation layer
- [x] Add new naming translation component (recommended):
  - `app/Services/SnmpNamingService.php`
  - (optional) `app/Core/Snmp/OidNameTranslator.php`
- [x] Extend `app/Helpers/SensorNameFormatter.php` with LibreNMS-like patterns
  - transceiver/DOM variants
  - optical temp/bias/voltage
  - interface metrics formatting
  - stable unit formatting in parentheses
- [ ] Extend `app/Helpers/ModuleNameFormatter.php` with additional module patterns
- [x] Implement LibreNMS naming conformance normalization post-processing
- [ ] Wire naming service into discovery modules:
  - [x] Start with `app/DiscoveryModules/OpticalDomDiscoveryModule.php`
  - [x] Update environmental and GPON discovery modules
  - [x] Update Pandora module naming

## 2. Thoroughness / broaden SNMP discovery coverage
- [ ] Add "MIB walk breadth" mode / additional standardized indexed walks (where missing)
  - potentially update `app/Core/Snmp/SnmpWalker.php` and/or `app/Core/Snmp/SnmpScanner.php`
- [x] Expand discovery coverage for common sensor groups:
  - [x] Temperatures (inlet/outlet/board/line-card)
  - [x] Power supplies voltage/current/power
  - [x] Fans RPM and status
  - [x] Storage/disk metrics (if not already covered)
- [ ] Ensure vendor adapters' `discoveryOids()` are used broadly where applicable

## 3. Vendor capability + entity mapping correctness
- [x] Ensure entity/interface index resolution is consistently applied
- [ ] Improve `GenericEntityMapper` / vendor mappers only if sensor tables require it

## 4. Testing / validation tooling
- [ ] Add test harness in `snmp-bridge/tests/`
  - Validate sensor naming patterns against LibreNMS-like expectations
- [ ] Add optional debug/log output:
  - matched vendor
  - modules executed
  - sample generated sensor names

## Progress
- [x] Plan approved by user
- [x] Implement naming translation service and integrate into OpticalDomDiscoveryModule first
- [x] Enable standard MIB universal system discovery in the active pipeline
