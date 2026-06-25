# Style Guide - PFMS-Toolkit

Style Guide ini menjelaskan standar visual, tipografi, palet warna, dan elemen antarmuka (UI/UX) yang wajib diikuti dalam pengembangan setiap modul/halaman kustom di lingkungan **PFMS-Toolkit**. Tujuannya adalah menjaga konsistensi tampilan agar menyatu sempurna dengan ekosistem **Pandora FMS Console**.

---

## 1. Tipografi & Font
*   **Font Utama:** `Inter`, system-ui, -apple-system, `Segoe UI`, Roboto, sans-serif.
    *   Digunakan untuk teks utama, judul, menu, dan elemen kontrol (tombol, input).
    *   Tinggi font basis: `14px` untuk teks biasa (body text).
*   **Font Monospace:** `Courier New`, Courier, monospace.
    *   Digunakan khusus untuk data mentah, log sistem, konsol pembaruan, dan nilai konfigurasi.
    *   Tinggi font basis: `12px`.
*   **Font Ikon:** `Material Symbols Outlined` (Google Fonts).
    *   Ikon dideklarasikan dengan elemen `<span class="material-symbols-outlined">nama_ikon</span>`.
    *   Ukuran default ikon: `18px`.

---

## 2. Sistem Warna (Color Palette)

| Kategori | Kode Warna | Contoh Penggunaan |
| :--- | :--- | :--- |
| **Primary Theme** | `#004d40` (Dark Green / Teal) | Warna tombol utama, menu aktif, dan aksen utama brand. |
| **Primary Hover** | `#00695c` (Medium Teal) | Efek hover pada tombol utama. |
| **Secondary Text** | `#7f8c8d` (Cool Gray) | Teks penjelasan, petunjuk form, sub-judul, dan label non-aktif. |
| **Dark Text** | `#334155` (Slate-700) | Warna default teks utama tubuh halaman (*body text*). |
| **Title Text** | `#0b1a26` (Midnight Navy) | Warna teks judul utama dasbor atau judul modal. |
| **Background Body** | `#f4f6f8` (Soft Gray) | Latar belakang halaman utama, iframe, dan area kerja. |
| **Card / Container**| `#ffffff` (Pure White) | Latar belakang modul sidebar, card widget, dan box modal. |
| **Borders & Dividers**| `#e0e4e8` / `#dce1e5` | Garis pembatas layout, border input, dan border card. |

### Status & Feedback Warna:
*   **Success (Hijau):** `#10b981` (Teks/Badge: `#065f46`, BG: `#d1fae5`) — Status koneksi terhubung, update berhasil.
*   **Error / Danger (Merah):** `#ef4444` (Teks/Badge: `#991b1b`, BG: `#fee2e2`) — Status koneksi gagal, kesalahan sistem.
*   **Warning (Kuning):** `#f1c40f` — Penanda direktori/folder di menu sidebar.
*   **Info (Muted Gray):** Teks: `#475569`, BG: `#e2e8f0` — Status tidak dikonfigurasi.

---

## 3. Komponen Antarmuka Standar (Standard UI Components)

### 3.1 Tombol (Buttons)
Gunakan dua jenis tombol berikut untuk menjaga konsistensi:
1.  **Tombol Utama (`.btn-apply`):**
    *   Latar belakang: `#004d40`, Teks: `#ffffff`
    *   Desain: Tanpa border, border-radius `4px`, padding `8px 20px`.
    *   Hover: Latar belakang berubah menjadi `#00695c`.
2.  **Tombol Sekunder / Garis Tepi (`.btn-outline`):**
    *   Latar belakang: `#ffffff`, Teks: `#4a5568`, Border: `1px solid #dce1e5`.
    *   Hover: Latar belakang berubah menjadi `#f4f6f8`, Teks: `#0b1a26`.

### 3.2 Modal Dialog (`.modal-overlay` & `.modal-box`)
*   **Overlay:** Mengisi layar (`position: fixed; inset: 0;`), latar belakang semi-transparan `rgba(0,0,0,0.5)`, tata letak fleksibel tengah (`display: flex; align-items: center; justify-content: center;`).
*   **Box Konten:** Latar belakang `#ffffff`, border-radius `8px`, bayangan halus `0 10px 30px rgba(0,0,0,0.1)`. Lebar default `500px` (atau `800px` untuk dokumen panjang).

### 3.3 Kartu Informasi (Cards)
Untuk menampilkan status koneksi, log, atau widget ringkasan:
*   Latar belakang: `#f8f9fa` (abu-abu sangat terang).
*   Garis tepi: `1px solid #e2e8f0`.
*   Jarak dalam (Padding): `12px 15px`.
*   Sudut (Border Radius): `6px`.
*   Penyelarasan: Disarankan menggunakan *flexbox* (`display: flex; align-items: center; justify-content: space-between;`) untuk menyelaraskan nama objek di kiri dan status/badge di kanan.

---

## 4. Struktur Kode CSS & Layout Grid
*   **Navigasi Sidebar:** Lebar tetap `260px`, pembatas kanan menggunakan `1px solid #e0e4e8`.
*   **Layout Container:** Menggunakan flexbox layout (`display: flex; height: 100vh; overflow: hidden;`).
*   **Iframe Content:** Mengisi sisa ruang secara fleksibel (`flex-grow: 1; border: none; background: #f4f6f8;`).
*   **Form Controls:** Setiap elemen input/textarea wajib memiliki border-radius `4px`, border `1px solid #dce1e5`, padding `10px 12px`, dan fokus dengan warna border `#004d40` serta bayangan halus `box-shadow: 0 0 0 2px rgba(0,77,64,0.1)`.
