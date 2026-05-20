# SpeedDetector Quick Reference

## Installation

Already included in refactoring. No additional installation needed.

## Basic Usage

```php
use SnmpBridge\Core\Normalize\SpeedDetector;

$detector = new SpeedDetector();
$result = $detector->detect($context, 1);

echo "Speed: " . $result['speed'] . " bps";
```

## API Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `detect($ctx, $ifIndex)` | `array` | Full detection result (speed, OID, source) |
| `detectBatch($ctx, $ifIndexes)` | `array[]` | Multiple interfaces at once |
| `getSpeed($ctx, $ifIndex)` | `int` | Speed in bps only (0 if unavailable) |
| `getOid($ctx, $ifIndex)` | `string` | OID used for detection (empty if unavailable) |
| `getSource($ctx, $ifIndex)` | `string` | Source description of speed |

## OID Priority

1. **ifHighSpeed** (RFC 2096) ← Preferred
   - Returns: Mbps (auto-converted to bps ×1,000,000)
   - Max: Unlimited (no 32-bit overflow)
   - OID: `1.3.6.1.2.1.31.1.1.1.15`

2. **ifSpeed** (RFC 2863) ← Fallback
   - Returns: bps (used directly)
   - Max: 4.3 Gbps (32-bit limit)
   - OID: `1.3.6.1.2.1.2.2.1.5`

3. **None** ← Last resort
   - Returns: 0
   - Source: "No speed data available"

## Speed Formatting

```php
// In any interface discovery module:
$display = $this->formatSpeed($speed);
// 1000000000 → "1 Gbps"
// 100000000  → "100 Mbps"
// 1000000    → "1 Mbps"
// 100000     → "100 Kbps"
```

## Return Value Structure

```php
[
    'speed' => 1000000000,              // bps
    'oid' => '1.3.6.1.2.1.31.1.1.1.15.1',
    'source' => 'ifHighSpeed (Mbps → bps)'
]
```

## Integration in Modules

### Step 1: Add Dependency
```php
public function __construct(
    private readonly NormalizerInterface $normalizer,
    private readonly SpeedDetector $speedDetector = new SpeedDetector(),
) {
}
```

### Step 2: Use in Discovery
```php
$result = $this->speedDetector->detect($context, $ifIndex);

if ($result['speed'] > 0) {
    $sensors[] = [
        'type' => 'interface_speed',
        'name' => "{$ifName} - Speed ({$this->formatSpeed($result['speed'])})",
        'oid' => $result['oid'],
        'unit' => 'bps',
        'value' => $result['speed'],
        'description' => "Speed: {$this->formatSpeed($result['speed'])} ({$result['source']})",
    ];
}
```

## Examples

### 1 Gbps Interface
```
Speed: 1000000000 bps
OID: 1.3.6.1.2.1.31.1.1.1.15.1
Source: ifHighSpeed (Mbps → bps)
Display: "1 Gbps"
```

### 10 Gbps Interface
```
Speed: 10000000000 bps
OID: 1.3.6.1.2.1.31.1.1.1.15.2
Source: ifHighSpeed (Mbps → bps)
Display: "10 Gbps"
```

### 100 Mbps Interface
```
Speed: 100000000 bps
OID: 1.3.6.1.2.1.2.2.1.5.3
Source: ifSpeed (bps)
Display: "100 Mbps"
```

## Batch Detection

```php
$speeds = $detector->detectBatch($context, [1, 2, 3, 4, 5]);

foreach ($speeds as $ifIndex => $data) {
    if ($data['speed'] > 0) {
        echo "Interface $ifIndex: {$data['speed']} bps\n";
    }
}
```

## Error Handling

No exceptions thrown. Invalid ifIndex returns:
```php
[
    'speed' => 0,
    'oid' => '',
    'source' => 'No speed data available'
]
```

## Common Patterns

### Get speed only
```php
$speed = $detector->getSpeed($context, $ifIndex);
```

### Get OID only
```php
$oid = $detector->getOid($context, $ifIndex);
```

### Get source only
```php
$source = $detector->getSource($context, $ifIndex);
```

## Files

- **Component:** `app/Core/Normalize/SpeedDetector.php`
- **API Docs:** `docs/SPEED-DETECTOR-API.md`
- **Refactoring Guide:** `docs/SPEED-DETECTOR-REFACTORING.md`

## Module Integration Status

✅ InterfaceDiscoveryModule
✅ HuaweiInterfaceDiscoveryModule
✅ CiscoInterfaceDiscoveryModule
✅ ZTEInterfaceDiscoveryModule
✅ AlcatelInterfaceDiscoveryModule
✅ RaisecomInterfaceDiscoveryModule

All modules: Ready for production

