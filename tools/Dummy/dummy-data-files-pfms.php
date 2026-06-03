<?php
/* custom-webui-template.php
 *
 * Template Boilerplate untuk Dashboard/Panel Custom Pandora FMS
 * Menggunakan UI/UX, CSS, dan arsitektur DB/API standar Enterprise.
 */

$DEFAULT_TZ = "Asia/Jakarta";
date_default_timezone_set($DEFAULT_TZ);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// 1. DYNAMIC BREADCRUMB & TITLE
$dynamic_breadcrumb = "PANDORA CONSOLE / CUSTOM / PANEL / NEW MODULE";
$page_title = "Custom Panel Title";

// 2. CONFIG LOADING & CSRF
$PANDORA_BASE_URL = "/pandora_console";
$config_paths = ['/var/www/html/pandora_console/include/config.php', '../../include/config.php', '../include/config.php'];
$config_loaded = false;
foreach ($config_paths as $path) { if (file_exists($path)) { require_once($path); $config_loaded = true; break; } }

if (session_status() === PHP_SESSION_NONE) session_start();
$csrf_token = $_SESSION['pfms_csrf_token'] ?? '';

// 3. HELPERS & DB INIT
// Sesuaikan path utils.php jika diperlukan
@include_once(__DIR__ . '/../tools/utils.php'); 

$pdo = null; $db_status = false; $db_error = '';
if ($config_loaded) {
    try {
        // Asumsi fungsi get_db_connection() tersedia di utils.php
        // Jika tidak, ganti dengan inisialisasi PDO standar menggunakan $config['dbhost'], dll.
        if (function_exists('get_db_connection')) {
            $pdo = get_db_connection($config);
        } else {
            $dsn = "mysql:host={$config['dbhost']};dbname={$config['dbname']};charset=utf8mb4";
            $pdo = new PDO($dsn, $config['dbuser'], $config['dbpass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        }
        $db_status = true;
    } catch (PDOException $e) { $db_error = $e->getMessage(); }
}

// =====================================================================
// 4. AJAX ENDPOINTS (BACKEND API)
// =====================================================================
$api = $_GET['api'] ?? '';

// API 1: Fetch Data Tabel/Card
if ($api === 'fetch_data' && $db_status) {
    ob_clean(); header('Content-Type: application/json');
    
    // Parameter dari Frontend
    $search = $_GET['search'] ?? '';
    $limit  = (int)($_GET['limit'] ?? 15);
    $page   = (int)($_GET['page'] ?? 1);
    $offset = ($page - 1) * $limit;

    try {
        // --- TULIS QUERY CUSTOM ANDA DI SINI ---
        // Contoh Query Skeleton:
        /*
        $sql = "SELECT id_agente, alias, direccion FROM tagente WHERE disabled = 0 AND alias LIKE ? LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(["%$search%"]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        */
        
        // Data Mockup untuk testing UI
        $rows = [
            ['id' => 1, 'name' => 'Server Alpha', 'ip' => '192.168.1.10', 'status' => 'OK'],
            ['id' => 2, 'name' => 'Router Bravo', 'ip' => '192.168.1.1', 'status' => 'WARNING']
        ];
        
        $totalFound = 2; // Ganti dengan COUNT() query asli Anda

        echo json_encode(['ok' => true, 'data' => $rows, 'total' => $totalFound, 'updated' => date('H:i:s')]);
    } catch (Exception $e) { 
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]); 
    }
    exit;
}

// API 2: Menyimpan Konfigurasi/Aksi (Contoh POST)
if ($api === 'save_action') {
    ob_clean(); header('Content-Type: application/json');
    
    // CSRF Validation Security
    $client_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrf_token) || $client_token !== $csrf_token) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF Token.']); exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    // --- LAKUKAN PROSES SIMPAN/UPDATE DB DI SINI ---
    
    echo json_encode(['ok' => true, 'message' => 'Data berhasil disimpan']); 
    exit;
}

