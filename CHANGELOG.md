# Changelog - PFMS-Toolkit

Semua perubahan signifikan pada proyek ini akan didokumentasikan di file ini.

## [2.11.23] - 2026-10-26 (Traffic Dashboard - Fix Dashboard Settings Persistence, Modal Form Sync & URL Query Pollution)
### Fixed & Enhanced
- **Perbaikan Ketahanan Penyimpanan Pengaturan Dashboard (*Dashboard Settings Persistence*):**
  - Mengatasi masalah di mana perubahan yang dilakukan pada modal **Dashboard Settings** (seperti ambang batas *Warning*, *Critical*, ukuran font tabel *Table Font Size*, batas baris *Row Limit*, akhiran satuan *Show Unit Suffixes*, serta pilihan unit dan kategori aktif) tidak tersimpan secara permanen dan selalu kembali ke nilai awal bawaan (*default* 70, 80, 12, 20) ketika di-refresh atau dibuka kembali.
  - **Akar Penyebab (*Root Cause*):**
    1. **Polusi Parameter Query URL (*URL State Query Pollution*):** Fungsi `updateURLState()` sebelumnya menyuntikkan parameter statis dashboard (`warn`, `crit`, `fs`, `show_units`, `per_page`, `enabled_units`, `enabled_categories`) ke dalam URL browser dan window parent (iframe Pandora Console). Ketika browser atau embed dimuat ulang, query parameter yang memuat nilai default awal (`warn=70&crit=80`) diteruskan kembali dan secara paksa menimpa konfigurasi yang sudah tersimpan di server (`dSettings`) maupun `localStorage`.
    2. **Ketiadaan Pengisian Form Modal (*Unpopulated Settings Modal Inputs*):** Fungsi `openSettingsModal()` sebelumnya hanya mengubah visibilitas modal (`display: flex`) tanpa membaca dan mengisi form input modal dari konfigurasi dashboard aktif (`d.settings` / `localStorage`). Akibatnya modal selalu menampilkan nilai statis bawaan HTML.
    3. **Prioritas Konfigurasi Terbalik (*Configuration Hierarchy Inversion*):** Pada fungsi `openDashboard()`, query parameter URL diberi prioritas tertinggi di atas `dSettings` server. Karena query URL terkontaminasi oleh default lama, nilai server `dSettings` tidak pernah diterapkan saat reload.
    4. **Penyimpanan Asinkron & Perbandingan ID Bertipe Lemah:** Fungsi `saveSettings()` sebelumnya menggunakan perbandingan ketat `x.id === currentDashId` (rawan gagal bila ID berupa angka dan string) serta menutup modal tanpa memberikan konfirmasi visual apakah request `saveConfigToServer` berhasil atau mengalami kegagalan.
  - **Perbaikan yang Diterapkan:**
    1. **Pembersihan Parameter Statis dari URL (*URL Query Decontamination*):** Mengeliminasi injeksi parameter statis dashboard (`warn`, `crit`, `fs`, `show_units`, `per_page`, `enabled_units`, `enabled_categories`) dari `updateURLState()` dan `copyShareLink()`. Secara eksplisit menghapus parameter-parameter tersebut dari URL iframe maupun parent window sehingga reload tidak memicu override loops.
    2. **Sinkronisasi Form Modal (*Modal Form Synchronization*):** Memperbarui `openSettingsModal()` agar membaca pengaturan aktif dashboard dari `d.settings` dan `localStorage` sebelum modal dibuka, mengisi seluruh input (`f_warn`, `f_crit`, `f_fontsize`, `f_perpage`, `f_showunits`), serta menyinkronkan status checkbox unit (`syncEnabledUnitsUI`) dan kategori (`syncEnabledCategoriesUI`).
    3. **Kanonisitas Konfigurasi Server (*Server Settings as Source of Truth*):** Pada `openDashboard()`, menjadikan konfigurasi server `dSettings` sebagai sumber utama kebenaran (*canonical source of truth*) yang dipadukan dengan `localStorage` (`saved`) dan nilai default, serta memanggil `syncEnabledCategoriesUI()` yang sebelumnya terlewat.
    4. **Ketahanan Identifikasi & Notifikasi Sukses (*Safe Lookup & User Feedback*):** Menyelaraskan pencarian dashboard dengan `String(x.id) === String(currentDashId)` dan memberikan umpan balik visual instan (`alert("Dashboard settings saved successfully!")`) ketika konfigurasi berhasil disimpan ke file server JSON.
    5. **Ketahanan Validasi CSRF Backend:** Menambahkan fallback pembacaan header via `getallheaders()` pada endpoint `api=save_config` di [traffic-dashboard.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/Dashboard/Traffic-Dashboard/traffic-dashboard.php).

## [2.11.22] - 2026-10-26 (Traffic Dashboard - Fix Blank Sort Dropdown & Ensure Setting Persistence Across Refresh/Embed)
### Fixed & Enhanced
- **Perbaikan Dropdown Urutan Kosong (*Blank Sort Dropdown*) & Ketahanan Pengaturan (*State Persistence*):**
  - Mengatasi masalah pada menu **Traffic Dashboard** di mana kolom pemilihan urutan (*sorting dropdown*) yang seharusnya secara default terisi `Default` justru tampil kosong (*blank*). Ketika diubah ke pilihan lain atau ke default, lalu di-refresh (baik refresh manual, auto-refresh countdown, maupun saat di-embed di iframe Pandora Console), dropdown selalu kembali kosong seolah perubahan tidak tersimpan.
  - **Akar Penyebab (*Root Cause*):**
    1. **Ketiadaan Atribut `selected` & Opsi Kosong pada DOM:** Elemen `<select id="f_sort">` pada toolbar tidak memiliki atribut `selected` pada opsi `<option value="default">`. Karena tidak ada opsi dengan `value=""`, penugasan string kosong menyebabkan browser menyetel `selectedIndex = -1` (tampilan dropdown menjadi kosong).
    2. **Nullish Coalescing Trap (`??`) terhadap String Kosong:** Pada fungsi `openDashboard()`, konfigurasi dibaca dengan pola `dSettings.sort ?? 'default'`. Dalam JavaScript, `"" ?? 'default'` mengevaluasi ke string kosong `""`. Begitu file JSON server atau URL memuat `sort=""`, dropdown diatur dengan `f_sort.value = ""` yang memicu `selectedIndex = -1` secara permanen.
    3. **Ketiadaan Sinkronisasi URL & LocalStorage di `onFilterChange()`:** Fungsi `onFilterChange()` sebelumnya tidak memanggil `updateURLState()` dan tidak segera menyimpan state ke `localStorage`. Ketika dashboard di-refresh atau di-embed di dalam iframe Pandora FMS, halaman memuat ulang URL iframe asal tanpa query parameter `sort` yang baru, dan jika user bukan administrator Pandora, penyimpanan server (`save_config`) ditolak (403) sehingga perubahan hilang saat refresh.
  - **Perbaikan yang Diterapkan:**
    1. **Toolbar HTML:** Menambahkan atribut `selected` pada `<option value="default" selected>Default</option>` di [traffic-dashboard.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/Dashboard/Traffic-Dashboard/traffic-dashboard.php).
    2. **Fungsi Normalisasi (*Whitelist Normalization*):** Menambahkan fungsi pembantu `normalizeSort()` dan `normalizeSpeedFilter()` serta menyempurnakan `normalizeUnit()` untuk menjamin nilai yang dikembalikan selalu valid dan memfallback ke `'default'` / `'Auto'` / `'all'` bila kosong atau tidak valid.
    3. **Hierarki Pemuatan Pengaturan (*Settings Loading Hierarchy*):** Memperbarui `openDashboard()` dengan urutan prioritas: URL Params -> LocalStorage (`saved`) -> Server Config (`dSettings`) -> Default (`'default'`), serta menambahkan pengecekan `selectedIndex < 0`.
    4. **Sinkronisasi Otomatis (*Immediate URL & LocalStorage Persistence*):** Memperbarui `onFilterChange()` agar selalu menyimpan pengaturan terbaru ke `localStorage` secara instan, memanggil `updateURLState()` untuk menyinkronkan URL browser maupun window parent (iframe embed), serta mendukung parameter `silent = true` pada `saveConfigToServer()` agar pengguna non-admin tidak terganggu oleh dialog otorisasi.
    5. **Sanitasi Backend (*PHP Server Config & API Data Sanitization*):** Menambahkan sanitasi pada endpoint `load_config`, `save_config`, dan `api=data` di PHP sehingga file konfigurasi JSON di server maupun request data selalu dinormalisasi dan tidak pernah menyimpan nilai string kosong.

## [2.11.21] - 2026-10-26 (SNMP Explorer - Fix Default Speed for Not Connected Interfaces to 0 Mbps with Red Styling)
### Fixed & Enhanced
- **Penetapan Kecepatan Default 0 Mbps pada Port yang Tidak Terhubung (*Disconnected / Not Connected Interfaces*):**
  - Mengatasi masalah di mana interface yang tidak terhubung (*operStatus* = 2 / *down*) pada switch (seperti Cisco Catalyst) secara keliru menampilkan kecepatan `10 Mbps` di Sensor Inventory SNMP Explorer.
  - **Akar Penyebab (*Root Cause*):** Firmware switch Cisco secara bawaan mengembalikan nilai `10` pada OID `ifHighSpeed` (`1.3.6.1.2.1.31.1.1.1.15`) atau `10,000,000` pada `ifSpeed` (`1.3.6.1.2.1.2.2.1.5`) saat port GigabitEthernet tidak tertancap kabel (*unlinked / not connected*) sebagai fallback baseline 10BASE-T PHY state standar IEEE 802.3.
  - **Perbaikan yang Diterapkan:**
    1. **Modul Penemuan Mesin (*Discovery Modules*):**
       - [InterfaceDiscoveryModule.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/tools/snmp-explorer/engine/DiscoveryModules/InterfaceDiscoveryModule.php): Memeriksa status operasional port (`operStatus`). Jika port tidak terhubung (`operStatus !== 1`), kecepatan disetel ke nilai default `0 Mbps` dengan status sensor `'down'`.
       - [InterfaceSpeedModule.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/tools/snmp-explorer/engine/DiscoveryModules/InterfaceSpeedModule.php): Melakukan walk OID `ifOperStatus` (`1.3.6.1.2.1.2.2.1.8`) dan menetapkan nilai default `0 Mbps` dengan status `'down'` saat port tidak terhubung.
       - [SpeedDetector.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/tools/snmp-explorer/engine/Core/Normalize/SpeedDetector.php): Mendeteksi `operStatus` secara otomatis; jika port *down*, langsung mengembalikan `0 Mbps` dengan `connected = false`.
       - [CiscoInterfaceDiscoveryModule.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/tools/snmp-explorer/engine/DiscoveryModules/CiscoInterfaceDiscoveryModule.php) & [HuaweiInterfaceDiscoveryModule.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/tools/snmp-explorer/engine/DiscoveryModules/HuaweiInterfaceDiscoveryModule.php): Menyerahkan `ifStatus` ke `SpeedDetector` dan menetapkan status sensor ke `'down'` serta display `'0 bps'` bila port tidak terhubung.
       - [DiscoveryPipeline.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/tools/snmp-explorer/engine/Core/Discovery/DiscoveryPipeline.php): Menambahkan rekonsiliasi akhir lintas vendor (`reconcileInterfaceStatuses`) agar seluruh modul penemu sensor mematok kecepatan `0 Mbps` jika status operasional port tidak UP.
    2. **Auto-Repair Basis Data (*Database Migration & Self-Healing*):**
       - Menambahkan migrasi otomatis pada endpoint `get_inventory` dan repository [SensorInventoryRepository.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/tools/snmp-explorer/engine/Repository/SensorInventoryRepository.php) di [snmp-explorer.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/tools/snmp-explorer/snmp-explorer.php) untuk memperbarui baris inventaris lama di tabel `sensor_inventory` yang tersimpan `10 Mbps` saat port *down* menjadi `0 Mbps` dengan status `'down'`.
    3. **Penyelarasan Tampilan Visual Antarmuka (*UI Red Color & Badge Indicator*):**
       - Menampilkan nilai kecepatan port yang tidak terhubung dengan warna **merah** (`#ef4444`) dan badge penanda `[Not Connected]` berwarna merah muda (`#fee2e2` / `#b91c1c`) di tabel Sensor Inventory, modal Quick Scan, tabel Provisioned Modules, dan antrian Selected Sensors.

