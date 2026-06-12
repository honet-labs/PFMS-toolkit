<?php
declare(strict_types=1);

/**
 * NetFlow Explorer (Pandora FMS-friendly)
 *
 * Reads nfcapd.* files using nfdump and renders a lightweight UI.
 * Designed to be dropped next to your existing "dashboard-traffic-interface.php".
 *
 * Security notes:
 * - No shell interpolation: uses proc_open with argv array.
 * - Filters are written to a temp file and passed with -f.
 * - File selection is allowlisted to nfcapd.YYYYMMDDHHMM.
 */

date_default_timezone_set('Asia/Jakarta');

/* =========================
 *  Security headers
 * ======================= */
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: interest-cohort=()');

/* =========================
 *  Helpers (config + security)
 * ======================= */
function is_truthy($v): bool {
    if (is_bool($v)) return $v;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','y','on'], true);
}

function load_local_config(): array {
    $path = getenv('NFX_LOCAL_CONFIG') ?: (__DIR__ . DIRECTORY_SEPARATOR . 'nfx_local_config.php');
    if (!is_string($path) || $path === '') return [];
    if (!is_file($path) || !is_readable($path)) return [];
    $cfg = include $path;
    return is_array($cfg) ? $cfg : [];
}

function safe_int($v, int $min, int $max, int $def): int {
    $i = (int)$v;
    if ($i < $min || $i > $max) return $def;
    return $i;
}

function valid_ip(string $s): bool {
    $t = trim($s);
    if ($t === '') return false;
    return filter_var($t, FILTER_VALIDATE_IP) !== false;
}

