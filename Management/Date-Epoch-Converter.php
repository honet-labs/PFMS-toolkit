<?php
// Dynamically locate includes/db-connection.php by searching parent directories upwards
$dir = __DIR__;
while ($dir !== '/' && $dir !== '.' && !file_exists($dir . '/includes/db-connection.php')) {
    $parent = dirname($dir);
    if ($parent === $dir) break;
    $dir = $parent;
}
require_once $dir . '/includes/db-connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$user_id = $_SESSION['id_usuario'] ?? 0;

// Security Check: Ensure valid Pandora FMS session
if (empty($user_id)) {
    header("Location: /pandora_console/index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Date-to-Epoch Converter - Pandora FMS</title>
    <!-- Core fonts and style dependencies -->
    <link href="/pandora_console/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="/pandora_console/custom/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6f8; color: #334155; font-size: 13px; margin: 0; padding: 0; }
        .material-symbols-outlined { vertical-align: middle; font-size: 18px; }
        .header-section { padding: 15px 30px; background: #fff; border-bottom: 1px solid #e0e4e8; display: flex; justify-content: space-between; align-items: center; }
        .page-title { font-size: 16px; font-weight: 600; color: #0b1a26; margin: 0; }
        .main-container { padding: 25px 30px; }
        .card-custom { background: #fff; border-radius: 8px; border: 1px solid #e0e4e8; box-shadow: 0 2px 8px rgba(0,0,0,0.04); overflow: hidden; }
        .table-pfms { width: 100%; border-collapse: collapse; }
        .table-pfms th { background: #f8f9fa; padding: 10px 20px; text-align: left; font-size: 10px; text-transform: uppercase; color: #7f8c8d; border-bottom: 1px solid #e0e4e8; }
        .table-pfms td { padding: 10px 20px; border-bottom: 1px solid #f0f3f5; }
        .btn-pfms { padding: 6px 14px; border-radius: 4px; font-size: 12px; font-weight: 500; cursor: pointer; transition: 0.2s; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 6px; }
        .btn-primary-pfms { background: #004d40; color: #fff; }
        .btn-outline-pfms { background: #fff; border-color: #dce1e5; color: #4a5568; }
        .d-none { display: none !important; }
    </style>
</head>
<body>

<div class="header-section">
    <div><h1 class="page-title">Date-to-Epoch Converter</h1></div>
</div>

<div class="main-container">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card-custom" style="padding: 25px 30px;">
                
                <!-- Report ID Parameter -->
                <div class="mb-3">
                    <label class="form-label" style="font-weight: 500; color: #4a5568;">Report ID (id_report)</label>
                    <input type="number" id="reportId" class="form-control" placeholder="Enter Report ID (e.g. 3)" min="1" oninput="calculateEpochs()">
                    <div class="form-text" style="font-size: 11px; color: #94a3b8;">Input the numerical ID of the report you wish to trigger.</div>
                </div>
                
                <!-- Preset Options -->
                <div class="mb-3">
                    <label class="form-label" style="font-weight: 500; color: #4a5568;">Timeframe Preset</label>
                    <select id="datePreset" class="form-select" onchange="calculateEpochs()">
                        <option value="1h">Last 1 Hour</option>
                        <option value="24h" selected>Last 24 Hours</option>
                        <option value="7d">Last 7 Days</option>
                        <option value="30d">Last 30 Days</option>
                        <option value="this_month">This Month</option>
                        <option value="custom">Custom Date & Time Range...</option>
                    </select>
                </div>
                
                <!-- Custom DateTime Inputs -->
                <div class="mb-4 d-none" id="customRangeContainer">
                    <label class="form-label" style="font-weight: 500; color: #4a5568;">Custom Date & Time Range</label>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <span style="font-size: 11px; color: #64748b; display: block; margin-bottom: 5px;">Start Date & Time</span>
                            <input type="datetime-local" id="customStart" class="form-control" onchange="calculateEpochs()">
                        </div>
                        <div class="col-sm-6">
                            <span style="font-size: 11px; color: #64748b; display: block; margin-bottom: 5px;">End Date & Time</span>
                            <input type="datetime-local" id="customEnd" class="form-control" onchange="calculateEpochs()">
                        </div>
                    </div>
                </div>
                
                <!-- Epoch Table Output -->
                <div class="mb-4">
                    <label class="form-label" style="font-weight: 500; color: #4a5568; margin-bottom: 10px;">Calculated Epoch Timestamps</label>
                    <div style="border: 1px solid #e0e4e8; border-radius: 6px; overflow: hidden;">
                        <table class="table-pfms">
                            <thead>
                                <tr>
                                    <th>Parameter</th>
                                    <th>Unix Epoch Timestamp</th>
                                    <th>Human-Readable Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><b style="color: #4a5568;">date_init (Start)</b></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <code id="startEpochText" style="font-size: 13px; font-weight: bold; color: #004d40;">0</code>
                                            <button class="btn-pfms btn-outline-pfms" onclick="copyToClipboard('startEpochText', this)" style="padding: 2px 6px;" title="Copy Start Epoch">
                                                <span class="material-symbols-outlined" style="font-size: 13px;">content_copy</span>
                                            </button>
                                        </div>
                                    </td>
                                    <td id="startHumanDate" style="color: #64748b;">N/A</td>
                                </tr>
                                <tr>
                                    <td><b style="color: #4a5568;">date_end (End)</b></td>
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 8px;">
                                            <code id="endEpochText" style="font-size: 13px; font-weight: bold; color: #004d40;">0</code>
                                            <button class="btn-pfms btn-outline-pfms" onclick="copyToClipboard('endEpochText', this)" style="padding: 2px 6px;" title="Copy End Epoch">
                                                <span class="material-symbols-outlined" style="font-size: 13px;">content_copy</span>
                                            </button>
                                        </div>
                                    </td>
                                    <td id="endHumanDate" style="color: #64748b;">N/A</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Target URL Preview -->
                <div class="mb-4">
                    <label class="form-label" style="font-weight: 500; color: #4a5568;">Generated Target PDF URL</label>
                    <div id="urlPreview" style="font-family: monospace; font-size: 11px; background: #f8f9fa; border: 1px solid #e0e4e8; padding: 12px 15px; border-radius: 4px; word-break: break-all; color: #2980b9;">
                        Please enter a valid Report ID...
                    </div>
                </div>
                
                <!-- Generate Button -->
                <button id="btnGenerateReport" class="btn-pfms btn-primary-pfms" style="width: 100%; height: 40px; justify-content: center; font-size: 13px;" onclick="generatePdf()" disabled>
                    <span class="material-symbols-outlined" style="font-size: 16px;">picture_as_pdf</span> Generate & View PDF Report
                </button>
                
            </div>
        </div>
    </div>
</div>

<script>
    // Helper to format Date objects as 'YYYY-MM-DDTHH:mm' local string for input[type="datetime-local"]
    function toLocalISOString(date) {
        const offset = date.getTimezoneOffset() * 60000;
        return new Date(date.getTime() - offset).toISOString().slice(0, 16);
    }

    // Set initial custom date pickers values to 24h range
    const initEndDate = new Date();
    const initStartDate = new Date(Date.now() - 24 * 60 * 60 * 1000);
    
    document.getElementById('customStart').value = toLocalISOString(initStartDate);
    document.getElementById('customEnd').value = toLocalISOString(initEndDate);

    // Calculate dynamic epoch times and update triggers
    function calculateEpochs() {
        const reportId = document.getElementById('reportId').value.trim();
        const preset = document.getElementById('datePreset').value;
        const customContainer = document.getElementById('customRangeContainer');
        const btn = document.getElementById('btnGenerateReport');
        
        let startEpoch, endEpoch;
        const now = new Date();
        
        if (preset === 'custom') {
            customContainer.classList.remove('d-none');
            
            const startVal = document.getElementById('customStart').value;
            const endVal = document.getElementById('customEnd').value;
            
            if (startVal) {
                startEpoch = Math.floor(new Date(startVal).getTime() / 1000);
            } else {
                startEpoch = Math.floor((Date.now() - 24 * 60 * 60 * 1000) / 1000);
            }
            
            if (endVal) {
                endEpoch = Math.floor(new Date(endVal).getTime() / 1000);
            } else {
                endEpoch = Math.floor(Date.now() / 1000);
            }
        } else {
            customContainer.classList.add('d-none');
            endEpoch = Math.floor(Date.now() / 1000);
            
            switch (preset) {
                case '1h':
                    startEpoch = endEpoch - 3600;
                    break;
                case '24h':
                    startEpoch = endEpoch - 86400;
                    break;
                case '7d':
                    startEpoch = endEpoch - (86400 * 7);
                    break;
                case '30d':
                    startEpoch = endEpoch - (86400 * 30);
                    break;
                case 'this_month':
                    const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
                    startEpoch = Math.floor(startOfMonth.getTime() / 1000);
                    break;
                default:
                    startEpoch = endEpoch - 86400;
            }
        }
        
        // Update visual elements
        document.getElementById('startEpochText').innerText = startEpoch;
        document.getElementById('endEpochText').innerText = endEpoch;
        
        document.getElementById('startHumanDate').innerText = new Date(startEpoch * 1000).toLocaleString();
        document.getElementById('endHumanDate').innerText = new Date(endEpoch * 1000).toLocaleString();
        
        // Construct and preview final target URL
        if (reportId && !isNaN(reportId) && parseInt(reportId) > 0) {
            const targetUrl = `/pandora_console/enterprise/operation/reporting/reporting_viewer_pdf.php?id_report=${reportId}&origin=&date_init=${startEpoch}&date_end=${endEpoch}`;
            document.getElementById('urlPreview').innerText = window.location.origin + targetUrl;
            
            btn.disabled = false;
        } else {
            document.getElementById('urlPreview').innerText = 'Please enter a valid Report ID...';
            btn.disabled = true;
        }
    }

    // Trigger PDF report in new tab
    function generatePdf() {
        const reportId = document.getElementById('reportId').value.trim();
        if (!reportId) return;
        const start = document.getElementById('startEpochText').innerText;
        const end = document.getElementById('endEpochText').innerText;
        const url = `/pandora_console/enterprise/operation/reporting/reporting_viewer_pdf.php?id_report=${reportId}&origin=&date_init=${start}&date_end=${end}`;
        window.open(url, '_blank');
    }

    // Copy element text value to clipboard with micro-animation
    function copyToClipboard(elementId, btn) {
        const text = document.getElementById(elementId).innerText;
        navigator.clipboard.writeText(text).then(() => {
            const origIcon = btn.innerHTML;
            btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:13px; color:#10b981;">check</span>';
            setTimeout(() => {
                btn.innerHTML = origIcon;
            }, 1500);
        });
    }

    // Trigger initial calculation on load
    calculateEpochs();
</script>

</body>
</html>
