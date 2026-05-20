# SpeedDetector API Reference

## Class Definition

```php
namespace SnmpBridge\Core\Normalize;

final class SpeedDetector
```

## Public Methods

### `detect(DiscoveryContext $context, int|string $ifIndex): array`

Detects interface speed from dual OIDs (ifSpeed and ifHighSpeed).

**Parameters:**
- `$context` (DiscoveryContext): Discovery context instance
- `$ifIndex` (int|string): Interface index

**Returns:**
```php
[
    'speed' => int,      // Speed in bps (0 if unavailable)
    'oid' => string,     // OID used for detection (empty if unavailable)
    'source' => string   // Description of speed source
]
```

**Example:**
```php
$detector = new SpeedDetector();
$result = $detector->detect($context, 1);

if ($result['speed'] > 0) {
    echo "Interface 1: " . $result['speed'] . " bps";
    echo " (from OID: " . $result['oid'] . ")";
    echo " - Source: " . $result['source'];
}
```

**Output Example:**
```
Interface 1: 1000000000 bps (from OID: 1.3.6.1.2.1.31.1.1.1.15.1) - Source: ifHighSpeed (Mbps → bps)
```

---

### `detectBatch(DiscoveryContext $context, array $ifIndexes): array`

Detects speeds for multiple interfaces in batch.

**Parameters:**
- `$context` (DiscoveryContext): Discovery context instance
- `$ifIndexes` (array<int|string>): Array of interface indices

**Returns:**
```php
[
    ifIndex => [
        'speed' => int,
        'oid' => string,
        'source' => string
    ]
    // ... repeated for each interface
]
```

**Example:**
```php
$detector = new SpeedDetector();
$ifIndexes = [1, 2, 3, 4, 5];
$results = $detector->detectBatch($context, $ifIndexes);

foreach ($results as $ifIndex => $data) {
    if ($data['speed'] > 0) {
        echo "Interface $ifIndex: " . $data['speed'] . " bps\n";
    }
}
```

**Output Example:**
```
Interface 1: 1000000000 bps
Interface 2: 10000000000 bps
Interface 3: 100000000 bps
Interface 4: 0 bps
Interface 5: 1000000000 bps
```

---

### `getSpeed(DiscoveryContext $context, int|string $ifIndex): int`

Gets only the speed value (convenience method).

**Parameters:**
- `$context` (DiscoveryContext): Discovery context instance
- `$ifIndex` (int|string): Interface index

**Returns:**
- `int`: Speed in bps (0 if unavailable)

**Example:**
```php
$detector = new SpeedDetector();
$speed = $detector->getSpeed($context, 1);

if ($speed > 0) {
    echo "Speed: " . number_format($speed) . " bps";
}
```

---

### `getOid(DiscoveryContext $context, int|string $ifIndex): string`

Gets only the OID that was used for detection (convenience method).

**Parameters:**
- `$context` (DiscoveryContext): Discovery context instance
- `$ifIndex` (int|string): Interface index

**Returns:**
- `string`: OID (empty string if unavailable)

**Example:**
```php
$detector = new SpeedDetector();
$oid = $detector->getOid($context, 1);

if (!empty($oid)) {
    echo "Detected from OID: " . $oid;
}
```

---

### `getSource(DiscoveryContext $context, int|string $ifIndex): string`

Gets only the source description (convenience method).

**Parameters:**
- `$context` (DiscoveryContext): Discovery context instance
- `$ifIndex` (int|string): Interface index

**Returns:**
- `string`: Source description

**Example:**
```php
$detector = new SpeedDetector();
$source = $detector->getSource($context, 1);

echo "Speed source: " . $source;
// Output: Speed source: ifHighSpeed (Mbps → bps)
// or:     Speed source: ifSpeed (bps)
// or:     Speed source: No speed data available
```

---

## OID Constants

The class uses these standard IF-MIB OIDs:

```php
private const IF_SPEED = '1.3.6.1.2.1.2.2.1.5';        // RFC 2863
private const IF_HIGH_SPEED = '1.3.6.1.2.1.31.1.1.1.15'; // RFC 2096
```

## Detection Priority

1. **ifHighSpeed (RFC 2096)** - Preferred
   - OID: `1.3.6.1.2.1.31.1.1.1.15`
   - Value unit: Mbps
   - Max speed: Unlimited
   - Source label: `ifHighSpeed (Mbps → bps)`

