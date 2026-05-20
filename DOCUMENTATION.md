# Pandora FMS - Custom Extensions Portal Documentation

## 📌 Project Overview
Portal ini adalah platform kustom yang terintegrasi dengan Pandora FMS Console untuk menyediakan dashboard dan alat bantu tambahan secara dinamis. Portal ini dirancang untuk performa tinggi, keamanan tingkat lanjut, dan kemudahan kustomisasi.

---

## 🛠️ Menu & Folder Structure

### 1. **Portal Utama (`custom-index.php`)**
- **Fungsi:** Container utama yang men-scan direktori secara otomatis untuk membangun menu sidebar.
- **Fitur:**
    - **Dynamic Directory Scanner:** Menampilkan folder dan file PHP sebagai menu.
    - **Menu Caching:** Menyimpan struktur menu di `temp/menu_cache.json` untuk loading instan.
    - **Global Search:** Pencarian cepat untuk semua modul yang terdaftar di sidebar.
    - **Settings Modal:** Konfigurasi untuk mengecualikan folder atau file tertentu dari portal.

### 2. **Dashboards (`/dashboards`)**
Berisi modul visualisasi data real-time dari database Pandora FMS.
- **Node Availability:** Fokus pada status "Host Alive" atau "Ping" dari agent.
- **Module Availability:** Overview status global untuk berbagai tipe modul monitoring.
- **Fitur Dashboard:**
    - **Widget Builder:** Membuat widget kustom berdasarkan Group atau Keyword tertentu.
    - **Heatmap View:** Visualisasi grid warna untuk pemantauan cepat dalam skala besar.
    - **Server-Side Pagination:** Mendukung ribuan data tanpa memperlambat browser.
    - **Auto-Refresh:** Sinkronisasi data otomatis dengan interval yang dapat diatur (30s - 5m).

### 3. **Core Library (`db-connection.php`)**
- **Fungsi:** Jantung dari seluruh panel kustom. Menangani koneksi database, pemuatan konfigurasi Pandora FMS, dan menyediakan fungsi bantuan global.
- **Fitur Utama:**
    - **Centralized PDO Connection:** Satu koneksi untuk semua modul.
    - **Global Helpers:** Fungsi `pretty_text`, `timeAgo`, `formatInterval`, dan `h` untuk standarisasi UI.
    - **Dynamic Breadcrumbs:** Logika navigasi otomatis berbasis direktori.

---

## 🚀 Key Features & Technical Specs

### **Database Optimization**
- **Batch Processing:** Fitur export data menggunakan satu query tunggal untuk menghindari beban berlebih pada DB server.
- **Persistent Connections:** Menggunakan koneksi PDO tetap untuk efisiensi eksekusi script.

### **Security Hardening**
- **CSRF Protection:** Setiap request yang mengubah konfigurasi (Save Widget/Settings) divalidasi menggunakan token rahasia di session.
- **Input Sanitization:** Parameter ID dan Keyword dibersihkan menggunakan Regex untuk mencegah SQL Injection.
- **Auth Integration:** Terintegrasi langsung dengan session aktif Pandora FMS Console.

### **Performance Tuning**
- **Memory Efficient:** Menggunakan pagination di sisi server (SQL LIMIT) untuk membatasi penggunaan RAM pada server dan browser.
- **Loading Feedback:** Dashboard dilengkapi dengan indikator visual saat sinkronisasi data sedang berjalan.

---

## 📜 Version History
Riwayat perubahan dan detail versi dapat dilihat pada file [CHANGELOG.md](./CHANGELOG.md).

---

## 📝 Configuration Notes
- **File Config:** Pengaturan portal disimpan di `portal_config.json`.
- **Requirements:** 
    - PHP 7.4 atau lebih tinggi.
    - Akses baca ke file `include/config.php` milik Pandora FMS.
    - Permission tulis pada folder `temp/` untuk caching.

---

## 📄 License
Proyek ini dirilis di bawah lisensi **[Apache License 2.0](LICENSE)**. Anda bebas menggunakan, memodifikasi, dan mendistribusikan kode ini asalkan mematuhi ketentuan lisensi tersebut.

---
*Dokumentasi ini dibuat otomatis oleh AI Assistant untuk Pandora FMS Custom Panel Project.*
