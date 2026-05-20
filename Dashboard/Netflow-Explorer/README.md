# NetFlow Explorer (Pandora FMS) - refactor v16

Versi ini merapikan struktur file agar lebih mudah dikembangkan dan memperbaiki bug auto refresh.

## Perubahan utama

- Bugfix auto refresh:
  - Sebelumnya timer refresh membaca nilai `auto_refresh` lama dari PHP saat halaman pertama kali dirender.
  - Sekarang nilai refresh diambil langsung dari dropdown aktif, disinkronkan ke URL, lalu reload menggunakan URL terbaru.
  - Hasilnya: mode `1m`, `5m`, dan `10m` tetap aktif secara konsisten dan halaman akan terus mengikuti file `nfcapd` terbaru selama `Manual end` tidak dicentang.

- Refactor struktur file:
  - `netflow-explorer.php` hanya menjadi entry point + template halaman.
  - `includes/nfx_lib.php` berisi helper/function backend.
  - `includes/nfx_bootstrap.php` berisi parsing request, pemanggilan `nfdump`, cache, dan pembentukan data untuk view.
  - `assets/css/netflow-explorer.css` berisi seluruh stylesheet.
  - `assets/js/netflow-explorer.js` berisi seluruh interaksi UI, Sankey, share URL, dan auto refresh.

## Struktur folder

```text
Netflow-Explorer/
  netflow-explorer.php
  nfx_local_config.php
  includes/
    nfx_lib.php
    nfx_bootstrap.php
  assets/
    css/
      netflow-explorer.css
    js/
      netflow-explorer.js
```

## Requirements

- `nfdump` terpasang di server Pandora FMS.
- User web server (`apache`, `www-data`, dll.) harus memiliki izin baca ke direktori NetFlow Pandora FMS.
- Folder deployment harus ikut membawa subfolder `includes/` dan `assets/`.

## Deploy

Contoh lokasi:

```text
/pandora_console/extensions/netflow_explorer/
  netflow-explorer.php
  nfx_local_config.php
  includes/
  assets/
```

Akses halaman:

```text
https://<pandora-console>/pandora_console/extensions/netflow_explorer/netflow-explorer.php
```

## Catatan auto refresh

Agar data terbaru ikut terbaca:

- biarkan `Manual end` nonaktif,
- pilih `Refresh = 1 minute`, `5 minutes`, atau `10 minutes`,
- halaman akan reload menggunakan query string terbaru dan `end` otomatis mengikuti file `nfcapd` paling baru yang sudah finalized.

## Permissions (contoh)

Jika web server berjalan sebagai `apache`:

```bash
setfacl -m u:apache:rx /var/spool/pandora/data_in/netflow
setfacl -m d:u:apache:rx /var/spool/pandora/data_in/netflow
```

## Pengembangan lanjutan yang disarankan

- Vendor `plotly.min.js` secara lokal untuk lingkungan Pandora yang tidak bisa keluar internet.
- Tambahkan endpoint JSON terpisah bila ingin auto refresh tanpa full page reload.
- Pisahkan template HTML lagi menjadi partial (`header`, `toolbar`, `tables`) bila file tampilan terus bertambah.
