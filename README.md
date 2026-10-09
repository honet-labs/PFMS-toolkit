# PFMS-Toolkit

[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Sebuah toolkit kustom (PFMS-Toolkit) yang terintegrasi secara langsung dengan **Pandora FMS Console** untuk menyediakan dashboard interaktif dan alat bantu administratif secara dinamis. Proyek ini dirancang dengan fokus pada performa tinggi, keamanan (security hardening), dan kemudahan kustomisasi (plug-and-play).

## 📖 Dokumentasi Lengkap
Untuk panduan struktur folder, penjelasan *Core Library*, keamanan, dan optimasi performa database, silakan baca file **[DOCUMENTATION.md](DOCUMENTATION.md)**.

## ✨ Fitur Unggulan
- **Dynamic Menu Scanner:** Membangun sidebar secara otomatis berdasarkan struktur folder/file PHP tanpa perlu konfigurasi manual yang rumit.
- **Menu Caching & Global Search:** Navigasi instan dengan pencarian modul terintegrasi.
- **Optimized Dashboards:** Server-side pagination, batch query processing, dan Heatmap visualizations untuk memantau ribuan perangkat tanpa membebani browser.
- **Security Hardening:** CSRF Protection, Input Sanitization, dan validasi sesi langsung dari Pandora FMS inti.

## 🛠️ Prasyarat (Requirements)
- **PHP** versi 7.4 atau lebih tinggi.
- Terinstal sebagai bagian dari ekstensi Pandora FMS Console (akses ke `include/config.php` diperlukan).
- Hak akses tulis (Write permission) pada folder `temp/` untuk kebutuhan penyimpanan *cache*.

## 🚀 Instalasi Cepat (Quick Install)

Jalankan perintah berikut di server Pandora FMS Anda (memerlukan hak akses `root` atau `sudo`):

```bash
curl -sSL https://raw.githubusercontent.com/honet-labs/PFMS-toolkit/main/installer-pfms-toolkit.sh | sudo bash
```

Atau jika Anda mengunduh/meng-clone repository ini secara manual:
```bash
sudo bash installer-pfms-toolkit.sh
```

Skrip installer otomatis menangani:
- Penempatan di `/var/www/html/pandora_console/custom/pfms-toolkit`.
- Pengaturan kepemilikan `apache:apache`.
- Pengaturan izin akses direktori (`0755`), file (`0644`), dan folder cache `temp/` & `cache/` (`0775`).
- Pembuatan symlink root `index.php -> custom-index.php`.
- Konfigurasi konteks SELinux (`httpd_sys_rw_content_t`) secara otomatis bila aktif.

## 📜 Lisensi
Proyek ini bersifat open-source dan didistribusikan di bawah naungan **[MIT License](LICENSE)**. Anda bebas memodifikasi, menggunakan, dan mendistribusikan kode ini sesuai dengan ketentuan yang berlaku.
