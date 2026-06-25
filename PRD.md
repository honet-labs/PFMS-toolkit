# Product Requirements Document (PRD) - PFMS-Toolkit

## 1. Pendahuluan & Visi Produk
**PFMS-Toolkit** adalah platform ekstensi kustom (panel mandiri) yang dirancang untuk berintegrasi langsung secara *plug-and-play* dengan **Pandora FMS Console**. Visi produk ini adalah menyediakan dasbor interaktif modern, visualisasi metrik performa tinggi, serta peralatan administrasi langsung bagi administrator sistem, tanpa memerlukan instalasi modul eksternal yang kompleks.

Toolkit ini berfokus pada tiga pilar utama:
1. **Aset Visual & UX Premium:** Dasbor interaktif bertenaga tinggi dengan navigasi responsif yang cepat.
2. **Keamanan & Pengerasan Sistem (Security Hardening):** Validasi akses ketat berbasis sesi Pandora FMS, proteksi CSRF, sanitasi input, serta pencegahan injeksi SQL.
3. **Efisiensi Database:** Query teroptimasi berbasis pagination server-side, pembatasan rentang data (lookback limits), dan koneksi database sekunder untuk data historis (History DB).

---

## 2. Struktur Arsitektur & Folder
Proyek ini diletakkan di dalam folder kustomisasi Pandora FMS (misalnya di bawah direktori `custom/panel/` atau `customize/panel/`).

*   `custom-index.php` — Titik masuk utama portal (Main Container) yang memuat layout, sidebar navigasi dinamis, modal pengaturan (*Settings*), modal dokumentasi (*Docs*), dan sistem pembaru (*Updater*).
*   `includes/db-connection.php` — Pustaka koneksi database terpusat (Primary DB & Historical DB) menggunakan PDO MySQL serta penyedia fungsi utilitas global.
*   `portal_config.json` — Konfigurasi lokal untuk daftar folder/file yang dikecualikan dari menu navigasi.
*   `Dashboard/` — Kumpulan modul dasbor visualisasi (Dynamic Dashboard, Traffic Dashboard, Metrics Dashboard, Netflow Explorer, Network Mapping, Table Viewer).
*   `Management/` — Kumpulan modul alat administratif (Script Manager, Date-Epoch Converter).
*   `temp/` — Direktori penyimpanan cache internal (menu cache, update cache).

---

## 3. Fitur Utama & Spesifikasi Fungsional

### 3.1 Portal Utama & Dynamic Sidebar Scanner
*   **Menu Scanner:** Sistem memindai struktur direktori `Dashboard/` dan `Management/` secara otomatis untuk merender menu sidebar. Folder diterjemahkan sebagai menu *dropdown*, sedangkan file PHP sebagai *link*.
*   **Menu Caching:** Struktur menu disimpan dalam bentuk JSON di `temp/menu_cache.json` untuk menghilangkan delay pemindaian direktori pada setiap pemuatan halaman.
*   **Global Search:** Kolom pencarian di bagian atas memfilter menu navigasi secara dinamis secara real-time.
*   **Settings Modal:** Menyediakan antarmuka bagi admin untuk:
    *   Mengecualikan direktori atau file PHP tertentu dari scanner.
    *   Melihat status dan rincian koneksi database aktif (Primary DB & Historical DB).
*   **Auto-Updater:** Sistem pembaruan berbasis Git atau unduhan ZIP langsung dari GitHub repositori tujuan, lengkap dengan konsol log visual interaktif.

### 3.2 Kategori Dashboard
1.  **Dynamic Dashboard:** Halaman pemantauan modular untuk membuat widget visualisasi berdasarkan Group atau Keyword agen tertentu.
2.  **Traffic Dashboard:** Visualisasi grafik lalu lintas antarmuka jaringan (bandwidth) secara real-time dan historis menggunakan ECharts. Dilengkapi query khusus ke database arsip (History DB) dengan batasan downsampling.
3.  **Metrics Dashboard:** Visualisasi tren beban CPU, penggunaan memori, utilitas disk, dan latensi sistem.
4.  **Netflow Explorer:** Modul analisis lalu lintas protokol jaringan berbasis port, IP sumber/tujuan, dan volume data.
5.  **Network Mapping:** Visualisasi topologi hubungan antar-simpul jaringan berbasis grup agen.
6.  **Table Viewer:** Utilitas untuk melihat struktur data tabular mentah langsung dari basis data dengan fitur penyaringan terintegrasi.

### 3.3 Utilitas Manajemen
1.  **Script Manager:** Antarmuka visual untuk mengeksekusi skrip otomasi yang aman di sisi server.
2.  **Date-Epoch Converter:** Konverter instan antara waktu Unix Epoch dan format penanggalan UTC/lokal.

---

## 4. Persyaratan Non-Fungsional (Non-Functional Requirements)

### 4.1 Keamanan (Security)
*   **Integrasi Sesi:** Hanya pengguna dengan sesi valid (`$_SESSION['id_usuario']`) di Pandora FMS yang dapat mengakses portal.
*   **Proteksi CSRF:** Operasi penulisan (seperti `save_settings` dan `execute_update`) wajib menyertakan token CSRF (`X-CSRF-TOKEN`) di dalam header HTTP request.
*   **Validasi Header:** Mengimplementasikan security header global: `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`, dan `Referrer-Policy`.
*   **Sanitasi Input:** Parameter SQL wajib diproses melalui parameter binding PDO. Parameter string non-SQL disanitasi menggunakan fungsi `htmlspecialchars` (`h()` helper).

### 4.2 Kinerja & Optimasi (Performance)
*   **Server-Side Pagination:** Semua visualisasi data tabular wajib menggunakan query berbasis `LIMIT` dan `OFFSET` agar tidak membebani memori server PHP.
*   **Database Downsampling:** Query histori dengan rentang waktu panjang wajib melalui fungsi kompresi data (`downsample_history_data`) sebelum dikirim ke frontend ECharts untuk mempercepat rendering.
*   **OpCache Reset:** Opsi reset OpCache PHP (`?clear_cache`) disediakan jika terjadi keterlambatan pemuatan script baru di sisi server web.

---

## 5. Alur Kerja Implementasi & Deployment
1.  **Inisialisasi Sandbox:** Mengembangkan modul baru di dalam direktori `Dashboard/` atau `Management/`.
2.  **Registrasi Otomatis:** Memastikan penamaan file mematuhi format Standard Name agar Sidebar Scanner dapat mendeteksi dan mempercantik penamaannya secara otomatis.
3.  **Pengujian Koneksi:** Melakukan pengecekan kestabilan query pada database utama dan database arsip (jika ada data historis lama).
4.  **Penerapan Produksi:** Melakukan pembaruan melalui tombol pembaru Git Updater di UI atau via CLI deployment.