## [2.11.20] - 2026-10-26 (Dashboard - Fix False NOT INIT Blocks in 24h Status Timeline & Add Batch Baseline Lookback)
### Fixed & Enhanced
- **Pembersihan Blok Biru Palsu (*False NOT INIT / No Data Recorded*) pada Status Timeline 24 Jam:**
  - Mengatasi masalah di mana saat pengguna memilih rentang waktu *Last 24 Hours* pada kartu bergaya *Status Timeline* (*Uptime Blocks*), balok-balok awal (misal 4 jam pertama, atau bahkan hampir seluruh 24 jam) ditampilkan berwarna biru (**NOT INIT** / *No data recorded*) padahal modul berstatus normal (**UP**) sepanjang waktu.
  - **Akar Penyebab (*Root Cause*):**
    1. **Ketiadaan Baseline Lookback pada Batch Query:** Fungsi `get_modules_history_data_batch` di `includes/db-connection.php` sebelumnya hanya mengambil data dengan kriteria `utimestamp BETWEEN $start AND $end`. Modul yang tidak mengalami perubahan status sebelum jendela 24 jam tidak memiliki titik data sama sekali di batas awal `$start`.
    2. **Logika Fallback Frontend Terjebak:** Ketika stempel waktu kontak terkini (`is_latest`) disuntikkan di akhir timeline, array riwayat memiliki panjang 1 (`sortedHist.length === 1`). Untuk balok waktu sebelumnya yang belum memiliki riwayat, kondisi `priorPoints.length === 0` dan `sortedHist.length === 0` menjadi tidak terpenuhi sehingga sistem secara keliru menandai balok sebagai `blockStatus = 4` (NOT INIT / *No data recorded*).
  - **Perbaikan yang Diterapkan:**
    1. **Baseline Lookback Query Batch:** Menambahkan subquery lookback pada `get_modules_history_data_batch` di [includes/db-connection.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/includes/db-connection.php) untuk mengambil status terakhir sebelum `$start` dan mematoknya di batas awal `$start`.
    2. **Steady State Preservation:** Memperbaiki logika evaluasi balok di [metrics-dashboard.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/Dashboard/Metrics-Dashboard/metrics-dashboard.php) dan [dynamic-dashboard-template.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/Dashboard/Dynamic-Dashboard/dynamic-dashboard-template.php) sehingga modul yang tidak memiliki transisi status di jendela riwayat tetap mempertahankan status terverifikasi (`curEval.status` / `Steady state continues`) dan tidak pernah lagi berubah menjadi biru (NOT INIT).

## [2.11.19] - 2026-10-26 (Dashboard - Real-Time Timestamp in Uptime Blocks, UI English Standardization & RULES.md)
### Fixed & Enhanced
- **Sinkronisasi Waktu Riil pada Balok Status Uptime Timeline (*Latest Contact Timestamp Fix*):**
  - Mengatasi masalah pada visualisasi kartu gaya *Status Timeline* (Uptime Blocks) di mana saat kursor diarahkan (*hover*) pada balok waktu terbaru (misal jam 13:12 hari ini), stempel waktu data menampilkan timestamp dari event perubahan status lama (misal kemarin `2026-10-25 19:05:37`) dengan catatan `(Status stabil berlanjut)`.
  - **Akar Penyebab (*Root Cause*):** Modul boolean seperti `Host Alive` pada Pandora FMS hanya mencatat baris baru ke tabel riwayat `tagente_datos` jika terjadi perubahan nilai/status (misal status UP ke DOWN). Ketika host terus-menerus UP secara stabil selama berjam-jam atau berhari-hari, array riwayat data historis tidak memiliki baris baru di dalam rentang interval balok terkini. Akibatnya, visualizer jatuh ke *fallback point* event terakhir dari hari sebelumnya.
  - **Perbaikan yang Diterapkan:**
    1. Mengintegrasikan stempel waktu verifikasi kontak berkala real-time dari daemon Pandora FMS (`m.last_contact` / `tagente_estado.utimestamp`) ke dalam array titik data `sortedHist`.
    2. Pada balok rentang waktu paling akhir (`b === numBlocks - 1`), sistem kini memverifikasi kontak aktif modul dan secara akurat menampilkan stempel waktu pemeriksaan terbaru (`Data Timestamp`) dengan penanda status terkini (`Current status verified`).
    3. Diterapkan secara sinkron pada [metrics-dashboard.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/Dashboard/Metrics-Dashboard/metrics-dashboard.php) dan [dynamic-dashboard-template.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/Dashboard/Dynamic-Dashboard/dynamic-dashboard-template.php).
- **Standarisasi Bahasa Antarmuka Pengguna (UI Language Standardization - English Only):**
  - Menghapus seluruh teks dan petunjuk berbahasa Indonesia yang tersisa pada antarmuka pengguna dashboard, menyelaraskan seluruh elemen visual ke dalam Bahasa Inggris secara profesional dan konsisten:
    - Tooltip hover Uptime Blocks: `Period:`, `Agent Name:`, `Module Name:`, `Module Status:`, `Module Value:`, `Data Timestamp:`.
    - Status data notes: `Recorded event`, `Steady state continues`, `Current status verified`, `No data recorded`.
    - Modal builder controls & helper texts: `Visible Stat Cards`, `Newest Timestamp (DESC)`, `Heatmap Box Size`, `Chart circular radius percentage`, dll.
- **Penerbitan Dokumen Standar Repositori `RULES.md`:**
  - Menambahkan file [RULES.md](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/RULES.md) di direktori utama repositori sebagai panduan baku pengembangan, mencakup kewajiban Bahasa Inggris pada seluruh teks UI, alur penambahan fitur, pencatatan di `CHANGELOG.md`, integritas data waktu real-time, serta hierarki pemilihan agen.

