<?php
declare(strict_types=1);

require_once __DIR__ . '/nfx_lib.php';

/* =========================
 *  Load config
 * ======================= */
$cfg = load_local_config();

$requireAuth = is_truthy($cfg['require_auth'] ?? (getenv('NFX_REQUIRE_AUTH') ?: '0'));
if ($requireAuth) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $loggedIn = false;
    if (!empty($_SESSION['id_usuario']) || !empty($_SESSION['user']) || !empty($_SESSION['username'])) {
        $loggedIn = true;
    }
    if (!$loggedIn) {
        http_response_code(401);
        echo 'Unauthorized (login required).';
        exit;
    }
}

$netflowDir = (string)($cfg['netflow_dir'] ?? (getenv('NFX_NETFLOW_DIR') ?: '/var/spool/pandora/data_in/netflow'));
$nfdumpBin  = (string)($cfg['nfdump_bin'] ?? (getenv('NFX_NFDUMP_BIN') ?: '/usr/bin/nfdump'));
$rotationSec = (int)($cfg['rotation_seconds'] ?? 300);
$cacheTtl = (int)($cfg['cache_ttl'] ?? 10);
$maxFiles = (int)($cfg['max_files_list'] ?? 600);

$windowMinutes = safe_int($_GET['window_min'] ?? ($cfg['default_window_minutes'] ?? 30), 5, 24*60, 30);
$topN = safe_int($_GET['top_n'] ?? ($cfg['default_top_n'] ?? 20), 5, 200, 20);
$flowN = safe_int($_GET['flow_n'] ?? ($cfg['default_flow_n'] ?? 200), 20, 2000, 200);
$sankeyN = safe_int($_GET['sankey_n'] ?? ($cfg['default_sankey_n'] ?? 12), 5, 40, 12);
$nodeGroupN = safe_int($_GET['node_group_n'] ?? ($cfg['default_node_group_n'] ?? 10), 3, 20, 10);
$autoRefresh = (string)($_GET['ar'] ?? ($_GET['auto_refresh'] ?? '0'));
if (!in_array($autoRefresh, ['0','1m','5m','10m'], true)) $autoRefresh = '0';
$sankeyMode = strtolower(trim((string)($_GET['sankey_mode'] ?? ($_GET['mode'] ?? ($cfg['default_sankey_mode'] ?? 'srcdst')))));
if (!in_array($sankeyMode, ['srcdst','srcport','srcproto','srcdstport'], true)) $sankeyMode = 'srcdst';

$files = list_nfcapd_files($netflowDir, $maxFiles);
if (!empty($files)) {
    $defStart = $files[count($files) - 1]; // Oldest file
    $defEnd = $files[0];                   // Newest file
} else {
    $defStart = null;
    $defEnd = null;
}

$manualEnd = isset($_GET['manual_end']) && (string)$_GET['manual_end'] === '1';
$startBase = (string)($_GET['start'] ?? ($defStart['base'] ?? ''));
$endBase   = (string)($_GET['end']   ?? ($defEnd['base'] ?? ''));

$startFile = find_file_by_base($files, $startBase) ?: $defStart;
$endFile   = $manualEnd
    ? (find_file_by_base($files, $endBase) ?: $defEnd)
    : ($files[0] ?? $defEnd); // auto-follow latest finalized nfcapd file

// Normalize ordering
if ($startFile && $endFile && $startFile['ts'] > $endFile['ts']) {
    $startFile = $endFile;
}

$srcIp = trim((string)($_GET['src_ip'] ?? ''));
$dstIp = trim((string)($_GET['dst_ip'] ?? ''));
$dstPort = trim((string)($_GET['dst_port'] ?? ''));
$proto = trim((string)($_GET['proto'] ?? ''));
$proto = strtolower($proto);

$sort = trim((string)($_GET['sort'] ?? 'bytes'));
$allowedSort = ['bytes','bps','packets','tstart'];
if (!in_array($sort, $allowedSort, true)) $sort = 'bytes';

$errors = [];

