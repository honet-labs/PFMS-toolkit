# SNMP Bridge Provisioning System

SNMP Bridge is a PHP 8.3 provisioning bridge for SNMP discovery, sensor normalization, and direct PandoraFMS module creation.

It is not a monitoring engine. It does not poll continuously, store timeseries data, render graphs, or generate XML imports.

## Runtime

- PHP 8.3 or newer
- `pdo_mysql`
- `php-snmp`
- net-snmp libraries
- MySQL or MariaDB
- Apache httpd user: `apache`

## Databases

The application uses two independent PDO connections:

- Internal inventory database: stores scanned devices and discovered sensors.
- PandoraFMS database: reads agents from `tagente` and inserts selected modules into `tagente_modulo`.

Copy `.env.example` to `.env` and set the internal inventory and PandoraFMS database credentials for the deployment host.

Run the internal schema migration:

```bash
mysql -uroot -p snmp_bridge < database/migrations/001_create_inventory_schema.sql
```

If the database does not exist yet, run the same migration without preselecting a database:

```bash
mysql -uroot -p < database/migrations/001_create_inventory_schema.sql
```

## Apache

Point a vhost or alias at:

```text
/var/www/html/snmp-bridge/public
```

For the current web root layout, the app is reachable at:

```text
http://10.10.5.56/snmp-bridge/public/index.php
```

Make writable directories owned by Apache:

```bash
chown -R apache:apache storage public/uploads
```

## Discovery Flow

1. Read `sysDescr`, `sysObjectID`, and `sysName`.
2. Match vendor profile:
   - exact `sysObjectID`
   - enterprise wildcard
   - `sysDescr` regex
   - generic fallback
3. Resolve vendor capabilities.
4. Build ENTITY/IF-MIB mapping where the adapter requires it.
5. Run only supported discovery modules.
6. Normalize values and filter invalid sentinel values.
7. Upsert rows into internal inventory.

Huawei DOM mapping uses `entAliasMappingIdentifier -> ifIndex -> ifName`; it does not assume the sensor index is an interface index.

Raisecom uses a hybrid mapper: ENTITY-MIB alias first, direct ifIndex second, and interface-name regex fallback from entity labels.

## Sensor Naming

Discovery modules now route generated names through `SnmpNamingService` and `SensorNameFormatter` so inventory and Pandora module names stay readable and close to LibreNMS-style labels:

- `Gi0/0/1 - RX Power (dBm)`
- `Gi0/0/1 - Transceiver Temperature (C)`
- `Power Supply 1 Voltage (V)`
- `Fan 1 Speed (rpm)`
- `GPON 0/1/0 ONT 1 - RX Power (dBm)`

The Pandora module builder uses the normalized sensor name directly. It no longer prefixes vendor and interface a second time.

## Vendor MIB Coverage

Vendor-specific discovery is driven by adapter capability and MIB OID definitions, not hardcoded vendor checks in the pipeline.

- Huawei: `HUAWEI-XPON-MIB` OLT and ONT DDM sensors, including the `hwGponOntOpticalDdmOltRxOntPower` offset formula.
- ZTE: `ZTE-AN-OPTICAL-MODULE-MIB` optical module current value tables.
- Raisecom: `RAISECOM-OPTICAL-TRANSCEIVER-MIB` and `ROSMGMT-OPTICAL-TRANSCEIVER-MIB` parameter tables indexed as `ifIndex.parameterType`.
- Alcatel/Nokia: `SFP-MIB` diagnostic DisplayString values.
- Cisco: `CISCO-ENVMON-MIB` temperature and voltage tables, plus generic `ENTITY-SENSOR-MIB` optical discovery.
- Generic/unknown vendors: standard `SNMPv2-MIB`, `ENTITY-MIB`, `ENTITY-SENSOR-MIB`, `IF-MIB`, and `HOST-RESOURCES-MIB` discovery where the device exposes those tables.

## Pandora Provisioning

Selected numeric sensors are inserted directly into `tagente_modulo` as Pandora remote SNMP numeric modules:

- `id_modulo = 2`
- `id_tipo_modulo = 15`
- `snmp_oid` from discovered inventory
- `snmp_community` from the scan profile
- `ip_target` from the discovered device

Each inserted module receives a stable `custom_id` in the form:

```text
snmpbridge:sensor:{sensor_inventory_id}
```

Repeated provisioning will reuse existing modules with the same `custom_id`.

## Development Checks

Composer is optional for this dependency-free build, but `composer.json` is PSR-4 ready. A fallback autoloader is included at `vendor/autoload.php`.

Run syntax checks:

```bash
find app bootstrap config public routes tests -name '*.php' -print0 | xargs -0 -n1 php -l
```
