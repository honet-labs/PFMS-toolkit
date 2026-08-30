# Changelog - PFMS-Toolkit

Semua perubahan signifikan pada proyek ini akan didokumentasikan di file ini.

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
