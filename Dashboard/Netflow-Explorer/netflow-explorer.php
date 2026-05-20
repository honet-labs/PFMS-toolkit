<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/nfx_bootstrap.php';

// =====================================================================
// DYNAMIC BREADCRUMB LOGIC
// =====================================================================
$raw_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); 
$dir_only = dirname($raw_path); 
$clean_path = trim($dir_only, '/'); 

$path_array = explode('/', $clean_path);
$formatted_array = array_map(function($p) {
    return ucwords(str_replace(['_', '-'], ' ', urldecode($p)));
}, $path_array);
$dynamic_breadcrumb = "PANDORA CONSOLE / CUSTOM / PANEL / DASHBOARD";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PandoraFMS - NetFlow Explorer</title>
    
    <link rel="icon" href="/pandora_console/images/pandora.ico" type="image/x-icon">
    
    <link href="/pandora_console/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="/pandora_console/custom/panel/vendor/fonts/fonts.css" />

    <link href="/pandora_console/custom/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    
    <script src="/pandora_console/custom/panel/vendor/chartjs/plotly-2.35.2.min.js"></script>
    <link rel="stylesheet" href="assets/css/netflow-explorer.css">

    <style>
        /* Base Global Styling Override */
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #334155; font-size: 14px; -webkit-font-smoothing: antialiased; } * { box-sizing: border-box; }
        body { background-color: #f4f6f8; margin: 0; padding: 0; }

        /* MATERIAL SYMBOLS FIX */
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-weight: normal !important; font-style: normal !important; font-size: 18px !important; line-height: 1 !important; display: inline-block; vertical-align: middle; color: inherit !important; }

        /* NAVBAR 1: GLOBAL TOP HEADER */
        .pandora-header-top { background-color: #ffffff; border-bottom: 1px solid #e0e4e8; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 25px; position: relative; z-index: 10; }
        .header-left { display: flex; align-items: center; }
        .header-logo { height: 24px; width: auto; object-fit: contain; }
        .header-divider { width: 1px; height: 28px; background-color: #dce1e5; margin: 0 20px; }
        .header-title-box { display: flex; flex-direction: column; line-height: 1.2; margin-right: 40px; }
        .header-title-box .main-title { font-size: 14px !important; font-weight: normal !important; color: #0b1a26 !important; }
        .header-title-box .sub-title { font-size: 12px !important; font-weight: normal !important; color: #7f8c8d !important; }

        .custom-search-container { position: relative; width: 450px; }
        .custom-search-container .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #7f8c8d !important; font-size: 20px !important; pointer-events: none; }
        .custom-search-container input { width: 100%; height: 36px; padding: 8px 15px 8px 40px; border-radius: 18px; border: 1px solid transparent; background-color: #f4f6f8; font-size: 13px !important; color: #333 !important; transition: all 0.2s ease; }
        .custom-search-container input:focus { background-color: #ffffff; border-color: #b5c1c9; outline: none; box-shadow: 0 0 0 3px rgba(181, 193, 201, 0.2); }

        .header-right { display: flex; align-items: center; gap: 15px; }
        .nav-icon-btn { color: #4a5568 !important; text-decoration: none; display: flex; align-items: center; justify-content: center; height: 36px; width: 36px; border-radius: 50%; transition: 0.2s; }
        .nav-icon-btn:hover { background-color: #f4f6f8; color: #1a252f !important; }

        /* NAVBAR 2: MODULE SUB-HEADER */
        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: flex-start; justify-content: space-between; }
        .breadcrumb-box { display: flex; flex-direction: column; }
        .page-breadcrumb { font-size: 12px !important; font-weight: normal !important; color: #4a5568 !important; margin-bottom: 2px; }
        .page-title { font-size: 18px !important; font-weight: 600 !important; color: #0b1a26 !important; margin: 0; padding: 0; display: flex; align-items: center; gap: 8px; }
        .page-subtitle { font-size: 12px !important; color: #7f8c8d !important; background: #e0e4e8; padding: 2px 8px; border-radius: 12px; font-weight: normal !important; }

        /* MAIN CONTENT & FILTER */
        .main-content { padding: 0 30px 30px 30px; }
        .filter-section { background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); padding: 15px 20px; margin-bottom: 20px; display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 10px !important; text-transform: uppercase; font-weight: normal; color: #7f8c8d; margin-bottom: 5px; display: block; }
        .filter-group input, .filter-group select { border: 1px solid #dce1e5; padding: 6px 12px; background: #f8f9fa; border-radius: 4px; font-weight: normal !important; color:#000 !important; min-width: 100px; }
        .filter-group input[type="checkbox"] { min-width: auto; margin-right: 5px; cursor: pointer; }
        
        .btn-apply { background: #004d40; color: #fff !important; border: none; padding: 7px 20px; border-radius: 4px; font-weight: normal !important; transition: 0.2s; display: flex; align-items: center; gap: 5px; }
        .btn-apply:hover { background: #00695c; color: #fff; }
        .btn-secondary-custom { background: #fff; color: #4a5568 !important; border: 1px solid #dce1e5; padding: 7px 20px; border-radius: 4px; font-weight: normal !important; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-secondary-custom:hover { background: #f4f6f8; color: #0b1a26 !important; border-color: #b5c1c9; }
        .btn-download { background: #1976d2; color: #fff !important; border: none; padding: 7px 20px; border-radius: 4px; font-weight: normal !important; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-download:hover { background: #1565c0; color: #fff; }

        /* DASHBOARD CARDS & TABLES */
        .dashboard-card { background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; flex-direction: column; height: 100%; overflow: hidden; border: 1px solid #f0f3f5; margin-bottom: 20px; }
        .dashboard-card-header { padding: 15px 20px; border-bottom: 1px solid #e0e4e8; background-color: #f8f9fa; display: flex; justify-content: space-between; align-items: center; }
        .dashboard-card-title { font-size: 14px !important; font-weight: 500 !important; color: #0b1a26 !important; margin: 0; text-transform: uppercase; }
        .dashboard-card-body { padding: 0; flex-grow: 1; overflow-x: auto; }

        table.table-pfms { border-collapse: collapse !important; margin: 0 !important; width: 100% !important; }
        table.table-pfms thead th { background-color: #f8f9fa !important; border-bottom: 1px solid #dce1e5 !important; text-transform: uppercase; padding: 12px 20px !important; white-space: nowrap; font-weight: normal !important; color: #7f8c8d !important; font-size: 11px !important; }
        table.table-pfms tbody tr { transition: all 0.2s ease-in-out; }
        table.table-pfms tbody td { font-weight: normal !important; vertical-align: middle; border-bottom: 1px solid #f0f3f5; padding: 12px 20px !important; white-space: nowrap; color: #0b1a26 !important; }
        table.table-pfms tbody tr:hover td { background-color: #f4f6f8 !important; }
        
        .code-mono { font-family: 'Courier New', Courier, monospace !important; color: #d63384; font-size: 12px !important; font-weight: normal; background: #fff0f6; padding: 2px 6px; border-radius: 4px; }
        .code-ip { color: #1976d2; font-family: 'Courier New', Courier, monospace !important; font-weight: normal; }
    </style>
</head>
<body>

<div class="pandora-header-top">
    <div class="header-left">
        <img src="/pandora_console/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Pandora Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box">
            <span class="main-title">Pandora FMS</span>
            <span class="sub-title">the Flexible Monitoring System</span>
        </div>
        <div class="custom-search-container">
            <span class="material-symbols-outlined search-icon">search</span>
            <input type="text" placeholder="Global search disabled in Explorer..." readonly>
        </div>
    </div>
    <div class="header-right">
        <a href="/pandora_console/index.php" class="nav-icon-btn" title="Back to Home">
            <span class="material-symbols-outlined">home</span>
        </a>
    </div>
</div>

<div class="pandora-header-bottom">
    <div class="breadcrumb-box">
        <span class="page-breadcrumb"><?= htmlspecialchars($dynamic_breadcrumb) ?></span>
        <h1 class="page-title">
            NetFlow Explorer 
            <span class="page-subtitle"><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></span>
        </h1>
    </div>
</div>

<div class="main-content">

    <?php if (empty($files)): ?>
      <div class="alert alert-danger" style="border-radius: 6px; font-weight: normal !important;">No nfcapd.* files found in: <span class="code-mono"><?= htmlspecialchars($netflowDir, ENT_QUOTES, 'UTF-8'); ?></span></div>
    <?php elseif (!nfdump_exists($nfdumpBin)): ?>
      <div class="alert alert-danger" style="border-radius: 6px; font-weight: normal !important;">nfdump binary not found/executable: <span class="code-mono"><?= htmlspecialchars($nfdumpBin, ENT_QUOTES, 'UTF-8'); ?></span></div>
    <?php elseif (!empty($errors)): ?>
      <div class="alert alert-danger" style="border-radius: 6px; font-weight: normal !important;">Invalid input: <?= htmlspecialchars(implode(', ', $errors), ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif (!$canRun): ?>
      <div class="alert alert-warning" style="border-radius: 6px; font-weight: normal !important;">Cannot run: missing time window / file range.</div>
    <?php endif; ?>

    <?php if (!empty($data['warnings'])): ?>
      <div class="alert alert-warning" style="border-radius: 6px; font-weight: normal !important;">Warnings: <?= htmlspecialchars(implode(' | ', $data['warnings']), ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <form method="get" class="filter-section" id="filterForm">
        
        <div class="filter-group">
            <label>Start File</label>
            <select name="start">
                <?php foreach (array_reverse($files) as $f): ?>
                  <?php $label = human_dt($f['dt']) . ' (' . $f['base'] . ')'; ?>
                  <option value="<?= htmlspecialchars($f['base'], ENT_QUOTES, 'UTF-8'); ?>" <?= ($startFile && $f['base'] === $startFile['base']) ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                  </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group" style="min-width: 200px;">
            <label style="display:flex; justify-content:space-between; align-items:center;">
                End File
                <span style="display:flex; align-items:center;">
                    <input type="checkbox" name="manual_end" value="1" <?= $manualEnd ? 'checked' : ''; ?> onchange="this.form.submit()"> Manual
                </span>
            </label>
            <?php if ($manualEnd): ?>
                <select name="end" style="width: 100%;">
                  <?php foreach (array_reverse($files) as $f): ?>
                    <?php $label = human_dt($f['dt']) . ' (' . $f['base'] . ')'; ?>
                    <option value="<?= htmlspecialchars($f['base'], ENT_QUOTES, 'UTF-8'); ?>" <?= ($endFile && $f['base'] === $endFile['base']) ? 'selected' : ''; ?>>
                      <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
            <?php else: ?>
                <div style="padding: 7px 12px; background: #f8f9fa; border: 1px solid #dce1e5; border-radius: 4px; font-weight: normal; color: #7f8c8d;">
                  Latest: <?php if ($endFile): ?><span style="color:#0b1a26;"><?= htmlspecialchars(human_dt($endFile['dt']), ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="filter-group">
            <label>Src IP</label>
            <input type="text" name="src_ip" placeholder="Src IP" value="<?= htmlspecialchars($srcIp, ENT_QUOTES, 'UTF-8'); ?>" size="12">
        </div>
        <div class="filter-group">
            <label>Dst IP</label>
            <input type="text" name="dst_ip" placeholder="Dst IP" value="<?= htmlspecialchars($dstIp, ENT_QUOTES, 'UTF-8'); ?>" size="12">
        </div>
        <div class="filter-group">
            <label>Dst Port</label>
            <input type="text" name="dst_port" placeholder="Port" value="<?= htmlspecialchars($dstPort, ENT_QUOTES, 'UTF-8'); ?>" size="6">
        </div>

        <div class="filter-group">
            <label>Protocol</label>
            <select name="proto">
                <option value="" <?= $proto==='' ? 'selected' : ''; ?>>Any</option>
                <?php foreach (['tcp','udp','icmp','gre','esp','ah'] as $p): ?>
                  <option value="<?= $p; ?>" <?= $proto===$p ? 'selected' : ''; ?>><?= strtoupper($p); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label>Sort By</label>
            <select name="sort">
                <option value="bytes" <?= $sort==='bytes' ? 'selected':''; ?>>Bytes</option>
                <option value="bps" <?= $sort==='bps' ? 'selected':''; ?>>Bytes/sec</option>
                <option value="packets" <?= $sort==='packets' ? 'selected':''; ?>>Packets</option>
                <option value="tstart" <?= $sort==='tstart' ? 'selected':''; ?>>Time</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Sankey Mode</label>
            <select name="sankey_mode">
                <option value="srcdst" <?= $sankeyMode==='srcdst' ? 'selected' : ''; ?>>Src -> Dst</option>
                <option value="srcport" <?= $sankeyMode==='srcport' ? 'selected' : ''; ?>>Src -> Port</option>
                <option value="srcproto" <?= $sankeyMode==='srcproto' ? 'selected' : ''; ?>>Src -> Proto -> Dst</option>
                <option value="srcdstport" <?= $sankeyMode==='srcdstport' ? 'selected' : ''; ?>>Src -> Dst -> Port</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Top / Flow N</label>
            <div style="display:flex; gap:5px;">
                <input type="text" name="top_n" value="<?= (int)$topN; ?>" size="3" title="Top N">
                <input type="text" name="flow_n" value="<?= (int)$flowN; ?>" size="4" title="Flow N">
            </div>
        </div>

        <div class="filter-group">
            <label>Auto Refresh</label>
            <select name="auto_refresh" id="autoRefreshSelect">
                <option value="0" <?= $autoRefresh==='0' ? 'selected' : ''; ?>>Off</option>
                <option value="1m" <?= $autoRefresh==='1m' ? 'selected' : ''; ?>>1 Min</option>
                <option value="5m" <?= $autoRefresh==='5m' ? 'selected' : ''; ?>>5 Min</option>
                <option value="10m" <?= $autoRefresh==='10m' ? 'selected' : ''; ?>>10 Min</option>
            </select>
        </div>

        <input type="hidden" name="sankey" value="<?= $sankeyOpen ? '1' : '0'; ?>" id="sankeyStateInput">

        <div style="display: flex; gap: 8px; margin-left: auto;">
            <button class="btn-apply" type="submit"><span class="material-symbols-outlined" style="font-size:18px!important;">sync</span> REFRESH</button>
            <a class="btn-secondary-custom" href="?"><span class="material-symbols-outlined" style="font-size:18px!important;">restart_alt</span> RESET</a>
            <?php if ($downloadUrl): ?>
                <a class="btn-download" href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="material-symbols-outlined" style="font-size:18px!important;">download</span> CSV
                </a>
            <?php endif; ?>
        </div>
    </form>

    <div class="dashboard-card mb-4 sankey-box" id="sankeyPanel" style="overflow: visible;">
        <div class="dashboard-card-header">
            <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="font-size:18px!important; color:#004d40;">account_tree</span> Traffic Sankey (<?= htmlspecialchars($sankeyMode, ENT_QUOTES, 'UTF-8'); ?>)</h5>
            <div style="display:flex; align-items:center; gap:10px;">
                <span class="copy-ok" id="shareSankeyOk" style="font-size: 11px; color: #2ecc71; font-weight: normal; display: none;">URL COPIED!</span>
                <button type="button" class="btn-secondary-custom" id="shareSankeyUrl" style="padding: 4px 10px;" title="Copy share URL">
                    <span class="material-symbols-outlined" style="font-size:16px!important;">share</span>
                </button>
                <button type="button" class="btn-secondary-custom" id="toggleSankeyInfo" style="padding: 4px 10px;" title="Chart information">
                    <span class="material-symbols-outlined" style="font-size:16px!important;">info</span>
                </button>
            </div>
        </div>
        <div class="info-pop" id="sankeyInfoBox" style="padding: 15px; background: #fff3cd; border-bottom: 1px solid #ffe69c; color: #856404; font-size: 12px; display: none;">
            Mode Sankey bisa diganti dari toolbar. Node kecil akan dikelompokkan ke <strong>Other</strong> untuk menjaga chart tetap stabil saat data besar. Hover pada flow akan menyorot seluruh jalur terkait.
        </div>
        <div class="dashboard-card-body p-4">
            <div class="sankey-inner"><div id="sankeyChart" class="sankey-canvas" style="min-height: 400px;"></div></div>
            <div class="mt-4 pt-3" style="border-top: 1px dashed #e0e4e8; display:flex; gap:20px; font-size: 12px; font-weight: normal; color: #7f8c8d;">
                <span style="display:flex; align-items:center; gap:5px;"><div style="width:12px; height:12px; background:#1f77b4; border-radius:2px;"></div> Source</span>
                <span style="display:flex; align-items:center; gap:5px;"><div style="width:12px; height:12px; background:#ff7f0e; border-radius:2px;"></div> Destination / Layer</span>
                <span style="display:flex; align-items:center; gap:5px;"><div style="width:12px; height:12px; background:rgba(0,0,0,0.2); border-radius:2px;"></div> Traffic flow</span>
                <span style="margin-left: auto; color:#b5c1c9;">Grouped to Top <?= (int)$nodeGroupN; ?> nodes per layer</span>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="font-size:18px!important; color:#004d40;">swap_horiz</span> Top Conversations (Src -> Dst) (V2)</h5>
                    <div style="display:flex; gap:10px; align-items:center;">
                        <button type="button" class="btn-secondary-custom" id="toggleSankey" style="padding: 2px 8px;" title="Toggle Sankey">
                            <span class="material-symbols-outlined" style="font-size:16px!important;">account_tree</span>
                        </button>
                        <span style="background:#e0e4e8; padding:2px 8px; border-radius:4px; font-size:10px; font-weight: normal;"><?= (int)$topN; ?> ROWS</span>
                    </div>
                </div>
                <div class="dashboard-card-body">
                    <table class="table-pfms">
                        <thead>
                            <tr><th>Src</th><th>Dst</th><th>Flows</th><th>Packets</th><th>Bytes</th><th>B/s</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($data['top_conv'])): ?>
                            <tr><td colspan="6" class="text-center text-muted">No data available.</td></tr>
                        <?php else: foreach ($data['top_conv'] as $r): ?>
                            <tr>
                                <td class="code-ip"><?= htmlspecialchars((string)$r['src'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="code-ip"><?= htmlspecialchars((string)$r['dst'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= (int)$r['flw']; ?></td>
                                <td><?= (int)$r['pkt']; ?></td>
                                <td class="code-mono"><?= htmlspecialchars(bytes_fmt((float)$r['byt']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="code-mono"><?= htmlspecialchars(bps_fmt((float)$r['bps']), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="font-size:18px!important; color:#004d40;">login</span> Top Source IP</h5>
                    <span style="background:#e0e4e8; padding:2px 8px; border-radius:4px; font-size:10px; font-weight: normal;"><?= (int)$topN; ?> ROWS</span>
                </div>
                <div class="dashboard-card-body">
                    <table class="table-pfms">
                        <thead>
                            <tr><th>IP</th><th>Flows</th><th>Packets</th><th>Bytes</th><th>B/s</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($data['top_src'])): ?>
                            <tr><td colspan="5" class="text-center text-muted">No data.</td></tr>
                        <?php else: foreach ($data['top_src'] as $r): ?>
                            <tr>
                                <td class="code-ip"><?= htmlspecialchars((string)$r['ip'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= (int)$r['flw']; ?></td>
                                <td><?= (int)$r['pkt']; ?></td>
                                <td class="code-mono"><?= htmlspecialchars(bytes_fmt((float)$r['byt']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="code-mono"><?= htmlspecialchars(bps_fmt((float)$r['bps']), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="font-size:18px!important; color:#004d40;">logout</span> Top Destination IP</h5>
                    <span style="background:#e0e4e8; padding:2px 8px; border-radius:4px; font-size:10px; font-weight: normal;"><?= (int)$topN; ?> ROWS</span>
                </div>
                <div class="dashboard-card-body">
                    <table class="table-pfms">
                        <thead>
                            <tr><th>IP</th><th>Flows</th><th>Packets</th><th>Bytes</th><th>B/s</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($data['top_dst'])): ?>
                            <tr><td colspan="5" class="text-center text-muted">No data.</td></tr>
                        <?php else: foreach ($data['top_dst'] as $r): ?>
                            <tr>
                                <td class="code-ip"><?= htmlspecialchars((string)$r['ip'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= (int)$r['flw']; ?></td>
                                <td><?= (int)$r['pkt']; ?></td>
                                <td class="code-mono"><?= htmlspecialchars(bytes_fmt((float)$r['byt']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="code-mono"><?= htmlspecialchars(bps_fmt((float)$r['bps']), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="font-size:18px!important; color:#004d40;">router</span> Top Destination Port</h5>
                    <span style="background:#e0e4e8; padding:2px 8px; border-radius:4px; font-size:10px; font-weight: normal;"><?= (int)$topN; ?> ROWS</span>
                </div>
                <div class="dashboard-card-body">
                    <table class="table-pfms">
                        <thead>
                            <tr><th>Port</th><th>Flows</th><th>Packets</th><th>Bytes</th><th>B/s</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($data['top_dport'])): ?>
                            <tr><td colspan="5" class="text-center text-muted">No data.</td></tr>
                        <?php else: foreach ($data['top_dport'] as $r): ?>
                            <tr>
                                <td class="code-ip"><?= htmlspecialchars((string)$r['port'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= (int)$r['flw']; ?></td>
                                <td><?= (int)$r['pkt']; ?></td>
                                <td class="code-mono"><?= htmlspecialchars(bytes_fmt((float)$r['byt']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="code-mono"><?= htmlspecialchars(bps_fmt((float)$r['bps']), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 mb-4">
            <div class="dashboard-card">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="font-size:18px!important; color:#004d40;">list_alt</span> Flows (Top <?= (int)$flowN; ?> Flows Sorted by <?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8'); ?>)</h5>
                </div>
                <div class="dashboard-card-body">
                    <table class="table-pfms">
                        <thead>
                            <tr><th>Start</th><th>Dur</th><th>Proto</th><th>Src</th><th>Dst</th><th>Packets</th><th>Bytes</th><th>B/s</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($data['flows'])): ?>
                            <tr><td colspan="8" class="text-center text-muted">No data.</td></tr>
                        <?php else: foreach ($data['flows'] as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$r['ts'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= htmlspecialchars((string)$r['td'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="text-transform: uppercase; font-weight: normal !important; color: #1976d2 !important;"><?= htmlspecialchars((string)$r['pr'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="code-ip"><?= htmlspecialchars((string)$r['sa'] . ':' . (string)$r['sp'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="code-ip"><?= htmlspecialchars((string)$r['da'] . ':' . (string)$r['dp'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?= (int)$r['pkt']; ?></td>
                                <td class="code-mono"><?= htmlspecialchars(bytes_fmt((float)$r['byt']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="code-mono"><?= htmlspecialchars(bps_fmt((float)$r['bps']), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div style="background: #e3f2fd; border: 1px solid #bbdefb; padding: 15px 20px; border-radius: 8px; font-size: 12px; color: #0d47a1;">
        <strong style="display:flex; align-items:center; gap:5px; margin-bottom:5px;">
            <span class="material-symbols-outlined" style="font-size:16px!important;">info</span> Technical Notes
        </strong>
        <ul style="margin:0; padding-left: 20px;">
            <li>File names follow <code>nfcapd.YYYYMMDDHHMM</code> (typically rotated every 5 minutes).</li>
            <li>Time window is passed to <code>nfdump -t</code> as <code>YYYY/MM/dd.hh:mm:ss-YYYY/MM/dd.hh:mm:ss</code>.</li>
            <li>Filter is passed via <code>nfdump -f filterfile</code> to avoid command injection.</li>
        </ul>
    </div>

</div>

<script>
window.NetflowExplorerConfig = {
  rawSankeyData: <?= $sankeyJson ?: '[]'; ?>,
  sankeyLimit: <?= (int)$sankeyN; ?>,
  sankeyMode: <?= $sankeyModeJs; ?>,
  autoRefresh: <?= $autoRefreshJs; ?>,
  sankeyOpenDefault: <?= $sankeyOpen ? 'true' : 'false'; ?>
};
</script>
<script src="assets/js/netflow-explorer.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Simple toggle logic for Sankey Info Box since original JS might rely on different IDs
    const toggleInfoBtn = document.getElementById('toggleSankeyInfo');
    const infoBox = document.getElementById('sankeyInfoBox');
    if(toggleInfoBtn && infoBox) {
        toggleInfoBtn.addEventListener('click', () => {
            infoBox.style.display = infoBox.style.display === 'none' || infoBox.style.display === '' ? 'block' : 'none';
        });
    }
});
</script>

</body>
</html>
