# Changelog - PFMS-Toolkit

Semua perubahan signifikan pada proyek ini akan didokumentasikan di file ini.

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
