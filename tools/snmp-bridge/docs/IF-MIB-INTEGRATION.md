# IF-MIB Integration Documentation

## Overview

The SNMP Bridge now includes comprehensive Interface (IF-MIB) discovery across all supported vendors using the standard IF-MIB (RFC 2863) and IF-MIB extensions (RFC 2096).

This integration enables:
- Universal interface discovery for all network devices
- LibreNMS-style interface naming
- Complete interface statistics collection
- Vendor-specific optimizations

## Supported OIDs

### Core IF-MIB (RFC 2863)

| OID | Name | Description |
|-----|------|-------------|
| 1.3.6.1.2.1.2.1 | ifNumber | Total number of interfaces |
| 1.3.6.1.2.1.2.2.1.1 | ifIndex | Interface index |
| 1.3.6.1.2.1.2.2.1.2 | ifDescr | Interface description |
| 1.3.6.1.2.1.2.2.1.3 | ifType | Interface type |
| 1.3.6.1.2.1.2.2.1.4 | ifMtu | Maximum transmission unit |
| 1.3.6.1.2.1.2.2.1.5 | ifSpeed | Interface speed (bps) |
| 1.3.6.1.2.1.2.2.1.7 | ifAdminStatus | Administrative status |
| 1.3.6.1.2.1.2.2.1.8 | ifOperStatus | Operational status |
| 1.3.6.1.2.1.2.2.1.10 | ifInOctets | Input octets |
| 1.3.6.1.2.1.2.2.1.14 | ifInErrors | Input errors |
| 1.3.6.1.2.1.2.2.1.16 | ifOutOctets | Output octets |
| 1.3.6.1.2.1.2.2.1.20 | ifOutErrors | Output errors |

### IF-MIB Extensions (RFC 2096)

| OID | Name | Description |
|-----|------|-------------|
| 1.3.6.1.2.1.31.1.1.1.1 | ifName | Interface name (preferred) |
| 1.3.6.1.2.1.31.1.1.1.15 | ifHighSpeed | High-speed interface speed (Mbps) |

## Discovered Sensors

### Standard Interface Metrics

All interfaces automatically discover:

```
eth0 - Speed                    # Interface speed
eth0 - Input Octets            # Input traffic
eth0 - Output Octets           # Output traffic
eth0 - Input Errors            # Input errors (if > 0)
eth0 - Output Errors           # Output errors (if > 0)
eth0 - Input Discards          # Input discards (if > 0)
eth0 - Output Discards         # Output discards (if > 0)
```

## Discovery Modules

| Module | Class | Vendor | OID Prefix |
|--------|-------|--------|-----------|
| interface_discovery | InterfaceDiscoveryModule | All | Universal |
| huawei_interface_discovery | HuaweiInterfaceDiscoveryModule | Huawei | 1.3.6.1.4.1.2011 |
| cisco_interface_discovery | CiscoInterfaceDiscoveryModule | Cisco | 1.3.6.1.4.1.9 |
| zte_interface_discovery | ZTEInterfaceDiscoveryModule | ZTE | 1.3.6.1.4.1.3902 |
| alcatel_interface_discovery | AlcatelInterfaceDiscoveryModule | Alcatel | 1.3.6.1.4.1.6486 |
| raisecom_interface_discovery | RaisecomInterfaceDiscoveryModule | Raisecom | 1.3.6.1.4.1.8886 |

## LibreNMS-Style Naming

Interface names are normalized to LibreNMS conventions:

| Input | Output |
|-------|--------|
| GigabitEthernet 0/0/1 | ge0/0/1 |
| Ethernet1 | eth1 |
| FastEthernet 0/1 | fe0/1 |
| VLAN 100 | vlan100 |

## Integration

Modules registered in `bootstrap/app.php` discovery pipeline in order:

1. InterfaceDiscoveryModule (universal IF-MIB)
2. HuaweiInterfaceDiscoveryModule (vendor-specific)
3. CiscoInterfaceDiscoveryModule (vendor-specific)
4. ZTEInterfaceDiscoveryModule (vendor-specific)
5. AlcatelInterfaceDiscoveryModule (vendor-specific)
6. RaisecomInterfaceDiscoveryModule (vendor-specific)
7. Other discovery modules (CPU, memory, etc.)

## Testing

```bash
# Scan device
cd /var/www/html/snmp-bridge
php public/scan.php --host 192.168.1.1 --version 2c --community public

# Expected output includes interface metrics
```

## References

- RFC 2863: The Interfaces Group MIB
- RFC 2096: IP Forwarding Table MIB