2. **ifSpeed (RFC 2863)** - Fallback
   - OID: `1.3.6.1.2.1.2.2.1.5`
   - Value unit: bps
   - Max speed: 4,294,967,295 bps (≈4.3 Gbps)
   - Source label: `ifSpeed (bps)`

3. **None** - No data available
   - Speed: 0 (zero)
   - OID: Empty string
   - Source label: `No speed data available`

## Conversion Formula

When using ifHighSpeed:
```
Speed (bps) = ifHighSpeed (Mbps) × 1,000,000
```

## Integration Example

### In a Discovery Module

```php
use SnmpBridge\Core\Normalize\SpeedDetector;

final class MyInterfaceModule implements DiscoveryModuleInterface
{
    public function __construct(
        private readonly NormalizerInterface $normalizer,
        private readonly SpeedDetector $speedDetector = new SpeedDetector(),
    ) {
    }

    public function discover(DiscoveryContext $context): array
    {
        $sensors = [];
        
        $interfaces = $context->snmp()->walk(self::IF_DESCR);
        
        foreach ($interfaces as $ifIndex => $ifDescription) {
            $ifName = $context->snmp()->get(self::IF_NAME . '.' . $ifIndex);
            
            // Detect speed using the module
            $speedResult = $this->speedDetector->detect($context, $ifIndex);
            
            if ($speedResult['speed'] > 0) {
                $sensors[] = [
                    'type' => 'interface_speed',
                    'name' => "{$ifName} - Speed",
                    'oid' => $speedResult['oid'],
                    'unit' => 'bps',
                    'value' => $speedResult['speed'],
                    'description' => "Speed of {$ifName} ({$speedResult['source']})",
                ];
            }
        }
        
        return $sensors;
    }
}
```

## Speed Formatting Helper

All interface discovery modules include a `formatSpeed()` helper method:

```php
private function formatSpeed(int $speedBps): string
{
    if ($speedBps >= 1000000000) {
        return round($speedBps / 1000000000, 2) . ' Gbps';
    } elseif ($speedBps >= 1000000) {
        return round($speedBps / 1000000, 2) . ' Mbps';
    } elseif ($speedBps >= 1000) {
        return round($speedBps / 1000, 2) . ' Kbps';
    }
    return $speedBps . ' bps';
}
```

**Usage:**
```php
$speedResult = $detector->detect($context, 1);
$display = $this->formatSpeed($speedResult['speed']);
echo "Speed: " . $display; // Output: Speed: 1 Gbps
```

## Return Value Examples

### 1 Gbps Interface (from ifHighSpeed)
```php
[
    'speed' => 1000000000,
    'oid' => '1.3.6.1.2.1.31.1.1.1.15.1',
    'source' => 'ifHighSpeed (Mbps → bps)'
]
```

### 10 Gbps Interface (from ifHighSpeed)
```php
[
    'speed' => 10000000000,
    'oid' => '1.3.6.1.2.1.31.1.1.1.15.2',
    'source' => 'ifHighSpeed (Mbps → bps)'
]
```

### 100 Mbps Interface (from ifSpeed)
```php
[
    'speed' => 100000000,
    'oid' => '1.3.6.1.2.1.2.2.1.5.3',
    'source' => 'ifSpeed (bps)'
]
```

### No Speed Data Available
```php
[
    'speed' => 0,
    'oid' => '',
    'source' => 'No speed data available'
]
```

## Error Handling

The SpeedDetector handles errors gracefully:

- **Invalid ifIndex:** Returns 0 speed
- **Missing SNMP data:** Returns 0 speed and empty OID
- **Exception during detection:** Returns 0 speed (caught internally)

No exceptions are thrown; invalid or missing data simply returns zero speed.

## Performance Considerations

- **Single detection:** ~1-2 SNMP queries per interface
- **Batch detection:** Efficient loop through multiple interfaces
- **No caching:** Each call performs fresh SNMP queries
- **Thread-safe:** Stateless class, safe for concurrent use

## Testing

```php
// Test basic detection
$detector = new SpeedDetector();
$result = $detector->detect($context, 1);
assert($result['speed'] > 0);
assert(!empty($result['oid']));

// Test batch detection
$results = $detector->detectBatch($context, [1, 2, 3]);
assert(count($results) === 3);

// Test getter methods
$speed = $detector->getSpeed($context, 1);
$oid = $detector->getOid($context, 1);
$source = $detector->getSource($context, 1);
assert($speed === $result['speed']);
```

## Dependencies

- **SnmpBridge\Core\Discovery\DiscoveryContext** - Discovery context
- **No external dependencies** - Uses only PHP standard library

