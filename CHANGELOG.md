# Changelog - PFMS-Toolkit

Semua perubahan signifikan pada proyek ini akan didokumentasikan di file ini.

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
