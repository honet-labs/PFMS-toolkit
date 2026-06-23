<?php
// =====================================================================
// PANDORA FMS - PURE BASH OGG GENERATOR
// UI/UX Match with Inventory & Availability Dashboard
// - Updated: Module naming template to Status_OGG
// - Updated: Custom Cache Path feature added
// =====================================================================

// BREADCRUMB DYNAMIC LOGIC
$raw_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); 
$dir_only = dirname($raw_path); 
$clean_path = trim($dir_only, '/'); 

// Rapikan teks (ganti _ dan - jadi spasi, lalu kapital huruf awal)
$path_array = explode('/', $clean_path);
$formatted_array = array_map(function($p) {
    return ucwords(str_replace(['_', '-'], ' ', $p));
}, $path_array);
$dynamic_breadcrumb = implode(' / ', $formatted_array);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PandoraFMS - Pure Bash OGG Generator</title>
    
    <link rel="icon" href="/pandora_console/images/pandora.ico" type="image/x-icon">
    
    
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/fonts/fonts.css" />

    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">

    <style>
        /* Base Global Styling */
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
        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: flex-start; flex-direction: column; }
        .page-breadcrumb { font-size: 12px !important; font-weight: normal !important; color: #4a5568 !important; margin-bottom: 2px; }
        .page-title { font-size: 18px !important; font-weight: 600 !important; color: #0b1a26 !important; margin: 0; padding: 0; }

        /* MAIN CONTENT & CARDS */
        .main-content { padding: 0 30px 30px 30px; }
        
        .dashboard-card { background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: flex; flex-direction: column; }
        .dashboard-card-header { padding: 15px 20px; border-bottom: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; background-color: #f8f9fa; border-radius: 8px 8px 0 0; }
        .dashboard-card-title { font-size: 14px !important; font-weight: 500 !important; color: #0b1a26 !important; margin: 0; text-transform: uppercase; }
        .dashboard-card-body { padding: 20px; flex-grow: 1; display: flex; flex-direction: column; }

        /* FORMS */
        .form-label { font-size: 11px !important; text-transform: uppercase; font-weight: normal !important; color: #7f8c8d; margin-bottom: 5px; display: block; }
        .form-control { border: 1px solid #dce1e5; padding: 8px 12px; background-color: #fff; font-weight: normal !important; color: #000 !important; border-radius: 4px; }
        .form-control:focus { border-color: #004d40; box-shadow: 0 0 0 2px rgba(0,77,64,0.1); }
        
        .code-box { font-family: 'Courier New', Courier, monospace !important; font-weight: normal !important; font-size: 12px !important; white-space: pre; overflow-wrap: normal; overflow-x: auto; flex-grow: 1; resize: none; }

        /* BUTTONS */
        .btn-apply { background: #004d40; color: #fff !important; border: none; padding: 8px 25px; border-radius: 4px; font-weight: normal !important; transition: 0.2s; display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; }
        .btn-apply:hover { background: #00695c; color: #fff; }
        .btn-outline-apply { background: #fff; color: #004d40 !important; border: 1px solid #004d40; padding: 6px 15px; border-radius: 4px; font-weight: normal !important; transition: 0.2s; display: flex; align-items: center; gap: 6px; }
        .btn-outline-apply:hover { background: #f4f6f8; }

        .arrow-icon { font-size: 40px !important; color: #dce1e5 !important; }
    </style>
</head>
<body>

<div class="pandora-header-top">
    <div class="header-left">
        <img src="/pandora_console/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Pandora Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box">
            <span class="main-title">Pandora FMS</span>
            <span class="sub-title">PFMS-Toolkit</span>
        </div>
        <div class="custom-search-container">
            <span class="material-symbols-outlined search-icon">search</span>
            <input type="text" placeholder="Search configuration..." readonly onclick="alert('Tip: Use this tool to generate bash config without Python dependencies.')">
        </div>
    </div>
    <div class="header-right">
        <a href="/pandora_console/index.php" class="nav-icon-btn" title="Back to Home">
            <span class="material-symbols-outlined">home</span>
        </a>
    </div>
</div>

<div class="pandora-header-bottom">
    <span class="page-breadcrumb"><?= htmlspecialchars($dynamic_breadcrumb) ?></span>
    <h1 class="page-title">Pure Bash OGG Generator</h1>
</div>

<div class="main-content">
    <div class="row align-items-stretch" style="min-height: 65vh;">
        
        <div class="col-md-5 d-flex flex-column">
            <div class="dashboard-card h-100">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="font-size:16px!important; color:#004d40;">settings</span> 1. Parameter & Raw Data</h5>
                </div>
                <div class="dashboard-card-body">
                    <div class="row mb-3">
                        <div class="col-md-4 mb-2">
                            <label class="form-label">OS User OGG</label>
                            <input type="text" id="osUser" class="form-control" value="oracle">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label">Path GGSCI</label>
                            <input type="text" id="ggsciPath" class="form-control" value="/ogg/ggsci">
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="form-label">Cache Path</label>
                            <input type="text" id="cachePath" class="form-control" value="/tmp/ogg.cache" title="File output sementara (Pastikan folder bisa ditulisi)">
                        </div>
                    </div>
                    <hr style="border-color: #e0e4e8; margin: 5px 0 15px 0;">
                    
                    <label class="form-label">Paste Output 'info all'</label>
                    <textarea id="rawInput" class="form-control code-box mb-3" placeholder="Program      Status      Group       Lag at Chkpt  Time Since Chkpt&#10;&#10;MANAGER      STOPPED&#10;EXTRACT      ABENDED     E_SND       00:00:00      3556:42:28..."></textarea>
                    
                    <button class="btn-apply" onclick="generateConfig()">
                        <span class="material-symbols-outlined" style="font-size:18px!important;">build_circle</span> GENERATE BASH CONFIG
                    </button>
                </div>
            </div>
        </div>

        <div class="col-md-1 d-flex align-items-center justify-content-center">
            <span class="material-symbols-outlined arrow-icon">arrow_forward</span>
        </div>

        <div class="col-md-6 d-flex flex-column">
            <div class="dashboard-card h-100">
                <div class="dashboard-card-header">
                    <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="font-size:16px!important; color:#004d40;">terminal</span> 2. Pandora Agent Configuration</h5>
                    <button class="btn-outline-apply" onclick="copyOutput()">
                        <span class="material-symbols-outlined" style="font-size:16px!important;">content_copy</span> Copy All
                    </button>
                </div>
                <div class="dashboard-card-body">
                    <textarea id="configOutput" class="form-control code-box w-100" readonly placeholder="Hasil konfigurasi Bash murni akan otomatis digenerate dan muncul di sini."></textarea>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/<?= htmlspecialchars($PANEL_DIR_NAME ?? "custom") ?>/panel/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
    function generateConfig() {
        const osUser = document.getElementById('osUser').value.trim();
        const ggsciPath = document.getElementById('ggsciPath').value.trim();
        const cachePath = document.getElementById('cachePath').value.trim() || '/tmp/ogg.cache';
        const rawText = document.getElementById('rawInput').value;
        const lines = rawText.split('\n');
        
        let output = "";
        let count = 0;

        // Base command dengan logika Caching di Bash (Berlaku 1 menit / -mmin -1)
        const bashCacheLogic = `find ${cachePath} -mmin -1 2>/dev/null | grep -q . || su - ${osUser} -c "bash -lc 'printf \\"%s\\\\n\\" \\"info all\\" \\"exit\\" | ${ggsciPath} '" > ${cachePath};`;

        lines.forEach(line => {
            const parts = line.trim().split(/\s+/);
            if (parts.length < 2) return;

            const prog = parts[0];

            // 1. Logika untuk proses inti (MANAGER, JAGENT, dll)
            if (['MANAGER', 'JAGENT', 'PMSRVR'].includes(prog)) {
                output += `# =======================================================\n`;
                output += `# MONITORING OGG: ${prog}\n`;
                output += `# =======================================================\n`;
                output += `module_begin\n`;
                output += `module_name Status_OGG_${prog}\n`;
                output += `module_type generic_data_string\n`;
                output += `module_exec ${bashCacheLogic} awk '$1=="${prog}" {print $2}' ${cachePath}\n`;
                output += `module_description Status proses utama ${prog}\n`;
                output += `module_group OGG\n`;
                output += `module_end\n\n`;
                count++;
            } 
            // 2. Logika untuk grup replikasi (EXTRACT, REPLICAT)
            else if (['EXTRACT', 'REPLICAT'].includes(prog)) {
                if (parts.length >= 3) {
                    const group = parts[2];
                    output += `# =======================================================\n`;
                    output += `# MONITORING OGG: GRUP ${group}\n`;
                    output += `# =======================================================\n`;
                    
                    // Modul Status
                    output += `module_begin\n`;
                    output += `module_name Status_OGG_${prog}_${group}\n`;
                    output += `module_type generic_data_string\n`;
                    output += `module_exec ${bashCacheLogic} awk '$1=="${prog}" && $3=="${group}" {print $2}' ${cachePath}\n`;
                    output += `module_description Status ${prog} ${group}\n`;
                    output += `module_group OGG\n`;
                    output += `module_end\n\n`;

                    // Modul Lag
                    output += `module_begin\n`;
                    output += `module_name Lag_OGG_${prog}_${group}\n`;
                    output += `module_type generic_data\n`;
                    output += `module_exec ${bashCacheLogic} awk '$1=="${prog}" && $3=="${group}" {split($4,a,":"); print int(a[1])*3600+int(a[2])*60+int(a[3])}' ${cachePath}\n`;
                    output += `module_description Keterlambatan replikasi ${group} (Detik)\n`;
                    output += `module_group OGG\n`;
                    output += `module_unit Sec\n`;
                    output += `module_end\n\n`;

                    // Modul Since Checkpoint
                    output += `module_begin\n`;
                    output += `module_name Since_OGG_${prog}_${group}\n`;
                    output += `module_type generic_data\n`;
                    output += `module_exec ${bashCacheLogic} awk '$1=="${prog}" && $3=="${group}" {split($5,a,":"); print int(a[1])*3600+int(a[2])*60+int(a[3])}' ${cachePath}\n`;
                    output += `module_description Waktu sejak checkpoint terakhir ${group} (Detik)\n`;
                    output += `module_group OGG\n`;
                    output += `module_unit Sec\n`;
                    output += `module_end\n\n`;
                    
                    count++;
                }
            }
        });

        if (output === "") {
            document.getElementById('configOutput').value = "Format tidak dikenali. Pastikan Anda copy-paste dari hasil output 'info all' OGG.";
        } else {
            document.getElementById('configOutput').value = output;
            alert(`Selesai!\n${count} metrik OGG dikonversi ke format Pure Bash.`);
        }
    }

    function copyOutput() {
        const copyText = document.getElementById("configOutput");
        if (!copyText.value) return;
        
        copyText.select();
        copyText.setSelectionRange(0, 99999);
        navigator.clipboard.writeText(copyText.value);
        
        alert("Konfigurasi berhasil disalin ke clipboard!");
    }
</script>
</body>
</html>


