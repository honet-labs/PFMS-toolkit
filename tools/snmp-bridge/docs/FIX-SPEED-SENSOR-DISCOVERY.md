# Fix: Speed Sensor Discovery Issue

## Problem
Speed sensors were not appearing in the discovered sensors list despite the SpeedDetector module being created and integrated into all interface discovery modules.

## Root Cause
The **SpeedDetector was not being injected into the interface discovery modules** in `bootstrap/app.php`. The modules were instantiated with only the `$normalizer` parameter, but they required both `$normalizer` and `$speedDetector`.

## Solution Applied

### 1. Added SpeedDetector Import
**File:** `bootstrap/app.php` (Line 10)

```php
use SnmpBridge\Core\Normalize\SpeedDetector;
```

### 2. Instantiated SpeedDetector Singleton
**File:** `bootstrap/app.php` (Line 81)

```php
$speedDetector = new SpeedDetector();
```

### 3. Injected into All 6 Interface Modules
**File:** `bootstrap/app.php` (Lines 91-96)

```php
$pipeline = new DiscoveryPipeline(
    new CapabilityResolver(),
    [
        new InterfaceDiscoveryModule($normalizer, $speedDetector),
        new HuaweiInterfaceDiscoveryModule($normalizer, $speedDetector),
        new CiscoInterfaceDiscoveryModule($normalizer, $speedDetector),
        new ZTEInterfaceDiscoveryModule($normalizer, $speedDetector),
        new AlcatelInterfaceDiscoveryModule($normalizer, $speedDetector),
        new RaisecomInterfaceDiscoveryModule($normalizer, $speedDetector),
        // ... other modules
    ]
);
```

## Verification

All changes have been verified:
- ✅ Bootstrap syntax is valid (PHP 8.3)
- ✅ SpeedDetector import is present
- ✅ SpeedDetector instantiation is correct
- ✅ All 6 modules receive SpeedDetector dependency
- ✅ Application bootstrap completes successfully

## Testing

After applying this fix, perform a device scan. You should now see interface speed sensors in the discovered sensors list:

**Expected Output:**
```
eth0 - Speed (1 Gbps)
ge0 - Speed (10 Gbps)
eth1 - Speed (100 Mbps)
[interface] - Speed ([speed value])
```

## Why This Works

The discovery pipeline now:
1. Runs InterfaceDiscoveryModule with SpeedDetector available
2. For each interface, calls `$this->speedDetector->detect($context, $ifIndex)`
3. SpeedDetector queries OIDs:
   - Priority 1: ifHighSpeed (RFC 2096) - Mbps (converts to bps)
   - Priority 2: ifSpeed (RFC 2863) - bps direct
4. Returns speed in bps with OID reference and source
5. Creates sensor with human-readable speed: `"eth0 - Speed (1 Gbps)"`

## Files Modified

- `bootstrap/app.php` - Added SpeedDetector import, instantiation, and injection

**Status:** ✅ Complete and verified