## [2.11.18] - 2026-10-26 (Dashboard - Fix Multi-Agent Selection & Time-Series History Truncation in Line/Area Charts)
### Fixed & Enhanced
- **Perbaikan Pemilihan Agen Manual & Pengabaian Daftar Agen (*Agent Override Bug Fix*):**
  - Mengatasi bug pada **Metrics Dashboard** di mana saat pengguna memilih Filter Group (misalnya "Network") lalu mencari dan memilih agen secara manual atau mengklik *Select All*, sistem justru mengabaikan agen-agen yang dipilih dan hanya mengambil data dari seluruh grup.
  - **Akar Penyebab (*Root Cause*):** Logika evaluasi di backend `metrics-dashboard.php` mengevaluasi kondisi `if ($groupParsed['id'] > 0)` lebih dahulu dan mengosongkan array `$target_nodes['agent_ids'] = []`, sehingga parameter `manual_ids` dibuang dan query SQL jatuh ke fallback seluruh grup (`WHERE a.id_grupo IN (...)`).
  - **Perbaikan yang Diterapkan:** Memprioritaskan pemeriksaan `!empty($manualIdsParsed)` sebelum pengecekan grup di endpoint `card_data`, `module_list`, dan `status_details` pada [metrics-dashboard.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/Dashboard/Metrics-Dashboard/metrics-dashboard.php), [availability-node-modules.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/Dashboard/Metrics-Dashboard/availability-node-modules.php), dan [optical-power-metrics.php](file:///c:/Users/yushan/Documents/clone-repo/PFMS-toolkit/Dashboard/Metrics-Dashboard/optical-power-metrics.php).

- **Penghapusan Pembatasan Paksa 25 Riwayat Metrik pada Line Chart & Area Chart:**
  - Mengatasi masalah di mana diagram garis/area hanya menampilkan satu perangkat atau beberapa metrik saja padahal banyak agen yang dipilih.
  - **Akar Penyebab (*Root Cause*):** Backend membatasi riwayat ke batas default hardcoded 25 saat `chart_limit` bernilai 0 (*Show All*). Karena metrik diurutkan secara alfabetis berdasarkan alias agen, switch pertama (misalnya `ASW01` dengan 24 port) menghabiskan seluruh kuota 25 slot riwayat, sehingga perangkat lain tidak mendapatkan kuota time-series riwayat sama sekali.
  - **Perbaikan yang Diterapkan:**
    1. Mengubah backend agar saat `chart_limit === 0` (*Show All*), batas riwayat mencakup seluruh metrik yang cocok (`count($tableData)`).
    2. Menghapus pemotongan fallback `data.slice(0, 25)` pada frontend `renderWidgetChart`.
    3. Membuka kontrol **Max Chart Series Limit** di modal builder untuk Line Chart, Area Chart, dan Bar Chart (*Show All (Default)*, *Top 5*, *Top 10*, *Top 25*, *Top 50*, *Top 100*).

- **Optimalisasi Tombol `[ Select/Clear All ]` pada Builder Modal:**
  - Mengubah logika `toggleBuilderAgentAll()` agar memperbarui state centang dan array `selectedIds` secara massal dalam satu siklus, lalu hanya memicu 1 kali pembaruan jumlah pilihan (`sel_count`) dan daftar modul ke server (menghilangkan *network request spam*).

## [2.11.17] - 2026-10-14 (Dashboard - Sticky Header & Footer on Modal Dialogs)
### Fixed & Enhanced
- **Pemisahan Header & Footer Tetap (*Sticky / Fixed Header & Footer*) pada Modal Dialog:**
  - Mengatasi masalah pada menu **Metrics Dashboard** di mana judul modal, ikon silang penutup (**`close` / `x`**), serta tombol aksi (**`Cancel`** dan **`Save Widget`** / **`Apply Widget`**) ikut tergulung (*scrolling*) saat pengguna menavigasi form konfigurasi widget yang panjang.
  - **Akar Penyebab (*Root Cause*):**
    Sebelumnya, kontainer modal (`.modal-box`) menerapkan aturan `padding: 25px; max-height: 90vh; overflow-y: auto;` pada elemen pembungkus terluar. Seluruh elemen—termasuk header, beragam form isian (preview tipe, filter grup, daftar agen, pengaturan heatmap, opsi diagram), dan tombol footer—diletakkan dalam satu ruang gulung yang sama. Akibatnya, saat menggulir ke bawah, header dan tombol `close` lenyap ke atas, sementara tombol simpan tersembunyi jauh di bagian paling dasar.
  - **Perbaikan yang Diterapkan:**
    1. Memperkenalkan sistem tata letak terstruktur `.modal-box-structured` berbasis Flexbox (`display: flex; flex-direction: column; overflow: hidden;`):
       - **Fixed Header (`.modal-header-custom`):** Dipatok di bagian paling atas dengan batas bawah tipis (`border-bottom`). Judul modal dan tombol `close` tetap terlihat dan dapat diklik kapan saja tanpa tergulung.
       - **Scrollable Body (`.modal-body-scroll`):** Kontainer khusus dengan `flex: 1; overflow-y: auto; min-height: 0;` yang menampung seluruh formulir dan kontrol builder secara independen.
       - **Fixed Footer (`.modal-footer-custom`):** Dipatok di bagian paling bawah dengan batas atas tipis (`border-top`). Tombol `Cancel` dan `Save Widget` selalu siap dijangkau tanpa harus menggulir sampai ujung halaman.
    2. Menambahkan reset scroll otomatis (`scrollTop = 0`) saat modal dibuka (`openBuilder()` dan `openEdit()`) agar posisi form selalu dimulai rapi dari atas.
    3. Menerapkan pola konsisten ini pada modal **Build Widget** (`#builderModal`), **Export Data** (`#exportModal`), **Dashboard Settings** (`#dashMetaModal`), dan **Share Widget** (`#shareWidgetModal`) pada [metrics-dashboard.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/Dashboard/Metrics-Dashboard/metrics-dashboard.php), [availability-node-modules.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/Dashboard/Metrics-Dashboard/availability-node-modules.php), dan [optical-power-metrics.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/Dashboard/Metrics-Dashboard/optical-power-metrics.php).

## [2.11.16] - 2026-10-14 (Dashboard - Fix False Critical Status on Traffic Uploads / Downloads)
### Fixed
- **Pembersihan Status Palsu (*False Critical Status*) pada Modul Trafik Jaringan:**
  - Mengatasi masalah di mana seluruh baris metrik trafik antarmuka (misalnya `Mpo50_ifHCOutOctets`, `Eth1/4_ifInOctets`, `Po1_ifHCInOctets`, dll.) pada widget Sparkline Table ("Traffic Uploads" dan "Traffic Downloads") menampilkan titik status merah (**Critical**) padahal trafik berjalan normal dalam batas wajar (ratusan Mbps).
  - **Akar Penyebab (*Root Cause*):**
    Fungsi `evaluateModuleThresholdStatus` memiliki evaluasi heuristik persentase cadangan (`isPct`) yang memeriksa substring kata kunci (`/cpu|usage|load|mem|disk|utilization|loss|drop/`). Ketika kartu diberi judul `"Traffic Uploads"` atau `"Traffic Downloads"`, regex mencocokkan potongan kata `"load"` yang terkandung di dalam kata `"Uploads"` dan `"Downloads"`. Akibatnya, modul trafik antarmuka disalahartikan sebagai metrik persentase beban sistem (0–100%). Karena nilai laju trafik (dalam Mbps atau bytes/s) jauh melampaui ambang batas 90 (misalnya $618\text{ Mbps} \ge 90$), sistem secara keliru menandai seluruh baris sebagai **Critical** (`status: 1`).
  - **Perbaikan yang Diterapkan:**
    1. Menambahkan pengkondisian `!isTraffic`: metrik laju trafik jaringan (`isTrafficMetric`) tidak pernah dievaluasi menggunakan heuristik persentase beban (0–100%).
    2. Membersihkan string konteks dengan mengecualikan kata `upload` dan `download` agar tidak lagi memicu pencocokan parsial substring `load`.
    3. Status modul trafik kini mematuhi status asli daemon Pandora FMS (`tagente_estado.estado` di mana 0 = Normal/OK warna hijau `#10b981`), atau nilai ambang batas eksplisit jika memang dikonfigurasi di database.
  - Diterapkan secara konsisten pada [metrics-dashboard.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/Dashboard/Metrics-Dashboard/metrics-dashboard.php) dan [dynamic-dashboard-template.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/Dashboard/Dynamic-Dashboard/dynamic-dashboard-template.php).

## [2.11.15] - 2026-10-14 (Dashboard - Optimal Pie/Donut Label Clearance & Pointer Separation)
### Fixed & Enhanced
- **Pencegahan Label Menabrak / Terlalu Dekat dengan Lingkaran Diagram (*Label-to-Chart Clearance*):**
  - Mengatasi masalah di mana label status (khususnya teks `UP` dan `1 (100%)` pada lingkaran *single-status*, serta slice trafik pada *multi-status*) menempel, menyentuh, atau bertabrakan langsung dengan garis tepi lingkaran diagram (*pie/donut circumference*).
  - Akar penyebab: Panjang segmen pertama garis penunjuk radial (`labelLine.length`) sebelumnya terlalu pendek (`6px`–`8px`), sementara teks label bertumpuk 2 baris (`UP\n1 (100%)`) dengan tinggi teks ~26px–28px yang terpusat secara vertikal di ujung siku horizontal. Akibatnya, baris atas teks terdorong ke atas dan menabrak lingkaran luar diagram.
  - Meningkatkan panjang radial `labelLine.length` menjadi **`14px`–`18px`** (dan `length2` menjadi **`10px`–`14px`**, `distanceToLabelLine: 4px–5px`) sehingga label selalu memiliki jarak aman (*breathing room*) minimal 10px–15px dari batas diagram.
- **Penyesuaian Pusat (*Center Y*) dan Radius Proporsional:**
  - Untuk *single-status* (100% UP), posisi pusat lingkaran disesuaikan ke `center: ['50%', '40%']` dengan radius adaptif `56%`–`68%`. Hal ini memberikan ruang vertikal yang lega di bagian bawah kartu untuk garis penunjuk dan dua baris label status tanpa menyentuh batas bawah kartu.
  - Untuk *multi-status*, posisi pusat disesuaikan ke `['50%', '47%']` dengan radius `46%`–`60%`, memastikan label kiri dan kanan memiliki ruang horizontal yang cukup tanpa terpotong (*truncated*) oleh batas samping tile.
  - Diterapkan secara seragam di **Metrics Dashboard** ([metrics-dashboard.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/Dashboard/Metrics-Dashboard/metrics-dashboard.php)) dan **Dynamic Dashboard** ([dynamic-dashboard-template.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/Dashboard/Dynamic-Dashboard/dynamic-dashboard-template.php)).

## [2.11.14] - 2026-10-14 (SNMP Explorer - Standardize Octet Units to bytes/s & Enforce 64-bit HC Counter Priority)
### Fixed & Enhanced
- **Standardisasi Satuan Counter Octet Trafik Menjadi `bytes/s`:**
  - Memperbaiki satuan counter trafik antarmuka jaringan (`ifHCInOctets`, `ifHCOutOctets`, `ifInOctets`, `ifOutOctets`) dari sebelumnya bertuliskan `bytes` menjadi `bytes/s` secara konsisten di seluruh modul discovery ([InterfaceStatsDiscoveryModule.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/engine/DiscoveryModules/InterfaceStatsDiscoveryModule.php), [F5InterfaceDiscoveryModule.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/engine/DiscoveryModules/F5InterfaceDiscoveryModule.php), [CiscoInterfaceDiscoveryModule.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/engine/DiscoveryModules/CiscoInterfaceDiscoveryModule.php), [HuaweiInterfaceDiscoveryModule.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/engine/DiscoveryModules/HuaweiInterfaceDiscoveryModule.php), [LoadedMibDiscoveryModule.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/engine/DiscoveryModules/LoadedMibDiscoveryModule.php)) dan katalog OID ([snmp-explorer.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/snmp-explorer.php), [OidTranslator.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/engine/Core/Snmp/OidTranslator.php)).
  - Modul Pandora FMS bertipe *Remote SNMP incremental data* (`id_tipo_modulo = 16`) mengukur laju delta per detik (`(counter_n - counter_n-1) / delta_t`), sehingga nilai riil yang tersimpan di database adalah Bytes per detik (**Bytes/s**). Mengubah label satuan menjadi `bytes/s` mencerminkan sifat data riil dengan akurat dan menghilangkan ambiguitas (misalnya nilai 54.554.922,5 bytes/s × 8 ÷ 1.000.000 = 436,44 Mbps).
  - Memperbarui normalizer satuan ([UnitNormalizer.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/engine/Core/Normalize/UnitNormalizer.php), [SnmpNamingService.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/engine/Services/SnmpNamingService.php), [OidTranslator.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/engine/Core/Snmp/OidTranslator.php)) agar mendeteksi dan mempertahankan `bytes/s`, `byte/s`, dan `b/s` sebelum mengevaluasi aturan umum `byte`.
- **Prioritas Utama Counter 64-bit (`Counter64`) pada Antarmuka Jaringan Cepat:**
  - Memastikan dan menegakkan prioritas counter 64-bit (`ifHCInOctets` OID `.1.3.6.1.2.1.31.1.1.1.6` untuk inbound dan `ifHCOutOctets` OID `.1.3.6.1.2.1.31.1.1.1.10` untuk outbound) pada antarmuka throughput tinggi (seperti link 10G/25G/40G/100G) guna mencegah risiko *counter rollover* (siklus reset counter 32-bit `Counter32` yang rollover dalam hitungan detik/menit pada bandwidth tinggi).
  - Memperbaiki OID assignment pada [F5InterfaceDiscoveryModule.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/engine/DiscoveryModules/F5InterfaceDiscoveryModule.php), [CiscoInterfaceDiscoveryModule.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/engine/DiscoveryModules/CiscoInterfaceDiscoveryModule.php), dan [HuaweiInterfaceDiscoveryModule.php](file:///c:/Users/yusha/Documents/HOME_DATA/HONET/github-repo/PFMS-toolkit/tools/snmp-explorer/engine/DiscoveryModules/HuaweiInterfaceDiscoveryModule.php) agar secara aktif memprioritaskan counter 64-bit HC octets dengan fallback aman ke counter 32-bit hanya jika counter 64-bit tidak didukung atau kosong pada perangkat lama.

## [2.11.13] - 2026-10-14 (Dashboard - Eliminate Excessive Margins & Compact Pie/Donut Layout Optimization)
### Fixed & Enhanced
- **Pembersihan dan Pemadatan Margin/Padding Berlebih pada Diagram Pie & Donut:**
  - Mengatasi masalah ruang kosong (*dead whitespace* / margin & padding kanan, atas, bawah, kiri) yang terlalu lebar pada widget Pie Chart dan Donut Chart (terutama saat ditampilkan dalam tile kompak atau di-embed di Visual Console Pandora FMS).
  - Mengurangi padding kontainer kartu (`.dashboard-card-body`, `.panel-body`) khusus untuk tipe Pie & Donut dari `10px` / `8px` menjadi `2px 4px 4px 4px` agar ruang kanvas diagram terpakai maksimal tanpa margin kosong yang membuang tempat.
  - Memperbaiki padding halaman standalone (`.main-content`) di mode embed/iframe pada Dynamic Dashboard (`dynamic-dashboard-template.php`) yang sebelumnya bernilai statis `20px 25px`, dipadatkan menjadi `4px 6px` (atau `0px` saat `hide_header=1`).
- **Skala Radius Adaptif Cerdas Menghilangkan Ruang Kosong Sisi Luar Lingkaran:**
  - Mengatasi lingkaran diagram yang tampak kecil dan terisolasi di tengah kotak kosong:
    - **Single Status (100% UP / satu status saja):** Lingkaran kini otomatis membesar hingga radius **`72%` - `78%`** dengan pusat `center: ['50%', '46%']` karena tidak memerlukan ruang label kiri/kanan, sehingga lingkaran diagram mengisi kotak secara proporsional dan elegan dengan label tunggal di bawah.
    - **Multi Status (misal UP + Critical):** Radius ditingkatkan dari `48%`/`52%` menjadi **`62%` - `70%`**.
- **Penyesuaian Garis Penunjuk (*Label Line*) Mencegah Pemotongan Teks (*Truncated Label*):**
  - Mengatasi label status yang terpotong menjadi `Criti...` / `UP 3 (7...`: pada container berukuran sempit (< 260px), panjang garis penunjuk (`labelLine.length` & `length2`) dipadatkan menjadi `8px` (dari sebelumnya 16px + 10px = 26px) serta jarak teks `distanceToLabelLine: 2`. Pengurangan 18px horizontal ini memberikan ruang yang cukup bagi teks label seperti "Critical (25%)" untuk tampil utuh tanpa terpotong oleh batas kanvas.
  - Diimplementasikan secara konsisten pada **Metrics Dashboard** (`metrics-dashboard.php`) dan **Dynamic Dashboard** (`dynamic-dashboard-template.php`).

## [2.11.12] - 2026-10-14 (Dashboard - Status Timeline Block Period Window & Contextual Timestamp Tooltip)
### Fixed & Enhanced
- **Resolusi Periode Waktu Unik per Balok Status Timeline (`Periode Blok: [bStart] – [bEnd]`):**
  - Mengatasi kebingungan di mana setiap kotak/balok pada Status Timeline (Uptime Blocks) menampilkan timestamp yang sama persis saat di-hover (misalnya `2026-10-13 17:58:14`) ketika time range diubah ke rentang yang lebih panjang (seperti Last 7 Days / Last 30 Days).
  - Akar penyebab: Tiap baris timeline dibagi rata ke dalam 30 balok waktu (*time buckets*). Ketika sebuah sensor/modul berstatus normal/stabil tanpa perubahan status selama berhari-hari, Pandora FMS tidak mencatat entri event baru di database pada setiap interval balok. Sistem sebelumnya mewarisi timestamp dari event status terakhir (`prior.ts`) sebagai atribut `data-ts` untuk seluruh balok berikutnya, sehingga tooltip menampilkan waktu identik pada banyak balok berturut-turut.
  - Memperbaiki penentuan rentang waktu dengan menambahkan fungsi `formatBlockPeriod`: setiap balok kini menampilkan rentang waktu jendelanya sendiri secara unik dan kronologis (`Periode: DD/MM/YYYY HH:MM – HH:MM` atau `DD/MM HH:MM – DD/MM HH:MM`).
- **Penyempurnaan Tooltip Status Timeline dengan Informasi Kontekstual yang Jelas:**
  - Header tooltip kini menampilkan ikon kalender dan **rentang periode waktu aktif balok** (`Periode: 13/10 12:20 – 17:58`).
  - Menambahkan baris detail **"Waktu Data:"** yang menampilkan waktu pencatatan data riil dari database (`2026-10-13 17:58:14`) disertai keterangan status yang transparan:
    - `(Data Event Tercatat)`: bila ada titik log data riil yang terekam persis di dalam jendela balok tersebut.
    - `(Status stabil berlanjut)`: bila status sensor berlanjut stabil tanpa perubahan status dari titik pembacaan sebelumnya.
    - `(Status data saat ini)`: bila menggunakan pembacaan kontak terakhir sensor.
    - `(Belum ada data)`: bila modul belum diinisialisasi atau data belum tersedia pada rentang waktu tersebut.
  - Diimplementasikan secara konsisten di **Metrics Dashboard** (`metrics-dashboard.php`) dan **Dynamic Dashboard** (`dynamic-dashboard-template.php`).

## [2.11.11] - 2026-10-14 (Dashboard - Custom Chart Font Size, Pie Radius Size & Embed Sync)
### Fixed & Added
- **Pemberlakuan Penuh Ukuran Font Kustom (`Chart Font Size`) pada Diagram Pie & Donut:**
  - Memperbaiki fungsi `getResponsivePieDonutOption` dan `getDynamicPieOption` yang sebelumnya menimpa (*hardcode*) ukuran font label diagram menjadi `10px` atau `9px` pada container berukuran kecil (< 320px seperti iframe Visual Console Pandora FMS), sehingga perubahan ukuran font yang diatur pengguna tidak berefek saat di-embed.
  - Nilai kustom pada field `Chart Font Size (px)` kini dipatuhi secara langsung baik di dashboard utama maupun tampilan embed. Panjang garis penunjuk label (*labelLine*) juga otomatis menyesuaikan clearance secara proporsional terhadap ukuran font.
- **Dukungan Pengaturan Ukuran Lingkaran Diagram Kustom ("Chart / Pie Size (%)"):**
  - Menghadirkan opsi baru **"Chart / Pie Size (% Radius)"** pada modal builder & edit widget di **Metrics Dashboard** (`metrics-dashboard.php`) dan **Dynamic Dashboard** (`dynamic-dashboard-template.php`).
  - Pengguna kini dapat mengatur sendiri persentase radius lingkaran diagram pie/donut (misal 40% untuk tampilan ringkas, atau 65% - 75% untuk lingkaran yang lebih besar dan dominan).
- **Sinkronisasi Tinggi Panel (`Panel Height`) pada Kode Embed Iframe:**
  - Memperbaiki generator kode embed share widget (`updateShareUrls`) yang sebelumnya selalu menetapkan atribut `height="280"` secara statis. Kini atribut tinggi iframe otomatis mengambil nilai `Panel Height (px)` kustom yang dikonfigurasi pada widget tersebut.

## [2.11.10] - 2026-10-14 (Dashboard - Fix Pie & Donut Chart Label Pointer Collision and Clearance)
### Fixed
- **Pembersihan dan Pemisahan Label Menempel pada Pie Chart & Donut Chart:**
  - Mengatasi masalah label teks (`UP 1 (100%)` / status data) yang menempel atau menabrak tepi lingkaran diagram pie/donut pada panel ringkas (seperti widget Visual Console Pandora FMS).
  - Memperbaiki akar masalah geometri ECharts di mana panjang segmen garis penunjuk awal (`length` / `line1`) terlalu pendek (4px - 6px) dan memiliki kurva melengkung (`smooth: 0.2`), sehingga total tinggi baris teks (*line height*) menarik teks mundur dan menabrak lingkaran.
  - Meningkatkan clearance garis penunjuk secara adaptif: `line1` diperpanjang menjadi `14px` - `20px` (minimal 16px untuk tile ringkas), `smooth` diatur ke `false` dengan sudut siku tegas (`minTurnAngle: 90`), serta menambahkan jarak renggang label `distanceToLabelLine: 4`.
  - Mengoptimalkan proporsi radius (`48%` - `52%` pada tile kompak) dan posisi pusat vertikal (`center: ['50%', '48%']`) untuk memberikan ruang bernapas (*breathing room*) yang lapang di sekeliling lingkaran.
  - Diterapkan secara konsisten di **Metrics Dashboard** (`metrics-dashboard.php`) dan **Dynamic Dashboard** (`dynamic-dashboard-template.php`).

## [2.11.9] - 2026-10-14 (Dashboard - Fully Responsive Embed & Sizing for Pie & Donut Charts)
### Fixed & Enhanced
- **Responsivitas Penuh Tampilan Embed & Sizing Pie Chart & Donut Chart:**
  - Mengatasi masalah tampilan Pie Chart dan Donut Chart yang tidak responsif saat di-embed di Visual Console Pandora FMS / iframe standalone (di mana diagram berukuran kaku, tidak otomatis membesar/mengecil saat elemen Visual Console di-resize, dan menyisakan banyak ruang kosong putih).
  - Memperbaiki `ResizeObserver` yang sebelumnya hanya terpasang pada line/area/bar chart dan terlewat pada pie/donut chart, sehingga kini kanvas pie dan donut chart secara aktif memantau perubahan ukuran container dan otomatis me-resize diagram secara instan (*live dynamic resize*).
  - Mengimplementasikan perhitungan radius dan garis label adaptif (`getResponsivePieDonutOption`): radius lingkaran menyesuaikan dimensi kotak (`54%` - `68%`) dan panjang garis label (*label line*) mengecil pada container sempit sehingga teks label tidak terpotong (*truncated*) atau berakhiran `Crit...` / `UP (...`.
  - Memperbaiki duplikasi nilai pada label pie: sebelumnya nilai kuantitas status muncul berulang (`Critical (1)\n1 (25%)`), kini dibersihkan menjadi format yang ringkas, elegan, dan proporsional (`Critical\n1 (25%)` atau `UP\n1 (100%)`).
  - Menghilangkan batasan tinggi kaku `min-height: 220px;` pada kanvas chart dan `height: calc(100% - 40px)` pada card body saat mode standalone/embed, menggantinya dengan `min-height: 0 !important; flex: 1 1 0% !important; height: 100% !important;` agar kanvas dapat menyesuaikan secara fleksibel ke ukuran kotak visual console mana pun (mulai dari kotak kecil 160px hingga kotak besar).
  - Mengoptimalkan padding card body untuk pie dan donut chart (`padding: 6px 8px 8px 8px;`) agar ruang visual diagram maksimal dan tidak terbuang oleh padding berlebih.
  - Menyelaraskan perbaikan responsivitas dan ResizeObserver ini di kedua modul: **Metrics Dashboard** (`metrics-dashboard.php`) dan **Dynamic Dashboard** (`dynamic-dashboard-template.php`).

## [2.11.8] - 2026-10-14 (Metrics Dashboard - Global Time Range & Auto Refresh HUD Toolbar Sync)
### Added & Enhanced
- **Sinkronisasi Global Time Range & Auto Refresh HUD pada Metrics Dashboard (Setara dengan Dynamic Dashboard):**
  - Menghadirkan toolbar **Global Time Range** dropdown pada header Metrics Dashboard (`Last 1h`, `Last 6h`, `Last 24h`, `Last 7d`, `Last 30d`, dan `Custom...` dengan rentang tanggal dan jam) yang memungkinkan pengguna mengubah rentang waktu untuk seluruh widget di dashboard secara serentak hanya dengan satu kali klik.
  - Menghadirkan **Global Refresh HUD** di toolbar atas Metrics Dashboard yang menampilkan status *Last Update: HH:MM:SS*, countdown interaktif *Auto Refresh: 1m*, tombol sinkronisasi instan *Force Refresh (`sync`)*, serta dropdown pilihan interval auto-refresh (*Off, 10s, 30s, 1m, 2m, 5m*).
  - Menyinkronkan status timer global dengan mekanisme per-widget: saat interval diatur ke *Off*, auto-refresh widget dihentikan, dan saat tombol refresh atau hitung mundur tercapai, seluruh widget melakukan fetch data secara serentak.
  - Nilai preferensi rentang waktu dan interval auto-refresh dashboard tersimpan secara otomatis dan persisten ke konfigurasi dashboard saat diubah.
  - Tetap mempertahankan kontrol per-widget (tombol ikon jam pada header masing-masing widget) untuk pengguna yang ingin mengesampingkan rentang waktu widget tertentu.

## [2.11.7] - 2026-10-14 (Dashboard - Responsive Embed & Standalone Sizing for Status Timeline Uptime Blocks)
### Fixed
- **Responsivitas Penuh Tampilan Embed & Standalone Visual Style Status Timeline (Uptime Blocks):**
  - Mengatasi masalah tampilan Status Timeline yang tidak responsif saat di-embed di Visual Console Pandora FMS / iframe standalone (di mana baris metrik tetap berukuran kaku dan meninggalkan ruang kosong putih besar / *dead gap* di bagian bawah saat container visual console di-resize meninggi).
  - Mengimplementasikan *dynamic flex distribution* (`flex: 1 1 0%`, `min-height: 46px`, `max-height: 85px`) pada setiap baris item Status Timeline ketika container memiliki tinggi kustom atau dalam mode standalone/embed (`IS_STANDALONE` / `card.height`), sehingga seluruh baris metrik terdistribusi secara seimbang dan proporsional mengisi tinggi visual console tanpa ruang kosong.
  - Memperbarui track timeline blocks (`timeline-blocks-track`) dan balok-balok status (`status-timeline-block`) dengan tinggi dinamis (`height: 100%`, `min-height: 16px`, `max-height: 30px`) yang secara otomatis menyesuaikan ketinggian alokasi baris.
  - Menghadirkan responsivitas horizontal cerdas pada baris timestamp (`timeline-labels-row`) menggunakan media query dan container query (`@container (max-width: 620px)` dan `@container (max-width: 380px)`): label timestamp menengah disembunyikan secara bertahap saat lebar panel menyempit untuk mencegah tabrakan atau teks bertumpuk.
  - Memperbaiki judul baris metrik dengan pembatasan lebar dan *ellipsis text* (`max-width: 55%`, `text-overflow: ellipsis`, `white-space: nowrap`) sehingga nama node yang panjang tidak lagi mendorong status dot atau nilai metrik ke luar layar pada ukuran panel sempit.
  - Menyelaraskan perbaikan ini di kedua modul: **Metrics Dashboard** (`metrics-dashboard.php`) dan **Dynamic Dashboard** (`dynamic-dashboard-template.php`).

## [2.11.6] - 2026-10-14 (Metrics Dashboard - Visual Type Library & Dynamic Dashboard Sync)
### Added & Fixed
- **Integrasi Visual Type Library & Interactive Preview Gallery (Sinkron dengan Dynamic Dashboard):**
  - Menambahkan kartu **Interactive Visual Type Preview** tepat di bawah dropdown Style View pada modal Edit/Build Widget yang menampilkan pratinjau mockup visual SVG dinamis, lencana kategori, deskripsi fungsi, dan rekomendasi (*Best For*) secara langsung (*live update*).
  - Menghadirkan modal **Visual Type Catalog & Preview / All Types Gallery** yang menampilkan galeri visual interaktif untuk seluruh 13 tipe visual lengkap dengan tab filter kategori (*All, Charts & Time-Series, Status & Real-Time Grids, Single Value & Timeline, Tables & Snapshots*) dan tombol aksi *"Select"* untuk memilih tipe visual langsung dari galeri katalog seperti di Dynamic Dashboard.
- **Sinkronisasi Visual Type & Opsi Show/Hide Status Cards pada Pie & Donut Chart:**
  - Menyelaraskan visual type pada Metrics Dashboard dengan Dynamic Dashboard, di mana style view **Pie Chart (Current Status)** dan **Donut Chart (Current Status)** secara *default* hanya menampilkan grafik diagram lingkaran tanpa baris kartu status di atasnya.
  - Menghadirkan opsi pemilihan visibilitas kartu status ("Show Status Cards (UP, Critical, etc.)") pada modal Edit/Build Widget ketika Style View yang dipilih adalah Pie Chart atau Donut Chart.
  - Memperbaiki bug persistensi unchecklist kartu status: sebelumnya, ketika seluruh kotak centang kartu status di-uncheck, sistem secara keliru menganggap konfigurasi kosong dan mereset kembali ke semua kartu status tercentang saat di-refresh. Kini unchecklist seluruh atau sebagian kartu status tersimpan secara persisten.
  - Jika seluruh kartu status di-uncheck atau opsi diset ke **Hide**, baris kartu indikator status disembunyikan sepenuhnya dan kanvas grafik pie/donut secara otomatis mengisi ruang card secara proporsional.
  - Menyelaraskan penamaan opsi Style View (`Table View (Current Status)`, `Heatmap View (Current Status)`, `Stats Cards (Current Status)`, `Pie Chart (Current Status)`, `Donut Chart (Current Status)`) agar sinkron dan konsisten dengan Dynamic Dashboard.

## [2.11.5] - 2026-10-13 (Explorer & Tools - Material Symbols Icon Font & Asset Resolution Fix)
### Fixed
- **Perbaikan Pemuatan Icon Material Symbols & Aset Vendor di Netflow Explorer & Tools:**
  - Memperbaiki kegagalan muat icon pada halaman Netflow Explorer dan sejumlah sub-modul tools di mana ligatur font tampil sebagai teks mentah (seperti `add_box`, `restart_alt`, `analytics`, `account_tree`, `list_alt`, `share`, `info`, `search`).
  - Mengubah referensi asset dari path statis yang mengasumsikan `/panel/vendor/` menjadi multi-layer resolution:
    1. **Direct Relative Path Fallback** (`../../vendor/fonts/fonts.css`, `../../vendor/bootstrap/...`, `../../vendor/chartjs/plotly-2.35.2.min.js`) yang dijamin selalu berhasil di lingkungan intranet / offline tanpa akses internet.
    2. **Dynamic Vendor Path Resolution** yang secara otomatis mendeteksi URL dasar instalasi Pandora Console dan direktori modul toolkit (`pfms-toolkit`).
    3. **Google Fonts CDN Fallback** non-blocking untuk performa maksimal di lingkungan dengan koneksi internet.
  - Menambahkan deklarasi lokal `@font-face` untuk font `Inter` dan `Material Symbols Outlined` langsung di `<style>` guna mencegah FOIT/FOUT.
  - Memperbaiki CSS `.material-symbols-outlined` dengan `text-transform: none !important;` agar teks judul bertransformasi huruf kapital (`text-transform: uppercase`) tidak merusak pencocokan ligatur icon case-sensitive (seperti `ACCOUNT_TREE` yang seharusnya `account_tree`).

## [2.11.4] - 2026-10-08 (Dashboard - Sparkline Table Dedicated Module Column & Clean Bottom Spacing)
### Fixed
- **Pemisahan Kolom Module Name & Agent pada Widget Sparkline Table Trend:**
  - Sebelumnya, nama modul dan nama/IP agen digabungkan ke dalam satu kolom "Name" (nama agen di atas dan nama modul di bawahnya).
  - Memisahkan keduanya menjadi kolom mandiri: kolom **Agent** (menampilkan IP dan nama/alias node) dan kolom **Module Name** (menampilkan nama modul metrik secara bersih dan dapat diklik untuk membuka modal detail modul).
  - Menambahkan dukungan pengurutan (sorting) independen untuk kolom **Agent** dan kolom **Module Name**.
- **Pembersihan Ruang Kosong (Dead Space) di Bagian Bawah Widget Sparkline Table:**
  - Sebelumnya, card widget Sparkline Table memaksakan `min-height` dan `flex: 1` berlebih yang menyebabkan area kosong putih yang sangat lebar di bawah tabel (terutama saat baris data sedikit, seperti 2 atau 4 baris).
  - Mengubah kalkulasi dimensi untuk widget tabel menjadi `height: auto; min-height: 0;` dengan padding atas dan bawah yang simetris (10px), sehingga card membungkus tabel secara presisi dan rapi tanpa menyisakan ruang kosong yang timpang.

## [2.11.3] - 2026-10-08 (SNMP Explorer - Module Group Resolution from Sensor Class & Human-Readable Module Descriptions)
### Fixed
- **Pemetaan Sensor Class ke Module Group Pandora FMS (`tmodule_group`):**
  - Sebelumnya, saat deploy/provisioning sensor ke agen Pandora FMS, nilai class sensor (seperti `environmental`, `interface`, `storage`, `system`) secara keliru dituliskan ke kolom `tagente_modulo.descripcion` ("Description"), sedangkan `tagente_modulo.id_module_group` ("Module group") bernilai `0` sehingga di console agen Pandora FMS tampil sebagai `Module group: None`.
  - Memperbaiki alur provisioning agar class sensor secara otomatis dipetakan ke grup modul Pandora FMS yang rapi (`Environmental`, `Networking`, `Storage`, `Memory`, `System`, `Optical`, `Printer`, `GPON`, `Camera`, `Routing`, `F5 BIG-IP`).
  - Menambahkan metode `resolveModuleGroupId()` pada `PandoraRepository` yang mencari ID grup di `tmodule_group` atau membuat grup baru secara otomatis jika belum ada di database Pandora FMS.
- **Deskripsi Modul Informatif & Manusiawi (Human-Readable):**
  - Kolom `tagente_modulo.descripcion` ("Description") kini diisi dengan nama sensor yang informatif dan deskriptif (misalnya `Chassis Temperature #1 (C)`, `Power Supply 1 - Voltage (V)`, atau detail interface `Eth1/1 - ifHCInOctets`), bukan lagi hanya teks nama class mentah (`environmental`).
- **Auto-Repair / Migrasi Retroaktif untuk Modul yang Sudah Terdeploy:**
  - Menambahkan fungsi `repairModuleGroupsAndDescriptions()` pada `PandoraRepository` yang secara otomatis mendeteksi dan memperbarui modul-modul yang sebelumnya terprovision dengan grup `None` (0) atau deskripsi berupa nama class mentah.
  - Perbaikan ini dieksekusi secara otomatis saat membuka daftar modul provisioned (`get_provisioned_sensors`), saat melakukan sinkronisasi (`sync_provisioned_status`), maupun saat provisioning modul baru (`provision`), sehingga pengguna tidak perlu menghapus atau me-redeploy manual modul yang sudah ada.

## [2.11.2] - 2026-10-08 (SNMP Explorer - Pandora Provisioning Active Modules Manager, Unprovision Action & Subtabs)
### Fixed
- **Inkonsistensi Tab Pandora Provisioning vs Header Stats:**
  - Memperbaiki kebingungan di mana header menampilkan jumlah sensor terprovision (misal `Provisioned: 14`), namun saat mengklik tab **Pandora Provisioning**, badge menampilkan `0` dan tabel antrean kosong (*No sensors currently selected*).
  - Badge tab **Pandora Provisioning** kini secara konsisten menampilkan total modul aktif yang sudah terprovision di Pandora FMS (misal `14`), selaras dengan badge inventori lainnya. Ketika ada sensor baru yang dicentang di antrean staging, pill penanda sekunder `+N queued` akan menyala.
- **Pembersihan Modul Agen Saat Unprovision:**
  - Tindakan unprovisioning kini secara otomatis membersihkan seluruh data relasi modul pada tabel Pandora FMS (`tagente_modulo`, `tagente_estado`, `tagente_datos`, `tagente_datos_inc`, `tagente_datos_string`), mendiskon `total_modules` pada agen terkait, dan me-reset status sensor di `sensor_inventory` kembali ke `Pending` (`provisioned = 0`).

### Added
- **Sub-Tampilan Active Provisioned Modules in Pandora FMS:**
  - Menambahkan sub-tampilan **Active Provisioned in Pandora** pada Tab Pandora Provisioning yang menampilkan daftar lengkap seluruh sensor yang telah aktif diprovision ke agen Pandora FMS.
  - Menampilkan informasi detail: IP & Versi SNMP, Nama Agen Pandora FMS, Nama Modul (`tagente_modulo.nombre`) beserta ID modul (`#ID`), Status Polling Modul (dot indikator Normal/Warning/Critical), Nama Sensor & Class, SNMP OID, Nilai Terakhir/Ternormalisasi, serta Tanggal Provisioning.
  - Menyediakan filter berdasarkan Agen Pandora FMS dan kotak pencarian real-time (search OID, nama modul, nama sensor, IP).
  - Menambahkan pagination controls dan total counter.
- **Tindakan Unprovision / Delete Modul dari Pandora FMS:**
  - Menambahkan tombol aksi **Unprovision** pada setiap baris modul untuk menghapus modul dari Pandora FMS dengan konfirmasi detail.
  - Menambahkan fitur **Unprovision Selected (Bulk)** untuk menghapus beberapa modul sekaligus secara batch.
- **Sinkronisasi Otomatis Database Toolkit & Pandora FMS:**
  - Menambahkan tombol dan API **Sync with Pandora** (`?api=sync_provisioned_status`) untuk melakukan rekonsiliasi dua arah antara tabel `sensor_inventory` dan `tagente_modulo` (memastikan modul eksternal atau modul yang dihapus langsung di GUI console Pandora FMS tersinkronisasi 100%).

## [2.11.1] - 2026-10-08 (SNMP Explorer - Sensor Deletion Persistence, Sensor ID Display, Cache Busting & Duplicate Cleaner)
### Fixed
- **Validasi & Verifikasi Penghapusan Sensor (`tools/snmp-explorer/snmp-explorer.php`):**
  - Mengubah penanganan `DELETE FROM sensor_inventory` pada mode single (`sensor`) dan batch (`bulk_sensors`, `clear_sensors`) agar memvalidasi `rowCount()`. Jika ID tidak ditemukan atau 0 baris terhapus, API mengembalikan pesan error yang jelas dan tidak lagi memberikan respons semu sukses.
  - Memperbaiki penghapusan device (`type: 'device'`) agar sekaligus membersihkan seluruh sensor relasi pada `sensor_inventory WHERE device_id = ?` sehingga tidak meninggalkan data yatim (*orphaned sensors*).
  - Menyuntikkan header `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` dan `Pragma: no-cache` pada seluruh respons endpoint API JSON untuk mencegah browser (Edge/Chrome) mengembalikan cache tabel inventory lama saat halaman di-refresh.
  - Menambahkan parameter cache-buster `_t` serta opsi `{ cache: 'no-store' }` pada seluruh panggilan AJAX `fetch` (`get_inventory`, `get_stats`, `get_devices`, `delete`, dll).
  - Memperkuat verifikasi CSRF (`$verify_csrf`) dengan membaca token dari header HTTP, parameter POST, maupun JSON payload `php://input` guna mencegah kegagalan hapus akibat token yang ter-strip oleh reverse proxy/webserver.
- **Pembedaan Visual Sensor ID & Konfirmasi Hapus yang Akurat:**
  - Menampilkan badge ID sensor (`#ID`) secara eksplisit di samping nama sensor pada tabel Sensor Inventory. Hal ini memudahkan pengguna membedakan sensor-sensor yang memiliki nama serupa (seperti beberapa sensor voltage/current pada power supply yang sama).
  - Dialog konfirmasi hapus kini menampilkan ID dan nama sensor spesifik yang akan dihapus (`Are you sure you want to permanently delete sensor #ID "Name"?`).
  - Menjaga nilai pilihan dropdown filter device (`filter-device`) agar tidak ter-reset kembali ke `-- All Devices --` saat tabel di-refresh.

### Added
- **Fitur Clean Duplicates pada Sensor Inventory:**
  - Menambahkan tombol **Clean Duplicates** dan API `?api=deduplicate_sensors` untuk membersihkan sensor duplikat OID lama pada perangkat terpilih (atau seluruh inventori) secara otomatis dengan mempertahankan entri terbaru dan modul yang sudah diprovision.
  - Tombol **Clear Filtered / All** kini secara cerdas menampilkan teks **Clear Device Sensors** ketika ada device yang sedang difilter.

## [2.11] - 2026-10-08 (SNMP Explorer - ENTITY-SENSOR-MIB Resolution, Parent Hierarchy Naming & Bulk Delete)
### Fixed
- **Resolusi OID & MIB ENTITY-SENSOR-MIB (RFC 3433):**
  - Menambahkan definisi resmi `ENTITY-SENSOR-MIB.mib` ke direktori engine MIB.
  - Menambahkan *in-memory fast-path* dan resolusi numerik/simbolik pada `OidTranslator.php` untuk OID `.1.3.6.1.2.1.99.1.1.1.*` (`entPhySensorValue`, `entPhySensorType`, dll) dan `ENTITY-MIB` (`.1.3.6.1.2.1.47.1.1.1.1.*`). OID sensor fisik kini langsung berstatus `TRANSLATED OK` pada Interactive Translator dan Discovery.
- **Penyelesaian Nama Sensor Identik Melalui Penelusuran Hirarki Parent:**
  - Pada `EnvironmentalDiscoveryModule.php`, menambahkan penelusuran relasi fisik `ENT_PHYSICAL_CONTAINED_IN`, `ENT_PHYSICAL_PARENT_REL_POS`, dan `ENT_PHYSICAL_CLASS`. Sensor leaf yang hanya memiliki nama generik (seperti "Voltage", "Current", "Power", "Fan Speed") kini mewarisi nama modul induknya (misal `Power Supply 1 - Voltage (V)`, `Power Supply 2 - Voltage (V)`, `Fan Tray 1 - Speed (rpm)`).
  - Menambahkan deduplikasi berbasis OID ternormalisasi dengan sistem prioritas modul serta fallback penomoran otomatis (`#1`, `#2`) pada `DiscoveryPipeline.php` dan `SensorInventoryRepository.php` untuk mencegah tabrakan nama modul pada agen Pandora FMS.

### Added
- **Fitur Bulk Delete pada Sensor Inventory:**
  - Menambahkan tombol **Delete Selected (<count>)** di toolbar Sensor Inventory untuk menghapus seluruh sensor yang dicentang secara batch.
  - Menambahkan tombol **Clear Filtered / All** di toolbar Sensor Inventory untuk menghapus semua sensor yang sesuai dengan filter aktif atau mengosongkan seluruh sensor inventori sekaligus dengan konfirmasi aman.
  - Menambahkan dukungan API `?api=delete` dengan mode `bulk_sensors` dan `clear_sensors`.
- **Fitur Action Delete pada Tab Pandora Provisioning:**
  - Menambahkan kolom **Actions** dengan tombol **Delete** pada baris antrean provisioning serta tombol **Clear All** untuk mengosongkan antrean provisioning.

## [2.10] - 2026-10-06 (SNMP Explorer - Memory & Storage Percentage Fix & Auto-Repair)
### Fixed
- **Perbaikan Ketidaksesuaian Nilai Memory & Storage (%) pada Modul Pandora FMS:**
  - **Koreksi OID Discovery (`MemoryDiscoveryModule.php`):** Mengubah penentuan OID sensor dari `HOST_RESOURCES_MEMORY_SIZE` (`1.3.6.1.2.1.25.2.3.1.5`) ke `HOST_RESOURCES_MEMORY_USED` (`1.3.6.1.2.1.25.2.3.1.6`). Sebelumnya modul mengarah ke OID kapasitas total partisi sehingga poller mencatat jumlah alokasi blok mentah (misal `186,307%` atau `2,474,907%`).
  - **Dukungan Pengali `post_process`:** Karena HOST-RESOURCES-MIB mengembalikan jumlah blok alokasi mentah (Integer) dan bukan persentase langsung, sistem kini menghitung dan menyuntikkan multiplier `post_process = 100 / total_units` secara otomatis ke tabel `tagente_modulo`. Nilai yang diambil Pandora FMS kini dikalikan dengan multiplier tersebut sehingga menghasilkan persentase aktual (contoh: `13%`, `2%`, `0%`) yang konsisten dengan hasil discovery di SNMP Explorer.
  - **Sinkronisasi Metrik Cisco (`CISCO-MEMORY-POOL-MIB`):** Menambahkan perhitungan `post_process = 100 / total_bytes` untuk pool memory Cisco yang mengembalikan nilai bytes mentah.
  - **Penyelarasan `UniversalSystemDiscoveryModule.php`:** Menambahkan pengali `post_process` dan menyelaraskan `raw_value` ke persentase pada metrik storage `hrStorageUsed`.
  - **Penyempurnaan Re-provisioning (`PandoraProvisioner.php` & `PandoraRepository.php`):**
    - Menambahkan pencocokan modul berdasarkan nama (`findModulesByNames`) selain `custom_id` sehingga saat sensor di-deploy ulang, modul yang sudah ada di agen langsung diperbarui OID dan `post_process`-nya tanpa membuat modul duplikat.
    - Menambahkan reset cache data `tagente_estado` saat modul diperbarui agar poller segera mengambil data baru yang valid.

### Added
- **Fitur Auto-Fix (%) Modules pada Antarmuka SNMP Explorer:**
  - Menambahkan tombol **Auto-Fix (%) Modules** pada tab *Sensor Inventory* dan endpoint API `?api=repair_storage_modules` untuk memperbaiki modul-modul memory/storage HOST-RESOURCES-MIB yang sudah terlanjur dibuat di Pandora FMS dengan 1 kali klik.

## [2.9] - 2026-10-05 (SNMP Explorer - Full SNMP v3 Support with USM Security)
### Added
- **Fitur SNMP v3 pada SNMP Explorer (`tools/snmp-explorer/`):**
  - Menambahkan opsi **SNMP v3** pada dropdown versi SNMP di konsol pemindaian perangkat tunggal (*Single IP*) dan Subnet (*CIDR Range*).
  - Formulir kredensial keamanan SNMP v3 interaktif (*User-based Security Model - USM*):
    - **Security Level**: `authPriv` (Autentikasi & Enkripsi), `authNoPriv` (Autentikasi tanpa Enkripsi), dan `noAuthNoPriv`.
    - **Security Name (Username)**.
    - **Auth Protocol & Passphrase**: Mendukung `SHA` (SHA-1), `SHA-256`, `MD5`, dan `SHA-512` dengan tombol toggle Show/Hide password.
    - **Privacy (Encryption) Protocol & Passphrase**: Mendukung `AES` (AES-128), `AES-256`, `DES`, dan `3DES` dengan tombol toggle Show/Hide password.
    - **Context Name** (opsional).
  - Tampilan form dinamis: saat SNMP v3 dipilih, input Community disembunyikan/opsional dan form USM ditampilkan; saat level `noAuthNoPriv` atau `authNoPriv` dipilih, field yang tidak relevan otomatis disembunyikan.
- **SNMP Engine & Session Core:**
  - `SnmpSession.php`: Menginisialisasi session `SNMP::VERSION_3` native PHP dengan konfigurasi `$session->setSecurity(...)` lengkap.
  - `SnmpScanner.php`: Validasi kredensial v3, pemindaian OID vendor, pembuatan identitas cache yang terisolasi per kredensial v3.
  - `DeviceRepository.php` & `bootstrap.php`: Auto-migration kolom kredensial SNMP v3 pada tabel `devices` (`snmp_security_level`, `snmp_security_name`, `snmp_auth_protocol`, `snmp_auth_passphrase`, `snmp_priv_protocol`, `snmp_priv_passphrase`, `snmp_context_name`).
- **Pandora FMS Module Provisioning:**
  - `SensorInventoryRepository.php`: Mengambil kredensial v3 dari perangkat terkait saat proses provisioning.
  - `PandoraModuleBuilder.php`: Membangun modul SNMP v3 (`snmp_version = 3`, `snmp3_sec_level`, `snmp3_auth_user`, `snmp3_auth_method`, `snmp3_auth_pass`, `snmp3_priv_method`, `snmp3_priv_pass`, `plugin_user`).
  - `PandoraRepository.php`: Deteksi kolom dinamis pada `tagente_modulo` agar kompatibel dengan berbagai versi Pandora FMS tanpa error SQL.

### Fixed
- **Perbaikan Fatal Error HTTP 500 pada menu SNMP Explorer:**
  - Menghapus modifier `readonly` pada class `PandoraRepository` karena PHP 8.2+ melarang deklarasi static property (`$tagenteModuloColumns`) di dalam `readonly class` (*Fatal error: Cannot declare static property in readonly class*).
  - Menambahkan pembungkus `try...catch (\Throwable $e)` saat inisialisasi bootstrap engine di `snmp-explorer.php` agar menampilkan kartu diagnostik yang ramah pengguna apabila terjadi kegagalan konfigurasi.
  - Memperbaiki seluruh blok `catch` tanpa variabel di discovery modules dan repositories menjadi `catch (\Throwable $e)` dan `catch (\Exception $e)` untuk kepatuhan sintaks PHP.
  - Mengoptimalkan proses auto-migration di `bootstrap.php` dengan memeriksa keberadaan kolom di tabel `devices` terlebih dahulu sebelum menjalankan query `ALTER TABLE`.

## [2.8] - 2026-10-02 (SNMP Explorer - Device Discovery, Sensor Normalization & Pandora Provisioning)
### Added
- **Brand New Module: `SNMP Explorer` (`tools/snmp-explorer/`):**
  - Mengadopsi arsitektur dan kapabilitas dari `snmp-bridge` dengan modernisasi antarmuka pengguna (UI/UX) sesuai standar PFMS-Toolkit.
  - Sidebar scanner secara otomatis merender menu baru **`Tools` > `Snmp Explorer`**.
- **Discovery Engine & Multi-Profile Scanner:**
  - Mendukung pemindaian perangkat tunggal (*Single IP*) dan Subnet (*CIDR Range* e.g. `192.168.1.0/24`).
  - Dilengkapi 9 profil pemindaian: `provisioning`, `network`, `system`, `router_switch`, `olt`, `rectifier`, `cctv`, `printer`, dan `full`.
  - Integrasi adapter vendor cerdas: **Huawei** (dengan formula offset GPON optical DDM), **Cisco**, **ZTE**, **Raisecom**, **Alcatel/Nokia**, **Dahua**, **F5**, dan **Epson**.
  - Normalisasi satuan otomatis (dBm, Celsius, Volt, Ampere, bps, rpm) dan penyaringan nilai sentinel tak valid.
- **Sensor Inventory & Batch Management:**
  - Tabel inventaris sensor terpadu dengan pencarian OID/Nama Sensor, filter vendor, filter sensor class, dan filter status provisioning.
  - Checkbox pemilihan sensor massal (*bulk select*) dengan badge indikator interaktif.
- **Direct Pandora FMS Provisioning Engine:**
  - Integrasi langsung ke tabel `tagente_modulo` dan `tagente` menggunakan koneksi `$pdo` aktif Pandora FMS.
  - Mendukung pemilihan agen target yang ada atau pembuatan agen baru secara instan (*Quick Create Agent*).
  - Mekanisme idempotent berbasis `custom_id` (`snmpbridge:sensor:{id}`) untuk mencegah duplikasi modul.

## [2.7] - 2026-09-18 (Topology Network - Brand New Module with Group/Agent Discovery & VMware SDDC Visualization)
### Added
- **Brand New Module: `Topology Network` (`Dashboard/Topology-Network/`):**
  - Membangun ulang modul topologi jaringan dari awal secara mandiri di direktori `Dashboard/Topology-Network/topology-network.php` agar terpisah bersih dari modul legacy.
  - Sidebar scanner secara otomatis merender menu baru **`Topology Network`** di bawah folder **Dashboard**.
- **Agent Group & Agent Discovery Engine:**
  - Terintegrasi langsung dengan database Pandora FMS untuk membaca pohon hierarkis grup agen (`tgrupo`) dengan indentasi visual `└─ Subgroup` dan perhitungan jumlah agen real-time per grup.
  - Dropdown filter grup agen dinamis yang secara rekursif menyaring seluruh agen dan sub-agen dalam grup yang dipilih.
  - Pencarian agen interaktif (*live search*) dengan animasi auto-pan dan fokus zoom langsung ke node perangkat target.
- **VMware SDDC / vSphere Visual Reference Architecture:**
  - Desain kanvas visual Cytoscape.js & Dagre beresolusi tinggi dengan ikon vector SVG tajam:
    - Virtual Machine (`vm`) ➔ Ikon laptop VMware.
    - Hypervisor (`hypervisor`) ➔ Ikon server rack chassis dengan drive bay.
    - Cluster (`cluster`) ➔ Ikon grid 3x3 vSphere cluster.
    - Datacenter (`datacenter`) ➔ Ikon gedung datacenter SDDC.
    - Datastore (`storage`) ➔ Ikon tumpukan silinder disk vSAN/storage.
    - vCenter (`vcenter`) ➔ Ikon konsol monitor manajemen vCenter.
  - Double-ring alert visual: lingkaran luar merah menyala untuk status Critical, kuning untuk Warning, dan abu-abu netral untuk Normal, lengkap dengan badge peringatan tanda seru (`!`).
  - Label dua baris dengan kontras tinggi (`Nama Perangkat\nRole Title`).
  - Menyediakan tombol instan **Load Reference SDDC Demo** untuk memuat topologi 21-node VMware SDDC yang identik dengan gambar referensi.
- **Inspector Drawer & Role Switcher:**
  - Panel drawer rincian perangkat saat node di-klik: status pill, alamat IP, OS, grup agen, jumlah alert aktif, direct link ke halaman agen Pandora FMS, dan role switcher dropdown untuk mengubah klasifikasi peran node secara instan.
- **Air-Gapped / Intranet Ready:**
  - Library visualisasi grafik Cytoscape.js v3.28.1, Dagre layout engine, dan cytoscape-dagre disimpan lokal di `vendor/cytoscape/` tanpa ketergantungan internet eksternal.

## [2.6] - 2026-09-08 (Traffic Dashboard Fix: 500 Error, Category Handling & Module Discovery)
### Fixed
- **Deterministic Chart Color Stability & Module Ordering:**
  - Memperbaiki masalah warna grafik dan legend chip yang berubah-ubah (*color jitter/swapping*) pada setiap auto-refresh atau reload halaman.
  - Mengubah sorting modul pada backend dan frontend grafik dari `last_contact DESC` (yang menyebabkan urutan teracak-acak akibat selisih detik polling SNMP) menjadi pengurutan deterministik alami (*natural alphanumeric order*) berdasarkan nama Agent dan Modul.
  - Memperluas palet warna grafik menjadi 36 warna modern bergradasi kontras tinggi dan menambahkan session color mapping (`window.__metricChartColorMap` & `window.__dynamicChartColorMap`) sehingga setiap interface/modul selalu mendapatkan warna yang identik dan konsisten di setiap refresh.
- **History Table View Pagination Limit & DESC Value Sorting:**
  - Mengatasi masalah tampilan baris History Table yang sebelumnya terkungkung pada tinggi tetap sehingga hanya memunculkan 2 baris (*squished 2 rows*). Container tabel kini otomatis menyesuaikan tinggi baris secara natural berdasarkan limit yang ditentukan.
  - Menambahkan dropdown pemilihan limit baris per halaman langsung pada pagination bar tabel (`Show: 5 | 10 | 15 | 20 | 25 | 50 | 100`) serta opsi input `Rows Per Page (Limit)` pada builder modal widget.
  - Menambahkan fitur pengurutan data (**Sort By**): mendukung pengurutan **Highest Value First (DESC)** untuk memunculkan nilai modul tertinggi di posisi paling atas, serta pengurutan Lowest Value (ASC), Latest Timestamp (DESC), Oldest Timestamp (ASC), Agent Name, dan Module Name.
  - Mengintegrasikan konversi satuan traffic otomatis (bps/Kbps/Mbps/Gbps) pada nilai history table view dengan tetap mempertahankan footer pagination yang bersih dan minimalis (`Show [Limit]`, `Showing entries`, `Prev/Next`), di mana konversi nilai traffic dikontrol secara konsisten melalui opsi konfigurasi widget pada modal builder/edit (`USE RAW VALUE (NO FORMATTING)` dan `AUTO-CONVERT TRAFFIC TO BPS / KBPS / MBPS / GBPS`).
- **Traffic Auto-Convert & Bit-Rate Units Enforcement (Mbps vs Mbytes/s):**
  - Mengatasi kemunculan satuan `Mbytes/s` pada sumbu Y dan angka raw byte pada popup tooltip grafik.
  - Memastikan seluruh modul traffic jaringan (seperti `ifInOctets`, `ifOutOctets`, modul bertipe rate byte/s atau octet) secara mutlak dikonversi ke satuan standar bandwidth bit-rate jaringan (**`bps`**, **`Kbps`**, **`Mbps`**, **`Gbps`**) dengan pengali byte-ke-bit ($8\times$).
  - Memperbaiki logika deteksi `cardIsTraffic` dan formatter sumbu Y sehingga tidak akan pernah lagi memunculkan label `Mbytes/s`, melainkan selalu `Mbps` atau `Gbps`.
  - Sinkronisasi ketinggian grafik, skala sumbu Y, dan nilai popup tooltip secara 100% konsisten pada Metrics Dashboard maupun Dynamic Dashboard.
- **Traffic Dashboard 500 Internal Server Error & Empty Dashboard Fix:**
  - Memperbaiki fatal error `TypeError` pada decoding JSON konfigurasi dashboard baru saat bernilai `null` dengan menambahkan validasi `is_array($config)`.
  - Mengubah penanganan error backend dari `catch (Exception $e)` menjadi `catch (Throwable $e)` agar menangkap seluruh tipe engine Error dan mencegah respons HTTP 500.
  - Memperbaiki query SQL traffic yang gagal akibat referensi tabel `tcategory` / kolom `id_category` dengan melakukan pengecekan dinamis ke database schema.
  - Memperbaiki bug filter kategori `enabled_categories: []` yang sebelumnya menghapus semua interface yang ditemukan pada dashboard baru.
  - Menambahkan dukungan pola prefix modul traffic (`traffic in - `, `traffic out - `, dll.) dan suffix modul tambahan.
  - Memperbaiki error handling AJAX di frontend dan mencegah korupsi array PHP akibat loop referensi (`unset($iface)`).
  - Menambahkan header `http_response_code(200)` dan `Content-Type: application/json; charset=utf-8` secara eksplisit pada seluruh endpoint API (`load_config`, `save_config`, `categories`, `groups`, `agents`, `data`, `series`).
  - Menegakkan ulang penekanan error (`error_reporting` dan `ini_set('display_errors', 0)`) setelah pemanggilan `db-connection.php` guna mencegah pesan Notice/Warning PHP 8 diteruskan ke FastCGI/Apache yang dapat memicu header HTTP Status 500.
  - Memperbaiki penanganan respons fetch AJAX di frontend agar mem-parse teks sebagai JSON terlebih dahulu sehingga respons sukses (`ok: true`) tetap diproses dengan benar tanpa terhalang false-positive error.
  - Menambahkan ekspansi sub-grup hierarkis rekursif pada `?api=data` dan `?api=agents` sehingga antarmuka pada agen yang berada di sub-grup / anak grup terdeteksi dengan tepat.
  - Menambahkan pesan informatif yang ramah ketika tidak ada antarmuka yang cocok dengan filter atau konfigurasi node yang dipilih.
  - Mengatur `traffic-interface.php` sebagai alias forwarder dan memperbarui `portal_config.json`.
- **Route Parser Demo Reference Elimination & Storage Fallback:**
  - Menghapus pembuatan otomatis (*hardcoded auto-seeding*) dashboard demo `Core Gateway Path (Demo Reference)` yang sebelumnya selalu muncul kembali saat halaman di-refresh meskipun sudah dihapus oleh user.
  - Memastikan hanya agen asli yang terdeteksi di database Pandora FMS yang didaftarkan ke daftar dashboard Route Parser.
  - Memfilter dan membersihkan entri demo lama secara otomatis dari file konfigurasi saat halaman dimuat.
  - Menambahkan multi-path fallback pada `load_route_dashboards()` dan `save_route_dashboards()` (ke direktori `temp`) serta menginisialisasi `route_dashboards.json` di repositori agar operasi hapus/simpan dashboard tidak terhalang masalah izin tulis (*permissions*) direktori Linux/Apache.
- **Route Parser Intelligent Auto-Scanning & Agent Sync:**
  - Menambahkan fitur **Auto Scanning** agen: sistem secara otomatis mendeteksi seluruh agen di Pandora FMS yang memiliki modul `RouteStep%`, `RouteStepTarget%`, `RouteTarget%`, `RouteHop%`, atau `Route_%`.
  - Menambahkan tombol **Auto Scan Agents** pada toolbar header lengkap dengan status animasi scanning dan notifikasi toast hasil pemindaian.
  - Mengotomatiskan sinkronisasi pendaftaran dashboard pada saat daftar dashboard kosong atau saat tombol *Auto Scan* ditekan, sehingga agen baru langsung terdaftar tanpa konfigurasi manual.
  - Menambahkan deteksi IP sumber otomatis (*auto-detect agent IP*) dari modul hop pertama (`RouteStep_<ip>`) jika kolom `direccion` pada agen bernilai kosong di database.
  - Memperbarui tampilan state kosong (*empty state*) dengan tombol aksi cepat *Auto Scan Agents Sekarang*.

## [2.5] - 2026-08-30 (Route Parser Auto-Refresh, Embed Live Polling & Data Consistency Fix)
### Fixed
- **Auto-Refresh & Realtime Poller:**
  - Menambahkan dropdown **Auto Refresh** (`Off`, `10s`, `30s`, `1m`, `2m`, `5m`) di toolbar header.
  - Menambahkan endpoint API `api=get_realtime_data` untuk polling berkala via AJAX tanpa me-reload/mereset viewport SVG, posisi pan/zoom, dan sidebar inspector.
  - Memperbaiki kegagalan auto-refresh pada mode **Share URL & Iframe Embed** (`&standalone=1` / `&embed=1`) dengan memastikan parameter URL dan autentikasi polling tetap terjaga.
- **Module Data Consistency & Non-Destructive Topology:**
  - Menghapus seluruh query `UPDATE tagente_modulo` saat render halaman yang sebelumnya merusak nama dan relasi `parent_module_id` di database.
  - Memperbaiki pembacaan latensi dari `tagente_estado` dan fallback ke `tagente_datos` serta sanitasi angka latensi.
- **Hierarchical Group Tree Selection:**
  - Memperbaiki dropdown pemilihan Group pada Availability Nodes, Metrics Dashboard, Dynamic Dashboard, dan Optical Power Metrics agar menampilkan struktur hierarki grup dan sub-sub grup secara bertingkat dengan indentasi visual (`└─ `) sesuai Pandora FMS Tree View.
  - Menghilangkan prefix `[Primary]` yang redundan pada grup database utama agar nama grup bersih dan mudah dicari.
- **Share URL & Minimalist Embed (Hide/Show Header):**
  - Menambahkan modal interaktif **Share Widget URL** pada Availability Node & Modules, Metrics Dashboard, dan Optical Power Metrics dengan opsi checkbox **Hide Card Header** (`&hide_header=1`).
  - Memungkinkan embed widget ke dalam Pandora FMS Visual Console, Grafana, atau iframe eksternal secara bersih tanpa memakan ruang header card (judul dan timestamps).
  - Menambahkan tombol floating toggle pada tampilan standalone untuk menyembunyikan/menampilkan header widget secara instan.
- **Selectable Visible Stat Cards & Zero-Scrollbar Minimalist Embed:**
  - Menambahkan opsi konfigurasi di Widget Builder untuk memilih kartu status mana saja yang ingin ditampilkan/disembunyikan (`Total`, `UP/OK`, `Warning`, `Critical`, `Unknown`, `Not Init`).
  - Menghilangkan scrollbar browser (`overflow: hidden`) dan merapikan margin/padding card saat widget di-embed dengan `hide_header=1` agar tampilan embed di Visual Console benar-benar rapi tanpa scrollbar yang mengganggu.
- **Chart Tooltip & Series Data Alignment Fix:**
  - Memperbaiki bug nilai `undefined bytes/s` pada tooltip grafik ECharts (Line/Area/Bar) dengan memastikan sinkronisasi modul history, forward-fill timestamp asinkron, dan sanitasi nilai `null`/`undefined` ke format angka/satuan yang valid.
- **Auto-Sorting Chart Tooltip (Highest to Lowest):**
  - Mengurutkan item data pada popup tooltip grafik secara otomatis dari nilai terbesar ke nilai terkecil (*descending order*), dilengkapi styling scroll container rapi saat terdapat banyak interface/modul.
- **Network Bandwidth Auto-Conversion with On/Off Toggle:**
  - Menambahkan fitur opsi konfigurasi On/Off `Auto-Convert Traffic to bps / Kbps / Mbps / Gbps` pada builder modal widget/panel untuk mengonversi data traffic network (`bytes/s`, `bps`) secara otomatis ke standar bit rate jaringan (*8x multiplier* untuk byte rate) menjadi `bps`, `Kbps`, `Mbps`, dan `Gbps` pada popup tooltip dan kartu nilai.
- **Standalone & Embed Minimalist Spacing (Eliminate Bottom Whitespace/Gap):**
  - Mengoptimalkan padding container, margin card, dan ukuran canvas ECharts pada mode standalone/embed (`s=1` / `standalone=1`) agar tidak ada celah kosong (*whitespace/gap*) di bagian bawah card/widget.
  - Memperbarui margin grid bawah dan legend padding ECharts serta tinggi default iframe embed (`height="280"` dengan header, `height="240"` tanpa header) sehingga tampilan widget di Visual Console Pandora FMS presisi dan kompak tanpa sisa ruang kosong.
- **Multi-Line Legend Wrap (Zero Chart Overlap & Zero Pagination):**
  - Memisahkan render canvas ECharts dengan container HTML Legend chips di bawah grafik sehingga seluruh nama antarmuka/modul dapat melakukan wrap otomatis secara bebas tanpa menutupi (*overlap*) garis grafik atau sumbu koordinat.
  - Dilengkapi scrollbar halus vertikal (`max-height: 75px; overflow-y: auto`) saat terdapat puluhan antarmuka serta mendukung interaktivitas penuh (klik untuk *toggle show/hide* series dan hover untuk *highlight* garis).
- **UI Cleanliness:**
  - Menghilangkan banner status diagnostik "DB Nodes" pada Metrics Dashboard agar tampilan header lebih bersih dan rapi.

## [2.4] - 2026-08-26 (Route Parser Dashboard Hub, Add Route Path & Standalone Clean View)
### Added
- **Route Path Discovery & Module Provisioning:**
  - Fitur **Add Route Path** untuk mengeksekusi pelacakan rute baru (*hop discovery*) ke IP target menggunakan binary `/usr/share/pandora_server/util/plugin/route_parser`.
  - Otomatis melakukan pendaftaran modul `RouteStep_<hop_ip>` dan `RouteStepTarget_<target_ip>` pada Agen Pandora FMS yang dipilih via Data Spooler (`/var/spool/pandora/data_in/`) serta sinkronisasi langsung ke tabel `tagente_modulo`, `tagente_estado`, dan `tagente_datos`.
  - Modal Add Route Path dengan pilihan Agent, IP target, custom hop, dan live execution log.
- **Standalone Clean View & Collapsible Controls:**
  - Mode Standalone (`&standalone=1`) terfokus 100% pada visualisasi topologi penuh tanpa header atau sidebar yang memotong layar.
  - Header kontrol dan Inspector Sidebar dapat di-expand/collapse dengan tombol mengambang (*floating toggle*) dan tab samping.
- **Multi-Dashboard Hub & Management:**
  - Halaman Dashboard Hub dengan format tabel vertikal bersih (*clean vertical table list*) konsisten dengan Dynamic Dashboard.
- **Share URL & Embed Engine:**
  - Fitur **Share URL** untuk setiap dashboard dengan tiga opsi:
    1. *Direct Portal Link:* Membuka dashboard langsung di dalam antarmuka PFMS-Toolkit.
    2. *Standalone URL (`&standalone=1`):* Tampilan layar penuh (*fullscreen*) tanpa header portal, ideal untuk layar monitoring NOC / TV Wall.
    3. *Iframe Embed Code:* Kode embed `<iframe>` siap pakai untuk disematkan pada Grafana, visual console, atau web portal eksternal.
  - Tombol one-click copy dengan visual toast notification.
- **Interactive SVG Topology Engine:**
  - Visualisasi aliran data animasi (*animated dashed flow lines*) dengan indikator latensi per *hop*.
  - Hierarchical Tree Layout otomatis dari Source IP menuju ke seluruh Target.
  - Ikon status (Source/Agent, Intermediate Hop/Router, Target/Bullseye) dengan pewarnaan dinamis (`OK`, `WARN`, `CRITICAL`).
  - Fitur Drag-and-Drop node dengan perutean ulang garis koneksi dinamis (*dynamic edge re-routing*).
  - Kontrol Pan & Zoom (Mouse wheel, Zoom In/Out, Reset View).
  - Inspector / Details Sidebar untuk inspeksi mendalam metrik Min/Max/Avg latensi, nama modul, status, dan threshold.
  - Dukungan filter rentang waktu (1h, 6h, 1d, 7d, 30d) dan Auto-Refresh (30s, 1m, 5m).
  - Fallback / Demo Mode interaktif.


## [2.3] - 2024-04-29 (Native Integration & Reliability Fixes)
### Added
- **Native Chart Integration:** Mengganti custom sparklines di Metrics Dashboard dengan native Pandora FMS history chart (`stat_win.php`) untuk stabilitas 100% dan performa tinggi.
- **Enhanced Error Handling:** Menambahkan blok `.catch()` dan validasi `res.ok` pada seluruh fungsi `fetch` di Dynamic Dashboard untuk mencegah masalah "always spinning".

### Changed
- **UI Standardization:** Menyeragamkan ikon history menggunakan simbol `monitoring` dan menstandarisasi tipografi (font-weight: 600 untuk Page Title, 500 untuk Widget Title).
- **Absolute Asset Paths:** Memastikan seluruh file vendor (fonts, CSS, JS) menggunakan referensi absolut `/pandora_console/custom/panel/vendor/` agar kompatibel di dalam iframe.
- **Code Cleanup:** Menghapus kode lama yang sudah tidak digunakan (obsolete history modal, sparkline lazy-loading logic).

### Fixed
- **Dynamic Dashboard Bug:** Memperbaiki kegagalan pemuatan daftar Agent/Node yang disebabkan oleh error JSON saat database terputus.
- **Chart Rendering Guard:** Menambahkan proteksi terhadap data history yang bernilai `null` agar tidak memutus eksekusi JavaScript pada dashboard.
- **CSRF Header:** Menghapus ketergantungan pada variabel `$csrf_token` yang tidak terdefinisi pada proses simpan konfigurasi.



## [2.2] - 2024-04-29 (Maintenance & Architecture Update)
### Added
- **Centralized DB Connection:** Pengenalan `db-connection.php` untuk standarisasi koneksi database dan fungsi utilitas di seluruh aplikasi.
- **Exact Match Module Support:** Fitur pencarian modul secara spesifik (Exact Match) pada Dynamic Dashboard dan Inventory Devices.
- **Panel Width Selector:** Menambahkan kontrol lebar panel (1-12 span) pada dashboard builder.

## [2.1] - 2024-04-29 (UI & Performance Optimization)
### Added
- **Loading Overlay:** Spinner visual saat dashboard melakukan sinkronisasi data.
- **Utility Library:** File `tools/utils.php` sebagai pusat logika bersama.

### Changed
- **Database Tuning:** Mengoptimasi query Export dari pola N+1 menjadi satu batch query tunggal.
- **UI Refinement:** Update palet warna status dengan gradasi modern dan kontras tinggi.
- **Refactoring:** Memindahkan fungsi `map_pandora_status`, `pretty_text`, dan `h` ke library pusat.

### Fixed
- **Input Validation:** Sanitasi parameter `manual_ids` untuk mencegah input ilegal.
- **Memory Leak:** Membatasi jumlah data yang dikirim ke browser untuk mencegah *tab crash* pada dataset besar.

---

## [1.8] - Previous Version
### Added
- **Dynamic Scanner:** Portal kini men-scan folder secara otomatis tanpa hardcode menu.
- **Live Search:** Fitur pencarian real-time pada sidebar menu.

---

## [1.5] - Initial Stable Release
### Added
- **Widget Builder:** Interface untuk membuat widget kustom berdasarkan Group.
- **Export System:** Dukungan ekspor data ke format CSV dan TXT.
- **Standalone Mode:** Fitur untuk menampilkan widget secara mandiri tanpa sidebar portal.

---
*Format changelog ini mengikuti standar Keep a Changelog.*
