# PANDUAN LIVE DEMO SUITECRM 8 (15 MENIT KULIAH INTERAKTIF)

> **Informasi Sistem & Lingkungan Terverifikasi:**  
> - **SuiteCRM Core Version:** 8.8.1 (Bitnami Legacy Container)  
> - **URL Aplikasi CRM:** [http://localhost:8080](http://localhost:8080)  
> - **URL Mailpit (Testing Email):** [http://localhost:8025](http://localhost:8025)  
> - **Kredensial Login Utama:** Username `admin` | Password `admin_master_2026`  
> - **Kredensial Sales / CS:** Username `bima` / `citra` | Password `Demo_2026!`  
> - **Kedalaman Data Historis:** Memuat **5 Tahun Riwayat Operasional Nyata (2021 - 2026)** mencakup 15 Akun B2B beragam (Hotel Bintang 5, Kafe Multi-Cabang, Roastery Artisan, Resort, Korporat, Rumah Sakit, Kampus), 18 Peluang Bisnis multi-tahun, 8 Tiket Servis, 18 Panggilan Telepon, 10 Notulen Rapat, dan 26 Berita Acara & Dokumen Kontrak.

---

## 1. Panduan Navigasi Menu SuiteCRM 8 (Hasil Validasi UI Riil)

Antarmuka SuiteCRM 8 menggunakan frontend Angular modern dengan tata letak menu sebagai berikut:

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ [Logo]  Home  Accounts  Contacts  Opportunities  Leads  Campaigns  Cases  [...]  [👤 Admin]│
└────────────────────────────────────────────────────────────────────────────────────────┘
```

1. **Bilah Navigasi Utama (Top Navbar):**
   * Tab modul utama langsung terlihat di bilah atas: **Home**, **Accounts**, **Contacts**, **Opportunities**, **Leads**, **Campaigns**, **Cases**, **Calls**, **Meetings**.
   * Jika resolusi layar laptop Anda lebih kecil, modul yang tidak muat akan terkumpul di menu **"..." (More / Titik Tiga)** di sisi kanan navbar.
2. **Menu Cepat Modul (Quick Actions):**
   * Di samping nama setiap modul terdapat tanda panah kecil (`v`) yang jika diklik memunculkan shortcut: *Create [Module]* dan *View [Module]*.
3. **Menu Profil & Administrasi:**
   * Di pojok kanan atas, klik **Avatar Profil (Lingkaran User)** → muncul dropdown: **Profile**, **Administration**, **About**, dan **Log Out**.
4. **Bilah Aksi Detail Record (Record Action Bar):**
   * Pada halaman detail entitas (misal Leads atau Accounts), terdapat tombol utama **Edit** dan tombol aksi dropdown **Actions** (tanda panah bawah) yang berisi perintah kontekstual seperti **Convert Lead**, **Duplicate**, atau **Delete**.

---

## 2. Tabel Alokasi Waktu Skenario Demo (15 Menit)

| Skenario | Durasi | Topik Pembelajaran CRM | Karakter & Entitas Kunci |
| :--- | :--- | :--- | :--- |
| **Pre-Flight Check** | - | Kesiapan sistem & server | Terminal: `make verify` (20/20 PASS) |
| **Act 1: Operasional CRM** | 3 Menit | Akuisisi Lead & Proses Konversi B2B | Kampanye F&B Expo → Lead Bapak Wahyu |
| **Act 2: Kolaboratif CRM** | 4 Menit | *Single Source of Truth* / 360° Customer View + Riwayat Loyalitas 5 Tahun | Akun Veteran *Grand Majapahit Hotel Surabaya* & *Kopi Titik Temu* |
| **Act 3: Analitikal CRM** | 4 Menit | Visualisasi Pipeline Multi-Tahun & Metrik Churn Risk | Dashboard Sales Stage & Panggilan Overdue Admin |
| **Act 4: Studio Customization** | 3 Menit | No-Code Customization & Ekstensi Skema | Field Dropdown "Customer Tier" |
| **Bonus (Opsional)** | 1 Menit | Email Tracking & Mailpit Sandbox | Kirim Email dari Tiket Servis Mesin |

---

## 3. Langkah Demi Langkah Live Demo

### ACT 1: Operasional CRM – Akuisisi & Konversi Lead (3 Menit)
*Pesan Kunci ke Audiens: "Bagaimana prospek mentah dari pameran bisnis dikonversi menjadi entitas resmi perusahaan (Akun, Kontak, dan Peluang Transaksi) tanpa entri ulang data."*

1. **Buka Menu Campaigns:**
   * Pada navbar atas, klik **Campaigns** (jika tidak terlihat, klik menu **"..." / More** → pilih **Campaigns**).
   * Tunjukkan 3 kampanye yang mencerminkan perjalanan perusahaan selama 5 tahun:
     - `Pameran F&B Expo 2026 Surabaya` (Aktif saat ini - Budget Rp 25 Juta)
     - `East Java Coffee Festival 2024` (Selesai 2024)
     - `Surabaya Hospitality Fair 2022` (Selesai 2022)
   * Buka kampanye: **"Pameran F&B Expo 2026 Surabaya"**.
2. **Lihat Daftar Prospek (Leads):**
   * Gulir ke bawah ke subpanel **Leads**.
   * Klik nama lead: **"Wahyu Pratama (Lead)"** (Kopi Kontainer Sidoarjo).
   * Tunjukkan kelengkapan datanya: No HP, Kota Sidoarjo, sumber *Trade Show*, dan deskripsi: *"Tertarik paket bundling mesin espresso 1-group dan biji kopi blend 20 kg/bulan saat berkunjung ke booth pameran"*.
3. **Lakukan Aksi "Convert Lead":**
   * Di sudut kanan atas halaman detail Bapak Wahyu, klik tombol dropdown **Actions** (di sebelah tombol Edit) → pilih **Convert Lead**.
   * Di layar konversi, centang 3 kotak:
     1. [x] **Create Contact** (Otomatis terisi nama *Wahyu Pratama*).
     2. [x] **Create Account** (Otomatis terisi perusahaan *Kopi Kontainer Sidoarjo*).
     3. [x] **Create Opportunity** → Isi nama: `[NEW BIZ] Paket Espresso Kopi Kontainer`, Sales Stage: `Proposal/Price Quote`, Nilai: `45000000` (Rp 45 Juta).
   * Klik tombol **Save / Convert**.
4. **Poin Evaluasi Mahasiswa:** Tunjukkan bahwa 1 aksi konversi otomatis melahirkan 3 entitas terhubung sekaligus di database (`accounts`, `contacts`, dan `opportunities`), mengeliminasi kesalahan entri data manual (*zero data duplication*).

---

### ACT 2: Kolaboratif CRM – Pandangan 360° & Pelanggan Setia 5 Tahun (4 Menit)
*Pesan Kunci ke Audiens: "Membuktikan Customer Retention & Longevity: bagaimana sebuah hotel bintang 5 menjadi pelanggan setia selama 5 tahun berturut-turut (2021-2026) dengan multi-order, laporan perawatan tahunan, dan penanganan tiket servis."*

1. **Buka Menu Accounts:**
   * Klik tab **Accounts** pada navbar atas, pilih akun: **"Grand Majapahit Hotel Surabaya"**.
2. **Eksplorasi Profil Pelanggan Setia:**
   * Tunjukkan profil hotel bintang 5 di Jl. Tunjungan Surabaya, omzet Rp 12 Miliar/tahun, 180 karyawan, dan kontak person: **Antonius Wibowo (Director of Food & Beverage)**.
3. **Eksplorasi Perjalanan 5 Tahun (The 5-Year Journey):**
   * **Subpanel Opportunities (Bukti Transaksi Multi-Tahun):**
     - **2021 (Closed Won):** `[2021] Mesin Aurelia Majapahit` (Rp 135 Juta) - pengadaan unit mesin utama.
     - **2022 (Closed Lost):** `[2022] Mesin Espresso Resto Cabang` (Rp 75 Juta) - kalah tender sewa pihak ketiga.
     - **2025 (Closed Won):** `[2025] Mesin Aurelia Wave Majapahit` (Rp 140 Juta) - penambahan unit ke-2 di executive lounge.
     - **2026 (Aktif/Qualification):** `[RETENTION] Reaktivasi Kontrak Majapahit` (Rp 72 Juta) - perpanjangan pasokan 2027.
   * **Subpanel History & Notes (Bukti Hubungan 5 Tahun):**
     - **2021:** BAST Pengiriman & Commissioning mesin Aurelia Wave + Laporan Cupping Uji Pasokan Biji Kopi Hotel.
     - **2022:** Laporan Servis Preventif Tahunan (PMR) 2022 (descaling & penggantian vacuum valve).
     - **2023:** Laporan Servis Preventif Tahunan (PMR) 2023 (uji tekanan pompa 9 bar & shower screen).
     - **2024:** Laporan Servis Preventif Tahunan (PMR) 2024 (kalibrasi level probe & overhaul flowmeter).
     - **2025:** BAST Mesin Unit ke-2 Executive Lounge + Laporan PMR 2025.
     - **2026:** Catatan Apresiasi Klien Loyalitas 5 Tahun + Tiket Darurat: `[URGENT] Tekanan Steam Wand Turun` (P1) beserta catatan investigasi teknisi darurat.
4. **Poin Diskusi Kelas:**  
   *"Inilah nilai inti dari CRM Enterprise. Ketika General Manager atau F&B Director hotel berganti orang, PT Sumber Kopi Nusantara tidak kehilangan sejarah kerja sama selama 5 tahun karena seluruh data terdokumentasi rapi di satu sistem terpusat."*

---

### ACT 3: Analitikal CRM – Pipeline Finansial & Peringatan Churn (4 Menit)
*Pesan Kunci ke Audiens: "Membaca kesehatan bisnis perusahaan dari dasbor eksekutif dan mendeteksi peluang maupun risiko keterlambatan follow-up."*

1. **Buka Halaman Utama (Home Dashboard):**
   * Klik logo SuiteCRM di pojok kiri atas untuk kembali ke **Home Dashboard**.
2. **Tunjukkan Grafik Pipeline Penjualan:**
   * Amati dashlet grafik **Pipeline by Stage** / **Sales Funnel**.
   * Tunjukkan ragam tahapan peluang 2026:
     - *Prospecting (10%):* [NEW BIZ] Paket Espresso Kopi Kontainer (Rp 45 Juta)
     - *Qualification (20%):* [RETENTION] Reaktivasi Kontrak Majapahit (Rp 72 Juta)
     - *Proposal/Price Quote (65%):* [2026] Grinder Single Origin Malang (Rp 28,5 Juta)
     - *Negotiation/Review (80%):* [2026] Kontrak Biji Espresso Titik Temu (Rp 65 Juta)
     - *Closed Won (100%):* Total deal sukses masa lalu (Rp 800+ Juta akumulatif 5 tahun)
     - *Closed Lost (0%):* Evaluasi peluang yang lepas untuk analisa kompetitor.
3. **Deteksi Panggilan Tertunda (Overdue Call Warning):**
   * Lihat widget **My Scheduled Calls / Overdue Activities**.
   * Terdapat panggilan berwarna merah (terlambat / overdue): `[OVERDUE] Konfirmasi Demo Mesin Kopi Kontainer`.
   * **Penjelasan:** Admin dapat langsung melihat bahwa ada jadwal telepon follow-up dengan Bapak Wahyu yang terlewat 3 hari. CRM mencegah prospek potensial lepas ke tangan kompetitor karena sales lupa menghubungi kembali.

---

### ACT 4: Kustomisasi No-Code – Studio Modul (3 Menit)
*Pesan Kunci ke Audiens: "Fleksibilitas sistem enterprise modern yang dapat dimodifikasi oleh analis bisnis tanpa menulis satu baris kode pun."*

1. **Buka Menu Studio:**
   * Klik ikon **Avatar Profil Admin** (pojok kanan atas) → pilih **Administration**.
   * Gulir ke bagian **Developer Tools** → klik **Studio**.
2. **Tambah Field Kustom:**
   * Di pohon modul sebelah kiri, klik **Accounts** → klik **Fields**.
   * Klik tombol **Add Field**:
     - **Data Type:** Pilih `DropDown`
     - **Field Name:** Ketik `customer_tier` (nama teknis otomatis menjadi `customer_tier_c`)
     - **Display Label:** Ketik `Customer Tier`
     - **Drop Down List:** Pilih opsi dropdown baru dengan isi: `VIP_5Years`, `Loyal`, `New_Customer`
     - Klik **Save**.
3. **Letakkan Field ke Tampilan Form (Layout):**
   * Kembali ke pohon **Accounts** → klik **Layouts** → pilih **DetailView** (atau EditView).
   * Tarik (*drag-and-drop*) kotak field `customer_tier_c` dari toolbox kiri ke salah satu baris kosong form.
   * Klik tombol **Save & Deploy**.
4. **Buktikan Hasilnya:**
   * Buka kembali menu **Accounts** → klik **Grand Majapahit Hotel Surabaya**.
   * Field baru **Customer Tier** sekarang resmi muncul di formulir aplikasi!
   * *Penjelasan Arsitektur:* Sampaikan bahwa data field ini otomatis disimpan di tabel MariaDB satelit `accounts_cstm`, sehingga kernel sistem tetap bersih dan aman saat di-upgrade.

---

## 4. Bonus: Pengujian Outbound Email dengan Mailpit (1 Menit)

1. Buka tiket Case: `[URGENT] Tekanan Steam Wand Turun`.
2. Pada subpanel aktivitas, pilih menu kirim email / respon klien.
3. Kirim email dengan subjek: *"Pemberitahuan: Teknisi PT Sumber Kopi Menuju Lokasi Grand Majapahit"*.
4. Buka tab browser [http://localhost:8025](http://localhost:8025) (Mailpit).
5. Email langsung tertangkap di inbox Mailpit tanpa pernah bocor ke internet publik.

---

## 5. Rencana Darurat (Troubleshooting / Plan B)

Jika di tengah demo Anda atau audiens salah menghapus data penting atau form menjadi berantakan:
1. Buka terminal pada folder proyek `suitecrm-lecture/`.
2. Jalankan perintah:
   ```bash
   make seed-data
   make verify
   ```
   Atau jika ingin mereset total dari nol:
   ```bash
   make reset
   ```
3. Dalam hitungan detik, seluruh data 5 tahun (15 akun, 18 deal, 8 tiket servis, 26 dokumen catatan) akan terpulihkan 100% dan lulus verifikasi (**20/20 PASS**).
