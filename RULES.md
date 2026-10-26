# Repository Rules & Standards - PFMS-Toolkit

Dokumen ini mendefinisikan aturan dan standar baku yang wajib dipatuhi oleh pengembang dan asisten AI coding saat mengembangkan, memodifikasi, atau menambahkan fitur baru di seluruh modul **PFMS-Toolkit**.

---

## 1. Bahasa Antarmuka Pengguna (UI Language Standard - English Only)
* **Semua teks antarmuka pengguna (UI), modal dialog, tombol, label formulir, opsi pilihan (dropdown), kartu statistik, tooltip, dan pesan status WAJIB menggunakan Bahasa Inggris (English Only).**
* Dilarang keras mencampur Bahasa Indonesia pada elemen visual publik:
  * **Header & Tombol Aksi:** Gunakan `Cancel`, `Save Widget`, `Apply`, `Close`, `Search`, `Export Data`, `Select/Clear All`.
  * **Label & Keterangan Formulir:** Gunakan `Chart circular radius percentage`, `Visible Stat Cards`, `Newest Timestamp (DESC)`, `Heatmap Box Size`, `Module Selection Mode`.
  * **Tooltip Timeline & Chart:** Gunakan `Period:`, `Agent Name:`, `Module Name:`, `Module Status:`, `Module Value:`, `Data Timestamp:`.
  * **Keterangan Status:** Gunakan `Latest polled data`, `Steady state continues`, `Recorded event`, `Current status verified`, `No data recorded`.
* Dokumentasi changelog dan arsitektur internal dapat menggunakan dwibahasa (Indonesia & Inggris).

---

## 2. Aturan Penambahan Fitur Baru (Feature Addition Workflow)
Setiap penambahan fitur baru pada dashboard, toolkit, atau visualizer wajib mematuhi aturan berikut:
1. **Pembaruan RULES.md:**
   * Jika penambahan fitur menetapkan standar baru (desain, arsitektur, parameter API, atau batasan sistem), aturan tersebut wajib langsung dicatatkan ke dalam file `RULES.md`.
2. **Pencatatan Rilis di CHANGELOG.md:**
   * Setiap penambahan fitur, peningkatan, atau perbaikan bug harus didokumentasikan di `CHANGELOG.md` dengan menaikkan patch/minor version (misal `v2.11.19`).
3. **Struktur Modal Dialog Sticky (`.modal-box-structured`):**
   * Setiap modal baru yang memiliki formulir panjang wajib memisahkan header (`.modal-header-custom`), area scrollable (`.modal-body-scroll`), dan footer tombol aksi (`.modal-footer-custom`) agar kontrol tetap sticky saat di-scroll.
4. **Hierarki Filter Agen vs Grup:**
   * Pilihan agen eksplisit (`manual_ids`) harus selalu memiliki prioritas lebih tinggi daripada filter grup (`group_id`). Jika agen dipilih secara manual, backend tidak boleh menimpa dengan query seluruh grup.
5. **Integritas Waktu Data Real-Time (Timestamp Integrity):**
   * Pada visualisasi time-series dan Status Timeline (Uptime Blocks), balok waktu terbaru (*latest block*) harus selalu menampilkan waktu pembaruan kontak terakhir (`last_contact`) modul secara riil, bukan stempel waktu lama dari event perubahan status hari sebelumnya.
6. **Batas Seri Chart Transparan (Chart Limit):**
   * Pilihan limit diagram (`chart_limit`) harus mendukung `Show All (Default: 0)` secara murni tanpa pemotongan tersembunyi (*hardcoded slicing*) di backend. Pilihan limit opsional (Top 5, Top 10, Top 25, Top 50, Top 100) dapat disediakan untuk kontrol performa pengguna.