$isStandalone = (isset($_GET['standalone']) && $_GET['standalone'] == '1') || (isset($_GET['s']) && $_GET['s'] == '1');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($page_title) ?></title>
    <link rel="icon" href="<?= h($PANDORA_BASE_URL) ?>/images/pandora.ico" type="image/x-icon">
    
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/custom/panel/vendor/fonts/fonts.css" rel="stylesheet">
    <link href="<?= htmlspecialchars($PANDORA_BASE_URL ?? "/pandora_console") ?>/custom/panel/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #334155; font-size: 14px; -webkit-font-smoothing: antialiased; background-color: #f4f6f8; margin: 0; padding: 0; }
        * { box-sizing: border-box; }
        .material-symbols-outlined { font-family: 'Material Symbols Outlined' !important; font-size: 18px !important; vertical-align: middle; line-height: 1; }

        /* Standalone Mode Handling */
        <?php if ($isStandalone): ?>
        .pandora-header-top, .pandora-header-bottom, .top-controls { display: none !important; visibility: hidden !important; }
        body { background-color: #ffffff !important; padding: 0 !important; }
        .main-content { padding: 20px 25px !important; width: 100% !important; max-width: 100% !important; margin: 0 !important; }
        .dashboard-card { box-shadow: none !important; border: 1px solid #eee !important; border-radius: 4px !important; width: 100% !important; }
        <?php endif; ?>

        /* Header Layouts */
        .pandora-header-top { background-color: #ffffff; border-bottom: 1px solid #e0e4e8; height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 0 25px; z-index: 10; }
        .header-logo { height: 24px; width: auto; }
        .header-divider { width: 1px; height: 28px; background-color: #dce1e5; margin: 0 20px; }
        .header-title-box { display: flex; flex-direction: column; line-height: 1.2; margin-right: 40px; }
        .header-title-box .main-title { font-size: 14px !important; font-weight: normal !important; color: #0b1a26 !important; }
        .nav-icon-btn { color: #4a5568 !important; text-decoration: none; display: flex; align-items: center; justify-content: center; height: 36px; width: 36px; border-radius: 50%; transition: 0.2s; border:none; background:transparent; cursor:pointer;}

        .pandora-header-bottom { background-color: #f4f6f8; padding: 15px 30px; display: flex; align-items: center; justify-content: space-between; }
        .page-breadcrumb { font-size: 11px !important; color: #64748b !important; margin-bottom: 4px; font-weight: normal !important; text-transform: uppercase; letter-spacing: 0.5px; }
        .page-title { font-size: 18px !important; color: #0b1a26 !important; margin: 0; font-weight: 600 !important; line-height: 1.2; }

        /* Buttons & Controls */
        .top-controls { display: flex !important; flex-direction: row !important; gap: 10px !important; align-items: center !important; }
        .btn-apply { background: #004d40; color: #fff !important; border: none; padding: 8px 25px; border-radius: 4px; font-weight: normal !important; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap; transition:0.2s;}
        .btn-apply:hover { background: #00332a; }
        .btn-secondary-custom { background: #fff; color: #4a5568 !important; border: 1px solid #dce1e5; padding: 8px 20px; border-radius: 4px; font-weight: normal !important; cursor: pointer; display: flex; align-items: center; gap: 5px; white-space: nowrap;}
        
        /* Grid & Cards */
        .main-content { padding: 0 30px 30px 30px; }
        .dashboard-card { background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); display: inline-block; width:100%; margin-bottom:20px; border: 1px solid #f0f3f5; overflow: hidden;}
        .dashboard-card-header { padding: 15px 20px; border-bottom: 1px solid #e0e4e8; background-color: #f8f9fa; display: flex; justify-content: space-between; align-items: center; }
        .dashboard-card-title { font-size: 14px !important; font-weight: 500 !important; color: #1e293b !important; margin: 0; display: flex; align-items: center; gap: 8px; }
        .dashboard-card-body { padding: 20px; }

        /* Tables */
        .table-wrap { overflow-x: auto; width: 100%; }
        table.table-pfms { border-collapse: collapse !important; width: 100% !important; margin: 0 !important; }
        table.table-pfms thead th { background-color: #ffffff !important; border-bottom: 2px solid #e0e4e8 !important; text-transform: uppercase; padding: 12px 20px !important; font-weight: normal !important; color: #7f8c8d !important; font-size: 10px !important; text-align: left; }
        table.table-pfms tbody td { font-weight: normal !important; border-bottom: 1px solid #f0f3f5; padding: 12px 20px !important; color: #0b1a26 !important; }

        /* Status Colors & Pills */
        .status-pill { padding: 4px 10px; border-radius: 4px; font-weight: normal !important; font-size: 10px !important; text-transform: uppercase; display: inline-block; text-align: center; color: #fff;}
        .bg-green { background: linear-gradient(135deg, #2ecc71, #27ae60) !important; }
        .bg-yellow { background: linear-gradient(135deg, #f1c40f, #f39c12) !important; }
        .bg-red { background: linear-gradient(135deg, #e74c3c, #c0392b) !important; }

        /* Overlays & Modals */
        #loadingOverlay { position: fixed; inset: 0; background: rgba(255,255,255,0.7); display: none; align-items: center; justify-content: center; z-index: 9999; flex-direction: column; gap: 15px; }
        .spinner { width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #004d40; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .loading-text { font-weight: normal !important; color: #004d40; letter-spacing: 1px; text-transform: uppercase; font-size: 11px !important; }

        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-box { background: #fff; width: 550px; padding: 25px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #e0e4e8; max-height: 90vh; overflow-y: auto; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 11px !important; text-transform: uppercase; font-weight: normal !important; color: #7f8c8d; margin-bottom: 5px; }
        .form-control-fix { width: 100%; height: 36px; padding: 8px 12px; border: 1px solid #dce1e5; border-radius: 4px; background-color: #fff; outline: none; }
    </style>
</head>
<body>

<div id="loadingOverlay">
    <div class="spinner"></div>
    <div class="loading-text">Processing Data...</div>
</div>

<div class="pandora-header-top">
    <div class="header-left" style="display:flex; align-items:center;">
        <img src="<?= h($PANDORA_BASE_URL) ?>/enterprise/images/custom_logo/logo-default-pandorafms.png" alt="Logo" class="header-logo" onerror="this.style.display='none'">
        <div class="header-divider"></div>
        <div class="header-title-box"><span class="main-title">Pandora FMS</span><span class="sub-title">Custom Extensions</span></div>
    </div>
    <div class="header-right"><a href="<?= h($PANDORA_BASE_URL) ?>/index.php" class="nav-icon-btn"><span class="material-symbols-outlined">home</span></a></div>
</div>

<div class="pandora-header-bottom">
    <div class="breadcrumb-box">
        <span class="page-breadcrumb"><?= h($dynamic_breadcrumb) ?></span>
        <h1 class="page-title"><?= h($page_title) ?></h1>
    </div>
    <div class="top-controls">
        <button class="btn-secondary-custom" onclick="openSettingsModal()"><span class="material-symbols-outlined">settings</span> Settings</button>
        <button class="btn-apply" onclick="loadData()"><span class="material-symbols-outlined">refresh</span> Refresh Data</button>
    </div>
</div>

<div class="main-content pt-4">
    <?php if (!$db_status): ?>
        <div class="alert alert-danger"><strong>Database Error:</strong> <?= h($db_error) ?></div>
    <?php endif; ?>

    <div class="dashboard-card">
        <div class="dashboard-card-header">
            <h5 class="dashboard-card-title"><span class="material-symbols-outlined" style="color:#004d40;">table_chart</span> Data Overview</h5>
            <div style="font-size:10px; color:#7f8c8d;" id="last_updated">Awaiting...</div>
        </div>
        <div class="dashboard-card-body" style="padding:0;">
            <div class="table-wrap">
                <table class="table-pfms">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Node Name</th>
                            <th>IP Address</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="table_body">
                        <tr><td colspan="4" style="text-align:center; padding:20px; color:#7f8c8d;">Memuat data...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal-overlay" id="settingsModal">
    <div class="modal-box">
        <div style="display:flex; justify-content:space-between; border-bottom:1px solid #eee; padding-bottom:15px; margin-bottom:20px;">
            <h5 style="font-weight: normal!important; text-transform:uppercase;">Settings Panel</h5>
            <span class="material-symbols-outlined" style="cursor:pointer;color:#7f8c8d;" onclick="closeSettingsModal()">close</span>
        </div>
        
        <div class="form-group">
            <label>Contoh Input Teks</label>
            <input type="text" id="setting_input" class="form-control-fix" placeholder="Ketik sesuatu...">
        </div>

        <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
            <button class="btn-secondary-custom" onclick="closeSettingsModal()">Cancel</button>
            <button class="btn-apply" onclick="saveSettings()">Save Configuration</button>
        </div>
    </div>
</div>

<script>
// ==========================================
// STATE MANAGEMENT & CONFIG
// ==========================================
const CSRF_TOKEN = "<?= $csrf_token ?>";
let currentPage = 1;

// ==========================================
// CORE FUNCTIONS
// ==========================================
function toggleLoading(show) {
    document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
}

function loadData() {
    toggleLoading(true);
    
    // Sesuaikan parameter pencarian dan pagination
    const url = `?api=fetch_data&page=${currentPage}&limit=15&search=`;

    fetch(url)
        .then(response => response.json())
        .then(res => {
            toggleLoading(false);
            if (!res.ok) {
                alert("Gagal memuat data: " + res.error);
                return;
            }
            
            document.getElementById('last_updated').innerText = "Updated: " + res.updated;
            renderTable(res.data);
        })
        .catch(err => {
            toggleLoading(false);
            console.error("Fetch error:", err);
        });
}

function renderTable(data) {
    const tbody = document.getElementById('table_body');
    
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:20px; color:#7f8c8d;">Tidak ada data ditemukan.</td></tr>';
        return;
    }

    let html = '';
    data.forEach(row => {
        // Logika pewarnaan status kustom
        let badgeColor = 'bg-gray';
        if(row.status === 'OK' || row.status === 'UP') badgeColor = 'bg-green';
        if(row.status === 'WARNING') badgeColor = 'bg-yellow';
        if(row.status === 'CRITICAL' || row.status === 'DOWN') badgeColor = 'bg-red';

        html += `
            <tr>
                <td>${row.id}</td>
                <td><strong>${row.name}</strong></td>
                <td><code>${row.ip}</code></td>
                <td><span class="status-pill ${badgeColor}">${row.status}</span></td>
            </tr>
        `;
    });
    
    tbody.innerHTML = html;
}

// ==========================================
// MODAL CONTROLS
// ==========================================
function openSettingsModal() { document.getElementById('settingsModal').style.display = 'flex'; }
function closeSettingsModal() { document.getElementById('settingsModal').style.display = 'none'; }

function saveSettings() {
    const val = document.getElementById('setting_input').value;
    
    // Contoh melakukan aksi POST ke backend
    fetch('?api=save_action', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': CSRF_TOKEN
        },
        body: JSON.stringify({ setting_value: val })
    })
    .then(r => r.json())
    .then(res => {
        if(res.ok) {
            closeSettingsModal();
            loadData(); // Refresh table setelah simpan
        } else {
            alert("Error: " + res.error);
        }
    });
}

// Inisialisasi awal saat halaman dimuat
document.addEventListener("DOMContentLoaded", () => {
    <?php if ($db_status): ?>
        loadData();
    <?php endif; ?>
});
</script>
</body>
</html>