function cache_dir(array $cfg): string {
    $dir = (string)($cfg['cache_dir'] ?? '');
    if ($dir === '') $dir = (string)(getenv('NFX_CACHE_DIR') ?: '');
    if ($dir === '') {
        $dir = is_writable(__DIR__)
            ? (__DIR__ . DIRECTORY_SEPARATOR . 'cache')
            : (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nfx_cache');
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function cache_get(string $file, int $ttl): mixed {
    if ($ttl <= 0) return null;
    if (!is_file($file)) return null;
    $age = time() - (int)@filemtime($file);
    if ($age < 0 || $age > $ttl) return null;
    $raw = @file_get_contents($file);
    if ($raw === false) return null;
    $v = @unserialize($raw);
    return $v === false ? null : $v;
}

function cache_put(string $file, mixed $value): void {
    $tmp = $file . '.tmp';
    @file_put_contents($tmp, serialize($value), LOCK_EX);
    @chmod($tmp, 0600);
    @rename($tmp, $file);
}

function parse_nfcapd_stamp(string $base): ?DateTimeImmutable {
    // Accept: nfcapd.YYYYMMDDHHMM
    if (!preg_match('/^nfcapd\.(\d{12})$/', $base, $m)) return null;
    $ts = $m[1];
    $dt = DateTimeImmutable::createFromFormat('YmdHi', $ts, new DateTimeZone(date_default_timezone_get()));
    return $dt ?: null;
}

function nfdump_timewin(DateTimeImmutable $start, DateTimeImmutable $end): string {
    // nfdump expects: YYYY/MM/dd.hh:mm:ss-YYYY/MM/dd.hh:mm:ss
    return $start->format('Y/m/d.H:i:s') . '-' . $end->format('Y/m/d.H:i:s');
}

function human_dt(DateTimeImmutable $dt): string {
    return $dt->format('Y-m-d H:i');
}

function bytes_fmt(float $b): string {
    $b = max(0.0, $b);
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($b >= 1024.0 && $i < count($units)-1) { $b /= 1024.0; $i++; }
    if ($i === 0) return number_format($b, 0) . ' ' . $units[$i];
    return number_format($b, 2) . ' ' . $units[$i];
}

function bps_fmt(float $bps): string {
    $bps = max(0.0, $bps);
    $units = ['B/s','KB/s','MB/s','GB/s'];
    $i = 0;
    while ($bps >= 1024.0 && $i < count($units)-1) { $bps /= 1024.0; $i++; }
    if ($i === 0) return number_format($bps, 0) . ' ' . $units[$i];
    return number_format($bps, 2) . ' ' . $units[$i];
}

function proc_run(array $cmd, int $timeoutSec = 20): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = @proc_open($cmd, $descriptors, $pipes, null, [
        'LC_ALL' => 'C',
        'LANG'   => 'C',
    ]);

    if (!is_resource($proc)) {
        return ['ok'=>false, 'code'=>-1, 'out'=>'', 'err'=>'proc_open failed'];
    }

    @fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $err = '';
    $start = time();

    while (true) {
        $status = proc_get_status($proc);
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);

        if (!$status['running']) {
            break;
        }
        if ((time() - $start) > $timeoutSec) {
            @proc_terminate($proc);
            $out .= stream_get_contents($pipes[1]);
            $err .= stream_get_contents($pipes[2]);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_close($proc);
            return ['ok'=>false, 'code'=>124, 'out'=>$out, 'err'=>"timeout after {$timeoutSec}s"]; 
        }
        usleep(50000);
    }

    $out .= stream_get_contents($pipes[1]);
    $err .= stream_get_contents($pipes[2]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $code = proc_close($proc);

    return ['ok'=>($code === 0), 'code'=>$code, 'out'=>$out, 'err'=>$err];
}

function build_filter_file(array $cfg, array $parts): ?string {
    $filter = trim(implode(' and ', array_values(array_filter($parts, fn($x) => is_string($x) && trim($x) !== ''))));
    if ($filter === '') return null;

    $dir = cache_dir($cfg);
    $file = $dir . DIRECTORY_SEPARATOR . 'filter_' . sha1($filter) . '.txt';

    if (!is_file($file)) {
        @file_put_contents($file, $filter . "\n", LOCK_EX);
        @chmod($file, 0600);
    }
    return $file;
}

function list_nfcapd_files(string $dir, int $max = 500): array {
    $dirReal = realpath($dir);
    if ($dirReal === false || !is_dir($dirReal)) return [];

    $items = [];
    foreach (glob($dirReal . DIRECTORY_SEPARATOR . 'nfcapd.*') as $path) {
        $base = basename($path);
        $dt = parse_nfcapd_stamp($base);
        if (!$dt) continue;
        $items[] = [
            'base' => $base,
            'path' => $path,
            'ts'   => $dt->getTimestamp(),
            'dt'   => $dt,
            'size' => (int)@filesize($path),
        ];
    }

    usort($items, fn($a,$b) => $b['ts'] <=> $a['ts']);
    if (count($items) > $max) $items = array_slice($items, 0, $max);
    return $items;
}

function pick_default_window(array $files, int $windowMinutes): array {
    if (empty($files)) return [null, null];
    $end = $files[0];
    /** @var DateTimeImmutable $endDt */
    $endDt = $end['dt'];
    $startTarget = $endDt->sub(new DateInterval('PT' . max(5, $windowMinutes) . 'M'));

    $start = $end;
    foreach ($files as $f) {
        /** @var DateTimeImmutable $dt */
        $dt = $f['dt'];
        if ($dt <= $startTarget) { $start = $f; break; }
        $start = $f;
    }
    return [$start, $end];
}

function find_file_by_base(array $files, string $base): ?array {
    foreach ($files as $f) {
        if ($f['base'] === $base) return $f;
    }
    return null;
}

function parse_csv_lines_fixed(string $raw, int $expectedCols): array {
    $rows = [];
    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $cols = array_map('trim', explode(',', $line));
        if (count($cols) < $expectedCols) continue;
        $rows[] = $cols;
    }
    return $rows;
}


function proto_label(string $raw): string {
    $v = strtolower(trim($raw));
    $map = [
        '6' => 'TCP', 'tcp' => 'TCP',
        '17' => 'UDP', 'udp' => 'UDP',
        '1' => 'ICMP', 'icmp' => 'ICMP',
        '47' => 'GRE', 'gre' => 'GRE',
        '50' => 'ESP', 'esp' => 'ESP',
        '51' => 'AH', 'ah' => 'AH',
    ];
    if (isset($map[$v])) return $map[$v];
    return $v === '' ? 'OTHER' : strtoupper($v);
}

function sankey_group_rows(array $rows, int $maxNodesPerLayer, int $maxLinks): array {
    if ($maxNodesPerLayer < 2) $maxNodesPerLayer = 2;
    $layerCount = 0;
    foreach ($rows as $row) {
        $count = count($row['nodes'] ?? []);
        if ($count > $layerCount) $layerCount = $count;
    }
    if ($layerCount < 2) return [];

    $topByLayer = [];
    for ($i = 0; $i < $layerCount; $i++) {
        $totals = [];
        foreach ($rows as $row) {
            $nodes = $row['nodes'] ?? [];
            $label = trim((string)($nodes[$i] ?? ''));
            if ($label === '') continue;
            $totals[$label] = ($totals[$label] ?? 0.0) + (float)($row['value'] ?? 0);
        }
        arsort($totals);
        $topByLayer[$i] = array_slice(array_keys($totals), 0, max(1, $maxNodesPerLayer - 1));
    }

    $merged = [];
    foreach ($rows as $row) {
        $nodes = $row['nodes'] ?? [];
        if (count($nodes) < 2) continue;
        $norm = [];
        $originals = [];
        for ($i = 0; $i < $layerCount; $i++) {
            $label = trim((string)($nodes[$i] ?? ''));
            if ($label === '') continue;
            $originals[] = $label;
            if (!in_array($label, $topByLayer[$i], true)) $label = 'Other';
            $norm[] = $label;
        }
        if (count($norm) < 2) continue;
        $key = implode('|', $norm);
        if (!isset($merged[$key])) {
            $merged[$key] = [
                'nodes' => $norm,
                'value' => 0.0,
                'packets' => 0,
                'flows' => 0,
                'details' => []
            ];
        }
        $merged[$key]['value'] += (float)($row['value'] ?? 0);
        $merged[$key]['packets'] += (int)($row['packets'] ?? 0);
        $merged[$key]['flows'] += (int)($row['flows'] ?? 0);

        if (in_array('Other', $norm, true)) {
            $origPath = implode(' -> ', $originals);
            if (!isset($merged[$key]['details'][$origPath])) {
                $merged[$key]['details'][$origPath] = [
                    'value' => 0.0,
                    'packets' => 0
                ];
            }
            $merged[$key]['details'][$origPath]['value'] += (float)($row['value'] ?? 0);
            $merged[$key]['details'][$origPath]['packets'] += (int)($row['packets'] ?? 0);
        }
    }

    $out = array_values($merged);
    usort($out, static fn($a, $b) => ($b['value'] <=> $a['value']));
    if (count($out) > $maxLinks) $out = array_slice($out, 0, $maxLinks);
    return $out;
}

function build_sankey_dataset(
    string $mode,
    string $nfdumpBin,
    string $expr,
    string $timewin,
    ?string $filterFile,
    int $sankeyN,
    int $nodeGroupN
): array {
    $rows = [];
    $limit = max(30, min(400, $sankeyN * 8));

    switch ($mode) {
        case 'srcport':
            $cmd = [$nfdumpBin, '-R', $expr, '-t', $timewin, '-A', 'srcip,dstport', '-O', 'bytes', '-n', (string)$limit, '-o', 'fmt:%sa,%dp,%pkt,%byt,%bps,%fl', '-q', '-N'];
            if ($filterFile) { $cmd[] = '-f'; $cmd[] = $filterFile; }
            $res = proc_run($cmd, 45);
            if (!$res['ok']) return [[], trim($res['err'])];
            foreach (parse_csv_lines_fixed($res['out'], 6) as $c) {
                $src = trim((string)($c[0] ?? ''));
                $port = trim((string)($c[1] ?? ''));
                if ($src === '' || $port === '') continue;
                $rows[] = [
                    'nodes' => [$src, 'Port ' . $port],
                    'value' => (float)($c[3] ?? 0),
                    'packets' => (int)($c[2] ?? 0),
                    'flows' => (int)($c[5] ?? 0),
                ];
            }
            break;

        case 'srcproto':
            $cmd = [$nfdumpBin, '-R', $expr, '-t', $timewin, '-A', 'srcip,proto,dstip', '-O', 'bytes', '-n', (string)$limit, '-o', 'fmt:%sa,%pr,%da,%pkt,%byt,%bps,%fl', '-q', '-N'];
            if ($filterFile) { $cmd[] = '-f'; $cmd[] = $filterFile; }
            $res = proc_run($cmd, 45);
            if (!$res['ok']) return [[], trim($res['err'])];
            foreach (parse_csv_lines_fixed($res['out'], 7) as $c) {
                $src = trim((string)($c[0] ?? ''));
                $pr  = proto_label((string)($c[1] ?? ''));
                $dst = trim((string)($c[2] ?? ''));
                if ($src === '' || $dst === '') continue;
                $rows[] = [
                    'nodes' => [$src, $pr, $dst],
                    'value' => (float)($c[4] ?? 0),
                    'packets' => (int)($c[3] ?? 0),
                    'flows' => (int)($c[6] ?? 0),
                ];
            }
            break;

        case 'srcdstport':
            $cmd = [$nfdumpBin, '-R', $expr, '-t', $timewin, '-A', 'srcip,dstip,dstport', '-O', 'bytes', '-n', (string)$limit, '-o', 'fmt:%sa,%da,%dp,%pkt,%byt,%bps,%fl', '-q', '-N'];
            if ($filterFile) { $cmd[] = '-f'; $cmd[] = $filterFile; }
            $res = proc_run($cmd, 45);
            if (!$res['ok']) return [[], trim($res['err'])];
            foreach (parse_csv_lines_fixed($res['out'], 7) as $c) {
                $src = trim((string)($c[0] ?? ''));
                $dst = trim((string)($c[1] ?? ''));
                $port = trim((string)($c[2] ?? ''));
                if ($src === '' || $dst === '' || $port === '') continue;
                $rows[] = [
                    'nodes' => [$src, $dst, 'Port ' . $port],
                    'value' => (float)($c[4] ?? 0),
                    'packets' => (int)($c[3] ?? 0),
                    'flows' => (int)($c[6] ?? 0),
                ];
            }
            break;

        case 'srcdst':
        default:
            $cmd = [$nfdumpBin, '-R', $expr, '-t', $timewin, '-A', 'srcip,dstip', '-O', 'bytes', '-n', (string)$limit, '-o', 'fmt:%sa,%da,%pkt,%byt,%bps,%fl', '-q', '-N'];
            if ($filterFile) { $cmd[] = '-f'; $cmd[] = $filterFile; }
            $res = proc_run($cmd, 45);
            if (!$res['ok']) return [[], trim($res['err'])];
            foreach (parse_csv_lines_fixed($res['out'], 6) as $c) {
                $src = trim((string)($c[0] ?? ''));
                $dst = trim((string)($c[1] ?? ''));
                if ($src === '' || $dst === '') continue;
                $rows[] = [
                    'nodes' => [$src, $dst],
                    'value' => (float)($c[3] ?? 0),
                    'packets' => (int)($c[2] ?? 0),
                    'flows' => (int)($c[5] ?? 0),
                ];
            }
            break;
    }

    return [sankey_group_rows($rows, $nodeGroupN, $sankeyN), null];
}