// Build time window + file expr
$timewin = null;
$expr = null;
if ($startFile && $endFile) {
    /** @var DateTimeImmutable $sdt */
    $sdt = $startFile['dt'];
    /** @var DateTimeImmutable $edt */
    $edt = $endFile['dt']->add(new DateInterval('PT' . max(60, $rotationSec) . 'S'));
    $timewin = nfdump_timewin($sdt, $edt);
    $expr = rtrim((string)realpath($netflowDir), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $startFile['base'] . ':' . $endFile['base'];
}

// Build filter parts
$filterParts = [];
if ($srcIp !== '') {
    if (valid_ip($srcIp)) $filterParts[] = 'src ip ' . $srcIp;
    else $errors[] = 'src_ip invalid';
}
if ($dstIp !== '') {
    if (valid_ip($dstIp)) $filterParts[] = 'dst ip ' . $dstIp;
    else $errors[] = 'dst_ip invalid';
}
if ($dstPort !== '') {
    if (ctype_digit($dstPort) && (int)$dstPort >= 1 && (int)$dstPort <= 65535) {
        $filterParts[] = 'dst port ' . (int)$dstPort;
    } else {
        $errors[] = 'dst_port invalid';
    }
}
if ($proto !== '') {
    $allowedProto = ['tcp','udp','icmp','gre','esp','ah'];
    if (in_array($proto, $allowedProto, true)) {
        $filterParts[] = 'proto ' . $proto;
    } else {
        $errors[] = 'proto invalid';
    }
}

$filterFile = build_filter_file($cfg, $filterParts);

// User access logging
$user = 'anonymous';
if (session_status() === PHP_SESSION_ACTIVE || !empty($_SESSION)) {
    $user = $_SESSION['id_usuario'] ?? $_SESSION['user'] ?? $_SESSION['username'] ?? 'anonymous';
}
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$action = 'PAGE_VIEW';
if (isset($_GET['download']) && $_GET['download'] === '1') {
    $action = 'EXPORT_CSV';
} elseif (isset($_GET['api'])) {
    $action = 'API_' . strtoupper((string)$_GET['api']);
} elseif (!empty($_POST) || !empty($_GET['start']) || !empty($_GET['end']) || !empty($_GET['src_ip']) || !empty($_GET['dst_ip'])) {
    $action = 'QUERY';
}

$details = [];
if ($action === 'QUERY' || $action === 'PAGE_VIEW' || $action === 'EXPORT_CSV') {
    if ($srcIp !== '') $details[] = "src_ip: $srcIp";
    if ($dstIp !== '') $details[] = "dst_ip: $dstIp";
    if ($dstPort !== '') $details[] = "dst_port: $dstPort";
    if ($proto !== '') $details[] = "proto: $proto";
    if ($startFile && $endFile) {
        $details[] = "window: " . $startFile['base'] . " to " . $endFile['base'];
    }
    if ($sankeyMode !== '') $details[] = "mode: $sankeyMode";
} elseif ($action === 'API_LOG_JS_ERROR') {
    $details[] = "logged client side js error";
}

$detailsStr = !empty($details) ? implode(', ', $details) : 'no_filters';
$logLine = sprintf(
    "[%s] [%s] [%s] [%s] %s\n",
    date('Y-m-d H:i:s'),
    $clientIp,
    $user,
    $action,
    $detailsStr
);
$accessLogFile = __DIR__ . '/../nfx_access.log';
@file_put_contents($accessLogFile, $logLine, FILE_APPEND);

function nfdump_exists(string $bin): bool {
    return is_file($bin) && is_executable($bin);
}

$canRun = nfdump_exists($nfdumpBin) && $timewin !== null && $expr !== null && empty($errors);

// Optional download: output raw CSV
if ($canRun && isset($_GET['download']) && $_GET['download'] === '1') {
    $cmd = [$nfdumpBin, '-R', $expr, '-t', $timewin, '-o', 'csv', '-q', '-N'];
    if ($filterFile) { $cmd[] = '-f'; $cmd[] = $filterFile; }
    $res = proc_run($cmd, 60);
    if (!$res['ok']) {
        http_response_code(500);
        echo "nfdump error: " . htmlspecialchars($res['err'], ENT_QUOTES, 'UTF-8');
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="netflow_export.csv"');
    echo $res['out'];
    exit;
}

// Cached results
$cacheKey = sha1(json_encode([
    'expr'=>$expr,
    'timewin'=>$timewin,
    'filter'=>$filterParts,
    'topN'=>$topN,
    'flowN'=>$flowN,
    'sort'=>$sort,
    'bin'=>$nfdumpBin,
    'sankeyMode'=>$sankeyMode,
    'sankeyN'=>$sankeyN,
    'nodeGroupN'=>$nodeGroupN,
]));
$cacheFile = cache_dir($cfg) . DIRECTORY_SEPARATOR . 'result_' . $cacheKey . '.ser';

$data = cache_get($cacheFile, $cacheTtl);
if (!is_array($data)) {
    $data = [
        'top_src' => [],
        'top_dst' => [],
        'top_conv' => [],
        'top_dport' => [],
        'flows' => [],
        'meta' => [],
        'warnings' => [],
        'sankey' => [],
    ];

    if ($canRun) {
        // Top conversations: srcip,dstip
        $cmdConv = [$nfdumpBin, '-R', $expr, '-t', $timewin, '-A', 'srcip,dstip', '-O', 'bytes', '-n', (string)$topN, '-o', 'fmt:%sa,%da,%pkt,%byt,%bps,%fl', '-q', '-N'];
        if ($filterFile) { $cmdConv[] = '-f'; $cmdConv[] = $filterFile; }
        $resConv = proc_run($cmdConv, 40);
        if ($resConv['ok']) {
            $rows = parse_csv_lines_fixed($resConv['out'], 6);
            foreach ($rows as $c) {
                $row = [
                    'src' => $c[0] ?? '',
                    'dst' => $c[1] ?? '',
                    'pkt' => (int)($c[2] ?? 0),
                    'byt' => (int)($c[3] ?? 0),
                    'bps' => (float)($c[4] ?? 0),
                    'flw' => (int)($c[5] ?? 0),
                ];
                $data['top_conv'][] = $row;
            }
        } else {
            $data['warnings'][] = 'Top conversations failed: ' . trim($resConv['err']);
        }

        // Top src IP
        $cmdSrc = [$nfdumpBin, '-R', $expr, '-t', $timewin, '-A', 'srcip', '-O', 'bytes', '-n', (string)$topN, '-o', 'fmt:%sa,%pkt,%byt,%bps,%fl', '-q', '-N'];
        if ($filterFile) { $cmdSrc[] = '-f'; $cmdSrc[] = $filterFile; }
        $resSrc = proc_run($cmdSrc, 40);
        if ($resSrc['ok']) {
            $rows = parse_csv_lines_fixed($resSrc['out'], 5);
            foreach ($rows as $c) {
                $data['top_src'][] = [
                    'ip'  => $c[0] ?? '',
                    'pkt' => (int)($c[1] ?? 0),
                    'byt' => (int)($c[2] ?? 0),
                    'bps' => (float)($c[3] ?? 0),
                    'flw' => (int)($c[4] ?? 0),
                ];
            }
        } else {
            $data['warnings'][] = 'Top src IP failed: ' . trim($resSrc['err']);
        }

        // Top dst IP
        $cmdDst = [$nfdumpBin, '-R', $expr, '-t', $timewin, '-A', 'dstip', '-O', 'bytes', '-n', (string)$topN, '-o', 'fmt:%da,%pkt,%byt,%bps,%fl', '-q', '-N'];
        if ($filterFile) { $cmdDst[] = '-f'; $cmdDst[] = $filterFile; }
        $resDst = proc_run($cmdDst, 40);
        if ($resDst['ok']) {
            $rows = parse_csv_lines_fixed($resDst['out'], 5);
            foreach ($rows as $c) {
                $data['top_dst'][] = [
                    'ip'  => $c[0] ?? '',
                    'pkt' => (int)($c[1] ?? 0),
                    'byt' => (int)($c[2] ?? 0),
                    'bps' => (float)($c[3] ?? 0),
                    'flw' => (int)($c[4] ?? 0),
                ];
            }
        } else {
            $data['warnings'][] = 'Top dst IP failed: ' . trim($resDst['err']);
        }

        // Top dst port
        $cmdPort = [$nfdumpBin, '-R', $expr, '-t', $timewin, '-A', 'dstport', '-O', 'bytes', '-n', (string)$topN, '-o', 'fmt:%dp,%pkt,%byt,%bps,%fl', '-q', '-N'];
        if ($filterFile) { $cmdPort[] = '-f'; $cmdPort[] = $filterFile; }
        $resPort = proc_run($cmdPort, 40);
        if ($resPort['ok']) {
            $rows = parse_csv_lines_fixed($resPort['out'], 5);
            foreach ($rows as $c) {
                $data['top_dport'][] = [
                    'port' => $c[0] ?? '',
                    'pkt'  => (int)($c[1] ?? 0),
                    'byt'  => (int)($c[2] ?? 0),
                    'bps'  => (float)($c[3] ?? 0),
                    'flw'  => (int)($c[4] ?? 0),
                ];
            }
        } else {
            $data['warnings'][] = 'Top dst port failed: ' . trim($resPort['err']);
        }

        // Flow list
        $orderMap = ['bytes'=>'bytes','bps'=>'bps','packets'=>'packets','tstart'=>'tstart'];
        $order = $orderMap[$sort] ?? 'bytes';
        $cmdFlows = [$nfdumpBin, '-R', $expr, '-t', $timewin, '-O', $order, '-n', (string)$flowN,
            '-o', 'fmt:%ts,%td,%pr,%sa,%sp,%da,%dp,%pkt,%byt,%bps', '-q', '-N'];
        if ($filterFile) { $cmdFlows[] = '-f'; $cmdFlows[] = $filterFile; }
        $resFlows = proc_run($cmdFlows, 60);
        if ($resFlows['ok']) {
            $rows = parse_csv_lines_fixed($resFlows['out'], 10);
            foreach ($rows as $c) {
                $data['flows'][] = [
                    'ts'  => $c[0] ?? '',
                    'td'  => $c[1] ?? '',
                    'pr'  => $c[2] ?? '',
                    'sa'  => $c[3] ?? '',
                    'sp'  => $c[4] ?? '',
                    'da'  => $c[5] ?? '',
                    'dp'  => $c[6] ?? '',
                    'pkt' => (int)($c[7] ?? 0),
                    'byt' => (int)($c[8] ?? 0),
                    'bps' => (float)($c[9] ?? 0),
                ];
            }
        } else {
            $data['warnings'][] = 'Flows failed: ' . trim($resFlows['err']);
        }

        [$sankeyRows, $sankeyWarn] = build_sankey_dataset($sankeyMode, $nfdumpBin, $expr, $timewin, $filterFile, $sankeyN, $nodeGroupN);
        if (!empty($sankeyRows)) {
            $data['sankey'] = $sankeyRows;
        } elseif ($sankeyWarn) {
            $data['warnings'][] = 'Sankey failed: ' . $sankeyWarn;
        }

        $data['meta'] = [
            'expr' => $expr,
            'timewin' => $timewin,
            'sankey_mode' => $sankeyMode,
            'node_group_n' => $nodeGroupN,
        ];
    }

    cache_put($cacheFile, $data);
}

$subtitleParts = [];
if ($startFile && $endFile) {
    $subtitleParts[] = human_dt($startFile['dt']) . ' -> ' . human_dt($endFile['dt']);
}
$subtitleParts[] = 'dir: ' . $netflowDir;
$subtitleParts[] = 'mode: ' . $sankeyMode;
$subtitle = implode(' - ', $subtitleParts);

$downloadUrl = null;
if ($canRun) {
    $q = $_GET;
    $q['download'] = '1';
    $downloadUrl = '?' . http_build_query($q);
}

$sankeyJson = json_encode($data['sankey'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sankeyModeJs = json_encode($sankeyMode, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$autoRefreshJs = json_encode($autoRefresh, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$sankeyOpen = true;
if (isset($_GET['sk']) && $_GET['sk'] === '0') {
    $sankeyOpen = false;
} elseif (isset($_GET['sankey']) && $_GET['sankey'] === '0') {
    $sankeyOpen = false;
}
