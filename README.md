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

## 📜 Lisensi
Proyek ini bersifat open-source dan didistribusikan di bawah naungan **[MIT License](LICENSE)**. Anda bebas memodifikasi, menggunakan, dan mendistribusikan kode ini sesuai dengan ketentuan yang berlaku.
